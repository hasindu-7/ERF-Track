<?php
declare(strict_types=1);
require_once __DIR__.'/domain.php';

require_once __DIR__.'/storage.php';
require_once __DIR__.'/sheets.php';
function rf_response(mixed $data,int $status=200):never {http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);exit;}
function rf_input():array {$raw=file_get_contents('php://input',false,null,0,50001);rf_require($raw!==false&&strlen($raw)<=50000,'Request too large.');try{$v=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(JsonException){throw new InputError('Invalid JSON.');}rf_require(is_array($v)&&str_starts_with(ltrim($raw),'{'),'Invalid request.');return $v;}
function rf_cookie(string $token,bool $remember=false,bool $clear=false):void {setcookie('rf_session',$token,['expires'=>$clear?1:($remember?time()+30*86400:0),'path'=>'/','secure'=>rf_config()['secure'],'httponly'=>true,'samesite'=>'Lax']);}
function rf_user(PDO $db):?array {$token=$_COOKIE['rf_session']??'';if(!is_string($token)||preg_match('/^[a-f0-9]{64}$/D',$token)!==1)return null;$q=$db->prepare('SELECT u.*,s.token_hash FROM erf_sessions s JOIN erf_users u ON u.id=s.user_id WHERE s.token_hash=? AND s.expires_at>?');$q->execute([hash('sha256',$token),time()]);return $q->fetch()?:null;}
function rf_snapshot(PDO $db,array $user):array {
    rf_begin($db);try{
        $row=$db->query("SELECT document,revision FROM erf_boards WHERE id='main'".(rf_mysql($db)?' FOR UPDATE':''))->fetch();$old=json_decode($row['document'],true,512,JSON_THROW_ON_ERROR);$board=rf_normalize($old);$found=false;
        foreach($board['members'] as &$member)if($member['userId']===$user['id']||strtolower($member['email'])===$user['email']){$member['userId']=$user['id'];$found=true;break;}unset($member);
        if(!$found)$board['members'][]=['id'=>rf_id(),'name'=>$user['name'],'email'=>$user['email'],'userId'=>$user['id']];
        $revision=(int)$row['revision'];if($board!==$old){$revision++;$q=$db->prepare("UPDATE erf_boards SET document=?,revision=?,updated_at=? WHERE id='main'");$q->execute([json_encode($board,JSON_THROW_ON_ERROR),$revision,rf_now()]);}rf_commit($db);
        return ['board'=>$board,'revision'=>$revision,'user'=>['userId'=>$user['id'],'displayName'=>$user['name'],'email'=>$user['email'],'role'=>$user['role']],'today'=>rf_day()];
    }catch(Throwable $e){rf_rollback($db);throw $e;}
}
function rf_throttle(PDO $db,string $key,bool $fail=false):bool {
    $q=$db->prepare('SELECT count,until_at FROM erf_login_attempts WHERE attempt_key=?');$q->execute([$key]);$r=$q->fetch();if($r&&(int)$r['until_at']>time()&&(int)$r['count']>=8)return true;
    if($fail){$sql=rf_mysql($db)?'INSERT INTO erf_login_attempts(attempt_key,count,until_at) VALUES(?,1,?) ON DUPLICATE KEY UPDATE count=IF(until_at>?,count+1,1),until_at=IF(until_at>?,until_at,VALUES(until_at))':'INSERT INTO erf_login_attempts(attempt_key,count,until_at) VALUES(?,1,?) ON CONFLICT(attempt_key) DO UPDATE SET count=CASE WHEN until_at> ? THEN count+1 ELSE 1 END,until_at=CASE WHEN until_at> ? THEN until_at ELSE excluded.until_at END';$q=$db->prepare($sql);$q->execute([$key,time()+900,time(),time()]);}return false;
}
function rf_api(string $path):never {
    $method=$_SERVER['REQUEST_METHOD'];if(!in_array($method,['GET','POST'],true))rf_response(['error'=>'Method not allowed.'],405);
    if($method==='POST'){
        if(!str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json'))rf_response(['error'=>'JSON required.'],415);
        if(($_SERVER['HTTP_ORIGIN']??'')!==rf_config()['origin'])rf_response(['error'=>'Invalid request origin.'],403);
    }
    $db=rf_db();$db->prepare('DELETE FROM erf_sessions WHERE expires_at<?')->execute([time()]);$db->prepare('DELETE FROM erf_login_attempts WHERE until_at<?')->execute([time()]);
    if($path==='/api/google-sheets/feed'&&$method==='GET')rf_sheets_feed($db);
    if($path==='/api/login'&&$method==='POST'){
        $p=rf_input();$email=strtolower(rf_optional($p['email']??null,'Email',200));$password=$p['password']??'';$ip=$_SERVER['REMOTE_ADDR']??'unknown';$keys=[hash('sha256',$ip.'|'.$email),hash('sha256',$ip.'|*')];foreach($keys as $key)if(rf_throttle($db,$key))rf_response(['error'=>'Too many attempts. Try again in 15 minutes.'],429);
        rf_require(is_string($password)&&strlen($password)<=256,'Invalid email or password.');$q=$db->prepare('SELECT * FROM erf_users WHERE email=?');$q->execute([$email]);$user=$q->fetch();$dummy='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';$valid=password_verify($password,$user['password_hash']??$dummy);
        if(!$user||!$valid){foreach($keys as $key)rf_throttle($db,$key,true);rf_response(['error'=>'Invalid email or password.'],401);}
        foreach($keys as $key)$db->prepare('DELETE FROM erf_login_attempts WHERE attempt_key=?')->execute([$key]);$remember=($p['remember']??false)===true;$token=bin2hex(random_bytes(32));$q=$db->prepare('INSERT INTO erf_sessions(token_hash,user_id,expires_at) VALUES(?,?,?)');$q->execute([hash('sha256',$token),$user['id'],time()+($remember?30:1)*86400]);rf_cookie($token,$remember);rf_response(['ok'=>true,'mustChangePassword'=>(bool)$user['must_change']]);
    }
    $user=rf_user($db);if(!$user)rf_response(['error'=>'Please sign in.'],401);
    if($path==='/api/me'&&$method==='GET')rf_response(['name'=>$user['name'],'email'=>$user['email'],'role'=>$user['role'],'mustChangePassword'=>(bool)$user['must_change']]);
    if($path==='/api/logout'&&$method==='POST'){$db->prepare('DELETE FROM erf_sessions WHERE token_hash=?')->execute([$user['token_hash']]);rf_cookie('',false,true);rf_response(['ok'=>true]);}
    if($path==='/api/password'&&$method==='POST'){
        $p=rf_input();$old=$p['currentPassword']??'';$new=$p['password']??'';rf_require(is_string($new)&&strlen($new)>=12&&strlen($new)<=72&&!str_contains($new,"\0"),'Choose a password of 12–72 bytes.');$key='password:'.$user['id'];if(rf_throttle($db,$key))rf_response(['error'=>'Too many attempts. Try again in 15 minutes.'],429);
        if(!is_string($old)||!password_verify($old,$user['password_hash'])){rf_throttle($db,$key,true);throw new InputError('Current password is incorrect.');}rf_require($old!==$new,'Choose a different password.');rf_begin($db);try{$db->prepare('UPDATE erf_users SET password_hash=?,must_change=0 WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$user['id']]);$db->prepare('DELETE FROM erf_sessions WHERE user_id=? AND token_hash<>?')->execute([$user['id'],$user['token_hash']]);rf_commit($db);}catch(Throwable $e){rf_rollback($db);throw $e;}rf_response(['ok'=>true]);
    }
    if($user['must_change'])rf_response(['error'=>'Change your temporary password before continuing.'],403);
    if($path==='/api/google-sheets/settings'){if($user['role']!=='admin')rf_response(['error'=>'Only administrators can manage the Sheets connection.'],403);rf_sheets_settings($db,$method);}
    if($path==='/api/accounts'&&$method==='POST'){
        if($user['role']!=='admin')rf_response(['error'=>'Only administrators can create logins.'],403);$p=rf_input();$state=rf_snapshot($db,$user);$member=null;foreach($state['board']['members'] as $m)if($m['id']===($p['memberId']??null))$member=$m;rf_require($member!==null&&$member['email']!=='','Add a member with an email first.');$password=bin2hex(random_bytes(12));$newId=rf_id();
        try{$db->prepare('INSERT INTO erf_users(id,name,email,password_hash,role,must_change,created_at) VALUES(?,?,?,?,?,1,?)')->execute([$newId,$member['name'],strtolower($member['email']),password_hash($password,PASSWORD_DEFAULT),'member',rf_now()]);}catch(PDOException $e){if($e->getCode()==='23000')rf_response(['error'=>'This person already has a login.'],409);throw $e;}rf_snapshot($db,['id'=>$newId,'name'=>$member['name'],'email'=>strtolower($member['email']),'role'=>'member']);rf_response(['email'=>$member['email'],'password'=>$password],201);
    }
    if($path==='/api/board'){
        $state=rf_snapshot($db,$user);if($method==='GET')rf_response($state);$p=rf_input();rf_require(is_string($p['action']??null)&&is_array($p['payload']??null),'Invalid action.');if(($p['revision']??null)!==$state['revision'])rf_response(['error'=>'Someone updated the calendar. Latest changes were loaded; review and save again.'],409);$actor=null;foreach($state['board']['members'] as $m)if($m['userId']===$user['id'])$actor=$m;
        $board=rf_apply($state['board'],$p['action'],$p['payload'],$actor);$q=$db->prepare("UPDATE erf_boards SET document=?,revision=revision+1,updated_at=? WHERE id='main' AND revision=?");$q->execute([json_encode($board,JSON_THROW_ON_ERROR),rf_now(),$state['revision']]);if($q->rowCount()!==1)rf_response(['error'=>'Someone updated the calendar. Reload and try again.'],409);rf_response(array_merge($state,['board'=>$board,'revision'=>$state['revision']+1]));
    }
    rf_response(['error'=>'Not found.'],404);
}
