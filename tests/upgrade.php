<?php
require '/wordpress/wp-load.php';
require_once '/wordpress/wp-admin/includes/plugin.php';
require_once '/wordpress/wp-admin/includes/update.php';
require_once '/wordpress/wp-admin/includes/file.php';
require_once '/wordpress/wp-admin/includes/class-wp-upgrader.php';
require_once '/wordpress/wp-admin/includes/template.php';
use Calico_JMCB_Updater as U;
$slug='calico-jetengine-mpc-cpt-bridge';$file=$slug.'/'.$slug.'.php';$checks=0;
function upgrade_ok($v,$m){global $checks;if(!$v)throw new Exception('FAIL: '.$m);$checks++;echo "PASS: $m\n";}
$test_admin=get_user_by('login','updater-admin');wp_set_current_user($test_admin->ID);
$headers=get_plugin_data(WP_PLUGIN_DIR.'/'.$file,false,false);upgrade_ok($headers['Version']==='2.5.0','Starts from isolated older-version fixture with updater bootstrapped');
$settings=get_option('calico_jmcb_allowed_fields');$events=get_posts(array('post_type'=>'events','numberposts'=>-1,'post_status'=>'any'));$meta=array();foreach($events as $p)$meta[$p->ID]=get_post_meta($p->ID);
$package=U::REPO.'/releases/download/v2.6.0/'.$slug.'.zip';
$release=array('id'=>1,'tag_name'=>'v2.6.0','draft'=>false,'prerelease'=>false,'assets'=>array(array('name'=>$slug.'.zip','state'=>'uploaded','size'=>filesize('/tmp/plugin.zip'),'browser_download_url'=>$package)));
add_filter('pre_http_request',function($pre,$args,$url)use($release,$package){
 if($url===U::API)return array('headers'=>array(),'body'=>json_encode($release),'response'=>array('code'=>200),'cookies'=>array());
 if($url===$package){if(!empty($args['stream']))copy('/tmp/plugin.zip',$args['filename']);return array('headers'=>array(),'body'=>'','response'=>array('code'=>200),'cookies'=>array());}
 if(strpos($url,'api.wordpress.org/plugins/update-check/')!==false)return array('headers'=>array(),'body'=>json_encode(array('plugins'=>array(),'no_update'=>array(),'translations'=>array())),'response'=>array('code'=>200),'cookies'=>array());
 return $pre;
},10,3);
delete_site_transient(U::CACHE);delete_site_transient('update_plugins');wp_clean_plugins_cache();wp_update_plugins();$offered=get_site_transient('update_plugins');
upgrade_ok(isset($offered->response[$file])&&$offered->response[$file]->new_version==='2.6.0','Real WordPress update check offers release');
upgrade_ok($offered->response[$file]->package===$package,'WordPress update uses exact release ZIP');
$upgrader=new Plugin_Upgrader(new Bulk_Plugin_Upgrader_Skin());ob_start();$result=$upgrader->bulk_upgrade(array($file));ob_end_clean();
upgrade_ok(is_array($result)&&!empty($result[$file])&&!is_wp_error($result[$file]),'Real Dashboard Updates bulk upgrader installs exact ZIP');
wp_clean_plugins_cache();$headers=get_plugin_data(WP_PLUGIN_DIR.'/'.$file,false,false);upgrade_ok($headers['Version']==='2.6.0','Installed plugin header upgraded');
upgrade_ok(is_plugin_active($file),'Plugin remains active under the same basename');
upgrade_ok(get_option('calico_jmcb_allowed_fields')===$settings,'Approved-field settings preserved');
foreach($meta as $id=>$values)upgrade_ok(get_post_meta($id)===$values,'Event metadata preserved: '.$id);
upgrade_ok(!is_dir(WP_PLUGIN_DIR.'/repo'),'No duplicate plugin directory created');
echo "TOTAL $checks upgrade checks passed\n";
