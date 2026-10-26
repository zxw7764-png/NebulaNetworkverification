<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * admin action: grace_rotate_keys
 * 轮换离线宽限签名密钥（ES256 EC P-256）。
 * 删除旧密钥文件并重生成，旧客户端的离线票据立即失效（需更新重发布）。
 */

$r = Grace::rotateKeys();
if (!$r['ok']) {
    Response::error(1001, '密钥轮换失败：' . ($r['msg'] ?? 'openssl 错误'));
}

Audit::log($admin, 'grace_rotate_keys', '', '轮换离线宽限签名密钥（kid=' . $r['kid'] . '，algo=' . $r['algo'] . '）');

Response::ok([
    'kid'    => $r['kid'],
    'algo'   => $r['algo'],
    'public' => Grace::publicKey() ?? '',
], '密钥已轮换，旧客户端需重新发布');
