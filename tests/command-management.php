<?php
define('ABSPATH',__DIR__);
$options=array('kab_site_uuid'=>'bb2a7d31-e161-4e78-9b99-3e08e659870e');
$target='https://komarena.sk';$allowed=true;$checks=array();$abilities=array();$backup_fail=false;$raw='original settings';$busy=false;$agent_busy=false;
function check($value,$message){if(!$value)throw new Exception($message);$GLOBALS['checks'][]=$message;}
function add_action(...$a){} function add_filter(...$a){}
function home_url(){return $GLOBALS['target'];}function site_url(){return home_url();}
function current_user_can($c){return $GLOBALS['allowed'];}function get_current_user_id(){return 3;}
function get_option($k,$default=false){return $GLOBALS['options'][$k]??$default;}
function add_option($k,$v,$deprecated='',$autoload=false){if(array_key_exists($k,$GLOBALS['options']) || ($GLOBALS['backup_fail'] && str_contains($k,'backup_')))return false;$GLOBALS['options'][$k]=$v;return true;}
function delete_option($k){unset($GLOBALS['options'][$k]);return true;}
function get_transient($k){return $GLOBALS['agent_busy'];}
function wp_generate_uuid4(){return '11111111-1111-1111-1111-111111111111';}
function wp_register_ability_category(...$a){}
function wp_register_ability($name,$args){$GLOBALS['abilities'][$name]=$args;}
function is_wp_error($r){return $r instanceof WP_Error;}
class WP_Error{public $message;function __construct($c,$m,$d){$this->message=$m;}}
class WP_REST_Request{public $params;function __construct($m){}function set_body_params($p){$this->params=$p;}}
class DB{public $options='wp_options';function prepare($s,...$a){return $s;}function get_var($sql){if(str_contains($sql,'GET_LOCK'))return $GLOBALS['busy']?'0':'1';if(str_contains($sql,'RELEASE_LOCK'))return '1';return $GLOBALS['raw'];}}
$wpdb=new DB();
class Settings{function all(){return KomArena_PF_Command_Management::settings(array('agent_autopilot'=>1,'auto_publish'=>1,'default_stock'=>10));}}
class Counts{public $values=array();function counts(){return $this->values;}}
class KomArena_Product_Factory{public $settings;public $queue;public $tasks;static $self;static function instance(){if(!self::$self){self::$self=new self();self::$self->settings=new Settings();self::$self->queue=new Counts();self::$self->tasks=new Counts();}return self::$self;}}
class KomArena_PF_Supplier_Stock{static function dispatch($name,$r){return array('action'=>$name,'input'=>$r->params);}}
require __DIR__.'/../includes/class-komarena-pf-command-management.php';
function run_command($name,$args=array()){return KomArena_PF_Command_Management::execute($name,array_merge(array('target'=>'https://komarena.sk'),$args));}
KomArena_PF_Command_Management::abilities();
check(count($abilities)===5,'Five bounded abilities registered');
foreach($abilities as $a){check($a['input_schema']['additionalProperties']===false && in_array('target',$a['input_schema']['required'],true),'Exact target required, extra fields rejected');check($a['permission_callback']===array('KomArena_PF_Command_Management','permission'),'All abilities permission-protected');}
check(!KomArena_PF_Command_Management::permission(array()),'Missing target denied');
$target='https://example.org';check(is_wp_error(run_command('supplier-stock-read',array('id'=>1))),'Wrong domain denied before private access');$target='https://komarena.sk';
$options['kab_site_uuid']='wrong';check(is_wp_error(run_command('command-mode-enable')),'Wrong site UUID denied');$options['kab_site_uuid']=KomArena_PF_Command_Management::UUID;
$allowed=false;check(is_wp_error(run_command('management-status')),'Unauthorized status denied');$allowed=true;
check(is_wp_error(run_command('arbitrary-code')),'Unknown action denied');
$before=$options;check(run_command('management-status')['mode']==='legacy_autopilot_settings' && $before===$options,'Status read does not mutate settings');
$busy=true;check(is_wp_error(run_command('command-mode-enable')),'Concurrent mode write refused');$busy=false;
$agent_busy=true;check(is_wp_error(run_command('command-mode-enable')),'Running legacy agent blocks enable');$agent_busy=false;
KomArena_Product_Factory::instance()->queue->values=array('waiting'=>1);check(is_wp_error(run_command('command-mode-enable')),'Queued work not silently adopted');KomArena_Product_Factory::instance()->queue->values=array();
$backup_fail=true;check(is_wp_error(run_command('command-mode-enable')) && !KomArena_PF_Command_Management::enabled(),'Failed backup prevents mode write');$backup_fail=false;
$result=run_command('command-mode-enable');check(!is_wp_error($result) && $result['mode']==='commands_only','Enable mode succeeds');
check($raw==='original settings','Original settings untouched');
$s=KomArena_PF_Command_Management::settings(array('default_stock'=>10,'agent_autopilot'=>1,'auto_publish'=>1));
check($s['agent_autopilot']===0 && $s['agent_task_autopilot']===0 && $s['auto_publish']===0 && $s['agent_repair_live_products']===0,'Unsolicited schedules/publishing/repairs suppressed');
check($s['default_stock']===10,'Unrelated settings preserved');
check(KomArena_PF_Command_Management::settings(false)['agent_autopilot']===0,'Missing settings fail closed in command mode');
$target='https://example.org';check(KomArena_PF_Command_Management::settings(array('agent_autopilot'=>1))['agent_autopilot']===1,'Other sites untouched');$target='https://komarena.sk';
$snapshot=$options;check(run_command('command-mode-enable')===$result && $snapshot===$options,'Enable retry idempotent');
$read=run_command('supplier-stock-read',array('id'=>5096));check($read['action']==='read' && $read['input']['id']===5096,'Private read reuses existing engine');
$update=array('id'=>5096,'quantity'=>0,'source_timestamp'=>123,'expected_hash'=>str_repeat('a',64));$u=run_command('supplier-stock-update',$update);
check($u['action']==='update' && $u['input']===array_merge(array('target'=>'https://komarena.sk'),$update),'Update preserves zero and forwards concurrency/source evidence to existing transactional validation');
$restore=array('backup_id'=>$result['backup_id'],'expected_hash'=>$result['mode_hash']);
check(is_wp_error(run_command('command-mode-restore',array_merge($restore,array('expected_hash'=>'bad')))),'Restore rejects stale mode hash');
$raw='intervening settings';check(is_wp_error(run_command('command-mode-restore',$restore)) && KomArena_PF_Command_Management::enabled(),'Restore rejects intervening settings changes');$raw='original settings';
$restored=run_command('command-mode-restore',$restore);check(!is_wp_error($restored) && !KomArena_PF_Command_Management::enabled(),'Exact backup restores previous mode');
check(KomArena_PF_Command_Management::settings(array('agent_autopilot'=>1))['agent_autopilot']===1,'Original behavior restored without rewriting settings');
echo json_encode(array('status'=>'PASS','checks'=>count($checks),'details'=>$checks),JSON_PRETTY_PRINT);
