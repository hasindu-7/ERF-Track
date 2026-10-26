import {spawn,spawnSync} from 'node:child_process';
import {randomBytes} from 'node:crypto';
import {mkdirSync,mkdtempSync} from 'node:fs';
import {resolve} from 'node:path';
import assert from 'node:assert/strict';
import test from 'node:test';
const php=process.env.PHP_BIN||'php',origin='http://127.0.0.1:5194',password=randomBytes(24).toString('hex'),newPassword=randomBytes(24).toString('hex');
mkdirSync('.sites-runtime',{recursive:true});const data=mkdtempSync(resolve('.sites-runtime','php-test-'));
let child,cookie='',logs='';
async function start(){child=spawn(php,['-S','127.0.0.1:5194','-t','public','php/router.php'],{env:{...process.env,APP_ORIGIN:origin,DATA_DIR:data,ADMIN_EMAIL:'test@example.test',ADMIN_NAME:'Test Admin',ADMIN_PASSWORD:password},stdio:['ignore','pipe','pipe']});child.stdout.on('data',s=>logs+=s);child.stderr.on('data',s=>logs+=s);for(let i=0;i<80;i++){try{if((await fetch(origin+'/healthz')).ok)return;}catch{}await new Promise(r=>setTimeout(r,100));}throw new Error('PHP failed to start: '+logs.slice(-1200));}
async function stop(){if(!child)return;const p=child;child=null;if(p.exitCode!==null)return;await new Promise(r=>{p.once('exit',r);p.kill();});}
async function api(path,payload,headers={}){return fetch(origin+path,{method:payload?'POST':'GET',headers:{'Content-Type':'application/json',Origin:origin,...(cookie?{Cookie:cookie}:{}),...headers},...(payload?{body:JSON.stringify(payload)}:{})});}
test('PHP authentication, projects, independent Dev/Live flow, persistence and permissions',async()=>{
 await start();try{
  const anonymous=await api('/api/board');assert.equal(anonymous.status,401,await anonymous.text());assert.equal((await api('/api/login',{email:'test@example.test',password},{Origin:'https://untrusted.example'})).status,403);
  let r=await api('/api/login',{email:'test@example.test',password,remember:true});assert.equal(r.status,200);assert.match(r.headers.get('set-cookie'),/Max-Age=2592000/i);cookie=r.headers.get('set-cookie').split(';')[0];assert.equal((await api('/api/board')).status,403);
  assert.equal((await api('/api/password',{currentPassword:password,password:newPassword})).status,200);let s=await(await api('/api/board')).json();const owner=s.board.members[0].id;
  const post=async(action,payload)=>{const r=await api('/api/board',{revision:s.revision,action,payload});assert.equal(r.status,200,await r.clone().text());s=await r.json();return s;};
  await post('project.add',{name:'Second product'});const project=s.board.projects[1].id;assert.equal(s.board.projects.length,2);
  await post('task.add',{name:'Receipt issue',category:'issue',origin:'internal',projectId:'erf',environment:'dev',ownerId:owner,deadline:'2099-12-31'});const issue=s.board.tasks[0].id;
  await post('task.status',{id:issue,status:'done'});assert.equal(s.board.tasks.length,2);const live=s.board.tasks.find(t=>t.environment==='live');assert.equal(live.status,'pending');
  await post('task.status',{id:live.id,status:'testing'});assert.equal(s.board.tasks.find(t=>t.id===issue).status,'done');
  await post('task.add',{name:'Independent feature',category:'feature',origin:'internal',projectId:project,environment:'dev',ownerId:owner,deadline:'2099-12-31'});const feature=s.board.tasks.at(-1).id;
  await post('task.status',{id:feature,status:'done'});assert.equal(s.board.tasks.length,3);await post('task.promote',{id:feature});assert.equal(s.board.tasks.length,4);
  assert.equal((await api('/api/board',{revision:s.revision-1,action:'project.add',payload:{name:'Stale'}})).status,409);
  await post('member.add',{name:'Colleague',email:'colleague@example.test'});const member=s.board.members.find(m=>m.email==='colleague@example.test');r=await api('/api/accounts',{memberId:member.id});assert.equal(r.status,201);const creds=await r.json();const adminCookie=cookie;
  r=await api('/api/login',{email:creds.email,password:creds.password});assert.equal(r.status,200);assert.equal(r.headers.get('set-cookie').includes('Max-Age='),false);cookie=r.headers.get('set-cookie').split(';')[0];assert.equal((await api('/api/password',{currentPassword:creds.password,password:randomBytes(24).toString('hex')})).status,200);assert.equal((await api('/api/accounts',{memberId:owner})).status,403);cookie=adminCookie;
  await stop();await start();s=await(await api('/api/board')).json();assert.equal(s.board.tasks.length,4);assert.equal(s.board.projects.length,2);assert.equal(s.board.tasks.find(t=>t.id===live.id).status,'testing');
  const backup=spawnSync(php,['bin/backup.php'],{env:{...process.env,APP_ORIGIN:origin,DATA_DIR:data},encoding:'utf8'});assert.equal(backup.status,0,backup.stderr);
  assert.equal((await api('/api/logout',{})).status,200);assert.equal((await api('/api/board')).status,401);
 }finally{await stop();}
});
