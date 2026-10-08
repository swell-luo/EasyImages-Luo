<?php
/*
 * =====================================================================
 * 变更日志 (Change Log)
 * ---------------------------------------------------------------------
 * 日期:     2026-10-07
 * 内容:     新增本文件 - 把后台的代理规则"翻译"成 Nginx 配置文件
 * 原因:     后台(admin/proxy.php)只存"规则数组"(人类可读), 而 Nginx 只
 *           认配置文件。中间需要一层翻译, 好处是: 规则改了只需重跑翻译,
 *           永远不用手写 Nginx 配置, 也不会手滑写错语法。
 * 调用方:   proxy_apply.php (root 定时任务, 项目根目录)
 *
 * 设计要点(初学者请重点理解):
 *   1. 本文件只"生成文本", 不直接写文件、不碰 Nginx —— 职责单一, 方便测试。
 *   2. 生成两段配置:
 *      - render_http()   -> 七层 HTTP(S) 反代, 放进 http{} 里
 *      - render_stream() -> 四层 TCP 转发, 放进 stream{} 里(Nginx 的另一个模块)
 *   3. 所有来自后台的字符串都要"消毒"(过滤引号/换行等), 否则可能把生成的
 *      配置写坏甚至注入危险指令 —— 这叫"输入校验", 是安全第一课。
 *   4. 【证书先后问题】certbot 签证书需要 Nginx 先用 80 端口跑起来, 而
 *      443 配置又要求证书文件已存在。所以: 只有"证书已就绪"的域名才生成
 *      443 块; 没证书的域名本次不生成 443, 等应用器下次跑时证书就有了。
 *   5. 【DDNS 跟随】proxy_pass 直接写域名时 Nginx 只在启动时解析一次。
 *      把目标放进变量(set $var ...)并配 resolver, Nginx 每次请求都重新
 *      解析, 公司 IP 变了自动跟随, 无需 reload。
 * =====================================================================
 */

// ACME 验证文件目录(证书签发用)。正常由 proxy_apply.php 定义; 这里加守卫,
// 保证本文件被单独 require(如测试)时也有默认值, 不会因常量未定义而报错。
if (!defined('ACME_WWWROOT')) {
    define('ACME_WWWROOT', '/var/www/certbot');
}

/**
 * 校验并清洗单个"域名或IP"字符串, 防止注入。
 * 只允许: 字母、数字、连字符、点、冒号(IPv6)、下划线。
 * 不合法直接返回 null, 调用方据此跳过该规则。
 */
function proxy_sanitize_host($raw)
{
    $raw = trim((string)$raw);
    if ($raw === '' || strlen($raw) > 253) {
        return null;
    }
    // 域名/IPv6 合法字符白名单, 其它一律拒绝(挡掉引号、空格、换行、分号)
    if (!preg_match('/^[A-Za-z0-9._:\-]+$/', $raw)) {
        return null;
    }
    return $raw;
}

/**
 * 判断一个字符串是不是 IPv6 字面量(含冒号即视为IPv6).
 * Nginx 里 IPv6 地址必须写成 [2408::1]:443 这种带中括号的形式,
 * 而域名不用, 所以渲染时要区分。
 */
function proxy_is_ipv6($host)
{
    return strpos($host, ':') !== false;
}

/**
 * 校验端口: 必须是 1~65535 的整数。
 */
function proxy_sanitize_port($raw)
{
    $n = (int)$raw;
    if ($n < 1 || $n > 65535) {
        return null;
    }
    return $n;
}

/**
 * 把"允许来源IP"字符串(逗号分隔, 支持CIDR)清洗成安全的数组。
 * 每一项只允许: IPv4/IPv6 字面量 + 可选 /前缀长度。
 */
function proxy_sanitize_allow_ips($raw)
{
    $out = array();
    foreach (preg_split('/[,;\s]+/', trim((string)$raw), -1, PREG_SPLIT_NO_EMPTY) as $item) {
        if (preg_match('/^[A-Za-z0-9.:\/]+$/', $item)) {
            $out[] = $item;
        }
    }
    return $out;
}

/**
 * 渲染"七层 HTTP(S) 反代"配置片段(放进 http{} 上下文)。
 *
 * @param array  $rules        全部规则
 * @param string $cert_base    证书存放根目录(如 /etc/nginx/proxy_certs)
 * @param string $resolver     运行时DNS服务器(让变量式 proxy_pass 跟随 DDNS)
 * @param array  $ready_domains 证书已就绪的入口域名列表(应用器检查文件后传入)
 */
function render_http($rules, $cert_base, $resolver = '127.0.0.53', $ready_domains = array())
{
    $blocks = array();

    foreach ($rules as $r) {
        // 只处理"启用 + http 类型"的规则
        if (empty($r['enabled']) || ($r['type'] ?? '') !== 'http') {
            continue;
        }

        $entry = proxy_sanitize_host($r['entry_domain'] ?? '');
        $target = proxy_sanitize_host($r['target_host'] ?? '');
        $tport = proxy_sanitize_port($r['target_port'] ?? 0);
        if ($entry === null || $target === null || $tport === null) {
            continue; // 字段不合法, 跳过(应用器会另行记录错误)
        }

        $backend_scheme = !empty($r['backend_ssl']) ? 'https' : 'http';
        $ssl_mode = $r['ssl_mode'] ?? 'auto';
        $cert_ready = in_array($entry, $ready_domains, true);

        // ---- 回源地址: IPv6 字面量必须用中括号包起来, 域名则原样 ----
        $target_addr = proxy_is_ipv6($target) ? '[' . $target . ']' : $target;

        // ---- 来源白名单 ----
        $allow = proxy_sanitize_allow_ips($r['allow_ips'] ?? '');
        $acl = '';
        if ($allow) {
            foreach ($allow as $ip) {
                $acl .= "        allow {$ip};\n";
            }
            $acl .= "        deny all;\n";
        }

        // 变量名只允许字母数字, 其它字符剔除。注意要保留 a-z 全部字母,
        // 否则像 gggg7777 这种 id 会被剥成 7777, 与别的规则碰撞, 导致
        // map 变量互相覆盖、转发到错误目标(后台自动生成的 id 是纯 hex,
        // 但这里防御性放宽到全部字母数字, 手动编辑也不会出问题)。
        $safe_id = preg_replace('/[^a-zA-Z0-9]/', '', $r['id'] ?? 'x');
        $var = '$ei_b' . $safe_id;

        // 公共的反代 location(443 块和 80 裸奔块都用它)
        // 【变量式 proxy_pass 的坑】proxy_pass 里一旦用了变量, Nginx 不再
        // 自动把原始请求路径转发给后端, 必须手动拼 $request_uri, 否则访问
        // /nas/file.jpg 会被转成 /(永远只请求根路径)。
        $proxy_loc = "    location / {\n"
                   . $acl
                   . "        proxy_pass {$backend_scheme}://{$var}\$request_uri;\n"
                   . "        proxy_ssl_server_name on;\n"          // https 回源时发送SNI
                   . "        proxy_set_header Host {$entry};\n"     // 让后端知道访问的是哪个域名
                   . "        proxy_set_header X-Real-IP \$remote_addr;\n"
                   . "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n"
                   . "        proxy_set_header X-Forwarded-Proto \$scheme;\n"
                   . "        proxy_http_version 1.1;\n"             // 支持长连接/WebSocket
                   . "        proxy_set_header Upgrade \$http_upgrade;\n"
                   . "        proxy_set_header Connection \"upgrade\";\n"
                   . "    }\n";
        // 变量里直接带上"地址:端口", 供上面 proxy_pass 引用
        $head = "    server_name {$entry};\n"
              . "    resolver {$resolver} valid=30s;\n"
              . "    set {$var} \"{$target_addr}:{$tport}\";\n";

        if ($ssl_mode === 'none') {
            // 不加密(仅测试): 80 端口直接反代
            $blocks[] = "server {\n    listen 80;\n    listen [::]:80;\n" . $head . $proxy_loc . "}\n";
        } elseif ($cert_ready) {
            // 证书已就绪: 443 反代 + 80 强制跳转 https
            $cert_dir = rtrim($cert_base, '/') . '/' . $entry;
            $blocks[] = "server {\n"
                      . "    listen 443 ssl;\n    listen [::]:443 ssl;\n"
                      . $head
                      . "    ssl_certificate     {$cert_dir}/fullchain.pem;\n"
                      . "    ssl_certificate_key {$cert_dir}/privkey.pem;\n"
                      . $proxy_loc . "}\n"
                      . "server {\n    listen 80;\n    listen [::]:80;\n"
                      . "    server_name {$entry};\n"
                      . "    location /.well-known/acme-challenge/ { root " . ACME_WWWROOT . "; }\n"
                      . "    location / { return 301 https://\$host\$request_uri; }\n}\n";
        } else {
            // 证书还没签好(auto 模式): 生成一个"ACME 引导块", 只在 80 端口
            // 放行证书验证路径, 让 certbot 能把证书签下来。签好后下次运行
            // 就会走上面的 cert_ready 分支生成正式 443 配置。
            $blocks[] = "server {\n    listen 80;\n    listen [::]:80;\n"
                      . "    server_name {$entry};\n"
                      . "    location /.well-known/acme-challenge/ { root " . ACME_WWWROOT . "; }\n"
                      . "    location / { return 404; } # 证书签发中, 暂不对外服务\n}\n";
        }
    }

    if (!$blocks) {
        return "# (当前没有可应用的 HTTP 代理规则)\n";
    }
    return implode("\n", $blocks);
}

/**
 * 渲染"四层 TCP 转发"配置片段(放进 stream{} 上下文)。
 * 用于 SSH / RDP / 数据库 等非 HTTP 协议。
 *
 * @param array  $rules    全部规则
 * @param string $resolver 运行时DNS服务器(同 http, 让 stream 也跟随 DDNS)
 */
function render_stream($rules, $resolver = '127.0.0.53')
{
    $maps = array();    // map 指令块(定义目标变量), 必须放 stream{} 顶层
    $blocks = array();  // server 转发块

    foreach ($rules as $r) {
        if (empty($r['enabled']) || ($r['type'] ?? '') !== 'tcp') {
            continue;
        }

        $lport = proxy_sanitize_port($r['listen_port'] ?? 0);
        $target = proxy_sanitize_host($r['target_host'] ?? '');
        $tport = proxy_sanitize_port($r['target_port'] ?? 0);
        if ($lport === null || $target === null || $tport === null) {
            continue;
        }

        $target_addr = proxy_is_ipv6($target) ? '[' . $target . ']' : $target;

        // ---- 四层白名单: TCP 类留空 = 默认拒绝所有(安全底线) ----
        $allow = proxy_sanitize_allow_ips($r['allow_ips'] ?? '');
        $acl = '';
        if ($allow) {
            foreach ($allow as $ip) {
                $acl .= "    allow {$ip};\n";
            }
            $acl .= "    deny all;\n";
        } else {
            // 没填白名单的 TCP 规则: 一律拒绝, 逼使用者显式放行
            $acl = "    deny all; # [安全] TCP规则未填白名单, 默认拒绝所有来源\n";
        }

        $safe_id = preg_replace('/[^a-zA-Z0-9]/', '', $r['id'] ?? 'x');
        $var = '$ei_s' . $safe_id;

        // 【stream 与 http 的关键差异】stream 模块没有 rewrite 模块, 不能
        // 用 set 指令。要让它运行时解析域名, 标准做法是用 map 定义一个
        // "常量变量": map "" $var { default "地址:端口"; } —— 源变量用空串,
        // default 永远命中, 效果等同常量赋值, 但 proxy_pass 引用变量就会
        // 触发 resolver 解析。map 块必须放 stream{} 顶层, 所以单独收集到 $maps。
        $maps[] = "map \"\" {$var} {\n    default \"{$target_addr}:{$tport}\";\n}\n";

        $block = "server {\n"
               . "    listen {$lport};\n"
               . "    listen [::]:{$lport};\n"
               . "    resolver {$resolver} valid=30s;\n"
               . $acl
               . "    proxy_pass {$var};\n"
               . "    proxy_timeout 300s;\n"
               . "}\n";

        $blocks[] = $block;
    }

    if (!$blocks) {
        return "# (当前没有启用的 TCP 代理规则)\n";
    }
    // map 块必须位于 stream{} 顶层、server 块之外, 所以拼在最前面
    return implode("\n", $maps) . "\n" . implode("\n", $blocks);
}
