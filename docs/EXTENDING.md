# Nebula 扩展开发指南

> 📚 本文属 Nebula 文档中心，主索引见 [README.md](../README.md)，**许可条款见 [LICENSE](../LICENSE)**；
> 相关文档：[架构设计](ARCHITECTURE.md) · [客户端 API](API.md) · [界面模板](TEMPLATE.md) · 运维手册（随发行包提供 `docs/OPS.md`，官网文档中心可在线阅读）

## 0. 这份文档解决什么问题

你想让 Nebula 跟自己的系统联动 —— 注册成功后同步到论坛、有人激活卡密时通知客服、会员到期时发提醒、登录时记一笔自己的流水。

**不用改任何核心文件**。所有这类需求都写进项目根目录的一个文件里：

```
你的站点/
├── custom.php     ← 只改这里
├── lib/           ← 别动
├── api/           ← 别动
└── ...
```

`custom.php` **不会被打进更新包**。这是它和项目里其他所有 PHP 文件的唯一区别，也是它存在的全部理由：你写的东西，升级永远不覆盖。

---

## 1. 三分钟上手

打开 `custom.php`，取消注释这样一段：

```php
Event::on('user.register', function (array $d) {
    Logger::log('myapp', 1, '新用户注册：' . $d['username'], ['user_id' => $d['user_id']]);
});
```

保存。注册一个用户。去后台「日志」页搜 `myapp`，能看到记录 —— 就成功了。

**就这样。** 不需要改配置、不需要重启、不需要装任何东西。

---

## 2. 事件清单

系统在下面这些时机各喊一声。你注册了监听器就能收到，没注册就什么都不发生（开销可以忽略）。

### 2.1 `user.register` — 新用户注册成功

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `user_id` | int | 新用户 ID |
| `username` | string | 用户名 |
| `email` | string | 邮箱，可能为空串 |
| `ip` | string | 注册 IP |
| `software_id` | int | 归属软件 ID，0 = 未绑定 |
| `time` | int | 时间戳 |

不触发的场景：注册开关关闭、当前是「激活码直登」模式、用户名重复、密码不合规。

### 2.2 `user.login` — 用户登录成功

三种登录方式（用户名密码 / 用户名+激活码 / 纯激活码）**都会触发这一个事件**，靠 `method` 区分。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `user_id` | int | 用户 ID |
| `username` | string | 用户名 |
| `nickname` | string | 昵称，未设置时等于用户名 |
| `ip` | string | 本次登录 IP |
| `method` | string | `password` / `username_code` / `code` |
| `group_id` | int | 用户组 ID |
| `vip_expire` | int | 会员到期时间戳，`-1` = 永久，`0` = 未激活 |
| `points` | int | 剩余点数 |
| `software_id` | int | 归属软件 ID |
| `time` | int | 时间戳 |

事件在「失败计数已清零、登录时间已写入」之后派发，拿到的是登录后的最新状态。

### 2.3 `card.activate` — 激活码使用成功

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `user_id` | int | 用户 ID |
| `username` | string | 用户名 |
| `card_id` | int | 卡密 ID |
| `code` | string | 卡密原文（**是登录凭证，别往公开渠道发**） |
| `type` | int | `1` 时长卡 / `2` 点数卡 / `3` 次数卡 / `4` 永久卡 |
| `type_name` | string | 卡类型中文名 |
| `duration` | int | 时长卡=秒数，点数/次数卡=数量 |
| `group_id` | int | 激活后的用户组 ID |
| `vip_expire` | int | 激活后的到期时间戳 |
| `points` | int | 激活后的点数 |
| `max_devices` | int | 激活后的设备上限（卡密与用户组取较大值） |
| `software_id` | int | 卡密所属软件 ID |
| `ip` | string | 激活 IP |
| `detail` | string | 中文描述，如「增加时长 30天，进入用户组「高级用户组」」 |
| `time` | int | 时间戳 |

事件在**数据库事务提交之后**派发，所以此时查库一定读得到最新值。

### 2.4 `user.ban` — 账号被封禁或冻结

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `user_id` | int | 用户 ID |
| `username` | string | 用户名 |
| `status` | int | `0` 封禁 / `2` 冻结 |
| `status_text` | string | 中文：`封禁` / `冻结` |
| `ban_expire` | int | 封禁到期时间戳，`0` = 永久 |
| `admin_id` | int | 操作的管理员 ID |
| `batch` | bool | 是批量操作还是单个编辑 |
| `time` | int | 时间戳 |

**只在状态真的改变时触发。** 给一个已经封禁的用户再点一次封禁，不会触发。

### 2.5 `user.unban` — 账号被解封

字段同 `user.ban`，`status` 恒为 `1`，`status_text` 为 `解封`。

同样只在「从非正常状态变回正常」时触发。

### 2.6 `user.expire` — 会员到期

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `user_id` | int | 用户 ID |
| `username` | string | 用户名 |
| `vip_expire` | int | 到期时间戳 |
| `expired_at` | int | 实际到期时刻（同上） |
| `remain` | int | 恒为 `0` |
| `points` | int | 剩余点数 |

**自带去重**：同一次到期只喊一次，不用你操心频率。续费后又过期，会被识别成新的到期点，能再喊一次。

注意：「从未激活」（`vip_expire = 0`）**不算到期**，不会触发。这样你就不会收到一堆「注册了但没买卡」的通知。

### 2.7 `heartbeat` — 客户端心跳

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `user_id` | int | 用户 ID |
| `username` | string | 用户名 |
| `machine_id` | string | 设备标识 |
| `remain` | int | 剩余秒数，`-1` = 永久 |
| `points` | int | 剩余点数 |
| `group_id` | int | 用户组 ID |
| `software_id` | int | 归属软件 ID |

⚠️ **频率 = 在线用户数 × 每分钟一次。** 100 个在线用户就是每小时 6000 次。这个事件只适合往内存/缓存里写东西，**绝对不要在里面做同步 HTTP 请求或大查询**，否则会拖垮整个站点。

被踢下线、设备解绑、安全阻断这些异常心跳**不会**触发本事件，收到的永远是正常在线的那一次。

---

## 3. 实战示例

### 3.1 注册成功后同步到自己的接口

```php
Event::on('user.register', function (array $d) {
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n",
        'content' => json_encode([
            'username' => $d['username'],
            'email'    => $d['email'],
            'uid'      => $d['user_id'],
        ]),
        'timeout' => 3,          // 必须设超时
    ]]);
    @file_get_contents('https://your-server.example/api/sync', false, $ctx);
});
```

### 3.2 把登录记录写进自己的表

```php
Event::on('user.login', function (array $d) {
    Database::insert('my_login_log', [
        'user_id'    => $d['user_id'],
        'ip'         => $d['ip'],
        'method'     => $d['method'],
        'created_at' => time(),
    ]);
}, 20);   // 第三个参数是优先级，数字大的先执行，默认 10
```

自己的表随便建，只要不跟 `nb_` 前缀冲突。

### 3.3 只监听第一次激活（比如送新人礼包）

```php
Event::once('card.activate', function (array $d) {
    Logger::log('myapp', 1, '首次激活：' . $d['username']);
});
```

`once()` 触发一次后自动摘除。**这只是一次性的**——重启 PHP 进程后又会在下一次激活时重新触发。要做「每个用户一辈子只一次」，得自己建表记标记。

### 3.4 会员到期时发提醒

```php
Event::on('user.expire', function (array $d) {
    // 走队列文件，别在这里直接发邮件（会被到期用户数量拖慢）
    @file_put_contents(
        NB_ROOT . '/data/expire_queue.log',
        json_encode($d, JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND
    );
});
```

再用一个定时任务把它消费掉。写个独立脚本放项目根目录（比如 `expire_consumer.php`），引导方式和 `cron.php` 一样：

```php
<?php
require_once __DIR__ . '/lib/bootstrap.php';

$queue = NB_ROOT . '/data/expire_queue.log';
$pos   = NB_ROOT . '/data/expire_queue.pos';   // 记住上次读到第几个字节
if (!is_file($queue)) exit;

$start = is_file($pos) ? (int) file_get_contents($pos) : 0;
if (filesize($queue) <= $start) exit;          // 没有新内容，直接结束

$fh = fopen($queue, 'r');
fseek($fh, $start);
while (($line = fgets($fh)) !== false) {
    $d = json_decode($line, true);
    if (is_array($d)) {
        // 在这里发邮件 / 调企微机器人 / 写自己的库
    }
}
$end = ftell($fh);
fclose($fh);
file_put_contents($pos, (string) $end);
```

crontab 每分钟跑一次：

```cron
* * * * * /usr/bin/php /path/to/nebula/expire_consumer.php >> /path/to/nebula/logs/expire.log 2>&1
```

两个必须知道的点：

1. **位点记的是字节数**，所以队列文件只能追加，不能中途改写或重排 —— 一改位点就错位了。
2. **队列文件要定期截断**（否则会一直长）。截断后位点必须归零，不然会卡在旧的偏移量上再也读不到新内容：

```php
if (filesize($queue) > 5 * 1024 * 1024) {   // 超过 5MB 就清空
    file_put_contents($queue, '');
    file_put_contents($pos, '0');
}
```

写入端建议带上 `LOCK_EX`，避免两个请求同时到期时互相覆盖：

```php
@file_put_contents(
    NB_ROOT . '/data/expire_queue.log',
    json_encode($d, JSON_UNESCAPED_UNICODE) . "\n",
    FILE_APPEND | LOCK_EX
);
```

### 3.5 自己造事件，给别的代码用

`custom.php` 只负责注册监听器，你也可以在自己改的其他文件里派发：

```php
// 在 custom.php 里监听
Event::on('myapp.order_paid', function (array $d) {
    Logger::log('myapp', 1, '订单支付：' . $d['order_no']);
});

// 在你自己的业务代码里派发
Event::emit('myapp.order_paid', ['order_no' => 'A123']);
```

事件名随便起，系统不会校验。

---

## 4. 五条铁律

### 4.1 你的代码出错，不会影响系统

监听器里抛的任何异常都会被就地捕获，写进后台日志（搜 `hook`），主流程继续跑。

**反过来说**：出错了系统不会告诉你，得自己去日志页看。所以「注册了但没收到通知」时，第一步永远是查日志。

### 4.2 事件只通知，不能改变主流程结果

监听器的返回值会被忽略。这是刻意的设计 —— 如果监听器能「否决」一次登录，你的一个 bug 就会变成「全体用户登不上」。

想要拦截行为，用后台已有的功能（IP 黑名单、注册开关、封禁、RuntimePolicy），别指望事件。

### 4.3 别在 `heartbeat` 里做重活

见 §2.7。这是最容易踩的坑，也是唯一能把站点拖垮的坑。

### 4.4 `custom.php` 里的 PHP 语法错误不会白屏

系统会捕获 `ParseError` 并记日志，整站照常运行 —— **但你的代码一行都不会执行**。

所以改完 `custom.php` 后，先确认事件真的触发了，别默认它在工作。

### 4.5 不要在这里写 `namespace` 或 `declare(strict_types=1)`

`custom.php` 是被 `include` 进一个已有函数的，不是独立脚本。写了会报错。

---

## 5. 调试

### 5.1 确认监听器注册上了

```php
// 临时加到 custom.php 末尾，随便触发一个请求后在日志里看
Logger::log('myapp', 1, '已注册的事件：' . implode(',', Event::names()));
```

### 5.2 确认事件触发了

监控期间有个更省事的办法 —— 后台「日志」页按类型 `hook` 过滤，能看到所有失败记录。

### 5.3 查看本次请求内的错误

```php
$errs = Event::errors();   // [['event' => 'user.login', 'msg' => '...'], ...]
```

只在当前请求内有值，请求结束就没了。适合在你自己的调试脚本里用。

---

## 6. 升级会怎样

| 情况 | 结果 |
| --- | --- |
| 你改了 `custom.php` | **不受影响**，文件不在更新包里 |
| 你新版本第一次升级 | 根目录原本就有 `custom.php`，内容保留 |
| 老站（升级前没有这个文件） | 第一次访问时自动生成一份带注释的空模板 |
| 你把自己的代码写在 `lib/Auth.php` 里 | **会被覆盖**，升级时请勾选「保留我改过的文件」 |

最后一行是重点：**能写 `custom.php` 就别改核心文件**。改了核心文件虽然升级时能选择保留，但之后官方修的 bug 你就拿不到了 —— 你会永久分叉在旧代码上。

## 7. 没有现成事件时，还能做什么

先记住选择顺序：**能挂事件就别写新文件，能写新文件就别改核心文件。**

| 你的需求 | 该动哪里 | 升级影响 |
| --- | --- | --- |
| 注册 / 登录 / 封禁 / 解封 / 到期 / 激活 / 心跳时做点事 | `custom.php` 里 `Event::on()` | 无。升级永不覆盖 |
| 要一个新的 HTTP 接口给自己的程序调 | 新建 `api/handlers/<名字>.php` | 无。新文件不与上游冲突 |
| 改登录页 / 发卡页的样子 | [界面模板](TEMPLATE.md) | 模板独立成目录 |
| 改登录判定本身、改心跳返回字段、改加密信封 | 只能改核心文件 | **会被覆盖** |

前三种都属于**加东西** —— 加出来的东西天然不会和上游撞车。只有第四种是真的「改东西」。

### 7.1 加一个新接口：文件名就是路由

`api/index.php` 是**约定式分发**，没有路由表：

```php
$handler = __DIR__ . '/handlers/' . $action . '.php';   // api/index.php 分发处
```

所以新建 `api/handlers/myfeature.php`，客户端发 `action=myfeature` 就会执行它 —— **不需要在 `api/index.php` 里加任何一行**。

两条硬规则：

**① 文件名只允许小写字母和下划线。** `action` 进来会先过一遍
`preg_replace('/[^a-z_]/', '', strtolower($action))`，大写、中文、连字符都会被吃掉：
`my-feature` 会变成 `myfeature`，`myFeature` 也会变成 `myfeature`。

**② 鉴权要自己写。** 每个 handler 自己做，没有统一拦截：

```php
$token     = Util::str($requestData, 'token', '');
$machineId = Util::str($requestData, 'machine_id', '');
$v = Session::validate($token, $machineId);   // 校验失败就直接返回
```

照抄 `api/handlers/heartbeat.php` 开头几行即可；报文格式见 [API.md](API.md)。

### 7.2 非要改核心文件时，先建一份改动清单

升级那天你得一眼看出「哪些是我改的」。靠回忆不行，最省事的办法是改完就记一行：

```bash
echo "$(date '+%F %T') api/handlers/login.php 增加部门字段校验" >> ~/nebula-changes.txt
```

### 7.3 升级前用「完整性校验」比一次

后台「完整性校验」页维护着一份文件基准（`data/file_baseline.json`）。

**重建基准的正确时机**：刚部署完、或刚升级完、且确认当前代码就是你想要的状态。

之后它报出的「已修改」列表，就是你和原始版本的差异。**升级前先看这份列表** —— 里面的每一个文件，升级时都会被新版本覆盖。

### 7.4 撞车了怎么办

| 情况 | 做法 |
| --- | --- |
| 你改的文件，新版本没动 | 升级时勾「保留我改过的文件」，直接保留 |
| 你改的文件，新版本也改了 | 逐个对比合并，**不要整文件覆盖** |
| 只是加字段 / 加接口 | 回头改写成「新文件 + 单独入口」，下次升级就不用管了 |

第二行要多说一句为什么：整文件覆盖等于**永久丢掉上游在这个文件里修的 bug 和安全问题**。
而升级器的「保留」只做整文件哈希比对、没有逐行合并 —— 一旦保留，那份文件从此就和官方版本分叉了。
这正是 `custom.php` 存在的全部理由。
