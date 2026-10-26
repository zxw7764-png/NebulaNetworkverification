<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * action: logout
 * 参数: token
 */

$token = Util::str($requestData, 'token', '');

if ($token !== '') {
    $v = Session::validate($token, Util::str($requestData, 'machine_id', '') ?: null, true);
    if ($v['ok']) {
        Session::destroy($token);
        Logger::log('logout', 1, '退出登录', ['user_id' => (int) $v['session']['user_id']]);
    } else {
        // 2026-10-06 审计：令牌无效（含缺 machine_id / 机器不匹配）时如实上报错误，
        // 不再返回虚假的 logout=true —— 避免客户端误以为会话已注销而服务端仍在生效。
        Response::send($v['code'], $v['msg'], ['need_relogin' => true]);
    }
}

Response::ok(['logout' => true], '已退出登录');
