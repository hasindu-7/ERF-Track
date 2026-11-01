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
    $board=rf_normalize($board);$projects=array_column($board['projects'],'name','id');$members=array_column($board['members'],'name','id');
    $selected=array_filter($board['tasks'],fn($t)=>$t['sheetSync']===true);$ids=array_column($selected,'id');
    $states=['pending'=>'Pending','working'=>'Working on','up_for_testing'=>'Up for testing','testing'=>'Testing','done'=>'Done'];$rows=[];
    foreach($selected as $t){
        // Never expose an unselected parent's name, notes, or identifier.
        $rows[]=[$t['id'],in_array($t['parentId'],$ids,true)?$t['parentId']:'',$projects[$t['projectId']]??'',ucfirst($t['environment']),ucfirst($t['category']),$t['name'],ucfirst($t['origin']),$t['client'],$members[$t['ownerId']]??'',$states[$t['status']]??$t['status'],$t['paused']?'Yes':'No',rf_day($t['addedAt']),$t['startedAt']?rf_day($t['startedAt']):'',$t['deadline']??'',$t['completedAt']?rf_day($t['completedAt']):'',$t['notes']];
    }
    return ['format'=>'erf-selected-tasks-v1','revision'=>$revision,'generatedAt'=>rf_now(),'timezone'=>RF_ZONE,'columns'=>['Task ID','Parent task ID','Project','Environment','Category','Task','Origin','Client','Owner','Status','Paused','Added','Started','Deadline','Completed','Notes'],'rows'=>array_values($rows)];
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
