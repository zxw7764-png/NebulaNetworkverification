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

再用定时任务（见发行包内 `docs/OPS.md` 的「定时任务」一节）每分钟扫这个文件去发消息。

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

### 我需要的功能没有对应事件怎么办

| 需求 | 建议 |
| --- | --- |
| 想改官网/发卡网的页面 | 用[界面模板](TEMPLATE.md)，不用改代码 |
| 想加新的 API 接口 | 在 `custom.php` 里注册监听器不够用，需要自己写独立文件从 `api/index.php` 路由 |
| 想改登录逻辑本身 | 只能改核心文件，且升级时要保留 —— 权衡清楚再做 |
