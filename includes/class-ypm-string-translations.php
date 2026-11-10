<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Explicit string overrides with one bounded dictionary load per locale/domain.
 * No SQL is executed per gettext call after the dictionary is warm.
 */
final class StringTranslations {
  public const OPT_DOMAINS = 'ypm_string_domains';
  private const PAGE_SIZE = 40;

  /** @var array<string,array<string,string>> */
  private static array $dictionary_cache = [];
  /** @var array<string,bool>|null */
  private static ?array $domain_cache = null;

  /** Prevent localized overrides from one network site reaching another. */
  public static function reset_request_cache(): void {
    self::$dictionary_cache = [];
    self::$domain_cache = null;
  }

  public static function init(): void {
    $domains = get_option(self::OPT_DOMAINS, []);
    if (is_array($domains) && $domains) {
      add_filter('gettext', [__CLASS__, 'filter_gettext'], 20, 3);
      add_filter('gettext_with_context', [__CLASS__, 'filter_gettext_context'], 20, 4);
    }

    if (is_admin()) {
      add_action('admin_menu', [__CLASS__, 'register_page'], 31);
      add_action('admin_post_ypm_string_save', [__CLASS__, 'handle_save']);
      add_action('admin_post_ypm_string_update', [__CLASS__, 'handle_update']);
      add_action('admin_post_ypm_string_delete', [__CLASS__, 'handle_delete']);
      add_action('admin_post_ypm_string_bulk', [__CLASS__, 'handle_bulk']);
    }
  }

  public static function table(): string {
    global $wpdb;
    return $wpdb->prefix . 'ypm_strings';
  }

  public static function install_table(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = self::table();
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      locale VARCHAR(32) NOT NULL,
      text_domain VARCHAR(191) NOT NULL,
      context_name VARCHAR(191) NOT NULL DEFAULT '',
      source_hash CHAR(40) NOT NULL,
      source_text LONGTEXT NOT NULL,
      translated_text LONGTEXT NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ypm_lookup (locale(32), text_domain(100), context_name(50), source_hash),
      KEY ypm_locale_domain (locale(32), text_domain(100))
    ) {$charset};";
    dbDelta($sql);
    if (get_option(self::OPT_DOMAINS) === false) add_option(self::OPT_DOMAINS, [], '', false);
  }

  public static function filter_gettext(string $translation, string $text, string $domain): string {
    return self::resolve($translation, $text, $domain, '');
  }

  public static function filter_gettext_context(string $translation, string $text, string $context, string $domain): string {
    return self::resolve($translation, $text, $domain, $context);
  }

  private static function resolve(string $translation, string $text, string $domain, string $context): string {
    if (is_admin() && !wp_doing_ajax()) return $translation;
    if ($text === '' || $domain === '' || !self::domain_enabled($domain)) return $translation;
    $language = self::current_language();
    if ($language === '' || $language === Languages::default_code()) return $translation;

    $cache_key = $language . '|' . $domain;
    if (!isset(self::$dictionary_cache[$cache_key])) self::$dictionary_cache[$cache_key] = self::load_dictionary($language, $domain);
    $lookup = $context . '|' . sha1($text);
    return isset(self::$dictionary_cache[$cache_key][$lookup]) && self::$dictionary_cache[$cache_key][$lookup] !== ''
      ? self::$dictionary_cache[$cache_key][$lookup]
      : $translation;
  }

  private static function current_language(): string {
    if (!is_admin() && class_exists(Router::class)) {
      $code = Router::current_lang();
      if (Languages::is_active($code)) return $code;
    }
    $wp_locale = function_exists('determine_locale') ? determine_locale() : get_locale();
    foreach (Languages::active_codes() as $code) {
      $meta = Languages::locale_metadata($code);
      if (!empty($meta['wp_locale']) && strcasecmp((string) $meta['wp_locale'], (string) $wp_locale) === 0) return $code;
    }
    return Languages::default_code();
  }

  private static function domain_enabled(string $domain): bool {
    if (self::$domain_cache === null) {
      self::$domain_cache = [];
      $domains = get_option(self::OPT_DOMAINS, []);
      if (is_array($domains)) foreach ($domains as $item) if (is_string($item) && $item !== '') self::$domain_cache[$item] = true;
    }
    return isset(self::$domain_cache[$domain]);
  }

  /** @return array<string,string> */
  private static function load_dictionary(string $locale, string $domain): array {
    global $wpdb;
    $table = self::table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One request-local dictionary projection per explicit locale/domain.
    $rows = $wpdb->get_results($wpdb->prepare(
      'SELECT context_name, source_hash, translated_text FROM ' . esc_sql($table) . ' WHERE locale = %s AND text_domain = %s',
      $locale,
      $domain
    ));
    $out = [];
    if (is_array($rows)) {
      foreach ($rows as $row) {
        if (!isset($row->source_hash)) continue;
        $out[(string) ($row->context_name ?? '') . '|' . (string) $row->source_hash] = (string) ($row->translated_text ?? '');
      }
    }
    return $out;
  }

  public static function total_overrides(): int {
    global $wpdb;
    $table = self::table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Single bounded dashboard aggregate over YMulti's own table.
    return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . esc_sql($table));
  }

  public static function register_page(): void {
    add_submenu_page(
      'ypm-multilingual',
      __('Strings', 'yablonsky-multilingual'),
      __('Strings', 'yablonsky-multilingual'),
      'manage_options',
      'ypm-strings',
      [__CLASS__, 'render_page']
    );
  }

  public static function render_page(): void {
    if (!current_user_can('manage_options')) return;
    global $wpdb;

    $active = Languages::active_codes();
    $locale_raw = filter_input(INPUT_GET, 'locale', FILTER_UNSAFE_RAW);
    $locale = is_string($locale_raw) ? sanitize_text_field($locale_raw) : '';
    if (!in_array($locale, $active, true)) $locale = Languages::default_code();
    $paged = max(1, (int) filter_input(INPUT_GET, 'paged', FILTER_VALIDATE_INT));
    $page_size = Admin::requested_page_size(self::PAGE_SIZE);
    $offset = ($paged - 1) * $page_size;
    $table = self::table();

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded admin listing.
    $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . esc_sql($table) . ' WHERE locale = %s', $locale));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded admin listing.
    $rows = $wpdb->get_results($wpdb->prepare(
      'SELECT id, text_domain, context_name, source_text, translated_text, updated_at FROM ' . esc_sql($table) . ' WHERE locale = %s ORDER BY text_domain, id DESC LIMIT %d OFFSET %d',
      $locale,
      $page_size,
      $offset
    ));
    $domains = get_option(self::OPT_DOMAINS, []);
    if (!is_array($domains)) $domains = [];
    $pages = max(1, (int) ceil($total / $page_size));
    if ($paged > $pages && $total > 0) {
      wp_safe_redirect(add_query_arg(['page' => 'ypm-strings', 'locale' => $locale, 'per_page' => $page_size, 'paged' => $pages], admin_url('admin.php')));
      exit;
    }
    $page_domains = [];
    if (is_array($rows)) foreach ($rows as $row) if (isset($row->text_domain) && (string) $row->text_domain !== '') $page_domains[(string) $row->text_domain] = true;
    $page_domains = array_keys($page_domains);
    sort($page_domains, SORT_NATURAL | SORT_FLAG_CASE);
    $ai_ready = class_exists('Yablonsky\Multilingual\AITranslation') && AITranslation::ready() && AITranslation::current_user_can_compute();
    $prefill_source_raw = filter_input(INPUT_GET, 'source_text', FILTER_UNSAFE_RAW);
    $prefill_domain_raw = filter_input(INPUT_GET, 'text_domain', FILTER_UNSAFE_RAW);
    $prefill_context_raw = filter_input(INPUT_GET, 'context_name', FILTER_UNSAFE_RAW);
    $prefill_source = is_string($prefill_source_raw) ? sanitize_textarea_field($prefill_source_raw) : '';
    $prefill_domain = is_string($prefill_domain_raw) ? sanitize_key($prefill_domain_raw) : '';
    $prefill_context = is_string($prefill_context_raw) ? sanitize_text_field($prefill_context_raw) : '';

    echo '<div class="wrap ypm-admin ypm-admin--strings">';
    Admin::render_shell_header(
      __('Strings', 'yablonsky-multilingual'),
      __('Override only the site, theme or plugin strings that need a local correction. WordPress language packs remain the default translation source.', 'yablonsky-multilingual'),
      'neutral',
      __('Local overrides', 'yablonsky-multilingual')
    );

    if (class_exists('Yablonsky\Multilingual\AITranslation')) {
      $text_setup = AITranslation::text_setup_status();
      if (empty($text_setup['ready'])) AIAdmin::render_text_compute_gate($text_setup, add_query_arg(['page'=>'ypm-strings','locale'=>$locale], admin_url('admin.php')));
    }

    $notice = '';
    if (filter_input(INPUT_GET, 'saved', FILTER_VALIDATE_BOOLEAN) === true) $notice = __('Override saved.', 'yablonsky-multilingual');
    elseif (filter_input(INPUT_GET, 'updated', FILTER_VALIDATE_BOOLEAN) === true) $notice = __('Override updated.', 'yablonsky-multilingual');
    elseif (filter_input(INPUT_GET, 'deleted', FILTER_VALIDATE_BOOLEAN) === true) $notice = __('Override deleted; native translation fallback is active again.', 'yablonsky-multilingual');
    elseif (($bulk_deleted = filter_input(INPUT_GET, 'bulk_deleted', FILTER_VALIDATE_INT)) !== null && $bulk_deleted !== false) $notice = sprintf(
      /* translators: %d: number of local string overrides deleted. */
      _n('%d override deleted; native fallback is active again.', '%d overrides deleted; native fallback is active again.', (int) $bulk_deleted, 'yablonsky-multilingual'),
      (int) $bulk_deleted
    );
    if ($notice !== '') {
      echo '<div class="ypm-callout ypm-callout--pass"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><div><strong>' . esc_html($notice) . '</strong></div></div>';
    }

    echo '<div class="ypm-metrics ypm-metrics--4">';
    Admin::render_metric(__('Locale', 'yablonsky-multilingual'), Languages::display_code($locale), Languages::display_name($locale), 'neutral');
    Admin::render_metric(__('Overrides', 'yablonsky-multilingual'), (string) $total, __('For this locale', 'yablonsky-multilingual'), 'neutral');
    Admin::render_metric(__('Tracked domains', 'yablonsky-multilingual'), (string) count($domains), __('Site, theme and plugins', 'yablonsky-multilingual'), 'neutral');
    Admin::render_metric(__('Page', 'yablonsky-multilingual'), $paged . '/' . $pages, __('Bounded dictionary view', 'yablonsky-multilingual'), 'neutral');
    echo '</div>';

    if (class_exists('Yablonsky\\Multilingual\\StringInterchange')) StringInterchange::render_panel($locale, $domains);

    echo '<details class="ypm-disclosure ypm-disclosure--wide ypm-string-add"' . ($prefill_source !== '' ? ' open' : '') . '>';
    echo '<summary><span><strong>' . esc_html__('Add string override', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Use this for a deliberate local correction. The native language-pack value stays active until you save a translation override.', 'yablonsky-multilingual') . '</small></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary>';
    echo '<div class="ypm-disclosure__body"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ypm-string-form">';
    wp_nonce_field('ypm_string_save', 'ypm_string_nonce');
    echo '<input type="hidden" name="action" value="ypm_string_save"><input type="hidden" name="locale" value="' . esc_attr($locale) . '">';
    echo '<div class="ypm-string-form__meta"><label><span>' . esc_html__('Text domain', 'yablonsky-multilingual') . '</span><input required type="text" name="text_domain" value="' . esc_attr($prefill_domain) . '" placeholder="woocommerce"></label><label><span>' . esc_html__('Context', 'yablonsky-multilingual') . '</span><input type="text" name="context_name" value="' . esc_attr($prefill_context) . '" placeholder="' . esc_attr__('Optional', 'yablonsky-multilingual') . '"></label></div>';
    echo '<div class="ypm-string-form__copy"><label><span>' . esc_html__('Source string', 'yablonsky-multilingual') . '</span><textarea required rows="3" name="source_text" placeholder="' . esc_attr__('Exact original string', 'yablonsky-multilingual') . '">' . esc_textarea($prefill_source) . '</textarea></label><label><span>' . esc_html__('Translation', 'yablonsky-multilingual') . '</span><textarea rows="3" name="translated_text" placeholder="' . esc_attr__('Your local translation', 'yablonsky-multilingual') . '"></textarea></label></div>';
    echo '<div class="ypm-form-actions"><span>' . esc_html__('Applies only to the selected locale.', 'yablonsky-multilingual') . '</span><div>';
    if ($ai_ready) echo '<button type="button" class="button" data-ypm-ai-string data-target-locale="' . esc_attr($locale) . '">' . esc_html__('Suggest with AI', 'yablonsky-multilingual') . '</button> ';
    echo '<button class="button button-primary">' . esc_html__('Save override', 'yablonsky-multilingual') . '</button><span class="ypm-ai-feedback" aria-live="polite"></span></div></div></form></div></details>';

    echo '<section class="ypm-card ypm-card--standalone ypm-strings-card"><div class="ypm-card__head ypm-card__head--toolbar"><div><span class="ypm-eyebrow">' . esc_html__('Dictionary', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Saved overrides', 'yablonsky-multilingual') . '</h2></div><div class="ypm-toolbar-group">';
    echo '<label class="screen-reader-text" for="ypm-string-domain">' . esc_html__('Text domain', 'yablonsky-multilingual') . '</label><select id="ypm-string-domain"><option value="all">' . esc_html__('All domains', 'yablonsky-multilingual') . '</option>'; foreach ($page_domains as $page_domain) echo '<option value="' . esc_attr(strtolower($page_domain)) . '">' . esc_html($page_domain) . '</option>'; echo '</select><div class="ypm-table-search"><label class="screen-reader-text" for="ypm-string-search">' . esc_html__('Search visible overrides', 'yablonsky-multilingual') . '</label><span class="dashicons dashicons-search" aria-hidden="true"></span><input id="ypm-string-search" type="search" placeholder="' . esc_attr__('Search this page…', 'yablonsky-multilingual') . '"></div>';
    echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="ypm-locale-filter"><input type="hidden" name="page" value="ypm-strings"><input type="hidden" name="per_page" value="' . esc_attr((string) $page_size) . '"><label class="screen-reader-text" for="ypm-string-locale">' . esc_html__('Locale', 'yablonsky-multilingual') . '</label><select id="ypm-string-locale" name="locale" onchange="this.form.submit()">';
    foreach ($active as $code) echo '<option value="' . esc_attr($code) . '"' . selected($locale, $code, false) . '>' . esc_html(Languages::display_name($code) . ' (' . Languages::display_code($code) . ')') . '</option>';
    echo '</select></form></div></div>';

    if (!$rows) {
      echo '<div class="ypm-empty-panel"><span class="dashicons dashicons-editor-quote" aria-hidden="true"></span><strong>' . esc_html__('Local overrides: 0 for this locale', 'yablonsky-multilingual') . '</strong><p>' . esc_html__('That is a valid state: native WordPress, theme and plugin translations continue to work normally.', 'yablonsky-multilingual') . '</p></div>';
    } else {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ymulti-list-bulkbar ymulti-bulk-form" data-ymulti-bulk-form="strings" data-ymulti-confirm-message="' . esc_attr__('Delete the selected local overrides? Native WordPress/theme/plugin translations will become active again.', 'yablonsky-multilingual') . '">';
      wp_nonce_field('ypm_string_bulk', 'ypm_string_bulk_nonce');
      echo '<input type="hidden" name="action" value="ypm_string_bulk"><input type="hidden" name="locale" value="' . esc_attr($locale) . '"><div data-ymulti-selected-inputs></div><div class="ymulti-list-bulkbar__copy"><strong>' . esc_html__('Bulk actions', 'yablonsky-multilingual') . '</strong><span>' . esc_html__('Use the row checkboxes to remove selected local overrides in one bounded action.', 'yablonsky-multilingual') . '</span></div><div class="ymulti-list-bulkbar__actions"><select name="bulk_operation" aria-label="' . esc_attr__('Bulk action', 'yablonsky-multilingual') . '"><option value="delete">' . esc_html__('Delete selected overrides', 'yablonsky-multilingual') . '</option></select><button class="button" data-ymulti-bulk-selected disabled>' . esc_html__('Apply to selected', 'yablonsky-multilingual') . ' <span data-ymulti-selected-count>(0)</span></button></div></form>';
      echo '<div class="ypm-table-wrap"><table id="ypm-string-table" class="widefat ypm-string-table"><thead><tr><th class="ymulti-bulk-check"><input type="checkbox" data-ymulti-bulk-all="strings" aria-label="' . esc_attr__('Select this page', 'yablonsky-multilingual') . '"></th><th>' . esc_html__('Domain / context', 'yablonsky-multilingual') . '</th><th>' . esc_html__('Source', 'yablonsky-multilingual') . '</th><th>' . esc_html__('Local translation', 'yablonsky-multilingual') . '</th><th class="ypm-col-action">' . esc_html__('Action', 'yablonsky-multilingual') . '</th></tr></thead><tbody>';
      foreach ($rows as $row) {
        $delete = wp_nonce_url(admin_url('admin-post.php?action=ypm_string_delete&id=' . (int) $row->id . '&locale=' . rawurlencode($locale)), 'ypm_string_delete_' . (int) $row->id);
        $search = strtolower(implode(' ', [(string) $row->text_domain, (string) $row->context_name, (string) $row->source_text, (string) $row->translated_text]));
        $edit_id = 'ypm-string-edit-' . (int) $row->id;
        /* translators: %s: source string. */
        $select_string_label = sprintf(__('Select string %s', 'yablonsky-multilingual'), (string) $row->source_text);
        echo '<tr data-ypm-string-search="' . esc_attr($search) . '" data-ypm-string-domain="' . esc_attr(strtolower((string) $row->text_domain)) . '"><td class="ymulti-bulk-check"><input type="checkbox" value="' . esc_attr((string) (int) $row->id) . '" data-ymulti-bulk-row="strings" aria-label="' . esc_attr($select_string_label) . '"></td><td><div class="ypm-domain-cell"><code>' . esc_html((string) $row->text_domain) . '</code>' . ((string) $row->context_name !== '' ? '<span>' . esc_html((string) $row->context_name) . '</span>' : '<span>' . esc_html__('Default context', 'yablonsky-multilingual') . '</span>') . '</div></td><td><div class="ypm-string-copy">' . esc_html((string) $row->source_text) . '</div></td><td><div class="ypm-string-copy ' . ((string) $row->translated_text === '' ? 'is-fallback' : '') . '">' . ((string) $row->translated_text !== '' ? esc_html((string) $row->translated_text) : '<em>' . esc_html__('Native fallback', 'yablonsky-multilingual') . '</em>') . '</div></td><td class="ypm-col-action"><div class="ypm-row-actions"><button type="button" class="button button-small" data-ypm-edit-row="' . esc_attr($edit_id) . '" aria-expanded="false" aria-controls="' . esc_attr($edit_id) . '">' . esc_html__('Edit', 'yablonsky-multilingual') . '</button><a class="button button-small ypm-delete-action" data-ypm-confirm="delete" href="' . esc_url($delete) . '">' . esc_html__('Delete', 'yablonsky-multilingual') . '</a></div></td></tr>';
        echo '<tr id="' . esc_attr($edit_id) . '" class="ypm-string-edit-row" hidden><td colspan="5"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ypm-string-inline-form">';
        wp_nonce_field('ypm_string_update_' . (int) $row->id, 'ypm_string_nonce');
        echo '<input type="hidden" name="action" value="ypm_string_update"><input type="hidden" name="id" value="' . esc_attr((string) (int) $row->id) . '"><input type="hidden" name="locale" value="' . esc_attr($locale) . '">';
        echo '<div class="ypm-string-inline-form__identity"><strong>' . esc_html((string) $row->text_domain) . '</strong><span>' . esc_html((string) $row->source_text) . '</span></div>';
        echo '<label><span>' . esc_html__('Local translation', 'yablonsky-multilingual') . '</span><textarea rows="3" name="translated_text" placeholder="' . esc_attr__('Use native translation', 'yablonsky-multilingual') . '">' . esc_textarea((string) $row->translated_text) . '</textarea></label>';
        echo '<div class="ypm-string-inline-form__actions"><span>' . esc_html__('Source identity stays unchanged. Native fallback stays active until you save a translation override.', 'yablonsky-multilingual') . '</span><div>';
        if ($ai_ready) echo '<button type="button" class="button" data-ypm-ai-string data-target-locale="' . esc_attr($locale) . '" data-source="' . esc_attr((string) $row->source_text) . '" data-domain="' . esc_attr((string) $row->text_domain) . '" data-context="' . esc_attr((string) $row->context_name) . '">' . esc_html__('Suggest with AI', 'yablonsky-multilingual') . '</button> ';
        echo '<button type="button" class="button" data-ypm-close-row="' . esc_attr($edit_id) . '">' . esc_html__('Cancel', 'yablonsky-multilingual') . '</button> <button class="button button-primary">' . esc_html__('Update override', 'yablonsky-multilingual') . '</button><span class="ypm-ai-feedback" aria-live="polite"></span></div></div></form></td></tr>';
      }
      echo '</tbody></table><div id="ypm-string-search-empty" class="ypm-empty-state" hidden>' . esc_html__('0 overrides on this page match your search.', 'yablonsky-multilingual') . '</div></div>';
    }
    /* translators: 1: current page number, 2: total number of pages. */
    echo '<div class="ypm-pagination"><span>' . esc_html(sprintf(__('Page %1$d of %2$d', 'yablonsky-multilingual'), $paged, $pages)) . '</span>';
    Admin::render_server_page_size(['page' => 'ypm-strings', 'locale' => $locale], $page_size, [20, 40, 80]);
    if ($pages > 1) {
      $links = paginate_links(['base' => add_query_arg(['page' => 'ypm-strings', 'locale' => $locale, 'per_page' => $page_size, 'paged' => '%#%'], admin_url('admin.php')), 'format' => '', 'current' => $paged, 'total' => $pages, 'prev_text' => '‹', 'next_text' => '›', 'type' => 'array']);
      if (is_array($links) && $links) echo '<span class="ypm-pagination__links">' . wp_kses_post(implode('', $links)) . '</span>';
    }
    echo '</div>';
    echo '</section></div>';
  }

  public static function handle_bulk(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_string_bulk', 'ypm_string_bulk_nonce');
    $locale_raw = filter_input(INPUT_POST, 'locale', FILTER_UNSAFE_RAW);
    $locale = is_string($locale_raw) ? sanitize_text_field($locale_raw) : '';
    $operation_raw = filter_input(INPUT_POST, 'bulk_operation', FILTER_UNSAFE_RAW);
    $operation = is_string($operation_raw) ? sanitize_key($operation_raw) : '';
    $ids_raw = filter_input(INPUT_POST, 'source_ids', FILTER_SANITIZE_NUMBER_INT, FILTER_REQUIRE_ARRAY);
    if (!Languages::is_active($locale) || $operation !== 'delete' || !is_array($ids_raw)) wp_die(esc_html__('Invalid string bulk request.', 'yablonsky-multilingual'));
    global $wpdb;
    $table = self::table();
    $deleted = 0;
    foreach (array_slice(array_values(array_unique(array_filter(array_map('absint', $ids_raw)))), 0, 100) as $id) {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit administrator bulk delete in YMulti's own bounded table.
      $result = $wpdb->delete($table, ['id' => $id, 'locale' => $locale], ['%d', '%s']);
      if ($result) $deleted += (int) $result;
    }
    self::$dictionary_cache = [];
    self::$domain_cache = null;
    wp_safe_redirect(add_query_arg(['page' => 'ypm-strings', 'locale' => $locale, 'bulk_deleted' => $deleted], admin_url('admin.php')));
    exit;
  }

  public static function handle_save(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_string_save', 'ypm_string_nonce');
    global $wpdb;

    $locale = isset($_POST['locale']) ? sanitize_text_field(wp_unslash($_POST['locale'])) : '';
    $domain = isset($_POST['text_domain']) ? sanitize_key(wp_unslash($_POST['text_domain'])) : '';
    $context = isset($_POST['context_name']) ? sanitize_text_field(wp_unslash($_POST['context_name'])) : '';
    $source = isset($_POST['source_text']) ? sanitize_textarea_field(wp_unslash($_POST['source_text'])) : '';
    $translated = isset($_POST['translated_text']) ? sanitize_textarea_field(wp_unslash($_POST['translated_text'])) : '';
    if (!Languages::is_active($locale) || $domain === '' || $source === '') wp_die(esc_html__('Invalid string translation.', 'yablonsky-multilingual'));

    $table = self::table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit admin write; request-local dictionary cache is invalidated below.
    $wpdb->replace($table, [
      'locale' => $locale,
      'text_domain' => $domain,
      'context_name' => $context,
      'source_hash' => sha1($source),
      'source_text' => $source,
      'translated_text' => $translated,
      'updated_at' => current_time('mysql', true),
    ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s']);

    $domains = get_option(self::OPT_DOMAINS, []);
    if (!is_array($domains)) $domains = [];
    if (!in_array($domain, $domains, true)) {
      $domains[] = $domain;
      update_option(self::OPT_DOMAINS, array_values(array_unique($domains)), false);
    }
    self::$domain_cache = null;
    self::$dictionary_cache = [];
    wp_safe_redirect(admin_url('admin.php?page=ypm-strings&locale=' . rawurlencode($locale) . '&saved=1'));
    exit;
  }

  public static function handle_update(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    $id = isset($_POST['id']) ? absint(wp_unslash($_POST['id'])) : 0;
    check_admin_referer('ypm_string_update_' . $id, 'ypm_string_nonce');
    $locale = isset($_POST['locale']) ? sanitize_text_field(wp_unslash($_POST['locale'])) : '';
    $translated = isset($_POST['translated_text']) ? sanitize_textarea_field(wp_unslash($_POST['translated_text'])) : '';
    if ($id <= 0 || !Languages::is_active($locale)) wp_die(esc_html__('Invalid string translation.', 'yablonsky-multilingual'));

    global $wpdb;
    $table = self::table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit admin write; request-local dictionary cache is invalidated below.
    $wpdb->update(
      $table,
      ['translated_text' => $translated, 'updated_at' => current_time('mysql', true)],
      ['id' => $id, 'locale' => $locale],
      ['%s', '%s'],
      ['%d', '%s']
    );
    self::$dictionary_cache = [];
    wp_safe_redirect(admin_url('admin.php?page=ypm-strings&locale=' . rawurlencode($locale) . '&updated=1'));
    exit;
  }

  public static function handle_delete(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    $id = (int) filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    check_admin_referer('ypm_string_delete_' . $id);
    $locale_raw = filter_input(INPUT_GET, 'locale', FILTER_UNSAFE_RAW);
    $locale = is_string($locale_raw) ? sanitize_text_field($locale_raw) : Languages::default_code();
    global $wpdb;
    $table = self::table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit admin delete; request-local dictionary cache is invalidated below.
    $wpdb->delete($table, ['id' => $id], ['%d']);
    self::$dictionary_cache = [];
    wp_safe_redirect(admin_url('admin.php?page=ypm-strings&locale=' . rawurlencode($locale) . '&deleted=1'));
    exit;
  }
}
