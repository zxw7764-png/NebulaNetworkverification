<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * action: register
 * 参数: username, password, email(可选)
 */

$r = Auth::register($requestData, Util::ip(), Software::current());

Logger::log('register', $r['ok'] ? 1 : 0, $r['msg'], [
    'username' => Util::str($requestData, 'username', ''),
    'user_id'  => $r['data']['user_id'] ?? 0,
]);

if (!$r['ok']) {
    Response::error($r['code'], $r['msg']);
}

Response::ok($r['data'], $r['msg']);
