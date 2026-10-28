# NF-E08 阅读与导出实施契约

本轮范围为正式阅读、版本比较、人工阅读记录、TXT/Markdown私有导出和冻结版本清单。显式草稿导出加“草稿”标记，不代表正式提交。没有新增生成、评审、Canonical Commit、终局或真实模型能力。

## 来源与身份

默认scope=formal，revision_id省略时捕获本书活动基线。正式来源必须同时对应canonical_chapters和committed提交；每章核对正文UTF-8字节SHA256与可见非空白码点字数。不得回退候选或另一基线。显式scope=draft使用选区各章最新候选，revision_id必须为空；同一导出不混合正式和草稿。

range.type支持chapter、range、volume、book；chapter指定from，range指定from/to，volume指定同书volume_id，book针对全书已建章节目录。整卷正式边界和卷名取正式提交引用的规划快照；缺章、空章、无效来源不导出。单次范围上限10000章，阅读页面每页连续1—20章。

导出清单冻结小说标题、章范围、基线、精确正文版本/提交、卷章标题、逐章哈希、字数、template_version=e08-text-v1、格式、选项和冻结时间。manifest_hash基于现有规范JSON算法；export_items追加对应映射并由数据库校验清单成员。构建按版本ID重读并验证，新增章、候选编辑、书名修改不改变原清单。

## HTTP

沿用同源会话/CSRF、管理员和小说所有权；拒绝越权404，非管理员403，未登录401。ID输出十进制字符串，时间带时区；写入要求Idempotency-Key。导出run_id指向独立export任务，run_type=export，不伪装为生成运行。

| 方法与路径 | 输入 / 结果 |
| --- | --- |
| GET /api/v1/novels/{novel}/reading | scope、revision_id、range；返回精确正文、目录和missing章序 |
| GET .../reading/compare | left/right同章版本ID；返回保守行区间差异，不修改正文 |
| GET .../reading/observations | page；当前作者同书25项/页 |
| POST .../reading/observations | chapter_version_id、revision_id可空、kind=read/issue、note；issue还需quote/start_offset/end_offset；201 |
| GET /api/v1/novels/{novel}/exports | format/status/from/to/page；25项/页，日期按UTC，过期为派生显示态 |
| POST /api/v1/exports | novel_id、scope、revision_id、range、format=txt/md、options；202，返回id/run_id/status_url |
| GET /api/v1/exports/{export} | 授权任务和完整冻结清单；没有私有路径 |
| GET .../download | 就绪且未到期才附件下载；UTF-8、private/no-store、nosniff，重新核对字节数与SHA256 |
| GET .../manifest | 附件下载冻结清单、manifest_hash与文件校验证据；清单不随文件过期而丢失 |
| POST .../rebuild | 按原清单新建持久任务及新文件记录；相同键返回同一新任务 |
| POST .../delete-file | 仅删除派生字节，留下文件墓碑、清单、正式历史和独立幂等记录 |

options：toc/volume_titles布尔，author≤200字符，synopsis≤4000字符，separator=blank/rule，filename≤100字符。危险文件名字符被清理；实际存储路径始终由服务端任务、清单哈希和租约令牌构造，不接收客户端路径。正文导出保留LF归一化版本的实际字节，不重新排标点或规范化Unicode。

阅读问题要求Unicode码点左闭右开引文，0≤start<end≤正文码点数，切片必须精确相等；不是UTF-8字节或UTF-16位置。已读与问题均绑定具体正文版本和基线，仅追加，不替代评审或作者成品认可。差异算法保守显示公共前后行之间的替换区间，不声称最小词级差异。

## 持久任务及恢复

export_jobs是数据库持久意图；创建仅冻结并排队，调度器每分钟调用novelforge:exports，投递BuildFrozenExport至export队列。重复投递可接受。消费者先锁任务领取120秒令牌，再构建；就绪提交重新锁任务并核对令牌、到期时间。失败记录固定错误码，不存认证信息、SQL或正文。原生Job超时60秒。

queued→building→ready/failed；到期building经补投器回到queued，旧令牌无法提交。派生文件路径包含隔离令牌，旧消费者收尾不能删除新消费者文件。文件到期/删除映射为expired；按旧清单rebuild产生新任务，原失败历史不改写。重建沿用冻结时间和模板，正文文件字节可相同；文件记录及到期时间是新的。

运行方式：

```sh
php artisan novelforge:exports --limit=100
php artisan queue:work --queue=export --tries=1 --timeout=60
```

使用实际部署配置的队列连接；调度器使用既有schedule:run/schedule:work托管。NF-A02运行服务托管仍未因此完成。导出不借用generation队列，也不启动模型。

四张新表export_jobs/export_items/export_files/reading_observations具有中文表/字段备注；任务清单、章节映射、文件校验证据及阅读记录受不可变触发器保护。任务状态/租约和文件删除墓碑是有界可变字段。审计只采集身份、状态、哈希和大小，不包含正文、阅读引文、文件路径。

## 页面与边界

连续阅读与版本比较归“创作工作台”；导出中心归“配置与运维”，二级菜单继续圆点。使用现有书级导航、公共字段/列表、正常宽度动作和Filament原生弹窗。导出默认列表，预检与编辑在弹窗；只读清单为逐字段证据。删除采用Filament原生确认。正文及差异由Blade转义显示，不解析用户HTML。

本项不覆盖EPUB/DOCX、平台发布、终局门禁、工作台生产指标接线、中文分词、真实模型和十章人工认可。UI13/UI14中的终局依赖应指向NF-G04，不能把本任务误作完结门禁。
