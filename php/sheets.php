<?php
declare(strict_types=1);

// A separate read-only credential, never a calendar login or a URL parameter.
function rf_sheets_table(PDO $db):void {
    $db->exec(rf_mysql($db)
        ? "CREATE TABLE IF NOT EXISTS erf_integrations(id VARCHAR(64) PRIMARY KEY,token_hash CHAR(64) NOT NULL,created_at VARCHAR(30) NOT NULL,last_fetched_at VARCHAR(30) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        : 'CREATE TABLE IF NOT EXISTS erf_integrations(id TEXT PRIMARY KEY,token_hash TEXT NOT NULL,created_at TEXT NOT NULL,last_fetched_at TEXT NULL)');
}
function rf_sheets_connection(PDO $db):array|false {
    rf_sheets_table($db);
    return $db->query("SELECT token_hash,created_at,last_fetched_at FROM erf_integrations WHERE id='google_sheets'")->fetch();
}
function rf_sheets_payload(array $board,int $revision):array {
    $board=rf_normalize($board);$projects=array_column($board['projects'],'name','id');
    $selected=array_filter($board['tasks'],fn($t)=>$t['sheetSync']===true);
    $states=['pending'=>'Pending','working'=>'In progress','up_for_testing'=>'Ready for testing','testing'=>'Testing','done'=>'Done'];$rows=[];$today=rf_day();
    $names=array_count_values(array_column($selected,'name'));
    foreach($selected as $t){
        $extensions=array_values(array_filter($t['history'],fn($h)=>$h['type']==='deadline_extended'&&!empty($h['from'])));
        $original=$extensions?$extensions[0]['from']:($t['deadline']??'');
        $revised=$extensions?($t['deadline']??''):'';
        $reason='';
        if($t['paused'])foreach(array_reverse($t['pauses']) as $pause)if(!$pause['to']){$reason=$pause['reason'];break;}
        if($reason==='')foreach(array_reverse($t['history']) as $h)if(in_array($h['type'],['deadline_extended','deadline_set','reopened','status_changed'],true)&&trim($h['reason']??'')!==''){$reason=$h['reason'];break;}
        // Keep the management report concise; the full audit stays in the calendar.
        $reason=preg_replace('/\s+/u',' ',trim($reason))??'';if(preg_match('/^(.{177}).{4}/us',$reason,$short))$reason=$short[1].'...';
        $name=$t['name'];if(($names[$name]??0)>1)$name=($projects[$t['projectId']]??'Project').' / '.ucfirst($t['environment']).' - '.$name;
        $status=$t['paused']?'Paused':($states[$t['status']]??$t['status']);
        if($t['status']!=='done'&&!empty($t['deadline'])&&$t['deadline']<$today)$status='Overdue - '.$status;
        $rows[]=[rf_day($t['addedAt']),$name,$original,$revised,$status,$reason];
    }
    usort($rows,fn($a,$b)=>[$a[4]==='Done'?1:0,$a[3]?:$a[2]?:'9999-12-31',$a[1]]<=>[$b[4]==='Done'?1:0,$b[3]?:$b[2]?:'9999-12-31',$b[1]]);
    return ['format'=>'erf-management-tasks-v2','revision'=>$revision,'generatedAt'=>rf_now(),'timezone'=>RF_ZONE,'columns'=>['Date','Task','Deadline','Deadline extended','Status','Reason / blocker'],'rows'=>array_values($rows)];
}
function rf_sheets_feed(PDO $db):never {
    $connection=rf_sheets_connection($db);$key=$_SERVER['HTTP_X_ERF_SHEETS_KEY']??'';
    if(!$connection||!is_string($key)||preg_match('/^[a-f0-9]{64}$/D',$key)!==1||!hash_equals($connection['token_hash'],hash('sha256',$key)))rf_response(['error'=>'Invalid or disabled Sheets connection.'],401);
    $row=$db->query("SELECT document,revision FROM erf_boards WHERE id='main'")->fetch();
    $payload=rf_sheets_payload(json_decode($row['document'],true,512,JSON_THROW_ON_ERROR),(int)$row['revision']);
    $db->prepare("UPDATE erf_integrations SET last_fetched_at=? WHERE id='google_sheets' AND token_hash=?")->execute([rf_now(),$connection['token_hash']]);
    rf_response($payload);
}
function rf_sheets_settings(PDO $db,string $method):never {
    $key=null;
    if($method==='POST'){
        $p=rf_input();rf_require(in_array($p['action']??'', ['rotate','disable'],true),'Choose create/replace or disable.');rf_sheets_table($db);
        if($p['action']==='disable')$db->exec("DELETE FROM erf_integrations WHERE id='google_sheets'");
        else{
            $key=bin2hex(random_bytes(32));$hash=hash('sha256',$key);$now=rf_now();
            $sql=rf_mysql($db)?"INSERT INTO erf_integrations(id,token_hash,created_at) VALUES('google_sheets',?,?) ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash),created_at=VALUES(created_at),last_fetched_at=NULL":"INSERT INTO erf_integrations(id,token_hash,created_at) VALUES('google_sheets',?,?) ON CONFLICT(id) DO UPDATE SET token_hash=excluded.token_hash,created_at=excluded.created_at,last_fetched_at=NULL";
            $db->prepare($sql)->execute([$hash,$now]);
        }
    }
    $c=rf_sheets_connection($db);$row=$db->query("SELECT document FROM erf_boards WHERE id='main'")->fetch();$b=rf_normalize(json_decode($row['document'],true,512,JSON_THROW_ON_ERROR));
    rf_response(['enabled'=>(bool)$c,'createdAt'=>$c?$c['created_at']:null,'lastFetchedAt'=>$c?$c['last_fetched_at']:null,'selectedCount'=>count(array_filter($b['tasks'],fn($t)=>$t['sheetSync']===true)),'feedUrl'=>rf_config()['origin'].'/api/google-sheets/feed','key'=>$key]);
}
