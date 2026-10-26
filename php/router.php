<?php
// Development server only: php -S 127.0.0.1:8080 -t public php/router.php
$root=realpath(dirname(__DIR__).'/public');$file=realpath($root.'/'.rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)));
if($file&&str_starts_with($file,$root.DIRECTORY_SEPARATOR)&&is_file($file)&&pathinfo($file,PATHINFO_EXTENSION)!=='php')return false;
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/install.php'){require $root.'/install.php';return;}
require $root.'/index.php';
