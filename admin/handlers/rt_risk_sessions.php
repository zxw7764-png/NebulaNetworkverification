<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * Admin handler: rt_risk_sessions
 * 风险会话列表（§38 §67）
 * 查看当前处于 RISK / BLOCKED 状态的会话
 */

AdminPermission::require($admin, AdminPermission::RT_SECURITY_VIEW);

$page = max(1, (int) ($requestData['page'] ?? 1));
$size = min(100, max(1, (int) ($requestData['size'] ?? 20)));
$softwareId = (int) ($requestData['software_id'] ?? 0);

$base = 'SELECT
    s.id, s.token, s.user_id, s.machine_id, s.ip, s.client_ver,
    s.login_at, s.last_active, s.expire_at, s.status,
    s.rt_status, s.rt_risk_score, s.rt_risk_level, s.rt_flags, s.rt_policy_version,
    u.username
FROM ' . Database::t('sessions') . ' s
LEFT JOIN ' . Database::t('users') . ' u ON u.id = s.user_id
WHERE s.rt_status IN ("RISK", "BLOCKED")';

$params = [];
if ($softwareId > 0) {
    $base .= ' AND s.software_id = ?';
    $params[] = $softwareId;
}

[$total, $rows] = Database::paginate($base, $params, $page, $size, 's.rt_risk_score DESC, s.last_active DESC');

Response::ok([
    'total' => $total,
    'page'  => $page,
    'size'  => $size,
    'list'  => $rows,
]);
