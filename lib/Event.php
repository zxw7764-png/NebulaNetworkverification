<?php
/**
 * Nebula 网络验证
 *
 * @copyright Copyright (c) 2026 Nebula 网络验证项目作者. All rights reserved.
 * @license   使用许可协议（可部署自用，禁止二次开发与再分发），详见根目录 LICENSE
 */

/**
 * 事件（钩子）派发
 * ------------------------------------------------------------------
 * 用途：让客户在不修改任何核心文件的前提下，把本系统接到自己的其他系统上
 *      （论坛、企业微信、CRM、订单系统、对账脚本…）。
 *
 * 三条硬性设计约束 —— 全部为了让「客户写的代码永远不能把站点搞坏」：
 *
 *   1. 只通知，不干预
 *      监听器的返回值不会改变主流程结果。所以客户在 user.login 里写错逻辑，
 *      最坏结果是「少收到一次通知」，绝不会变成「用户登不上」。
 *      （刻意不做 veto / 中断传播：那会让客户的 bug 升级成业务故障。）
 *
 *   2. 绝不抛异常
 *      监听器内部抛出的任何 Throwable 都被就地捕获并写入日志，主流程继续。
 *
 *   3. 递归有上限
 *      监听器里再 emit 同名事件会在第 MAX_DEPTH 层停住并记日志，
 *      避免客户手滑写成死循环把 PHP 进程打满。
 *
 * 已内置的事件见 self::KNOWN —— 完整用法与示例见 docs/EXTENDING.md。
 */
final class Event
{
    /** @var array<string, array<int, array{id:int, fn:callable, pri:int, once:bool}>> */
    private static array $listeners = [];

    /** 自增序号：once 事件靠它精确定位，避免 array_splice 后索引错位 */
    private static int $seq = 0;

    private static bool $booted = false;

    /** 当前派发深度（防递归爆炸） */
    private static int $depth = 0;

    private const MAX_DEPTH = 5;

    /** @var array<int, array{event:string, msg:string}> 本次请求内监听器的失败记录 */
    private static array $errors = [];

    /**
     * 内置事件清单：事件名 => 说明。
     * 只用于后台展示与文档生成，不参与派发逻辑 —— 客户可以自造事件名。
     */
    public const KNOWN = [
        'user.register' => '新用户注册成功',
        'user.login'    => '用户登录成功（含卡密直登与自动建号）',
        'user.ban'      => '账号被后台封禁或冻结',
        'user.unban'    => '账号被后台解封',
        'user.expire'   => '会员到期（每次到期只触发一次）',
        'card.activate' => '激活码使用成功',
        'heartbeat'     => '客户端心跳（频率 = 在线用户数 / 分钟，慎用）',
    ];

    /**
     * 注册监听器。$priority 数值大的先执行，同优先级按注册顺序。
     */
    public static function on(string $name, callable $fn, int $priority = 10): void
    {
        self::insert($name, $fn, $priority, false);
    }

    /**
     * 注册只执行一次的监听器。
     * 注意：先摘除再调用，所以监听器内部再次 emit 同名事件也不会重复触发。
     */
    public static function once(string $name, callable $fn, int $priority = 10): void
    {
        self::insert($name, $fn, $priority, true);
    }

    private static function insert(string $name, callable $fn, int $priority, bool $once): void
    {
        $item = ['id' => ++self::$seq, 'fn' => $fn, 'pri' => $priority, 'once' => $once];
        $list = self::$listeners[$name] ?? [];

        // 插入排序（监听器数量是个位数，比每次 emit 前重排更省）
        $pos = count($list);
        foreach ($list as $i => $row) {
            if ($row['pri'] < $priority) {
                $pos = $i;
                break;
            }
        }
        array_splice($list, $pos, 0, [$item]);
        self::$listeners[$name] = $list;
    }

    /**
     * 移除监听器。
     *   off('name')        清空该事件全部监听器
     *   off('name', $id)   只移除指定 id（on() 不返回 id，此形式主要用于 once 自查）
     * 闭包无法用 === 比较，所以不提供「按函数引用移除」——需要精确控制时用 once()。
     */
    public static function off(string $name, ?int $id = null): void
    {
        if ($id === null) {
            unset(self::$listeners[$name]);
            return;
        }
        if (empty(self::$listeners[$name])) {
            return;
        }
        self::$listeners[$name] = array_values(array_filter(
            self::$listeners[$name],
            static fn(array $r): bool => $r['id'] !== $id
        ));
    }

    public static function has(string $name): bool
    {
        return !empty(self::$listeners[$name]);
    }

    /** 已注册监听器的事件名（排查「我注册了但没触发」用） */
    public static function names(): array
    {
        return array_keys(self::$listeners);
    }

    /** 本次请求内监听器的失败记录 */
    public static function errors(): array
    {
        return self::$errors;
    }

    public static function booted(): bool
    {
        return self::$booted;
    }

    /**
     * 派发事件。返回值为所有监听器非 null 返回值的列表（便于调试，业务代码请忽略）。
     *
     * $payload 走值拷贝：监听器改它不会影响主流程，也拿不到内部对象引用。
     *
     * @param array<string, mixed> $payload
     * @return array<int, mixed>
     */
    public static function emit(string $name, array $payload = []): array
    {
        if (empty(self::$listeners[$name])) {
            return [];
        }

        if (self::$depth >= self::MAX_DEPTH) {
            self::report($name, '事件递归超过 ' . self::MAX_DEPTH . ' 层，本次派发已中止');
            return [];
        }

        $out = [];
        self::$depth++;
        try {
            // 取快照再遍历：监听器里 on()/off() 改的是 self::$listeners，
            // 直接遍历原数组会受 array_splice 影响漏掉元素。
            $snapshot = self::$listeners[$name];

            foreach ($snapshot as $row) {
                if ($row['once']) {
                    self::off($name, $row['id']);
                }
                try {
                    $ret = ($row['fn'])($payload, $name);
                    if ($ret !== null) {
                        $out[] = $ret;
                    }
                } catch (Throwable $e) {
                    self::report($name, $e->getMessage(), $e);
                }
            }
        } finally {
            self::$depth--;
        }

        return $out;
    }

    /**
     * 加载客户扩展文件。
     * 由 bootstrap.php 在所有类就绪后调用一次。
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $file = NB_ROOT . '/custom.php';
        if (!is_file($file)) {
            // 只有目录确实可写才尝试生成：否则每个请求都会撞一次失败的 write，
            // 在装有防篡改/只读挂载的站点上纯属浪费。
            if (is_writable(NB_ROOT)) {
                self::scaffold($file);
            }
            if (!is_file($file)) {
                return;
            }
        }

        try {
            // 独立作用域：客户文件里的局部变量不会泄漏到 bootstrap 的作用域
            (static function (string $__nbCustomFile): void {
                include $__nbCustomFile;
            })($file);
        } catch (Throwable $e) {
            // ParseError（客户写坏语法）与运行期错误都在这里兜住。
            // 硬性要求：custom.php 出任何问题都不能让整站白屏。
            self::report('custom.php', $e->getMessage(), $e, true);
        }
    }

    /**
     * 老站升级后根目录没有 custom.php 时自动生成一份极简模板。
     * 失败（目录不可写 / 被防篡改插件拦截）就静默跳过，不影响站点运行。
     */
    private static function scaffold(string $file): void
    {
        $tpl = <<<'PHP'
<?php
/**
 * Nebula 自定义扩展入口
 * ------------------------------------------------------------------
 * 本文件不会被打进更新包，升级时永不覆盖 —— 你的改动可以放心写在这里。
 * 完整事件清单与示例见 docs/EXTENDING.md。
 */

// 防止被浏览器直接访问（本文件只应由 bootstrap.php include）
if (!defined('NB_ROOT')) {
    http_response_code(404);
    exit;
}

// ---- 示例：注册成功后写一条自己的日志（取消注释即可生效）----
// Event::on('user.register', function (array $d) {
//     Logger::log('hook', 1, '新用户注册：' . $d['username'], ['user_id' => $d['user_id']]);
// });

// ---- 示例：登录成功后推送到自己的接口 ----
// Event::on('user.login', function (array $d) {
//     @file_get_contents('https://your-server.example/hook?u=' . urlencode($d['username']));
// });
PHP;

        try {
            @file_put_contents($file, $tpl, LOCK_EX);
        } catch (Throwable $e) {
            // 静默：脚手架只是为了方便，不生成也不影响任何功能
        }
    }

    /**
     * 记录监听器错误。
     * 写日志本身也可能失败（例如 DB 未就绪、日志表已满），再套一层兜底。
     */
    private static function report(string $event, string $msg, ?Throwable $e = null, bool $isLoad = false): void
    {
        self::$errors[] = ['event' => $event, 'msg' => $msg];

        $ctx = ['raw' => ['event' => $event]];
        if ($e !== null) {
            $ctx['raw']['file'] = $e->getFile();
            $ctx['raw']['line'] = $e->getLine();
        }

        $text = $isLoad ? 'custom.php 加载失败：' . $msg : '监听器执行失败：' . $msg;

        try {
            Logger::log('hook', 0, $text, $ctx);
        } catch (Throwable $ignore) {
            // 日志不可用时至少让错误进 PHP 错误日志，便于排查
            error_log('[nebula hook] ' . $event . ': ' . $msg);
        }
    }
}
