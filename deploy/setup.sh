#!/usr/bin/env bash
# =====================================================================
# EasyImage 2.0 简单图床 - 一键部署脚本 (Ubuntu 20.04 / 22.04)
# ---------------------------------------------------------------------
# 变更日志 (Change Log)
#   日期: 2026-10-07
#   内容: 新增本脚本
#   原因: 之前部署到内网 CT 虚拟机时, 装 Nginx/PHP、设时区、写站点配置、
#         装 crontab、调权限这些操作全是临时散落在一次性脚本里执行的,
#         没有沉淀到项目。换一台全新公网 VPS 部署时, 这些步骤(尤其是
#         crontab 定时删除任务)不会自动出现, 必须重做。
#   效果: 现在把全部服务器初始化步骤固化进本脚本。以后部署新机器只需:
#           1) 把整个项目目录传到服务器(任意路径)
#           2) 在该目录里执行: sudo bash deploy/setup.sh
#         脚本是幂等的(可重复执行), 已装好的服务会跳过或安全地重启。
#
#   日期: 2026-10-07 (追加)
#   内容: 新增 IPv6 反向代理功能的部署支持(第7步):
#         安装 certbot、创建 Nginx stream 挂载目录并在 nginx.conf 注入
#         stream{} 块、建证书目录、给 root 装"每分钟应用代理配置"的 cron。
#         这样部署到新公网 VPS 时, IPv6 代理功能开箱即用, 无需手动配置。
#
# 用法:
#   sudo bash deploy/setup.sh [网站根目录] [访问地址]
#   例1(内网, 用默认值):  sudo bash deploy/setup.sh
#   例2(公网VPS):          sudo bash deploy/setup.sh /var/www/easyimage http://your-domain.com
#
# 参数说明:
#   参数1 WEBROOT : 网站代码放哪, 默认 /var/www/easyimage
#   参数2 SITE_URL: 用户访问图床的地址(域名或IP), 默认 http://服务器IP
#
# 重要: 本脚本【只】在首次部署时改写 config.php 的 domain/imgurl;
#       之后你在后台改的所有设置都会被完整保留, 不会被覆盖。
#       密码永远不由脚本改动 —— 首次部署后请登录后台自行设置。
# =====================================================================

set -e  # 任何一步出错立即停止, 避免"半成品"部署

# ---------- 读取参数(带默认值) ----------
WEBROOT="${1:-/var/www/easyimage}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"   # deploy/ 目录的绝对路径
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"         # 项目根目录(deploy 的上一级)

# 自动探测本机 IP, 作为默认访问地址(公网部署时建议显式传入域名参数)
DETECT_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
SITE_URL="${2:-http://${DETECT_IP:-127.0.0.1}}"

echo "=================================================="
echo " 网站根目录  WEBROOT  = $WEBROOT"
echo " 访问地址    SITE_URL = $SITE_URL"
echo " 项目来源    PROJECT_DIR = $PROJECT_DIR"
echo "=================================================="

# ---------- 0. 安全检查: 必须 root、必须在项目里跑 ----------
if [ "$(id -u)" != "0" ]; then
    echo "错误: 请用 root 运行(sudo bash deploy/setup.sh)"
    exit 1
fi
if [ ! -f "$PROJECT_DIR/index.php" ] || [ ! -f "$PROJECT_DIR/config/config.php" ]; then
    echo "错误: 在 $PROJECT_DIR 找不到图床代码(index.php/config/config.php)"
    echo "提示: 请先进入项目目录再执行本脚本"
    exit 1
fi

# ---------- 1. 安装 Nginx + PHP 8.1 + 图床所需扩展 ----------
# 说明: 这些是"运行环境", 全新 VPS 上什么都没有, 必须先装。
# 如果已安装, apt 会直接跳过, 不会重复安装。
export DEBIAN_FRONTEND=noninteractive
echo "[1/8] 安装运行环境(apt, 可能需要几分钟)..."
apt-get update -y
apt-get install -y nginx php8.1-fpm php8.1-cli \
    php8.1-gd php8.1-curl php8.1-mbstring php8.1-xml php8.1-zip php8.1-bcmath \
    certbot

# ---------- 2. 设置系统时区为上海 ----------
# 说明: 【关键】cron 定时任务按"系统时间"触发。若系统是 UTC, 脚本里的
# "凌晨3:17"实际会跑在北京时间 11:17, 删除日期也会差一天。必须对齐。
echo "[2/8] 设置系统时区 Asia/Shanghai..."
timedatectl set-timezone Asia/Shanghai

# ---------- 3. 把项目代码放到网站根目录 ----------
# 说明: 如果代码本来就在 WEBROOT, 跳过复制; 否则复制过去。
# 【安全设计】故意不使用 rsync --delete:
#   --delete 会把"本地没有但服务器上有"的文件删掉, 而访客上传的图片(i/ 目录)
#   正是这种情况 —— 一旦加上, 每次重新部署都会清空你所有的图片! 绝对不要加。
#   不加 --delete 的代价只是升级后可能残留旧文件, 远比重删图片安全。
# 排除项: .git/docs/deploy 是开发用的, 不需要出现在生产服务器上。
echo "[3/8] 同步代码到 $WEBROOT ..."
mkdir -p "$WEBROOT"
apt-get install -y rsync >/dev/null 2>&1 || true
if [ "$PROJECT_DIR" != "$WEBROOT" ]; then
    rsync -a \
        --exclude '.git' --exclude 'docs' --exclude 'deploy' \
        "$PROJECT_DIR/" "$WEBROOT/"
fi

# ---------- 4. 初始化配置(两种场景, 见下) ----------
# 场景A 部署到新机器/换域名: 显式传入第2个参数(访问地址)时, 【强制】把
#   domain/imgurl 写成该地址 —— 哪怕代码里带着上一台机器的旧地址。
# 场景B 重复部署同一台机器: 不传参数时, 只在 config 仍是出厂默认 127.0.0.1
#   才替换, 否则保留现有值 —— 这样你在后台改过的设置永远不会被脚本抹掉。
echo "[4/8] 初始化 config.php..."
CFG="$WEBROOT/config/config.php"
if [ -n "${2:-}" ]; then
    # 场景A: 用户显式指定了访问地址
    sed -i "s#'domain'=>'[^']*'#'domain'=>'$SITE_URL'#" "$CFG"
    sed -i "s#'imgurl'=>'[^']*'#'imgurl'=>'$SITE_URL'#" "$CFG"
    echo "  按参数强制写入 domain/imgurl = $SITE_URL"
elif grep -q "'domain'=>'http://127.0.0.1'" "$CFG"; then
    # 场景B 的出厂默认情况
    sed -i "s#'domain'=>'http://127.0.0.1'#'domain'=>'$SITE_URL'#" "$CFG"
    sed -i "s#'imgurl'=>'http://127.0.0.1'#'imgurl'=>'$SITE_URL'#" "$CFG"
    echo "  检测到出厂默认地址, 写入 domain/imgurl = $SITE_URL"
else
    echo "  保留 config.php 现有域名设置(不覆盖)"
fi

# 开启自动删除(保留7天)。仅当仍是默认关闭值 0 时才改, 尊重后台已有配置。
if grep -q "'auto_delete'=>0," "$CFG"; then
    sed -i "s#'auto_delete'=>0,#'auto_delete'=>7,#" "$CFG"
    echo "  已开启 auto_delete=7 (保留7天)"
fi

# 创建安装锁: 阻止浏览器访问 /install 向导。
# 【重要】向导会用 bcrypt 写密码, 而登录逻辑用 SHA256 字符串比较, 两者
# 不兼容, 走向导会导致登不进后台, 所以必须锁掉。已存在则不动。
touch "$WEBROOT/config/install.lock"

# ---------- 5. 安装 Nginx 站点配置 ----------
# 说明: 用模板文件替换占位符后写入, 并删除 Ubuntu 自带的默认站点。
echo "[5/8] 配置 Nginx 站点..."
sed "s#__WEBROOT__#$WEBROOT#g" "$SCRIPT_DIR/nginx-easyimage.conf" \
    > /etc/nginx/sites-available/easyimage
ln -sf /etc/nginx/sites-available/easyimage /etc/nginx/sites-enabled/easyimage
rm -f /etc/nginx/sites-enabled/default
nginx -t   # 语法检查, 不通过则脚本因 set -e 停止

# ---------- 6. 调大 PHP 上传限制 ----------
# 说明: PHP 默认只允许上传 2M, 图床默认单文件 10M, 不改会上传失败。
echo "[6/8] 调整 PHP 上传限制..."
PHPINI="/etc/php/8.1/fpm/php.ini"
sed -i 's|^upload_max_filesize.*|upload_max_filesize = 100M|' "$PHPINI"
sed -i 's|^post_max_size.*|post_max_size = 100M|' "$PHPINI"
sed -i 's|^max_execution_time.*|max_execution_time = 300|' "$PHPINI"
sed -i 's|^memory_limit.*|memory_limit = 256M|' "$PHPINI"

# ---------- 7. IPv6 反向代理基础设施 ----------
# 说明: 后台"IPv6代理"功能依赖三样东西, 都在这里一次性装好:
#   a) Nginx stream 模块的挂载目录 + nginx.conf 里的 stream{} 块
#      (Ubuntu 的 nginx 完整版自带 ngx_stream_core_module, 但默认没有
#       stream{} 配置块, 需要我们补上 include 入口)
#   b) certbot 证书验证目录 /var/www/certbot(签发/续期证书用)
#   c) root 用户的 cron: 每分钟跑 proxy_apply.php 应用器
#      【为什么是 root 而不是 www-data】应用器要写 /etc/nginx 并 reload,
#      这些只有 root 能做; 而 Web 进程(www-data)只留"改规则+打标记"的
#      低权限操作, 被攻破也碰不到系统 —— 这是本功能的安全边界。
echo "[7/8] 初始化 IPv6 代理基础设施..."

# a) stream 挂载目录
mkdir -p /etc/nginx/stream.d

# 只在 nginx.conf 还没有 stream{} 块时才注入(幂等, 重复跑不会叠加)
if ! grep -q '^stream {' /etc/nginx/nginx.conf; then
    # 在 http{} 块所在文件的末尾追加 stream{} 块。
    # sed 的 a\ 在最后一行后追加多行文本。
    cat >> /etc/nginx/nginx.conf <<'STREAM_BLOCK'

stream {
    include /etc/nginx/stream.d/*.conf;
}
STREAM_BLOCK
    echo "  已向 nginx.conf 追加 stream{} 块"
else
    echo "  nginx.conf 已有 stream{} 块, 跳过"
fi

# b) certbot webroot 目录(与站点模板里 /.well-known 的 root 一致)
mkdir -p /var/www/certbot
chown -R www-data:www-data /var/www/certbot

# 代理证书存放目录(root 专属, www-data 不可读, 私钥更安全)
mkdir -p /etc/nginx/proxy_certs

# c) root cron: 每分钟检查"待应用"标记并应用代理配置
# 注意: 应用器没有标记时直接退出, 所以每分钟跑一次的开销≈0, 放心。
APPLY_CRON="* * * * * /usr/bin/php $WEBROOT/proxy_apply.php >> $WEBROOT/admin/logs/tasks/proxy_apply_cron.log 2>&1"
# 用"先删旧行再加新行"的方式合并进 root 的 crontab, 保证幂等且不覆盖其他任务
( crontab -u root -l 2>/dev/null | grep -v 'proxy_apply.php' ; echo "$APPLY_CRON" ) | crontab -u root -
echo "  已安装 root crontab: 每分钟应用 IPv6 代理配置"

nginx -t   # stream{} 注入后再验一次语法

# ---------- 8. 设置权限 + 安装 crontab 定时删除 ----------
echo "[8/8] 设置权限并安装定时任务..."
# 整个网站归 www-data(Nginx/PHP 的运行用户), 否则 PHP 无法写图片
chown -R www-data:www-data "$WEBROOT"
chmod -R 755 "$WEBROOT"
chmod 775 "$WEBROOT/i"   # 图片目录需要可写
mkdir -p "$WEBROOT/admin/logs/tasks"
chown -R www-data:www-data "$WEBROOT/admin/logs"

# 【回答你的问题】这一步就是把 crontab 写进系统。
# 全新 VPS 首次跑本脚本时, 定时删除任务会在这里被自动装上, 不需要你手动
# 再去配置。用 "|" 整体覆盖 www-data 的 crontab, 保证可重复执行且不会重复添加。
CRON_LINE="17 3 * * * /usr/bin/php $WEBROOT/cron_auto_delete.php >> $WEBROOT/admin/logs/tasks/cron.log 2>&1"
printf '%s\n' "$CRON_LINE" | crontab -u www-data -
echo "  已安装 crontab: 每天 03:17 自动删除过期图片"

# ---------- 启动/重启服务 ----------
systemctl enable nginx php8.1-fpm >/dev/null 2>&1
systemctl restart php8.1-fpm nginx

# ---------- 结果自检 ----------
echo ""
echo "==================== 部署完成, 自检 ===================="
echo "Nginx 状态 : $(systemctl is-active nginx)"
echo "PHP-FPM    : $(systemctl is-active php8.1-fpm)"
echo "系统时区   : $(timedatectl | grep -oP 'Time zone:\s*\K.*')"
echo "定时删除   : $(crontab -u www-data -l | head -1)"
echo "代理应用器 : $(crontab -u root -l | grep -c proxy_apply) 条 root cron"
echo "stream模块 : $(nginx -V 2>&1 | grep -o with-stream | head -1)"
echo "本机访问   : $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/)"
echo "========================================================"
echo "访问地址: $SITE_URL"
echo "后台地址: $SITE_URL/admin/index.php"
echo ""
echo "下一步必做:"
echo "  1. 账号 admin, 密码沿用 config.php 里已设置的密码(本地改好后随代码一起部署)"
echo "  2. 若密码仍是出厂默认 admin@123, 请立即登录后台修改(有泄露风险, 后台会弹红字提醒)"
echo "  3. 如需 HTTPS, 可安装 certbot 为 $SITE_URL 申请证书"
echo "  4. IPv6 反向代理: 登录后台顶部菜单「IPv6代理」添加规则(需先配好域名解析)"
