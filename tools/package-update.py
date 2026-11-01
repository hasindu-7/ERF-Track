"""Package a cPanel application update without installation/configuration files."""
import argparse
import zipfile
from pathlib import Path

parser=argparse.ArgumentParser()
parser.add_argument('--output',required=True)
args=parser.parse_args()
root=Path(__file__).resolve().parents[1]
output=Path(args.output).resolve()
output.parent.mkdir(parents=True,exist_ok=True)
with zipfile.ZipFile(output,'w',compression=zipfile.ZIP_DEFLATED) as z:
    for name in ['index.php','index.html','favicon.svg']:
        z.write(root/'public'/name,name)
    for folder in ['assets','integrations']:
        for path in (root/'public'/folder).rglob('*'):
            if path.is_file():z.write(path,path.relative_to(root/'public').as_posix())
    for path in (root/'php').glob('*.php'):
        z.write(path,'app-private/php/'+path.name)
print('Update ZIP created. Configuration, database, .htaccess and installer files are excluded.')
