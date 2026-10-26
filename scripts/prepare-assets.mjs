import {existsSync,readdirSync,unlinkSync,realpathSync} from 'node:fs';
import {resolve,join} from 'node:path';
const root=resolve('public');if(realpathSync(root)!==root)throw new Error('Unexpected public directory.');
const folder=join(root,'assets');
if(existsSync(folder))for(const file of readdirSync(folder)){
  if(!/^[a-zA-Z0-9_.-]+\.(js|css)$/.test(file))throw new Error('Unexpected generated asset; inspect before cleaning.');
  unlinkSync(join(folder,file));
}
