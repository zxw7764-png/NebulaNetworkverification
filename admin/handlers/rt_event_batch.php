<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * Admin handler: rt_event_batch
 * 批量标记安全事件处理状态（§104 §105: 误报不删除原事件，保留审计数据）
 *
 * 入参: ids=[] (事件ID列表, 最多500), status=0|1|2 (0未处理 1已处理 2误报)
 */

AdminPermission::require($admin, AdminPermission::RT_SECURITY_EVENTS);

$ids    = $requestData['ids'] ?? [];
$status = (int) ($requestData['status'] ?? -1);

if (!is_array($ids) || !$ids) {
    Response::error(1001, '缺少事件ID列表');
}
if (count($ids) > 500) {
    Response::error(1001, '一次最多批量处理 500 条事件');
}
if (!in_array($status, [0, 1, 2], true)) {
    Response::error(1001, '无效的处理状态');
}

$ids      = array_values(array_unique(array_map('intval', $ids)));
$affected = RuntimeEventService::markHandledBatch($ids, $status);

// §55: 审计日志
$statusLabel = $status === 2 ? '误报' : ($status === 1 ? '已处理' : '未处理');
Audit::log($admin, 'rt_event_batch',
    '安全事件批量处理',
    "批量标记 {$statusLabel}: {$affected}/" . count($ids) . ' 条',
    [], [],
    ['ids' => array_slice($ids, 0, 100), 'status' => $status, 'affected' => $affected]
);

Response::ok([
    'ok'       => true,
    'affected' => $affected,
    'requested'=> count($ids),
]);
