<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','1');
require is_file(__DIR__.'/app-private/php/app.php')?__DIR__.'/app-private/php/app.php':dirname(__DIR__).'/php/app.php';
header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
try{
    if($path==='/healthz'){rf_db();rf_response(['ok'=>true,'runtime'=>'php']);}
    if(str_starts_with($path,'/api/'))rf_api($path);
    if(!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true))rf_response(['error'=>'Method not allowed.'],405);
    if(!is_file(__DIR__.'/index.html'))rf_response(['error'=>'Frontend assets missing. Use the complete release package.'],503);
    header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');if($_SERVER['REQUEST_METHOD']!=='HEAD')readfile(__DIR__.'/index.html');
}catch(InputError $e){rf_response(['error'=>$e->getMessage()],400);}catch(Throwable $e){error_log('ERF Track: '.$e->getMessage());rf_response(['error'=>'Server configuration or database error. Ask the administrator to check the PHP error log.'],503);}
