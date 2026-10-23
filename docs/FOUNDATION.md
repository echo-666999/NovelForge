# 基础架构交付记录

日期：2026-10-22。范围：按用户最后要求，只搭建基础架构，不开发具体业务。

## 已交付

- 在 /Users/webb/Herd/NovelForge 创建独立 Laravel 13 工程，Composer 和 npm 锁文件已生成。
- Filament 5 中文后台登录、默认仪表盘与实施状态页；关闭公开注册；单管理员权限模型、交互创建命令及数据库唯一约束。
- 开发库 novel_forge、隔离测试库 novel_forge_test，均新建于用户指定 PostgreSQL 连接。未修改其他项目数据库。
- Laravel 系统表及后台权限迁移。曾创建的业务代码和空业务表已按用户收窄范围撤回；当前不存在小说业务模块。
- pgvector 0.8.1、pg_trgm 1.6 在开发库已启用；相关操作可通过迁移复现。
- Redis 8.10.2 项目独立实例，127.0.0.1:56379，认证、AOF、专属 key 前缀；Predis 3.7.0。消费者按需启动，没有设置系统自启。
- 真实队列基础探针、数据库/扩展/Redis 诊断命令、测试数据库防误用检查。
- 完整 PRD V1.1 的 Markdown、DOCX 与项目使用说明。

## 已验证与未验证

| 检查 | 结果 |
| --- | --- |
| Composer 依赖安装 | Laravel 13.38.0 / Filament 5.10.3 安装成功 |
| 基础自动测试 | 9 项：8 通过、1 中文分词测试跳过；18 条断言 |
| 后台权限与页面 | 未登录重定向、非管理员禁止访问、管理员页面响应通过 |
| 实际 Herd 登录页 | HTTP 200；没有进行人工浏览器登录验收 |
| Redis 队列 | 真实入队、worker 执行及缓存确认通过 |
| 前端构建 | npm run build 通过；有可选字体回退优化依赖提示，不阻塞构建 |
| 开发数据库表 | 只有 Laravel 系统表，无小说业务表 |
| 中文分词 | 实际服务器未安装，doctor 如实返回不完整状态 |
| 业务验收 | 未执行，当前范围不含业务实现 |

## 中文分词的实际部署缺口

用户提供的数据库实际报告为 PostgreSQL 18.6 / aarch64-unknown-linux-gnu，扩展库目录 `/www/server/pgsql/lib`，共享目录 `/www/server/pgsql/share`。本机 Homebrew PostgreSQL 的扩展目录与该服务不同。

已验证：在本机 macOS 上编译并安装 pg_jieba 1.1.1 成功，源码固定为 d0ffac8028328b2566a889ff4db3d74ba63d1b42；但这不能证明它可在目标 Linux 服务加载。目标数据库 `pg_available_extensions` 不含 pg_jieba 或 zhparser，因此没有强行启用或伪造中文检索成功结果。

需要后续在实际 Linux 服务器安装并验证选定扩展。安装流程以源码文档及服务器实际 pg_config 路径为准：[pg_jieba 官方源码](https://github.com/jaiminpan/pg_jieba)、[zhparser 官方源码](https://github.com/amutu/zhparser)。不应将 macOS 二进制复制到 Linux。

项目默认选择 pg_jieba。启用脚本位于 `database/migrations/search/`，不在默认迁移中；待服务器安装扩展后分别执行：

```sh
php artisan migrate --path=database/migrations/search
php artisan migrate --env=testing --path=database/migrations/search
php artisan novelforge:doctor
```

该迁移建立 public.novelforge_zh 文本配置。若首次部署选择 zhparser，需在执行前设置 NOVELFORGE_SEARCH_DRIVER=zhparser；不能仅修改环境变量就认为已有配置完成切换。当前没有业务索引，未来有业务数据时必须按 PRD 完成重建与切换验证。

注意：RefreshDatabase 会重建默认迁移。中文分词专项验收需先在测试环境准备可用扩展及文本配置，普通基础测试在缺失时显式跳过。服务器扩展上线时应把检索迁移纳入正式测试迁移流程，再取消跳过。

## 本机依赖与运行影响

本轮安装了 Homebrew Redis 与 CMake，并将 pg_jieba 安装到本机 Homebrew PostgreSQL 扩展目录；这些不代表远端数据库已安装扩展。最初临时建立的独立本机 PostgreSQL 实例已停止，临时数据目录已移除，应用最终只使用用户指定连接。

未创建默认管理员账号、未设置默认密码、未接入任何 AI 密钥。首次登录前执行 `php artisan novelforge:admin`。

## 后续边界

本轮不推进 PRD 的任何业务实施阶段。小说结构、状态引擎、Canonical Commit、生成评审修订、长期记忆、伏笔、Token 成本和导出等均保留在 PRD 中，等待明确的新任务。
