<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * admin action: system_update_precheck
 * ------------------------------------------------------------------
 * 升级前预检：不落盘覆盖任何站点文件，只回答「这次更新会动到什么」。
 *
 * 解决的问题（2026-10-26）：
 *   此前后台只有一个「一键更新」按钮，客户在自己站点里改过的文件会被
 *   静默全覆盖（虽然会自动备份到 logs/update_backups/，但那目录只写不读，
 *   回滚要人工 SFTP）。本接口把「你改过哪些文件」「这次会新增哪些」
 *   「这次会删除哪些」在升级前摊开，交给前端做逐项选择。
 *
 * 前置条件：更新包的 manifest.json 必须带 hashes（path => sha256）。
 *   旧包没有哈希，只能返回 has_hash=false 并降级为「将覆盖 N 个文件」，
 *   物理上无法判断哪些被改过 —— 这是数据缺失，不是本接口的缺陷。
 *
 * 请求方式：POST
 * 参数：
 *   download_url  下载地址（HTTPS）
 *   sha256        更新包 SHA-256
 *   version       目标版本号
 *   build         目标 Build（仅回显）
 *
 * 副作用：把更新包下载到 logs/update_packages/pre_<sha前16>.zip 供
 *   system_update_do 复用（避免点「更新」时再下载一次 5MB）；
 *   超过 2 小时的残留 pre_* 包会在本次请求中顺带清理。
 */

$downloadUrl = trim((string) ($input['download_url'] ?? ''));
$sha256      = strtolower(trim((string) ($input['sha256'] ?? '')));
$targetVer   = trim((string) ($input['version'] ?? ''));
$targetBuild = (int) ($input['build'] ?? 0);

if ($targetVer === '' || !preg_match('/^\d+\.\d+\.\d+(\.\d+)?$/', $targetVer)) {
    Response::error(1001, '版本号不合法');
}
if ($sha256 === '' || !preg_match('/^[0-9a-f]{64}$/', $sha256)) {
    Response::error(1001, 'SHA-256 校验值不合法');
}

// 有更新锁在跑时不做预检（避免与执行中的升级抢同一个包目录）
$lockFile = NB_ROOT . '/logs/update.lock';
if (is_file($lockFile) && (time() - (int) @filemtime($lockFile)) < 1800) {
    Response::error(1001, '系统正在更新中，请稍后再试');
}

$pkgDir = NB_ROOT . '/logs/update_packages';
if (!is_dir($pkgDir)) {
    @mkdir($pkgDir, 0750, true);
}
// 复用缓存：同一 sha256 的包已存在就直接用，省一次 5MB 下载
$cachePath = $pkgDir . '/pre_' . substr($sha256, 0, 16) . '.zip';
$needDownload = true;
if (is_file($cachePath)) {
    $cached = @hash_file('sha256', $cachePath);
    if ($cached !== false && hash_equals($sha256, strtolower($cached))) {
        $needDownload = false;
    } else {
        @unlink($cachePath);   // 缓存损坏或对不上，丢弃重下
    }
}

try {
    if ($needDownload) {
        UpdatePackage::download($downloadUrl, $cachePath);
        UpdatePackage::verifySha256($cachePath, $sha256);
    }

    if (!extension_loaded('zip')) {
        throw new RuntimeException('服务器未安装 PHP zip 扩展，无法读取更新包');
    }
    $zip = new ZipArchive();
    if ($zip->open($cachePath, ZipArchive::RDONLY) !== true) {
        @unlink($cachePath);
        throw new RuntimeException('无法打开更新包，文件可能已损坏');
    }

    $manifestIdx = $zip->locateName('manifest.json');
    if ($manifestIdx === false) {
        $zip->close();
        throw new RuntimeException('更新包中未找到 manifest.json');
    }
    $manifest = json_decode((string) $zip->getFromIndex($manifestIdx), true);
    if (!is_array($manifest)) {
        $zip->close();
        throw new RuntimeException('manifest.json 格式错误');
    }
    $product = (string) ($manifest['product'] ?? '');
    if ($product !== 'nebula-verification') {
        $zip->close();
        throw new RuntimeException('产品标识不匹配（' . $product . '），此更新包不适用于当前系统');
    }

    $analysis = UpdatePackage::analyze($zip, $manifest);
    $zip->close();

    // 列表截断：极端情况下（几百个文件全被改）避免一次吐 200KB JSON
    $cap = 500;
    $modified = array_slice($analysis['modified'], 0, $cap);
    $deleted  = array_slice($analysis['deleted'], 0, $cap);

    // 顺手清理超过 2 小时的 pre_* 残留包（预检点了取消就不再需要）
    foreach ((array) @glob($pkgDir . '/pre_*.zip') as $stale) {
        if (is_file($stale) && (time() - (int) @filemtime($stale)) > 7200) {
            @unlink($stale);
        }
    }

    Response::ok([
        'current_version' => defined('NB_VERSION') ? NB_VERSION : '',
        'target_version'  => $targetVer,
        'target_build'    => $targetBuild,
        'has_hash'        => $analysis['has_hash'],
        'counts'          => [
            'entries'   => $analysis['entries'],
            'modified'  => count($analysis['modified']),
            'overwrite' => count($analysis['overwrite']),
            'missing'   => count($analysis['missing']),
            'deleted'   => count($analysis['deleted']),
            'unhashed'  => $analysis['unhashed'],
        ],
        'modified'        => $modified,
        'deleted'         => $deleted,
        'truncated'       => count($analysis['modified']) > $cap || count($analysis['deleted']) > $cap,
        'deleted_declared' => $analysis['deleted_declared'],
    ], '预检完成');
} catch (Throwable $e) {
    Logger::log('admin_system_update', (int) ($admin['id'] ?? 0), '升级预检失败：' . $e->getMessage());
    Response::error(1001, '预检失败：' . $e->getMessage());
}
