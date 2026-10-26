<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * admin action: group_list
 * 用户组列表
 */

$list = Database::all('SELECT * FROM ' . Database::t('groups') . ' ORDER BY id ASC');

foreach ($list as &$g) {
    $g['id']          = (int) $g['id'];
    $g['max_devices'] = (int) $g['max_devices'];
    $g['daily_quota'] = (int) $g['daily_quota'];
    $g['user_count']  = (int) Database::value(
        'SELECT COUNT(*) FROM ' . Database::t('users') . ' WHERE group_id = ?',
        [$g['id']]
    );
    // 配额用量：0=不限时不必统计
    $g['today_calls']      = $g['daily_quota'] > 0 ? Quota::groupUsedToday($g['id']) : 0;
    $g['daily_quota_text'] = $g['daily_quota'] > 0
        ? $g['daily_quota'] . ' 次/人/天'
        : '不限';
    $g['created_at_text'] = Util::date((int) $g['created_at']);
}
unset($g);

Response::ok(['list' => $list]);
