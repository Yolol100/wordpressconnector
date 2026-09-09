#!/usr/bin/env python3
import json,os,re,subprocess,tempfile
SITE=os.environ.get('SITE_URL','').rstrip('/')
if SITE!='https://doctorcura.com': raise RuntimeError('DoctorCura environment mismatch')
def fetch(path):
    with tempfile.TemporaryDirectory(prefix='dc-depth-') as td:
        out=os.path.join(td,'body'); hdr=os.path.join(td,'hdr')
        cmd=['curl','--silent','--show-error','--location','--compressed','--connect-timeout','10','--max-time','40','--user-agent','Mozilla/5.0 (compatible; DoctorCuraPaginationDepthAudit/1.0)','--dump-header',hdr,'--output',out,'--write-out','%{http_code}\t%{url_effective}\t%{num_redirects}',SITE+path]
        r=subprocess.run(cmd,capture_output=True,text=True,timeout=50)
        if r.returncode: raise RuntimeError(r.stderr.strip() or f'curl {r.returncode}')
        parts=r.stdout.strip().split('\t'); html=open(out,'rb').read().decode('utf-8','ignore')
        return int(parts[0]),parts[1],int(parts[2]),html
def post_ids(html):
    ids=[]
    patterns=[r'<article[^>]+class=["\'][^"\']*elementor-post[^"\']*["\'][^>]+(?:id=["\']post-(\d+)["\'])?',r'class=["\'][^"\']*elementor-post[^"\']*["\'][^>]+data-id=["\'](\d+)["\']']
    for m in re.finditer(r'<article\b[^>]*class=["\'][^"\']*elementor-post[^"\']*["\'][^>]*>',html,re.I|re.S):
        tag=m.group(0); x=re.search(r'id=["\']post-(\d+)["\']',tag,re.I) or re.search(r'data-id=["\'](\d+)["\']',tag,re.I)
        if x and x.group(1) not in ids: ids.append(x.group(1))
    if ids: return ids
    for x in re.findall(r'\bpost-(\d+)\b',html,re.I):
        if x not in ids: ids.append(x)
    return ids
def main():
    rows=[]; seen_nonempty=False; empty_streak=0
    for n in range(1,41):
        path='/blogs/' if n==1 else f'/blogs/page/{n}/'
        status,final,redirects,html=fetch(path); ids=post_ids(html)
        article_count=len(re.findall(r'<article\b[^>]*class=["\'][^"\']*elementor-post[^"\']*["\']',html,re.I|re.S))
        row={'page':n,'path':path,'status':status,'final_url':final,'redirects':redirects,'elementor_post_articles':article_count,'post_ids':ids[:30]}; rows.append(row)
        count=max(article_count,len(ids))
        if count>0: seen_nonempty=True; empty_streak=0
        elif seen_nonempty: empty_streak+=1
        if empty_streak>=3: break
    nonempty=[r['page'] for r in rows if max(r['elementor_post_articles'],len(r['post_ids']))>0]
    print(json.dumps({'last_nonempty_page':max(nonempty) if nonempty else None,'nonempty_pages':nonempty,'rows':rows},ensure_ascii=False,indent=2))
if __name__=='__main__': main()
