<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * action: online
 * 在线人数（公开接口，无需登录）
 * ------------------------------------------------------------------
 * 口径：nb_sessions 中 status = 1 且 last_active 在心跳超时内的会话数，
 *       与后台首页「在线会话」一致。
 * 参数：无
 * 返回：{ online, timeout, server_time }
 *
 * 客户端可频繁轮询（建议 30~60 秒一次），走 IP 限流保护。
 */

Response::ok(Session::onlineStat());
