<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

// 仅允许由 api.php 引入：直接访问时既没有 $input/$agent/$token，
// 也可能把内部路径与逻辑暴露给扫描器（本机 Apache 环境下 .htaccess 未必生效，故用文件级守卫）。
if (!defined("NB_AGENT_ENTRY")) {
    require_once __DIR__ . '/../../lib/error_page.php';
    nb_error_page(404);
}
/**
 * agent action: password
 * 代理商修改自己的登录密码
 */

$old  = (string) Util::get($input, 'old_password', '');
$new  = (string) Util::get($input, 'new_password', '');
$new2 = (string) Util::get($input, 'new_password2', '');

if ($old === '' || $new === '') {
    Response::error(1001, '请填写原密码与新密码');
}
if ($new !== $new2) {
    Response::error(1001, '两次输入的新密码不一致');
}

$r = Agent::changePassword((int) $agent['id'], $old, $new);
if (!$r['ok']) {
    Response::error(1001, $r['msg']);
}

Response::ok(['changed' => true], $r['msg']);
