<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * admin action: session_kick
 * 踢出会话（单个 / 批量 / 按用户）
 */

$op        = Util::str($input, 'op', 'single');
$sessionId = Util::int($input, 'session_id', 0);
$userId    = Util::int($input, 'user_id', 0);

if ($op === 'user') {
    if ($userId <= 0) {
        Response::error(1001, '缺少 user_id');
    }
    $n = Session::kickUser($userId);
    Audit::log($admin, 'session_kick', "用户#{$userId}", "踢出用户 #{$userId} 的 {$n} 个会话");
    Response::ok(['count' => $n], "已踢出 {$n} 个会话");
}

// 批量踢出
if ($op === 'batch') {
    $ids = $input['session_ids'] ?? [];
    if (!is_array($ids)) {
        $ids = array_filter(array_map('intval', explode(',', (string) $ids)));
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if (!$ids) {
        Response::error(1001, '请选择会话');
    }
    if (count($ids) > 1000) {
        Response::error(1001, '单次最多操作 1000 个会话');
    }
    $n = 0;
    foreach ($ids as $sid) {
        if (Session::kickById($sid)) {
            $n++;
        }
    }
    Audit::log($admin, 'session_kick', '会话批量', "批量踢出 {$n} 个会话", [], [], ['ids' => $ids]);
    Response::ok(['count' => $n], "已踢出 {$n} 个会话");
}

if ($sessionId <= 0) {
    Response::error(1001, '缺少 session_id');
}

$ok = Session::kickById($sessionId);
Audit::log($admin, 'session_kick', "会话#{$sessionId}", "踢出会话 #{$sessionId}");

Response::ok(null, $ok ? '已踢出该会话' : '操作失败');
