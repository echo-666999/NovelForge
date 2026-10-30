# NF-A02 Mac/Herd运行落地验收

日期：2026-10-29，Asia/Shanghai。用户授权继续完成开发账户切换和长期服务，以当前Mac/Herd为目标，不影响其他项目和共享数据库；本机备份目录保留7天。

**结果：本机开发账户切换与当前用户长期服务已完成。** 与先前仅隔离准备阶段不同，本次已修改本项目开发连接并持久安装六份LaunchAgent。未调用真实模型，未修改共享数据库、其他项目或Herd全局配置。

后续NF-A02等待/重试与恢复一致性补强已完成，当前追加证据见[收口验收](NF-A02_HARDENING.md)。以下原始运行落地结果按其当时版本保留；新v2恢复校验使用冻结备份清单，不再比较实时业务库。

## 实际变更

| 项目 | 已落地结果 |
| --- | --- |
| 开发数据库 | 专用PostgreSQL18.6，`.local/runtime/postgres`；私有socket`.local/runtime/socket`、55432，无TCP监听；数据库名仍为`novel_forge` |
| 运行身份 | `.env`改为`novel_forge_app`；迁移身份`novel_forge_migrator`只经私有凭据与临时进程使用；APP_KEY原值保留 |
| 数据迁移 | 在本项目维护窗口只读备份原共享实例的`novel_forge`，恢复至新实例；151表、135序列、32行已有数据逐项核对一致；切换后再次核对共享源库摘要未变化 |
| Redis | 核实为本项目独立目录、原回环端口56379、原口令及AOF；正常停止后由launchd前台启动同一配置；切换前六个业务队列均为空 |
| 长期托管 | 当前用户`~/Library/LaunchAgents/local.novelforge.*.plist`：postgres、redis、worker-production、worker-derived、scheduler、backup；文件0600，日志在项目私有目录 |
| 自动备份 | 当前系统时区确认Asia/Shanghai；每日03:15及服务加载时备份数据库和私有产物，目录`.local/backups/nf-a02`；成功发布后清理7天以前的本工具完整备份；切换源快照按登记哈希同样保留7天 |
| 迁移入口 | `python3 scripts/deployment/migrate-runtime.py`已实际运行成功，再应用运行权限；不将运维凭据写入应用环境或配置缓存 |

原共享库保留为切换前副本，没有修改其表、角色、权限、认证规则或服务。新运行数据不会反向同步；不能把旧共享库当作仍在更新的主库。

## 实际验证

- 运行连接确认为`novel_forge_app`及专用实例；超级用户、建库、建角色、复制、绕过RLS、数据库CREATE/TEMP、schema CREATE、TRUNCATE、迁移记录写权限均关闭。所有业务表的SELECT/INSERT/UPDATE/DELETE四项分别核实；应用与迁移账户连接postgres/template1均被认证规则拒绝。
- 专用PostgreSQL与Redis真实重启后自动拉起，应用健康检查通过。只暂停和恢复本项目三个消费者/调度标签，未重启共享依赖。
- 真实maintenance探针经长期Worker消费；`queue:restart`后两个Worker的PID变化，新Worker再次消费成功。探针无小说或生成业务副作用，确认缓存已清理。
- `schedule:run`三个现有分钟任务均DONE；调度器常驻运行。没有把进程存在等同于业务执行成功。
- 实际备份在专用实例新建的`novel_forge_test`中恢复，151表、135序列、32行内容摘要一致；恢复库随后删除。原共享测试库未使用、未清空。
- 私有产物包实际恢复至临时目录，1个现有文件的哈希一致；无真实供应商密钥解密样本，不能据此声称密钥恢复通过。
- 备份LaunchAgent实际执行退出码0。备份清理边界测试通过：仅删除超过7天且归属明确的备份，保留近期、陌生、不完整目录和软链；测试使用临时人工文件，无真实备份被删。
- Herd的`/admin/login`和`/up`均HTTP200；CLI健康检查为专用应用账户、Redis ready、file共享缓存、redis队列。
- 新增PHP工具Pint通过，Python语法检查通过，六份已安装plist格式检查通过；没有重跑完整业务套件，也没有修改测试连接守卫。

新增代码均有中文注释；没有新增业务迁移或新业务字段。复制后原有表和字段备注与源库逐项一致。专用账户的中文角色备注来自已验收初始化脚本。

证据摘要追加在[NF-A02_VERIFICATION.json](NF-A02_VERIFICATION.json)的`runtime`字段；私有备份、逐表摘要、连接信息与本机日志留在`.local`，不入仓库。

## 修复记录与运行边界

专用PostgreSQL首次在launchd下启动报`postmaster became multithreaded during startup`，日志要求有效locale。已显式设置`LC_ALL=C`并重载本项目标签；随后初始化、数据迁移及真实重启均通过。失败期间没有切换开发连接。没有重启或修改共享PostgreSQL。

六项服务是当前用户登录会话的LaunchAgent，已持久安装并实际运行/重启验证；未通过注销或重启Mac打断用户来验证登录场景，也不承诺休眠、注销、断电或无人登录期间持续运行。

备份尚为用户确认的本机同盘方案，APP_KEY/运维凭据未打入数据备份，应另行保管；正式异盘目的地、备份加密与真实密钥解密恢复、RPO/RTO继续归H04。数据库一致性快照与随后打包的私有文件不是全局原子快照。本机使用同一OS用户，数据库身份分离不是操作系统用户隔离。

日常命令、受控迁移及回退约束见[运行手册](NF-A02_OPERATIONS.md)。本轮不提交代码、不新增系统用户、不升级NF-F01或其他任务状态。
