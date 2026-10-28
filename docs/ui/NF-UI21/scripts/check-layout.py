"""核对保存的浏览器几何证据，不进行网络或数据库操作，不代替真实浏览器验收。"""
from pathlib import Path
import json

root = Path(__file__).resolve().parents[1]
counts = {}
for name in ("final-1440.json", "final-390.json"):
    pages = json.loads((root / name).read_text())
    assert len(pages) == 31
    total = 0
    for page in pages:
        assert page["title"] and page["scroll"] == page["width"], page["url"]
        for group in page["groups"]:
            expected_gap = "16px" if group["class"] in ("nf-form-grid", "nf-filter-grid") else "12px"
            assert group["gap"] == expected_gap, (page["title"], group["class"])
            for index, item in enumerate(group["items"]):
                total += 1
                if item["tag"] in ("BUTTON", "INPUT", "SELECT", "A", "DIV"):
                    assert abs(item["height"] - 40) < 0.1, (page["title"], item)
                # 同一视觉行比中心线；不同换行和多行正文不硬压成相同高度。
                for other in group["items"][index + 1:]:
                    if item["height"] <= 41 and other["height"] <= 41 and abs(item["y"] - other["y"]) < 20:
                        assert abs(item["center"] - other["center"]) < 0.5, (page["title"], item, other)
    assert total == 239
    counts[name] = {"pages": len(pages), "elements": total}

details = {item["case"]: item for item in json.loads((root / "details.json").read_text())}
for name in ("host_final_1440", "host_final_390"):
    spacing = details[name]["spacing"]
    assert spacing["rowHeight"] == 40 and spacing["nextButtonGap"] == 12 and spacing["columnGap"] == "12px"
assert details["host_removed"]["remaining"] == 1 and details["host_removed"]["disabled"]
assert details["workspace_checkbox"]["checked"]
keyboard = details["overview_keyboard_settled"]
assert keyboard["escapeFocus"] == "overview-status" and keyboard["expanded"] == "false" and keyboard["tabFocus"] == "overview-from"
state = details["prompt_rejected_1440"]["state"]
assert state["key"] == "ui21.layout" and state["property"] == "" and state["alerts"]
assert all(json.loads((root / "browser-cleanup.json").read_text()).values())
print(json.dumps({"result": "passed", "measurements": counts}, ensure_ascii=False))
