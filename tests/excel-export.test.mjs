import test from 'node:test';
import assert from 'node:assert/strict';
import {build} from 'esbuild';
import {mkdir} from 'node:fs/promises';
import ExcelJS from 'exceljs';
await mkdir('.sites-runtime/tests',{recursive:true});
await build({entryPoints:['lib/export-excel.ts'],outfile:'.sites-runtime/tests/export-excel.mjs',bundle:true,platform:'node',format:'esm',external:['exceljs']});
const {buildExportWorkbook,localDateTime}=await import('../.sites-runtime/tests/export-excel.mjs');
const member={id:'owner',name:'Hasindu',email:'owner@example.test',userId:'private-login-id'};
const audit={id:'event',type:'deadline_extended',at:'2026-10-13T20:00:00Z',actorId:'owner',actorName:'Hasindu',reason:'Additional client work',from:'2026-10-12',to:'2026-10-13'};
const base={id:'task',parentId:null,name:'Receipt fix',origin:'client',client:'Green Mart',ownerId:'owner',color:'#397a66',notes:'=HYPERLINK("https://example.test","Not a formula")',addedAt:'2026-10-10T03:30:00Z',plannedFrom:null,startedAt:null,completedAt:null,deadline:'2026-10-13',status:'not_started',history:[audit],pauses:[]};
const child={...base,id:'subtask',parentId:'task',name:'Tax breakdown',status:'paused',notes:'Line one\nLine two',plannedFrom:'2026-10-12',startedAt:'2026-10-12T04:30:00Z',history:[],pauses:[{id:'pause',from:'2026-10-13T05:00:00Z',to:null,reason:'Urgent checkout request',actorName:'Hasindu',actorId:'owner',waitingFor:'task',expectedResume:'2026-10-16'}]};
const board={members:[member],tasks:[base,child],meetings:[{id:'meeting',title:'Planning',date:'2026-10-14',time:'14:30',duration:35,notes:'Prioritise customer fixes',status:'held',createdAt:'2026-10-13T08:00:00Z',history:[]}],timezone:'Asia/Colombo'};

test('Excel export preserves records, typed dates, parent links and audit reasons after XLSX reload',async()=>{
 const workbook=buildExportWorkbook(board,'2026-10-14','2026-10-14T05:00:00Z');
 const buffer=await workbook.xlsx.writeBuffer();const reload=new ExcelJS.Workbook();await reload.xlsx.load(buffer);
 assert.deepEqual(reload.worksheets.map(s=>s.name),['Tasks','Meetings','Team','Activity','Pauses']);
 const tasks=reload.getWorksheet('Tasks');assert.equal(tasks.rowCount,6);assert.equal(tasks.getCell('A6').value,'Subtask');assert.equal(tasks.getCell('C6').value,'Receipt fix');
 assert.equal(tasks.getCell('J5').value,null);assert.equal(tasks.getCell('H5').value.toISOString(),'2026-10-10T09:00:00.000Z');assert.equal(tasks.getCell('K5').value.toISOString(),'2026-10-13T00:00:00.000Z');
 assert.equal(tasks.getCell('N5').value,1);assert.equal(tasks.getCell('O5').value,base.notes);assert.equal(tasks.getCell('O5').type,ExcelJS.ValueType.String);
 assert.equal(tasks.getCell('K5').numFmt,'dd mmm yyyy');assert.equal(tasks.views[0].ySplit,4);assert.equal(tasks.autoFilter,'A4:Q6');
 const meetings=reload.getWorksheet('Meetings');const time=meetings.getCell('C5').value;const timeFraction=time instanceof Date?(time.getUTCHours()*60+time.getUTCMinutes())/1440:time;assert.ok(Math.abs(timeFraction-14.5/24)<1e-10);assert.equal(meetings.getCell('D5').value,35);
 const history=reload.getWorksheet('Activity');assert.equal(history.getCell('F5').value,audit.reason);assert.equal(history.getCell('D5').value.toISOString(),'2026-10-14T01:30:00.000Z');assert.equal(history.getCell('G5').value.toISOString(),'2026-10-12T00:00:00.000Z');
 const pauses=reload.getWorksheet('Pauses');assert.equal(pauses.getCell('E5').value,'Still paused');assert.equal(pauses.getCell('D5').value,null);assert.equal(pauses.getCell('H5').value,'Receipt fix');
 const team=reload.getWorksheet('Team');assert.equal(team.getCell('B5').value,member.email);assert.equal(team.columnCount,4);
 const values=reload.worksheets.flatMap(s=>{const rows=[];s.eachRow(r=>rows.push(r.values));return rows;});assert.ok(!JSON.stringify(values).includes(member.userId));
});
test('empty calendars still export readable sheets with headers',async()=>{
 const workbook=buildExportWorkbook({members:[],tasks:[],meetings:[],timezone:'Asia/Colombo'},'2026-10-14');
 const reload=new ExcelJS.Workbook();await reload.xlsx.load(await workbook.xlsx.writeBuffer());for(const sheet of reload.worksheets){assert.equal(sheet.rowCount,4);assert.ok(sheet.getCell('A4').value);}
});
test('local timestamps preserve Colombo day boundaries',()=>{assert.equal(localDateTime('2026-10-13T23:55:00Z').toISOString(),'2026-10-14T05:25:00.000Z');assert.equal(localDateTime(null),null);});
