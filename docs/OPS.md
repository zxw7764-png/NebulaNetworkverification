# Nebula 运维手册

> 📚 本文属 Nebula 文档中心，主索引见 [README.md](../README.md)，完整指南见 [GUIDE.md](GUIDE.md)，**许可条款见 [LICENSE](../LICENSE)**；
> 相关文档：[架构设计](ARCHITECTURE.md) · [客户端 API](API.md) · [报文示例](API_RAW_EXAMPLES.md) · [界面模板](TEMPLATE.md)

## 0. 这份手册管什么

**管**：系统部署上线**之后**的事——巡检、升级、备份、回滚、排障、安全事件处置。

**不管**：

| 想做的事 | 看哪份文档 |
| --- | --- |
| 第一次安装、装完自检 | [GUIDE.md](GUIDE.md) → 「环境要求」「安装部署」 |
| 改后台目录名、入口密钥、IP 白名单 | [GUIDE.md](GUIDE.md) → 「安全配置」 |
| 客户端怎么对接、协议字段 | [API.md](API.md)、[API_RAW_EXAMPLES.md](API_RAW_EXAMPLES.md) |
| 整体架构、请求生命周期 | [ARCHITECTURE.md](ARCHITECTURE.md) |
| 加密客户端打包 | [SEALER.md](SEALER.md) |

本文所有命令假设项目根目录为 `/www/nebula`，PHP CLI 为 `/usr/bin/php`，请按实际路径替换。

---

## 1. 上线前检查清单

上线前逐项打勾。**任何一项不合格都会在运营期变成事故**。

| # | 检查项 | 怎么查 | 不合格的后果 |
| --- | --- | --- | --- |
| 1 | PHP ≥ 8.0 | `php -v` | 语法错误，整站 500 |
| 2 | 扩展 `pdo_mysql` / `openssl` / `json` / `zlib` 已装 | `php -m \| grep -E 'pdo_mysql\|openssl\|json\|zlib'` | 连不上库 / 握手失败 / 接口报错 / **无法生成备份** |
| 3 | MySQL ≥ 5.7，字符集 `utf8mb4` | `SELECT VERSION();` | 表情符号、生僻字入库报错 |
| 4 | 后台目录名已改，且**不是** `admin` | 后台「系统设置」确认 | 被扫描器直接打后台登录页 |
| 5 | 后台入口密钥 `admin_entry_key` 已设 | 访问 `/后台目录/` 是否 404 | 入口形同虚设 |
| 6 | 默认管理员密码已改（≥ 8 位） | 登录后台 | 被撞库 |
| 7 | HTTPS 已启用，证书有效 | 浏览器看锁标 | 明文传输账号密码与卡密 |
| 8 | `config/` `logs/` `data/` `uploads/` 可写 | `install/install.php` 自检页 | 无法安装 / 日志写不进 / 备份失败 |
| 9 | `lib/` `api/` 目录**不可写** | `ls -ld lib api` | 被挂马后可直接改核心代码 |
| 10 | 定时任务已加（见 §4） | `crontab -l` | 在线数不准、设备不回收、备份不跑 |
| 11 | 生产环境 `display_errors=Off` | `php -i \| grep display_errors` | 报错信息泄露绝对路径与表名 |
| 12 | `install/` 下除 `install.php` 外均不可访问 | 访问 `/install/migrate.php` 应 404 | 迁移脚本被反复重跑、审计日志被清空 |

> 第 4、5 项是**最高频的疏漏**。安装向导会随机生成一个 `nbXXXXXX` 形式的后台目录名，请保持随机，不要改回 `admin`。

---

## 2. 文件与目录权限基线

```
项目根/
├── config/       750  仅 Web 进程用户可读   ← 含数据库凭据与签名密钥
├── logs/         775  可写                  ← 审计与运行日志
├── data/         775  可写                  ← 备份、文件完整性基准
├── uploads/      775  可写，但禁止执行 PHP
├── lib/          755  不可写                ← 核心代码
├── api/          755  不可写
├── 后台目录/      755  不可写
└── 其余          755
```

设置示例（Web 进程用户为 `www`）：

```bash
chown -R www:www /www/nebula
chmod -R 755 /www/nebula
chmod -R 775 /www/nebula/logs /www/nebula/data /www/nebula/uploads
chmod 750 /www/nebula/config
```

**几个容易踩的点**：

- `config/config.php` 里有数据库密码和响应签名私钥，**只要 Web 能直接读到它就已经错了**——先确认 `/config/` 返回 404 或 403，再谈权限。
- `uploads/` 允许写但**绝不能允许执行 PHP**，见 §3。
- 不要为了省事给整个项目 `777`，那等于把 `lib/` 也开放成可写。

---

## 3. 反向代理与敏感路径

### 3.1 必须拒绝访问的路径

| 路径 | 原因 |
| --- | --- |
| `/config/` | 数据库凭据、签名密钥 |
| `/lib/` | 核心代码，可被读取分析 |
| `/logs/` | 审计日志，含管理员操作记录与 IP |
| `/data/` | 备份 SQL、文件完整性基准 |
| `/uploads/*.php` | 上传目录内的可执行文件，WebShell 首选落点 |
| `/后台目录/handlers/` | 依赖 `index.php` 注入的变量，单独访问无意义且可能泄露信息 |
| `/agent/handlers/` | 同上 |
| `*.sql` `*.log` `*.md` `*.bak` `*.old` | 备份/日志/源码备份文件 |
| `/.git/` `/.svn/` `.env` 等隐藏项 | 源码与历史泄露 |

### 3.2 Nginx

仓库内 `install/nginx.conf.example` 是可直接使用的完整配置，含伪静态、安全响应头与上述 deny 规则，**照着改两行（`server_name` 与 `root`）即可**：

```bash
cp install/nginx.conf.example /etc/nginx/conf.d/nebula.conf
# 修改 server_name 与 root
nginx -t && nginx -s reload
```

**有一个坑必须记住**：后台页面入口要用 `rewrite ... last`，**不能用 `try_files`**。

```nginx
location = /后台目录/ {
    rewrite ^ /后台目录/home.php last;   # 正确
}
```

用 `try_files` 会把 `home.php` 当静态文件直接返回，**PHP 源码被原样下载**，数据库账号密码直接泄露。

### 3.3 Apache

仓库内 `.htaccess` 已包含等价规则，需确认：

- `mod_rewrite` 已启用；
- `AllowOverride All`（否则 `.htaccess` 完全不生效）；
- `uploads/` 目录下禁止 `php` 解析。

### 3.4 校验 deny 是否真的生效

配置完**一定要从外网实测**，不要只看配置文件：

```bash
for p in config/config.php lib/bootstrap.php logs data backups .git/config; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "https://你的域名/$p")
  echo "$p -> $code"
done
```

期望结果：全部为 `403` 或 `404`。**出现 `200` 立即处理**。

---

## 4. 定时任务

`cron.php` 是全部周期性工作的唯一入口，它内部按「到点才做」节流，所以**每分钟跑一次是安全的**，不会重复备份或重复清理。

### 4.1 它实际做什么

| 顺序 | 任务 | 作用 | 不跑的后果 |
| --- | --- | --- | --- |
| 0a | 心跳缓冲落库 | 把聚合的心跳批量写入设备表 | 在线数、活跃统计不准 |
| 0b | 调用统计落库 | 接口调用统计从缓冲落表 | 数据面板空白 |
| 1 | 清理过期会话 | 删除过期 `nb_sessions` | 会话表无限膨胀 |
| 1b | 清理过期签名密钥 | 删除过期 `nb_sign_keys` | 密钥表膨胀 |
| 2 | 清理僵尸设备 | 心跳超时的设备置为离线 | 「在线用户」堆满早已关机的机器 |
| 3 | 清理限流记录 | 删除 10 分钟前的限流窗口 | `nb_rate_limit` 无限增长 |
| 4 | 清理过期日志 | 按 `log.keep_days`（默认 30 天）删 `nb_logs` | 日志表过千万行后后台变卡 |
| 5 | 清理过期统计 | `nb_api_stats` 保留 90 天 | 统计表膨胀 |
| 5b | 在线数快照 | 每分钟记一个点供大屏画曲线，保留 90 天 | 在线曲线长期空白 |
| 6 | 清理过期 nonce | 删除防重放缓存 | 磁盘吃满 |
| 7 | 清理旧文件日志 | 按 `log.keep_days` 删除 `logs/*.log` | 磁盘吃满 |
| 8 | 缓存维护 | 清理 7 天前的 `logs/cache/k_*` | 缓存目录无限增长 |
| 9 | 数据备份 | 按间隔自动备份数据库 | **没有备份**，出事无法恢复 |
| 10 | 健康巡检 | 磁盘 / 数据库 / 目录权限 / PHP 扩展 / 备份新鲜度 | 磁盘满、备份失败无人知晓 |

> 磁盘先爆的往往不是数据库，而是 `logs/`。第 6、7、8 三项都是纯磁盘回收，**cron 一停，最先出问题的就是它**。

### 4.2 接法一：crontab（推荐）

```cron
* * * * * /usr/bin/php /www/nebula/cron.php >> /www/nebula/logs/cron.log 2>&1
```

### 4.3 接法二：HTTP 触发

适合无法使用 crontab 的环境，用外部定时服务访问：

```
https://你的域名/cron.php?key=你的cron密钥
```

密钥优先级：后台配置的 `security.cron_secret` → 自动生成并落盘到 `logs/cron_secret.txt`。

> **不要拿客户端签名密钥（`sign_salt`）当 cron key 用**。`sign_salt` 能从合法客户端里被提取出来，一旦混用，攻击者可以任意高频触发备份与清理这类重任务。

### 4.4 验证它真的在跑

```bash
tail -20 /www/nebula/logs/cron.log          # 应有本轮执行记录
ls -lh /www/nebula/data/backups/            # 备份文件时间戳应在刷新
php /www/nebula/install/migrate.php status  # 顺便确认库版本
```

日志里连续出现 `PHP Fatal error` 说明 cron 一直失败而你没发现——**这是最容易被忽略的故障**，建议在前 30 天每天看一眼。

---

## 5. 备份与恢复

### 5.1 必须备份的四样东西

| 内容 | 位置 | 丢失后果 |
| --- | --- | --- |
| 数据库 | 由 `Backup` 导出 | 用户、卡密、订单、日志全丢 |
| 配置文件 | `config/` | 数据库密码、签名密钥全丢，客户端全部连不上 |
| 上传文件 | `uploads/` | 官网图片、模板资源丢失 |
| 完整性基准 | `data/file_baseline.json` | 无法判断文件是否被篡改（可重建，但基线丢失期间等于裸奔） |

**代码本身不用备份**（发行包可重新获取），但**客户自己改过的代码必须单独备份**，见 §10。

### 5.2 自动备份

由 `cron.php` 调 `Backup::auto()` 完成，纯 PHP 导出 SQL 并 gzip（不依赖 `mysqldump`，共享主机禁用 `exec` 时同样可用），产物落在 `data/backups/nb_YYYYmmdd_HHMMSS.sql.gz`。

后台「系统设置 → 维护 → 数据备份」可调三项：

| 配置键 | 默认 | 说明 |
| --- | --- | --- |
| `backup_mode` | `auto` | `off` 不备份 / `auto` 自动 / `manual` 仅手动 |
| `backup_interval_hours` | `24` | 自动备份间隔（小时） |
| `backup_keep` | `7` | 保留最近 N 份，超出自动删除 |

**默认 7 份 × 24 小时 = 只有 7 天窗口**。数据库较大或业务敏感时，把 `backup_keep` 调到 `30`，或额外把 `data/backups/` 同步到异地。

> **`backup_mode` 的三档是互斥的**：
> - `auto`：cron 按间隔自动备份；
> - `manual`：cron 不自动备份，只允许手动执行；
> - `off`：**自动和手动都不允许**，连 §5.3 的手动命令也会被拒绝执行。
>
> 所以想"关掉自动、只手动备份"，要选 `manual`，不能选 `off`。
>
> 备份依赖 PHP 的 `zlib` 扩展（`gzopen`）。扩展缺失时备份会直接失败，`Health::run` 健康巡检会报出来。

### 5.3 手动备份（重大变更前必做）

```bash
cd /www/nebula
php -r 'require "lib/bootstrap.php"; $r = Backup::run(); print_r($r);'
```

返回里 `ok=true` 且带 `file` 才算成功。若返回「备份模式为「不备份」，已禁止执行备份」，说明后台把 `backup_mode` 设成了 `off`，先去后台「系统设置 → 维护 → 数据备份」改成 `manual` 或 `auto`。

升级、改配置、批量操作卡密、调整用户组之前，**先手动备份一次**。

### 5.4 恢复

```bash
# 1. 停掉定时任务，避免边恢复边写入
crontab -l | grep -v cron.php | crontab -

# 2. 解压
gunzip -c /www/nebula/data/backups/nb_20261026_030000.sql.gz > /tmp/restore.sql

# 3. 导入（先建空库或直接覆盖，视备份内容而定）
mysql -u 数据库用户 -p 数据库名 < /tmp/restore.sql

# 4. 清运行时策略缓存（可选：设置类改动已立即生效，此项只影响防护策略）
rm -f /www/nebula/logs/cache/k_*

# 5. 恢复定时任务
crontab -e   # 把 cron.php 那行加回去
```

> 第 4 步只影响运行时防护策略缓存。站点设置（`nb_settings`）是每个请求重新读库的，恢复数据库后立即生效，不需要清缓存。

### 5.5 备份没有验证过等于没有备份

至少每季度做一次**恢复演练**：把备份导入一个临时库，确认表数量和关键数据行数对得上。

```bash
mysql -u 用户 -p -e "SELECT COUNT(*) FROM 库名.nb_users;"
mysql -u 用户 -p -e "SELECT COUNT(*) FROM 库名.nb_cards;"
```

演练过的人，出事时 10 分钟能恢复；没演练过的人，出事时才发现备份是空文件。

---

## 6. 升级流程

自建部署的升级风险全部集中在两件事：**数据库结构变了没迁移**，和**客户自己改的文件被覆盖了**。

### 6.1 升级前

```bash
cd /www/nebula

# 1. 记录当前版本（出问题要能说清从哪升到哪）
php -r 'require "lib/bootstrap.php"; echo NB_VERSION, "\n";'

# 2. 备份数据库
php -r 'require "lib/bootstrap.php"; print_r(Backup::run());'

# 3. 备份整份代码（最省事，回滚时直接换回去）
tar czf /tmp/nebula-code-$(date +%Y%m%d%H%M).tar.gz \
    --exclude=logs --exclude=data/backups .

# 4. 记录自己改过哪些文件（见 §10）
```

### 6.2 升级中

1. 上传新版本文件覆盖。**以下文件严禁覆盖**（覆盖即丢失）：

   | 文件 | 原因 |
   | --- | --- |
   | `config/config.php` | 数据库凭据、签名私钥、安全开关，每站唯一 |
   | `config/` 下其余密钥文件 | 离线宽限等密钥，覆盖后已发出的票据全部失效 |
   | `data/file_baseline.json` | 文件完整性基准 |
   | `shop/*_notify.php` | 支付回调地址，被覆盖会改成默认值导致回调失败 |

2. 跑数据库迁移：

   ```bash
   php install/migrate.php status   # 先看：当前版本、待执行迁移列表
   php install/migrate.php run      # 再执行
   ```

   迁移是版本化登记的，`schema_version` 存在 `settings` 表，只会补跑「大于当前版本」的脚本，**可以重复运行**。

   如果 `status` 提示某个迁移文件缺失，**立刻停止**——执行器此时会主动终止，宁可不动库也不做半升级。补齐文件后重跑。

3. 清运行时策略缓存（升级后建议做，避免残留旧策略）：

   ```bash
   rm -f logs/cache/k_*
   ```

### 6.3 升级后

```bash
php install/migrate.php status          # 确认版本已是最新、无待执行项
tail -50 logs/error.log                 # 确认无 Fatal
curl -s https://你的域名/api/init | head -c 300   # 接口有正常响应
```

然后登后台看三处：仪表盘数据是否正常、系统设置的配置项是否还在、软件列表是否完整。

客户端侧：随便找一台已授权的机器登录一次，确认能正常握手与心跳。

### 6.4 回滚

**前提：升级前做过数据库备份。**

```bash
# 1. 代码回滚（用 6.1 的 tar 包）
cd /www/nebula
tar xzf /tmp/nebula-code-20261026xxxx.tar.gz

# 2. 数据库回滚（迁移通常是加表加列，一般不需要回滚；但结构改动大的版本必须回）
gunzip -c data/backups/nb_<升级前的时间戳>.sql.gz > /tmp/rollback.sql
mysql -u 用户 -p 库名 < /tmp/rollback.sql

# 3. 清运行时策略缓存
rm -f logs/cache/k_*

# 4. 验证
php install/migrate.php status
```

> **迁移不可逆**。`migrate.php run` 之后想退回旧版，光换代码不够——旧代码可能不认新结构。所以 6.1 的数据库备份是硬性前置条件。

---

## 7. 故障排查

按现象查，不要凭感觉重启。

### 7.1 整站 500 / 白屏

```bash
tail -50 logs/error.log                 # 先看有没有 Fatal
php -l lib/bootstrap.php                # 语法检查
php -r 'require "lib/bootstrap.php"; echo "OK\n";'   # 引导能否跑通
```

常见原因依次是：PHP 版本不足 8.0、扩展缺失、`config/config.php` 权限不对读不到、`logs/` 不可写。

### 7.2 后台打不开，返回 404

**先确认是否漏带入口密钥**，这是最常见的误判：

- 正确入口：`https://域名/后台目录/?entry=你的入口密钥`
- 首次带对后，会种一个 30 天有效的 HttpOnly Cookie，之后可免带
- 入口密钥为空时该机制不生效；设置了就必须带

如果带对了仍 404，检查 §3 的 deny 规则是否把后台目录本身也拦掉了。

### 7.3 客户端连不上 / 握手失败

按顺序排除：

| 现象 | 检查点 |
| --- | --- |
| 完全无响应 | `curl` 测 `api/handshake` 是否可达；防火墙、CDN 是否拦截 POST |
| 握手返回协议版本异常 | 客户端与服务端 `proto` 必须一致（当前为 `31`） |
| 签名校验失败 | 服务器时间是否准确。**时钟偏差超过窗口会直接导致验签失败**，装 `ntp`/`chrony` 校时 |
| 旧客户端突然全部报错 | 检查 `min_version` 是否被调高，强制更新会踢掉低版本 |
| 大面积断线 | 数据库连接数耗尽，或磁盘满导致写不进会话 |

```bash
date                              # 服务器时间
timedatectl status 2>/dev/null    # 时区与同步状态
df -h                             # 磁盘
mysql -e "show processlist"       # 连接数
```

### 7.4 提示「今日调用配额已用尽」

用户组设了 `daily_quota`。注意心跳默认 60 秒一次，**一天约 1440 次**，如果配额设成几百，当天很早就会被心跳吃掉。

调整路径：后台「用户管理 → 用户组」→ 修改该组 `daily_quota`，或设为 0 表示不限。

### 7.5 改了配置不生效

先分清改的是哪一类：

| 改的是什么 | 生效时机 |
| --- | --- |
| 后台「系统设置」里的项（存 `nb_settings`） | **下一次请求就生效**，配置在每个请求里重新读库 |
| 运行时防护策略（存 `nb_runtime_policies`） | 有 60 秒缓存，最多等一分钟 |
| 直接改数据库 | 设置类同样立即生效，无需清缓存 |

只有运行时防护策略需要手动清缓存来让它立刻生效：

```bash
rm -f logs/cache/k_*
```

如果改完还是不生效，问题通常不在缓存，而在**改错了地方**——比如同一项在 `nb_softwares.policy_json`（分软件覆盖）里也有值，分软件的值优先于全局。

### 7.6 在线数、活跃统计长期为 0

定时任务没跑。回到 §4，先确认 `logs/cron.log` 有没有新内容。

---

## 8. 安全事件处置

### 8.1 发现可疑文件 / 疑似被挂马

后台的「完整性校验」页会给出三类结果：新增、修改、缺失；「挂马扫描」页会按特征规则评分列出可疑文件，并单独列出 `uploads/` 内出现的可执行文件（最高危）。

处置顺序：

```bash
# 1. 立即备份现场（不要先删，取证需要）
tar czf /tmp/incident-$(date +%s).tar.gz uploads logs

# 2. 记录异常文件的路径、修改时间、内容
#    （「完整性校验」页可直接查看可疑文件前 64KB）

# 3. 隔离：把可疑文件移出 Web 目录，不要直接 rm
mv uploads/可疑文件 /tmp/quarantine/

# 4. 改密码：管理员、数据库、以及所有有后台权限的账号
# 5. 轮换签名密钥（后台「通信安全」→ 响应签名轮换；离线宽限密钥同理）
# 6. 重建完整性基准（确认干净之后，在「完整性校验」页点重建）
```

**第 5 步容易被忘**：如果攻击者拿到过服务器权限，签名私钥应当视为已泄露。签名的用途是让客户端验证「服务端是真的」，私钥泄露等于对方可以冒充你的服务端。

### 8.2 数据库被拖

1. 立刻改数据库密码，并检查 `config/config.php` 权限；
2. 审计日志里查异常登录 IP 与时间点，确认入侵入口（多数是弱口令后台或上传点）；
3. 检查 `nb_users` 是否有异常新增账号；
4. 通知用户改密码（如系统对外提供用户体系）；
5. 轮换全部密钥。

### 8.3 管理员账号疑似泄露

1. 后台「管理员」页立即改密并**踢掉该账号的所有会话**；
2. 看审计日志确认他做过什么（生成卡密？改用户到期时间？）；
3. 补齐 2FA：后台支持 TOTP，日常操作账号应全部开启；
4. 复核权限矩阵，确认该账号原本不该有的权限。

### 8.4 大量卡密异常生成 / 被刷

1. 审计日志按时间排序，定位操作账号与 IP；
2. 作废受影响批次（后台「卡密管理 → 批次作废」，不要逐条删，会留不下记录）；
3. 检查代理体系：`agent_codes` 是否被滥用；
4. 调低单 IP 限流阈值（`rate_limit_per_min`）。

---

## 9. 容量与性能

### 9.1 心跳是主要压力来源

每个在线客户端默认 60 秒一次心跳。粗略估算：

```
每分钟请求数 ≈ 在线数 × 1 + 登录/激活等业务请求
```

1000 人在线 ≈ 每分钟 1000+ 请求 ≈ 每秒 17 次。这个量级单机 PHP-FPM 完全够用。

到 5000 在线以上再考虑：调大 `heartbeat_interval`（代价是掉线判定变慢）、加 PHP-FPM 进程数、把数据库迁到独立机器。

### 9.2 该看的地方

```bash
# 慢查询
mysql -e "SHOW FULL PROCESSLIST"

# 表体积，找出增长最快的表
mysql -e "SELECT table_name, ROUND(data_length/1024/1024,1) AS mb, table_rows
          FROM information_schema.tables
          WHERE table_schema='库名' ORDER BY data_length DESC LIMIT 10;"

# 磁盘
df -h
du -sh /www/nebula/data/backups /www/nebula/logs
```

`nb_logs`、`nb_api_stats`、`nb_hsessions`（握手会话）、`nb_api_quota` 是四个增长最快的表，都靠定时任务回收。**如果 cron 停了，先爆的是磁盘**。

### 9.3 日志保留

`nb_logs` 与 `logs/*.log` 的保留天数由 **`config/config.php` 里的 `log.keep_days`** 控制，默认 **30 天**，由定时任务的「清理过期日志」执行。

API 调用统计和在线数快照固定保留 90 天（写死在 `cron.php` 里，不可配）。

日志量大的站点应把 `log.keep_days` 调到 7～15 天，否则 `nb_logs` 过千万行后，后台日志页的翻页与筛选会明显变慢。

---

## 10. 客户自改代码的变更管理

**这是自建部署独有的风险**。本系统目前**没有插件/钩子机制**，任何定制都要直接改核心文件，因此必须自己建立变更纪律。

### 10.1 改之前

```bash
# 建一份改动清单，每次改完追加一行：时间 + 文件 + 改了什么
echo "$(date +%F' '%T) api/handlers/login.php 增加部门字段校验" >> ~/nebula-changes.txt
```

### 10.2 用文件完整性做差异比对

用「完整性校验」页做差异比对。重建基准的时机应该是：**刚部署完、或刚升级完、且确认代码是你期望的状态**。

之后它报出的「已修改」列表，就是你和原始版本的差异——**升级前先看这个列表**，这些文件升级时会被覆盖。

### 10.3 升级冲突怎么处理

| 情况 | 做法 |
| --- | --- |
| 改的文件，新版本没动 | 直接保留你的版本 |
| 改的文件，新版本也改了 | 逐个对比合并，**不要整文件覆盖** |
| 只是加字段 / 加接口 | 优先考虑用「新文件 + 单独入口」实现，避免改核心文件 |

### 10.4 长期建议

把定制尽量收敛到三个地方，能显著降低升级成本：

1. **数据库层**：加表、加字段。升级不会覆盖数据。
2. **新增文件**：新写一个 `api/handlers/自定义.php` 并在路由里登记，而不是改 `login.php`。
3. **配置层**：能用 `policy_json`、`nb_settings` 表达的，不要写死在代码里。

---

## 11. 责任边界

| 事项 | 责任方 |
| --- | --- |
| 服务器、域名、TLS 证书、系统补丁 | 部署方 |
| 数据库账号权限、备份策略与恢复演练 | 部署方 |
| 后台账号与密码、2FA、权限分配 | 部署方 |
| 客户自己修改代码引起的故障与升级冲突 | 部署方 |
| 发行包本身的代码缺陷 | 提供方（请附版本号、复现步骤、`logs/error.log` 相关片段） |

**反馈问题前请先收集**：

```bash
php -r 'require "lib/bootstrap.php"; echo NB_VERSION, "\n";'   # 版本号
php -v | head -1                                                # PHP 版本
mysql -e "SELECT VERSION();"                                    # MySQL 版本
tail -100 logs/error.log                                        # 报错上下文
```

这三项齐了，绝大多数问题能一次定位。

---

## 附：一页速查

```bash
# 版本
php -r 'require "lib/bootstrap.php"; echo NB_VERSION,"\n";'

# 库版本与待执行迁移
php install/migrate.php status

# 手动备份
php -r 'require "lib/bootstrap.php"; print_r(Backup::run());'

# 清配置缓存
rm -f logs/cache/k_*

# 跑一次定时任务（手动触发，用于验证）
php cron.php

# 看错误
tail -50 logs/error.log

# 看 cron 是否在跑
tail -20 logs/cron.log
```
