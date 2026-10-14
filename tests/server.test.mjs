import {spawn} from 'node:child_process';
import {randomBytes} from 'node:crypto';
import {mkdirSync,mkdtempSync} from 'node:fs';
import {resolve} from 'node:path';
import assert from 'node:assert/strict';
import test from 'node:test';
const origin='http://127.0.0.1:5194';
const password=randomBytes(24).toString('base64url'),newPassword=randomBytes(24).toString('base64url');
mkdirSync('.sites-runtime',{recursive:true});const data=mkdtempSync(resolve('.sites-runtime','auth-test-'));
let child,cookie='';
async function start(){child=spawn(process.execPath,['server.mjs'],{env:{...process.env,PORT:'5194',APP_ORIGIN:origin,DATA_DIR:data,ADMIN_EMAIL:'test@example.test',ADMIN_NAME:'Test Admin',ADMIN_PASSWORD:password},stdio:'ignore'});for(let i=0;i<50;i++){try{if((await fetch(origin+'/healthz')).ok)return;}catch{}await new Promise(r=>setTimeout(r,100));}throw new Error('Server did not start');}
async function stop(){if(!child)return;const p=child;child=null;await new Promise(r=>{p.once('exit',r);p.kill();});}
async function api(path,payload,headers={}){return fetch(origin+path,{method:payload?'POST':'GET',headers:{'Content-Type':'application/json',Origin:origin,...(cookie?{Cookie:cookie}:{}),...headers},...(payload?{body:JSON.stringify(payload)}:{})});}
test('VPS authentication, account creation, shared writes, persistence and permissions',async()=>{
 await start();try{
  assert.equal((await api('/api/board',null,{'oai-authenticated-user-id':'forged','oai-authenticated-user-email':'forged@example.test'})).status,401);
  assert.equal((await api('/api/login',{email:'test@example.test',password},{Origin:'https://untrusted.example'})).status,403);
  const login=await api('/api/login',{email:'test@example.test',password});assert.equal(login.status,200);assert.equal(login.headers.get('set-cookie').includes('Max-Age='),false);cookie=login.headers.get('set-cookie').split(';')[0];assert.equal((await api('/api/board')).status,403);
  assert.equal((await api('/api/password',{currentPassword:password,password:newPassword})).status,200);
  const remembered=await api('/api/login',{email:'test@example.test',password:newPassword,remember:true});assert.equal(remembered.status,200);assert.match(remembered.headers.get('set-cookie'),/Max-Age=2592000/);assert.match(remembered.headers.get('set-cookie'),/HttpOnly/);cookie=remembered.headers.get('set-cookie').split(';')[0];
  let s=await(await api('/api/board')).json();const me=s.board.members[0];
  let r=await api('/api/board',{revision:s.revision,action:'task.add',payload:{name:'Persistent feature',origin:'internal',ownerId:me.id,deadline:'2099-12-31'}});assert.equal(r.status,200);s=await r.json();assert.equal(s.board.tasks[0].startedAt,null);
  assert.equal((await api('/api/board',{revision:s.revision-1,action:'task.start',payload:{id:s.board.tasks[0].id}})).status,409);
  r=await api('/api/board',{revision:s.revision,action:'member.add',payload:{name:'Colleague',email:'colleague@example.test'}});s=await r.json();const member=s.board.members.find(m=>m.email==='colleague@example.test');
  r=await api('/api/accounts',{memberId:member.id});assert.equal(r.status,201);const creds=await r.json();assert.equal(creds.email,'colleague@example.test');
  const adminCookie=cookie;r=await api('/api/login',{email:creds.email,password:creds.password});cookie=r.headers.get('set-cookie').split(';')[0];assert.equal((await api('/api/password',{currentPassword:creds.password,password:randomBytes(24).toString('base64url')})).status,200);assert.equal((await api('/api/accounts',{memberId:me.id})).status,403);cookie=adminCookie;
  await stop();await start();s=await(await api('/api/board')).json();assert.equal(s.board.tasks[0].name,'Persistent feature');
  assert.equal((await api('/api/logout',{})).status,200);assert.equal((await api('/api/board')).status,401);
 }finally{await stop();}
});
