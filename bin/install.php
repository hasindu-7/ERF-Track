<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/php/app.php';
try{rf_db();echo "PHP database and initial administrator are ready. Sign in using the configured email and temporary password.\n";}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
