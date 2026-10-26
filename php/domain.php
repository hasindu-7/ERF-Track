<?php
declare(strict_types=1);

final class InputError extends RuntimeException {}
const RF_ZONE = 'Asia/Colombo';
const RF_STATES = ['pending','working','up_for_testing','testing','done'];
const RF_COLORS = ['#397a66','#7770a6','#b97932','#b65c55','#487f9b','#90688a'];
function rf_id(): string { return bin2hex(random_bytes(16)); }
function rf_now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
function rf_day(?string $at=null): string { return (new DateTimeImmutable($at ?? 'now'))->setTimezone(new DateTimeZone(RF_ZONE))->format('Y-m-d'); }
function rf_require(bool $ok,string $message): void { if(!$ok) throw new InputError($message); }
function rf_text(mixed $v,string $label,int $max=200,bool $required=true): string {
    rf_require(is_string($v),"$label is required."); $s=trim($v);
    rf_require(!$required||$s!=='',"$label is required.");
    rf_require(preg_match_all('/./us',$s) <= $max,"$label is too long."); return $s;
}
function rf_optional(mixed $v,string $label,int $max=8000): string { return $v===null?'':rf_text($v,$label,$max,false); }
function rf_date(mixed $v,string $label,bool $required=false): ?string {
    if($v===null||$v===''){rf_require(!$required,"$label is required.");return null;}
    rf_require(is_string($v)&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v)===1,"$label must be a valid date.");
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
    rf_require($d!==false&&$d->format('Y-m-d')===$v&&$v>='2000-01-01'&&$v<='2100-12-31',"$label must be a valid date.");return $v;
}
function rf_empty_board(): array { return ['schemaVersion'=>2,'projects'=>[['id'=>'erf','name'=>'Easy Retailflow','createdAt'=>rf_now()]],'tasks'=>[],'meetings'=>[],'members'=>[],'timezone'=>RF_ZONE]; }
function rf_normalize(array $b): array {
    $b['projects']=$b['projects']??[['id'=>'erf','name'=>'Easy Retailflow','createdAt'=>rf_now()]];
    $b['timezone']=RF_ZONE;$b['schemaVersion']=2;$b['members']=$b['members']??[];$b['tasks']=$b['tasks']??[];$b['meetings']=$b['meetings']??[];
    foreach($b['tasks'] as &$t){$old=$t['status'];$t['projectId']=$t['projectId']??'erf';$t['environment']=$t['environment']??'dev';$t['category']=$t['category']??'feature';$t['paused']=$t['paused']??($old==='paused');$t['status']=match($old){'not_started'=>'pending','in_progress','paused'=>'working',default=>$old};$t['sourceTaskId']=$t['sourceTaskId']??null;$t['liveTaskId']=$t['liveTaskId']??null;}unset($t);
    foreach($b['meetings'] as &$m){$m['projectId']=$m['projectId']??'erf';$m['environment']=$m['environment']??'dev';}unset($m);return $b;
}
function rf_apply(array $board,string $action,array $p,array $actor,?string $now=null): array {
    $b=rf_normalize($board);$now=$now??rf_now();$today=rf_day($now);
    $audit=fn(string $type,string $reason='',?string $from=null,?string $to=null)=>['id'=>rf_id(),'type'=>$type,'at'=>$now,'actorId'=>$actor['id'],'actorName'=>$actor['name'],'reason'=>$reason,'from'=>$from,'to'=>$to];
    $findTask=function(?string $id) use (&$b): int {foreach($b['tasks'] as $i=>$t)if($t['id']===$id)return $i;throw new InputError('Task no longer exists.');};
    $taskIndex=fn()=>$findTask(isset($p['id'])&&is_string($p['id'])?$p['id']:null);
    $owner=function(mixed $id) use (&$b): string {foreach($b['members'] as $m)if($m['id']===$id)return $id;throw new InputError('Choose a team member.');};
    $scope=function(array $data) use (&$b): array {$project=$data['projectId']??null;$environment=$data['environment']??null;rf_require(in_array($project,array_column($b['projects'],'id'),true),'Choose a project.');rf_require(in_array($environment,['dev','live'],true),'Choose Dev or Live.');return [$project,$environment];};
    $bounds=function(array $parent,?string $from,?string $end):void {rf_require(!$from||!$end||$from<=$end,'Planned start must be before the deadline.');rf_require(!$end||!$parent['deadline']||$end<=$parent['deadline'],'Extend the parent deadline first.');};
    $same=fn(array $a,array $c)=>$a['projectId']===$c['projectId']&&$a['environment']===$c['environment'];
    $children=function(string $id) use (&$b):array {return array_keys(array_filter($b['tasks'],fn($t)=>$t['parentId']===$id));};
    $promote=function(int $index) use (&$b,$audit,$now,$children):string {
        $source=$b['tasks'][$index];rf_require($source['environment']==='dev','Only Dev entries can be sent to Live.');
        $ids=$source['parentId']?[$index]:array_merge([$index],$children($source['id']));$map=[];$existing=[];
        foreach($ids as $i){$old=$b['tasks'][$i];foreach($b['tasks'] as $j=>$target)if($target['projectId']===$old['projectId']&&$target['environment']==='live'&&$target['sourceTaskId']===$old['id']){$existing[$old['id']]=$j;break;}$map[$old['id']]=isset($existing[$old['id']])?$b['tasks'][$existing[$old['id']]]['id']:rf_id();}
        rf_require(count($b['tasks'])+count($ids)-count($existing)<=5000,'Task limit reached.');
        foreach($ids as $i){$old=$b['tasks'][$i];$parentId=$old['parentId']?($map[$old['parentId']]??null):null;if($old['parentId']&&!$parentId)foreach($b['tasks'] as $parent)if($parent['id']===$old['parentId']){$parentId=$parent['liveTaskId'];break;}
            if(isset($existing[$old['id']])){if($parentId)$b['tasks'][$existing[$old['id']]]['parentId']=$parentId;$b['tasks'][$i]['liveTaskId']=$map[$old['id']];continue;}
            $copy=$old;$copy['id']=$map[$old['id']];$copy['parentId']=$parentId;$copy['environment']='live';$copy['sourceTaskId']=$old['id'];$copy['liveTaskId']=null;$copy['status']='pending';$copy['paused']=false;$copy['addedAt']=$now;$copy['startedAt']=null;$copy['completedAt']=null;$copy['plannedFrom']=null;$copy['pauses']=[];$copy['history']=[$audit('promoted_from_dev','Created for independent Live verification.')];$b['tasks'][]=$copy;$b['tasks'][$i]['liveTaskId']=$copy['id'];$b['tasks'][$i]['history'][]=$audit('sent_to_live','Live entry starts Pending; Dev history is retained.');
        }return $map[$source['id']];
    };
    if($action==='project.add'){
        $name=rf_text($p['name']??null,'Project name',100);rf_require(count($b['projects'])<50,'Project limit reached.');foreach($b['projects'] as $project)rf_require(strcasecmp($project['name'],$name)!==0,'That project already exists.');$b['projects'][]=['id'=>rf_id(),'name'=>$name,'createdAt'=>$now];
    }elseif($action==='member.add'){
        $name=rf_text($p['name']??null,'Name',80);$email=strtolower(rf_optional($p['email']??null,'Email',200));rf_require($email===''||filter_var($email,FILTER_VALIDATE_EMAIL)!==false,'Enter a valid email.');foreach($b['members'] as $m)rf_require($email===''||strtolower($m['email'])!==$email,'That email is already on the team.');rf_require(count($b['members'])<100,'Team limit reached.');$b['members'][]=['id'=>rf_id(),'name'=>$name,'email'=>$email,'userId'=>null];
    }elseif($action==='task.add'){
        rf_require(count($b['tasks'])<5000,'Task limit reached.');$parent=isset($p['parentId'])&&$p['parentId']!==null?$b['tasks'][$findTask($p['parentId'])]:null;
        if($parent){rf_require($parent['parentId']===null&&$parent['status']!=='done','Choose an unfinished top-level parent.');$project=$parent['projectId'];$environment=$parent['environment'];}else{[$project,$environment]=$scope($p);}
        $deadline=rf_date($p['deadline']??null,'Deadline',!$parent);$planned=rf_date($p['plannedFrom']??null,'Planned start');rf_require(!$deadline||$deadline>=$today,'A new deadline cannot be in the past.');if($parent)$bounds($parent,$planned,$deadline);else rf_require($planned===null,'Only subtasks have planned dates.');
        $category=$p['category']??$parent['category']??'feature';rf_require(in_array($category,['issue','feature'],true),'Choose Issue or Feature.');$origin=$parent['origin']??($p['origin']??'internal');rf_require(in_array($origin,['internal','client'],true),'Choose a valid origin.');
        $b['tasks'][]=['id'=>rf_id(),'parentId'=>$parent['id']??null,'projectId'=>$project,'environment'=>$environment,'category'=>$category,'name'=>rf_text($p['name']??null,'Task name',160),'origin'=>$origin,'client'=>$origin==='client'?($parent['client']??rf_text($p['client']??null,'Client name',100)):'','ownerId'=>$owner($p['ownerId']??$parent['ownerId']??null),'color'=>$parent['color']??(in_array($p['color']??'',RF_COLORS,true)?$p['color']:RF_COLORS[count($b['tasks'])%count(RF_COLORS)]),'notes'=>rf_optional($p['notes']??null,'Notes'),'addedAt'=>$now,'plannedFrom'=>$planned,'startedAt'=>null,'completedAt'=>null,'deadline'=>$deadline,'status'=>'pending','paused'=>false,'pauses'=>[],'history'=>[$audit('added')],'sourceTaskId'=>null,'liveTaskId'=>null];
    }elseif($action==='task.edit'){
        $i=$taskIndex();$t=&$b['tasks'][$i];$t['name']=rf_text($p['name']??null,'Task name',160);$t['notes']=rf_optional($p['notes']??null,'Notes');$t['ownerId']=$owner($p['ownerId']??null);$category=$p['category']??$t['category'];rf_require(in_array($category,['issue','feature'],true),'Choose a category.');rf_require(!$t['liveTaskId']&&!$t['sourceTaskId']||$category===$t['category'],'Category cannot change after linking Dev and Live.');$t['category']=$category;
        if($t['parentId']===null){$t['origin']=($p['origin']??'internal')==='client'?'client':'internal';$t['client']=$t['origin']==='client'?rf_text($p['client']??null,'Client name',100):'';$category=$p['category']??$t['category'];rf_require(in_array($category,['issue','feature'],true),'Choose a category.');rf_require(!$t['liveTaskId']&&!$t['sourceTaskId']||$category===$t['category'],'Category cannot change after linking Dev and Live.');$t['category']=$category;if(in_array($p['color']??'',RF_COLORS,true))$t['color']=$p['color'];foreach($children($t['id']) as $j){foreach(['origin','client','color'] as $key)$b['tasks'][$j][$key]=$t[$key];}}
        else{$planned=rf_date($p['plannedFrom']??null,'Planned start');$bounds($b['tasks'][$findTask($t['parentId'])],$planned,$t['deadline']);$t['plannedFrom']=$planned;}$t['history'][]=$audit('details_updated');unset($t);
    }elseif(in_array($action,['task.status','task.start','task.done','task.reopen'],true)){
        $i=$taskIndex();$target=match($action){'task.start','task.reopen'=>'working','task.done'=>'done',default=>$p['status']??''};rf_require(in_array($target,RF_STATES,true),'Choose a valid status.');$t=$b['tasks'][$i];rf_require(!$t['paused'],'Resume this task before changing status.');
        if($t['status']==='done'&&$target!=='done')$reason=rf_text($p['reason']??null,'Reopening reason',2000);else $reason='';
        if($t['parentId']&&$target!=='pending'){$pi=$findTask($t['parentId']);$parent=$b['tasks'][$pi];rf_require(!$parent['paused']&&$parent['status']!=='done','Resume or reopen the parent first.');if($parent['status']==='pending'){$b['tasks'][$pi]['status']='working';$b['tasks'][$pi]['startedAt']=$now;$b['tasks'][$pi]['history'][]=$audit('started','Started with its first subtask.');}}
        if($target==='done')foreach($children($t['id']) as $ci)rf_require($b['tasks'][$ci]['status']==='done','Complete unfinished subtasks first.');
        if($target!==$t['status']){$b['tasks'][$i]['status']=$target;if($target!=='pending'&&!$t['startedAt'])$b['tasks'][$i]['startedAt']=$now;$b['tasks'][$i]['completedAt']=$target==='done'?$now:null;$b['tasks'][$i]['history'][]=$audit($action==='task.reopen'?'reopened':'status_changed',$reason,$t['status'],$target);}
        if($target==='done'&&$t['environment']==='dev'&&$t['category']==='issue')$promote($i);
    }elseif($action==='task.promote'){
        $i=$taskIndex();$t=$b['tasks'][$i];rf_require($t['environment']==='dev','Only Dev entries can be sent to Live.');rf_require($t['category']==='feature'||$t['status']==='done','Dev issues are sent to Live when Done.');$promote($i);
    }elseif($action==='task.pause'){
        $i=$taskIndex();$t=$b['tasks'][$i];rf_require(!$t['paused']&&!in_array($t['status'],['pending','done'],true),'Only active work can be paused.');$reason=rf_text($p['reason']??null,'Pause reason',2000);$expected=rf_date($p['expectedResume']??null,'Expected resume date');rf_require(!$expected||$expected>=$today,'Expected resume cannot be in the past.');$waiting=rf_optional($p['waitingFor']??null,'Waiting for',100)?:null;if($waiting){$wait=$b['tasks'][$findTask($waiting)];rf_require($wait['id']!==$t['id']&&$wait['status']!=='done'&&$same($wait,$t),'Choose unfinished work in the same project and environment.');}
        $pauseId=rf_id();$pause=function(int $j,?string $parentPauseId=null)use(&$b,$i,$now,$reason,$actor,$waiting,$expected,$pauseId,$audit):void{$b['tasks'][$j]['paused']=true;$b['tasks'][$j]['pauses'][]=['id'=>$j===$i?$pauseId:rf_id(),'from'=>$now,'to'=>null,'reason'=>$reason,'actorId'=>$actor['id'],'actorName'=>$actor['name'],'waitingFor'=>$waiting,'expectedResume'=>$expected,'parentPauseId'=>$parentPauseId];$b['tasks'][$j]['history'][]=$audit('paused',$reason);};$pause($i);foreach($children($t['id']) as $j)if(!$b['tasks'][$j]['paused']&&!in_array($b['tasks'][$j]['status'],['pending','done'],true))$pause($j,$pauseId);
    }elseif($action==='task.resume'){
        $i=$taskIndex();$t=$b['tasks'][$i];rf_require($t['paused'],'This task is not paused.');if($t['parentId']){$parent=$b['tasks'][$findTask($t['parentId'])];rf_require(!$parent['paused']&&!in_array($parent['status'],['pending','done'],true),'Resume the parent first.');}$active=null;foreach($t['pauses'] as $pause)if(!$pause['to'])$active=$pause;rf_require($active!==null,'No active pause found.');$resume=function(int $j)use(&$b,$now,$audit):void{$b['tasks'][$j]['paused']=false;foreach($b['tasks'][$j]['pauses'] as &$p)if(!$p['to'])$p['to']=$now;unset($p);$b['tasks'][$j]['history'][]=$audit('resumed');};$resume($i);if(($p['resumeSubtasks']??false)===true)foreach($children($t['id']) as $j)foreach($b['tasks'][$j]['pauses'] as $pause)if(!$pause['to']&&($pause['parentPauseId']??null)===$active['id']){$resume($j);break;}
    }elseif($action==='task.extend'){
        $i=$taskIndex();$t=$b['tasks'][$i];rf_require($t['status']!=='done','Reopen this task before changing its deadline.');$deadline=rf_date($p['deadline']??null,'New deadline',true);rf_require($deadline>=$today&&(!$t['deadline']||$deadline>$t['deadline']),'Choose a future date later than the current deadline.');if($t['parentId'])$bounds($b['tasks'][$findTask($t['parentId'])],$t['plannedFrom'],$deadline);$b['tasks'][$i]['deadline']=$deadline;$b['tasks'][$i]['history'][]=$audit($t['deadline']?'deadline_extended':'deadline_set',rf_text($p['reason']??null,'Extension reason',2000),$t['deadline'],$deadline);
    }elseif(in_array($action,['meeting.add','meeting.edit'],true)){
        $day=rf_date($p['date']??null,'Meeting date',true);$time=rf_text($p['time']??null,'Time',5);rf_require(preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D',$time)===1,'Choose a valid meeting time.');$status=$p['status']??'scheduled';rf_require(in_array($status,['scheduled','held','cancelled'],true),'Choose a meeting status.');rf_require($status!=='held'||$day<=$today,'A future meeting cannot be Held.');$duration=($p['duration']??'')===''||$p['duration']===null?null:filter_var($p['duration'],FILTER_VALIDATE_INT);rf_require($duration===null||$duration!==false&&$duration>0&&$duration<=1440,'Duration must be 1–1440 minutes.');$fields=['title'=>rf_text($p['title']??null,'Meeting title',160),'date'=>$day,'time'=>$time,'duration'=>$duration,'notes'=>rf_optional($p['notes']??null,'Meeting notes',12000),'status'=>$status];
        if($action==='meeting.add'){[$project,$environment]=$scope($p);rf_require(count($b['meetings'])<5000,'Meeting limit reached.');$b['meetings'][]=array_merge($fields,['id'=>rf_id(),'projectId'=>$project,'environment'=>$environment,'createdAt'=>$now,'history'=>[$audit('meeting_added')]]);}else{$index=null;foreach($b['meetings'] as $j=>$m)if($m['id']===($p['id']??null))$index=$j;rf_require($index!==null,'Meeting no longer exists.');$b['meetings'][$index]=array_merge($b['meetings'][$index],$fields);$b['meetings'][$index]['history'][]=$audit('meeting_updated');}
    }else throw new InputError('Unknown action.');
    rf_require(strlen(json_encode($b,JSON_THROW_ON_ERROR))<1500000,'Workspace is full. Export your records.');return $b;
}
