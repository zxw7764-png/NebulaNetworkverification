<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * Admin handler: rt_policy_detail
 * 运行时策略详情
 */

AdminPermission::require($admin, AdminPermission::RT_POLICY_READ);

$id = (int) ($requestData['id'] ?? 0);
if ($id <= 0) {
    Response::error(1001, '缺少策略ID');
}

$policy = RuntimePolicy::get($id);
if (!$policy) {
    // 1002/1003 是前端登出码，业务错误一律用 1001
    Response::error(1001, '策略不存在');
}

Response::ok([
    'policy' => $policy,
]);
