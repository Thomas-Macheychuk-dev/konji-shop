#!/usr/bin/env python3
"""Read-only full-variant duplicate review via the user's existing AWS profile."""
import argparse,base64,gzip,importlib.util,json,re,shlex,shutil,sys
from pathlib import Path
ROOT=Path(__file__).parent
path=ROOT.parent/'editorial-review-20261031/download.py'
if not path.exists():path=ROOT/'shared-download.py'
spec=importlib.util.spec_from_file_location('duplicate_transport',path)
transport=importlib.util.module_from_spec(spec);spec.loader.exec_module(transport)
VERSION='product-seo-duplicate-review-20261101-v1'


def export_command(files,container):
    expected={'export.php','DuplicateExport.php','Metadata.php'}
    if set(files)!=expected:raise ValueError('Unexpected export bundle')
    if not re.fullmatch(r'[A-Za-z0-9_.-]+',container):raise ValueError('Invalid container')
    package=base64.b64encode(gzip.compress(json.dumps({n:base64.b64encode(b).decode() for n,b in files.items()}).encode(),mtime=0)).decode()
    return '\n'.join([
        'set -eu','umask 077','taskdir="$(mktemp -d /tmp/konji-seo-editorial.XXXXXX)"',
        'python3 - "$taskdir" '+shlex.quote(package)+" <<'PY'",
        'import base64,gzip,json,pathlib,sys','root=pathlib.Path(sys.argv[1])',
        'files=json.loads(gzip.decompress(base64.b64decode(sys.argv[2])))',
        "assert set(files)=={'export.php','DuplicateExport.php','Metadata.php'}",
        'for name,data in files.items():(root/name).write_bytes(base64.b64decode(data,validate=True))','PY',
        'docker exec --user 0 '+shlex.quote(container)+" sh -c 'umask 077; mkdir -p \"$1\"' sh \"$taskdir\"",
        'docker cp "$taskdir/." '+shlex.quote(container)+':"$taskdir" >/dev/null',
        'docker exec --user 0 -w /var/www/html '+shlex.quote(container)+' php "$taskdir/export.php" >"$taskdir/review.json"',
        'python3 - "$taskdir" <<\'PY\'','import gzip,hashlib,json,pathlib,sys',
        "root=pathlib.Path(sys.argv[1]);raw=(root/'review.json').read_bytes();data=json.loads(raw)",
        "assert data['database_writes'] is False and data['version']=='"+VERSION+"'",
        "payload=gzip.compress(raw,mtime=0);path=root/'review.json.gz';path.write_bytes(payload)",
        "print(json.dumps({'path':str(path),'bytes':len(payload),'json_bytes':len(raw),'sha256':hashlib.sha256(payload).hexdigest(),'json_sha256':hashlib.sha256(raw).hexdigest(),'summary':data['summary']}))",'PY'])


def main():
    a=argparse.ArgumentParser(description=__doc__);a.add_argument('--profile',default='konji-shop');a.add_argument('--region',default='eu-central-1');a.add_argument('--target',default='i-0ae94208f2d069dbc');a.add_argument('--container',default='konji-shop-prod-app-1');a.add_argument('--output',default='konji-seo-duplicate-review.json');args=a.parse_args()
    if not shutil.which('aws'):raise RuntimeError('AWS CLI and authenticated existing profile are required')
    output=Path(args.output).expanduser().resolve()
    if output.exists():raise FileExistsError('Choose a fresh output path; this file exists')
    files={n:(ROOT/n).read_bytes() for n in ['export.php','DuplicateExport.php']}
    files['Metadata.php']=(ROOT.parent/'product-database-20261031/Metadata.php').read_bytes()
    client=transport.SsmClient(args.profile,args.region,args.target)
    print('Creating read-only full-variant duplicate export through AWS SSM...',flush=True)
    metadata=json.loads(client.run(export_command(files,args.container)));print(json.dumps(metadata['summary']),flush=True)
    output,_=transport.download_export(client,metadata,output,progress=lambda s:print(s,flush=True),version=VERSION,collections=('products','groups'))
    print('CHECKSUMS=PASS\nDATABASE_WRITES=0\nOUTPUT='+str(output))

if __name__=='__main__':
    try:main()
    except (RuntimeError,ValueError,OSError,TimeoutError) as e:
        print('DUPLICATE_EXPORT=FAIL '+str(e),file=sys.stderr);sys.exit(1)
