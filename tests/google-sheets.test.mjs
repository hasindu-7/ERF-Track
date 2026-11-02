import {spawn} from 'node:child_process';
import {randomBytes} from 'node:crypto';
import {mkdtempSync,mkdirSync,readFileSync} from 'node:fs';
import {resolve} from 'node:path';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import test from 'node:test';

test('Sheets feed exports only opted-in entries, with isolated Live selection and revocable read-only keys',async()=>{
 mkdirSync('.sites-runtime',{recursive:true});const data=mkdtempSync(resolve('.sites-runtime','sheets-test-')),origin='http://127.0.0.1:5204',password=randomBytes(24).toString('hex');
 const child=spawn(process.env.PHP_BIN||'php',['-S','127.0.0.1:5204','-t','public','php/router.php'],{env:{...process.env,APP_ORIGIN:origin,DATA_DIR:data,ADMIN_EMAIL:'sheets@example.test',ADMIN_NAME:'Owner',ADMIN_PASSWORD:password},stdio:'ignore'});
 let cookie='';
 const api=(path,body,extra={})=>fetch(origin+path,{method:body?'POST':'GET',headers:{Origin:origin,'Content-Type':'application/json',...(cookie?{Cookie:cookie}:{}),...extra},...(body?{body:JSON.stringify(body)}:{})});
 try{
  for(let i=0;i<80;i++){try{if((await fetch(origin+'/healthz')).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
  assert.equal((await api('/api/google-sheets/feed')).status,401);
  let r=await api('/api/login',{email:'sheets@example.test',password});cookie=r.headers.get('set-cookie').split(';')[0];
  await api('/api/password',{currentPassword:password,password:randomBytes(24).toString('hex')});let state=await(await api('/api/board')).json();
  const post=async(action,payload)=>{const r=await api('/api/board',{revision:state.revision,action,payload});assert.equal(r.status,200,await r.clone().text());state=await r.json();};
  const add=extra=>post('task.add',{name:'Private parent',notes:'PRIVATE NOT SHARED',category:'feature',ownerId:state.board.members[0].id,projectId:'erf',environment:'dev',deadline:'2099-12-31',...extra});
  await add({});const parent=state.board.tasks[0].id;await add({parentId:parent,name:'Selected subtask',notes:'Allowed notes'});const childId=state.board.tasks[1].id;
  await post('task.sheet_sync',{id:childId,enabled:true});assert.equal(state.board.tasks[0].sheetSync,false);
  const bad=await api('/api/board',{revision:state.revision,action:'task.sheet_sync',payload:{id:parent,enabled:'true'}});assert.equal(bad.status,400);
  r=await api('/api/google-sheets/settings',{action:'rotate'});assert.equal(r.status,200);const key=(await r.json()).key;assert.equal(key.length,64);
  const settings=await(await api('/api/google-sheets/settings')).json();assert.equal(settings.key,null);assert.equal(settings.selectedCount,1);
  const admin=cookie;cookie='';const feed=()=>api('/api/google-sheets/feed',null,{'X-ERF-Sheets-Key':key});r=await feed();assert.equal(r.status,200);let body=await r.json();assert.equal(body.rows.length,1);assert.equal(body.rows[0][1],'Selected subtask');assert.equal(body.rows[0].length,6);assert.ok(!JSON.stringify(body).includes('Private parent'));assert.ok(!JSON.stringify(body).includes('PRIVATE NOT SHARED'));assert.ok(!JSON.stringify(body).includes(parent));
  assert.equal((await api('/api/google-sheets/settings',null,{'X-ERF-Sheets-Key':key})).status,401);
  assert.equal((await api('/api/board',null,{'X-ERF-Sheets-Key':key})).status,401);
  assert.equal((await api('/api/google-sheets/feed?key='+key)).status,401);
  cookie=admin;await post('task.sheet_sync',{id:parent,enabled:true});await post('task.promote',{id:parent});assert.equal(state.board.tasks.filter(t=>t.environment==='live'&&t.sheetSync).length,0);
  body=await(await feed()).json();assert.equal(body.rows.length,2);assert.ok(body.rows.some(row=>row[1]==='Private parent'));assert.ok(body.rows.some(row=>row[1]==='Selected subtask'));
  await post('task.sheet_sync',{id:childId,enabled:false});body=await(await feed()).json();assert.equal(body.rows.length,1);
  await post('task.sheet_sync',{id:parent,enabled:false});assert.equal((await(await feed()).json()).rows.length,0);
  r=await api('/api/google-sheets/settings',{action:'rotate'});const newer=(await r.json()).key;assert.notEqual(newer,key);assert.equal((await feed()).status,401);
  assert.equal((await api('/api/google-sheets/feed',null,{'X-ERF-Sheets-Key':newer})).status,200);
  await post('member.add',{name:'Member',email:'member@example.test'});r=await api('/api/accounts',{memberId:state.board.members.at(-1).id});const credentials=await r.json();r=await api('/api/login',credentials);cookie=r.headers.get('set-cookie').split(';')[0];await api('/api/password',{currentPassword:credentials.password,password:randomBytes(24).toString('hex')});assert.equal((await api('/api/google-sheets/settings')).status,403);assert.equal((await api('/api/google-sheets/settings',{action:'rotate'})).status,403);
  cookie=admin;await api('/api/google-sheets/settings',{action:'disable'});assert.equal((await api('/api/google-sheets/feed',null,{'X-ERF-Sheets-Key':newer})).status,401);
 }finally{await new Promise(r=>{child.once('exit',r);child.kill();});}
});

test('Apps Script replaces selected rows, removes stale rows, keeps other tabs, escapes formulas and preserves data on fetch failure',()=>{
 const script=readFileSync('frontend-static/integrations/erf-track.gs','utf8');let values=null,writes=0,code=200;
 const columns=['Date','Task','Deadline','Deadline extended','Status','Reason / blocker'];
 let rows=[['2026-11-02','=IMPORTXML("evil")','2099-12-31','','Testing','\n=HYPERLINK("evil")']];
 const range={setNumberFormat(){return this;},setValues(v){values=v;writes++;return this;},setBackground(){return this;},setFontColor(){return this;},setFontWeight(){return this;},setNote(){return this;},setVerticalAlignment(){return this;},setWrap(){return this;},clearFormat(){return this;},createFilter(){return this;}};
 const sheet={getSheetId:()=>7,getMaxRows:()=>1000,getMaxColumns:()=>26,getLastRow:()=>4,getLastColumn:()=>16,setConditionalFormatRules(){},getRange:()=>range,setFrozenRows(){},setColumnWidths(){},setColumnWidth(){},getFilter:()=>null};
 const props={ERF_KEY:'a'.repeat(64),ERF_ORIGIN:'https://erf.example',ERF_BOOK_ID:'book',ERF_SHEET_ID:'7'};let lockReleased=0;
 const context={PropertiesService:{getScriptProperties:()=>({getProperties:()=>props,setProperty(){}})},LockService:{getScriptLock:()=>({tryLock:()=>true,releaseLock:()=>lockReleased++})},UrlFetchApp:{fetch:(url,options)=>{assert.equal(options.headers['X-ERF-Sheets-Key'],props.ERF_KEY);assert.equal(options.followRedirects,false);return {getResponseCode:()=>code,getContentText:()=>JSON.stringify({format:'erf-management-tasks-v2',columns,rows,timezone:'Asia/Colombo'})};}},SpreadsheetApp:{newConditionalFormatRule:()=>({whenTextContains(){return this;},setBackground(){return this;},setFontColor(){return this;},setRanges(){return this;},build(){return {};}}),getActiveSpreadsheet:()=>({getId:()=>'book',getSheets:()=>[sheet]}),flush(){}}};
 vm.createContext(context);vm.runInContext(script,context);context.syncERFTrack();assert.equal(writes,1);assert.equal(values.length,4);assert.equal(values[1][1][0],"'");assert.equal(values[1][5][0],"'");assert.equal(Object.prototype.toString.call(values[1][0]),'[object Date]');assert.equal(values[1].length,16);assert.equal(values[1].slice(6).join(''),'');assert.equal(values[2].join(''),'');
 rows=[];context.syncERFTrack();assert.equal(values[1].join(''),'');assert.equal(writes,2);
 code=401;assert.throws(()=>context.syncERFTrack(),/HTTP 401/);assert.equal(writes,2);assert.equal(lockReleased,3);
});
