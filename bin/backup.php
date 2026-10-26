<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/php/app.php';
try{if(rf_mysql(rf_db()))throw new InputError('Use cPanel Backup or phpMyAdmin for MySQL backups.');$folder=rf_config()['data'].'/backups';umask(0077);if(!is_dir($folder))mkdir($folder,0700,true);$name=gmdate('Y-m-d\TH-i-s').'-'.bin2hex(random_bytes(3)).'.sqlite';$q=rf_db()->prepare('VACUUM INTO ?');$q->execute([$folder.'/'.$name]);$files=glob($folder.'/*.sqlite');sort($files);foreach(array_slice($files,0,max(0,count($files)-14)) as $file)if(dirname($file)===$folder)unlink($file);echo "Consistent database backup created.\n";}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
