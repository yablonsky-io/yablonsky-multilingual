<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Native taxonomy administration for multilingual terms.
 */
final class AdminTerms {
  private const VISIBLE_LOCALE_LIMIT = 5;
  private const WORKSPACE_PAGE_SIZE = 40;
  private static bool $list_primed = false;

  public static function init(): void {
    add_action('init', [__CLASS__, 'register_taxonomy_hooks'], 50);
    add_action('admin_menu', [__CLASS__, 'register_workspace_page']);
    add_action('admin_menu', [__CLASS__, 'register_translation_page']);
    add_action('admin_post_ypm_create_term_translation', [__CLASS__, 'handle_create_translation']);
    add_action('wp_ajax_ypm_term_translation_family', [__CLASS__, 'ajax_translation_family']);
    add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    add_action('admin_notices', [__CLASS__, 'maybe_show_translation_notice']);
  }

  public static function enqueue_assets(string $hook): void {
    unset($hook);
    $page_raw = filter_input(INPUT_GET, 'page', FILTER_UNSAFE_RAW);
    $page = is_string($page_raw) ? sanitize_key($page_raw) : '';
    $screen = get_current_screen();
    if (!$screen) return;
    $taxonomy = isset($screen->taxonomy) ? (string) $screen->taxonomy : '';
    $base = (string) ($screen->base ?? '');
    $is_workspace = $page === 'ypm-taxonomies';
    if (!$is_workspace && (!in_array($base, ['edit-tags', 'term'], true) || !TermTranslations::is_supported_taxonomy($taxonomy))) return;

    wp_enqueue_style(
      'ypm-admin-content',
      YPM_ASSET_URL . 'admin-content.css',
      [],
      YPM_VERSION
    );
    wp_enqueue_style('ymulti-language-ui', YPM_ASSET_URL . 'language-ui.css', [], YPM_VERSION);
    wp_enqueue_script('ymulti-language-ui', YPM_ASSET_URL . 'language-ui.js', [], YPM_VERSION, true);
    LanguageUI::localize_script();
    wp_enqueue_script(
      'ypm-admin-content',
      YPM_ASSET_URL . 'admin-content.js',
      [],
      YPM_VERSION,
      true
    );
    wp_localize_script('ypm-admin-content', 'YPMAdminContent', AdminContent::drawer_config());
  }

  public static function maybe_show_translation_notice(): void {
    $created_raw = filter_input(INPUT_GET, 'ypm_translation_created', FILTER_UNSAFE_RAW);
    if (!is_string($created_raw) || $created_raw !== '1') return;
    $screen = get_current_screen();
    if (!$screen) return;
    $taxonomy = isset($screen->taxonomy) ? (string) $screen->taxonomy : '';
    if (!TermTranslations::is_supported_taxonomy($taxonomy) || !self::can_manage_taxonomy($taxonomy)) return;

    echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__('YMulti translation created and linked.', 'yablonsky-multilingual') . '</strong> ' . esc_html__('The new term remains an independent WordPress taxonomy object for this locale.', 'yablonsky-multilingual') . ' <a href="' . esc_url(add_query_arg(['page' => 'ypm-taxonomies', 'taxonomy' => $taxonomy], admin_url('admin.php'))) . '">' . esc_html__('Open Taxonomies workspace', 'yablonsky-multilingual') . '</a></p></div>';
  }

  public static function register_taxonomy_hooks(): void {
    foreach (Languages::supported_taxonomies() as $taxonomy) {
      add_action("{$taxonomy}_add_form_fields", [__CLASS__, 'render_add_fields']);
      add_action("{$taxonomy}_edit_form_fields", [__CLASS__, 'render_edit_fields'], 10, 2);
      add_action("created_{$taxonomy}", [__CLASS__, 'initialize_created_term'], 10, 2);
      add_action("edited_{$taxonomy}", [__CLASS__, 'save_edited_term'], 10, 2);
      add_filter("manage_edit-{$taxonomy}_columns", [__CLASS__, 'columns']);
      add_filter("manage_{$taxonomy}_custom_column", [__CLASS__, 'render_column'], 10, 3);
    }
  }

  public static function register_workspace_page(): void {
    $taxonomies = self::manageable_taxonomies();
    if (!$taxonomies) return;

    $object = get_taxonomy($taxonomies[0]);
    $capability = ($object && !empty($object->cap->manage_terms)) ? (string) $object->cap->manage_terms : 'manage_options';
    add_submenu_page(
      'ypm-multilingual',
      __('Taxonomies', 'yablonsky-multilingual'),
      __('Taxonomies', 'yablonsky-multilingual'),
      $capability,
      'ypm-taxonomies',
      [__CLASS__, 'render_workspace_page']
    );
  }

  public static function register_translation_page(): void {
    add_submenu_page(
      null,
      __('Create term translation', 'yablonsky-multilingual'),
      __('Create term translation', 'yablonsky-multilingual'),
      'read',
      'ypm-term-translation',
      [__CLASS__, 'render_translation_page']
    );
  }

  /**
   * Return supported taxonomies the current user can manage.
   *
   * @return string[]
   */
  public static function manageable_taxonomies(): array {
    $out = [];
    foreach (Languages::supported_taxonomies() as $taxonomy) {
      $object = get_taxonomy($taxonomy);
      if (!$object || empty($object->cap->manage_terms)) continue;
      if (current_user_can((string) $object->cap->manage_terms)) $out[] = (string) $taxonomy;
    }
    return array_values(array_unique($out));
  }

  /** Apply native YMulti term-language identity to a WP_Term_Query argument set. */
  private static function apply_native_term_language_filter(array &$args, string $language): void {
    if ($language === '') return;
    if ($language === Languages::default_code()) {
      // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded admin term-language identity lookup; this is the canonical native YMulti term language store.
      $args['meta_query'] = [
        'relation' => 'OR',
        [
          'key' => TermTranslations::META_LANGUAGE,
          'value' => $language,
          'compare' => '=',
        ],
        [
          'key' => TermTranslations::META_LANGUAGE,
          'compare' => 'NOT EXISTS',
        ],
      ];
      return;
    }
    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded admin term-language identity lookup; this is the canonical native YMulti term language store.
    $args['meta_query'] = [[
      'key' => TermTranslations::META_LANGUAGE,
      'value' => $language,
      'compare' => '=',
    ]];
  }

  /**
   * Read one WPML taxonomy family per row in bridge mode. The incumbent graph
   * remains read-only; YMulti only selects the representative term used by the
   * workspace. Source filtering and pagination are therefore provider-aware.
   *
   * @return array{0:array<int,\WP_Term>,1:int}
   */
  private static function wpml_bridge_term_catalog(string $taxonomy, string $source_locale, string $search, int $limit, int $offset): array {
    global $wpdb;
    $table = RuntimeAuthority::wpml_translation_table();
    $element_type = 'tax_' . sanitize_key($taxonomy);
    $where = ['root.trid > 0', 'root.element_type = %s', 'rtt.taxonomy = %s'];
    $args = [$element_type, $taxonomy];

    $member = ['fm.element_type = %s', 'mtt.taxonomy = %s'];
    $member_args = [$element_type, $taxonomy];
    if ($search !== '') {
      $like = '%' . $wpdb->esc_like($search) . '%';
      $member[] = '(mt.name LIKE %s OR mt.slug LIKE %s)';
      $member_args[] = $like;
      $member_args[] = $like;
    }
    if ($source_locale !== '') {
      $provider_code = RuntimeAuthority::provider_code_for_language($source_locale);
      if ($provider_code === '') {
        $member[] = '1 = 0';
      } else {
        $member[] = 'fm.language_code = %s';
        $member_args[] = $provider_code;
      }
    }
    $member_sql = implode(' AND ', $member);
    $where[] = 'EXISTS (SELECT 1 FROM ' . esc_sql($table) . ' fm INNER JOIN ' . $wpdb->term_taxonomy . ' mtt ON mtt.term_taxonomy_id = fm.element_id INNER JOIN ' . $wpdb->terms . ' mt ON mt.term_id = mtt.term_id WHERE fm.trid = root.trid AND ' . $member_sql . ')';
    $args = array_merge($args, $member_args);
    $where_sql = implode(' AND ', $where);

    $count_sql = 'SELECT COUNT(DISTINCT root.trid) FROM ' . esc_sql($table) . ' root INNER JOIN ' . $wpdb->term_taxonomy . ' rtt ON rtt.term_taxonomy_id = root.element_id WHERE ' . $where_sql;
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only incumbent taxonomy family count; all request values use prepare() placeholders.
    $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $args));

    $preferred = $source_locale !== '' ? RuntimeAuthority::provider_code_for_language($source_locale) : RuntimeAuthority::provider_code_for_language(RuntimeAuthority::default_language());
    if ($preferred === '') $preferred = '__ymulti_unmapped__';
    // The representative must itself satisfy Source/Search. Otherwise a family
    // could match on RU/LT while rendering its EN member, recreating the exact
    // filter/display mismatch this bridge layer is meant to prevent.
    $pick_sql = strtr($member_sql, ['fm.' => 'pick.', 'mtt.' => 'ptt.', 'mt.' => 'pt.']);
    $row_sql = 'SELECT COALESCE(MIN(CASE WHEN pick.language_code = %s THEN ptt.term_id END),MIN(CASE WHEN pick.source_language_code IS NULL THEN ptt.term_id END),MIN(ptt.term_id)) AS term_id, root.trid AS provider_group '
      . 'FROM ' . esc_sql($table) . ' root INNER JOIN ' . $wpdb->term_taxonomy . ' rtt ON rtt.term_taxonomy_id = root.element_id '
      . 'INNER JOIN ' . esc_sql($table) . ' pick ON pick.trid = root.trid INNER JOIN ' . $wpdb->term_taxonomy . ' ptt ON ptt.term_taxonomy_id = pick.element_id INNER JOIN ' . $wpdb->terms . ' pt ON pt.term_id = ptt.term_id '
      . 'WHERE ' . $where_sql . ' AND ' . $pick_sql . ' GROUP BY root.trid ORDER BY MIN(pt.name) ASC, term_id ASC LIMIT %d OFFSET %d';
    $row_args = array_merge([$preferred], $args, $member_args, [$limit, $offset]);
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only incumbent taxonomy family pagination; all request values use prepare() placeholders.
    $rows = $wpdb->get_results($wpdb->prepare($row_sql, $row_args));
    $terms = [];
    if (is_array($rows)) foreach ($rows as $row) {
      $term_id = absint($row->term_id ?? 0);
      if ($term_id <= 0) continue;
      $term = get_term($term_id, $taxonomy);
      if ($term instanceof \WP_Term) $terms[] = $term;
    }
    return [$terms, $total];
  }

  /**
   * Native provider-neutral term catalog. Physical translated terms are folded
   * to one logical family before count/pagination, matching the bridge catalog.
   * The scan is chunked so memory stays bounded; a concrete Source filter is
   * applied by WordPress first and therefore naturally yields that family member.
   *
   * @return array{0:array<int,\WP_Term>,1:int}
   */
  private static function native_term_catalog(string $taxonomy, string $source_locale, string $search, int $limit, int $offset): array {
    $taxonomy = sanitize_key($taxonomy);
    $limit = max(1, $limit);
    $offset = max(0, $offset);
    $chunk = 500;
    $scan_offset = 0;
    $families = [];
    $page_ids = [];
    $matched = 0;

    while (true) {
      $args = [
        'taxonomy' => $taxonomy,
        'hide_empty' => false,
        'fields' => 'ids',
        'number' => $chunk,
        'offset' => $scan_offset,
        'orderby' => 'term_id',
        'order' => 'ASC',
      ];
      if ($search !== '') $args['search'] = $search;
      if ($source_locale !== '') self::apply_native_term_language_filter($args, $source_locale);
      $ids = get_terms($args);
      if (is_wp_error($ids) || !is_array($ids) || !$ids) break;
      $ids = array_values(array_filter(array_map('absint', $ids)));
      TermTranslations::prime_terms($ids);
      TermTranslations::prime_translation_families($ids, $taxonomy);
      foreach ($ids as $term_id) {
        if ($source_locale !== '' && EntityContract::term_source_locale($term_id, $taxonomy) !== $source_locale) continue;
        $key = EntityContract::term_family_key($term_id, $taxonomy);
        if (isset($families[$key])) continue;
        $families[$key] = true;
        if ($matched >= $offset && count($page_ids) < $limit) $page_ids[] = $term_id;
        $matched++;
      }
      $scan_offset += count($ids);
      if (count($ids) < $chunk) break;
    }

    $terms = [];
    foreach ($page_ids as $term_id) {
      $term = get_term($term_id, $taxonomy);
      if ($term instanceof \WP_Term) $terms[] = $term;
    }
    return [$terms, $matched];
  }

  /** @return int[] */
  public static function workspace_term_ids(string $taxonomy, string $source_locale = '', string $search = '', int $limit = 5000): array {
    $taxonomy = sanitize_key($taxonomy);
    $source_locale = sanitize_text_field($source_locale);
    $search = sanitize_text_field($search);
    $limit = max(1, min(5000, $limit));
    if (!TermTranslations::is_supported_taxonomy($taxonomy)) return [];

    if (class_exists(RuntimeAuthority::class, false) && RuntimeAuthority::wpml_translation_table_available()) {
      $ids = [];
      $offset = 0;
      $chunk = min(200, $limit);
      while (count($ids) < $limit) {
        [$terms] = self::wpml_bridge_term_catalog($taxonomy, $source_locale, $search, min($chunk, $limit - count($ids)), $offset);
        if (!$terms) break;
        foreach ($terms as $term) if ($term instanceof \WP_Term && !in_array((int) $term->term_id, $ids, true)) $ids[] = (int) $term->term_id;
        $offset += count($terms);
        if (count($terms) < $chunk) break;
      }
      return $ids;
    }

    [$terms] = self::native_term_catalog($taxonomy, $source_locale, $search, $limit, 0);
    return array_values(array_map(static fn(\WP_Term $term): int => (int) $term->term_id, $terms));
  }

  public static function render_workspace_page(): void {
    $taxonomies = self::manageable_taxonomies();
    if (!$taxonomies) wp_die(esc_html__('A supported taxonomy with management permission is required for this action.', 'yablonsky-multilingual'));

    $taxonomy_raw = filter_input(INPUT_GET, 'taxonomy', FILTER_UNSAFE_RAW);
    $taxonomy = is_string($taxonomy_raw) ? sanitize_key($taxonomy_raw) : '';
    if ($taxonomy === '' || !in_array($taxonomy, $taxonomies, true)) $taxonomy = $taxonomies[0];

    $active = Languages::active_codes();
    $search_raw = filter_input(INPUT_GET, 's', FILTER_UNSAFE_RAW);
    $search = is_string($search_raw) ? sanitize_text_field(wp_unslash($search_raw)) : '';
    $source_raw = filter_input(INPUT_GET, 'source_locale', FILTER_UNSAFE_RAW);
    $source_locale = is_string($source_raw) ? sanitize_text_field(wp_unslash($source_raw)) : '';
    if ($source_locale !== '' && !in_array($source_locale, $active, true)) $source_locale = '';
    $target_raw = filter_input(INPUT_GET, 'target_locale', FILTER_UNSAFE_RAW);
    $target_locale = is_string($target_raw) ? sanitize_text_field(wp_unslash($target_raw)) : '';
    if ($target_locale === '' && class_exists('Yablonsky\Multilingual\AdminLanguageContext')) $target_locale = AdminLanguageContext::preferred_target();
    if ($target_locale !== '' && !in_array($target_locale, $active, true)) $target_locale = '';

    $paged_raw = filter_input(INPUT_GET, 'paged', FILTER_VALIDATE_INT);
    $paged = max(1, $paged_raw ? (int) $paged_raw : 1);
    $page_size = Admin::requested_page_size(self::WORKSPACE_PAGE_SIZE);
    $offset = ($paged - 1) * $page_size;

    if (class_exists(RuntimeAuthority::class, false) && RuntimeAuthority::wpml_translation_table_available()) {
      [$terms, $total] = self::wpml_bridge_term_catalog($taxonomy, $source_locale, $search, $page_size, $offset);
    } else {
      [$terms, $total] = self::native_term_catalog($taxonomy, $source_locale, $search, $page_size, $offset);
    }

    $pages = max(1, (int) ceil($total / $page_size));
    if ($paged > $pages && $total > 0) {
      wp_safe_redirect(add_query_arg([
        'page' => 'ypm-taxonomies',
        'taxonomy' => $taxonomy,
        's' => $search,
        'source_locale' => $source_locale,
        'target_locale' => $target_locale,
        'paged' => $pages,
        'per_page' => $page_size,
      ], admin_url('admin.php')));
      exit;
    }

    $term_ids = [];
    foreach ($terms as $term) if ($term instanceof \WP_Term) $term_ids[] = (int) $term->term_id;
    if ($term_ids) {
      TermTranslations::prime_terms($term_ids);
      TermTranslations::prime_translation_families($term_ids, $taxonomy);
    }

    $tax_object = get_taxonomy($taxonomy);
    $taxonomy_label = $tax_object && isset($tax_object->labels->name) ? (string) $tax_object->labels->name : $taxonomy;
    $native_url = self::taxonomy_admin_url($taxonomy);

    echo '<div class="wrap ypm-admin ypm-admin--taxonomies">';
    Admin::render_shell_header(
      __('Taxonomies', 'yablonsky-multilingual'),
      __('Manage multilingual category, tag and commerce term families from one bounded queue while keeping editing in native WordPress taxonomy screens.', 'yablonsky-multilingual'),
      'neutral',
      __('Native editor', 'yablonsky-multilingual')
    );

    if (class_exists('Yablonsky\Multilingual\AITranslation')) {
      $text_setup = AITranslation::text_setup_status();
      if (empty($text_setup['ready'])) AIAdmin::render_text_compute_gate($text_setup, add_query_arg(['page'=>'ypm-taxonomies','taxonomy'=>$taxonomy], admin_url('admin.php')));
    }

    $taxonomy_site_coverage = SiteCoverage::terms([$taxonomy]);
    echo '<div class="ypm-metrics ypm-metrics--4">';
    Admin::render_metric(__('Taxonomy types', 'yablonsky-multilingual'), (string) count($taxonomies), __('Available translation workspaces', 'yablonsky-multilingual'), 'neutral');
    Admin::render_metric(__('Translation families', 'yablonsky-multilingual'), (string) $total, $taxonomy_label, 'neutral');
    Admin::render_metric(__('Coverage', 'yablonsky-multilingual'), (string) $taxonomy_site_coverage['percent'] . '%', (string) $taxonomy_site_coverage['detail'], (string) $taxonomy_site_coverage['state']);
    Admin::render_metric(__('Active locales', 'yablonsky-multilingual'), (string) count($active), __('Translation coverage target', 'yablonsky-multilingual'), 'neutral');
    echo '</div>';

    echo '<section class="ypm-card ypm-card--standalone"><div class="ypm-card__head ypm-card__head--toolbar"><div><span class="ypm-eyebrow">' . esc_html__('Term translation queue', 'yablonsky-multilingual') . '</span><h2>' . esc_html($taxonomy_label) . '</h2><p class="ypm-card__copy">' . esc_html__('Each locale keeps its own WordPress term ID and slug. YMulti stores only language identity and the translation-family relationship.', 'yablonsky-multilingual') . '</p></div><div class="ypm-toolbar-group">';
    echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="ypm-taxonomy-selector"><input type="hidden" name="page" value="ypm-taxonomies"><input type="hidden" name="per_page" value="' . esc_attr((string) $page_size) . '"><label class="screen-reader-text" for="ypm-taxonomy-select">' . esc_html__('Taxonomy', 'yablonsky-multilingual') . '</label><select id="ypm-taxonomy-select" name="taxonomy" onchange="this.form.submit()">';
    foreach ($taxonomies as $candidate) {
      $candidate_object = get_taxonomy($candidate);
      $candidate_label = $candidate_object && isset($candidate_object->labels->name) ? (string) $candidate_object->labels->name : $candidate;
      echo '<option value="' . esc_attr($candidate) . '"' . selected($taxonomy, $candidate, false) . '>' . esc_html($candidate_label) . '</option>';
    }
    echo '</select><input type="hidden" name="source_locale" value="' . esc_attr($source_locale) . '"><input type="hidden" name="target_locale" value="' . esc_attr($target_locale) . '"></form>';
    echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="ypm-workspace-filter-form ypm-workspace-filter-form--compact"><input type="hidden" name="page" value="ypm-taxonomies"><input type="hidden" name="taxonomy" value="' . esc_attr($taxonomy) . '"><input type="hidden" name="per_page" value="' . esc_attr((string) $page_size) . '">';
    echo '<label class="ypm-workspace-filter"><span>' . esc_html__('Source language', 'yablonsky-multilingual') . '</span><select name="source_locale"><option value="">' . esc_html__('Any source language', 'yablonsky-multilingual') . '</option>';
    foreach ($active as $code) echo '<option value="' . esc_attr($code) . '"' . selected($source_locale, $code, false) . '>' . esc_html(Languages::display_name($code) . ' · ' . Languages::display_code($code)) . '</option>';
    echo '</select></label>';
    echo '<label class="ypm-workspace-filter"><span>' . esc_html__('Target language', 'yablonsky-multilingual') . '</span><select id="ymulti-taxonomy-target-language" name="target_locale"><option value="">' . esc_html__('Any target language', 'yablonsky-multilingual') . '</option>';
    foreach ($active as $code) echo '<option value="' . esc_attr($code) . '"' . selected($target_locale, $code, false) . '>' . esc_html(Languages::display_name($code) . ' · ' . Languages::display_code($code)) . '</option>';
    echo '</select></label>';
    echo '<label class="ypm-workspace-filter ypm-workspace-filter--search"><span>' . esc_html__('Search', 'yablonsky-multilingual') . '</span><input id="ypm-taxonomy-search" type="search" name="s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Name or slug', 'yablonsky-multilingual') . '"></label>';
    echo '<div class="ypm-workspace-filter-actions"><button class="button button-primary">' . esc_html__('Apply filters', 'yablonsky-multilingual') . '</button><a class="button" href="' . esc_url(add_query_arg(['page'=>'ypm-taxonomies','taxonomy'=>$taxonomy], admin_url('admin.php'))) . '">' . esc_html__('Reset', 'yablonsky-multilingual') . '</a></div></form>';
    echo '<label class="screen-reader-text" for="ypm-taxonomy-state">' . esc_html__('Coverage', 'yablonsky-multilingual') . '</label><select id="ypm-taxonomy-state" aria-label="' . esc_attr__('Coverage', 'yablonsky-multilingual') . '"><option value="all">' . esc_html__('All visible terms', 'yablonsky-multilingual') . '</option><option value="open">' . esc_html__('Needs translation', 'yablonsky-multilingual') . '</option><option value="complete">' . esc_html__('Complete', 'yablonsky-multilingual') . '</option></select>';
    if ($native_url !== '') echo '<a class="button" href="' . esc_url($native_url) . '">' . esc_html__('Open native taxonomy', 'yablonsky-multilingual') . '</a>';
    echo '</div></div>';

    if (class_exists('Yablonsky\\Multilingual\\AITermJobs') && $terms) AITermJobs::render_panel($taxonomy, $terms, $target_locale, $search, $total, $source_locale);

    if (!$terms) {
      echo '<div class="ypm-empty-panel"><span class="dashicons dashicons-category" aria-hidden="true"></span><strong>' . esc_html($search !== '' ? __('0 terms match this view', 'yablonsky-multilingual') : __('Taxonomy terms: 0 in current scope', 'yablonsky-multilingual')) . '</strong><p>' . esc_html($search !== '' ? __('Try another search or clear the current query.', 'yablonsky-multilingual') : __('Create terms in the native WordPress taxonomy screen; YMulti attaches multilingual identity while the native editor remains authoritative.', 'yablonsky-multilingual')) . '</p></div>';
    } else {
      echo '<div class="ypm-table-wrap"><table class="widefat ymulti-entity-table ymulti-entity-table--terms ypm-taxonomy-table"><thead><tr><th class="ymulti-bulk-check"><input type="checkbox" data-ymulti-bulk-all="taxonomy" aria-label="' . esc_attr__('Select this page', 'yablonsky-multilingual') . '"></th><th>' . esc_html__('Term', 'yablonsky-multilingual') . '</th><th class="ymulti-col-source">' . esc_html__('Source', 'yablonsky-multilingual') . '</th><th>' . esc_html__('Coverage', 'yablonsky-multilingual') . '</th><th>' . esc_html__('Translations', 'yablonsky-multilingual') . '</th><th class="ypm-col-action">' . esc_html__('Action', 'yablonsky-multilingual') . '</th></tr></thead><tbody>';
      foreach ($terms as $term) {
        if (!($term instanceof \WP_Term)) continue;
        $term_id = (int) $term->term_id;
        $assigned = TermTranslations::assigned_term_language($term_id);
        $effective = EntityContract::term_family($term_id, $taxonomy, $active);
        $map = is_array($effective['translations'] ?? null) ? $effective['translations'] : [];
        $current = sanitize_text_field((string) ($effective['source'] ?? ''));
        if ($current === '') $current = $assigned ?: Languages::default_code();
        if (!isset($map[$current])) $map[$current] = $term_id;
        $coverage = count(array_intersect(array_keys($map), $active));
        $missing = array_values(array_diff($active, array_keys($map)));
        $visible = self::visible_locales($active, $current, $map);
        $edit_url = get_edit_term_link($term_id, $taxonomy, '');

        /* translators: %s: taxonomy term name. */
        $select_term_label = sprintf(__('Select %s', 'yablonsky-multilingual'), $term->name);
        echo '<tr data-ypm-taxonomy-state="' . esc_attr($missing ? 'open' : 'complete') . '"><td class="ymulti-bulk-check"><input type="checkbox" value="' . esc_attr((string) $term_id) . '" data-ymulti-bulk-row="taxonomy" aria-label="' . esc_attr($select_term_label) . '"></td><td><div class="ypm-term-cell"><strong>' . esc_html($term->name) . '</strong><code>' . esc_html($term->slug) . '</code></div></td>';
        $assumed_default = $assigned === null && empty($effective['bridged']) && $current === Languages::default_code();
        echo '<td class="ymulti-col-source">' . wp_kses_post(Admin::language_badge($current)) . ($assumed_default ? '<small class="ypm-assumed-label">' . esc_html__('assumed default', 'yablonsky-multilingual') . '</small>' : '') . '</td>';
        echo '<td><div class="ypm-coverage-cell"><strong>' . esc_html((string) $coverage) . '/' . esc_html((string) count($active)) . '</strong><span class="ypm-coverage-bar" aria-hidden="true"><i style="width:' . esc_attr((string) (count($active) > 0 ? min(100, round(($coverage / count($active)) * 100)) : 0)) . '%"></i></span><small>' . esc_html($missing ? __('Needs translation', 'yablonsky-multilingual') : __('Complete', 'yablonsky-multilingual')) . '</small></div></td>';
        echo '<td><div class="ypm-family">';
        foreach ($visible as $code) {
          if (isset($map[$code])) {
            $target_id = (int) $map[$code];
            if ($target_id === $term_id) {
              echo '<span class="ypm-family-chip is-current">' . esc_html(Languages::display_code($code)) . '</span>';
            } else {
              $target_url = get_edit_term_link($target_id, $taxonomy, '');
              if ($target_url) echo '<a class="ypm-family-chip is-existing" href="' . esc_url($target_url) . '">' . esc_html(Languages::display_code($code)) . '</a>';
            }
          } else {
            echo '<a class="ypm-family-chip is-missing" href="' . esc_url(self::create_translation_url($term_id, $taxonomy, $code)) . '">+ ' . esc_html(Languages::display_code($code)) . '</a>';
          }
        }
        $hidden = max(0, count($active) - count($visible));
        if ($hidden > 0) {
          $family_fallback = $edit_url ?: self::taxonomy_admin_url($taxonomy);
          echo '<a class="ypm-family-chip ypm-family-more is-overflow" data-term-id="' . esc_attr((string) $term_id) . '" data-taxonomy="' . esc_attr($taxonomy) . '" href="' . esc_url($family_fallback) . '">+' . esc_html((string) $hidden) . '</a>';
        }
        echo '</div></td><td class="ymulti-col-action ypm-col-action"><div class="ypm-row-actions">';
        $action_target = '';
        if ($target_locale !== '') {
          if ($target_locale === $current) {
            if ($edit_url) echo '<a class="button button-small" href="' . esc_url($edit_url) . '">' . esc_html__('Edit source', 'yablonsky-multilingual') . '</a>';
          } elseif (isset($map[$target_locale])) {
            $target_edit = get_edit_term_link((int) $map[$target_locale], $taxonomy, '');
            if ($target_edit) echo '<a class="button button-small button-primary" href="' . esc_url($target_edit) . '">' . esc_html__('Edit target', 'yablonsky-multilingual') . '</a>';
          } else {
            $action_target = $target_locale;
            echo '<a class="button button-small" href="' . esc_url(self::create_translation_url($term_id, $taxonomy, $action_target)) . '">' . esc_html__('Create target', 'yablonsky-multilingual') . '</a>';
          }
        } else {
          $action_target = (string) ($missing[0] ?? '');
          if ($action_target !== '') echo '<a class="button button-small" href="' . esc_url(self::create_translation_url($term_id, $taxonomy, $action_target)) . '">' . esc_html__('Create next', 'yablonsky-multilingual') . '</a>';
          if ($edit_url) echo '<a class="button button-small" href="' . esc_url($edit_url) . '">' . esc_html__('Edit source', 'yablonsky-multilingual') . '</a>';
        }
        if ($action_target !== '' && class_exists('Yablonsky\Multilingual\AITranslation') && AITranslation::ready() && AITranslation::current_user_can_compute() && class_exists('Yablonsky\Multilingual\AITermJobs') && !AITermJobs::is_running()) {
          echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ymulti-inline-form">';
          wp_nonce_field('ypm_ai_term_job_start', 'ypm_ai_term_job_nonce');
          echo '<input type="hidden" name="action" value="ypm_ai_term_job_start"><input type="hidden" name="taxonomy" value="' . esc_attr($taxonomy) . '"><input type="hidden" name="target_locale" value="' . esc_attr($action_target) . '"><input type="hidden" name="mode" value="selected"><input type="hidden" name="source_ids[]" value="' . esc_attr((string) $term_id) . '"><button class="button button-small button-primary ymulti-ai-cta">' . esc_html__('AI draft', 'yablonsky-multilingual') . '</button></form>';
        }
        $family_fallback = $edit_url ?: self::taxonomy_admin_url($taxonomy);
        echo '<a class="button button-small ypm-family-more" data-term-id="' . esc_attr((string) $term_id) . '" data-taxonomy="' . esc_attr($taxonomy) . '" href="' . esc_url($family_fallback) . '">' . esc_html__('Open family', 'yablonsky-multilingual') . '</a>';
        echo '</div></td></tr>';
      }
      echo '</tbody></table><div id="ypm-taxonomy-filter-empty" class="ypm-empty-state" hidden>' . esc_html__('0 visible terms match this coverage filter.', 'yablonsky-multilingual') . '</div></div>';
    }

    $visible_count = count($terms);
    $start = $total > 0 && $visible_count > 0 ? $offset + 1 : 0;
    $end = $visible_count > 0 ? min($total, $offset + $visible_count) : 0;
    echo '<div class="ypm-pagination"><span>' . esc_html(sprintf(
      /* translators: 1: first visible result, 2: last visible result, 3: total matching results. */
      __('Showing %1$d–%2$d of %3$d', 'yablonsky-multilingual'),
      $start,
      $end,
      $total
    )) . '</span>';
    Admin::render_server_page_size([
      'page' => 'ypm-taxonomies',
      'taxonomy' => $taxonomy,
      's' => $search,
      'source_locale' => $source_locale,
      'target_locale' => $target_locale,
    ], $page_size, [20, 40, 80]);
    if ($pages > 1) {
      $links = paginate_links([
        'base' => add_query_arg(['page' => 'ypm-taxonomies', 'taxonomy' => $taxonomy, 's' => $search, 'source_locale' => $source_locale, 'per_page' => $page_size, 'target_locale' => $target_locale, 'paged' => '%#%'], admin_url('admin.php')),
        'format' => '',
        'current' => $paged,
        'total' => $pages,
        'prev_text' => '‹',
        'next_text' => '›',
        'type' => 'array',
      ]);
      if (is_array($links) && $links) echo '<span class="ypm-pagination__links">' . wp_kses_post(implode('', $links)) . '</span>';
    }
    echo '</div>';
    echo '</section>';

    echo '</div>';
  }

  public static function render_add_fields(string $taxonomy): void {
    if (!TermTranslations::is_supported_taxonomy($taxonomy)) return;
    wp_nonce_field('ypm_save_term_language', 'ypm_term_nonce');
    echo '<div class="form-field term-ypm-language-wrap"><label for="ypm-term-language">' . esc_html__('YMulti language', 'yablonsky-multilingual') . '</label>';
    echo '<select id="ypm-term-language" name="ypm_term_language">';
    foreach (Languages::active_codes() as $code) {
      echo '<option value="' . esc_attr($code) . '"' . selected(Languages::default_code(), $code, false) . '>' . esc_html(Languages::display_name($code) . ' (' . Languages::display_code($code) . ')') . '</option>';
    }
    echo '</select><p>' . esc_html__('New terms start a new translation family. Add translations later from the term edit screen.', 'yablonsky-multilingual') . '</p></div>';
  }

  public static function render_edit_fields(\WP_Term $term, string $taxonomy): void {
    if (!TermTranslations::is_supported_taxonomy($taxonomy)) return;

    $assigned = TermTranslations::assigned_term_language((int) $term->term_id);
    $current = RuntimeAuthority::effective_term_language((int) $term->term_id, $taxonomy);
    if ($current === '') $current = $assigned ?: Languages::default_code();
    TermTranslations::ensure_group_id((int) $term->term_id);
    $effective = EntityContract::term_family((int) $term->term_id, $taxonomy, Languages::active_codes());
    $by_language = is_array($effective['translations'] ?? null) ? $effective['translations'] : [];

    wp_nonce_field('ypm_save_term_language', 'ypm_term_nonce');
    echo '<tr class="form-field term-ypm-language-wrap"><th scope="row"><label for="ypm-term-language">' . esc_html__('YMulti language', 'yablonsky-multilingual') . '</label></th><td>';
    echo '<select id="ypm-term-language" name="ypm_term_language">';
    foreach (Languages::active_codes() as $code) {
      echo '<option value="' . esc_attr($code) . '"' . selected($current, $code, false) . '>' . esc_html(Languages::display_name($code) . ' (' . Languages::display_code($code) . ')') . '</option>';
    }
    echo '</select>';
    if ($assigned === null && RuntimeAuthority::public_owner() === 'ymulti') echo '<p class="description">' . esc_html__('This term currently uses the default locale by fallback. Saving makes the assignment explicit.', 'yablonsky-multilingual') . '</p>';
    echo '</td></tr>';

    echo '<tr class="form-field term-ypm-family-wrap"><th scope="row">' . esc_html__('Translations', 'yablonsky-multilingual') . '</th><td><div class="ypm-term-family" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">';
    foreach (Languages::active_codes() as $code) {
      if (isset($by_language[$code])) {
        $target_id = (int) $by_language[$code];
        if ($target_id === (int) $term->term_id) {
          echo '<span class="button disabled" aria-disabled="true">' . esc_html(Languages::display_code($code) . ' · ' . __('Current', 'yablonsky-multilingual')) . '</span>';
        } else {
          $url = get_edit_term_link($target_id, $taxonomy, '');
          if ($url) echo '<a class="button" href="' . esc_url($url) . '">' . esc_html(Languages::display_code($code) . ' · ' . __('Edit', 'yablonsky-multilingual')) . '</a>';
        }
      } else {
        echo '<a class="button" href="' . esc_url(self::create_translation_url((int) $term->term_id, $taxonomy, $code)) . '">+ ' . esc_html(Languages::display_code($code)) . '</a>';
      }
    }
    echo '</div><p class="description">' . esc_html__('Each locale uses its own WordPress term ID and slug. YMulti keeps the family relationship separate from shared WooCommerce operational state.', 'yablonsky-multilingual') . '</p></td></tr>';
  }

  public static function initialize_created_term(int $term_id, int $term_taxonomy_id): void {
    unset($term_taxonomy_id);
    if ($term_id <= 0) return;

    $code = Languages::default_code();
    if (self::posted_term_nonce_is_valid()) {
      $posted = filter_input(INPUT_POST, 'ypm_term_language', FILTER_UNSAFE_RAW);
      if (is_string($posted)) {
        $posted = sanitize_text_field($posted);
        if (Languages::is_active($posted)) $code = $posted;
      }
    }

    TermTranslations::set_term_language($term_id, $code);
    TermTranslations::ensure_group_id($term_id);
  }

  public static function save_edited_term(int $term_id, int $term_taxonomy_id): void {
    unset($term_taxonomy_id);
    if ($term_id <= 0 || !self::posted_term_nonce_is_valid()) return;

    $term = get_term($term_id);
    if (!($term instanceof \WP_Term) || !self::can_manage_taxonomy((string) $term->taxonomy)) return;

    $posted = filter_input(INPUT_POST, 'ypm_term_language', FILTER_UNSAFE_RAW);
    $code = is_string($posted) ? sanitize_text_field($posted) : '';
    $group = TermTranslations::ensure_group_id($term_id);
    if ($code !== '' && Languages::is_active($code)) {
      $map = TermTranslations::translations_by_language_for_group((string) $term->taxonomy, $group);
      if (!isset($map[$code]) || (int) $map[$code] === $term_id) {
        TermTranslations::set_term_language($term_id, $code);
      }
    }
  }

  public static function columns(array $columns): array {
    $out = [];
    foreach ($columns as $key => $label) {
      $out[$key] = $label;
      if ($key === 'name') {
        $out['ypm_language'] = __('Source', 'yablonsky-multilingual');
        $out['ypm_translations'] = __('Translations', 'yablonsky-multilingual');
      }
    }
    return $out;
  }

  public static function render_column(string $content, string $column, int $term_id): string {
    if ($column !== 'ypm_language' && $column !== 'ypm_translations') return $content;

    $term = get_term($term_id);
    if (!($term instanceof \WP_Term)) return $content;
    $taxonomy = (string) $term->taxonomy;
    self::prime_current_list_table($taxonomy);

    if ($column === 'ypm_language') {
      $assigned = TermTranslations::assigned_term_language($term_id);
      $code = class_exists(RuntimeAuthority::class, false) ? RuntimeAuthority::effective_term_language($term_id, $taxonomy) : ($assigned ?: Languages::default_code());
      if ($code === '') $code = $assigned ?: Languages::default_code();
      $html = Admin::language_badge($code);
      if ($assigned === null && (!class_exists(RuntimeAuthority::class, false) || RuntimeAuthority::public_owner() === 'ymulti')) $html .= ' <small>' . esc_html__('assumed', 'yablonsky-multilingual') . '</small>';
      return $html;
    }

    $active = Languages::active_codes();
    $effective = EntityContract::term_family($term_id, $taxonomy, $active);
    $map = is_array($effective['translations'] ?? null) ? $effective['translations'] : [];
    $current = sanitize_text_field((string) ($effective['source'] ?? ''));
    if ($current === '') $current = EntityContract::term_source_locale($term_id, $taxonomy);
    $visible = self::visible_locales($active, $current, $map);
    $fallback = get_edit_term_link($term_id, $taxonomy, '') ?: self::taxonomy_admin_url($taxonomy);
    $coverage = count(array_intersect(array_keys($map), $active));
    $parts = [
      '<a class="ypm-family-progress ypm-family-more" data-term-id="' . esc_attr((string) $term_id) . '" data-taxonomy="' . esc_attr($taxonomy) . '" href="' . esc_url($fallback) . '" title="' . esc_attr__('Open translation family', 'yablonsky-multilingual') . '">' . esc_html((string) $coverage) . '/' . esc_html((string) count($active)) . '</a>',
    ];

    foreach ($visible as $code) {
      if (isset($map[$code])) {
        $target_id = (int) $map[$code];
        if ($target_id === $term_id) {
          $parts[] = '<span class="ypm-family-chip is-current">' . esc_html(Languages::display_code($code)) . '</span>';
        } else {
          $url = get_edit_term_link($target_id, $taxonomy, '');
          if ($url) $parts[] = '<a class="ypm-family-chip is-existing" href="' . esc_url($url) . '">' . esc_html(Languages::display_code($code)) . '</a>';
        }
      } else {
        $parts[] = '<a class="ypm-family-chip is-missing" href="' . esc_url(self::create_translation_url($term_id, $taxonomy, $code)) . '">+ ' . esc_html(Languages::display_code($code)) . '</a>';
      }
    }

    $hidden = max(0, count($active) - count($visible));
    if ($hidden > 0) {
      $parts[] = '<a class="ypm-family-chip ypm-family-more is-overflow" data-term-id="' . esc_attr((string) $term_id) . '" data-taxonomy="' . esc_attr($taxonomy) . '" href="' . esc_url($fallback) . '">+' . esc_html((string) $hidden) . '</a>';
    }
    return '<div class="ypm-family">' . implode('', $parts) . '</div>';
  }

  public static function ajax_translation_family(): void {
    check_ajax_referer('ypm_term_translation_family');
    $term_raw = filter_input(INPUT_POST, 'term_id', FILTER_VALIDATE_INT);
    $taxonomy_raw = filter_input(INPUT_POST, 'taxonomy', FILTER_UNSAFE_RAW);
    $term_id = $term_raw ? (int) $term_raw : 0;
    $taxonomy = is_string($taxonomy_raw) ? sanitize_key($taxonomy_raw) : '';
    if ($term_id <= 0 || !TermTranslations::is_supported_taxonomy($taxonomy) || !self::can_manage_taxonomy($taxonomy)) {
      wp_send_json_error(['message' => __('Forbidden translation family request.', 'yablonsky-multilingual')], 403);
    }
    $term = get_term($term_id, $taxonomy);
    if (!($term instanceof \WP_Term)) wp_send_json_error(['message' => __('Term is unavailable. Return to Taxonomies and choose an existing term.', 'yablonsky-multilingual')], 404);
    wp_send_json_success(['html' => self::family_panel_html($term, $taxonomy)]);
  }

  private static function family_panel_html(\WP_Term $term, string $taxonomy): string {
    $term_id = (int) $term->term_id;
    $active = Languages::active_codes();
    $effective = EntityContract::term_family($term_id, $taxonomy, $active);
    $map = is_array($effective['translations'] ?? null) ? $effective['translations'] : [];
    $current = sanitize_text_field((string) ($effective['source'] ?? ''));
    if ($current === '') $current = EntityContract::term_source_locale($term_id, $taxonomy);
    if (!isset($map[$current])) $map[$current] = $term_id;
    $coverage = count(array_intersect(array_keys($map), $active));
    ob_start();
    echo '<div class="ypm-family-panel"><strong class="ypm-family-panel__title">' . esc_html($term->name) . '</strong>';
    echo '<div class="ypm-family-panel__coverage">' . esc_html(sprintf(
      /* translators: 1: translated locales, 2: active locales. */
      __('%1$d of %2$d locales connected', 'yablonsky-multilingual'),
      $coverage,
      count($active)
    )) . ' · <code>' . esc_html($taxonomy) . '</code></div>';
    echo '<input class="ypm-family-search" type="search" placeholder="' . esc_attr__('Search languages…', 'yablonsky-multilingual') . '">';
    echo '<div class="ypm-family-list">';
    foreach ($active as $code) {
      $name = Languages::display_name($code);
      $display = Languages::display_code($code);
      $search = strtolower($name . ' ' . $display . ' ' . $code);
      echo '<div class="ypm-family-row" data-search="' . esc_attr($search) . '"><div class="ypm-family-row__name"><strong>' . esc_html($name) . '</strong><span>' . esc_html($code) . '</span></div><span class="ypm-family-chip ypm-family-row__code' . ($code === $current ? ' is-current' : '') . '">' . esc_html($display) . '</span><div class="ypm-family-row__action">';
      if (isset($map[$code])) {
        $target_id = (int) $map[$code];
        if ($target_id === $term_id) echo '<span class="ypm-family-status is-current">' . esc_html__('Current', 'yablonsky-multilingual') . '</span>';
        else {
          $url = get_edit_term_link($target_id, $taxonomy, '');
          if ($url) echo '<a class="button button-small" href="' . esc_url($url) . '">' . esc_html__('Edit', 'yablonsky-multilingual') . '</a>';
        }
      } else {
        echo '<a class="button button-small button-primary" href="' . esc_url(self::create_translation_url($term_id, $taxonomy, $code)) . '">' . esc_html__('Create', 'yablonsky-multilingual') . '</a>';
      }
      echo '</div></div>';
    }
    echo '</div></div>';
    return (string) ob_get_clean();
  }

  public static function render_translation_page(): void {
    $context = self::translation_context_from_request();
    if (!$context) return;

    [$source, $taxonomy, $language] = $context;
    if (!self::can_manage_taxonomy($taxonomy)) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));

    $existing = EffectiveRelations::existing_term_target((int) $source->term_id, $taxonomy, $language);
    if ($existing) {
      $url = get_edit_term_link($existing, $taxonomy, '');
      if ($url) wp_safe_redirect($url);
      exit;
    }

    $parent_target = 0;
    $parent_note = '';
    if ((int) $source->parent > 0) {
      $parent_target = (int) EffectiveRelations::existing_term_target((int) $source->parent, $taxonomy, $language);
      if ($parent_target === 0) {
        $parent_note = __('The source parent translation is ready to add for this locale. The new term starts at the taxonomy root and can be re-parented later.', 'yablonsky-multilingual');
      }
    }

    echo '<div class="wrap ypm-admin">';
    Admin::render_shell_header(
      __('Create term translation', 'yablonsky-multilingual'),
      __('Create one native WordPress taxonomy term for the target locale. YMulti links it to the source family while shared WooCommerce operational state keeps its native authority.', 'yablonsky-multilingual'),
      'neutral',
      Languages::display_code($language)
    );
    echo '<div class="ypm-family-page-bar"><a class="button" href="' . esc_url(add_query_arg(['page' => 'ypm-taxonomies', 'taxonomy' => $taxonomy], admin_url('admin.php'))) . '">← ' . esc_html__('Back to Taxonomies', 'yablonsky-multilingual') . '</a>';
    $source_edit_url = get_edit_term_link((int) $source->term_id, $taxonomy, '');
    if ($source_edit_url) echo '<a class="button" href="' . esc_url($source_edit_url) . '">' . esc_html__('Open source term', 'yablonsky-multilingual') . '</a>';
    echo '</div>';
    echo '<div class="ypm-dashboard-grid"><section class="ypm-card ypm-card--primary"><span class="ypm-eyebrow">' . esc_html__('Source term', 'yablonsky-multilingual') . '</span><h2>' . esc_html($source->name) . '</h2><p class="ypm-card__copy"><code>' . esc_html($taxonomy) . '</code> · <code>' . esc_html($source->slug) . '</code></p>';
    if ((string) $source->description !== '') echo '<div class="ypm-source-reference"><strong>' . esc_html__('Source description', 'yablonsky-multilingual') . '</strong><p>' . wp_kses_post((string) $source->description) . '</p></div>';
    echo '</section><section class="ypm-card"><span class="ypm-eyebrow">' . esc_html__('Target locale', 'yablonsky-multilingual') . '</span><h2>' . esc_html(Languages::display_name($language)) . '</h2><p class="ypm-card__copy">' . esc_html__('Enter the real localized name. YMulti generates a locale-specific slug when the slug field is blank.', 'yablonsky-multilingual') . '</p></section></div>';

    $suggestion = class_exists('Yablonsky\\Multilingual\\AITermJobs') ? AITermJobs::suggestion_for((int) $source->term_id, $taxonomy, $language) : [];
    if (!$suggestion && class_exists('Yablonsky\Multilingual\Autopilot')) $suggestion = Autopilot::term_suggestion_for((int) $source->term_id, $taxonomy, $language);
    $suggested_name = sanitize_text_field((string) ($suggestion['name'] ?? ''));
    $suggested_slug = sanitize_title((string) ($suggestion['slug'] ?? ''));
    $suggested_description = wp_kses_post((string) ($suggestion['description'] ?? ''));
    if ($suggestion) echo '<div class="ypm-callout ypm-callout--pass"><span class="dashicons dashicons-superhero-alt" aria-hidden="true"></span><div><strong>' . esc_html__('AI term suggestion loaded', 'yablonsky-multilingual') . '</strong><p>' . esc_html__('Review the prefilled fields below. Create translation submits the new term after your review.', 'yablonsky-multilingual') . '</p></div></div>';

    if ($parent_note !== '') echo '<div class="ypm-callout ypm-callout--attention"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><div><strong>' . esc_html__('Parent translation to create', 'yablonsky-multilingual') . '</strong><p>' . esc_html($parent_note) . '</p></div></div>';

    echo '<section class="ypm-card ypm-card--standalone"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('ypm_create_term_translation_' . (int) $source->term_id . '_' . $language, 'ypm_create_term_nonce');
    echo '<input type="hidden" name="action" value="ypm_create_term_translation"><input type="hidden" name="source" value="' . esc_attr((string) $source->term_id) . '"><input type="hidden" name="taxonomy" value="' . esc_attr($taxonomy) . '"><input type="hidden" name="language" value="' . esc_attr($language) . '">';
    if (class_exists('Yablonsky\Multilingual\AITranslation') && AITranslation::ready() && AITranslation::current_user_can_compute()) {
      echo '<div class="ypm-ai-suggest-bar"><div><strong>' . esc_html__('Yablonsky AI suggestion', 'yablonsky-multilingual') . '</strong><span>' . esc_html__('AI fills the fields below for review. Submit this form when the translation is ready to create.', 'yablonsky-multilingual') . '</span></div><button type="button" class="button" data-ypm-ai-term data-source-id="' . esc_attr((string) $source->term_id) . '" data-taxonomy="' . esc_attr($taxonomy) . '" data-target-locale="' . esc_attr($language) . '">' . esc_html__('Suggest with AI', 'yablonsky-multilingual') . '</button><span class="ypm-ai-feedback" aria-live="polite"></span></div>';
    }
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="ypm-term-name">' . esc_html__('Localized name', 'yablonsky-multilingual') . '</label></th><td><input id="ypm-term-name" name="name" type="text" class="regular-text" required autocomplete="off" value="' . esc_attr($suggested_name) . '"></td></tr>';
    echo '<tr><th scope="row"><label for="ypm-term-slug">' . esc_html__('Localized slug', 'yablonsky-multilingual') . '</label></th><td><input id="ypm-term-slug" name="slug" type="text" class="regular-text" autocomplete="off" value="' . esc_attr($suggested_slug) . '"><p class="description">' . esc_html__('YMulti generates it from the localized name when this field is blank.', 'yablonsky-multilingual') . '</p></td></tr>';
    echo '<tr><th scope="row"><label for="ypm-term-description">' . esc_html__('Localized description', 'yablonsky-multilingual') . '</label></th><td><textarea id="ypm-term-description" name="description" rows="6" class="large-text">' . esc_textarea($suggested_description) . '</textarea></td></tr>';
    echo '</tbody></table><p class="submit"><button type="submit" class="button button-primary">' . esc_html__('Create translation', 'yablonsky-multilingual') . '</button> <a class="button" href="' . esc_url(get_edit_term_link((int) $source->term_id, $taxonomy, '') ?: admin_url()) . '">' . esc_html__('Cancel', 'yablonsky-multilingual') . '</a></p></form></section></div>';
  }

  public static function handle_create_translation(): void {
    $source_raw = filter_input(INPUT_POST, 'source', FILTER_VALIDATE_INT);
    $taxonomy_raw = filter_input(INPUT_POST, 'taxonomy', FILTER_UNSAFE_RAW);
    $language_raw = filter_input(INPUT_POST, 'language', FILTER_UNSAFE_RAW);
    $source_id = $source_raw ? (int) $source_raw : 0;
    $taxonomy = is_string($taxonomy_raw) ? sanitize_key($taxonomy_raw) : '';
    $language = is_string($language_raw) ? sanitize_text_field($language_raw) : '';

    if ($source_id <= 0 || !TermTranslations::is_supported_taxonomy($taxonomy) || !Languages::is_active($language)) {
      wp_die(esc_html__('Invalid translation request.', 'yablonsky-multilingual'));
    }
    if (!self::can_manage_taxonomy($taxonomy)) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_create_term_translation_' . $source_id . '_' . $language, 'ypm_create_term_nonce');

    $source = get_term($source_id, $taxonomy);
    if (!($source instanceof \WP_Term)) wp_die(esc_html__('Source term is unavailable. Return to Taxonomies and choose an existing source term.', 'yablonsky-multilingual'));

    $existing = EffectiveRelations::existing_term_target($source_id, $taxonomy, $language);
    if ($existing) {
      $url = get_edit_term_link($existing, $taxonomy, '');
      if ($url) wp_safe_redirect($url);
      exit;
    }

    $name_raw = filter_input(INPUT_POST, 'name', FILTER_UNSAFE_RAW);
    $slug_raw = filter_input(INPUT_POST, 'slug', FILTER_UNSAFE_RAW);
    $description_raw = filter_input(INPUT_POST, 'description', FILTER_UNSAFE_RAW);
    $name = is_string($name_raw) ? sanitize_text_field($name_raw) : '';
    $slug = is_string($slug_raw) ? sanitize_title($slug_raw) : '';
    $description = is_string($description_raw) ? wp_kses_post($description_raw) : '';
    if ($name === '') wp_die(esc_html__('Localized name is required.', 'yablonsky-multilingual'));
    if ($slug === '') $slug = sanitize_title($name);

    $parent = 0;
    if ((int) $source->parent > 0) {
      $parent = (int) EffectiveRelations::existing_term_target((int) $source->parent, $taxonomy, $language);
    }

    $result = wp_insert_term($name, $taxonomy, [
      'slug' => $slug,
      'description' => $description,
      'parent' => $parent,
    ]);
    if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()));

    $target_id = (int) $result['term_id'];
    $group = TermTranslations::ensure_group_id($source_id);
    TermTranslations::set_group_id($target_id, $group);
    TermTranslations::set_term_language($target_id, $language);
    if (class_exists('Yablonsky\\Multilingual\\AITermJobs')) AITermJobs::clear_suggestion($source_id, $taxonomy, $language);

    do_action('yablonsky_multilingual_term_translation_created', $source_id, $target_id, $taxonomy, $language);
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Legacy YPM public compatibility hook.
    do_action('ypm_term_translation_created', $source_id, $target_id, $taxonomy, $language);

    $url = get_edit_term_link($target_id, $taxonomy, '');
    if ($url) {
      wp_safe_redirect(add_query_arg('ypm_translation_created', '1', $url));
      exit;
    }
    wp_safe_redirect(admin_url('edit-tags.php?taxonomy=' . rawurlencode($taxonomy) . '&post_type=product'));
    exit;
  }

  public static function create_translation_url(int $term_id, string $taxonomy, string $language): string {
    $url = add_query_arg([
      'page' => 'ypm-term-translation',
      'source' => $term_id,
      'taxonomy' => $taxonomy,
      'lang' => $language,
    ], admin_url('admin.php'));
    return wp_nonce_url($url, 'ypm_prepare_term_translation_' . $term_id . '_' . $language, 'ypm_prepare_nonce');
  }

  private static function taxonomy_admin_url(string $taxonomy): string {
    $object = get_taxonomy($taxonomy);
    if (!$object) return '';
    $args = ['taxonomy' => $taxonomy];
    $object_types = isset($object->object_type) && is_array($object->object_type) ? array_values($object->object_type) : [];
    $post_type = '';
    foreach ($object_types as $candidate) {
      if ($candidate === 'product') { $post_type = 'product'; break; }
      if ($candidate === 'download') { $post_type = 'download'; break; }
      if ($post_type === '' && is_string($candidate)) $post_type = $candidate;
    }
    if ($post_type !== '' && $post_type !== 'post') $args['post_type'] = $post_type;
    return add_query_arg($args, admin_url('edit-tags.php'));
  }

  /**
   * @return array{0:\WP_Term,1:string,2:string}|null
   */
  private static function translation_context_from_request(): ?array {
    $source_raw = filter_input(INPUT_GET, 'source', FILTER_VALIDATE_INT);
    $taxonomy_raw = filter_input(INPUT_GET, 'taxonomy', FILTER_UNSAFE_RAW);
    $language_raw = filter_input(INPUT_GET, 'lang', FILTER_UNSAFE_RAW);
    $nonce_raw = filter_input(INPUT_GET, 'ypm_prepare_nonce', FILTER_UNSAFE_RAW);
    $source_id = $source_raw ? (int) $source_raw : 0;
    $taxonomy = is_string($taxonomy_raw) ? sanitize_key($taxonomy_raw) : '';
    $language = is_string($language_raw) ? sanitize_text_field($language_raw) : '';
    $nonce = is_string($nonce_raw) ? sanitize_text_field($nonce_raw) : '';

    if ($source_id <= 0 || !TermTranslations::is_supported_taxonomy($taxonomy) || !Languages::is_active($language)) {
      wp_die(esc_html__('Invalid translation request.', 'yablonsky-multilingual'));
    }
    if ($nonce === '' || !wp_verify_nonce($nonce, 'ypm_prepare_term_translation_' . $source_id . '_' . $language)) {
      wp_die(esc_html__('The translation link has expired. Return to the taxonomy screen and try again.', 'yablonsky-multilingual'));
    }

    $term = get_term($source_id, $taxonomy);
    if (!($term instanceof \WP_Term)) wp_die(esc_html__('Source term is unavailable. Return to Taxonomies and choose an existing source term.', 'yablonsky-multilingual'));
    return [$term, $taxonomy, $language];
  }

  private static function posted_term_nonce_is_valid(): bool {
    $nonce_raw = filter_input(INPUT_POST, 'ypm_term_nonce', FILTER_UNSAFE_RAW);
    $nonce = is_string($nonce_raw) ? sanitize_text_field($nonce_raw) : '';
    return $nonce !== '' && wp_verify_nonce($nonce, 'ypm_save_term_language');
  }

  private static function can_manage_taxonomy(string $taxonomy): bool {
    $object = get_taxonomy($taxonomy);
    if (!$object || empty($object->cap->manage_terms)) return false;
    return current_user_can((string) $object->cap->manage_terms);
  }

  private static function prime_current_list_table(string $taxonomy): void {
    if (self::$list_primed) return;
    self::$list_primed = true;

    $ids = [];
    $list_table = $GLOBALS['wp_list_table'] ?? null;
    if (is_object($list_table) && isset($list_table->items) && is_array($list_table->items)) {
      foreach ($list_table->items as $item) {
        if ($item instanceof \WP_Term && (string) $item->taxonomy === $taxonomy) $ids[] = (int) $item->term_id;
      }
    }
    if ($ids) TermTranslations::prime_translation_families($ids, $taxonomy);
  }

  /**
   * @param string[] $active
   * @param array<string,int> $map
   * @return string[]
   */
  private static function visible_locales(array $active, string $current, array $map): array {
    if (count($active) <= 5) return $active;

    $visible = [];
    $append = static function(string $code) use (&$visible): void {
      if ($code !== '' && !in_array($code, $visible, true)) $visible[] = $code;
    };
    $append($current);
    foreach ($active as $code) {
      if (isset($map[$code])) $append($code);
      if (count($visible) >= self::VISIBLE_LOCALE_LIMIT) break;
    }
    foreach ($active as $code) {
      $append($code);
      if (count($visible) >= self::VISIBLE_LOCALE_LIMIT) break;
    }
    return array_slice($visible, 0, self::VISIBLE_LOCALE_LIMIT);
  }
}
