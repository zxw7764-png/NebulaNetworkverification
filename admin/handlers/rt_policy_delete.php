<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * Admin handler: rt_policy_delete
 * 删除运行时策略（§55 审计）
 */

AdminPermission::require($admin, AdminPermission::RT_POLICY_WRITE);

$id = (int) ($requestData['id'] ?? 0);
if ($id <= 0) {
    Response::error(1001, '缺少策略ID');
}

$old = RuntimePolicy::get($id);
if (!$old) {
    // 1002/1003 是前端登出码，业务错误一律用 1001
    Response::error(1001, '策略不存在');
}

$ok = RuntimePolicy::delete($id);

Audit::log($admin, 'rt_policy_delete',
    "运行时策略#{$id}",
    "删除策略: {$old['policy_name']}",
    $old, [], ['policy_id' => $id]
);

Response::ok(['ok' => $ok]);
