"""Build a cPanel upload ZIP and a separate one-time installation key."""
import argparse
import hashlib
import secrets
import zipfile
from pathlib import Path

parser=argparse.ArgumentParser()
parser.add_argument('--output',required=True)
parser.add_argument('--key-output',required=True)
args=parser.parse_args()
root=Path(__file__).resolve().parents[1]
public=root/'public'
if not (public/'index.html').is_file() or not (public/'assets').is_dir():
    raise SystemExit('Build the frontend before packaging.')
key=secrets.token_urlsafe(30)
digest=hashlib.sha256(key.encode()).hexdigest()
output=Path(args.output).resolve()
key_output=Path(args.key_output).resolve()
output.parent.mkdir(parents=True,exist_ok=True)
key_output.parent.mkdir(parents=True,exist_ok=True)
with zipfile.ZipFile(output,'w',compression=zipfile.ZIP_DEFLATED) as z:
    for path in public.rglob('*'):
        if path.is_file() and 'app-private' not in path.parts:
            z.write(path,path.relative_to(public).as_posix())
    for folder in ['php','bin']:
        for path in (root/folder).glob('*.php'):
            z.write(path,'app-private/'+folder+'/'+path.name)
    z.writestr('app-private/.htaccess','Require all denied\n')
    z.writestr('app-private/setup-key.php',"<?php return '"+digest+"';\n")
    z.write(root/'CPANEL-GUIDE.md','START-HERE.md')
key_output.write_text('ERF Track: private installation key\n\n'+key+'\n\nUpload and extract the cPanel ZIP into the subdomain document root.\nOpen https://YOUR-SUBDOMAIN/install.php and enter this key.\nChoose the database and your administrator credentials in the browser.\nDo not upload this text file into a public folder or commit it to GitHub.\nThe installer locks after setup.\n',encoding='utf-8')
print('cPanel ZIP and separate private installation key created.')
