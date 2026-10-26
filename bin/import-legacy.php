<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/php/app.php';
try{
    if($argc!==2)throw new InputError('Usage: php bin/import-legacy.php /private/path/calendar-backup.json');
    $raw=file_get_contents($argv[1]);rf_require($raw!==false&&strlen($raw)<=2000000,'Backup is missing or too large.');$v=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$b=$v['board']??$v;
    foreach(['tasks','meetings','members'] as $key)rf_require(isset($b[$key])&&is_array($b[$key]),'Invalid calendar backup.');$b=rf_normalize($b);
    foreach($b['members'] as &$m){rf_require(is_string($m['id']??null)&&is_string($m['name']??null)&&is_string($m['email']??null),'Invalid member record.');$m['userId']=null;}unset($m);
    foreach($b['tasks'] as $t){rf_require(is_string($t['id']??null)&&is_string($t['name']??null)&&in_array($t['status'],RF_STATES,true),'Invalid task record.');rf_require(in_array($t['ownerId'],array_column($b['members'],'id'),true),'Task owner is missing.');rf_require(in_array($t['projectId'],array_column($b['projects'],'id'),true),'Task project is missing.');}
    rf_require(strlen(json_encode($b,JSON_THROW_ON_ERROR))<1500000,'Backup exceeds workspace size.');$db=rf_db();rf_begin($db);try{$current=json_decode($db->query("SELECT document FROM erf_boards WHERE id='main'")->fetchColumn(),true);rf_require(count($current['tasks'])===0&&count($current['meetings'])===0&&count($current['projects']??[])<=1,'Import requires an empty destination calendar.');$q=$db->prepare("UPDATE erf_boards SET document=?,revision=revision+1,updated_at=? WHERE id='main'");$q->execute([json_encode($b,JSON_THROW_ON_ERROR),rf_now()]);rf_commit($db);}catch(Throwable $e){rf_rollback($db);throw $e;}
    echo "Calendar records imported. PHP login accounts are separate; recreate team logins and match members by email.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
