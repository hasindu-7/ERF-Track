import {DatabaseSync} from 'node:sqlite';
import {mkdirSync,readdirSync,unlinkSync} from 'node:fs';
import {resolve,sep} from 'node:path';
const root=resolve(process.env.DATA_DIR||'/data'),folder=resolve(root,'backups');mkdirSync(folder,{recursive:true,mode:0o700});
const name=new Date().toISOString().replace(/[:.]/g,'-')+'-retailflow.sqlite';
const db=new DatabaseSync(resolve(root,'retailflow.sqlite'));db.prepare('VACUUM INTO ?').run(resolve(folder,name));db.close();
const backups=readdirSync(folder).filter(n=>/^\d{4}-\d{2}-\d{2}T[\dTZ-]+-retailflow\.sqlite$/.test(n)).sort();
for(const old of backups.slice(0,-14)){const target=resolve(folder,old);if(!target.startsWith(folder+sep))throw new Error('Invalid backup path');unlinkSync(target);}
console.log('Database backup created.');
