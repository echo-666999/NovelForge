# NovelForge Interface Design 决策入口

用户于2026-10-26选择 Interface Design 为主 UI skill。

唯一项目视觉规范：[DESIGN.md](../DESIGN.md)。整改范围和页面矩阵：[docs/ui/PLAN.md](../docs/ui/PLAN.md)。修改 UI 前先读取两者，不维护重复 token 清单。

方向：适中密度的中文小说编辑工作台，纸白、墨色、书签靛蓝，轻边界；复用 Filament 5.x。

特征：小说/章上下文、候选/正式身份、证据来源。用于工作台、正文、事件提取、评审、记忆。

固定五组导航；JSON 存储采用类型化字段编辑；正式历史逐字段只读；所有写入经过现有应用服务。

目标规范已记录，当前旧页面不视为已符合。新增 NF-UI01–08 跟踪，不改既有任务状态。未开启真实模型、中文分词或新业务。


NF-UI02已接入：后台独立主题资源位于 resources/css/filament/admin/theme.css；公共外壳为 x-nf.section/form-grid/field/input/table/state。参数与用法只在 DESIGN.md 维护，验收见 docs/ui/NF-UI02/ACCEPTANCE.md；没有完成导航和JSON字段改造。
