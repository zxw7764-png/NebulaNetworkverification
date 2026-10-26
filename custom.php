<?php
/**
 * Nebula 自定义扩展入口
 * ------------------------------------------------------------------
 * 这是给「客户自己」留的文件。你的所有定制代码都可以写在这里。
 *
 * 它不会被写进更新包，升级时永远不会被覆盖 —— 这是它与项目里其他
 * PHP 文件的唯一区别，也是它能放心改的原因。
 *
 * 详细说明与完整事件清单见：docs/EXTENDING.md
 */

// 本文件只应由 lib/bootstrap.php include，禁止浏览器直接访问。
// 少了这两行，别人访问 https://你的域名/custom.php 就能触发你写的代码。
if (!defined('NB_ROOT')) {
    http_response_code(404);
    exit;
}

// ==================================================================
// 一、能做什么
// ==================================================================
// 用 Event::on('事件名', 处理函数) 注册监听器。系统在对应时机自动调用你。
//
// 内置事件：
//   user.register   新用户注册成功
//   user.login      用户登录成功（含卡密直登、自动建号）
//   user.ban        账号被后台封禁或冻结
//   user.unban      账号被后台解封
//   user.expire     会员到期（每次到期只触发一次，不会每分钟重复）
//   card.activate   激活码使用成功
//   heartbeat       客户端心跳
//
// 你也能自己造事件名，然后在自己改的其他文件里 Event::emit('你自己的名字', [...])

// ==================================================================
// 二、可以在这里直接写任意 PHP
// ==================================================================
// 此时数据库、缓存、日志、配置都已就绪，直接用：
//   Config::get('site_name')      读配置
//   Database::one('SELECT ...')   查库
//   Setting::get('skey')          读后台设置
//   Logger::log('myapp', 1, '...') 写日志（会出现在后台日志页）
//   Cache::get / Cache::set       读写缓存

// ==================================================================
// 三、示例（取消注释即生效）
// ==================================================================

// ---- 示例 1：注册成功后，把用户同步到自己的接口 ----
// Event::on('user.register', function (array $d) {
//     // $d = ['user_id', 'username', 'email', 'ip', 'software_id', 'time']
//     $ctx = stream_context_create(['http' => [
//         'method'  => 'POST',
//         'header'  => "Content-Type: application/json\r\n",
//         'content' => json_encode(['name' => $d['username'], 'uid' => $d['user_id']]),
//         'timeout' => 3,
//     ]]);
//     @file_get_contents('https://your-server.example/api/sync', false, $ctx);
// });

// ---- 示例 2：登录成功后记一笔自己的账 ----
// Event::on('user.login', function (array $d) {
//     // $d = ['user_id', 'username', 'ip', 'method', 'group_id', 'vip_expire', 'points', 'time']
//     Database::insert('my_login_log', [
//         'user_id'    => $d['user_id'],
//         'ip'         => $d['ip'],
//         'created_at' => time(),
//     ]);
// }, 20);   // 第三个参数是优先级，数字大的先执行，默认 10

// ---- 示例 3：激活码用掉后通知客服（只触发一次）----
// Event::once('card.activate', function (array $d) {
//     Logger::log('myapp', 1, '卡密激活：' . $d['code']);
// });

// ---- 示例 4：会员刚过期时，给他发一封提醒 ----
// Event::on('user.expire', function (array $d) {
//     // $d = ['user_id', 'username', 'vip_expire', 'expired_at', 'remain']
//     Logger::log('myapp', 1, '会员到期：' . $d['username']);
// });
// 注意：本事件已经自带去重，同一次到期只会触发一次，不用你操心频率。

// ==================================================================
// 四、三个必须知道的坑
// ==================================================================
// 1) 你的代码出错不会影响系统 —— 异常会被吞掉并记进后台日志。
//    所以「没收到通知」时，先去后台日志页搜一下 hook 记录。
//
// 2) heartbeat 事件频率很高（在线用户数 × 每分钟一次）。
//    千万别在里面做同步 HTTP 请求或大查询，否则会拖垮整个站点。
//    它只适合往缓存里写东西，由定时任务再去处理。
//
// 3) 这里只能用运行时已经加载的类。如果你想用某个类却提示找不到，
//    先 require 一下，或者选择在其他更早的位置引入。
