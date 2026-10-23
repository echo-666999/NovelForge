# NF-A01 基线核查与门禁

核查日期：2026-10-22。只读源码检查和既有隔离测试，不修改业务代码。测试产生的测试库重建及运行缓存不属于业务实现；没有对开发库执行迁移、清库、写业务数据或更换账号。

## 1. 本次确认事实

| 对象 | 当前证据 | 结论 |
| --- | --- | --- |
| Laravel / Filament / Livewire | composer.lock + 已安装依赖 | 13.38.0 / 5.10.3 / 4.4.8 |
| Predis / PHPUnit | composer.lock | 3.7.0 / 12.5.40 |
| CLI PHP / Node | 本次实际命令 | PHP 8.5.7 / Node v22.23.3；早期记录为PHP8.5.12，本轮不能继续声称同一运行时版本 |
| PHP依赖 | composer check-platform-reqs | 所列检查success；这不替代Web运行时和真实供应商能力验证 |
| 基础测试 | 显式固定APP_ENV、DB_CONNECTION、DB_DATABASE、DB_URL，先查current_database() | 实际目标novel_forge_test；9项中8通过、1中文配置缺失跳过，18断言 |
| 格式 | vendor/bin/pint --test | 通过；没有执行自动格式修复 |
| 后台 | route:list和登录页HTTP请求 | 仅/admin、登录、退出、实施状态共4条路由；登录HTTP 200 |
| 数据库与Redis | novelforge:doctor | PG18、vector、pg_trgm、Redis就绪；pg_jieba和中文配置未就绪，因此诊断命令整体不应判为成功 |
| 业务范围 | app / migrations / routes清单 | 只有User、后台基础、环境命令和探针，没有小说业务模型、接口与默认业务迁移 |
| 中文分词脚本 | database/migrations/search | 可选目录未纳入默认测试重建；未在实际服务器执行 |

本轮没有重新构建前端、浏览器登录、运行真实队列消费探针、模型调用、备份恢复、中文召回评测或性能测试。相应历史结果只能见FOUNDATION，不能记为本轮通过。Web PHP版本没有在本轮独立验证。

## 2. 环境隔离与已确认缺口

| 通道 | 当前配置/行为 | 问题及门禁 |
| --- | --- | --- |
| 开发数据库 | novel_forge，用户提供连接 | 禁止测试清库与测试写入 |
| PHPUnit数据库 | phpunit.xml强制pgsql和novel_forge_test；TestCase只检查配置字段 | F01：DB_URL可覆盖实际解析目标，当前守卫不充分 |
| Redis | .env与.env.testing均为novelforge_，DB0/cache DB1 | F02：普通PHPUnit用sync/array，但显式Redis集成测试未隔离，不能直接照搬开发探针 |
| 文件/缓存 | .env.testing仍配置file cache和file session；phpunit覆盖array | F03：单独 --env=testing 的CLI不等于PHPUnit隔离；需要独立产物、缓存、session、export根目录 |
| 账号 | 连接配置为postgres | F04：尚未达PRD最小权限目标；归NF-A02，不在本轮改动 |
| 中文扩展 | 未就绪，测试skip | F05：归NF-A03；不阻塞文档设计，但不接受分词专属验收；按CR001不再阻塞当前核心检索或MVP/P0验收 |
| 阶段性测试 | 断言novels不存在及/admin/novels为404 | F06：只在当前基础阶段有效；NF-B01获准后用真实业务权限/隔离断言替换 |

### F01 复现与限界

运行两个独立PHP进程，只调用测试createApplication及连接配置解析，不执行test setup、迁移或数据库连接：

- DB_DATABASE=not_allowed、空DB_URL：得到 `WRONG_CONFIGURED_DATABASE_BLOCKED_BEFORE_TEST_SETUP`，现有配置名保护有效。
- DB_DATABASE=novel_forge_test，但DB_URL为虚构的 `postgresql://probe:dummy@127.0.0.1:1/not_allowed`：测试引导通过，连接对象解析得到database=not_allowed。没有建立PDO连接。这证明仅检查原始配置名不能保证实际目标安全。

后续修复契约（本轮不实现）：在任何RefreshDatabase/迁移前检查允许连接类型、解析后的连接配置和实际只读 `current_database()`；数据库/host/port需匹配批准测试环境，DB_URL与DB_DATABASE不一致时拒绝。连不上也fail closed。单独测试CLI同样需要预检。不得通过连接开发库后尝试迁移来验证守卫。

## 3. 本次可复用检查步骤

执行环境变量固定：APP_ENV=testing、DB_CONNECTION=pgsql、DB_DATABASE=novel_forge_test、DB_URL为空。先启动Laravel并只读核对current_database()==novel_forge_test，再执行 `php artisan test`；本轮已按此顺序执行。为避免缓存配置覆盖，未来执行前还需检查缓存配置及有效连接。不能将这些命令原样套到未知部署环境。

另执行：`vendor/bin/pint --test`、`composer check-platform-reqs`、`php artisan route:list --path=admin`、`php artisan novelforge:doctor`、登录页HTTP状态检查。诊断中文缺失属于明确未就绪，不吞掉失败状态。

## 4. 基础回归与断言过渡

| 门禁 | 当前 | 后续实施要求 |
| --- | --- | --- |
| G-ENV 实际测试目标拒绝错误库 | 部分满足；F01未修复 | 在业务迁移/Redis集成测试前修复并补正反例；A01修复子项另行授权 |
| G-ACCESS 未登录与非管理员拒绝 | 现有测试通过 | 永久保留；新正文/导出/API补端到端覆盖 |
| G-SINGLE 单管理员唯一约束 | 现有测试通过 | 不以后台按钮隐藏替代数据库约束 |
| G-EXT PG18/vector/trgm | 当前通过 | schema变更继续用真实PG；中文能力单列 |
| G-NOBIZ 业务表不存在 | 当前通过 | NF-B01时仅替换这一阶段断言；保留鉴权、跨书、幂等检查 |
| G-IO HTTP/队列/私有文件隔离 | 不完整 | Fake Provider拒绝意外网络；Redis前缀、队列名、存储根目录逐批隔离 |
| G-RELEASE 业务成功 | 未实施 | 不能从基础回归推导业务通过 |

## 5. 核查结论

NF-A01的核查材料完成，严格验收状态为 **audit_complete_with_findings**。错误库保护的完整通过条件尚未满足，不标记accepted。F01–F03在业务实现获准前必须明确修复批次；本轮不因发现问题擅自修改测试或配置。剩余文档设计可继续，不执行服务器扩展安装。


## 后续修复更新（2026-10-22）

用户随后授权修复 F01–F03。已完成 URL 门禁与测试 Redis/队列/文件隔离，最终完整测试 27 通过、1 中文项跳过、72 断言；详见 [隔离修复验证](A01_ISOLATION_FIX.md)。以上原始核查记录保留为修复前证据，不代表当前仍有同一缺陷。
