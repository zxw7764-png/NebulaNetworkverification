<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * Admin handler: rt_event_list
 * 安全事件列表（§39）
 */

AdminPermission::require($admin, AdminPermission::RT_SECURITY_EVENTS);

$page = max(1, (int) ($requestData['page'] ?? 1));
$size = min(100, max(1, (int) ($requestData['size'] ?? 20)));

$filters = [
    'event_type'  => $requestData['event_type'] ?? '',
    'risk_level'  => $requestData['risk_level'] ?? '',
    'software_id' => (int) ($requestData['software_id'] ?? 0),
    'handled'     => $requestData['handled'] ?? '',
];

[$total, $rows] = RuntimeEventService::listEvents($page, $size, $filters);

Response::ok([
    'total' => $total,
    'page'  => $page,
    'size'  => $size,
    'list'  => $rows,
]);
