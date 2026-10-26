<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * UpdatePackage — 在线更新包解析与本站差异比对
 * ------------------------------------------------------------------
 * 把「升级包怎么读、包内路径对应本站哪个文件、哪些路径不许动」这三件事
 * 收敛到一处，供 admin/handlers/system_update_precheck.php（升级前预检）
 * 与 system_update_do.php（执行升级）共用。
 *
 * 2026-10-26 引入原因：此前升级是「全量覆盖 + 事后备份」，包内 manifest.json
 * 的 files 只是路径数组、不含任何哈希，升级器手里没有「官方原版」参照物，
 * 因此物理上无法判断客户改过哪些文件，只能一律覆盖。备份目录
 * logs/update_backups/ 也只写不读（全代码库无读取方），回滚需人工 SFTP。
 *
 * 本类补上缺失的那半截：
 *   · manifest 兼容两种格式 ——
 *       旧包：files = ['a/b.php', 'c.php']            （无哈希，只能全量覆盖）
 *       新包：files = [...] + hashes = {path: sha256}  （可逐文件识别改动）
 *   · analyze() 一次遍历算出四类清单：
 *       modified  本站存在但内容与包内原版不同（客户改过）
 *       overwrite 本站存在且与原版一致（正常覆盖）
 *       missing   本站不存在（新增）
 *       deleted   包内 DELETED.txt 声明应移除、且本站确实存在
 *   · 所有路径判定统一走 isSafe() / isProtected()，杜绝 Zip Slip 与
 *     覆盖 config/、logs/ 等运行时目录
 */
class UpdatePackage
{
    /**
     * 整目录不覆盖（运行时数据与站点私有配置）
     * 与 system_update_do.php 历史行为保持一致，迁移到这里后单一来源。
     */
    public const PROTECTED_DIRS = [
        'config', 'logs', 'data', 'uploads',
        '.catpaw', '.git', '.freebuff', '.workbuddy',
    ];

    /** 包内后台目录固定名（打包侧统一为 admin/，本站可能已改名） */
    public const PACKED_ADMIN = 'admin';

    /**
     * 包内的元数据文件：不是站点文件，绝不能被解压到站点根目录。
     * （旧实现只跳过 manifest.json，MANIFEST.txt 与 DELETED.txt 会被当成
     *  普通文件写进客户站点根目录，属于构建产物污染）
     */
    public const META_FILES = ['manifest.json', 'MANIFEST.txt', 'DELETED.txt'];

    /** 是否包内元数据文件 */
    public static function isMeta(string $path): bool
    {
        return in_array(str_replace('\\', '/', $path), self::META_FILES, true);
    }

    /** 自动探测后台目录时跳过的保留名 */
    private const RESERVED_NAMES = [
        'api', 'agent', 'shop', 'web', 'config', 'lib', 'logs',
        'data', 'uploads', 'update-system', 'install', 'pack', 'docs', 'assets',
        'deploy', 'tests', 'releases',
    ];

    /** 判定后台目录的特征文件 */
    private const ADMIN_SIGNATURES = [
        'AdminAuth.php',
        'assets/js/app.js',
        'handlers',
    ];

    // ==================================================================
    // 路径与目录判定
    // ==================================================================

    /**
     * 本站实际后台目录名（可能多个：历史改名残留会被同时更新）
     *
     * 优先 config 的 admin.path；未配置或非法时自动探测站点根下同时
     * 具备 AdminAuth.php + assets/js/app.js + handlers/ 的目录。
     */
    public static function adminDirNames(): array
    {
        $cfg = trim((string) Config::get('admin.path', ''), '/');
        if ($cfg !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_-]{2,31}$/', $cfg)) {
            return [$cfg];
        }

        $names = [];
        $scan = @scandir(NB_ROOT);
        if (is_array($scan)) {
            foreach ($scan as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                if (in_array($name, self::RESERVED_NAMES, true)) {
                    continue;
                }
                $dir = NB_ROOT . '/' . $name;
                if (!is_dir($dir)) {
                    continue;
                }
                $hit = true;
                foreach (self::ADMIN_SIGNATURES as $sig) {
                    if (!file_exists($dir . '/' . $sig)) {
                        $hit = false;
                        break;
                    }
                }
                if ($hit) {
                    $names[] = $name;
                }
            }
        }

        if (!$names) {
            $names[] = self::PACKED_ADMIN;
        }
        sort($names);
        return $names;
    }

    /**
     * 包内路径 → 本站相对路径（一个包内路径可能对应多个本地后台目录）
     *
     * @return string[]
     */
    public static function mapEntry(string $path, array $adminNames): array
    {
        $path = str_replace('\\', '/', $path);
        if ($path === self::PACKED_ADMIN || str_starts_with($path, self::PACKED_ADMIN . '/')) {
            $suffix = substr($path, strlen(self::PACKED_ADMIN));
            $out = [];
            foreach ($adminNames as $n) {
                $out[] = $n . $suffix;
            }
            return $out;
        }
        return [$path];
    }

    /**
     * 路径安全校验（Zip Slip / 绝对路径 / 盘符 / 空字节）
     * 任何来自 zip 的路径在落盘、删除前都必须过这一关。
     */
    public static function isSafe(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if (str_contains($path, "\0")) {
            return false;
        }
        if ($path[0] === '/' || $path[0] === '\\') {
            return false;
        }
        if (str_contains($path, '..')) {
            return false;
        }
        if (preg_match('#^[a-zA-Z]:#', $path)) {
            return false;
        }
        return true;
    }

    /** 是否落在保护目录内（整目录不覆盖/不删除） */
    public static function isProtected(string $path): bool
    {
        foreach (self::PROTECTED_DIRS as $p) {
            if ($path === $p || str_starts_with($path, $p . '/')) {
                return true;
            }
        }
        return false;
    }

    /** 本站绝对路径 */
    public static function localPath(string $rel): string
    {
        return NB_ROOT . '/' . ltrim($rel, '/');
    }

    // ==================================================================
    // 下载
    // ==================================================================

    /**
     * 下载更新包到本地
     *
     * 安全边界（与 2026-10-06 审计结论一致，迁移自 system_update_do.php）：
     *   · 仅允许 HTTPS
     *   · 主机必须等于 config 的 update_server（未配置时只做 HTTPS 校验）
     *   · curl FOLLOWLOCATION 跟随 302 后，最终主机必须仍在白名单内
     *     （此前只校验初始 URL，恶意 302 可把下载导向白名单外主机）
     *
     * @throws RuntimeException 任一步骤失败
     */
    public static function download(string $url, string $dest): void
    {
        if (!preg_match('#^https://#i', $url)) {
            throw new RuntimeException('下载地址不合法（仅允许 HTTPS）');
        }

        $allowedHost = parse_url((string) Config::get('update_server', ''), PHP_URL_HOST);
        $dlHost      = parse_url($url, PHP_URL_HOST);
        if (!$dlHost || ($allowedHost && $dlHost !== $allowedHost)) {
            throw new RuntimeException('下载地址域名不在允许列表中，拒绝执行更新');
        }

        if (function_exists('curl_init')) {
            $fp = @fopen($dest, 'wb');
            if ($fp === false) {
                throw new RuntimeException('无法创建下载文件，请检查 logs 目录权限');
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fp,
                CURLOPT_TIMEOUT        => 300,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_USERAGENT      => 'Nebula-Updater/' . (defined('NB_VERSION') ? NB_VERSION : '0'),
            ]);
            $ok       = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err      = curl_error($ch);
            $effUrl   = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);
            fclose($fp);

            if (!$ok || $httpCode >= 400) {
                @unlink($dest);
                throw new RuntimeException('下载失败：' . ($err ?: 'HTTP ' . $httpCode));
            }

            $effHost = parse_url($effUrl, PHP_URL_HOST);
            if ($allowedHost && ($effHost === false || $effHost === null || $effHost !== $allowedHost)) {
                @unlink($dest);
                throw new RuntimeException('下载地址被重定向到非白名单域名，已中止更新：' . (string) $effHost);
            }
            return;
        }

        // 回退到 file_get_contents（无 curl 扩展的极简环境）
        $ctx = stream_context_create([
            'http' => ['timeout' => 300],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) {
            @unlink($dest);
            throw new RuntimeException('下载失败：无法连接更新服务器');
        }
        if (@file_put_contents($dest, $data) === false) {
            @unlink($dest);
            throw new RuntimeException('写入下载文件失败，请检查 logs 目录权限');
        }
    }

    /**
     * 校验下载包 SHA-256，不匹配则删除并抛错
     *
     * @throws RuntimeException
     */
    public static function verifySha256(string $file, string $expect): void
    {
        $actual = @hash_file('sha256', $file);
        if ($actual === false) {
            @unlink($file);
            throw new RuntimeException('无法计算下载文件 SHA-256');
        }
        if (!hash_equals(strtolower($expect), strtolower((string) $actual))) {
            @unlink($file);
            throw new RuntimeException('SHA-256 校验失败，文件可能已损坏或被篡改');
        }
    }

    // ==================================================================
    // manifest 解析
    // ==================================================================

    /**
     * 从 manifest 提取「包内路径 => 期望 sha256」
     *
     * 支持三种形态：
     *   1. hashes 为关联数组：{"admin/a.php": "abc..."}     ← 新包
     *   2. files 为关联数组：{"admin/a.php": "abc..."}       ← 兼容写法
     *   3. 都没有哈希                                        ← 旧包，返回空数组
     *
     * 哈希值统一转小写并校验为 64 位 hex，非法项丢弃（不让脏数据误判）。
     *
     * @return array<string,string>
     */
    public static function expectedHashes(array $manifest): array
    {
        $raw = [];
        if (isset($manifest['hashes']) && is_array($manifest['hashes'])) {
            $raw = $manifest['hashes'];
        } elseif (isset($manifest['files']) && is_array($manifest['files'])) {
            // files 若是关联数组（path => hash）而非纯路径列表，同样可用
            $keys = array_keys($manifest['files']);
            if ($keys !== range(0, count($keys) - 1)) {
                $raw = $manifest['files'];
            }
        }

        $out = [];
        foreach ($raw as $path => $hash) {
            if (!is_string($path) || !is_string($hash)) {
                continue;
            }
            $path = str_replace('\\', '/', ltrim($path, '/'));
            $hash = strtolower(trim($hash));
            if ($path === '' || !preg_match('/^[0-9a-f]{64}$/', $hash)) {
                continue;
            }
            $out[$path] = $hash;
        }
        return $out;
    }

    /**
     * 读取包内 DELETED.txt（make_release.php diff 生成）
     *
     * 格式：以 # 开头的注释行 + 每行一个包内相对路径。
     *
     * @return string[]
     */
    public static function readDeletedList(ZipArchive $zip): array
    {
        $idx = $zip->locateName('DELETED.txt');
        if ($idx === false) {
            return [];
        }
        $raw = $zip->getFromIndex($idx);
        if ($raw === false) {
            return [];
        }
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $line = str_replace('\\', '/', $line);
            if (self::isSafe($line)) {
                $out[] = $line;
            }
        }
        return array_values(array_unique($out));
    }

    // ==================================================================
    // 差异分析
    // ==================================================================

    /**
     * 分析升级包对本站的影响
     *
     * @param ZipArchive $zip      已打开的更新包
     * @param array      $manifest 已解码的 manifest.json
     * @return array{
     *   has_hash: bool,
     *   entries: int,
     *   modified: array<int,array{path:string,packed:string,local_size:int,remote_size:int}>,
     *   overwrite: array<int,array{path:string,packed:string}>,
     *   missing: array<int,array{path:string,packed:string}>,
     *   deleted: array<int,array{path:string,packed:string,size:int}>,
     *   deleted_declared: int,
     *   unhashed: int
     * }
     */
    public static function analyze(ZipArchive $zip, array $manifest): array
    {
        $adminNames = self::adminDirNames();
        $hashes     = self::expectedHashes($manifest);
        $hasHash    = !empty($hashes);

        $res = [
            'has_hash'         => $hasHash,
            'entries'          => 0,
            'modified'         => [],
            'overwrite'        => [],
            'missing'          => [],
            'deleted'          => [],
            'deleted_declared' => 0,
            'unhashed'         => 0,
        ];

        $seen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if ($entry === '' || str_ends_with($entry, '/')) {
                continue;                      // 目录条目
            }
            $packed = str_replace('\\', '/', $entry);
            if (self::isMeta($packed)) {
                continue;                      // 包内元数据不参与差异
            }
            if (!self::isSafe($packed)) {
                continue;
            }

            $stat = $zip->statIndex($i);
            $remoteSize = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
            foreach (self::mapEntry($packed, $adminNames) as $rel) {
                if (isset($seen[$rel]) || self::isProtected($rel) || !self::isSafe($rel)) {
                    continue;
                }
                $seen[$rel] = true;
                $res['entries']++;

                $abs = self::localPath($rel);
                if (!is_file($abs)) {
                    $res['missing'][] = ['path' => $rel, 'packed' => $packed];
                    continue;
                }

                $expect = $hashes[$packed] ?? null;
                if ($expect === null) {
                    // 旧包无哈希：只能判定「将被覆盖」，无法判断是否被改过
                    $res['unhashed']++;
                    $res['overwrite'][] = ['path' => $rel, 'packed' => $packed];
                    continue;
                }

                $actual = @hash_file('sha256', $abs);
                if ($actual !== false && !hash_equals($expect, strtolower($actual))) {
                    $res['modified'][] = [
                        'path'        => $rel,
                        'packed'      => $packed,
                        'local_size'  => (int) @filesize($abs),
                        'remote_size' => $remoteSize,
                    ];
                } else {
                    $res['overwrite'][] = ['path' => $rel, 'packed' => $packed];
                }
            }
        }

        // 待删除清单：只保留「本站确实存在」的，避免无意义 unlink 与误报
        $declared = self::readDeletedList($zip);
        $res['deleted_declared'] = count($declared);
        $delSeen = [];
        foreach ($declared as $packed) {
            foreach (self::mapEntry($packed, $adminNames) as $rel) {
                if (isset($delSeen[$rel]) || self::isProtected($rel) || !self::isSafe($rel)) {
                    continue;
                }
                $delSeen[$rel] = true;
                $abs = self::localPath($rel);
                if (!is_file($abs)) {
                    continue;
                }
                $res['deleted'][] = [
                    'path'   => $rel,
                    'packed' => $packed,
                    'size'   => (int) @filesize($abs),
                ];
            }
        }

        return $res;
    }
}
