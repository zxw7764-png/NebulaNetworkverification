<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * admin action: software_delete
 * 删除软件（至少保留一个启用软件）
 */

$id = Util::int($input, 'id', 0);

$r = Software::delete($id);
if (!$r['ok']) {
    Response::error(1001, $r['msg']);
}

Response::ok(null, $r['msg']);
