import ExcelJS from 'exceljs';
import { type Board, type Audit, TIMEZONE, statusName, isOverdue, dayDiff } from './model';

type Column={label:string;width:number;format?:string};
type Value=string|number|Date|null;
const DATE='dd mmm yyyy',DATETIME='dd mmm yyyy hh:mm';
const dateValue=(value:string|null|undefined)=>value?new Date(value.slice(0,10)+'T00:00:00Z'):null;
// Excel timestamps have no timezone: store the calendar's local wall-clock time.
export function localDateTime(value:string|null|undefined):Date|null {
 if(!value)return null;
 const p=new Intl.DateTimeFormat('en-GB',{timeZone:TIMEZONE,year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).formatToParts(new Date(value));
 const n=(type:string)=>Number(p.find(x=>x.type===type)!.value);
 return new Date(Date.UTC(n('year'),n('month')-1,n('day'),n('hour'),n('minute'),n('second')));
}
const eventNames:Record<string,string>={added:'Task added',started:'Started',paused:'Paused',resumed:'Resumed',completed:'Completed',reopened:'Reopened',details_updated:'Details updated',deadline_extended:'Deadline extended',deadline_set:'Deadline set',meeting_added:'Meeting added',meeting_updated:'Meeting updated'};

export function buildExportWorkbook(board:Board,today:string,generatedAt=new Date().toISOString()){
 const workbook=new ExcelJS.Workbook();
 workbook.creator='Easy Retailflow';workbook.created=new Date(generatedAt);workbook.modified=new Date(generatedAt);
 const person=(id:string)=>board.members.find(m=>m.id===id)?.name||'Team member';
 const taskName=(id:string|null)=>id?board.tasks.find(t=>t.id===id)?.name||id:'';
 const stamp=localDateTime(generatedAt)!;
 const exported=`Exported ${stamp.toISOString().slice(0,16).replace('T',' ')} · ${TIMEZONE} · All records`;
 function sheet(name:string,columns:Column[],rows:Value[][]){
  const ws=workbook.addWorksheet(name,{views:[{state:'frozen',ySplit:4}],properties:{defaultRowHeight:20},pageSetup:{orientation:'landscape',fitToPage:true,fitToWidth:1,fitToHeight:0,paperSize:9}});
  columns.forEach((c,i)=>{const column=ws.getColumn(i+1);column.width=c.width;if(c.format)column.numFmt=c.format;});
  ws.mergeCells(1,1,1,columns.length);ws.getCell('A1').value=`Easy Retailflow · ${name}`;ws.getCell('A1').font={name:'Calibri',size:17,bold:true,color:{argb:'FF255D48'}};ws.getRow(1).height=30;
  ws.mergeCells(2,1,2,columns.length);ws.getCell('A2').value=exported;ws.getCell('A2').font={name:'Calibri',size:10,color:{argb:'FF66776E'}};ws.getRow(2).height=22;
  ws.getRow(4).values=columns.map(c=>c.label);ws.getRow(4).height=32;
  ws.getRow(4).eachCell(cell=>{cell.font={name:'Calibri',bold:true,size:11,color:{argb:'FFFFFFFF'}};cell.fill={type:'pattern',pattern:'solid',fgColor:{argb:'FF255D48'}};cell.alignment={vertical:'middle',wrapText:true};});
  for(const values of rows){
   const row=ws.addRow(values);let lines=1;
   row.eachCell({includeEmpty:true},(cell,i)=>{
    cell.font={name:'Calibri',size:11,color:{argb:'FF263A34'}};cell.alignment={vertical:'top',wrapText:true};
    if(row.number%2===1)cell.fill={type:'pattern',pattern:'solid',fgColor:{argb:'FFF3F7F2'}};
    if(typeof cell.value==='string'){const width=Math.max(8,columns[i-1].width-3);lines=Math.max(lines,cell.value.split('\n').reduce((sum,line)=>sum+Math.max(1,Math.ceil(line.length/width)),0));}
   });
   row.height=Math.min(409,Math.max(24,lines*16+8));
  }
  ws.autoFilter={from:{row:4,column:1},to:{row:Math.max(4,ws.rowCount),column:columns.length}};
  ws.pageSetup.printTitlesRow='1:4';
  return ws;
 }

 const taskRows=board.tasks.map(t=>[
  t.parentId?'Subtask':'Task',t.name,taskName(t.parentId),t.origin==='client'?'Client':'Internal',t.client,person(t.ownerId),statusName[t.status],
  localDateTime(t.addedAt),dateValue(t.plannedFrom),localDateTime(t.startedAt),dateValue(t.deadline),localDateTime(t.completedAt),
  isOverdue(t,today)?'Yes':'No',isOverdue(t,today)?dayDiff(t.deadline!,today):0,t.notes,t.id,t.parentId||''
 ] as Value[]);
 const tasks=sheet('Tasks',[
  {label:'Type',width:12},{label:'Task name',width:36},{label:'Parent task',width:36},{label:'Origin',width:12},{label:'Client',width:24},{label:'Responsible',width:24},{label:'Status',width:18},
  {label:'Added on',width:23,format:DATETIME},{label:'Planned start',width:18,format:DATE},{label:'Actually started',width:23,format:DATETIME},{label:'Deadline',width:18,format:DATE},{label:'Completed on',width:23,format:DATETIME},
  {label:'Overdue',width:12},{label:'Days overdue',width:15,format:'0'},{label:'Notes',width:65},{label:'Task ID',width:39},{label:'Parent ID',width:39}
 ],taskRows);
 board.tasks.forEach((t,i)=>{if(t.status==='not_started'||isOverdue(t,today))tasks.getCell(i+5,7).font={name:'Calibri',size:11,color:{argb:'FFB04438'}};});
 sheet('Meetings',[
  {label:'Meeting',width:36},{label:'Date',width:18,format:DATE},{label:'Time',width:14,format:'hh:mm'},{label:'Duration (minutes)',width:20,format:'0'},{label:'Status',width:16},{label:'Minutes / special notes',width:70},{label:'Added on',width:23,format:DATETIME},{label:'Meeting ID',width:39}
 ],[...board.meetings].sort((a,b)=>(a.date+a.time).localeCompare(b.date+b.time)).map(m=>[m.title,dateValue(m.date),Number(m.time.slice(0,2))/24+Number(m.time.slice(3,5))/1440,m.duration,m.status.charAt(0).toUpperCase()+m.status.slice(1),m.notes,localDateTime(m.createdAt),m.id]));
 sheet('Team',[{label:'Name',width:30},{label:'Email',width:42},{label:'Login enabled',width:20},{label:'Member ID',width:39}],board.members.map(m=>[m.name,m.email,m.userId?'Yes':'No',m.id]));
 const events:{kind:string;name:string;id:string;a:Audit}[]=[];
 board.tasks.forEach(t=>t.history.forEach(a=>events.push({kind:t.parentId?'Subtask':'Task',name:t.name,id:t.id,a})));
 board.meetings.forEach(m=>m.history.forEach(a=>events.push({kind:'Meeting',name:m.title,id:m.id,a})));
 sheet('Activity',[
  {label:'Record type',width:14},{label:'Task / meeting',width:36},{label:'Event',width:24},{label:'Recorded at',width:23,format:DATETIME},{label:'Changed by',width:24},{label:'Reason',width:65},{label:'Previous deadline',width:21,format:DATE},{label:'New deadline',width:18,format:DATE},{label:'Record ID',width:39}
 ],events.sort((a,b)=>a.a.at.localeCompare(b.a.at)).map(({kind,name,id,a})=>[kind,name,eventNames[a.type]||a.type,localDateTime(a.at),a.actorName,a.reason,dateValue(a.from),dateValue(a.to),id]));
 sheet('Pauses',[
  {label:'Task',width:36},{label:'Parent task',width:36},{label:'Paused from',width:23,format:DATETIME},{label:'Resumed on',width:23,format:DATETIME},{label:'Pause state',width:18},{label:'Reason',width:65},{label:'Paused by',width:24},{label:'Waiting for',width:36},{label:'Expected resume',width:20,format:DATE},{label:'Task ID',width:39}
 ],board.tasks.flatMap(t=>t.pauses.map(p=>[t.name,taskName(t.parentId),localDateTime(p.from),localDateTime(p.to),p.to?'Ended':'Still paused',p.reason,p.actorName,taskName(p.waitingFor),dateValue(p.expectedResume),t.id])));
 return workbook;
}

export async function excelExportBytes(board:Board,today:string){
 const workbook=buildExportWorkbook(board,today);
 const buffer=await workbook.xlsx.writeBuffer();
 return new Uint8Array(buffer as unknown as ArrayBuffer);
}
