<?php
// Isolated contract tests; production read-back additionally checks real Woo and MySQL.
define('ABSPATH',__DIR__);define('ARRAY_A','ARRAY_A');
$db=array();$options=array();$hooks=array();$notices=array();$checks=array();$fail_id=0;$target='https://komarena.sk';$allowed=true;
function check($v,$message){global $checks;if(!$v){throw new Exception($message);} $checks[]=$message;}
function add_action($h,$cb,$p=10,$n=1){$GLOBALS['hooks'][$h]=$cb;}
function add_filter($h,$cb,$p=10,$n=1){add_action($h,$cb,$p,$n);}
function do_action($h,...$args){$GLOBALS['fired'][]=array($h,$args);}
function register_post_meta($a,$b,$c){$GLOBALS['registration']=$c;}
function register_rest_route($a,$b,$c){}
function current_user_can($c){return $GLOBALS['allowed'];}
function home_url(){return $GLOBALS['target'];}function site_url(){return home_url();}
function get_term($id,$t){return (object)array('name'=>'U dodávateľa','slug'=>'u-dodavatela');}
function has_term($id,$t,$p){return $p>=1 && $p<=58;}
function metadata_exists($type,$id,$key){return isset($GLOBALS['db'][$id]['meta'][$key]);}
function get_post_meta($id,$key='',$single=false){
 $m=$GLOBALS['db'][$id]['meta']??array();if($key===''){return $m;}
 return $single ? (isset($m[$key][0]) ? maybe_unserialize($m[$key][0]) : '') : ($m[$key]??array());
}
function update_post_meta($id,$key,$v){if($id===$GLOBALS['fail_id'])return false;$GLOBALS['db'][$id]['meta'][$key]=array(is_array($v)?serialize($v):(string)$v);return true;}
function delete_post_meta($id,$key){if($id===$GLOBALS['fail_id'])return false;unset($GLOBALS['db'][$id]['meta'][$key]);return true;}
function add_post_meta($id,$key,$v){$GLOBALS['db'][$id]['meta'][$key][]=is_array($v)?serialize($v):(string)$v;return true;}
function maybe_unserialize($v){if(is_string($v)&&strpos($v,'a:')===0)return unserialize($v);return $v;}
function wp_slash($v){return $v;}
function clean_post_cache($id){}function wp_cache_delete($id,$g){}function wc_delete_product_transients($id){}
function get_post($id,$format){return $GLOBALS['db'][$id]['post']??null;}
function get_posts($a){return range(1,58);}
function add_option($k,$v,$d='',$a=false){if(isset($GLOBALS['options'][$k]))return false;$GLOBALS['options'][$k]=$v;return true;}
function get_option($k){return $GLOBALS['options'][$k]??false;}
function wp_generate_uuid4(){return sprintf('00000000-0000-0000-0000-%012d',count($GLOBALS['options'])+1);}
function is_wp_error($e){return $e instanceof WP_Error;}
function wc_add_notice($m,$t){$GLOBALS['notices'][]=$m;}
function wc_format_stock_quantity_for_display($q,$p){return $q;}
function esc_html($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
class WP_Error{public $message;function __construct($c,$m,$d){$this->message=$m;}}
class Request{public $data;function __construct($a=array()){$this->data=$a+array('target'=>'https://komarena.sk');}function get_param($k){return $this->data[$k]??null;}}
class WC_Product{
 public $id;public $changes=array();function __construct($id){$this->id=$id;}function get_id(){return $this->id;}
 function get_stock_quantity($c='view'){$v=get_post_meta($this->id,'_stock',true);return $v===''?null:(int)$v;}
 function get_manage_stock($c='view'){return get_post_meta($this->id,'_manage_stock',true)==='yes';}
 function managing_stock(){return $this->get_manage_stock();}
 function get_backorders($c='view'){return get_post_meta($this->id,'_backorders',true);}
 function get_stock_status($c='view'){return get_post_meta($this->id,'_stock_status',true);}
 function get_status($c='view'){return $GLOBALS['db'][$this->id]['post']['post_status'];}
 function get_catalog_visibility($c='view'){return $this->id===2?'hidden':'visible';}
 function is_type($t){return $t==='simple';}
 function is_purchasable(){return KomArena_PF_Supplier_Stock::purchasable($this->get_status()==='publish',$this);}
 function is_in_stock(){return KomArena_PF_Supplier_Stock::in_stock($this->get_stock_status()==='instock',$this);}
 function __call($n,$args){if(strpos($n,'set_')===0){$this->changes[$n]=$args[0];return;}throw new Exception($n);}
}
function wc_get_product($id){return isset($GLOBALS['db'][$id])?new WC_Product($id):false;}
class FakeDB{
 public $posts='wp_posts',$postmeta='wp_postmeta',$term_relationships='wp_term_relationships',$prefix='wp_',$options='wp_options',$saved;
 function prepare($sql,...$args){return vsprintf(str_replace(array('%d','%s'),array('%d',"'%s'"),$sql),$args);}
 function get_results($sql,$format){return array();}
 function get_var($sql){return strpos($sql,'ENGINE')!==false?'InnoDB':1;}
 function get_row($sql,$format){preg_match('/product_id=(\d+)/',$sql,$m);return $GLOBALS['db'][(int)$m[1]]['lookup'];}
 function query($sql){
  if($sql==='START TRANSACTION')$this->saved=array($GLOBALS['db'],$GLOBALS['options']);
  if($sql==='ROLLBACK')list($GLOBALS['db'],$GLOBALS['options'])=$this->saved;
  return 1;
 }
 function update($table,$data,$where){$id=$where['product_id']??$where['ID'];if($table==='wp_posts'){$GLOBALS['db'][$id]['post']=array_merge($GLOBALS['db'][$id]['post'],$data);}else{$GLOBALS['db'][$id]['lookup']=$data;}return 1;}
}
$wpdb=new FakeDB();
function seed(){
 $GLOBALS['db']=array();$GLOBALS['options']=array();
 foreach(range(1,58) as $id){$q=$id<=42?10:0;$s=$q?'instock':'outofstock';
  $GLOBALS['db'][$id]=array('post'=>array('ID'=>$id,'post_type'=>'product','post_status'=>'publish','post_title'=>'Preserve – '.$id,'post_content'=>'Preserve content'),
   'meta'=>array('_stock'=>array((string)$q),'_manage_stock'=>array('yes'),'_stock_status'=>array($s),'_backorders'=>array('no'),'_price'=>array('19.99'),'_sku'=>array('KOM-'.$id),'_yoast_wpseo_title'=>array('SEO title')),
   'lookup'=>array('stock_quantity'=>$q,'stock_status'=>$s));
 }
}
require __DIR__.'/../includes/class-komarena-pf-supplier-stock.php';
$c='KomArena_PF_Supplier_Stock';seed();
foreach(array(0=>'Momentálne vypredané u dodávateľa',1=>'U dodávateľa – 1 ks',20=>'U dodávateľa – 20 ks',21=>'U dodávateľa – 10+ ks',999=>'U dodávateľa – 10+ ks') as $q=>$label){check($c::label($q)===$label,'label '.$q);}
$c::register_meta();check($registration['show_in_rest']===false,'Private registered meta');
$allowed=false;check(!$c::permission(),'Unauthorized access refused');$allowed=true;
check(is_wp_error($c::dispatch('plan',new Request(array('target'=>'https://other.example')))),'Wrong target refused');check(count($options)===0,'Wrong target made no backup/write');
$plan=$c::dispatch('plan',new Request());check(!is_wp_error($plan)&&$plan['backup_verified'],'Plan persists verified backup');
$before=$db;$req=new Request($plan);$fail_id=30;
$failed=$c::dispatch('apply',$req);check(is_wp_error($failed),'Injected mid-migration failure detected');check($db===$before,'All 58 roll back on partial failure');$fail_id=0;
$done=$c::dispatch('apply',$req);check(!is_wp_error($done)&&$done['verified']===58,'58 migrated and verified');
check(count($GLOBALS['fired'])===58,'All affected public pages purged after commit');
check($c::dispatch('apply',$req)===$done,'Idempotent apply');
foreach($done['items'] as $item){check($item['stock_quantity']===null&&$item['manage_stock']===false,'No physical supplier stock '.$item['id']);}
check(!$c::purchasable(true,wc_get_product(58))&&!wc_get_product(58)->is_in_stock(),'Zero cannot be purchased');
$product=wc_get_product(58);ob_start();$c::unavailable_notice();$notice=ob_get_clean();check(strpos($notice,'Momentálne vypredané u dodávateľa')!==false,'Zero availability rendered when Woo skips purchase template');
$product=wc_get_product(1);ob_start();$c::unavailable_notice();check(ob_get_clean()==='','Positive availability not duplicated');
check($c::maximum(-1,wc_get_product(1))===10,'Classic max quantity');
check(isset($hooks['woocommerce_store_api_product_quantity_maximum']),'Blocks maximum hook installed');
check($c::add_to_cart(true,1,11)===false,'Oversized add rejected');check($c::add_to_cart(true,58,1)===false,'Zero-stock add rejected');
check($c::add_to_cart(true,1,10)===true,'Within supplier stock accepted');check($c::purchasable(false,wc_get_product(1))===false,'Existing purchasing guard preserved');
$p=wc_get_product(1);$c::guard_save($p);check($p->changes===array('set_manage_stock'=>false,'set_stock_quantity'=>null,'set_backorders'=>'no','set_stock_status'=>'instock'),'Future Woo saves preserve separation');
$html='<p>U dodávateľa – 10+ ks</p><b>Keep pricing &amp; text</b>';
check($c::product_content($html,wc_get_product(58))==='<p>Momentálne vypredané u dodávateľa</p><b>Keep pricing &amp; text</b>','Legacy rendered availability updates without editing other HTML');
$valid=get_post_meta(1,$c::META,true);
foreach(array(null,array(),array_merge($valid,array('quantity'=>-1)),array_merge($valid,array('quantity'=>'10')),array_merge($valid,array('status'=>'unavailable')),array_merge($valid,array('source_timestamp'=>time()+5000))) as $bad){update_post_meta(1,$c::META,$bad);check(!$c::purchasable(true,wc_get_product(1)),'Invalid supplier data fails closed');}
update_post_meta(1,$c::META,$valid);
update_post_meta(1,'_price','20.99');check(is_wp_error($c::dispatch('rollback',$req)),'Rollback refuses intervening price changes');check(get_post_meta(1,'_price',true)==='20.99','Intervening edit preserved');update_post_meta(1,'_price','19.99');
$undo=$c::dispatch('rollback',$req);check(!is_wp_error($undo)&&$undo['restored']===58,'Explicit rollback verified');
foreach($db as &$row){ksort($row['meta']);}unset($row);foreach($before as &$row){ksort($row['meta']);}unset($row);
check($db===$before,'Exact original product data restored');
seed();$plan=$c::dispatch('plan',new Request());update_post_meta(1,'_stock',9);check(is_wp_error($c::dispatch('apply',new Request($plan))),'Pre-migration stock drift refused');check(!metadata_exists('post',1,$c::META),'Drift left supplier meta absent');
echo json_encode(array('status'=>'PASS','checks'=>count($checks),'tests'=>$checks),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
