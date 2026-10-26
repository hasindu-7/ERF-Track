<?php
declare(strict_types=1);
// This file is included by public/install.php; it is not an API endpoint.
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: no-referrer');
$https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['SERVER_PORT']??'')==='443';
$host=$_SERVER['HTTP_HOST']??'localhost';$local=in_array(parse_url('http://'.$host,PHP_URL_HOST),['localhost','127.0.0.1','::1'],true);
session_name('rf_setup');session_set_cookie_params(['httponly'=>true,'secure'=>$https,'samesite'=>'Strict','path'=>'/']);session_start();
$_SESSION['csrf']=$_SESSION['csrf']??bin2hex(random_bytes(24));$csrf=$_SESSION['csrf'];
$keyFile=dirname(__DIR__).'/setup-key.php';$expected=getenv('INSTALL_KEY_HASH')?: (is_file($keyFile)?require $keyFile:'');
$installed=is_file(rf_config_file());$error='';$success=false;$drivers=PDO::getAvailableDrivers();
$origin=($https?'https':'http').'://'.$host;$defaultData=rf_default_data();
if($_SERVER['REQUEST_METHOD']==='POST'&&!$installed){
    $lock=null;
    try{
        rf_require($https||$local,'Enable HTTPS for the subdomain before entering administrator credentials.');
        rf_require((int)($_SERVER['CONTENT_LENGTH']??0)<=15000,'Request too large.');
        rf_require(hash_equals($csrf,(string)($_POST['csrf']??'')),'This setup form expired. Reload the page.');
        rf_require(is_string($expected)&&strlen($expected)===64&&hash_equals($expected,hash('sha256',(string)($_POST['key']??''))),'Installation key is incorrect or setup is not enabled.');
        $lock=fopen(rf_config_file().'.install.lock','c');rf_require($lock!==false&&flock($lock,LOCK_EX),'Cannot lock setup.');rf_require(!is_file(rf_config_file()),'Already installed.');
        $driver=$_POST['driver']??'sqlite';rf_require(in_array($driver,['sqlite','mysql'],true)&&in_array($driver,$drivers,true),'Enable the required PDO database extension in cPanel.');
        $name=rf_text($_POST['name']??null,'Administrator name',80);$email=strtolower(rf_text($_POST['email']??null,'Email',200));rf_require(filter_var($email,FILTER_VALIDATE_EMAIL)!==false,'Enter a valid administrator email.');$password=$_POST['password']??'';rf_require(is_string($password)&&strlen($password)>=16&&strlen($password)<=72&&!str_contains($password,"\0"),'Use an administrator password of 16–72 bytes.');rf_require($password===($_POST['confirm']??null),'The administrator passwords do not match.');
        $values=['APP_ORIGIN'=>rf_text($_POST['origin']??null,'Public URL',250),'DB_DRIVER'=>$driver,'DATA_DIR'=>rf_optional($_POST['data']??null,'Private data directory',500)?:$defaultData,'ADMIN_NAME'=>$name,'ADMIN_EMAIL'=>$email,'ADMIN_PASSWORD'=>$password];
        if($driver==='mysql'){$values+=['DB_HOST'=>rf_text($_POST['dbHost']??null,'MySQL host',200),'DB_PORT'=>(string)(int)($_POST['dbPort']??3306),'DB_NAME'=>rf_text($_POST['dbName']??null,'Database name',100),'DB_USER'=>rf_text($_POST['dbUser']??null,'Database user',100),'DB_PASSWORD'=>(string)($_POST['dbPassword']??'')];}
        $c=rf_make_config($values);rf_require($local||$c['secure'],'The public URL must use HTTPS.');
        if($driver==='sqlite'){
            $path=str_replace('\\','/', $c['data']);$web=str_replace('\\','/',realpath($_SERVER['DOCUMENT_ROOT']??__DIR__)?:'');
            rf_require($web===''||!str_starts_with(rtrim($path,'/').'/',rtrim($web,'/').'/'),'Choose a private data folder outside the subdomain document root.');
            rf_require(preg_match('#(^|/)public_html(?:/|$)#',$path)!==1,'SQLite data must be outside public_html. Use the suggested private folder.');
        }
        $probe=rf_connect($c);$existing=0;try{$existing=(int)$probe->query('SELECT COUNT(*) FROM erf_users')->fetchColumn();}catch(PDOException $e){if(!str_contains($e->getMessage(),'no such table')&&!str_contains($e->getMessage(),"doesn't exist")&&$e->getCode()!=='42S02')throw $e;}rf_require($existing===0,'This database already contains an installation. Restore its private configuration instead of reinstalling.');unset($probe);
        rf_config($c);$db=rf_db();$db->prepare('UPDATE erf_users SET must_change=0 WHERE email=? AND role=?')->execute([$email,'admin']);unset($values['ADMIN_PASSWORD']);
        umask(0077);$tmp=rf_config_file().'.tmp-'.bin2hex(random_bytes(5));rf_require(file_put_contents($tmp,"<?php\n// Private configuration. Never publish this file.\nreturn ".var_export($values,true).";\n",LOCK_EX)!==false,'Cannot write private configuration.');chmod($tmp,0600);rf_require(rename($tmp,rf_config_file()),'Cannot save private configuration.');
        if(is_file($keyFile))unlink($keyFile);$success=true;$installed=true;unset($_SESSION['csrf']);
    }catch(InputError $e){$error=$e->getMessage();}catch(Throwable $e){error_log('ERF setup: '.$e->getMessage());$error='Setup could not finish. Check the database details, folder permissions and cPanel PHP error log.';}
    finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
}
$esc=fn(mixed $v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$value=fn(string $key,string $default='')=>$esc($_POST[$key]??$default);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install ERF Track</title><style>
*{box-sizing:border-box}body{margin:0;background:#f4f7f3;color:#263a34;font:14px/1.6 system-ui,sans-serif;padding:30px 16px}.setup{max-width:610px;margin:auto;background:white;border:1px solid #dfe7df;border-radius:16px;padding:30px}.logo{background:#255d48;color:white;border-radius:10px;width:38px;height:38px;display:grid;place-items:center;font-size:20px}h1{font-size:27px;letter-spacing:-.8px;margin:18px 0 8px}h2{font-size:15px;margin:24px 0 12px}p,small{color:#69796f}label{display:block;font-size:12px;margin:16px 0}input,select{display:block;width:100%;padding:11px;border:1px solid #d8e2d9;border-radius:7px;font:inherit;font-size:15px;margin-top:6px;background:white;color:#263a34}button,.go{display:inline-block;background:#255d48;color:white;padding:12px 18px;border:0;border-radius:8px;font:inherit;text-decoration:none;cursor:pointer}button{width:100%;margin-top:22px}button:disabled{opacity:.5;cursor:default}.error{background:#fff0eb;color:#a94436;padding:12px;border-radius:8px;margin:18px 0}.hint{padding:12px;background:#f4f7f3;border-radius:8px;font-size:12px}.row{display:grid;grid-template-columns:1fr 1fr;gap:14px}summary{cursor:pointer;font-size:13px}details{border-top:1px solid #e2e9e3;padding-top:12px;margin-top:20px}@media(max-width:500px){.setup{padding:22px}.row{display:block}input,select{font-size:16px}}
</style></head><body><main class="setup"><span class="logo">er</span>
<?php if($installed):?><h1><?=$success?'Your calendar is ready.':'ERF Track is installed.'?></h1><p><?=$success?'Sign in using the administrator email and password you just chose.':'The installer is locked. No installation settings can be changed here.'?></p><a href="/" class="go">Open ERF Track</a>
<?php else:?><h1>Set up your team calendar.</h1><p>PHP + your database. No terminal, Node.js or Composer required.</p>
<div class="hint">PHP <?= $esc(PHP_VERSION) ?> · Available database drivers: <?= $esc(implode(', ',$drivers)) ?></div>
<?php if(!$https&&!$local):?><div class="error">Enable SSL/AutoSSL in cPanel, then open this page over HTTPS before continuing.</div><?php endif?>
<?php if(!$expected):?><div class="error">Setup is not activated. Use the prepared cPanel release ZIP and its separate installation key.</div><?php endif?>
<?php if($error):?><div class="error" role="alert"><?=$esc($error)?></div><?php endif?>
<form method="post"><input type="hidden" name="csrf" value="<?=$esc($csrf)?>"><label>Installation key<input type="password" name="key" required autocomplete="off"></label><label>Public URL · no trailing slash<input name="origin" type="url" value="<?=$value('origin',$origin)?>" required></label>
<h2>Database</h2><label>Database type<select name="driver"><option value="sqlite" <?= !in_array('sqlite',$drivers,true)?'disabled':'' ?>>SQLite · simplest, no database account needed</option><option value="mysql" <?= !in_array('mysql',$drivers,true)?'disabled':'' ?> <?= ($_POST['driver']??(in_array('sqlite',$drivers,true)?'sqlite':'mysql'))==='mysql'?'selected':'' ?>>MySQL / MariaDB · use cPanel database details</option></select></label>
<details <?= ($_POST['driver']??'')==='mysql'?'open':'' ?>><summary>MySQL / MariaDB connection details</summary><p>Create a database and user in cPanel, add the user to the database with all privileges, and enter the full prefixed names here. Ignore these fields for SQLite.</p><div class="row"><label>Database host<input name="dbHost" value="<?=$value('dbHost','localhost')?>"></label><label>Port<input name="dbPort" type="number" min="1" max="65535" value="<?=$value('dbPort','3306')?>"></label></div><label>Database name<input name="dbName" value="<?=$value('dbName')?>"></label><label>Database user<input name="dbUser" value="<?=$value('dbUser')?>"></label><label>Database password<input type="password" name="dbPassword" autocomplete="off"></label></details>
<details><summary>Private SQLite data directory</summary><p>Use a private folder outside public_html. The suggested location is normally suitable for cPanel.</p><label>Data directory<input name="data" value="<?=$value('data',$defaultData)?>"></label></details>
<h2>Administrator</h2><label>Name<input name="name" value="<?=$value('name')?>" required maxlength="80" autocomplete="name"></label><label>Email<input name="email" type="email" value="<?=$value('email')?>" required autocomplete="username"></label><label>Password · 16–72 characters<input name="password" type="password" required minlength="16" maxlength="72" autocomplete="new-password"></label><label>Confirm password<input name="confirm" type="password" required minlength="16" maxlength="72" autocomplete="new-password"></label><button <?= !$expected||(!$https&&!$local)?'disabled':'' ?>>Install ERF Track</button></form>
<?php endif?></main></body></html>
