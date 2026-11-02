<?php
require dirname(__DIR__).'/php/app.php';
$actor=['id'=>'owner','name'=>'Owner'];$b=rf_empty_board();$b['members']=[['id'=>'owner','name'=>'Private owner','email'=>'private@example.test','userId'=>null]];
$b=rf_apply($b,'task.add',['name'=>'Management task','notes'=>'SECRET NOTES','origin'=>'client','client'=>'SECRET CLIENT','ownerId'=>'owner','projectId'=>'erf','environment'=>'dev','deadline'=>'2099-01-01'],$actor,'2026-11-01T00:00:00Z');$id=$b['tasks'][0]['id'];
$apply=function($action,$p)use(&$b,$actor){$b=rf_apply($b,$action,$p,$actor,'2026-11-02T00:00:00Z');};
function same($a,$b){if($a!==$b)throw new RuntimeException('Expected '.json_encode($b).', received '.json_encode($a));}
$apply('task.sheet_sync',['id'=>$id,'enabled'=>true]);$out=rf_sheets_payload($b,1);same($out['columns'],['Date','Task','Deadline','Deadline extended','Status','Reason / blocker']);same($out['rows'][0],['2026-11-01','Management task','2099-01-01','','Pending','']);
$apply('task.extend',['id'=>$id,'deadline'=>'2099-02-01','reason'=>'Customer approval delayed']);$apply('task.extend',['id'=>$id,'deadline'=>'2099-03-01','reason'=>'Additional testing required']);$out=rf_sheets_payload($b,2);same($out['rows'][0][2],'2099-01-01');same($out['rows'][0][3],'2099-03-01');same($out['rows'][0][5],'Additional testing required');
$apply('task.start',['id'=>$id]);$apply('task.pause',['id'=>$id,'reason'=>'Waiting for customer access']);$out=rf_sheets_payload($b,3);same($out['rows'][0][4],'Paused');same($out['rows'][0][5],'Waiting for customer access');
$apply('task.resume',['id'=>$id]);$out=rf_sheets_payload($b,4);same($out['rows'][0][4],'In progress');same($out['rows'][0][5],'Additional testing required');
foreach(['SECRET NOTES','SECRET CLIENT','private@example.test','Private owner',$id] as $private)if(str_contains(json_encode($out),$private))throw new RuntimeException('Management feed contains excluded detail.');
$b['tasks'][0]['deadline']='2026-01-01';same(str_starts_with(rf_sheets_payload($b,5)['rows'][0][4],'Overdue'),true);
$b['tasks'][0]['status']='done';same(rf_sheets_payload($b,5)['rows'][0][4],'Done');
echo "PASS six-column management report: original/latest deadlines, current blocker, concise reason, typed-date input and excluded details.\n";
