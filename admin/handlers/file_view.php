<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * admin action: file_view
 * 查看受管文件内容（前 64KB），路径白名单校验，杜绝路径穿越。
 *
 * 权限：settings.business（仅超管）。
 */

AdminPermission::require($admin, AdminPermission::SETTINGS_BUSINESS);

$rel = Util::str($input, 'file', '');
if ($rel === '') {
    Response::error(1001, '缺少 file 参数');
}
$content = FileGuard::read($rel);
if ($content === null) {
    Response::error(1004, '文件不存在或类型不允许查看');
}
Response::ok([
    'file'    => $rel,
    'size'    => strlen($content),
    'mtime'   => @filemtime(FileGuard::safePath($rel)) ?: 0,
    'content' => $content,
]);
