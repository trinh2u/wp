<?php
/** Plugin Name: WP Security Kit Monitor
 * Description: Baseline monitoring with file diffs and signed Telegram review links.
 * Version: 2.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

const WPSK_MONITOR_HOOK='wpsk_security_monitor_scan', WPSK_MONITOR_BASELINE='wpsk_security_monitor_baseline', WPSK_MONITOR_PENDING='wpsk_security_monitor_pending', WPSK_MONITOR_HISTORY='wpsk_security_monitor_history', WPSK_MONITOR_NOTICES='wpsk_security_monitor_notices', WPSK_MONITOR_CODE_RUN='wpsk_security_monitor_last_code_scan', WPSK_MONITOR_AUDIT='wpsk_security_monitor_audit', WPSK_MONITOR_VERSION=2;

function wpsk_monitor_hash($v){return hash('sha256',serialize($v));}
function wpsk_monitor_history($code,$message,$severity='warning',$context=array()){
	$h=get_option(WPSK_MONITOR_HISTORY,array());$h=is_array($h)?$h:array();
	$h[]=array('time'=>time(),'code'=>sanitize_key($code),'severity'=>sanitize_key($severity),'message'=>sanitize_text_field($message),'context'=>is_array($context)?$context:array());
	update_option(WPSK_MONITOR_HISTORY,array_slice($h,-100),false);
}
function wpsk_monitor_alert($code,$message,$severity='warning',$context=array(),$options=array()){
	$fp=(string)($options['fingerprint']??hash('sha256',wp_json_encode(array($code,$message,$context))));
	$n=get_option(WPSK_MONITOR_NOTICES,array());$n=is_array($n)?$n:array();
	if(isset($n[$fp])&&!empty($options['once']))return false;$last=isset($n[$fp])?(int)$n[$fp]:0;
	if(empty($options['once'])&&time()-$last<HOUR_IN_SECONDS)return false;
	$host=wp_parse_url(home_url(),PHP_URL_HOST);$text=sprintf("[%s] %s\nSite: %s\nEvent: %s",strtoupper($severity),$message,$host,$code);$tg=array();
	if(!empty($options['url']))$tg['reply_markup']=array('inline_keyboard'=>array(array(array('text'=>'Xem & xác nhận','url'=>$options['url']))));
	if(function_exists('pfhd_tg_alert'))pfhd_tg_alert($text,$tg);else error_log('[WP Security Kit] '.$text);
	$n[$fp]=time();if(count($n)>200)$n=array_slice($n,-200,null,true);update_option(WPSK_MONITOR_NOTICES,$n,false);wpsk_monitor_history($code,$message,$severity,$context);return true;
}
function wpsk_monitor_admins(){
	$r=array();foreach(get_users(array('role'=>'administrator'))as $u)$r[(string)$u->ID]=array('login'=>(string)$u->user_login,'email'=>(string)$u->user_email,'registered'=>(string)$u->user_registered,'pass_hash'=>hash('sha256',(string)$u->user_pass));ksort($r);return $r;
}
function wpsk_monitor_apps(){
	$r=array();if(!class_exists('WP_Application_Passwords'))return $r;foreach(array_keys(wpsk_monitor_admins())as $uid)foreach(WP_Application_Passwords::get_user_application_passwords((int)$uid)as $a){$k=$uid.':'.(string)($a['uuid']??'');$r[$k]=array('user_id'=>(int)$uid,'uuid'=>(string)($a['uuid']??''),'name'=>(string)($a['name']??''),'created'=>(int)($a['created']??0),'last_used'=>isset($a['last_used'])?(int)$a['last_used']:null,'last_ip'=>(string)($a['last_ip']??''));}ksort($r);return $r;
}
function wpsk_monitor_homepage(){
	$id=(int)get_option('page_on_front',0);$r=array('show_on_front'=>(string)get_option('show_on_front','posts'),'page_on_front'=>$id,'page_for_posts'=>(int)get_option('page_for_posts',0));if($id){$p=get_post($id);$r['page']=$p?array('id'=>(int)$p->ID,'title'=>(string)$p->post_title,'status'=>(string)$p->post_status,'content_hash'=>hash('sha256',(string)$p->post_content)):null;}return $r;
}
function wpsk_monitor_cron_shape(){
	$r=array();$cron=_get_cron_array();foreach(is_array($cron)?$cron:array()as $hooks)foreach($hooks as $hook=>$events)foreach($events as $e){$s=(string)($e['schedule']??'');if($s===''||preg_match('/^wp_\d+_wc_privacy_cleanup_cron$/',(string)$hook))continue;$r[]=array($hook,$s,wpsk_monitor_hash($e['args']??array()));}sort($r);return $r;
}
function wpsk_monitor_php_code(){
	$files=array();foreach(array_unique(array(WP_PLUGIN_DIR,get_theme_root(),WPMU_PLUGIN_DIR))as $root){if(!is_dir($root))continue;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));foreach($it as $i){if(!$i->isFile()||!preg_match('/\.php$/i',$i->getFilename()))continue;$p=$i->getPathname();if(strpos($p,DIRECTORY_SEPARATOR.'.wp-security-kit-backup-')!==false)continue;$files[str_replace(ABSPATH,'',$p)]=array((int)$i->getSize(),hash_file('sha256',$p));}}ksort($files);return array('count'=>count($files),'hash'=>wpsk_monitor_hash($files),'files'=>$files);
}
function wpsk_monitor_snapshot($code=true){
	$s=array();foreach(array('siteurl','home','users_can_register','default_role','active_plugins','stylesheet','template','admin_email')as $n)$s[$n]=wpsk_monitor_hash(get_option($n,null));$r=array('version'=>WPSK_MONITOR_VERSION,'captured'=>time(),'homepage'=>wpsk_monitor_homepage(),'admins'=>wpsk_monitor_admins(),'application_passwords'=>wpsk_monitor_apps(),'sensitive_options'=>$s,'cron_shape'=>wpsk_monitor_cron_shape());if($code)$r['php_code']=wpsk_monitor_php_code();return $r;
}
function wpsk_monitor_code_diff($old,$new){
	$o=is_array($old['files']??null)?$old['files']:array();$n=is_array($new['files']??null)?$new['files']:array();$changed=array();foreach(array_intersect_key($n,$o)as $k=>$v)if($v!==$o[$k])$changed[]=$k;return array('added'=>array_values(array_keys(array_diff_key($n,$o))),'removed'=>array_values(array_keys(array_diff_key($o,$n))),'changed'=>$changed);
}
function wpsk_monitor_pending_id($p){$s=$p['snapshot']??array();unset($s['captured']);return hash('sha256',wp_json_encode(array($p['sections']??array(),$s,$p['details']??array())));}
function wpsk_monitor_site_secret(){
	$secret=(string)get_option('wpsk_security_monitor_site_secret','');if(strlen($secret)>=32)return $secret;
	$seed=defined('WPSK_APPROVAL_SECRET')?(string)WPSK_APPROVAL_SECRET:'';$secret=strlen($seed)>=32?hash_hmac('sha256',home_url(),$seed):wp_generate_password(64,true,true);update_option('wpsk_security_monitor_site_secret',$secret,false);return $secret;
}
function wpsk_monitor_sign($id,$exp){return hash_hmac('sha256',$id.'|'.(int)$exp.'|'.home_url(),wpsk_monitor_site_secret());}
function wpsk_monitor_review_url($p){$id=wpsk_monitor_pending_id($p);$exp=time()+DAY_IN_SECONDS;return add_query_arg(array('wpsk_review'=>1,'snapshot'=>$id,'expires'=>$exp,'sig'=>wpsk_monitor_sign($id,$exp)),home_url('/'));}
function wpsk_monitor_summary($sections,$details){
	$l=array('Thay đổi: '.implode(', ',$sections));if(isset($details['php_code']))foreach(array('added'=>'Thêm','removed'=>'Xóa','changed'=>'Sửa')as $k=>$label){$a=$details['php_code'][$k]??array();if($a){$l[]=$label.' PHP ('.count($a).'):';foreach(array_slice($a,0,8)as $f)$l[]='- '.$f;if(count($a)>8)$l[]='- … và '.(count($a)-8).' file khác';}}return implode("\n",$l);
}
function wpsk_monitor_scan(){
	$b=get_option(WPSK_MONITOR_BASELINE,array());$needs_manifest=!is_array($b)||empty($b['php_code']['files']);$scan=$needs_manifest||time()-(int)get_option(WPSK_MONITOR_CODE_RUN,0)>=6*HOUR_IN_SECONDS;$c=wpsk_monitor_snapshot($scan);if(!$scan&&isset($b['php_code']))$c['php_code']=$b['php_code'];if($scan)update_option(WPSK_MONITOR_CODE_RUN,time(),false);
	if(!is_array($b)||empty($b['version'])||(int)$b['version']<WPSK_MONITOR_VERSION||$needs_manifest){update_option(WPSK_MONITOR_BASELINE,$c,false);delete_option(WPSK_MONITOR_PENDING);wpsk_monitor_history('baseline_initialized','Security baseline initialized or migrated with complete PHP manifest','info');return;}
	$sec=array();$det=array();foreach(array('homepage','admins','application_passwords','sensitive_options','cron_shape')as $k)if(($b[$k]??null)!==($c[$k]??null))$sec[]=$k;if(($b['php_code']['hash']??null)!==($c['php_code']['hash']??null)){$sec[]='php_code';$det['php_code']=wpsk_monitor_code_diff($b['php_code']??array(),$c['php_code']??array());}
	if($sec){$p=array('time'=>time(),'sections'=>$sec,'snapshot'=>$c,'details'=>$det);$p['id']=wpsk_monitor_pending_id($p);update_option(WPSK_MONITOR_PENDING,$p,false);$sev=in_array('php_code',$sec,true)||in_array('admins',$sec,true)?'critical':'warning';wpsk_monitor_alert('baseline_changed',wpsk_monitor_summary($sec,$det),$sev,array('pending_id'=>$p['id']),array('fingerprint'=>'pending:'.$p['id'],'once'=>true,'url'=>wpsk_monitor_review_url($p)));}else delete_option(WPSK_MONITOR_PENDING);
	global $wpdb;$recent=$wpdb->get_results("SELECT ID,post_title,post_name FROM {$wpdb->posts} WHERE post_type='post' AND ((post_status IN ('publish','draft') AND post_date_gmt BETWEEN DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE) AND UTC_TIMESTAMP()) OR (post_status='future' AND post_modified_gmt BETWEEN DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE) AND UTC_TIMESTAMP())) ORDER BY ID DESC LIMIT 100",ARRAY_A);if(count($recent)>=5){$latest=(int)$recent[0]['ID'];wpsk_monitor_alert('post_burst',count($recent).' posts created or scheduled within 10 minutes','critical',array('latest_id'=>$latest),array('fingerprint'=>'post_burst:'.$latest,'once'=>true));}
}
function wpsk_monitor_approve($source='wp-admin'){
	$p=get_option(WPSK_MONITOR_PENDING,array());if(!is_array($p)||empty($p['snapshot']))return false;update_option(WPSK_MONITOR_BASELINE,$p['snapshot'],false);delete_option(WPSK_MONITOR_PENDING);$a=get_option(WPSK_MONITOR_AUDIT,array());$a=is_array($a)?$a:array();$a[]=array('time'=>time(),'action'=>'approve','source'=>sanitize_text_field($source),'snapshot'=>wpsk_monitor_pending_id($p),'ip'=>sanitize_text_field($_SERVER['REMOTE_ADDR']??''));update_option(WPSK_MONITOR_AUDIT,array_slice($a,-100),false);wpsk_monitor_history('baseline_approved','Security baseline approved via '.$source,'info');return true;
}
function wpsk_monitor_review_request(){
	if(empty($_GET['wpsk_review']))return;$p=get_option(WPSK_MONITOR_PENDING,array());$id=sanitize_text_field(wp_unslash($_GET['snapshot']??''));$exp=(int)($_GET['expires']??0);$sig=sanitize_text_field(wp_unslash($_GET['sig']??''));$valid=is_array($p)&&!empty($p['snapshot'])&&hash_equals(wpsk_monitor_pending_id($p),$id)&&$exp>=time()&&$exp<=time()+2*DAY_IN_SECONDS&&hash_equals(wpsk_monitor_sign($id,$exp),$sig);status_header($valid?200:403);nocache_headers();header('Content-Type: text/html; charset=utf-8');echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>WP Security Kit</title><style>body{font:16px system-ui;max-width:820px;margin:40px auto;padding:0 18px;color:#172033}pre{white-space:pre-wrap;background:#f3f5f7;padding:16px;border-radius:8px}button{background:#b42318;color:#fff;border:0;border-radius:6px;padding:12px 18px;font-weight:700}.ok{color:#067647}</style></head><body><h1>WP Security Kit</h1>';
	if(!$valid){echo '<p>Liên kết không hợp lệ, đã hết hạn hoặc snapshot đã được xử lý.</p></body></html>';exit;}if('POST'===($_SERVER['REQUEST_METHOD']??'')&&isset($_POST['confirm'])){wpsk_monitor_approve('signed-telegram-link');echo '<p class="ok">Đã duyệt đúng snapshot này làm baseline.</p></body></html>';exit;}echo '<h2>'.esc_html(wp_parse_url(home_url(),PHP_URL_HOST)).'</h2><pre>'.esc_html(wpsk_monitor_summary((array)$p['sections'],(array)($p['details']??array()))).'</pre><p>Chỉ xác nhận nếu các thay đổi trên là hợp lệ. Nếu site tiếp tục đổi, liên kết này sẽ tự vô hiệu.</p><form method="post"><button name="confirm" value="1">Xác nhận snapshot này</button></form></body></html>';exit;
}
add_action('template_redirect','wpsk_monitor_review_request',0);
add_filter('cron_schedules',function($s){$s['wpsk_five_minutes']=array('interval'=>300,'display'=>'Every five minutes');return $s;});add_action(WPSK_MONITOR_HOOK,'wpsk_monitor_scan');add_action('init',function(){if(!wp_next_scheduled(WPSK_MONITOR_HOOK))wp_schedule_event(time()+60,'wpsk_five_minutes',WPSK_MONITOR_HOOK);},20);
add_action('user_register',function($id){$u=get_userdata($id);if($u&&in_array('administrator',(array)$u->roles,true))wpsk_monitor_alert('admin_added','Administrator account created: '.$u->user_login,'critical',array('user_id'=>(int)$id));});add_action('set_user_role',function($id,$role){if($role==='administrator')wpsk_monitor_alert('admin_role_granted','Administrator role granted','critical',array('user_id'=>(int)$id));},10,2);add_action('wp_create_application_password',function($id,$item){wpsk_monitor_alert('application_password_added','Application Password created: '.(string)($item['name']??'(unnamed)'),'critical',array('user_id'=>(int)$id));},10,2);add_action('application_password_did_authenticate',function($u,$item){wpsk_monitor_alert('application_password_authenticated','Application Password authenticated: '.(string)($item['name']??'(unnamed)'),'warning',array('user_id'=>(int)$u->ID));},10,2);add_action('rest_after_insert_post',function($p,$r,$creating){if($creating)wpsk_monitor_alert('rest_post_created','Post created through REST API: '.$p->post_title,'warning',array('post_id'=>(int)$p->ID,'user_id'=>get_current_user_id()));},10,3);
add_action('admin_menu',function(){add_management_page('WP Security Kit','WP Security Kit','manage_options','wp-security-kit','wpsk_monitor_admin_page');});
function wpsk_monitor_admin_page(){if(!current_user_can('manage_options'))return;if(isset($_POST['wpsk_action'])&&$_POST['wpsk_action']==='approve'){check_admin_referer('wpsk_approve_baseline');wpsk_monitor_approve();echo '<div class="notice notice-success"><p>Security baseline approved.</p></div>';}$p=get_option(WPSK_MONITOR_PENDING,array());$h=array_reverse((array)get_option(WPSK_MONITOR_HISTORY,array()));echo '<div class="wrap"><h1>WP Security Kit</h1>';if($p){echo '<div class="notice notice-warning"><p>Unapproved security changes are pending.</p></div><pre>'.esc_html(wpsk_monitor_summary((array)$p['sections'],(array)($p['details']??array()))).'</pre><form method="post">';wp_nonce_field('wpsk_approve_baseline');echo '<input type="hidden" name="wpsk_action" value="approve">';submit_button('Approve current state as baseline','primary','submit',false);echo '</form>';}else echo '<p>No pending baseline changes.</p>';echo '<h2>Recent events</h2><table class="widefat striped"><thead><tr><th>Time</th><th>Severity</th><th>Event</th><th>Message</th></tr></thead><tbody>';foreach(array_slice($h,0,50)as $i)printf('<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td></tr>',esc_html(wp_date('Y-m-d H:i:s',(int)$i['time'])),esc_html($i['severity']),esc_html($i['code']),esc_html($i['message']));echo '</tbody></table></div>';}
