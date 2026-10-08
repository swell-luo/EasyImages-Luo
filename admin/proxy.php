<?php
/*
 * =====================================================================
 * 变更日志 (Change Log)
 * ---------------------------------------------------------------------
 * 日期:     2026-10-07
 * 内容:     新增本文件 - IPv6 反向代理「后台管理页」
 * 原因:     让你不用手改配置文件, 在图床后台图形界面里就能增删改代理规则。
 *           页面只做两件事: (1)把规则存进 config/config.proxy.php;
 *           (2)创建一个"待应用"标记文件。真正写 Nginx 配置 + reload 的
 *           脏活累活由 root 定时任务 proxy_apply.php 完成(职责分离, 更安全)。
 *
 * 初学者提示: 本文件复用了图床现成的三件套 ——
 *   app/header.php   页面骨架(导航/样式)
 *   is_who_login()   管理员鉴权
 *   cache_write()    把 PHP 数组写回配置文件
 * =====================================================================
 */

require_once __DIR__ . '/../app/header.php';

// ---------- 鉴权: 只有管理员能进 ----------
if (!is_who_login('admin')) {
    echo '<script> new $.zui.Messager("请使用管理员账户登录!", {type:"danger",icon:"exclamation-sign"}).show();</script>';
    header("refresh:2;url=" . $config['domain'] . "/admin/index.php");
    require_once APP_ROOT . '/app/footer.php';
    exit;
}

// ---------- 路径定义 ----------
$proxy_file   = APP_ROOT . '/config/config.proxy.php';
$mark_file    = APP_ROOT . '/admin/logs/tasks/proxy_pending';       // 待应用标记
$status_file  = APP_ROOT . '/admin/logs/tasks/proxy_status.json';   // 应用器回写的状态

// ---------- 读取现有规则 ----------
$proxyRules = array();
if (is_file($proxy_file)) {
    require $proxy_file;
}
if (!is_array($proxyRules)) {
    $proxyRules = array();
}

// ---------- 工具: 保存规则 + 打"待应用"标记 ----------
function proxy_save($rules, $file, $mark)
{
    // cache_write 第三个参数 'proxyRules' 决定生成的变量名
    cache_write($file, $rules, 'proxyRules');
    // touch 标记文件, 告诉 root 应用器"有改动, 下次运行时应用"
    if (!is_dir(dirname($mark))) {
        @mkdir(dirname($mark), 0755, true);
    }
    @touch($mark);
}

// ---------- 处理表单动作(增/改/删/启用停用) ----------
$action = $_POST['action'] ?? '';

if ($action === 'save') {
    // 新增或编辑一条规则
    $id = trim($_POST['id'] ?? '');
    if ($id === '') {
        $id = bin2hex(random_bytes(4)); // 自动生成8位编号
    }
    $rule = array(
        'id'          => $id,
        'name'        => trim($_POST['name'] ?? ''),
        'type'        => ($_POST['type'] ?? 'http') === 'tcp' ? 'tcp' : 'http',
        'entry_domain'=> trim($_POST['entry_domain'] ?? ''),
        'listen_port' => (int)($_POST['listen_port'] ?? 0),
        'target_host' => trim($_POST['target_host'] ?? ''),
        'target_port' => (int)($_POST['target_port'] ?? 0),
        'backend_ssl' => isset($_POST['backend_ssl']) ? 1 : 0,
        'ssl_mode'    => in_array($_POST['ssl_mode'] ?? '', array('auto','manual','none')) ? $_POST['ssl_mode'] : 'auto',
        // 手动证书: 仅当 ssl_mode=manual 时才保存粘贴的 PEM(自动/关闭模式清空, 避免残留)
        'ssl_cert'    => (($_POST['ssl_mode'] ?? '') === 'manual') ? trim($_POST['ssl_cert'] ?? '') : '',
        'ssl_key'     => (($_POST['ssl_mode'] ?? '') === 'manual') ? trim($_POST['ssl_key'] ?? '') : '',
        'allow_ips'   => trim($_POST['allow_ips'] ?? ''),
        'enabled'     => isset($_POST['enabled']) ? 1 : 0,
    );

    // 覆盖同 id 的规则(编辑), 否则追加(新增)
    $found = false;
    foreach ($proxyRules as $i => $r) {
        if (($r['id'] ?? '') === $id) {
            $proxyRules[$i] = $rule;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $proxyRules[] = $rule;
    }

    proxy_save($proxyRules, $proxy_file, $mark_file);
    $msg = '已保存, 最长 1 分钟内自动生效';
} elseif ($action === 'delete') {
    $id = trim($_POST['id'] ?? '');
    $proxyRules = array_values(array_filter($proxyRules, function ($r) use ($id) {
        return ($r['id'] ?? '') !== $id;
    }));
    proxy_save($proxyRules, $proxy_file, $mark_file);
    $msg = '已删除';
} elseif ($action === 'toggle') {
    $id = trim($_POST['id'] ?? '');
    foreach ($proxyRules as $i => $r) {
        if (($r['id'] ?? '') === $id) {
            $proxyRules[$i]['enabled'] = empty($r['enabled']) ? 1 : 0;
        }
    }
    proxy_save($proxyRules, $proxy_file, $mark_file);
    $msg = '已切换启用状态';
}

// ---------- 读取"上次应用结果"(给页面顶部显示) ----------
$status = null;
if (is_file($status_file)) {
    $status = json_decode(file_get_contents($status_file), true);
}
$pending = is_file($mark_file); // 是否还有改动没被应用

// 编辑模式: ?edit=id 时把该规则填进表单
$editing = null;
if (isset($_GET['edit'])) {
    foreach ($proxyRules as $r) {
        if (($r['id'] ?? '') === $_GET['edit']) {
            $editing = $r;
        }
    }
}
// 表单默认值(新增时用空, 编辑时用该规则)
$f = function ($key, $default = '') use ($editing) {
    $v = $editing[$key] ?? $default;
    return htmlspecialchars((string)$v, ENT_QUOTES);
};

?>

<div class="panel panel-default">
    <div class="panel-heading">
        <b><i class="icon icon-sitemap"></i> IPv6 反向代理</b>
        <span class="pull-right text-muted">
            <?php if ($pending): ?>
                <span class="label label-warning">待应用</span>
            <?php elseif ($status && ($status['result'] ?? '') === 'success'): ?>
                <span class="label label-success">已生效</span>
            <?php elseif ($status && ($status['result'] ?? '') === 'error'): ?>
                <span class="label label-danger">应用失败</span>
            <?php else: ?>
                <span class="label label-default">未应用</span>
            <?php endif; ?>
        </span>
    </div>
    <div class="panel-body">

    <?php if (!empty($msg)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <?php if ($status && ($status['result'] ?? '') === 'error'): ?>
        <div class="alert alert-danger">
            上次应用失败: <?php echo htmlspecialchars($status['message'] ?? ''); ?>
            <br><small>请检查规则填写是否正确(常见: 域名没解析、端口冲突、Nginx语法)。</small>
        </div>
    <?php endif; ?>

    <p class="text-muted">
        用途: 家里纯 IPv4 通过 VPS 反代访问公司 IPv6 服务。<br>
        <b>HTTP 服务</b>(NAS/面板/网站) 用「子域名」方式; <b>SSH/RDP 等</b>用「端口转发」方式。<br>
        前提: 入口域名 A 记录 -> VPS 的 IPv4; 回源域名 AAAA -> 公司 IPv6(你侧 DDNS 维护)。
    </p>

    <!-- ===================== 规则列表 ===================== -->
    <table class="table table-hover table-bordered">
        <thead>
            <tr>
                <th>名称</th><th>类型</th><th>入口(家里访问)</th>
                <th>回源(公司)</th><th>白名单</th><th>状态</th><th>操作</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$proxyRules): ?>
            <tr><td colspan="7" class="text-center text-muted">还没有规则, 在下方添加第一条。</td></tr>
        <?php else: foreach ($proxyRules as $r):
            $type = ($r['type'] ?? 'http');
            if ($type === 'http') {
                $entry = htmlspecialchars($r['entry_domain'] ?? '') . ' <span class="label label-default">443</span>';
            } else {
                $entry = 'VPS_IP:' . (int)($r['listen_port'] ?? 0);
            }
            $target = htmlspecialchars(($r['target_host'] ?? '')) . ':' . (int)($r['target_port'] ?? 0);
        ?>
            <tr>
                <td><?php echo htmlspecialchars($r['name'] ?? ''); ?></td>
                <td><?php echo $type === 'http' ? '<span class="label label-primary">HTTP</span>' : '<span class="label label-info">TCP</span>'; ?></td>
                <td><?php echo $entry; ?></td>
                <td><code><?php echo $target; ?></code></td>
                <td><small><?php
                    if (!empty($r['allow_ips'])) {
                        echo htmlspecialchars($r['allow_ips']);
                    } elseif ($type === 'tcp') {
                        echo '<span class="text-danger">留空=拒绝所有!</span>';
                    } else {
                        echo '<span class="text-muted">允许所有</span>';
                    }
                ?></small></td>
                <td><?php echo empty($r['enabled']) ? '<span class="label label-default">停用</span>' : '<span class="label label-success">启用</span>'; ?></td>
                <td nowrap>
                    <a class="btn btn-xs btn-default" href="?edit=<?php echo $r['id']; ?>"><i class="icon icon-edit"></i> 编辑</a>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                        <button class="btn btn-xs btn-default"><i class="icon icon-power-off"></i></button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return confirm('确定删除该规则?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                        <button class="btn btn-xs btn-danger"><i class="icon icon-trash"></i></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <!-- ===================== 新增 / 编辑表单 ===================== -->
    <div class="panel panel-<?php echo $editing ? 'warning' : 'default'; ?>">
        <div class="panel-heading">
            <?php echo $editing ? '编辑规则: ' . htmlspecialchars($editing['name'] ?? '') : '添加新规则'; ?>
            <?php if ($editing): ?><a class="pull-right" href="?">取消编辑</a><?php endif; ?>
        </div>
        <div class="panel-body">
        <form method="post" class="form-horizontal">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?php echo $f('id'); ?>">

            <div class="form-group">
                <label class="col-sm-2 control-label">名称</label>
                <div class="col-sm-10">
                    <input class="form-control" name="name" value="<?php echo $f('name'); ?>" placeholder="如: 公司NAS" required>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-2 control-label">类型</label>
                <div class="col-sm-10">
                    <label class="radio-inline">
                        <input type="radio" name="type" value="http" <?php echo ($f('type','http')==='http')?'checked':''; ?> onclick="proxyTypeChange('http')"> HTTP(S) 网页服务
                    </label>
                    <label class="radio-inline">
                        <input type="radio" name="type" value="tcp" <?php echo ($f('type','http')==='tcp')?'checked':''; ?> onclick="proxyTypeChange('tcp')"> TCP 端口(SSH/RDP等)
                    </label>
                </div>
            </div>

            <!-- HTTP 专属: 入口域名 + 证书 -->
            <div class="proxy-http">
                <div class="form-group">
                    <label class="col-sm-2 control-label">入口域名</label>
                    <div class="col-sm-10">
                        <input class="form-control" name="entry_domain" value="<?php echo $f('entry_domain'); ?>" placeholder="nas.example.com (A记录指向VPS的IPv4)">
                        <span class="help-block">家里浏览器访问这个域名 -> 到达 VPS。留空则 HTTP 规则无效。</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-2 control-label">入口证书</label>
                    <div class="col-sm-10">
                        <select name="ssl_mode" class="form-control" onchange="proxySslModeChange(this.value)">
                            <option value="auto" <?php echo $f('ssl_mode','auto')==='auto'?'selected':''; ?>>自动申请 Let's Encrypt (推荐)</option>
                            <option value="manual" <?php echo $f('ssl_mode')==='manual'?'selected':''; ?>>手动上传证书</option>
                            <option value="none" <?php echo $f('ssl_mode')==='none'?'selected':''; ?>>不启用HTTPS(仅测试)</option>
                        </select>
                    </div>
                </div>
                <!-- 手动证书: 只有选了"手动上传"才显示这两个粘贴框 -->
                <div class="proxy-ssl-manual" style="display:none">
                    <div class="form-group">
                        <label class="col-sm-2 control-label">证书 fullchain</label>
                        <div class="col-sm-10">
                            <textarea name="ssl_cert" rows="4" class="form-control" placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----"><?php echo $f('ssl_cert'); ?></textarea>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-2 control-label">私钥 privkey</label>
                        <div class="col-sm-10">
                            <textarea name="ssl_key" rows="4" class="form-control" placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----"><?php echo $f('ssl_key'); ?></textarea>
                            <span class="help-block">私钥会存进 config.proxy.php 并由应用器写入 root 专属目录。若介意, 优先用"自动申请"。</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TCP 专属: 监听端口 -->
            <div class="proxy-tcp" style="display:none">
                <div class="form-group">
                    <label class="col-sm-2 control-label">VPS 监听端口</label>
                    <div class="col-sm-10">
                        <input class="form-control" name="listen_port" value="<?php echo $f('listen_port'); ?>" placeholder="2222">
                        <span class="help-block">家里用 <code>ssh -p 2222 VPS_IP</code> 访问。别和已有端口冲突。</span>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-2 control-label">回源地址</label>
                <div class="col-sm-10">
                    <input class="form-control" name="target_host" value="<?php echo $f('target_host'); ?>" placeholder="office6.example.com 或 2408:xxxx::1">
                    <span class="help-block">公司机器的 IPv6 或 DDNS 域名(推荐域名, 自动跟随IP变化)。</span>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-2 control-label">回源端口</label>
                <div class="col-sm-10">
                    <input class="form-control" name="target_port" value="<?php echo $f('target_port'); ?>" placeholder="443 / 80 / 22">
                </div>
            </div>

            <!-- HTTP 专属: 回源协议 -->
            <div class="proxy-http">
                <div class="form-group">
                    <label class="col-sm-2 control-label">回源协议</label>
                    <div class="col-sm-10">
                        <label class="checkbox-inline">
                            <input type="checkbox" name="backend_ssl" value="1" <?php echo $f('backend_ssl')?'checked':''; ?>> 公司服务本身是 HTTPS
                        </label>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-2 control-label">来源白名单</label>
                <div class="col-sm-10">
                    <input class="form-control" name="allow_ips" value="<?php echo $f('allow_ips'); ?>" placeholder="1.2.3.4, 2001:db8::/32">
                    <span class="help-block">逗号分隔, 支持CIDR。<b>TCP 留空=默认拒绝所有</b>(安全底线); HTTP 留空=允许所有。</span>
                </div>
            </div>

            <div class="form-group">
                <div class="col-sm-offset-2 col-sm-10">
                    <label class="checkbox-inline">
                        <input type="checkbox" name="enabled" value="1" <?php echo ($editing && !empty($editing['enabled']))||(!$editing)?'checked':''; ?>> 启用
                    </label>
                    <button type="submit" class="btn btn-primary"><i class="icon icon-save"></i> 保存</button>
                    <?php if ($editing): ?><a class="btn btn-default" href="?">取消</a><?php endif; ?>
                </div>
            </div>
        </form>
        </div>
    </div>

    </div>
</div>

<script>
// 根据类型切换显示 HTTP 专属字段 / TCP 专属字段
function proxyTypeChange(t){
    document.querySelectorAll('.proxy-http').forEach(function(el){ el.style.display = (t==='http')?'':'none'; });
    document.querySelectorAll('.proxy-tcp').forEach(function(el){ el.style.display = (t==='tcp')?'':'none'; });
}
// 证书模式为"手动"时才显示证书粘贴框
function proxySslModeChange(v){
    document.querySelectorAll('.proxy-ssl-manual').forEach(function(el){ el.style.display = (v==='manual')?'':'none'; });
}
// 页面加载时按当前选中项初始化显示
document.addEventListener('DOMContentLoaded', function(){
    var checked = document.querySelector('input[name="type"]:checked');
    if (checked) proxyTypeChange(checked.value);
    var ssl = document.querySelector('select[name="ssl_mode"]');
    if (ssl) proxySslModeChange(ssl.value);
});
</script>

<?php require_once APP_ROOT . '/app/footer.php'; ?>
