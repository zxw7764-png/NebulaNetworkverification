<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

// 仅允许由 api.php 引入：直接访问时既没有 $input/$agent/$token，
// 也可能把内部路径与逻辑暴露给扫描器（本机 Apache 环境下 .htaccess 未必生效，故用文件级守卫）。
if (!defined("NB_AGENT_ENTRY")) {
    require_once __DIR__ . '/../../lib/error_page.php';
    nb_error_page(404);
}
/**
 * agent action: batch_list
 * 我的批次
 */

$agentId = (int) $agent['id'];

$rows = Database::all(
    'SELECT * FROM ' . Database::t('card_batches') . '
     WHERE agent_id = ? ORDER BY id DESC LIMIT 200',
    [$agentId]
);

$list = array_map(static function (array $b) {
    $count = (int) $b['count'];
    $used  = (int) $b['used_count'];
    return [
        'id'         => (int) $b['id'],
        'name'       => (string) ($b['name'] ?? ''),
        'prefix'     => (string) ($b['prefix'] ?? ''),
        'type_text'  => Card::typeName((int) $b['type']),
        'count'      => $count,
        'used_count' => $used,
        'left_count' => max(0, $count - $used),
        'created_at_text' => Util::date((int) $b['created_at']),
    ];
}, $rows);

Response::ok(['list' => $list]);
