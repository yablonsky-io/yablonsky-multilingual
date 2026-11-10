<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Explicit, admin-only static discovery of translatable PHP string literals. */
final class StringDiscovery {
  public const TABLE_SUFFIX = 'ymulti_string_discovery';
  private const MAX_FILES = 400;
  private const MAX_FILE_BYTES = 524288;
  private const MAX_TOTAL_BYTES = 25165824;

  public static function init(): void {
    if (!is_admin()) return;
    add_action('admin_menu', [__CLASS__, 'register_page'], 32);
    add_action('admin_post_ymulti_string_discovery_scan', [__CLASS__, 'handle_scan']);
  }

  public static function table(): string { global $wpdb; return $wpdb->prefix . self::TABLE_SUFFIX; }

  public static function install_table(): void {
    global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table=self::table();$charset=$wpdb->get_charset_collate();
    $sql="CREATE TABLE {$table} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      source_type VARCHAR(32) NOT NULL,
      source_name VARCHAR(191) NOT NULL,
      file_path VARCHAR(255) NOT NULL,
      text_domain VARCHAR(191) NOT NULL,
      context_name VARCHAR(191) NOT NULL DEFAULT '',
      source_hash CHAR(40) NOT NULL,
      source_text LONGTEXT NOT NULL,
      discovered_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ymulti_identity (source_type(20), source_name(50), text_domain(50), context_name(20), source_hash),
      KEY ymulti_domain (text_domain(100)),
      KEY ymulti_source (source_type(20), source_name(100))
    ) {$charset};";
    dbDelta($sql);
  }

  public static function register_page(): void {
    add_submenu_page('ypm-multilingual', __('String Discovery', 'yablonsky-multilingual'), __('String Discovery', 'yablonsky-multilingual'), 'manage_options', 'ypm-string-discovery', [__CLASS__, 'render_page']);
  }

  /** @return array<int,array<string,string>> */
  private static function sources(): array {
    $out=[];$seen=[];
    $theme=get_stylesheet_directory();
    if(is_dir($theme)){$real=realpath($theme);if($real){$seen[$real]=true;$out[]=['type'=>'theme','name'=>(string)wp_get_theme()->get('Name'),'root'=>$real];}}
    $template=get_template_directory();$real_template=is_dir($template)?realpath($template):false;
    if($real_template && !isset($seen[$real_template])){$seen[$real_template]=true;$out[]=['type'=>'theme','name'=>(string)wp_get_theme(get_template())->get('Name'),'root'=>$real_template];}
    $active=(array)get_option('active_plugins',[]);
    foreach($active as $plugin){
      if(!is_string($plugin)||$plugin==='')continue;
      $plugin_file=realpath(WP_PLUGIN_DIR.'/'.$plugin);if(!$plugin_file||strpos($plugin_file,realpath(WP_PLUGIN_DIR))!==0)continue;
      $dir=dirname($plugin);$root=$dir==='.'?$plugin_file:realpath(WP_PLUGIN_DIR.'/'.$dir);if(!$root||isset($seen[$root]))continue;$seen[$root]=true;
      $data=function_exists('get_plugin_data')?get_plugin_data(WP_PLUGIN_DIR.'/'.$plugin,false,false):[];$fallback=$dir==='.'?basename($plugin):$dir;$name=sanitize_text_field((string)($data['Name']??$fallback));$out[]=['type'=>'plugin','name'=>$name!==''?$name:$fallback,'root'=>$root];
    }
    return $out;
  }

  /** @return array<string,mixed> */
  public static function scan(): array {
    self::install_table();$files=[];$total_bytes=0;$truncated=false;
    foreach(self::sources() as $source){
      if(is_file($source['root'])){
        $file=new \SplFileInfo($source['root']);$size=(int)$file->getSize();if($size>0&&$size<=self::MAX_FILE_BYTES&&$total_bytes+$size<=self::MAX_TOTAL_BYTES){$total_bytes+=$size;$files[]=['source'=>$source,'path'=>$file->getPathname(),'size'=>$size];}continue;
      }
      try{$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source['root'],\FilesystemIterator::SKIP_DOTS));}catch(\Throwable $e){continue;}
      foreach($it as $file){if(count($files)>=self::MAX_FILES){$truncated=true;break 2;}if(!($file instanceof \SplFileInfo)||!$file->isFile()||strtolower($file->getExtension())!=='php')continue;$size=(int)$file->getSize();if($size<=0||$size>self::MAX_FILE_BYTES)continue;if($total_bytes+$size>self::MAX_TOTAL_BYTES){$truncated=true;break 2;}$total_bytes+=$size;$files[]=['source'=>$source,'path'=>$file->getPathname(),'size'=>$size];}
    }
    $found=[];
    foreach($files as $entry){$code=@file_get_contents($entry['path']);if(!is_string($code)||$code==='')continue;$relative=ltrim(str_replace($entry['source']['root'],'',$entry['path']),DIRECTORY_SEPARATOR);foreach(self::extract($code) as $row){$row['source_type']=$entry['source']['type'];$row['source_name']=$entry['source']['name'];$row['file_path']=$relative;$key=$row['source_type'].'|'.$row['source_name'].'|'.$row['text_domain'].'|'.$row['context_name'].'|'.sha1($row['source_text']);$found[$key]=$row;}}
    self::persist(array_values($found));
    return ['files'=>count($files),'strings'=>count($found),'bytes'=>$total_bytes,'truncated'=>$truncated];
  }

  /** @return array<int,array<string,string>> */
  private static function extract(string $code): array {
    $tokens=token_get_all($code);$out=[];$functions=['__','_e','esc_html__','esc_attr__','esc_html_e','esc_attr_e','_x','_ex','esc_html_x','esc_attr_x','_n','_nx','_n_noop','_nx_noop'];$n=count($tokens);
    for($i=0;$i<$n;$i++){if(!is_array($tokens[$i])||$tokens[$i][0]!==T_STRING)continue;$fn=strtolower($tokens[$i][1]);if(!in_array($fn,$functions,true))continue;$j=$i+1;while($j<$n&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;if($j>=$n||$tokens[$j]!=='(')continue;$args=[];$current=[];$depth=1;for($k=$j+1;$k<$n;$k++){ $t=$tokens[$k];if($t==='('||$t==='['||$t==='{')$depth++;elseif($t===')'||$t===']'||$t==='}'){$depth--;if($depth===0){$args[]=$current;break;}}if($depth===1&&$t===','){$args[]=$current;$current=[];continue;}$current[]=$t;}if(!$args)continue;$values=array_map([__CLASS__,'literal_arg'],$args);
      $domain='default';$context='';$texts=[];
      if(in_array($fn,['_x','_ex','esc_html_x','esc_attr_x'],true)){$texts[]=$values[0]??'';$context=$values[1]??'';$domain=$values[2]??'default';}
      elseif($fn==='_nx'){$texts[]=$values[0]??'';$texts[]=$values[1]??'';$context=$values[3]??'';$domain=$values[4]??'default';}
      elseif($fn==='_nx_noop'){$texts[]=$values[0]??'';$texts[]=$values[1]??'';$context=$values[2]??'';$domain=$values[3]??'default';}
      elseif(in_array($fn,['_n','_n_noop'],true)){$texts[]=$values[0]??'';$texts[]=$values[1]??'';$domain=$values[$fn==='_n'?3:2]??'default';}
      else{$texts[]=$values[0]??'';$domain=$values[1]??'default';}
      $domain=sanitize_key($domain!==''?$domain:'default');$context=sanitize_text_field($context);
      foreach(array_unique($texts) as $text){$text=sanitize_textarea_field($text);if($text==='')continue;$out[]=['text_domain'=>$domain,'context_name'=>$context,'source_text'=>$text];}
    }
    return $out;
  }

  private static function literal_arg(array $tokens): string {
    $meaningful=[];foreach($tokens as $t){if(is_array($t)&&in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))continue;$meaningful[]=$t;}if(count($meaningful)!==1||!is_array($meaningful[0])||$meaningful[0][0]!==T_CONSTANT_ENCAPSED_STRING)return '';$raw=$meaningful[0][1];$q=$raw[0]??'';$body=substr($raw,1,-1);if($q==="'")return str_replace(["\\\\","\\'"],["\\","'"],$body);return stripcslashes($body);
  }

  /** @param array<int,array<string,string>> $rows */
  private static function persist(array $rows): void {
    if(!$rows)return;global $wpdb;$table=self::table();$now=current_time('mysql',true);
    foreach(array_chunk($rows,80) as $chunk){$ph=[];$vals=[];foreach($chunk as $row){$ph[]='(%s,%s,%s,%s,%s,%s,%s,%s)';array_push($vals,$row['source_type'],$row['source_name'],$row['file_path'],$row['text_domain'],$row['context_name'],sha1($row['source_text']),$row['source_text'],$now);} // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name and VALUES placeholder list are generated internally; every row value is passed to prepare().
      $sql='INSERT INTO '.esc_sql($table).' (source_type,source_name,file_path,text_domain,context_name,source_hash,source_text,discovered_at) VALUES '.implode(',',$ph).' ON DUPLICATE KEY UPDATE file_path=VALUES(file_path), discovered_at=VALUES(discovered_at)';
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Own table; the SQL above contains only internal identifier/placeholder fragments and all external values are prepared.
      $wpdb->query($wpdb->prepare($sql,...$vals));
    }
  }

  public static function total(): int {global $wpdb;$table=self::table();self::install_table();/* phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded admin aggregate on YMulti-owned table. */return (int)$wpdb->get_var('SELECT COUNT(*) FROM '.esc_sql($table));}

  public static function render_page(): void {
    if(!current_user_can('manage_options'))return;self::install_table();global $wpdb;$table=self::table();$search_raw=filter_input(INPUT_GET,'s',FILTER_UNSAFE_RAW);$search=is_string($search_raw)?trim(sanitize_text_field($search_raw)):'';$domain_raw=filter_input(INPUT_GET,'domain',FILTER_UNSAFE_RAW);$domain=is_string($domain_raw)?sanitize_key($domain_raw):'';$source_raw=filter_input(INPUT_GET,'source_type',FILTER_UNSAFE_RAW);$source=is_string($source_raw)?sanitize_key($source_raw):'';$paged=max(1,(int)filter_input(INPUT_GET,'paged',FILTER_VALIDATE_INT));$per=Admin::requested_page_size(40,[20,40,80]);$offset=($paged-1)*$per;$where=['1=1'];$args=[];if($search!==''){$like='%'.$wpdb->esc_like($search).'%';$where[]='(source_text LIKE %s OR text_domain LIKE %s OR source_name LIKE %s)';array_push($args,$like,$like,$like);}if($domain!==''){$where[]='text_domain=%s';$args[]=$domain;}if(in_array($source,['plugin','theme'],true)){$where[]='source_type=%s';$args[]=$source;}$where_sql=implode(' AND ',$where);
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WHERE fragments are fixed allowlisted SQL; user values remain placeholders prepared below.
    $count_sql='SELECT COUNT(*) FROM '.esc_sql($table).' WHERE '.$where_sql;
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WHERE fragments are fixed allowlisted SQL; LIMIT/OFFSET and user values are prepared below.
    $rows_sql='SELECT id,source_type,source_name,file_path,text_domain,context_name,source_text,discovered_at FROM '.esc_sql($table).' WHERE '.$where_sql.' ORDER BY text_domain,source_name,id DESC LIMIT %d OFFSET %d';
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic SQL contains only internal allowlisted fragments and placeholders.
    $count_prepared=$args?$wpdb->prepare($count_sql,...$args):$count_sql;$row_args=array_merge($args,[$per,$offset]);
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic SQL contains only internal allowlisted fragments and placeholders.
    $rows_prepared=$wpdb->prepare($rows_sql,...$row_args);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Prepared bounded admin catalog on YMulti-owned table.
    $total=(int)$wpdb->get_var($count_prepared);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Prepared bounded admin catalog on YMulti-owned table.
    $rows=$wpdb->get_results($rows_prepared);$pages=max(1,(int)ceil($total/$per));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded distinct list for admin filters.
    $domains=$wpdb->get_col('SELECT DISTINCT text_domain FROM '.esc_sql($table).' ORDER BY text_domain LIMIT 250');
    echo '<div class="wrap ypm-admin ypm-admin--string-discovery">';Admin::render_shell_header(__('String Discovery','yablonsky-multilingual'),__('Discover static translation strings from active themes and plugins through bounded admin scanning while frontend requests stay local.','yablonsky-multilingual'),'neutral',__('Admin-only scan','yablonsky-multilingual'));
    $scan_notice_raw=filter_input(INPUT_GET,'scan',FILTER_UNSAFE_RAW);if(is_string($scan_notice_raw)&&$scan_notice_raw!==''){echo '<div class="ypm-callout ypm-callout--pass"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><div><strong>'.esc_html__('Discovery scan complete.','yablonsky-multilingual').'</strong><span>'.esc_html($scan_notice_raw).'</span></div></div>';}
    echo '<div class="ypm-metrics ypm-metrics--4">';Admin::render_metric(__('Discovered','yablonsky-multilingual'),(string)$total,__('After current filters','yablonsky-multilingual'),'neutral');Admin::render_metric(__('Stored catalog','yablonsky-multilingual'),(string)self::total(),__('Static string identities','yablonsky-multilingual'),'neutral');Admin::render_metric(__('Runtime writes','yablonsky-multilingual'),'0',__('Scanner runs only on explicit admin action','yablonsky-multilingual'),'pass');Admin::render_metric(__('Frontend scanner','yablonsky-multilingual'),__('Off','yablonsky-multilingual'),__('Visitor scan cost: 0','yablonsky-multilingual'),'pass');echo '</div>';
    echo '<section class="ypm-card ypm-card--standalone"><div class="ypm-card__head"><div><span class="ypm-eyebrow">'.esc_html__('Discovery','yablonsky-multilingual').'</span><h2>'.esc_html__('Static string catalog','yablonsky-multilingual').'</h2><p class="ypm-card__copy">'.esc_html__('Scans only static PHP translation calls in active themes/plugins. Dynamic strings remain available through explicit overrides.','yablonsky-multilingual').'</p></div><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('ymulti_string_discovery_scan','ymulti_string_discovery_nonce');echo '<input type="hidden" name="action" value="ymulti_string_discovery_scan"><button class="button button-primary">'.esc_html__('Scan active code','yablonsky-multilingual').'</button></form></div>';
    echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" class="ypm-workspace-filter-form ypm-workspace-filter-form--compact"><input type="hidden" name="page" value="ypm-string-discovery"><label class="ypm-workspace-filter ypm-workspace-filter--search"><span>'.esc_html__('Search','yablonsky-multilingual').'</span><input type="search" name="s" value="'.esc_attr($search).'" placeholder="'.esc_attr__('String, domain or source','yablonsky-multilingual').'"></label><label class="ypm-workspace-filter"><span>'.esc_html__('Domain','yablonsky-multilingual').'</span><select name="domain"><option value="">'.esc_html__('All domains','yablonsky-multilingual').'</option>';if(is_array($domains))foreach($domains as $d)echo '<option value="'.esc_attr((string)$d).'"'.selected($domain,(string)$d,false).'>'.esc_html((string)$d).'</option>';echo '</select></label><label class="ypm-workspace-filter"><span>'.esc_html__('Source','yablonsky-multilingual').'</span><select name="source_type"><option value="">'.esc_html__('Themes + plugins','yablonsky-multilingual').'</option><option value="theme"'.selected($source,'theme',false).'>'.esc_html__('Themes','yablonsky-multilingual').'</option><option value="plugin"'.selected($source,'plugin',false).'>'.esc_html__('Plugins','yablonsky-multilingual').'</option></select></label><div class="ypm-workspace-filter-actions"><button class="button button-primary">'.esc_html__('Apply filters','yablonsky-multilingual').'</button></div></form>';
    if(!$rows){echo '<div class="ypm-empty-panel"><span class="dashicons dashicons-search" aria-hidden="true"></span><strong>'.esc_html__('String discovery ready to scan','yablonsky-multilingual').'</strong><p>'.esc_html__('Run the bounded admin scan. String discovery stays in the administrator workflow while visitor requests remain lightweight.','yablonsky-multilingual').'</p></div>';}else{echo '<div class="ypm-table-wrap"><table class="widefat"><thead><tr><th>'.esc_html__('String','yablonsky-multilingual').'</th><th>'.esc_html__('Domain / context','yablonsky-multilingual').'</th><th>'.esc_html__('Source','yablonsky-multilingual').'</th><th class="ypm-col-action">'.esc_html__('Action','yablonsky-multilingual').'</th></tr></thead><tbody>';foreach($rows as $row){$url=add_query_arg(['page'=>'ypm-strings','source_text'=>(string)$row->source_text,'text_domain'=>(string)$row->text_domain,'context_name'=>(string)$row->context_name],admin_url('admin.php'));echo '<tr><td><div class="ypm-string-copy">'.esc_html((string)$row->source_text).'</div></td><td><div class="ypm-domain-cell"><code>'.esc_html((string)$row->text_domain).'</code><span>'.esc_html((string)$row->context_name).'</span></div></td><td><div class="ypm-content-cell"><strong>'.esc_html((string)$row->source_name).'</strong><span>'.esc_html((string)$row->file_path).'</span></div></td><td class="ypm-col-action"><a class="button button-small" href="'.esc_url($url).'">'.esc_html__('Create override','yablonsky-multilingual').'</a></td></tr>';}echo '</tbody></table></div>';}
    $start=$total>0?(($paged-1)*$per)+1:0;$end=min($total,$paged*$per);/* translators: 1: first visible item, 2: last visible item, 3: total matching items. */echo '<div class="ypm-pagination"><span>'.esc_html(sprintf(__('Showing %1$d–%2$d of %3$d','yablonsky-multilingual'),$start,$end,$total)).'</span>';Admin::render_server_page_size(['page'=>'ypm-string-discovery','s'=>$search,'domain'=>$domain,'source_type'=>$source],$per,[20,40,80]);if($pages>1){$links=paginate_links(['base'=>add_query_arg(['page'=>'ypm-string-discovery','s'=>$search,'domain'=>$domain,'source_type'=>$source,'per_page'=>$per,'paged'=>'%#%'],admin_url('admin.php')),'format'=>'','current'=>$paged,'total'=>$pages,'prev_text'=>'‹','next_text'=>'›','type'=>'array']);if(is_array($links)&&$links)echo '<span class="ypm-pagination__links">'.wp_kses_post(implode('',$links)).'</span>';}echo '</div></section></div>';
  }

  public static function handle_scan(): void {
    if(!current_user_can('manage_options'))wp_die(esc_html__('Forbidden','yablonsky-multilingual'));check_admin_referer('ymulti_string_discovery_scan','ymulti_string_discovery_nonce');$result=self::scan();$summary=sprintf(/* translators: 1: scanned files, 2: discovered static strings. */__('Scanned %1$d files and indexed %2$d static strings.','yablonsky-multilingual'),(int)$result['files'],(int)$result['strings']);if(!empty($result['truncated']))$summary.=' '.__('Safety limit reached; rerun after narrowing the active code surface if needed.','yablonsky-multilingual');wp_safe_redirect(add_query_arg(['page'=>'ypm-string-discovery','scan'=>$summary],admin_url('admin.php')));exit;
  }
}
