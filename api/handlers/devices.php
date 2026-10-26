<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * action: devices
 * 查询当前账号已绑定的设备列表
 * 参数: token, machine_id（强制设备绑定校验，2026-10-03 审计）
 */

$token = Util::str($requestData, 'token', '');
$v = Session::validate($token, Util::str($requestData, 'machine_id', '') ?: null, true);
if (!$v['ok']) {
    Response::send($v['code'], $v['msg'], ['need_relogin' => true]);
}

$userId = (int) $v['session']['user_id'];
$user   = Database::one('SELECT * FROM ' . Database::t('users') . ' WHERE id = ?', [$userId]);

$list = array_map(function ($d) {
    return [
        'id'           => (int) $d['id'],
        'machine_id'   => $d['machine_id'],
        'device_name'  => $d['device_name'],
        'os_info'      => $d['os_info'],
        'ip'           => $d['ip'],
        'status'       => (int) $d['status'],
        'status_text'  => (int) $d['status'] === 1 ? '正常' : '已解绑',
        'bind_at'      => Util::date((int) $d['bind_at']),
        'last_seen'    => Util::date((int) $d['last_seen']),
        // 设备指纹风险（可能为空：客户端未上报指纹时为 ''）
        'vm'           => (int) ($d['vm_flag'] ?? 0) === 1,
        'risk'         => array_values(array_filter(explode(',', (string) ($d['risk_flags'] ?? '')))),
        'risk_text'    => DeviceFp::flagsText((string) ($d['risk_flags'] ?? '')),
        'online'       => (int) $d['status'] === 1
            && (time() - (int) $d['last_seen']) < Policy::heartbeatTimeout(),
    ];
}, Device::listByUser($userId));

Response::ok([
    'max_devices' => Auth::maxDevices($user),
    'bound_count' => Device::activeCount($userId),
    'current'     => $v['session']['machine_id'],
    'devices'     => $list,
]);
