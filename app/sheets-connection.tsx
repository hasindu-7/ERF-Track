import {useEffect,useState} from 'react';
type Connection={enabled:boolean;createdAt:string|null;lastFetchedAt:string|null;selectedCount:number;feedUrl:string;key:string|null};
export default function SheetsConnection(){
 const [state,setState]=useState<Connection|null>(null),[key,setKey]=useState(''),[error,setError]=useState(''),[busy,setBusy]=useState(false),[replace,setReplace]=useState(false);
 async function request(action?:'rotate'|'disable'){
  setBusy(true);setError('');
  try{const r=await fetch('/api/google-sheets/settings',action?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action})}:{cache:'no-store'});const s=await r.json();if(!r.ok)throw new Error(s.error||'Could not load the Sheets connection.');setState(s);if(action)setKey(s.key||'');setReplace(false);}
  catch(e){setError(e instanceof Error?e.message:'Connection failed.');}finally{setBusy(false);}
 }
 useEffect(()=>{request();},[]);
 return <section className="sheets-connection"><h2>Only the tasks you choose.</h2><p>One-way reporting to Google Sheets, refreshed about every 5 minutes. Edit the calendar here; sheet edits do not come back.</p>{error&&<p className="form-error" role="alert">{error}</p>}
 {state?<><p><strong>{state.selectedCount} {state.selectedCount===1?'entry':'entries'} selected</strong> across all projects and environments.</p><p>{state.enabled?'Read-only connection enabled.':'No connection key created yet.'}</p>{state.lastFetchedAt&&<p className="helper">Last successful feed request: {new Date(state.lastFetchedAt).toLocaleString()}. Check the sheet header for its last completed refresh.</p>}
 <h3>1. Create your connection key</h3><p className="helper">Only an administrator can create this key. It can read selected entries only.</p>
 {!state.enabled?<button className="primary" disabled={busy} onClick={()=>request('rotate')}>Create connection key</button>:<div className="actions wrap"><button disabled={busy} onClick={()=>setReplace(!replace)}>Replace key</button><button disabled={busy} onClick={()=>request('disable')}>Disable connection</button></div>}
 {replace&&<div className="credential-note"><p>Replacing the key immediately stops the old one. Update the Google script with the new key to resume.</p><button disabled={busy} onClick={()=>request('rotate')}>Replace connection key now</button></div>}
 {key&&<div className="credential-note"><strong>Copy this key now. It is shown once.</strong><textarea style={{display:'block',width:'100%',boxSizing:'border-box',margin:'10px 0',padding:10,resize:'vertical'}} aria-label="Google Sheets connection key" readOnly rows={3} value={key} onFocus={e=>e.target.select()}/><small>Keep this private. Never paste it into a spreadsheet cell or share it with viewers. Closing this panel hides it.</small></div>}
 <h3>2. Add the refresh script to your sheet</h3><p>Open the new Google Sheet, choose Extensions → Apps Script, and replace the starter code with the downloaded script.</p><a href="/integrations/erf-track.gs" download="ERF-Track-Google-Sheets.gs">Download Google Sheets script</a>
 <h3>3. Run setupERFTrack once</h3><p>Save the script, run <code>setupERFTrack</code>, approve Google’s permissions, and return to the sheet for the setup prompts. Enter this app URL and the connection key.</p><label className="field"><span>App URL</span><input readOnly value={window.location.origin} onFocus={e=>e.target.select()}/></label><p className="helper">The script manages its own “ERF Track” tab. Keep manual notes in other tabs. It runs under the Google account that installs it.</p>
 <h3>4. Choose your tasks</h3><p>Open a task and tick “Sync to Google Sheets”. Select subtasks and Dev/Live entries separately. Unchecking removes its row on the next successful refresh.</p><p className="helper">Disabling the connection stops future reads; it does not erase rows already copied to Google. Sheet viewers can read the selected entries’ notes and client names. Only trusted editors should have access to the bound script and its key.</p></>:<p>Loading connection…</p>}
 </section>;
}
