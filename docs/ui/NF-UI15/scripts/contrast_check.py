# 离线计算浏览器采集到的sRGB文字对比度；不访问浏览器或改动图片。
import argparse,json,re
from pathlib import Path

def rgba(text):
    """只接受实际采集的RGB色；未知颜色不能冒充通过。"""
    m=re.fullmatch(r'rgba?\(([^)]+)\)',text)
    if not m:return None
    v=[float(x) for x in re.split(r'[\s,/]+',m[1]) if x]
    return v[:3]+[v[3] if len(v)>3 else 1]

def blend(front,back):
    return [front[i]*front[3]+back[i]*(1-front[3]) for i in range(3)]+[1]

def luminance(c):
    v=[x/255 for x in c[:3]]
    v=[x/12.92 if x<=.04045 else ((x+.055)/1.055)**2.4 for x in v]
    return sum(a*b for a,b in zip(v,[.2126,.7152,.0722]))

def measure(row):
    """从外至内合成祖先纯色背景，图片/整体透明度场景保留未认证。"""
    bg=[255,255,255,1]
    for layer in reversed(row['chain']):
        color=rgba(layer['background'])
        if color is None or float(layer['opacity'])<1 or layer['image']!='none':return {'measured':False}
        bg=blend(color,bg)
    fg=rgba(row['color'])
    if fg is None:return {'measured':False}
    fg=blend(fg,bg);a,b=luminance(fg),luminance(bg);ratio=(max(a,b)+.05)/(min(a,b)+.05)
    return {'measured':True,'foreground':fg[:3],'background':bg[:3],'ratio':round(ratio,3),'required':4.5,'pass':ratio>=4.5}

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--input',default=str(Path(__file__).resolve().parents[1]/'BROWSER.json'));args=parser.parse_args()
    data=json.loads(Path(args.input).read_text());group=next(x for x in data if x['case']=='pagination_contrast_raw')
    result=[{'theme':case['theme'],'width':case['width'],'text':row['text'],**measure(row)} for case in group['rows'] for row in case['rows']]
    labels=[x for x in result if x['text']=='每页']
    assert len(labels)==2 and {x['theme'] for x in labels}=={'light','dark'}
    assert all(x.get('pass') for x in labels),labels
    print(json.dumps(result,ensure_ascii=False,indent=2))
