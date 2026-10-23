# NF-A01 测试隔离修复验证

日期：2026-10-22。范围：F01–F03 测试基础设施修复；不开发业务，不安装或调整中文分词。原始发现保留在 A01_BASELINE.md。

## 结果

F01–F03 本轮修复通过。`php artisan test`：28 项，27 通过、1 跳过、72 断言，最终退出码 0。唯一跳过是实际服务器尚无中文分词配置，按 CR001 保持后期最低优先级。本轮真实 Redis 集成用例已运行，未跳过。

对本次五个 PHP 变更文件运行 `vendor/bin/pint --test`：通过。本轮不声称全仓格式检查通过；曾误用 --dirty 扫到无关 CreateAdmin 格式改动，已按修改前 SHA256 精确恢复。

## 隔离实现

- PHPUnit 强制 APP_ENV=testing；取消 DB_URL 的空值遮盖，由正式门禁判断 URL 是否安全。
- bootstrap 在配置加载前识别 APP_ENV 和两种 --env 参数写法；拒绝测试使用缓存配置、拒绝命令行与配置环境冲突。在加载配置后、服务注册前执行资源隔离。
- 使用当前安装 Laravel 的 ConfigurationUrlParser 解析 URL，包括查询参数覆盖。只批准 pgsql、127.0.0.1:5432、novel_forge_test；错误库、主机、端口、驱动、读写分离和 PDO options 被拒绝。移除其他数据库连接及 URL，仅保留批准的连接参数。
- AppServiceProvider 在迁移及测试 setup 前只读核对 current_database()。连接不可验证或库名不符立即失败；异常消息不输出凭据。测试库必须已由运维预先建立，守卫不创建数据库。
- 测试 Redis 固定 Predis + 项目 `.local/redis-testing.sock`，独立 Redis 进程，不监听 TCP。使用 0700 socket 权限、独立工作目录、不持久化。开发 Redis 的 TCP 地址、密码、DB0/1、REDIS_URL 不进入有效测试配置。隔离依赖独立进程而非仅靠前缀，因此测试实例的清库不指向开发实例。
- 默认 array 缓存、array session、sync 队列。显式 redis 缓存/队列也只能走测试 socket；database 队列、失败任务、批次及数据库缓存固定 pgsql 测试连接。只开放 sync/redis/database 队列及 array/file/redis/database 缓存。
- Storage 的 local/public、文件缓存、session 文件目录、编译视图、日志统一在 storage/testing；拒绝关键目录为符号链接。默认 local，移除 S3 配置与公开 storage 链接。测试目录已加入忽略清单。邮件默认 array。
- `.env.testing` 中旧资源变量不再决定有效资源路径；统一安全覆盖在 TestIsolation 中。开发 `.env` SHA256 前后相同，未修改连接信息。

## 正反例证据

| 用例 | 结果 |
| --- | --- |
| 普通批准配置与批准 URL | 允许；解析结果固定测试库，URL 被剥离 |
| 错误数据库、URL 路径或查询参数换库 | 拒绝，纯解析单元测试不打开数据库 |
| URL 换主机/端口、其他驱动、读写分离、PDO options | 拒绝 |
| 独立 artisan 子进程携带恶意 URL | 在连接前拒绝，无 SQLSTATE 或假密码输出 |
| 无法认证的测试库连接 | fail closed，输出不含假密码；只尝试批准测试库 |
| 缓存配置文件包含抛错探针 | 在加载缓存前拒绝，探针未执行；临时文件已删除 |
| --env=testing 与 APP_ENV=local 冲突 | 拒绝；发现并修正了 Laravel 环境检测与配置值不一致的边界 |
| 外部 REDIS_URL / SQS / S3 / Redis session / 编译路径覆盖 | 子进程启动后仍为安全测试配置 |
| 实际 PostgreSQL current_database() | novel_forge_test |
| 两种磁盘文件写读删、文件缓存写读删 | 位于测试路径；开发磁盘对应路径不存在测试文件 |
| Redis 真实读写、Redis cache、显式自定义队列入队/消费 | 通过；worker 执行 InfrastructureProbe 后收到 ack，队列清空 |
| 原有权限、唯一管理员、PG18/vector/trgm、基础页面 | 完整回归继续通过 |

中间曾有环境冲突反例失败，修正后完整重跑得到上述最终结果；不以早期通过结果覆盖失败过程。

## 复跑

在项目目录，一个终端运行：

```sh
sh scripts/start-testing-redis.sh
```

另一个终端运行：

```sh
php artisan test
vendor/bin/pint --test app/Support/TestIsolation.php app/Providers/AppServiceProvider.php bootstrap/app.php tests/Unit/TestIsolationTest.php tests/Feature/TestIsolationTest.php
```

停止独立测试服务：

```sh
redis-cli -s "$PWD/.local/redis-testing.sock" shutdown nosave
```

测试 Redis 未启动时，该项集成测试明确 skip，不得报告 Redis 验证通过。测试启动脚本要求 redis-server 在 PATH；已有 socket 时拒绝重复启动，需先确认归属再处理残留。普通 artisan 测试环境入口使用 `--env=testing`，勿通过普通开发环境命令运行测试迁移。配置缓存存在时门禁拒绝，不自动删除开发缓存。

## 边界与待决事项

- 当前仅支持本机批准的测试库地址和单进程串行运行。未承诺 Laravel parallel testing、多个测试运行并发、远程 CI 或 Windows Unix socket 支持；未来变更需要显式设计并补测试。
- 这是一套防误配置措施，不是恶意 PHP 代码沙箱；测试代码若主动覆写配置、直接创建 PDO 或绕过 Storage 使用系统路径，不受此门禁完整约束。未来模块必须沿用受控连接和 storage_path。
- PostgreSQL 仍为已有 postgres 凭据。最小权限账号、正式部署和运维托管归 NF-A02，尚未实施；本轮没有对开发库执行迁移或业务写入。
- 未运行真实模型、业务验收、性能或备份恢复测试。当前业务表不存在的阶段断言保留。
- NF-A01 的 F01–F03 可标记修复通过；不代表 NF-A02、业务开发或中文分词验收完成。本轮没有需要用户批准的遗留修复动作。
