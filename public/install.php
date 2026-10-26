<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','1');
require is_file(__DIR__.'/app-private/php/app.php')?__DIR__.'/app-private/php/app.php':dirname(__DIR__).'/php/app.php';
require is_file(__DIR__.'/app-private/php/installer.php')?__DIR__.'/app-private/php/installer.php':dirname(__DIR__).'/php/installer.php';
