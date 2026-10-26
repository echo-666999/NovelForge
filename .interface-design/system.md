# NovelForge Interface Design 决策入口

用户于2026-10-26选择 Interface Design 为主 UI skill。

唯一项目视觉规范：[DESIGN.md](../DESIGN.md)。整改范围和页面矩阵：[docs/ui/PLAN.md](../docs/ui/PLAN.md)。修改 UI 前先读取两者，不维护重复 token 清单。

方向：适中密度的中文小说编辑工作台，纸白、墨色、书签靛蓝，轻边界；复用 Filament 5.x。

特征：小说/章上下文、候选/正式身份、证据来源。用于工作台、正文、事件提取、评审、记忆。

固定五组导航；JSON 存储采用类型化字段编辑；正式历史逐字段只读；所有写入经过现有应用服务。

目标规范已记录，当前旧页面不视为已符合。新增 NF-UI01–08 跟踪，不改既有任务状态。未开启真实模型、中文分词或新业务。


NF-UI02已接入：后台独立主题资源位于 resources/css/filament/admin/theme.css；公共外壳为 x-nf.section/form-grid/field/input/table/state。参数与用法只在 DESIGN.md 维护，验收见 docs/ui/NF-UI02/ACCEPTANCE.md；没有完成导航和JSON字段改造。


NF-UI03已接入：五组排序、统一授权书级上下文、实际正式基线、白名单GET切书与用户菜单关于入口。组件使用规则仍只在DESIGN.md维护；证据见docs/ui/NF-UI03/ACCEPTANCE.md。任务页附属书内入口默认收起，StoryHub默认展开；未完成JSON字段改造或全页验收。


NF-UI04已接入：配置原生字段、语义Schema/惰性类型树、扁平签名字段映射、逐字段只读比较与安全POST。用法仍只在DESIGN.md维护；证据docs/ui/NF-UI04。完整对象语义修复另列NF-UI11，其他页面和全页验收未完成。


NF-UI05已接入：总览列表/状态与候选五类字段样板，正文来源、诊断定位、历史/流水线分层和有限范围分页。组件约定仍只维护于DESIGN.md；证据docs/ui/NF-UI05。完整历史扩展另列NF-UI12，未推进UI06–08。
