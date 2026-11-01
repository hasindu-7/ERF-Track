import {spawnSync} from 'node:child_process';
import {readdirSync} from 'node:fs';
const php=process.env.PHP_BIN||'php';
const files=['public/index.php','public/install.php',...readdirSync('php').filter(f=>f.endsWith('.php')).map(f=>'php/'+f),...readdirSync('bin').filter(f=>f.endsWith('.php')).map(f=>'bin/'+f)];
const commands=[...files.map(f=>[php,'-l',f]),[php,'tests/php-domain.test.php'],[process.execPath,'--test','tests/php-server.test.mjs','tests/php-installer.test.mjs','tests/excel-export.test.mjs','tests/google-sheets.test.mjs']];
for(const [bin,...args] of commands){const r=spawnSync(bin,args,{stdio:'inherit',env:{...process.env,PHP_BIN:php}});if(r.error){console.error(r.error.message);process.exit(1);}if(r.status!==0)process.exit(r.status||1);}
