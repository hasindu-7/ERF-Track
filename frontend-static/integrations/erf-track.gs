/**
 * @OnlyCurrentDoc
 * Bound Google Apps Script. No web-app deployment or public sheet needed.
 */
function onOpen() {
  SpreadsheetApp.getUi().createMenu('ERF Track')
    .addItem('Set up / replace key', 'setupERFTrack')
    .addItem('Refresh now', 'syncERFTrack')
    .addItem('Stop automatic refresh', 'stopERFTrack').addToUi();
}

function setupERFTrack() {
  const ui=SpreadsheetApp.getUi(),book=SpreadsheetApp.getActiveSpreadsheet();
  const origin=ui.prompt('ERF Track app URL', 'Enter https://erftrack.aradhana.au (no trailing slash).', ui.ButtonSet.OK_CANCEL);
  if(origin.getSelectedButton()!==ui.Button.OK)return;
  const url=origin.getResponseText().trim().replace(/\/$/,'');
  if(!/^https:\/\/[a-z0-9.-]+(?::\d+)?$/i.test(url))throw new Error('Use the exact HTTPS app origin, with no path.');
  const answer=ui.prompt('Private connection key', 'Paste the key from ERF Track → Export → Google Sheets sync. Never put it in a cell.',ui.ButtonSet.OK_CANCEL);
  if(answer.getSelectedButton()!==ui.Button.OK)return;
  const key=answer.getResponseText().trim();
  if(!/^[a-f0-9]{64}$/.test(key))throw new Error('The connection key must be 64 hexadecimal characters.');
  const props=PropertiesService.getScriptProperties();
  // Validate credentials before writing settings, creating a tab or changing any cells.
  erfFetch_(url,key);
  const previous=props.getProperties();
  let sheet=previous.ERF_BOOK_ID===book.getId()?erfFindSheet_(book,previous.ERF_SHEET_ID):null;
  if(!sheet){
    if(book.getSheetByName('ERF Track'))throw new Error('An unmanaged tab named ERF Track exists. Rename it first to preserve its contents, then run setup again.');
    sheet=book.insertSheet('ERF Track');
  }
  props.setProperties({ERF_ORIGIN:url,ERF_KEY:key,ERF_BOOK_ID:book.getId(),ERF_SHEET_ID:String(sheet.getSheetId())});
  syncERFTrack();
  erfRemoveTriggers_();
  ScriptApp.newTrigger('syncERFTrack').timeBased().everyMinutes(5).create();
  ui.alert('Connected. Tick “Sync to Google Sheets” on the entries you want to share. Refresh runs about every 5 minutes. Keep manual notes in other tabs.');
}

function erfFindSheet_(book,id){return book.getSheets().find(s=>String(s.getSheetId())===String(id))||null;}
function erfFetch_(url,key){
  const response=UrlFetchApp.fetch(url+'/api/google-sheets/feed',{
    method:'get',headers:{'X-ERF-Sheets-Key':key},muteHttpExceptions:true,followRedirects:false
  });
  if(response.getResponseCode()!==200)throw new Error('ERF Track feed returned HTTP '+response.getResponseCode()+'. Check the app URL, connection key and hosting access rules. Existing rows have been kept.');
  let data;try{data=JSON.parse(response.getContentText());}catch(e){throw new Error('ERF Track returned an invalid feed. Existing rows have been kept.');}
  const expected=['Date','Task','Deadline','Deadline extended','Status','Reason / blocker'];
  if(!data||data.format!=='erf-management-tasks-v2'||JSON.stringify(data.columns)!==JSON.stringify(expected)||!Array.isArray(data.rows)||data.rows.length>5000||data.rows.some(row=>!Array.isArray(row)||row.length!==expected.length||row.some(cell=>typeof cell!=='string'))){
    throw new Error('Unsupported ERF Track feed. Existing rows have been kept.');
  }
  return data;
}

// Prevent task/client/notes text being interpreted as a spreadsheet formula.
function erfCell_(value){return /^[\s\u0000-\u0020]*[=+@-]/.test(value)?"'"+value:value;}

function syncERFTrack(){
  const lock=LockService.getScriptLock();if(!lock.tryLock(1000))return;
  try{
    const props=PropertiesService.getScriptProperties(),p=props.getProperties();
    if(!p.ERF_KEY||!p.ERF_BOOK_ID)throw new Error('Run setupERFTrack first.');
    const data=erfFetch_(p.ERF_ORIGIN,p.ERF_KEY),book=SpreadsheetApp.getActiveSpreadsheet();
    if(!book||book.getId()!==p.ERF_BOOK_ID)throw new Error('Run this script from its original bound spreadsheet.');
    const sheet=erfFindSheet_(book,p.ERF_SHEET_ID);
    if(!sheet)throw new Error('The managed ERF Track tab was deleted. Run setupERFTrack again.');
    const columns=data.columns.length,newRows=data.rows.length+1,height=Math.max(newRows,sheet.getLastRow(),2),width=Math.max(columns,sheet.getLastColumn());
    if(sheet.getMaxRows()<height)sheet.insertRowsAfter(sheet.getMaxRows(),height-sheet.getMaxRows());
    if(sheet.getMaxColumns()<columns)sheet.insertColumnsAfter(sheet.getMaxColumns(),columns-sheet.getMaxColumns());
    // One replacement write also blanks stale rows after unchecking. Never clear first.
    const values=[data.columns,...data.rows].map((row,index)=>row.map((value,col)=>index>0&&[0,2,3].includes(col)&&/^\d{4}-\d{2}-\d{2}$/.test(value)?new Date(value+'T00:00:00Z'):erfCell_(value)));
    values.forEach(row=>{while(row.length<width)row.push('');});
    while(values.length<height)values.push(Array(width).fill(''));
    sheet.getRange(1,1,height,width).setNumberFormat('@').setValues(values);
    if(width>columns)sheet.getRange(1,columns+1,height,width-columns).clearFormat();
    for(const col of [1,3,4])sheet.getRange(2,col,height-1,1).setNumberFormat('dd mmm yyyy');
    sheet.getRange(2,1,height-1,columns).setVerticalAlignment('top').setWrap(true);
    sheet.getRange(1,1,1,columns).setBackground('#255d48').setFontColor('#ffffff').setFontWeight('bold');
    sheet.setFrozenRows(1);sheet.setColumnWidths(1,columns,130);sheet.setColumnWidth(2,360);sheet.setColumnWidth(4,150);sheet.setColumnWidth(5,175);sheet.setColumnWidth(6,380);
    const statusRange=sheet.getRange(2,5,height-1,1);
    sheet.setConditionalFormatRules([['Overdue','#fce8e6','#a50e0e'],['Paused','#fef7e0','#8d6200'],['Done','#e6f4ea','#137333'],['Testing','#e8f0fe','#174ea6']].map(([text,bg,fg])=>SpreadsheetApp.newConditionalFormatRule().whenTextContains(text).setBackground(bg).setFontColor(fg).setRanges([statusRange]).build()));
    sheet.getRange(1,1).setNote('Last completed refresh: '+new Date().toISOString()+'\n'+data.rows.length+' selected entries. Date = added date. Deadline = original. Deadline extended = latest revision. Reasons show the current blocker or latest change, not full history. Edit tasks in ERF Track. Dates: '+data.timezone+'.');
    if(sheet.getFilter())sheet.getFilter().remove();sheet.getRange(1,1,Math.max(newRows,2),columns).createFilter();
    SpreadsheetApp.flush();props.setProperty('ERF_LAST_SUCCESS',new Date().toISOString());
  }finally{lock.releaseLock();}
}
function erfRemoveTriggers_(){ScriptApp.getProjectTriggers().filter(t=>t.getHandlerFunction()==='syncERFTrack').forEach(t=>ScriptApp.deleteTrigger(t));}
function stopERFTrack(){erfRemoveTriggers_();}
