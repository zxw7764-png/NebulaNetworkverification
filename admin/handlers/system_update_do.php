<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * admin action: system_update_do
 * ------------------------------------------------------------------
 * 一键自动更新：下载更新包 → SHA-256 校验 → 备份 → 解压覆盖 → 处理删除 → 更新版本号
 *
 * 请求方式：POST
 * 参数：
 *   download_url   下载地址
 *   sha256         SHA-256 校验值
 *   version        目标版本号
 *   build          目标 Build 编号
 *   keep           升级时要「保留本站版本、不覆盖」的路径清单
 *                  （数组或逗号/换行分隔字符串；由 system_update_precheck 的
 *                   modified 列表让用户勾选得出。缺省=全部覆盖，保持旧行为）
 *   delete_removed 是否删除包内 DELETED.txt 声明的「新版已移除」文件
 *                  （默认 1。旧版升级器从不删文件，被移除的 PHP 会永久
 *                   留在站点根目录，若因漏洞被移除则旧文件仍可被直接访问）
 */

// ------------------------------------------------------------------
// 参数校验
// ------------------------------------------------------------------
$downloadUrl = trim((string) ($input['download_url'] ?? ''));
$sha256      = strtolower(trim((string) ($input['sha256'] ?? '')));
$targetVer   = trim((string) ($input['version'] ?? ''));
$targetBuild = (int) ($input['build'] ?? 0);

if ($downloadUrl === '' || !preg_match('#^https://#i', $downloadUrl)) {
    Response::error(1001, '下载地址不合法（仅允许 HTTPS）');
}
if ($sha256 === '' || !preg_match('/^[0-9a-f]{64}$/', $sha256)) {
    Response::error(1001, 'SHA-256 校验值不合法');
}
if ($targetVer === '' || !preg_match('/^\d+\.\d+\.\d+(\.\d+)?$/', $targetVer)) {
    Response::error(1001, '版本号不合法');
}

// 保留清单归一化：接受数组，也接受逗号/换行分隔字符串。
// 只保留「安全且非保护目录」的路径，其余静默丢弃（前端传脏不影响升级）。
$keepRaw = $input['keep'] ?? [];
if (is_string($keepRaw)) {
    $keepRaw = preg_split('/[\s,]+/', $keepRaw) ?: [];
}
$keepSet = [];
foreach ((array) $keepRaw as $k) {
    if (!is_string($k)) {
        continue;
    }
    $k = str_replace('\\', '/', ltrim(trim($k), '/'));
    if ($k === '' || !UpdatePackage::isSafe($k) || UpdatePackage::isProtected($k)) {
        continue;
    }
    $keepSet[$k] = true;
}
$deleteRemoved = !isset($input['delete_removed']) || !empty($input['delete_removed']);

// ------------------------------------------------------------------
// 更新锁（防并发）
// ------------------------------------------------------------------
$lockFile = NB_ROOT . '/logs/update.lock';
if (is_file($lockFile)) {
    $lockAge = time() - (int) @filemtime($lockFile);
    if ($lockAge < 1800) {
        Response::error(1001, '系统正在更新中，请 ' . ceil((1800 - $lockAge) / 60) . ' 分钟后再试');
    }
    @unlink($lockFile);
}
$lockDir = dirname($lockFile);
if (!is_dir($lockDir)) @mkdir($lockDir, 0750, true);
$fp = @fopen($lockFile, 'x');
if ($fp === false) {
    Response::error(1001, '获取更新锁失败，请稍后重试');
}
fwrite($fp, json_encode(['pid' => getmypid(), 'time' => time(), 'ip' => Util::ip()]));
fclose($fp);

// ------------------------------------------------------------------
// 存储目录
// ------------------------------------------------------------------
$pkgDir   = NB_ROOT . '/logs/update_packages';
$bakDir   = NB_ROOT . '/logs/update_backups/' . date('Ymd_His');
if (!is_dir($pkgDir)) @mkdir($pkgDir, 0750, true);
if (!is_dir($bakDir)) @mkdir($bakDir, 0750, true);

$pkgPath = $pkgDir . '/update_' . $targetVer . '.zip';
// 预检阶段已下载过同一个包时直接复用，避免再拉一次（license 模式下载链接
// 是限时令牌，复用还能规避「预检停留太久、点更新时令牌已过期」）
$prePath = $pkgDir . '/pre_' . substr($sha256, 0, 16) . '.zip';
if (is_file($prePath)) {
    $preHash = @hash_file('sha256', $prePath);
    if ($preHash !== false && hash_equals($sha256, strtolower($preHash))) {
        $pkgPath = $prePath;
    }
}

try {

    // ----------------------------------------------------------------
    // 1. 下载更新包 + 2. SHA-256 校验
    // ----------------------------------------------------------------
    // 下载边界（仅 HTTPS / 主机白名单 / 302 后最终主机复核）统一在
    // UpdatePackage::download 内实现，与升级前预检共用同一套判定。
    // 已存在的包先按 sha256 复核：对不上说明是上次失败留下的半成品，丢弃重下。
    if (is_file($pkgPath)) {
        $h = @hash_file('sha256', $pkgPath);
        if ($h === false || !hash_equals($sha256, strtolower($h))) {
            @unlink($pkgPath);
        }
    }
    if (!is_file($pkgPath)) {
        UpdatePackage::download($downloadUrl, $pkgPath);
    }
    UpdatePackage::verifySha256($pkgPath, $sha256);

    // ----------------------------------------------------------------
    // 3. 打开 ZIP 并验证 manifest
    // ----------------------------------------------------------------
    if (!extension_loaded('zip')) {
        throw new RuntimeException('服务器未安装 PHP zip 扩展，无法解压更新包');
    }
    $zip = new ZipArchive();
    if ($zip->open($pkgPath) !== true) {
        throw new RuntimeException('无法打开更新包，文件可能已损坏');
    }

    // 读取 manifest.json
    $manifestIdx = $zip->locateName('manifest.json');
    if ($manifestIdx === false) {
        $zip->close();
        throw new RuntimeException('更新包中未找到 manifest.json');
    }
    $manifestJson = $zip->getFromIndex($manifestIdx);
    $manifest = json_decode($manifestJson, true);
    if (!is_array($manifest)) {
        $zip->close();
        throw new RuntimeException('manifest.json 格式错误');
    }

    // 校验产品标识
    $manifestProduct = $manifest['product'] ?? '';
    if ($manifestProduct !== 'nebula-verification') {
        $zip->close();
        throw new RuntimeException('产品标识不匹配（' . $manifestProduct . '），此更新包不适用于当前系统');
    }

    // ----------------------------------------------------------------
    // 3.1 计算本次升级的完整影响面（覆盖 / 保留 / 删除）
    // ----------------------------------------------------------------
    // 后台目录在打包时固定为 admin/，这里按本站实际目录名重写。映射规则与
    // 升级前预检同一份实现（UpdatePackage::mapEntry），避免两处判定漂移。
    // 自动探测特征：AdminAuth.php + assets/js/app.js + handlers/，可命中
    // 多个随机目录，保证改名后的站点后台也能被更新（2026-10-06 修复：
    // 生产曾因未配置 admin.path 导致后台目录整段漏更）。
    $adminNames = UpdatePackage::adminDirNames();

    // 待删除清单（新版已移除的文件）。只处理「包内声明 + 本站确实存在 +
    // 路径安全 + 非保护目录」的项，且需用户未关闭删除开关。
    $delTargets = [];
    if ($deleteRemoved) {
        foreach (UpdatePackage::readDeletedList($zip) as $packed) {
            foreach (UpdatePackage::mapEntry($packed, $adminNames) as $rel) {
                if (isset($delTargets[$rel])) continue;
                if (!UpdatePackage::isSafe($rel) || UpdatePackage::isProtected($rel)) continue;
                if (!is_file(UpdatePackage::localPath($rel))) continue;
                $delTargets[$rel] = $packed;
            }
        }
    }

    // ----------------------------------------------------------------
    // 4. 备份当前文件
    // ----------------------------------------------------------------
    // 备份范围 = 「包内会覆盖的已存在文件」+「将被删除的已存在文件」。
    // 注意：备份是冷备份，全代码库没有读取方，回滚需人工把文件拷回原位。
    $filesBackedUp = 0;
    $zipRels = [];   // 包内实际会落盘的路径，用于避免与删除清单自相矛盾
    $backupOne = static function (string $rel) use ($bakDir, &$filesBackedUp): void {
        $src = UpdatePackage::localPath($rel);
        if (!is_file($src)) return;
        $bak = $bakDir . '/' . $rel;
        $bakParent = dirname($bak);
        if (!is_dir($bakParent)) @mkdir($bakParent, 0750, true);
        if (@copy($src, $bak)) $filesBackedUp++;
    };

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        // 用 substr 判断是否为目录条目（以 / 结尾）
        if ($entry === '' || substr($entry, -1) === '/') continue;
        // 跳过包内元数据（manifest.json / MANIFEST.txt / DELETED.txt）
        if (UpdatePackage::isMeta($entry)) continue;
        $rawEntry = str_replace('\\', '/', $entry);
        if (!UpdatePackage::isSafe($rawEntry)) continue;
        foreach (UpdatePackage::mapEntry($rawEntry, $adminNames) as $entryNorm) {
            if (!UpdatePackage::isSafe($entryNorm) || UpdatePackage::isProtected($entryNorm)) continue;
            $zipRels[$entryNorm] = true;
            if (isset($keepSet[$entryNorm])) continue;   // 用户选择保留，本就不动它
            $backupOne($entryNorm);
        }
    }
    // 待删除文件同样先备份，否则「删除」就成了不可逆操作
    foreach (array_keys($delTargets) as $rel) {
        $backupOne($rel);
    }

    // 备份 version.php（lib/bootstrap.php 中的 NB_VERSION 定义）
    $bootstrapFile = NB_ROOT . '/lib/bootstrap.php';
    if (is_file($bootstrapFile)) {
        @copy($bootstrapFile, $bakDir . '/bootstrap.php.bak');
    }
    // 写备份信息
    @file_put_contents($bakDir . '/backup_info.json', json_encode([
        'from_version'       => NB_VERSION,
        'to_version'         => $targetVer,
        'to_build'           => $targetBuild,
        'created_at'         => date('Y-m-d H:i:s'),
        'files_count'        => $filesBackedUp,
        'files_kept'         => count($keepSet),
        'files_will_delete'  => count($delTargets),
        'restore_hint'       => '回滚：把本目录下文件按原相对路径覆盖回站点根目录即可',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // ----------------------------------------------------------------
    // 5. 解压覆盖文件
    // ----------------------------------------------------------------
    $updated = 0;
    $kept = 0;
    $errors = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if ($entry === '' || substr($entry, -1) === '/') continue;
        if (UpdatePackage::isMeta($entry)) continue;

        $rawEntry = str_replace('\\', '/', $entry);
        if (!UpdatePackage::isSafe($rawEntry)) continue;
        foreach (UpdatePackage::mapEntry($rawEntry, $adminNames) as $entryNorm) {
            if (!UpdatePackage::isSafe($entryNorm) || UpdatePackage::isProtected($entryNorm)) continue;
            if (isset($keepSet[$entryNorm])) { $kept++; continue; }

            $targetPath = UpdatePackage::localPath($entryNorm);
            $targetDirName = dirname($targetPath);
            if (!is_dir($targetDirName)) @mkdir($targetDirName, 0750, true);
            $content = $zip->getFromIndex($i);
            if ($content === false) { $errors[] = "读取失败：{$entry}"; continue; }
            if (@file_put_contents($targetPath, $content) === false) { $errors[] = "写入失败：{$entry}"; continue; }
            $updated++;
        }
    }
    $zip->close();

    // ----------------------------------------------------------------
    // 6. 删除新版已移除的文件
    // ----------------------------------------------------------------
    // 旧实现完全忽略 DELETED.txt，升级只增不减：新版删掉的 PHP 会永久留在
    // 站点根目录（例如因漏洞被移除的旧接口仍可通过 URL 直接访问）。
    $deleted = 0;
    foreach ($delTargets as $rel => $packed) {
        $abs = UpdatePackage::localPath($rel);
        // 二次校验：路径必须仍在本站内、且非保护目录；
        // 若同一路径又在包内（DELETED.txt 与 zip 冲突时以 zip 为准）则跳过删除
        if (!UpdatePackage::isSafe($rel) || UpdatePackage::isProtected($rel)) continue;
        if (isset($zipRels[$rel])) continue;
        if (!is_file($abs)) continue;
        if (@unlink($abs)) {
            $deleted++;
        } else {
            $errors[] = "删除失败：{$rel}";
        }
    }

    // ----------------------------------------------------------------
    // 7. 更新版本号（lib/bootstrap.php 中的 NB_VERSION）
    // ----------------------------------------------------------------
    if (is_file($bootstrapFile)) {
        $content = file_get_contents($bootstrapFile);
        if ($content !== false) {
            // 替换 NB_VERSION 定义
            // 必须用 preg_replace_callback：preg_replace 的替换串会被二次解析，
            // \1、$1、\\ 都是特殊语法，单引号未转义时还能截断字符串字面量注入 PHP 代码。
            // 回调只返回纯字面量，不做二次解析。（与 install/install.php 的写法保持一致）
            $newContent = preg_replace_callback(
                "/define\('NB_VERSION',\s*'[^']*'\)/",
                static function () use ($targetVer) {
                    return "define('NB_VERSION', '" . $targetVer . "')";
                },
                $content
            );
            if ($newContent !== null && $newContent !== $content) {
                @file_put_contents($bootstrapFile, $newContent);
            }
        }
    }

    // ----------------------------------------------------------------
    // 8. 清理缓存
    // ----------------------------------------------------------------
    Cache::del('system_update_check');
    // 清除模板缓存等
    $cacheDir = NB_ROOT . '/logs/cache';
    if (is_dir($cacheDir)) {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $f) {
            if ($f->isFile()) @unlink($f->getPathname());
        }
    }

    // ----------------------------------------------------------------
    // 9. 清理下载包
    // ----------------------------------------------------------------
    @unlink($pkgPath);
    if ($pkgPath !== $prePath) @unlink($prePath);

    // 释放锁
    @unlink($lockFile);

    // 记录日志
    Logger::log('admin_system_update', $admin['id'] ?? 0, "系统更新 {$targetVer}", [
        'from'           => NB_VERSION,
        'to'             => $targetVer,
        'updated_files'  => $updated,
        'backed_up_files' => $filesBackedUp,
        'kept_files'     => $kept,
        'deleted_files'  => $deleted,
        'backup_path'    => $bakDir,
    ]);

    // 升级后清尾：清版本检查缓存 + 重置 OPcache（共享主机常见 timestamp 校验延迟，
// 否则下一请求可能仍跑旧代码，出现"更新完成却又提示可更新/会话异常"）
try { Cache::delete('system_update_check_' . $targetVer); } catch (Throwable $e) { }
try { Cache::delete('system_update_check'); } catch (Throwable $e) { }
if (function_exists('opcache_reset')) { @opcache_reset(); }

Response::ok([
        'updated_files'  => $updated,
        'kept_files'     => $kept,
        'deleted_files'  => $deleted,
        'backup_path'    => basename($bakDir),
        'backup_files'   => $filesBackedUp,
        'errors'         => $errors,
        'new_version'    => $targetVer,
    ], '系统更新完成');

} catch (Throwable $e) {
    // 释放锁
    @unlink($lockFile);
    // 清理下载的半成品
    if (isset($pkgPath) && is_file($pkgPath)) @unlink($pkgPath);

    Logger::log('admin_system_update', $admin['id'] ?? 0, '系统更新失败：' . $e->getMessage());
    Response::error(1001, '更新失败：' . $e->getMessage());
}
