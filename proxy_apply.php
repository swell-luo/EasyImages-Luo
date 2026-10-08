<?php
/*
 * =====================================================================
 * 变更日志 (Change Log)
 * ---------------------------------------------------------------------
 * 日期:     2026-10-07
 * 内容:     新增本文件 - IPv6 反向代理「应用器」(root 定时任务)
 * 原因:     后台页面(admin/proxy.php)以 www-data 低权限运行, 无权写
 *           /etc/nginx 也无权 reload Nginx。所以网页只负责"改规则+打标记",
 *           真正落地配置的动作收敛到本脚本, 由 root 的 cron 每分钟跑一次。
 *           这样 Web 进程被攻破也碰不到系统配置, 安全边界干净。
 *
 * 工作流程(每一步都有详细注释):
 *   1. 读 config/config.proxy.php 规则
 *   2. 无"待应用"标记 -> 直接退出(省资源, 每分钟跑一次也不能空转)
 *   3. 手动证书规则: 把后台粘贴的 PEM 写入证书目录
 *   4. 自动证书规则: certbot --webroot 签发(80端口的ACME验证由主站模板放行)
 *   5. 检查哪些域名"证书已就绪" -> 只有就绪的才生成 443 配置(解决
 *      "证书没签下来配置就校验不过"的死循环)
 *   6. require app/proxy_render.php 渲染两段 Nginx 配置并写入
 *   7. nginx -t 语法校验: 失败 -> 恢复旧配置, 不 reload, 记日志
 *   8. 校验通过 -> nginx -s reload -> 更新状态文件供后台显示
 *
 * 用法:  php proxy_apply.php            (cron: * * * * * root ...)
 *        php proxy_apply.php --force    (忽略标记强制执行一次)
 *        php proxy_apply.php --dry-run  (只渲染打印, 不写任何文件)
 * =====================================================================
 */

// ---------- 0. 命令行守卫: 只允许 CLI 运行 ----------
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('本脚本仅允许命令行(root)运行');
}

$DRY   = in_array('--dry-run', $argv);
$FORCE = in_array('--force', $argv);

// ---------- 1. 路径定义(全部写死绝对路径, 防止被相对路径坑) ----------
define('EI_ROOT',     dirname(__FILE__) . '/');
define('RULES_FILE',  EI_ROOT . 'config/config.proxy.php');
define('STATUS_FILE', EI_ROOT . 'admin/logs/tasks/proxy_status.json');
define('MARK_FILE',   EI_ROOT . 'admin/logs/tasks/proxy_pending'); // "待应用"标记文件
define('LOG_FILE',    EI_ROOT . 'admin/logs/tasks/proxy_apply.log');

define('NGX_HTTP_DIR',    '/etc/nginx/conf.d');
define('NGX_STREAM_DIR',  '/etc/nginx/stream.d');
define('NGX_HTTP_CONF',   NGX_HTTP_DIR . '/easyimage-proxy-http.conf');
define('NGX_STREAM_CONF', NGX_STREAM_DIR . '/easyimage-proxy-tcp.conf');
define('CERT_BASE',   '/etc/nginx/proxy_certs');   // 证书存放根目录
define('ACME_WWWROOT', '/var/www/certbot');        // certbot webroot 验证目录

// ---------- 2. 日志函数: 同时打印到屏幕和写入日志文件 ----------
function plog($msg)
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    echo $line;
    if (!is_dir(dirname(LOG_FILE))) {
        @mkdir(dirname(LOG_FILE), 0755, true);
    }
    @file_put_contents(LOG_FILE, $line, FILE_APPEND);
}

// ---------- 3. 状态文件读写: 给后台页面显示"上次应用结果"用 ----------
function write_status($arr)
{
    if (!is_dir(dirname(STATUS_FILE))) {
        @mkdir(dirname(STATUS_FILE), 0755, true);
    }
    $arr['updated_at'] = date('Y-m-d H:i:s');
    @file_put_contents(STATUS_FILE, json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/**
 * 从 /etc/resolv.conf 读取本机 DNS 服务器地址, 供 Nginx resolver 用。
 * (Ubuntu 默认是 systemd-resolved 的 127.0.0.53)
 */
function detect_resolver()
{
    if (is_file('/etc/resolv.conf')) {
        foreach (file('/etc/resolv.conf') as $line) {
            if (preg_match('/^nameserver\s+(\S+)/', trim($line), $m)) {
                return $m[1];
            }
        }
    }
    return '127.0.0.53';
}

// ---------- 4. 主流程 ----------

// 4.1 加载规则文件
if (!is_file(RULES_FILE)) {
    plog('规则文件不存在: ' . RULES_FILE);
    exit(1);
}
$proxyRules = array();
require RULES_FILE;
if (!is_array($proxyRules)) {
    $proxyRules = array();
}

// 4.2 检查"待应用"标记: 没有标记且非 --force 就安静退出
if (!$FORCE && !$DRY && !is_file(MARK_FILE)) {
    exit(0);
}

// 4.3 加载渲染器
require_once EI_ROOT . 'app/proxy_render.php';

// 4.4 证书准备阶段(必须在渲染之前做) --------------------------------
$cert_results = array();
$ready_domains = array();

foreach ($proxyRules as $r) {
    if (empty($r['enabled']) || ($r['type'] ?? '') !== 'http') {
        continue;
    }
    $domain = proxy_sanitize_host($r['entry_domain'] ?? '');
    if ($domain === null) {
        continue;
    }
    $ssl_mode = $r['ssl_mode'] ?? 'auto';
    $cert_dir = CERT_BASE . '/' . $domain;

    if ($ssl_mode === 'none') {
        continue; // 明文模式不需要证书
    }

    if ($ssl_mode === 'manual') {
        // 手动证书: 后台表单粘贴的 PEM 存在规则里, 这里落盘
        $pem_c = trim($r['ssl_cert'] ?? '');
        $pem_k = trim($r['ssl_key'] ?? '');
        if (strpos($pem_c, 'BEGIN CERTIFICATE') !== false && strpos($pem_k, 'PRIVATE KEY') !== false) {
            if (!is_dir($cert_dir)) {
                @mkdir($cert_dir, 0755, true);
            }
            file_put_contents($cert_dir . '/fullchain.pem', $pem_c . "\n");
            file_put_contents($cert_dir . '/privkey.pem', $pem_k . "\n");
            @chmod($cert_dir . '/privkey.pem', 0600); // 私钥只有 root 能读
            $cert_results[$domain] = 'ok(手动证书)';
        } else {
            $cert_results[$domain] = 'fail: 手动证书内容不是有效的 PEM';
        }
    } else {
        // 自动证书: certbot webroot 模式
        // 前提: 主站 80 端口已放行 /.well-known/acme-challenge/ -> ACME_WWWROOT
        // (deploy/nginx-easyimage.conf 模板里已配置)
        if (is_file($cert_dir . '/fullchain.pem')) {
            // 已有证书 -> 检查 LE 目录里是否有更新的(续期后同步过来)
            $live = '/etc/letsencrypt/live/' . $domain . '/fullchain.pem';
            if (is_file($live) && filemtime($live) > filemtime($cert_dir . '/fullchain.pem')) {
                @copy($live, $cert_dir . '/fullchain.pem');
                @copy('/etc/letsencrypt/live/' . $domain . '/privkey.pem', $cert_dir . '/privkey.pem');
                $cert_results[$domain] = 'ok(已续期同步)';
            } else {
                $cert_results[$domain] = 'ok(已存在)';
            }
        } else {
            @mkdir($cert_dir, 0755, true);
            @mkdir(ACME_WWWROOT, 0755, true);
            // 证书到期提醒邮箱: 用域名本身拼一个(LE 只用于发续期失败通知)
            $email = 'admin@' . preg_replace('/[^a-z0-9.\-]/i', '', strtolower($domain));
            $cmd = 'certbot certonly --webroot -w ' . escapeshellarg(ACME_WWWROOT)
                 . ' -d ' . escapeshellarg($domain)
                 . ' --non-interactive --agree-tos -m ' . escapeshellarg($email)
                 . ' --key-type rsa 2>&1';
            exec($cmd, $co, $cc);
            if ($cc === 0) {
                @copy('/etc/letsencrypt/live/' . $domain . '/fullchain.pem', $cert_dir . '/fullchain.pem');
                @copy('/etc/letsencrypt/live/' . $domain . '/privkey.pem', $cert_dir . '/privkey.pem');
                @chmod($cert_dir . '/privkey.pem', 0600);
                $cert_results[$domain] = 'ok(新签发)';
                plog('certbot 签发成功: ' . $domain);
            } else {
                $cert_results[$domain] = 'fail: ' . implode(' ', array_slice($co, -2));
                plog('certbot 签发失败: ' . $domain);
            }
        }
    }

    // 证书文件存在 = "就绪", 渲染器才会为它生成 443 块
    if (is_file($cert_dir . '/fullchain.pem') && is_file($cert_dir . '/privkey.pem')) {
        $ready_domains[] = $domain;
    }
}

// 4.5 渲染两段配置文本
$resolver = detect_resolver();
$http_text   = render_http($proxyRules, CERT_BASE, $resolver, $ready_domains);
$stream_text = render_stream($proxyRules, $resolver);

if ($DRY) {
    echo "===== http 片段 =====\n" . $http_text . "\n";
    echo "===== stream 片段 =====\n" . $stream_text;
    exit(0);
}

// 4.6 备份旧配置(用于 nginx -t 失败时回滚)
$old_http   = is_file(NGX_HTTP_CONF)   ? file_get_contents(NGX_HTTP_CONF)   : null;
$old_stream = is_file(NGX_STREAM_CONF) ? file_get_contents(NGX_STREAM_CONF) : null;

if (!is_dir(NGX_HTTP_DIR)) {
    plog('目录不存在: ' . NGX_HTTP_DIR . ' (Nginx 未安装?)');
    write_status(array('result' => 'error', 'message' => 'Nginx 目录不存在', 'certs' => $cert_results));
    exit(1);
}
if (!is_dir(NGX_STREAM_DIR)) {
    @mkdir(NGX_STREAM_DIR, 0755, true);
}

// 4.7 头部注释 + 写入新配置
$header = "# 由 EasyImage IPv6 代理功能自动生成, 请勿手工编辑!\n"
        . "# 生成时间: " . date('Y-m-d H:i:s') . "\n\n";
file_put_contents(NGX_HTTP_CONF, $header . $http_text);
file_put_contents(NGX_STREAM_CONF, $header . $stream_text);

// 4.8 nginx -t 语法校验: 失败则回滚, 绝不把坏配置推上线
exec('nginx -t 2>&1', $out, $code);
if ($code !== 0) {
    plog('nginx -t 校验失败, 回滚旧配置: ' . implode(' ', $out));
    if ($old_http !== null) {
        file_put_contents(NGX_HTTP_CONF, $old_http);
    } else {
        @unlink(NGX_HTTP_CONF);
    }
    if ($old_stream !== null) {
        file_put_contents(NGX_STREAM_CONF, $old_stream);
    } else {
        @unlink(NGX_STREAM_CONF);
    }
    write_status(array('result' => 'error', 'message' => 'Nginx 语法校验失败: ' . implode(' ', $out), 'certs' => $cert_results));
    exit(1);
}

// 4.9 重载 Nginx(语法已通过校验)
exec('nginx -s reload 2>&1', $ro, $rc);
if ($rc !== 0) {
    plog('nginx reload 失败: ' . implode(' ', $ro));
    write_status(array('result' => 'error', 'message' => 'reload 失败', 'certs' => $cert_results));
    exit(1);
}

// 4.10 一切成功: 删除标记 + 写状态
@unlink(MARK_FILE);
plog('应用完成: http规则=' . substr_count($http_text, 'server {') . ' 块, stream规则=' . substr_count($stream_text, 'server {') . ' 块');
write_status(array('result' => 'success', 'message' => '配置已应用', 'certs' => $cert_results));
