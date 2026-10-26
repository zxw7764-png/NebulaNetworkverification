<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * agent action: recharge
 * 代理商兑换充值卡密（余额充值 / 张数额度）
 * ------------------------------------------------------------------
 * 参数: code（充值卡密）
 * 卡密由主管理员在后台「代理商激活码 → 充值卡密」生成。
 *   余额充值卡 → 兑换后余额增加（仅余额计费模式有意义）
 *   张数额度卡 → 兑换后指定卡类型额度增加（-1 = 不限量）
 * 校验 / 扣次数 / 入账由 AgentRecharge::redeem() 在同一事务内完成，
 * 返回最新的代理资料（余额与各类型额度），前端据此直接刷新展示。
 */

$code = Util::str($input, 'code', '');

$r = AgentRecharge::redeem((int) $agent['id'], $code);
if (!$r['ok']) {
    Response::error($r['code'], $r['msg']);
}

Response::ok([
    'detail' => $r['data']['detail'],
    'agent'  => $r['data']['agent'],
], $r['msg']);
