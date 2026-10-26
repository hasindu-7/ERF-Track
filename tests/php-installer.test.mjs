import {spawn,spawnSync} from 'node:child_process';
import {createHash,randomBytes} from 'node:crypto';
import {mkdirSync,mkdtempSync,existsSync,readFileSync,writeFileSync} from 'node:fs';
import {resolve} from 'node:path';
import assert from 'node:assert/strict';
import test from 'node:test';
const php=process.env.PHP_BIN||'php',origin='http://127.0.0.1:5200';
test('browser installation needs its key, stores no admin password, locks after setup and preserves legacy records',async()=>{
 mkdirSync('.sites-runtime',{recursive:true});const root=mkdtempSync(resolve('.sites-runtime','install-test-')),config=resolve(root,'config.local.php'),data=resolve(root,'private-data'),key=randomBytes(24).toString('hex'),password=randomBytes(24).toString('hex');
 const env={...process.env,ERF_CONFIG_FILE:config,INSTALL_KEY_HASH:createHash('sha256').update(key).digest('hex')};for(const name of ['ADMIN_PASSWORD','ADMIN_EMAIL','DATA_DIR','APP_ORIGIN','DB_DRIVER'])delete env[name];
 const child=spawn(php,['-S','127.0.0.1:5200','-t','public','php/router.php'],{env,stdio:'ignore'});
 try{
  let page;for(let i=0;i<80;i++){try{page=await fetch(origin+'/install.php');if(page.ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}assert.ok(page?.ok,'Installer started');
  const cookie=page.headers.get('set-cookie').split(';')[0],html=await page.text(),csrf=html.match(/name="csrf" value="([^"]+)"/)[1];
  const values={csrf,key:'wrong',origin,driver:'sqlite',data,name:'Owner',email:'owner@example.test',password,confirm:password};
  const post=()=>fetch(origin+'/install.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded',Cookie:cookie,Origin:origin},body:new URLSearchParams(values)});
  assert.match(await(await post()).text(),/Installation key is incorrect/);assert.equal(existsSync(config),false);
  values.key=key;const result=await(await post()).text();assert.match(result,/Your calendar is ready/);assert.ok(existsSync(config));assert.ok(!readFileSync(config,'utf8').includes(password));assert.match(await(await fetch(origin+'/install.php')).text(),/installer is locked/);
  const login=await fetch(origin+'/api/login',{method:'POST',headers:{'Content-Type':'application/json',Origin:origin},body:JSON.stringify({email:values.email,password,remember:true})});assert.equal(login.status,200);assert.equal((await login.json()).mustChangePassword,false);const session=login.headers.get('set-cookie').split(';')[0];
  const backup={tasks:[{id:'legacy',name:'Existing feature',ownerId:'old-owner',parentId:null,origin:'internal',client:'',color:'#397a66',notes:'Imported',addedAt:'2026-10-01T00:00:00Z',startedAt:null,plannedFrom:null,deadline:'2099-12-31',completedAt:null,status:'not_started',pauses:[],history:[]}],meetings:[],members:[{id:'old-owner',name:'Owner',email:values.email,userId:'node-user'}]};
  const file=resolve(root,'legacy.json');writeFileSync(file,JSON.stringify({board:backup}));let imported=spawnSync(php,['bin/import-legacy.php',file],{env,encoding:'utf8'});assert.equal(imported.status,0,imported.stderr);
  const snapshot=await(await fetch(origin+'/api/board',{headers:{Cookie:session}})).json();assert.equal(snapshot.board.tasks[0].status,'pending');assert.equal(snapshot.board.tasks[0].environment,'dev');assert.equal(snapshot.board.tasks[0].category,'feature');assert.equal(snapshot.board.members[0].id,'old-owner');assert.equal(snapshot.board.members[0].userId,snapshot.user.userId);
  imported=spawnSync(php,['bin/import-legacy.php',file],{env,encoding:'utf8'});assert.notEqual(imported.status,0);
 }finally{await new Promise(r=>{child.once('exit',r);child.kill();});}
});
