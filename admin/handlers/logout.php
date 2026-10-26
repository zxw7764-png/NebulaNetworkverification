<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * admin action: logout
 */

$token = SessionCookie::fromRequest($input);
if ($token) {
    AdminAuth::logout((string) $token);
    Logger::log('admin_logout', 1, '管理员退出', ['admin_id' => (int) $admin['id'], 'username' => $admin['username']]);
}

// 清除会话 Cookie（P1-09）：否则浏览器会继续带着已失效的令牌
SessionCookie::clear();

Response::ok(null, '已退出');
