<?php
require '/wordpress/wp-load.php';
require_once '/wordpress/wp-admin/includes/plugin.php';
$slug='calico-jetengine-mpc-cpt-bridge';$file=$slug.'/'.$slug.'.php';
require_once WP_PLUGIN_DIR.'/'.$file;
use Calico_JMCB_Updater as U;
$checks=0;function check_update($v,$m){global $checks;if(!$v)throw new Exception('FAIL: '.$m);$checks++;echo "PASS: $m\n";}
$release=array('id'=>1,'tag_name'=>'v2.6.0','draft'=>false,'prerelease'=>false,'assets'=>array(array('name'=>$slug.'.zip','state'=>'uploaded','size'=>12345,'browser_download_url'=>U::REPO.'/releases/download/v2.6.0/'.$slug.'.zip')));
$parsed=U::parse($release);check_update($parsed['version']==='2.6.0','Stable release with exact asset accepted');
check_update(strpos($parsed['package'],'/download/v2.6.0/')!==false,'Package is version-pinned');
foreach(array(array_replace($release,array('draft'=>true)),array_replace($release,array('prerelease'=>true)),array_replace($release,array('tag_name'=>'v2.6.0-beta1')),array_replace($release,array('assets'=>array()))) as $bad){check_update(U::parse($bad)===false,'Incomplete or non-stable release rejected');}
$bad=$release;$bad['tag_name']=array('v2.6.0');check_update(U::parse($bad)===false,'Malformed tag payload rejected without throwing');
$bad=$release;$bad['assets'][0]['size']='invalid';check_update(U::parse($bad)===false,'Malformed asset size rejected');
$bad=$release;$bad['assets'][0]['browser_download_url']='https://example.test/plugin.zip';check_update(U::parse($bad)===false,'Foreign download URL rejected');
$bad=$release;$bad['assets'][0]['browser_download_url']=U::REPO.'/releases/download/v2.7.0/'.$slug.'.zip';check_update(U::parse($bad)===false,'Mismatched version asset rejected');
$bad=$release;$bad['assets'][0]['state']='new';check_update(U::parse($bad)===false,'Unfinished asset upload rejected');
$GLOBALS['updater_http_count']=0;$GLOBALS['updater_http_failure']=false;
$mock=function($pre,$args,$url)use($release){
 if($url!==U::API)return $pre;
 $GLOBALS['updater_http_count']++;
 check_update($args['sslverify']===true && $args['timeout']<=8 && $args['limit_response_size']<=524288,'Bounded HTTPS metadata request');
 if($GLOBALS['updater_http_failure'])return new WP_Error('unavailable','Fixture network failure');
 return array('headers'=>array(),'body'=>json_encode($release),'response'=>array('code'=>200,'message'=>'OK'),'cookies'=>array());
};add_filter('pre_http_request',$mock,10,3);
delete_site_transient(U::CACHE);check_update(U::release()===$parsed,'Metadata fetched through WordPress HTTP API');
U::release();check_update($GLOBALS['updater_http_count']===1,'Successful metadata response cached');
check_update(U::update(false,array('Version'=>'2.5.0'),$file,array())['version']==='2.6.0','Older installed version receives update');
check_update(U::update(false,array('Version'=>'2.6.0'),$file,array())===false,'Current version not offered again');
check_update(U::update(false,array('Version'=>'2.7.0'),$file,array())===false,'Downgrades not offered');
check_update(U::update('unchanged',array('Version'=>'1.0.0'),'other/other.php',array())==='unchanged','Other plugins unaffected');
$details=U::details(false,'plugin_information',(object)array('slug'=>$slug));check_update($details->version==='2.6.0'&&$details->download_link===$parsed['package'],'WordPress plugin information resolves stable release');
check_update(U::details('unchanged','plugin_information',(object)array('slug'=>'other'))==='unchanged','Other plugin details unaffected');
delete_site_transient(U::CACHE);$GLOBALS['updater_http_failure']=true;check_update(U::release()===false,'Network failure leaves no update instead of throwing');$count=$GLOBALS['updater_http_count'];U::release();check_update($GLOBALS['updater_http_count']===$count,'Failure response cached briefly');
$id=wp_create_user('updater-admin',wp_generate_password(),'updater@example.test');$u=new WP_User($id);$u->set_role('administrator');wp_set_current_user($id);$_GET['force-check']='1';U::refresh();unset($_GET['force-check']);check_update(get_site_transient(U::CACHE)===false,'WordPress Check again clears updater cache');
remove_filter('pre_http_request',$mock,10);delete_site_transient(U::CACHE);
$headers=get_plugin_data(WP_PLUGIN_DIR.'/'.$file,false,false);check_update($headers['UpdateURI']===U::REPO,'Plugin advertises matching Update URI');
check_update(has_filter('update_plugins_github.com',array('Calico_JMCB_Updater','update'))!==false,'Native hostname update hook registered');
echo "TOTAL $checks updater checks passed\n";
