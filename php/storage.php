<?php
declare(strict_types=1);
function rf_config_file():string{return getenv('ERF_CONFIG_FILE')?:dirname(__DIR__).'/config.local.php';}
function rf_default_data():string {
    $base=str_replace('\\','/',dirname(__DIR__));
    if(preg_match('#^(.+?)/public_html(?:/|$)#',$base,$m))return $m[1].'/erf-track-data';
    if(preg_match('#^(/home/[^/]+)(?:/|$)#',$base,$m))return $m[1].'/erf-track-data';
    return basename($base)==='app-private'?dirname($base,2).'/erf-track-data':$base.'/data';
}
function rf_make_config(array $values):array {
    $origin=$values['APP_ORIGIN']??'http://127.0.0.1:8080';$url=parse_url($origin);
    if(!$url||!in_array($url['scheme']??'', ['http','https'],true)||empty($url['host'])||isset($url['path'])||isset($url['query'])||isset($url['fragment']))throw new RuntimeException('APP_ORIGIN must have no trailing slash.');
    $data=$values['DATA_DIR']??rf_default_data();$absolute=str_starts_with($data,'/')||(strlen($data)>2&&ctype_alpha($data[0])&&$data[1]===':'&&in_array($data[2],['/',chr(92)],true));if(!$absolute)$data=dirname(__DIR__).'/'.$data;
    $driver=$values['DB_DRIVER']??'sqlite';if(!in_array($driver,['sqlite','mysql'],true))throw new RuntimeException('Choose SQLite or MySQL.');
    return ['origin'=>$origin,'secure'=>str_starts_with($origin,'https://'),'data'=>$data,'driver'=>$driver,'dbHost'=>$values['DB_HOST']??'localhost','dbPort'=>(int)($values['DB_PORT']??3306),'dbName'=>$values['DB_NAME']??'','dbUser'=>$values['DB_USER']??'','dbPassword'=>$values['DB_PASSWORD']??'','adminEmail'=>strtolower($values['ADMIN_EMAIL']??'admin@example.com'),'adminName'=>$values['ADMIN_NAME']??'Administrator','adminPassword'=>$values['ADMIN_PASSWORD']??''];
}
function rf_config(?array $override=null):array {
    static $config=null;if($override!==null)return $config=$override;if($config!==null)return $config;
    $values=[];$file=rf_config_file();if(is_file($file)){$values=require $file;if(!is_array($values))throw new RuntimeException('Invalid private configuration.');}
    $env=dirname(__DIR__).'/.env';if(is_file($env))foreach(file($env,FILE_IGNORE_NEW_LINES) as $line){$line=trim($line);if($line===''||str_starts_with($line,'#'))continue;if(preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/',$line,$m)){$value=trim($m[2]);if(str_starts_with($value,'"')&&str_ends_with($value,'"')){$v=json_decode($value,true);$value=is_string($v)?$v:substr($value,1,-1);}elseif(str_starts_with($value,"'")&&str_ends_with($value,"'"))$value=substr($value,1,-1);$values[$m[1]]=$value;}}
    foreach(['APP_ORIGIN','DATA_DIR','DB_DRIVER','DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASSWORD','ADMIN_EMAIL','ADMIN_NAME','ADMIN_PASSWORD'] as $key)if(getenv($key)!==false)$values[$key]=getenv($key);
    return $config=rf_make_config($values);
}
function rf_mysql(PDO $db):bool{return $db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';}
function rf_begin(PDO $db):void{if(rf_mysql($db))$db->beginTransaction();else $db->exec('BEGIN IMMEDIATE');}
function rf_commit(PDO $db):void{if(rf_mysql($db))$db->commit();else $db->exec('COMMIT');}
function rf_rollback(PDO $db):void{if(rf_mysql($db)){if($db->inTransaction())$db->rollBack();}else $db->exec('ROLLBACK');}
function rf_connect(array $c):PDO {
    if($c['driver']==='sqlite'){
        umask(0077);if(!is_dir($c['data'])&&!mkdir($c['data'],0700,true))throw new RuntimeException('Cannot create the data folder.');
        $dsn='sqlite:'.$c['data'].'/erf-track.sqlite';$user=null;$password=null;
    }else{
        if(!preg_match('/^[A-Za-z0-9_.:-]+$/D',$c['dbHost'])||!preg_match('/^[A-Za-z0-9_]+$/D',$c['dbName'])||$c['dbPort']<1||$c['dbPort']>65535)throw new RuntimeException('Check the MySQL host, database name and port.');
        $dsn='mysql:host='.$c['dbHost'].';port='.$c['dbPort'].';dbname='.$c['dbName'].';charset=utf8mb4';$user=$c['dbUser'];$password=$c['dbPassword'];
    }
    return new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}
function rf_db():PDO {
    static $db=null;if($db)return $db;$c=rf_config();$db=rf_connect($c);
    if(rf_mysql($db)){
        $db->exec("CREATE TABLE IF NOT EXISTS erf_users(id VARCHAR(64) PRIMARY KEY,name VARCHAR(200) NOT NULL,email VARCHAR(200) NOT NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,role VARCHAR(20) NOT NULL DEFAULT 'member',must_change INTEGER NOT NULL DEFAULT 1,created_at VARCHAR(30) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS erf_sessions(token_hash CHAR(64) PRIMARY KEY,user_id VARCHAR(64) NOT NULL,expires_at BIGINT NOT NULL,FOREIGN KEY(user_id) REFERENCES erf_users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS erf_boards(id VARCHAR(64) PRIMARY KEY,revision INTEGER NOT NULL DEFAULT 0,document LONGTEXT NOT NULL,updated_at VARCHAR(30) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS erf_login_attempts(attempt_key VARCHAR(80) PRIMARY KEY,count INTEGER NOT NULL,until_at BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }else{
        $db->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON;');
        $db->exec("CREATE TABLE IF NOT EXISTS erf_users(id TEXT PRIMARY KEY,name TEXT NOT NULL,email TEXT NOT NULL UNIQUE,password_hash TEXT NOT NULL,role TEXT NOT NULL DEFAULT 'member',must_change INTEGER NOT NULL DEFAULT 1,created_at TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS erf_sessions(token_hash TEXT PRIMARY KEY,user_id TEXT NOT NULL REFERENCES erf_users(id) ON DELETE CASCADE,expires_at INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS erf_boards(id TEXT PRIMARY KEY,revision INTEGER NOT NULL DEFAULT 0,document TEXT NOT NULL,updated_at TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS erf_login_attempts(attempt_key TEXT PRIMARY KEY,count INTEGER NOT NULL,until_at INTEGER NOT NULL);");
    }
    rf_begin($db);try{
        if(!$db->query('SELECT id FROM erf_users LIMIT 1')->fetch()){
            if(strlen($c['adminPassword'])<16||strlen($c['adminPassword'])>72||str_contains($c['adminPassword'],"\0"))throw new RuntimeException('Configure a unique administrator password of 16–72 bytes.');
            if(!filter_var($c['adminEmail'],FILTER_VALIDATE_EMAIL))throw new RuntimeException('Configure a valid administrator email.');
            $q=$db->prepare('INSERT INTO erf_users(id,name,email,password_hash,role,must_change,created_at) VALUES(?,?,?,?,?,1,?)');$q->execute([rf_id(),$c['adminName'],$c['adminEmail'],password_hash($c['adminPassword'],PASSWORD_DEFAULT),'admin',rf_now()]);
        }
        $insert=rf_mysql($db)?'INSERT IGNORE':'INSERT OR IGNORE';$q=$db->prepare($insert.' INTO erf_boards(id,revision,document,updated_at) VALUES(?,0,?,?)');$q->execute(['main',json_encode(rf_empty_board(),JSON_THROW_ON_ERROR),rf_now()]);rf_commit($db);
    }catch(Throwable $e){rf_rollback($db);throw $e;}return $db;
}
