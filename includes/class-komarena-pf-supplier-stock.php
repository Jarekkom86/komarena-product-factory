<?php
/** Private supplier inventory and bounded, reversible migration for KomArena. */
if (!defined('ABSPATH')) { exit; }

final class KomArena_PF_Supplier_Stock {
 const VERSION = '1.0.0';
 const META = '_komarena_pf_supplier_stock';
 const PREFIX = 'komarena_pf_supplier_plan_';
 const TARGET = 'https://komarena.sk';
 const TAG = 580;

 public static function boot() {
  add_action('init', array(__CLASS__, 'register_meta'));
  add_action('rest_api_init', array(__CLASS__, 'routes'));
  add_filter('woocommerce_get_availability_text', array(__CLASS__, 'availability'), PHP_INT_MAX, 2);
  add_action('woocommerce_single_product_summary', array(__CLASS__, 'unavailable_notice'), 29);
  add_filter('woocommerce_is_purchasable', array(__CLASS__, 'purchasable'), PHP_INT_MAX, 2);
  add_filter('woocommerce_product_is_in_stock', array(__CLASS__, 'in_stock'), PHP_INT_MAX, 2);
  add_filter('woocommerce_product_backorders_allowed', array(__CLASS__, 'backorders'), PHP_INT_MAX, 2);
  add_filter('woocommerce_quantity_input_max', array(__CLASS__, 'maximum'), PHP_INT_MAX, 2);
  add_filter('woocommerce_store_api_product_quantity_maximum', array(__CLASS__, 'maximum'), PHP_INT_MAX, 2);
  add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'add_to_cart'), PHP_INT_MAX, 6);
  add_filter('woocommerce_update_cart_validation', array(__CLASS__, 'update_cart'), PHP_INT_MAX, 4);
  add_action('woocommerce_check_cart_items', array(__CLASS__, 'check_cart'));
  add_action('woocommerce_before_product_object_save', array(__CLASS__, 'guard_save'), PHP_INT_MAX);
  add_filter('the_content', array(__CLASS__, 'content'), PHP_INT_MAX);
  add_filter('woocommerce_short_description', array(__CLASS__, 'content'), PHP_INT_MAX);
  add_filter('woocommerce_product_get_description', array(__CLASS__, 'product_content'), PHP_INT_MAX, 2);
  add_filter('woocommerce_product_get_short_description', array(__CLASS__, 'product_content'), PHP_INT_MAX, 2);
  add_filter('woocommerce_rest_prepare_product_object', array(__CLASS__, 'rest_privacy'), PHP_INT_MAX, 3);
 }

 public static function register_meta() {
  register_post_meta('product', self::META, array('type'=>'object', 'single'=>true, 'show_in_rest'=>false,
   'auth_callback'=>function(){ return current_user_can('manage_options'); }));
 }

 public static function is_supplier($product) {
  return $product && (has_term(self::TAG, 'product_tag', $product->get_id()) || metadata_exists('post', $product->get_id(), self::META));
 }

 public static function valid($s) {
  if (!is_array($s) || !isset($s['quantity'], $s['status'], $s['source_timestamp'], $s['observed_at'], $s['provenance'])) { return false; }
  if (!is_int($s['quantity']) || $s['quantity'] < 0 || !is_int($s['source_timestamp']) || $s['source_timestamp'] < 0 || $s['source_timestamp'] > time()+300) { return false; }
  if (!is_int($s['observed_at']) || $s['observed_at'] <= 0 || $s['observed_at'] > time()+300) { return false; }
  if (!in_array($s['provenance'], array('legacy_woo_snapshot','supplier_refresh'), true)) { return false; }
  if ('supplier_refresh' === $s['provenance'] && $s['source_timestamp'] <= 0) { return false; }
  return ($s['quantity'] > 0 && $s['status'] === 'available') || ($s['quantity'] === 0 && $s['status'] === 'unavailable');
 }

 public static function quantity($product) {
  $s = get_post_meta($product->get_id(), self::META, true);
  return self::valid($s) ? $s['quantity'] : 0;
 }

 public static function label($quantity) {
  if ($quantity <= 0) { return 'Momentálne vypredané u dodávateľa'; }
  return 'U dodávateľa – ' . ($quantity > 20 ? '10+' : (string)$quantity) . ' ks';
 }

 public static function availability($text, $p) {
  if (self::is_supplier($p)) { return self::label(self::quantity($p)); }
  if ($p && $p->managing_stock() && $p->is_in_stock() && $p->get_stock_quantity() > 0) { return 'Skladom ' . wc_format_stock_quantity_for_display($p->get_stock_quantity(), $p) . ' ks'; }
  return $text;
 }
 public static function unavailable_notice() {
  global $product;
  // Woo's simple add-to-cart template returns before rendering stock for non-purchasable products.
  if ($product && self::is_supplier($product) && self::quantity($product)===0) {
   echo '<p class="stock out-of-stock komarena-supplier-availability">' . esc_html(self::label(0)) . '</p>';
  }
 }
 public static function purchasable($value, $p) { return self::is_supplier($p) ? ($value && self::quantity($p) > 0) : $value; }
 public static function in_stock($value, $p) { return self::is_supplier($p) ? ($value && self::quantity($p) > 0) : $value; }
 public static function backorders($value, $id) { return self::is_supplier(wc_get_product($id)) ? false : $value; }
 public static function maximum($value, $p) {
  if (!self::is_supplier($p)) { return $value; }
  $qty = self::quantity($p);
  return $value >= 0 ? min($value, $qty) : $qty;
 }
 private static function cart_quantity($id, $exclude = '') {
  $qty = 0;
  if (function_exists('WC') && WC()->cart) {
   foreach (WC()->cart->get_cart() as $key=>$item) {
    if ($key !== $exclude && (int)$item['product_id'] === (int)$id) { $qty += $item['quantity']; }
   }
  }
  return $qty;
 }
 private static function validate_quantity($passed, $id, $qty) {
  $p = wc_get_product($id);
  if (!$passed || !self::is_supplier($p)) { return $passed; }
  if ($qty <= 0 || $qty > self::quantity($p) || !$p->is_purchasable() || !$p->is_in_stock()) {
   wc_add_notice('Požadované množstvo momentálne nie je dostupné u dodávateľa.', 'error');
   return false;
  }
  return true;
 }
 public static function add_to_cart($passed, $id, $qty, $variation_id=0, $variations=array(), $item=array()) {
  return self::validate_quantity($passed, $id, $qty + self::cart_quantity($id));
 }
 public static function update_cart($passed, $key, $item, $qty) {
  if ((float)$qty === 0.0) { return $passed; }
  return self::validate_quantity($passed, $item['product_id'], $qty + self::cart_quantity($item['product_id'], $key));
 }
 public static function check_cart() {
  if (!WC()->cart) { return; }
  $checked = array();
  foreach (WC()->cart->get_cart() as $item) {
   $id=$item['product_id'];
   if (!isset($checked[$id])) { self::validate_quantity(true, $id, self::cart_quantity($id)); $checked[$id]=true; }
  }
 }
 public static function guard_save($p) {
  if (!self::is_supplier($p) || !metadata_exists('post', $p->get_id(), self::META)) { return; }
  $p->set_manage_stock(false);
  $p->set_stock_quantity(null);
  $p->set_backorders('no');
  $p->set_stock_status(self::quantity($p)>0 ? 'instock' : 'outofstock');
 }
 public static function product_content($html, $p) {
  if (!self::is_supplier($p)) { return $html; }
  // Replace legacy availability text at render time; stored descriptions remain byte-for-byte intact.
  return preg_replace('/(?:U dodávateľa\s*(?:–|—|-|&ndash;|&#8211;)\s*\d+\+?\s*ks|Momentálne vypredané u dodávateľa)/u', self::label(self::quantity($p)), $html);
 }
 public static function content($html) {
  global $product;
  return ($product instanceof WC_Product) ? self::product_content($html, $product) : $html;
 }
 public static function rest_privacy($response, $p, $request) {
  $d=$response->get_data();
  if (isset($d['meta_data'])) {
   $d['meta_data']=array_values(array_filter($d['meta_data'], function($m){
    $key=is_object($m) ? $m->key : $m['key'];
    return strpos($key, '_komarena_pf_supplier') !== 0;
   }));
  }
  $response->set_data($d);return $response;
 }

 public static function permission() { return current_user_can('manage_options') && current_user_can('manage_woocommerce'); }
 private static function require_value($value, $message) { if (!$value) { throw new RuntimeException($message); } }
 private static function identity($r) {
  self::require_value(rtrim(home_url(),'/')===self::TARGET && rtrim(site_url(),'/')===self::TARGET && $r->get_param('target')===self::TARGET, 'Target mismatch');
  $t=get_term(self::TAG,'product_tag');
  self::require_value($t && !is_wp_error($t) && $t->name==='U dodávateľa' && $t->slug==='u-dodavatela', 'Supplier tag identity mismatch');
 }
 public static function routes() {
  foreach (array('plan','apply','verify','rollback','read','update','fix-draft-title') as $action) {
   register_rest_route('komarena-pf/v1','/supplier-stock/'.$action,array('methods'=>'POST','permission_callback'=>array(__CLASS__,'permission'),
    'callback'=>function($r) use ($action) { return self::dispatch($action,$r); }));
  }
 }
 private static function ids() {
  $ids=get_posts(array('post_type'=>'product','post_status'=>'publish','numberposts'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC',
   'tax_query'=>array(array('taxonomy'=>'product_tag','field'=>'term_id','terms'=>self::TAG))));
  return array_map('intval',$ids);
 }
 private static function fresh($id) { clean_post_cache($id); wp_cache_delete($id,'post_meta'); wc_delete_product_transients($id); return wc_get_product($id); }
 private static function snapshot($id) {
  global $wpdb;
  $p=get_post($id,ARRAY_A);
  self::require_value($p && $p['post_type']==='product','Product missing');
  $meta=get_post_meta($id); ksort($meta);
  $terms=$wpdb->get_results($wpdb->prepare("SELECT term_taxonomy_id, term_order FROM {$wpdb->term_relationships} WHERE object_id=%d ORDER BY term_taxonomy_id",$id),ARRAY_A);
  $stock=array();
  foreach (array('_stock','_manage_stock','_stock_status','_backorders',self::META) as $k) { $stock[$k]=$meta[$k]??array(); unset($meta[$k]); }
  unset($meta['_edit_lock']);
  return array('id'=>$id,'stock'=>$stock,'protected_hash'=>hash('sha256',serialize(array($p,$meta,$terms))), 'post'=>$p,'meta'=>$meta,'terms'=>$terms);
 }
 private static function digest($v) { return hash('sha256',serialize($v)); }
 private static function purge($ids) {
  foreach($ids as $id) { do_action('litespeed_purge_post',(int)$id); }
 }
 private static function store($key,$value) {
  self::require_value(add_option($key,$value,'',false),'Backup already exists or could not be written');
  self::require_value(get_option($key) === $value,'Backup read-back failed');
 }
 private static function load_plan($r) {
  $id=$r->get_param('plan_id');
  self::require_value(is_string($id) && preg_match('/^[a-f0-9-]{36}$/D',$id),'Invalid plan ID');
  $plan=get_option(self::PREFIX.$id);
  self::require_value(is_array($plan) && hash_equals(self::digest($plan),(string)$r->get_param('plan_hash')),'Plan hash mismatch');
  return array($id,$plan);
 }
 private static function plan() {
  $ids=self::ids();self::require_value(count($ids)===58,'Expected exactly 58 published supplier products');
  $items=array();$counts=array('instock'=>0,'outofstock'=>0);
  foreach($ids as $id) {
   $p=self::fresh($id);$q=$p->get_stock_quantity('edit');$status=$p->get_stock_status('edit');
   self::require_value($p->is_type('simple') && $p->get_manage_stock('edit') && is_numeric($q) && $q>=0 && floor($q)==$q && $p->get_backorders('edit')==='no','Invalid legacy stock: '.$id);
   self::require_value(($q>0 && $status==='instock') || ($q==0 && $status==='outofstock'),'Inconsistent stock: '.$id);
   self::require_value(!metadata_exists('post',$id,self::META),'Already migrated: '.$id);
   $counts[$status]++;
   $items[]=array('before'=>self::snapshot($id),'supplier'=>array('quantity'=>(int)$q,'status'=>$q>0?'available':'unavailable',
    // No supplier-source time existed in the legacy model. Zero means unknown, not a fabricated refresh.
    'source_timestamp'=>0,'observed_at'=>time(),'provenance'=>'legacy_woo_snapshot'));
  }
  self::require_value($counts===array('instock'=>42,'outofstock'=>16),'Legacy stock counts differ from authorized audit');
  $plan=array('target'=>self::TARGET,'ids'=>$ids,'items'=>$items,'created_at'=>time(),'counts'=>$counts);
  $id=wp_generate_uuid4();self::store(self::PREFIX.$id,$plan);
  return array('plan_id'=>$id,'plan_hash'=>self::digest($plan),'ids'=>$ids,'counts'=>$counts,'backup_verified'=>true);
 }
 private static function transaction() {
  global $wpdb;
  foreach(array($wpdb->posts,$wpdb->postmeta,$wpdb->term_relationships,$wpdb->prefix.'wc_product_meta_lookup',$wpdb->options) as $table) {
   $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
   self::require_value(strtolower((string)$engine)==='innodb','Transactional tables required');
  }
  self::require_value($wpdb->query('START TRANSACTION')!==false,'Transaction failed');
 }
 private static function lock_rows($ids) {
  global $wpdb;
  $list=implode(',',array_map('intval',$ids));
  foreach(array("SELECT ID FROM {$wpdb->posts} WHERE ID IN ($list) FOR UPDATE", "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id IN ($list) FOR UPDATE", "SELECT object_id FROM {$wpdb->term_relationships} WHERE object_id IN ($list) FOR UPDATE") as $sql) {
   self::require_value($wpdb->query($sql)!==false,'Row lock failed');
  }
 }
 private static function write_stock($id,$s) {
  global $wpdb;
  update_post_meta($id,self::META,$s);
  wp_cache_delete($id,'post_meta');
  self::require_value(get_post_meta($id,self::META,true)===$s,'Supplier meta read-back failed: '.$id);
  // Only after the private quantity has been persisted and read back may local stock be cleared.
  delete_post_meta($id,'_stock');
  update_post_meta($id,'_manage_stock','no');
  update_post_meta($id,'_backorders','no');
  $status=$s['quantity']>0?'instock':'outofstock';
  update_post_meta($id,'_stock_status',$status);
  self::require_value($wpdb->update($wpdb->prefix.'wc_product_meta_lookup',array('stock_quantity'=>null,'stock_status'=>$status),array('product_id'=>$id))!==false,'Lookup update failed');
  $p=self::fresh($id);
  self::require_value(!$p->get_manage_stock('edit') && $p->get_stock_quantity('edit')===null && $p->get_backorders('edit')==='no' && $p->get_stock_status('edit')===$status,'Woo stock read-back failed: '.$id);
 }
 private static function verify($plan) {
  global $wpdb;
  self::require_value(self::ids()===$plan['ids'],'Supplier cohort drift');
  $rows=array();
  foreach($plan['items'] as $item) {
   $before=$item['before'];$id=$before['id'];$p=self::fresh($id);$now=self::snapshot($id);
   $s=get_post_meta($id,self::META,true);
   $lookup=$wpdb->get_row($wpdb->prepare("SELECT stock_quantity,stock_status FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id=%d",$id),ARRAY_A);
   $ok=$s===$item['supplier'] && $now['protected_hash']===$before['protected_hash'] && $p->get_stock_quantity('edit')===null && !$p->get_manage_stock('edit') && $p->get_backorders('edit')==='no' && $lookup && $lookup['stock_quantity']===null && $lookup['stock_status']===$p->get_stock_status('edit');
   $ok=$ok && $p->get_stock_status('edit')===($s['quantity']>0?'instock':'outofstock') && ($s['quantity']>0 || (!$p->is_purchasable() && !$p->is_in_stock()));
   self::require_value($ok,'Verification failed: '.$id);
   $rows[]=array('id'=>$id,'quantity'=>$s['quantity'],'status'=>$s['status'],'source_timestamp'=>$s['source_timestamp'],'manage_stock'=>false,'stock_quantity'=>null,'stock_status'=>$p->get_stock_status('edit'),'purchasable'=>$p->is_purchasable(),'availability'=>self::availability('',$p),'content_unchanged'=>true,'visibility'=>$p->get_catalog_visibility('edit'),'post_status'=>$p->get_status('edit'));
  }
  return array('verified'=>count($rows),'items'=>$rows);
 }
 private static function apply($id,$plan) {
  global $wpdb;
  if (get_option(self::PREFIX.$id.'_applied')) { $result=self::verify($plan);self::purge($plan['ids']);return $result; }
  self::require_value(!get_option(self::PREFIX.$id.'_rolled_back'),'Plan already rolled back');
  self::transaction();
  try {
   self::lock_rows($plan['ids']);
   self::require_value(self::ids()===$plan['ids'],'Cohort drift');
   foreach($plan['items'] as $item) { self::fresh($item['before']['id']);self::require_value(self::snapshot($item['before']['id'])===$item['before'],'Product drift before migration: '.$item['before']['id']); }
   foreach($plan['items'] as $item) { self::write_stock($item['before']['id'],$item['supplier']); }
   $result=self::verify($plan);
   self::store(self::PREFIX.$id.'_applied',array('at'=>time()));
   self::require_value($wpdb->query('COMMIT')!==false,'Commit failed');
   self::purge($plan['ids']);
   return $result;
  } catch(Throwable $e) { $wpdb->query('ROLLBACK');wp_cache_delete(self::PREFIX.$id.'_applied','options');throw $e; }
  finally { foreach($plan['ids'] as $pid) { self::fresh($pid); } }
 }
 private static function rollback($id,$plan) {
  global $wpdb;
  self::require_value(get_option(self::PREFIX.$id.'_applied') && !get_option(self::PREFIX.$id.'_rolled_back'),'No applied migration to roll back');
  self::transaction();
  try {
   self::lock_rows($plan['ids']);self::verify($plan);
   foreach($plan['items'] as $item) {
    $pid=$item['before']['id'];
    foreach($item['before']['stock'] as $key=>$values) {
     delete_post_meta($pid,$key);
     foreach($values as $v) { add_post_meta($pid,$key,wp_slash(maybe_unserialize($v))); }
    }
    $p=self::fresh($pid);
    self::require_value($wpdb->update($wpdb->prefix.'wc_product_meta_lookup',array('stock_quantity'=>$p->get_stock_quantity('edit'),'stock_status'=>$p->get_stock_status('edit')),array('product_id'=>$pid))!==false,'Lookup rollback failed');
    self::require_value(self::snapshot($pid)===$item['before'],'Rollback read-back failed');
   }
   self::store(self::PREFIX.$id.'_rolled_back',array('at'=>time()));
   self::require_value($wpdb->query('COMMIT')!==false,'Rollback commit failed');
   self::purge($plan['ids']);
   return array('restored'=>58,'runtime_note'=>'Restore prior plugin code to restore legacy purchasing behavior.');
  } catch(Throwable $e) { $wpdb->query('ROLLBACK');wp_cache_delete(self::PREFIX.$id.'_rolled_back','options');throw $e; }
  finally { foreach($plan['ids'] as $pid) { self::fresh($pid); } }
 }
 private static function update($r) {
  global $wpdb;
  $id=(int)$r->get_param('id');$q=$r->get_param('quantity');$ts=$r->get_param('source_timestamp');
  self::require_value(is_int($q) && $q>=0 && is_int($ts) && $ts>0 && $ts<=time()+300,'Invalid supplier refresh');
  $p=self::fresh($id);self::require_value(self::is_supplier($p) && metadata_exists('post',$id,self::META) && $p->is_type('simple'),'Migrated supplier product required');
  $old=get_post_meta($id,self::META,true);
  self::require_value(self::digest($old)===$r->get_param('expected_hash'),'Supplier data changed');
  self::require_value($ts>($old['source_timestamp']??0),'Stale source timestamp');
  $before=self::snapshot($id);self::store('komarena_pf_supplier_update_'.wp_generate_uuid4(),$before);
  self::transaction();
  try {
   self::lock_rows(array($id));self::fresh($id);self::require_value(self::snapshot($id)===$before,'Product drift');
   $s=array('quantity'=>$q,'status'=>$q>0?'available':'unavailable','source_timestamp'=>$ts,'observed_at'=>time(),'provenance'=>'supplier_refresh');
   self::write_stock($id,$s);self::require_value(self::snapshot($id)['protected_hash']===$before['protected_hash'],'Protected data changed');
   self::require_value($wpdb->query('COMMIT')!==false,'Commit failed');
   self::purge(array($id));
   return array('id'=>$id,'supplier'=>$s,'hash'=>self::digest($s));
  } catch(Throwable $e) { $wpdb->query('ROLLBACK');throw $e; } finally { self::fresh($id); }
 }
 private static function fix_title() {
  global $wpdb;
  $id=4312;$before=self::snapshot($id);$desired='eSUN PLA+ 1,75 mm – 1 kg';
  self::require_value($before['post']['post_status']==='draft','4312 is not draft');
  if($before['post']['post_title']===$desired) { return array('id'=>$id,'title'=>$desired,'status'=>'draft','already_correct'=>true); }
  self::require_value($before['post']['post_title']==='eSUN PLA+ 1,75 mm â€“ 1 kg','Unexpected draft title');
  self::store('komarena_pf_supplier_title_backup_'.wp_generate_uuid4(),$before);
  self::transaction();
  try {
   self::lock_rows(array($id));self::fresh($id);self::require_value(self::snapshot($id)===$before,'Draft changed');
   // Bounded title-only DB update avoids unrelated Woo variation synchronization and title filters.
   self::require_value($wpdb->update($wpdb->posts,array('post_title'=>$desired),array('ID'=>$id,'post_status'=>'draft'))===1,'Title write failed');
   self::fresh($id);$now=self::snapshot($id);$expected=$before['post'];$expected['post_title']=$desired;
   self::require_value($now['post']===$expected && $now['stock']===$before['stock'] && $now['meta']===$before['meta'] && $now['terms']===$before['terms'],'Title read-back failed');
   self::require_value($wpdb->query('COMMIT')!==false,'Title commit failed');
   self::purge(array($id));
   return array('id'=>$id,'title'=>$desired,'status'=>'draft','only_title_changed'=>true);
  } catch(Throwable $e) { $wpdb->query('ROLLBACK');throw $e; } finally { self::fresh($id); }
 }
 public static function dispatch($action,$r) {
  global $wpdb;
  $locked=false;
  try {
   self::identity($r);
   $locked=(string)$wpdb->get_var("SELECT GET_LOCK('komarena_pf_supplier_stock',0)")==='1';
   self::require_value($locked,'Another supplier operation is running');
   if($action==='plan') { return self::plan(); }
   if($action==='fix-draft-title') { return self::fix_title(); }
   if($action==='read') {
    $p=self::fresh((int)$r->get_param('id'));
    self::require_value(self::is_supplier($p),'Supplier product required');
    $s=get_post_meta($p->get_id(),self::META,true);
    return array('module_version'=>self::VERSION,'id'=>$p->get_id(),'supplier'=>$s,'hash'=>self::digest($s),'valid'=>self::valid($s));
   }
   if($action==='update') { return self::update($r); }
   list($id,$plan)=self::load_plan($r);
   if($action==='apply') { return self::apply($id,$plan); }
   if($action==='rollback') { return self::rollback($id,$plan); }
   return self::verify($plan);
  } catch(Throwable $e) { return new WP_Error('komarena_supplier_stock_failed',$e->getMessage(),array('status'=>409)); }
  finally { if($locked) { $wpdb->get_var("SELECT RELEASE_LOCK('komarena_pf_supplier_stock')"); } }
 }
}

KomArena_PF_Supplier_Stock::boot();
