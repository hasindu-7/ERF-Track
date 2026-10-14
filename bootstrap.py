"""Prepare private first-run configuration without printing credentials."""
import argparse
import json
import os
import secrets
from pathlib import Path

parser=argparse.ArgumentParser(description='Configure a new ERF Track deployment')
parser.add_argument('--directory',default=str(Path(__file__).resolve().parent))
parser.add_argument('--origin',required=True,help='Public origin, without trailing slash')
parser.add_argument('--email',required=True,help='Initial administrator email')
parser.add_argument('--name',default='Administrator')
args=parser.parse_args()
if not args.origin.startswith(('http://','https://')) or args.origin.endswith('/'):
    parser.error('origin must start with http:// or https:// and have no trailing slash')
if '@' not in args.email or '\n' in args.email or '\r' in args.email:
    parser.error('enter a valid administrator email')
root=Path(args.directory).resolve()
root.mkdir(parents=True,exist_ok=True)
os.umask(0o077)
env=root/'.env'
if env.exists():
    print('Existing .env retained; no credentials were changed.')
else:
    password=secrets.token_urlsafe(24)
    values={'APP_ORIGIN':args.origin,'HOST':'127.0.0.1','PORT':'3000','DATA_DIR':str(root/'data'),'ADMIN_EMAIL':args.email,'ADMIN_NAME':args.name,'ADMIN_PASSWORD':password}
    env.write_text(''.join(k+'='+json.dumps(v,ensure_ascii=False)+'\n' for k,v in values.items()),encoding='utf-8')
    env.chmod(0o600)
    access=root/'bootstrap-access.txt'
    access.write_text('ERF Track administrator access\n\nURL: '+args.origin+'\nEmail: '+args.email+'\nTemporary password: '+password+'\n\nChoose a new password on first sign-in. Keep this file private.\n',encoding='utf-8')
    access.chmod(0o600)
    print('Configuration created. Initial login details are in bootstrap-access.txt; keep it private.')
(root/'data').mkdir(exist_ok=True,mode=0o700)
