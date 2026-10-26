<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * action: runtime_policy
 * ------------------------------------------------------------------
 * 客户端拉取当前运行时安全策略（§72）
 *
 * 请求：需 3.1 会话信封
 * 响应：Runtime Policy 快照（forClient 格式）
 *
 * §72: 不要单独设计明文 JSON 安全接口，继续使用 Nebula encrypted response。
 */

$sw = Software::current();
$policy = RuntimePolicy::forClient((int) ($sw['id'] ?? 0));

Response::ok([
    'runtime_policy' => $policy,
], 'ok');
