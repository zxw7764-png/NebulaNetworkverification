<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * Admin handler: rt_overview
 * Runtime Security Overview（§38）
 * 返回今日安全事件统计、风险会话数、被阻断设备数等
 */

AdminPermission::require($admin, AdminPermission::RT_SECURITY_VIEW);

$softwareId = (int) ($requestData['software_id'] ?? 0);
$stats = RuntimeEventService::overviewStats($softwareId);

// 事件类型分布
$typeDist = Database::all(
    'SELECT event_type, COUNT(*) as cnt
     FROM ' . Database::t('security_events') . '
     WHERE created_at >= ?' . ($softwareId > 0 ? ' AND software_id = ' . (int) $softwareId : '') . '
     GROUP BY event_type ORDER BY cnt DESC LIMIT 20',
    [strtotime('today')]
);

// 最近事件
$recentEvents = Database::all(
    'SELECT id, event_type, risk_level, risk_score, created_at, user_id, session_id
     FROM ' . Database::t('security_events') . '
     WHERE created_at >= ?' . ($softwareId > 0 ? ' AND software_id = ' . (int) $softwareId : '') . '
     ORDER BY id DESC LIMIT 20',
    [strtotime('today')]
);

Response::ok([
    'stats'          => $stats,
    'type_distribution' => $typeDist,
    'recent_events'  => $recentEvents,
]);
