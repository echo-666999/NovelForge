# NF-A02 首次隔离准备验收记录（历史阶段）

后续用户已授权并完成开发账户切换与长期服务启用，当前结果见[NF-A02运行落地验收](NF-A02_RUNTIME_ACCEPTANCE.md)。以下保留首次隔离准备阶段的事实，不能用其中的“尚未切换”描述当前运行状态。

日期：2026-10-29（Asia/Shanghai）。状态：`implemented_core_verified`，表示本机隔离部署准备通过，不表示当前开发运行账户已切换或正式上线完成。

## 已确认的选择与交付

用户选择当前 Mac/Herd 为运行目标，本机维护入口为当前用户终端与 launchd 会话；临时备份 `.local/backups/nf-a02`，保留7天，正式目的地待定。

- `scripts/deployment/bootstrap-postgres.sql`：仅在标记为 `novelforge-private` 的PG18专用实例与允许库名执行；角色已存在直接失败。管理员预装扩展，迁移/应用账户分离，默认表/序列/函数权限受控。
- `grant-runtime.sql`、`pg_hba.conf.example`：迁移后撤销应用迁移记录权限；按角色拒绝跨库与TCP连接。没有修改共享实例、其他数据库或开发凭据。
- `prepare-private-dirs.py`：实际将项目私有产物、日志、服务与临时备份目录设为0700；环境文件保持0600，内容未改。
- `render-launchd.py`：两类 Worker 与一个调度器的当前用户模板，含明确PATH、KeepAlive、退出等待与私有日志路径。输出文件均经plutil检查。本轮只临时托管测试标签，没有安装长期开发/生产服务。
- 三个验收工具加PHP测试探针：真实PostgreSQL、Redis、launchd、备份恢复；新增代码均有中文注释。无新增业务迁移；测试临时表、所有新字段与角色都有中文备注。

## 实测结果

| 项目 | 实际结果与边界 |
| --- | --- |
| PostgreSQL | 本机18.6；随机私有目录，Unix socket，无TCP；唯一业务测试库`novel_forge_test` |
| 账户权限 | 16项检查全部通过；包含迁移建表/增列、中文备注、应用CRUD/序列、真实users/novels表CRUD、vector/pg_trgm使用 |
| 拒绝项 | 应用安装扩展、建表、ALTER、TRUNCATE、伪造迁移、SET ROLE、建库、临时表均拒绝；应用及迁移账户连接postgres/template1均被HBA拒绝 |
| 现有完整结构 | 沿用项目testing守卫只读导出当前测试库结构；受限迁移账户完整导入，含现有索引/函数/触发器；不等于逐条PHP迁移和存量切换验收 |
| PostgreSQL重启 | 仅重启本工具创建的专用实例；重启后权限与中文测试内容可读 |
| 备份恢复 | custom快照，删除测试表后由迁移账户恢复，应用账户逐字读取原内容；目录0700、文件0600；仅人工夹具，没有真实密钥解密验收 |
| launchd | 3项通过：真实Redis探针消费；queue:restart后新PID继续消费；调度器新PID及三个分钟命令均DONE |
| 既有回归 | OutboxRecoveryTest、Feature/TestIsolationTest、Unit/TestIsolationTest：24通过、111断言，0失败/错误/跳过；512M内存，未跑全量业务套件 |
| 静态验证 | 两份PHP工具Pint通过；3份launchd plist格式通过；git diff空白检查通过 |

命令及适用前提见[运行手册](NF-A02_OPERATIONS.md)。机器可读摘要见[NF-A02_VERIFICATION.json](NF-A02_VERIFICATION.json)；JUnit、私有备份与完整本机诊断留在`.local`，不提交仓库。

首轮launchd测试未完成：默认PATH不包含Redis工具目录，测试Worker退出、探针等待超时。已在生成器中明确工具路径；最终重新验收全部通过。该失败没有被记作成功，私有诊断保留供复核。

## 尚未验收与下一步

1. 当前开发环境仍使用原数据库连接。本轮测试角色随专用测试实例销毁；初始化脚本可重复建立隔离环境，但不等于已存在长期运行角色。正式切换需先建立项目专用运行实例、私有凭据并完成实际迁移/数据副本验收，再统一切换Web、Worker与调度器。
2. 本轮未安装长期PostgreSQL/Redis/Worker/调度服务。用户会话LaunchAgent也不覆盖注销、休眠和无人登录开机；需要明确持续运行策略后才能承诺恢复时限。
3. 临时同盘备份只验证写入、权限和恢复内容；7天清理发生在下次验收运行时，不是每日自动备份。正式备份目的地、加密/异盘策略、APP_KEY及供应商密钥解密恢复、RPO/RTO留待H04。
4. 未调用真实模型、未处理中文分词、未推进F01或UI31/UI32。A02隔离结果不能解除真实模型/预算/正式部署门禁。
