<?php
require '/wordpress/wp-load.php';
require_once '/wordpress/wp-admin/includes/plugin.php';
$file='calico-jetengine-mpc-cpt-bridge/calico-jetengine-mpc-cpt-bridge.php';
$activation=activate_plugin($file); if(is_wp_error($activation))throw new Exception($activation->get_error_message());
require_once '/wordpress/wp-content/plugins/'.$file;
use Calico_JetEngine_MPC_CPT_Bridge as B;
use Calico_JMCB_Advanced_Dates as A;
$checks=0;
function ok($test,$message){global $checks;if(!$test)throw new Exception('FAIL: '.$message);$checks++;echo "PASS: $message\n";}
function err($v){return is_wp_error($v);}
register_post_type('events',array('public'=>true,'supports'=>array('title','editor','thumbnail')));
$uid=wp_create_user('bridge-test',wp_generate_password(),'bridge@example.test'); $u=new WP_User($uid);$u->set_role('administrator');wp_set_current_user($uid);
update_option(B::OPT_TYPES,array('events')); $allowed=array('date','date__end_date','date__config','ordinary-date','text','show_begin','show_begin__end_date','show_begin__config');
update_option(B::OPT_FIELDS,array('events'=>$allowed)); update_option(B::OPT_FORMATS,array('events'=>array('date'=>'date_timestamp_utc','date__end_date'=>'date_timestamp_utc','ordinary-date'=>'date_timestamp_utc','date__config'=>'text')));
$cfg=array('date'=>'2026-12-04','time'=>'19:00','is_end_date'=>'1','end_date'=>'2026-12-04','end_time'=>'23:59');
ok(err(A::prepare(array('date__config'=>json_encode($cfg)),0,$allowed)),'Missing JetEngine fails closed before writes');
// Provider fixture follows native data.php update_field_with_value/add_field_data inspected on JetEngine 3.8.9.1.
// Native recurrence calculation is owned by JetEngine; this fixture supplies three occurrences.
class Bridge_Test_Provider {
 public $corrupt=false;
 function get_date_pairs_cache_key($id,$field){return 'jet_engine_advanced_date_field_pairs_'.$id.'_'.$field;}
 function update_field_with_value($value,$id,$field){
  delete_post_meta($id,$field);delete_post_meta($id,$field.'__end_date');wp_cache_delete($this->get_date_pairs_cache_key($id,$field),'jet-engine');
  if(!empty($value['dates']))$value['dates']=array_values($value['dates']);
  update_post_meta($id,$field.'__config',json_encode($value));
  $items=!empty($value['dates'])?$value['dates']:array($value);
  foreach($items as $entry){
   $start=strtotime($entry['date'].' '.($entry['time']??''));
   $end=!empty($entry['is_end_date'])?strtotime($entry['end_date'].' '.($entry['end_time']??'')):false;
   $count=!empty($value['is_recurring'])&&empty($value['dates'])?3:1;
   for($i=0;$i<$count;$i++){
    add_post_meta($id,$field,$this->corrupt?$start-19*3600:$start+$i*86400);
    if($end)add_post_meta($id,$field.'__end_date',$end+$i*86400);
   }
  }
 }
}
if (true) { class Jet_Engine_Advanced_Date_Field {
 public $data; static $instance;
 static function instance(){if(!self::$instance){self::$instance=new self;self::$instance->data=new Bridge_Test_Provider;}return self::$instance;}
}
}
function create_schedule($config,$more=array(),$status='publish'){
 return B::execute_create_item(array('post_type'=>'events','title'=>'Schedule test','status'=>$status,'fields'=>array_merge(array('date__config'=>json_encode($config)),$more)));
}
$created=create_schedule($cfg,array('date'=>'2026-12-04','date__end_date'=>'2026-12-04','text'=>'Keep copy'));
ok(!err($created),'Legacy date-only input with full schedule creates successfully'); $id=$created['id'];
ok($created['fields']['date']==='1796410800','Evening time regression: stores 7 PM, not midnight');
ok($created['fields']['date__end_date']==='1796428740','Evening time regression: stores 11:59 PM');
ok($created['status']==='publish','Publishes only after schedule verification');
ok(A::verify_all($id,$allowed)===true,'Saved raw timestamps agree with configuration');
ok($created['fields']['text']==='Keep copy','Unrelated supplied fields preserved');
$definitions=B::execute_list_types(); ok(strpos(json_encode($definitions),'complete JSON schedule')!==false,'Tool discovery explains complete schedule input');
$day=$cfg;$day['time']='12:00';$day['end_time']='23:00';$r=create_schedule($day);ok(!err($r)&&gmdate('H:i',(int)$r['fields']['date'])==='12:00','Midday time preserved');
$overnight=$cfg;$overnight['end_date']='2026-12-05';$overnight['end_time']='02:00';$r=create_schedule($overnight);ok(!err($r)&&$r['fields']['date__end_date']==='1796436000','Overnight end uses following calendar date');
$multi=$cfg;$multi['end_date']='2026-12-06';$multi['end_time']='17:00';$r=create_schedule($multi);ok(!err($r)&&gmdate('Y-m-d H:i',(int)$r['fields']['date__end_date'])==='2026-12-06 17:00','Multi-day end preserved');
$mid=$cfg;$mid['time']='00:00';$r=create_schedule($mid);ok(!err($r)&&gmdate('H:i',(int)$r['fields']['date'])==='00:00','Explicit midnight remains valid');
$manual=array('dates'=>array($cfg,array('date'=>'2026-12-07','time'=>'20:00','is_end_date'=>'0'),$overnight));$r=create_schedule($manual);ok(!err($r)&&count(get_post_meta($r['id'],'date',false))===3,'Manual occurrences retain multiple metadata rows');
ok(count(get_post_meta($r['id'],'date__end_date',false))===2,'Manual entries can mix end-date and no-end entries');
$rec=$cfg;$rec['is_recurring']='1';$rec['recurring']='daily';$r=create_schedule($rec);ok(!err($r)&&count(get_post_meta($r['id'],'date',false))===3,'Native recurrence provider retains all generated rows');
foreach(array(
 array_replace($rec,array('recurring_period'=>'0.5')),
 array_replace($rec,array('recurring'=>'weekly','week_days'=>array())),
 array_replace($rec,array('end'=>'after','end_after'=>1000000)),
 array_replace($rec,array('end'=>'on_date','end_after_date'=>'2099-01-01'))
) as $unsafe){ok(err(create_schedule($unsafe)),'Unsafe or unbounded recurrence rejected before native generation');}
foreach(array('UTC','America/Chicago','America/New_York','Europe/London') as $zone){update_option('timezone_string',$zone);$r=create_schedule($cfg);ok(!err($r)&&$r['fields']['date']==='1796410800','Wall-clock encoding does not apply site offset twice: '.$zone);}
$ordinary=B::execute_create_item(array('post_type'=>'events','title'=>'Date only','fields'=>array('ordinary-date'=>'2026-12-04')));ok(!err($ordinary)&&$ordinary['fields']['ordinary-date']==='1796342400','Ordinary date-only field keeps existing midnight convention');
$other=B::execute_create_item(array('post_type'=>'events','title'=>'Custom field','fields'=>array('show_begin__config'=>json_encode($cfg))));ok(!err($other)&&$other['fields']['show_begin']==='1796410800','Works with another approved Advanced Date field name');
$before=wp_count_posts('events')->publish;
foreach(array(array_replace($cfg,array('date'=>'2026-02-30')),array_replace($cfg,array('time'=>'25:00')),array_replace($cfg,array('end_time'=>'18:00')),array_replace($cfg,array('time'=>'7 PM'))) as $bad){ok(err(create_schedule($bad)),'Rejects invalid or reversed schedule before publication');}
ok(wp_count_posts('events')->publish===$before,'Rejected schedules do not publish');
ok(err(create_schedule($cfg,array('date'=>'1796342400'))),'Conflicting numeric midnight rejected');
ok(err(B::execute_update_item(array('post_type'=>'events','id'=>$id,'fields'=>array('date'=>'2026-12-05')))),'Independent Advanced Date timestamp update rejected');
ok(err(B::execute_update_item(array('post_type'=>'events','id'=>$id,'fields'=>array('date__config'=>'{broken')))),'Malformed JSON cannot erase an existing schedule');
ok(err(create_schedule(null,array('date'=>'2026-12-04'))),'New incomplete Advanced Date input rejected');
$r=B::execute_update_item(array('post_type'=>'events','id'=>$id,'expected_revision'=>$created['revision'],'fields'=>array('date__config'=>json_encode($day))));ok(!err($r)&&gmdate('H:i',(int)$r['fields']['date'])==='12:00','Config-only update regenerates timestamps');
ok(err(B::execute_update_item(array('post_type'=>'events','id'=>$id,'expected_revision'=>$created['revision'],'fields'=>array('text'=>'Stale edit')))),'Stale revision protection retained');
$mixed=create_schedule($manual); $mixed_id=$mixed['id'];
$pair_sql=$GLOBALS['wpdb']->prepare("SELECT meta_key,meta_value FROM {$GLOBALS['wpdb']->postmeta} WHERE post_id=%d AND meta_key IN ('date','date__end_date') ORDER BY meta_id",$mixed_id);
$rows_before=$GLOBALS['wpdb']->get_results($pair_sql,ARRAY_A);
Jet_Engine_Advanced_Date_Field::instance()->data->corrupt=true;
$bad_mixed=B::execute_update_item(array('post_type'=>'events','id'=>$mixed_id,'fields'=>array('date__config'=>json_encode($cfg))));
ok(err($bad_mixed)&&$GLOBALS['wpdb']->get_results($pair_sql,ARRAY_A)===$rows_before,'Rollback preserves interleaved mixed manual occurrence rows');
Jet_Engine_Advanced_Date_Field::instance()->data->corrupt=false;
$snapshot=get_post_meta($id);Jet_Engine_Advanced_Date_Field::instance()->data->corrupt=true;
$r=B::execute_update_item(array('post_type'=>'events','id'=>$id,'title'=>'Do not apply','fields'=>array('date__config'=>json_encode($cfg),'text'=>'Do not retain')));
ok(err($r),'Post-save timestamp mismatch detected');ok(get_post_meta($id)==$snapshot,'Failed update restores prior schedule and changed metadata');ok(get_post($id)->post_title==='Schedule test','Failed schedule does not apply title change');
$drafts=wp_count_posts('events')->draft;$r=create_schedule($cfg);ok(err($r)&&wp_count_posts('events')->draft===$drafts,'Failed create removes temporary incomplete draft');
Jet_Engine_Advanced_Date_Field::instance()->data->corrupt=false;
$bad_id=wp_insert_post(array('post_type'=>'events','post_status'=>'draft','post_title'=>'Inherited mismatch')); update_post_meta($bad_id,'date','1796342400');update_post_meta($bad_id,'date__end_date','1796342400');update_post_meta($bad_id,'date__config',json_encode($cfg));
$r=B::execute_update_item(array('post_type'=>'events','id'=>$bad_id,'status'=>'publish'));ok(err($r)&&get_post_status($bad_id)==='draft','Status-only publication blocked for inherited mismatch');
$r=B::execute_update_item(array('post_type'=>'events','id'=>$bad_id,'status'=>'publish','fields'=>array('date__config'=>json_encode($cfg))));ok(!err($r)&&get_post_status($bad_id)==='publish','Full schedule repair permits publication');
$reduced=array_diff($allowed,array('date__end_date'));update_option(B::OPT_FIELDS,array('events'=>array_values($reduced)));ok(err(create_schedule($cfg)),'Derived field approval is required; no permission expansion');update_option(B::OPT_FIELDS,array('events'=>$allowed));
$reader=wp_create_user('bridge-reader',wp_generate_password(),'reader@example.test');$reader_u=new WP_User($reader);$reader_u->set_role('subscriber');wp_set_current_user($reader);ok(err(create_schedule($cfg)),'Native user creation permissions retained');
echo "TOTAL $checks checks passed on WordPress ".get_bloginfo('version').', PHP '.PHP_VERSION."\n";
