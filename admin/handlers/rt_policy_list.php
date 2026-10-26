<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * Admin handler: rt_policy_list
 * 运行时策略列表
 */

AdminPermission::require($admin, AdminPermission::RT_POLICY_READ);

$page = max(1, (int) ($requestData['page'] ?? 1));
$size = min(100, max(1, (int) ($requestData['size'] ?? 20)));

[$total, $rows] = RuntimePolicy::listAll($page, $size);

Response::ok([
    'total' => $total,
    'page'  => $page,
    'size'  => $size,
    'list'  => $rows,
]);
