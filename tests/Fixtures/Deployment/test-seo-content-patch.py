"""Exercise source preflight, sync and rollback with a filesystem-only Docker stub."""
import hashlib,os,pathlib,shutil,subprocess,tempfile
import re,sys
script_source=pathlib.Path(sys.argv[1]) if len(sys.argv)>1 else pathlib.Path(__file__).resolve().parents[3]/'scripts/deploy/sync-seo-content-20261031.sh'
production_script=script_source.read_text()
files=['app/Http/Controllers/CategoryProductIndexController.php', 'resources/views/pages/categories/show.blade.php', 'app/Http/Controllers/ProductShowController.php', 'resources/content/category-copy.pl.php']
new=[('reviewed source '+str(i)+'\n').encode() for i in range(4)]
old=[('previous source '+str(i)+'\n').encode() for i in range(4)]
# Replace only the expected hashes in a temporary test copy. Real deployment
# remains pinned to the reviewed production files; the stub never runs PHP.
for label,contents in [('new_hashes',new),('old_hashes',old)]:
 section=production_script.split(label+'=(')[1].split(')')[0]
 hashes=re.findall(r'[a-f0-9]{64}',section)
 assert len(hashes)==(4 if label=='new_hashes' else 3)
 for value,content in zip(hashes,contents):production_script=production_script.replace(value,hashlib.sha256(content).hexdigest())
mock=r'''#!/usr/bin/env python3
import sys,os,pathlib,subprocess,hashlib,shutil,tempfile
root=pathlib.Path(os.environ['MOCK_DOCKER_ROOT']);a=sys.argv[1:]
if a[0]=='cp':
 src,dest=a[1:];container,path=dest.split(':',1)
 if os.environ.get('MOCK_FAIL_COPY')=='1' and 'queue' in container and not (root/'failed-once').exists():
  (root/'failed-once').touch();sys.exit(1)
 target=root/container/path.lstrip('/');target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(src,target);sys.exit(0)
assert a.pop(0)=='exec'
while a[0].startswith('-'):
 flag=a.pop(0)
 if flag=='-w':a.pop(0)
container=a.pop(0);cwd=root/container
if a[0]=='sha256sum':
 for p in a[1:]:print(hashlib.sha256((cwd/p).read_bytes()).hexdigest()+'  '+p)
elif a[0]=='mkdir':
 for p in a[2:]:(cwd/p.lstrip('/')).mkdir(parents=True,exist_ok=True)
elif a[0]=='mktemp':
 template=pathlib.Path(a[-1]);d=tempfile.mkdtemp(prefix=template.name.replace('XXXXXX',''),dir=cwd/template.parent);print(pathlib.Path(d).relative_to(cwd))
elif a[0]=='bash':
 a=[str(cwd/x.lstrip('/')) if x.startswith('/tmp/') else x for x in a]
 code=sys.stdin.read();code='php(){ return 0; }\n'+code
 sys.exit(subprocess.run(['bash',*a[1:]],input=code,text=True,cwd=cwd).returncode)
elif a[0] in ['php','sh']:pass
else:raise RuntimeError(a)
'''
for case in ['success','drift','rollback']:
 with tempfile.TemporaryDirectory(prefix='konji-view-sync-test-') as tmp:
  t=pathlib.Path(tmp);artifact=t/'artifact';fixture=t/'docker';shared=fixture/'shared';shared.mkdir(parents=True)
  script=artifact/'scripts/deploy/sync-seo-content-20261031.sh';script.parent.mkdir(parents=True);script.write_text(production_script)
  for rel,source in zip(files,new):p=artifact/rel;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(source)
  containers=['konji-shop-prod-app-1','konji-shop-prod-queue-1','konji-shop-prod-scheduler-1'];before={}
  for index,container in enumerate(containers):
   d=fixture/container;d.mkdir();(d/'storage').symlink_to(shared,target_is_directory=True)
   for rel,source in zip(files,old):
    p=d/rel;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(source)
  for container in containers:(fixture/container/files[3]).unlink()
  if case=='drift':(fixture/containers[1]/files[1]).write_text('Unreviewed source')
  for container in containers:
   for rel in files:before[(container,rel)]=(fixture/container/rel).read_bytes() if (fixture/container/rel).exists() else None
  bin_dir=t/'bin';bin_dir.mkdir();docker=bin_dir/'docker';docker.write_text(mock);docker.chmod(0o755)
  env=os.environ|{'PATH':str(bin_dir)+':'+os.environ['PATH'],'MOCK_DOCKER_ROOT':str(fixture),'MOCK_FAIL_COPY':'1' if case=='rollback' else '0'}
  result=subprocess.run(['bash',str(script)],capture_output=True,text=True,env=env)
  if case=='success':
   assert result.returncode==0,(result.stdout,result.stderr)
   for container in containers:
    for rel,source in zip(files,new):assert (fixture/container/rel).read_bytes()==source
   assert 'SEO_CONTENT_PATCH=PASS' in result.stdout and 'DATABASE_WRITES=0' in result.stdout
  else:
   assert result.returncode!=0,(case,result.stdout,result.stderr)
   for key,content in before.items():
    actual=fixture/key[0]/key[1]
    assert (actual.read_bytes() if actual.exists() else None)==content,(case,key,result.stderr)
   assert ('UNREVIEWED_SOURCE=' if case=='drift' else 'source rollback attempted') in result.stderr
  print(case.upper()+'=PASS')
