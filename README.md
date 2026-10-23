# NovelForge

单人 AI 长篇小说系统的基础工程。当前范围仅为基础架构，尚未进行具体业务开发。

## 当前环境

- Laravel 13.38.0、Filament 5.10.3、Livewire 4.4.8，实际版本锁定于 composer.lock。
- PHP 8.5.12；Node.js 22.23.3；前端依赖锁定于 package-lock.json。
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

后台仅提供登录、默认仪表盘和基础实施状态页。未开放注册；非管理员禁止访问。新管理员操作采用数据库唯一约束保证单管理员。

PostgreSQL 使用用户已有服务，本项目不控制该服务。项目 Redis 启动命令：

```sh
./scripts/start-local-redis.sh
```

Redis 配置保存在 .local/redis.conf（本机专用、不入库）。未设置系统开机自启；重启后需重新启动。跨机器部署时须自行配置 Redis 服务及环境变量。

开发时在独立终端运行队列消费者：

```sh
php artisan queue:work redis --queue=maintenance,planning,generation,review,memory,export --timeout=60 --tries=1
```

当前没有业务队列任务。以上队列名称仅为 PRD 预留。默认 retry_after=90 秒大于 worker timeout=60 秒；未来模型调用接入前须按实际最长执行时间同时配置。发布代码后运行 `php artisan queue:restart`。

调度入口遵循 Laravel `routes/console.php`；当前未注册业务定时任务。部署时可配置每分钟 `php artisan schedule:run`，本次未修改系统计划任务。

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
- docs/FOUNDATION.md：本轮实际交付及验证记录。
- app/Providers/Filament：后台基础配置。
- app/Console/Commands：管理员创建、依赖诊断。
- config/novelforge.php：检索配置及预留队列名称。
- database/migrations：Laravel 系统表、管理员权限及数据库扩展；无小说业务表。
- scripts/queue-probe.php：真实队列基础验证。

小说规划、状态引擎、Canonical Commit、上下文与记忆、伏笔、生成评审修订、模型与 Prompt 管理、费用、导出、API 等均未实现。没有接入任何模型密钥或产生模型调用费用。PRD 中的十章成品、百章伏笔验收不属于本轮基础架构验收。

## 重新部署

安装 composer.lock 与 package-lock.json 指定依赖；复制 .env.example 为 .env 并填写本机数据库/Redis 凭据；生成 APP_KEY；准备 PostgreSQL 18 和所需扩展后执行 `php artisan migrate`、`npm run build`、`php artisan novelforge:admin`。部署目录必须指向 public，持久目录为 storage，禁止公开 .env、.local 和私有日志。生产环境关闭 APP_DEBUG，启用 HTTPS 并配置安全 Cookie。
