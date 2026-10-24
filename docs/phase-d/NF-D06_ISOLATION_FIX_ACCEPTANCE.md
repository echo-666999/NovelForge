# NF-D06 隔离启动输出修复验收

日期：2026-10-24。范围仅为剩余的隔离启动输出断言失败、关联保护测试及验收记录。保留先前 D06 改动，不推进其他业务任务、不调用真实模型、不处理中文分词。本轮无数据库迁移、开发库数据或凭据修改。

## 已确认原因

原门禁在 `LoadConfiguration` 执行前发现配置缓存后抛出 RuntimeException，正确阻止了缓存载入，但当时容器还没有 config：

1. Laravel 的异常报告器调用日志器，日志器读取 config，再抛出 `Target class [config] does not exist`，遮住原拒绝原因。原消息只残留于堆栈参数，受 `zend.exception_string_param_max_len` 截短影响。原独立失败结果保留在 `NF-D06_ISOLATION_REPRODUCTION.json`。
2. 只跳过日志报告仍不足：`Application::handleCommand` 随后调用 Console Kernel 的 terminate，框架在那里读取 `app.timezone`，再次因没有 config 失败，进程变为255。修复中间验证确实出现该路径，未将这一轮记为通过。

已核实本机安装版本为 Laravel v13.38.0。原因核对基于该版本框架源码：`Handler::reportThrowable`、`Console\Kernel::terminate`、`Application::handleCommand` 和 `LoadConfiguration::bootstrap`，没有修改 vendor。

## 修复与边界

- 新增中文注释的 `TestIsolationBootstrapException`，继承 RuntimeException 并实现框架 ShouldntReport。仅供配置载入前的缓存/存储隔离拒绝使用，避免进入依赖 config 的日志器；框架仍输出完整命令行拒绝消息。
- 新增应用 Console Kernel 并在 bootstrap 绑定，同时显式保留框架原有自动命令发现（控制台路由与 Commands 目录）。仅在 testing.isolation.requested=true 且 config 尚未建立时跳过依赖配置的命令收尾；其他路径调用框架原收尾。没有安装兜底配置、载入缓存、继续运行命令或将失败转换为成功。
- 原缓存拒绝、测试存储符号链接拒绝、测试库白名单、URL/读写副本/PDO选项限制、环境冲突、资源重定向防护及数据库实连确认均保留。配置载入后的异常仍沿用原报告路径。
- 缓存探针改用唯一文件，并在其中加入写标记副作用；拒绝后标记必须不存在，证明缓存 PHP 没有执行。三种入口分别验证：APP_ENV=local 配合 `--env testing`、`--env=testing`，以及单独 APP_ENV=testing。
- 每种入口均在堆栈参数长度为0时要求：退出码精确为1、完整拒绝提示、无缓存执行标记、无 config 二次异常、无 Fatal error。没有放宽原断言。
- 新增正常命令收尾测试，验证成功退出及 terminating 回调实际执行；新增临时空目录符号链接拒绝测试，验证失败退出、原提示和目标目录未写入，不移动真实 storage。

## 验证结果

- 缓存拒绝定向1项通过、18断言、0.328秒，见 `NF-D06_ISOLATION_FIX_TARGET_RESULTS.json`。
- 隔离单元及集成专项21项全部通过、76断言、1.312秒，见 `NF-D06_ISOLATION_FIX_TEST_RESULTS.json`。包括实际连接 novel_forge_test、数据库 URL 绕过拒绝、错误凭据不泄漏、环境冲突、存储/文件缓存隔离、资源设置不可重定向，以及专用 Redis 和显式队列任务。
- 4个本轮涉及PHP文件Pint检查及 `git diff --check` 通过；非测试环境 `php artisan list --raw` 退出0，未修改开发配置。
- 首轮完整回归297项：295通过、1错误、1跳过，3071断言、379.125秒，见 `NF-D06_ISOLATION_FIX_FIRST_FULL_RESULTS.json`。隔离输出测试通过，但 Outbox 重启恢复发现 `novelforge:dispatch` 未注册。已核实框架 shouldDiscoverCommands 默认仅对框架原类返回true；自定义 Kernel 必须显式保留自动发现。已修复并在正常命令测试增加 dispatch（控制台路由）和 doctor（Commands目录）两条注册断言，没有新增业务命令。
- 命令发现修复后的最终定向24项全部通过、111断言、18.286秒，见 `NF-D06_ISOLATION_FIX_FINAL_TARGET_RESULTS.json`，涵盖完整隔离专项和 OutboxRecoveryTest 的专用Redis重启、数据库恢复及独立Worker消费。
- 最终完整回归297项：296通过、1跳过、0失败，3080断言、388.630秒，见 `NF-D06_ISOLATION_FIX_FULL_RESULTS.json`。原隔离启动输出失败与命令发现兼容错误均已关闭；首轮失败证据继续保留。

完整回归限定 PostgreSQL `novel_forge_test`，HTTP 外发由测试环境阻断；测试 Redis 使用项目专用 Unix socket，收尾关闭。本轮不执行新的开发数据库迁移或备份恢复。
