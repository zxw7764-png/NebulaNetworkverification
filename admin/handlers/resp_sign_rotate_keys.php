<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * admin action: resp_sign_rotate_keys
 * 轮换响应签名密钥（ES256 EC P-256），独立于离线宽限密钥。
 */

$r = RespSign::rotateKeys();

Audit::log($admin, 'resp_sign_rotate_keys', '', '轮换响应签名密钥（kid=' . $r['kid'] . '，algo=' . $r['algo'] . '）');

Response::ok([
    'kid'        => $r['kid'],
    'algo'       => $r['algo'],
    'public_key' => $r['public_key'],
], '响应签名密钥已轮换，旧签名立即失效');
