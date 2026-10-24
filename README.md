# NovelForge

单人 AI 长篇小说系统。已按逐项授权完成 B01–B06 规划基础、C01–C07 离线运行底座及 D01–D02 候选事件、状态回放与一致性服务；具体范围与证据见 docs/DEVELOPMENT_TASKS.md 和各阶段验收记录。生产生成尚未开放。

## 当前环境

- Laravel 13.38.0、Filament 5.10.3、Livewire 4.4.8，实际版本锁定于 composer.lock。
- 2026-10-24 当前 CLI 实测 PHP 8.5.7；Herd Web 运行时未在本轮复核。前端依赖锁定于 package-lock.json，其他初始环境记录见 docs/FOUNDATION.md。
- PostgreSQL 18.6：127.0.0.1:5432 / novel_forge，使用用户提供的连接；实际数据库服务运行于 Linux。
- 测试库：novel_forge_test。测试引导会检查目标库，禁止在业务库执行 RefreshDatabase。
- Redis 8.10.2：127.0.0.1:56379，项目独立实例、认证和 AOF；Predis 3.7.0 客户端。
- pgvector、pg_trgm 由迁移启用。中文分词情况见 docs/FOUNDATION.md。

数据库密码、APP_KEY 和 Redis 密码仅保存在被 Git 忽略的本地文件中；.env.example 不包含真实凭据。

## 本机使用

Herd 登录页：http://novelforge.test/admin/login

首次创建唯一管理员（交互输入邮箱与密码，没有内置默认密码）：

```sh
cd /Users/webb/Herd/NovelForge
php artisan novelforge:admin
```

后台包含创作总览、小说规划工作台、供应商/模型、Prompt 模板、主备路由及文风评分策略配置页。未开放注册，非管理员禁止访问；数据库唯一约束保证单管理员。密钥不回填页面，保存配置不触发真实模型请求。

PostgreSQL 使用用户已有服务，本项目不控制该服务。项目 Redis 启动命令：

```sh
./scripts/start-local-redis.sh
```

Redis 配置保存在 .local/redis.conf（本机专用、不入库）。未设置系统开机自启；重启后需重新启动。跨机器部署时须自行配置 Redis 服务及环境变量。

开发时在独立终端运行队列消费者：

```sh
php artisan queue:work redis --queue=maintenance,planning,generation,review,memory,export --timeout=60 --tries=1
```

C02 已有持久 outbox 通知与幂等消费者，当前只注册测试环境阶段。默认 retry_after=120 秒、租约90秒、worker timeout=60秒；真实模型仍关闭。更新消费者使用 `php artisan queue:restart`，完整步骤和共享缓存要求见 docs/phase-c/NF-C02_OPERATIONS.md。

调度入口 `routes/console.php` 已注册每分钟 `novelforge:dispatch` 补投与租约扫描。识别过期任务依赖调度器持续运行；本项目没有自动安装系统守护进程。

## 验证

```sh
php artisan test
php scripts/queue-probe.php
php artisan novelforge:doctor
vendor/bin/pint --test
npm run build
```

测试使用 .env.testing 和独立测试库。队列探针创建唯一的临时维护队列，执行一条无业务副作用的基础设施任务，并校验缓存确认结果。

`novelforge:doctor` 检查数据库版本、扩展、中文文本配置和 Redis；缺失任何依赖时返回非零状态，不会把未安装依赖标记为成功。

## 文件与边界

- docs/PRD-V1.1.md、docs/PRD-V1.1.docx：完整产品需求，包含未来业务范围。
- docs/FOUNDATION.md：初始基础工程的历史交付记录。
- docs/phase-c/NF-C03_ACCEPTANCE.md：供应商网关、安全配置、中文数据库备注与离线验收。
- docs/phase-c/NF-C04_ACCEPTANCE.md：Prompt/路由/文风版本、发布回归、运行冻结及主备尝试验收。
- app/Providers/Filament：后台基础配置。
- app/Console/Commands：管理员创建、依赖诊断。
- config/novelforge.php：检索配置及预留队列名称。
- database/migrations：系统表及逐项授权的规划、编排、供应商配置和请求证据表；新增 C03–D03 表及字段均有中文备注。
- scripts/queue-probe.php：真实队列基础验证。

已实现模块不能代表完整小说生产可用：正式状态提交、Canonical Commit、上下文/记忆、伏笔正式证据与长程验收、生成评审修订及正文导出仍待各自任务。C05预算门禁已离线验证，但A02部署条件、真实能力验证和外部调用授权仍未齐备，真实模型及能力探测继续关闭。前十章成品与百章伏笔验收仍未执行。

## 重新部署

安装 composer.lock 与 package-lock.json 指定依赖；复制 .env.example 为 .env 并填写本机数据库/Redis 凭据；生成 APP_KEY；准备 PostgreSQL 18 和所需扩展后执行 `php artisan migrate`、`npm run build`、`php artisan novelforge:admin`。部署目录必须指向 public，持久目录为 storage，禁止公开 .env、.local 和私有日志。生产环境关闭 APP_DEBUG，启用 HTTPS 并配置安全 Cookie。

NF-C05 价格版本、Token计量、原子预算、对账及费用导出：docs/phase-c/NF-C05_ACCEPTANCE.md。

NF-C06 恢复台：`/admin/recovery-desk`，支持恢复预览、暂停/取消及人工决定。生产生成入口仍关闭；事件提取/配置变更登记不等于业务执行完成。验证边界见 [NF-C06 验收](docs/phase-c/NF-C06_ACCEPTANCE.md)。

NF-C07：`/admin/run-center` 查看运行、请求、心跳、产物与账本；`/admin/audit-center` 查看追加审计与数据库健康。完整证据下载需鉴权。详见 [NF-C07验收](docs/phase-c/NF-C07_ACCEPTANCE.md)。

NF-D01：已提供候选事件、Unicode 证据与可回放状态内部服务；无新增页面或 HTTP 入口，正式事实写入仍关闭。范围及验收见 [NF-D01](docs/phase-d/NF-D01_ACCEPTANCE.md)。

NF-D02：`/admin/story-timeline` 管理候选双时间轴、知识来源、动态关系和一致性报告；可查看某章 POV。未知与冲突均阻止候选知识作为可用信息，正式提交继续关闭。详见 [NF-D02验收](docs/phase-d/NF-D02_ACCEPTANCE.md)。

NF-D03：`/admin/foreshadow-ledger` 管理不可变伏笔计划、冻结、改期/放弃候选与作者决定，并查看蓝图主支线承诺。章位提示是作者预览，正式埋设/回收证据写入仍关闭。详见 [NF-D03验收](docs/phase-d/NF-D03_ACCEPTANCE.md)。
