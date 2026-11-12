<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/sync.php';
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')gateway_render_error(405,'Method not allowed.');$location=gateway_require_location_session();gateway_require_csrf();
$type=(string)($_POST['event_action']??'');if(!in_array($type,['checkin.recorded','checkin.exception','checkin.no_show','checkin.unscheduled_arrival'],true))gateway_render_error(422,'Invalid check-in action.');
$recorded=(string)($_POST['recorded_at']??'');$date=DateTimeImmutable::createFromFormat('Y-m-d\TH:i',$recorded,new DateTimeZone(date_default_timezone_get()));
if(!$date)gateway_render_error(422,'A valid arrival or report time is required.');
$resourceId=(int)($_POST['expected_resource_id']??0);$resource=null;
if($resourceId>0){$stmt=gateway_db()->prepare('SELECT * FROM gateway_expected_resources WHERE id=? AND gateway_location_id=? AND publication_status="published" LIMIT 1');$stmt->execute([$resourceId,$location['id']]);$resource=$stmt->fetch();}
if($type==='checkin.recorded'&&!$resource)gateway_render_error(422,'Select a currently published expected resource.');
$notes=gateway_normalize_text((string)($_POST['notes']??''),2000);$identity=gateway_session_identity();$data=['gateway_location_id'=>(int)$location['id'],'recorder'=>['name'=>$identity['name'],'position'=>$identity['position'],'gateway_user_id'=>$identity['gateway_user_id'],'roles'=>$identity['roles']],'gateway_user'=>['gateway_user_id'=>$identity['gateway_user_id'],'display_name'=>$identity['name'],'role'=>$identity['position'],'roles'=>$identity['roles'],'gateway_location_id'=>(int)$location['id'],'authenticated_at'=>$identity['authenticated_at']],'recorded_at'=>$date->setTimezone(new DateTimeZone('UTC'))->format('c'),'expected_resource_id'=>$resource?(int)$resource['internal_expected_resource_id']:null,'reference_number'=>$resource?$resource['reference_number']:null,'provider_name'=>$resource?$resource['provider_name']:null];
if($type==='checkin.recorded'){
 $lead=gateway_normalize_text((string)($_POST['crew_lead']??''),160);$mobile=gateway_normalize_text((string)($_POST['mobile_phone']??''),50);$radio=gateway_normalize_text((string)($_POST['radio_call_sign']??''),100);$count=filter_var($_POST['personnel_count']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>10000]]);
 if($lead===''||$count===false||($mobile===''&&$radio===''))gateway_render_error(422,'Crew lead, personnel count, and either mobile phone or radio call sign are required.');
 $data+=['crew_lead'=>$lead,'mobile_phone'=>$mobile?:null,'radio_call_sign'=>$radio?:null,'email'=>gateway_normalize_text((string)($_POST['email']??''),254)?:null,'personnel_count'=>$count,'notes'=>$notes?:null];
}else{
 $description=gateway_normalize_text((string)($_POST['resource_description']??''),500);if($description===''||$notes==='')gateway_render_error(422,'Resource description and operational notes are required.');
 $data+=['resource_description'=>$description,'agency_owner'=>gateway_normalize_text((string)($_POST['agency_owner']??''),160)?:null,'person_spoken_to'=>gateway_normalize_text((string)($_POST['person_spoken_to']??''),160)?:null,'notes'=>$notes];
}
try{
 $pdo=gateway_db();$pdo->beginTransaction();
 if($type==='checkin.recorded'){
  $mark=$pdo->prepare("UPDATE gateway_expected_resources SET publication_status='checked_in',updated_at=NOW() WHERE id=? AND publication_status='published'");
  $mark->execute([(int)$resource['id']]);
  if($mark->rowCount()!==1)throw new RuntimeException('This resource has already been reported or is no longer available for check-in.');
 }
 $id=gateway_queue_event($type,(int)$location['incident_id'],(int)$location['internal_location_id'],$data);
 gateway_audit($type,(int)$location['id'],['outbound_event_id'=>$id,'expected_resource_id'=>$data['expected_resource_id']]);
 $pdo->commit();
 gateway_flash('success','Resource checked-in');header('Location: /checkin.php',true,303);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();gateway_log('submission_failure',['error'=>$e->getMessage()]);gateway_render_error(500,'The report could not be saved. Please notify the Resources Unit.');}
