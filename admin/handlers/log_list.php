<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * admin action: log_list
 * 日志查询（支持按动作、结果、用户、IP、时间范围筛选）
 */

$page     = max(1, Util::int($input, 'page', 1));
$size     = min(200, max(1, Util::int($input, 'size', 30)));
$action   = Util::str($input, 'action_filter', '');
$result   = Util::get($input, 'result', '');
$keyword  = Util::str($input, 'keyword', '');
$userId   = Util::int($input, 'user_id', 0);
$startAt  = Util::int($input, 'start_at', 0);
$endAt    = Util::int($input, 'end_at', 0);

$where  = ['1=1'];
$params = [];

if ($action !== '') {
    $where[] = 'action = :ac';
    $params['ac'] = $action;
}
if ($result !== '' && $result !== null) {
    $where[] = 'result = :rs';
    $params['rs'] = (int) $result;
}
if ($keyword !== '') {
    $where[] = '(username LIKE :kw OR message LIKE :kw2 OR ip LIKE :kw3 OR machine_id LIKE :kw4)';
    $params['kw']  = "%{$keyword}%";
    $params['kw2'] = "%{$keyword}%";
    $params['kw3'] = "%{$keyword}%";
    $params['kw4'] = "%{$keyword}%";
}
if ($userId > 0) {
    $where[] = 'user_id = :uid';
    $params['uid'] = $userId;
}
if ($startAt > 0) {
    $where[] = 'created_at >= :sa';
    $params['sa'] = $startAt;
}
if ($endAt > 0) {
    $where[] = 'created_at <= :ea';
    $params['ea'] = $endAt;
}

$baseSql = 'SELECT * FROM ' . Database::t('logs') . ' WHERE ' . implode(' AND ', $where);
[$total, $rows] = Database::paginate($baseSql, $params, $page, $size, '`id` DESC');

$list = array_map(function ($l) {
    return [
        'id'         => (int) $l['id'],
        'user_id'    => (int) $l['user_id'],
        'username'   => $l['username'],
        'action'     => $l['action'],
        'result'     => (int) $l['result'],
        'result_text'=> (int) $l['result'] === 1 ? '成功' : '失败',
        'message'    => $l['message'],
        'ip'         => $l['ip'],
        'machine_id' => $l['machine_id'],
        'created_at' => Util::date((int) $l['created_at']),
        'time_ts'    => (int) $l['created_at'],
    ];
}, $rows);

// 可选动作列表
$actions = Database::all('SELECT DISTINCT action FROM ' . Database::t('logs') . ' ORDER BY action');

Response::ok([
    'total'   => $total,
    'page'    => $page,
    'size'    => $size,
    'pages'   => (int) ceil($total / $size),
    'list'    => $list,
    'actions' => array_column($actions, 'action'),
]);
