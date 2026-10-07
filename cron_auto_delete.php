<?php
/**
 * =====================================================================
 * 变更日志 (Change Log)
 * ---------------------------------------------------------------------
 * 日期:     2026-10-07
 * 作者:     部署修复脚本 (配合内网服务器 192.168.100.6 定时任务)
 * 变更内容: 新增本脚本 cron_auto_delete.php
 * 变更原因: 原 auto_delete() 函数(app/function.php)在全项目中唯一的
 *           调用点是 admin/admin.inc.php —— 只有管理员打开后台页面时
 *           才会顺带执行一次删除。如果没人访问后台, 过期图片永远不会
 *           被删除, 属于"伪自动"。
 * 改进效果: 本脚本提供一个命令行(CLI)入口, 由 Linux crontab 每天
 *           03:17 (Asia/Shanghai) 自动调用一次, 与是否有人访问后台
 *           完全解耦, 实现真正的定时自动删除。
 * 用法:     php /var/www/easyimage/cron_auto_delete.php
 *           (crontab 中由 www-data 用户执行, 输出追加到日志文件)
 * =====================================================================
 */

/*
 * 第 1 步: 安全守卫 —— 只允许命令行运行
 * php_sapi_name() 返回 PHP 当前的运行方式:
 *   - 'cli'    = 命令行(我们想要的, cron 就是这么跑的)
 *   - 'fpm-fcgi' = 通过 Nginx/PHP-FPM 的网页请求
 * 如果有人从浏览器访问本脚本, 直接返回 403 拒绝,
 * 避免陌生人通过网页反复触发删除操作。
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('此脚本仅允许通过命令行(crontab)运行, 禁止网页访问。' . PHP_EOL);
}

/*
 * 第 2 步: 加载项目基础库
 * app/function.php 内部会先加载 app/base.php, 而 base.php 负责:
 *   a) 定义常量 APP_ROOT (项目根目录的绝对路径)
 *   b) 读取 config/config.php, 得到全局配置数组 $config
 *   c) 按配置设置 PHP 时区 (本站配置为 Asia/Shanghai)
 * 这一步非常重要: auto_delete() 计算"7 天前的目录是哪一天"时,
 * 必须使用和图片上传时完全相同的时区, 否则删除日期会错位一天。
 */
require_once __DIR__ . '/app/function.php';

/*
 * 第 3 步: 调用现成的 auto_delete() 函数, 不重复造轮子
 * 该函数自带开关与条件判断(见 app/function.php 中的定义):
 *   - 仅当 config['auto_delete'] > 0 且存储方式为 Y/m/d/ 时才执行
 *   - 两段式删除:
 *     ① 把 N 天前的图片目录重命名为 "原目录名_auto_delete" (相当于进回收站)
 *     ② 把 2N 天前已进回收站的目录彻底删除
 *   - 每次执行结果都会追加写入 admin/logs/tasks/auto_delete.php 日志
 * 函数返回 true 表示本次执行了删除相关动作, false 表示开关未开启。
 */
$ok = auto_delete();

/*
 * 第 4 步: 在标准输出打印一行执行记录
 * 这行文字会被 crontab 通过 ">> 日志文件" 重定向保存,
 * 方便以后排查"定时任务到底有没有跑、跑成功没有"。
 */
echo date('Y-m-d H:i:s') . ' [cron_auto_delete] 执行完成: '
    . ($ok ? '已处理过期目录' : '未开启或不满足执行条件(检查后台设置 auto_delete)')
    . PHP_EOL;
