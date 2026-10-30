# NF-A02 Mac/Herd 运行准备

日期：2026-10-29。用户确认运行目标为当前 Mac/Herd；临时备份位置为项目 `.local/backups/nf-a02`，保留 7 天，正式目的地待定。维护入口是当前 Mac 的本地终端和当前登录用户的 launchd 会话，不假定存在 SSH 或 Linux systemd。

首次交付为隔离准备；后续经用户明确授权，已完成本机专用实例、开发账户切换与长期服务启用。当前事实见[运行落地验收](NF-A02_RUNTIME_ACCEPTANCE.md)，首次记录见[隔离准备](NF-A02_ACCEPTANCE.md)。共享源库保留原样，不能再向其写入后假定会自动同步到新实例。

## 当前运行入口（2026-10-29）

- PostgreSQL：项目`.local/runtime/postgres`，私有socket`.local/runtime/socket`，port55432仅用于socket名称，无TCP监听。开发库`novel_forge`，应用账户`novel_forge_app`；迁移口令只在私有`.local/runtime/credentials.json`及临时运维进程使用。
- Redis：继续使用原项目`.local/redis.conf`、`.local/redis`与回环端口56379、原口令/AOF；由`local.novelforge.redis`前台托管，未新建或清空队列。
- 六份plist安装在当前用户`~/Library/LaunchAgents/`，标签为`local.novelforge.postgres`、`redis`、`worker-production`、`worker-derived`、`scheduler`、`backup`。前五项常驻，backup是定时任务，空闲时`not running`且上次退出码0属于正常。
- 当前系统时区已核实为Asia/Shanghai；每日03:15及加载备份服务时执行备份，目录`.local/backups/nf-a02`，保留7天。快照名用UTC时间，内容包含数据库custom dump和`storage/app/private`，不包含.env、APP_KEY、运行凭据或日志。APP_KEY保持原值，需单独妥善保存。

从项目根目录进行日常维护：

```sh
php scripts/deployment/runtime-bridge.php health
python3 scripts/deployment/migrate-runtime.py
python3 scripts/deployment/backup-runtime.py
launchctl print "gui/$(id -u)/local.novelforge.worker-derived"
php artisan queue:restart
```

迁移脚本只在专用实例临时使用运维账户并重新应用授权；不要直接用应用账户执行DDL。存在配置缓存时迁移会拒绝，先按下文的缓存流程安排维护窗口。`verify-runtime.py`会短暂维护、重启本项目依赖及消费者，并在专用实例创建/删除`novel_forge_test`做实际恢复，因此只在维护窗口手工运行。

## 备份启动与一致性补强（2026-10-29）

备份任务在取得独占锁后，每次最多等待数据库60秒；连接探测有单次超时。启动未就绪或备份失败时共尝试3次，重试间隔10秒、30秒；耗尽后退出非零并在私有日志写明失败次数，保留以前的成功备份。错误数据库身份立即拒绝，不重试；已有备份在运行时重复触发会明确返回skipped。没有开启无限KeepAlive，也没有修改原每日03:15计划。

新备份格式为`novelforge-runtime-v2`。表数据摘要与pg_dump使用同一导出快照；序列不受MVCC快照保护，因此冻结pg_dump归档实际保存的setval；私有文件摘要来自归档字节。恢复校验只使用包内`verification`，不再与已继续写入的实时库或文件比较。数据库与私有文件仍不是跨存储全局原子快照，不扩大到H04。

需要只验证某一份新备份、且不重启应用时，使用备份输出的准确目录名，例如本轮实际产物：

```sh
python3 scripts/deployment/verify_backup.py .local/backups/nf-a02/nf-runtime-20261029T085243Z
```

该命令只在本项目专用实例创建/删除`novel_forge_test`，已有同名库会拒绝覆盖，恢复锁防止重复验收。v1旧备份继续按7天保留，可按原运维流程恢复，但因缺少冻结清单，新校验器会明确拒绝自动验收，不会回退到实时比较。

备份失败的半成品目录不会发布；只有成功发布后才执行保留清理。若发布成功但清理失败，单独记录`backup_retention_failed`并退出非零，避免误报保留期已执行，也不会重新生成重复备份。查看`.local/services/backup.out.log`的目录、attempts与retention_ok，以及`.local/services/backup.err.log`的失败事件即可，不应把密钥粘贴到诊断输出。

当前共享实例上的旧`novel_forge`是切换前副本；运行后新数据只进入专用实例。`.local/runtime/environment.before`是回退配置证据，**不能在产生新数据后直接覆盖.env盲目回退**。需要回退时先停止本项目写入与消费者，备份当前专用库，核对增量并制定恢复目标，不能擅自覆盖共享旧库。安装脚本的prepare/cutover/enable是首次落地阶段，已有实例或文件时会拒绝覆盖，不要当作日常重启命令。

本机托管使用同一操作系统用户；数据库身份分离不能阻止取得该用户文件访问权限的进程读取运维凭据。0700/0600保护其他系统用户，本轮没有新建系统账户或修改Herd的运行身份。

## 账户与数据库边界

| 身份 | 权限 | 使用位置 |
| --- | --- | --- |
| 专用实例管理员 | 初始化库、安装 vector/pg_trgm、维护认证规则 | 本机运维入口，不能放入应用环境 |
| `novel_forge_migrator` | 目标库所有者、受控结构变更 | 临时迁移进程；与应用凭据分开保管 |
| `novel_forge_app` | public schema 使用、业务表 SELECT/INSERT/UPDATE/DELETE、序列 USAGE/SELECT | Herd Web、Worker、调度器 |

应用没有超级用户、CREATEDB、CREATEROLE、REPLICATION、BYPASSRLS、数据库 CREATE/TEMP、表 TRUNCATE/TRIGGER、迁移账户成员资格或迁移记录写权限。既有不可变触发器继续执行，CRUD 授权不能绕过业务约束。

只撤销目标库 PUBLIC 权限不足以阻止连接其他库：其他库的 PUBLIC CONNECT 仍可能存在。因此必须使用**专用实例和按角色首匹配拒绝的认证规则**，而不是批量修改其他 Herd 项目的库权限。[PostgreSQL 18 权限说明](https://www.postgresql.org/docs/18/ddl-priv.html)和[认证规则顺序](https://www.postgresql.org/docs/18/auth-pg-hba-conf.html)为此设计的依据。

后续正式启用顺序：

1. 在项目私有目录初始化单独的 PostgreSQL 18 实例；`cluster_name = 'novelforge-private'`、`listen_addresses = ''`、私有 Unix socket 0700。初始化管理员与应用身份分离。不修改共享实例的配置、角色或 HBA，不执行共享服务重启。
2. 管理员建立目标库 `novel_forge`，审核并配置 `scripts/deployment/pg_hba.conf.example`，将应用/迁移角色规则放在宽泛规则之前。管理员认证另行配置；测试支架的管理员 trust 只用于已隔离、随验收销毁的实例，不能复制到正式运行配置。
3. 管理员在目标库执行 `bootstrap-postgres.sql`。脚本要求专用实例标记、PG18 和允许的库名；角色已存在会失败，不会改写已有角色。通过交互式 `psql` 的 `\password novel_forge_app` 与 `\password novel_forge_migrator` 分别设置口令；禁止把真实口令粘进 SQL 文件、命令行参数或版本库。
4. 在迁移窗口备份并核对目标；停下本项目消费者及调度器。在未缓存配置的临时进程中注入迁移账户连接，执行 `php artisan migrate --force`。迁移凭据不写入应用 `.env`，不在该环境执行 `config:cache`。若有旧配置缓存，先按当前部署流程清理，结束后使用**应用账户**重建缓存。
5. 迁移后以迁移账户执行 `grant-runtime.sql`；应用不能伪造 migrations 记录。默认权限仅适用于该迁移角色创建的未来对象。原有库的对象所有权迁移必须另行审核，不能用这一新实例初始化脚本冒充存量转换。
6. 切换运行目标前，在隔离副本用应用账户复验 CRUD、所有拒绝项、业务约束与恢复，再将 Web/Worker/调度器统一指向目标实例和相同缓存。测试配置仍保持原来的 `novel_forge_test` 守卫，不修改固定连接规则。

本轮对实际结构的验证使用现有测试库 schema 导出后由迁移角色导入；它证明现有表、索引、函数、触发器的结构权限兼容，**不替代新实例上逐条 PHP 迁移与存量数据切换验收**。

## 私有目录与备份

在项目根目录执行：

```sh
python3 scripts/deployment/prepare-private-dirs.py
```

脚本先检查所有目标及父目录不是软链且归当前用户所有，再设置 `.local`、`.local/services`、`.local/backups`、临时备份目标、`storage/app/private` 与 `storage/logs` 为 0700，现有 `.env` / `.env.testing` 为 0600。没有递归改写业务文件。Herd 与用户 LaunchAgent 必须以同一用户访问；多用户/远端服务须重新设计用户组权限。私有产物不能通过 public/storage 链接暴露。

首次隔离准备的`nf-a02-test-*.dump`是人工夹具，由隔离工具管理。当前长期备份为`nf-runtime-时间戳`目录，数据库与私有产物成功写入并计算哈希后才原子发布；仅在新备份成功后清理超过7天且匹配命名/登记类型的旧运行备份，跳过软链、不完整或陌生目录。切换前源库快照也按登记时间和哈希保留7天。环境文件与运维凭据不随数据备份打包。

当前已确认每日频率；正式异盘目标、加密密钥托管以及真实密钥恢复仍待确认。同盘备份不能应对主机丢失，不能据此声称达到PRD的RPO/RTO。H04负责完整策略。数据库快照由pg_dump保证一致性；私有产物随后打包，不宣称跨数据库与文件系统的全局原子快照，运行期间的产物生命周期仍须H04补齐。

## Mac 服务托管

下列为新环境首次安装示例；当前六项服务已安装，日常使用本页顶部维护入口，不重复bootstrap。Homebrew/Herd升级后须复核plist中的可执行路径；本轮没有升级这些工具。

生成模板（输出目录必须不存在，避免覆盖本地定制）：

```sh
python3 scripts/deployment/render-launchd.py --output .local/launchd-release
plutil -lint .local/launchd-release/*.plist
```

模板会解析当前 PHP 路径并明确 PATH，不嵌入密码。需要先完成运行账户切换及目录准备，再在当前登录会话按文件逐个加载，例如：

```sh
launchctl bootstrap "gui/$(id -u)" "$PWD/.local/launchd-release/local.novelforge.worker-production.plist"
launchctl bootstrap "gui/$(id -u)" "$PWD/.local/launchd-release/local.novelforge.worker-derived.plist"
launchctl bootstrap "gui/$(id -u)" "$PWD/.local/launchd-release/local.novelforge.scheduler.plist"
```

两个 Worker 分别处理 planning/generation/review 与 memory/export/maintenance；timeout60秒、tries1，退出等待75秒，KeepAlive 自动拉起。必须保持 Redis retry_after120秒（大于业务90秒租约）及同一项目共享缓存；多机或数组缓存无法证明 queue:restart 生效。

发布后执行 `php artisan queue:restart`，核对两个 Worker 的旧 PID 退出、新 PID 接手，再检查 outbox/回执及失败记录。调度器部署更新可用 `launchctl kickstart -k "gui/$(id -u)/local.novelforge.scheduler"`，随后验证新 PID 和三个分钟任务；只操作本项目标签。下线用相同目标执行 `launchctl bootout`，不终止所有 PHP/Redis/PostgreSQL 进程。

这些是用户会话LaunchAgent，不是开机后无人登录的系统服务；注销、休眠、断电期间不能承诺持续运行或5分钟恢复。本轮已安装专用PostgreSQL和原项目专属Redis的常驻服务；共享数据库未交给本项目管理。未通过实际注销/重启Mac打断用户会话来验证重新登录场景，已验证plist持久安装、依赖重启和进程自动拉起。

Redis运行目标须是项目专属实例或经审核的独立ACL；仅使用逻辑database/prefix不构成安全隔离。首次隔离准备使用禁用TCP/持久化的测试socket；后续运行落地已核实开发Redis的项目目录、pidfile和AOF归属，再将原实例转为launchd前台托管。配置文件及数据目录沿用原值，没有接管共享Redis。

## 重复验收

```sh
php scripts/deployment/export-testing-schema.php
python3 scripts/deployment/verify-isolated.py --schema .local/nf-a02-schema.dump
sh scripts/start-testing-redis.sh
# Redis 就绪后，在另一终端顺序运行；不要与其他恢复测试并行。
python3 scripts/deployment/verify-launchd.py
php -d memory_limit=512M vendor/bin/phpunit tests/Feature/OutboxRecoveryTest.php tests/Feature/TestIsolationTest.php tests/Unit/TestIsolationTest.php
```

第一条沿用项目 testing 启动守卫，只读导出 `novel_forge_test` 的结构。第二条创建随机私有实例与同名测试库，任何退出都会停止自己的实例；停止失败会报错并保留现场。第三条若测试 socket 已存在会拒绝接管；先确认归属，不能盲删。第四条临时加载随机标签，使用真实测试队列消费、缓存确认、queue:restart 新 PID 与调度器新 PID，最终卸载自己的标签；失败日志仅保留在 `.local`。恢复测试可能重启专用测试 Redis；结束后只停止已确认属于本次验收的专用实例。
