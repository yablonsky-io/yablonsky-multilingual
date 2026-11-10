<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Native WordPress content-list UX for multilingual content.
 *
 * Initial list-table hydration is bounded. Full locale-family state is loaded
 * only when the user opens the family drawer for one resource.
 */
final class AdminContent {
  private const VISIBLE_LOCALE_LIMIT = 4;
  private static bool $effective_query_filter_registered = false;

  public static function init(): void {
    add_action('current_screen', [__CLASS__, 'register_screen']);
    add_action('restrict_manage_posts', [__CLASS__, 'render_language_filter'], 10, 2);
    add_action('pre_get_posts', [__CLASS__, 'apply_language_filter'], 20);
    self::ensure_effective_query_filter();
    add_filter('the_posts', [__CLASS__, 'prime_list_rows'], 20, 2);
    add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    add_action('wp_ajax_ypm_translation_family', [__CLASS__, 'ajax_translation_family']);
    add_action('admin_menu', [__CLASS__, 'register_family_fallback_page']);
  }

  public static function register_screen($screen): void {
    if (!is_object($screen)) return;

    // Media Library list view uses a different WordPress list-table hook set
    // from Posts / Pages / Products, but the multilingual family interaction
    // contract is intentionally the same.
    if (($screen->base ?? '') === 'upload') {
      add_filter('manage_media_columns', [__CLASS__, 'columns']);
      add_action('manage_media_custom_column', [__CLASS__, 'render_column'], 10, 2);
      return;
    }

    if (($screen->base ?? '') !== 'edit') return;

    $post_type = isset($screen->post_type) ? (string) $screen->post_type : '';
    if ($post_type === '' || !in_array($post_type, Languages::supported_post_types(), true)) return;

    add_filter("manage_{$post_type}_posts_columns", [__CLASS__, 'columns']);
    add_action("manage_{$post_type}_posts_custom_column", [__CLASS__, 'render_column'], 10, 2);
  }

  public static function columns(array $columns): array {
    $out = [];
    $inserted = false;
    foreach ($columns as $key => $label) {
      $out[$key] = $label;
      if ($key === 'title') {
        $out['ypm_language'] = __('Source', 'yablonsky-multilingual');
        $out['ypm_translations'] = __('Translations', 'yablonsky-multilingual');
        $inserted = true;
      }
    }
    // Media Library and third-party list tables may alter/remove the canonical
    // title column. Family controls must still be present rather than silently
    // disappearing because another plugin changed the column map.
    if (!$inserted) {
      $out['ypm_language'] = __('Source', 'yablonsky-multilingual');
      $out['ypm_translations'] = __('Translations', 'yablonsky-multilingual');
    }
    return $out;
  }

  public static function render_column(string $column, int $post_id): void {
    if ($column === 'ypm_language') {
      self::render_language_cell($post_id);
      return;
    }

    if ($column === 'ypm_translations') self::render_translation_cell($post_id);
  }

  private static function render_language_cell(int $post_id): void {
    $assigned = Translations::assigned_post_language($post_id);
    $code = RuntimeAuthority::effective_post_language($post_id);
    if ($code === '') $code = $assigned ?: Languages::default_code();
    $name = Languages::display_name($code);

    echo wp_kses_post(Admin::language_badge($code));
    if ($assigned === null && RuntimeAuthority::public_owner() === 'ymulti') {
      echo '<span class="ypm-lang-state" title="' . esc_attr__('Language identity is ready to assign; YMulti currently uses the default locale as the working fallback.', 'yablonsky-multilingual') . '">' . esc_html__('assumed', 'yablonsky-multilingual') . '</span>';
    }
    if (Languages::is_legacy_code($code)) {
      echo '<span class="ypm-lang-state is-legacy" title="' . esc_attr__('Legacy generic language identity. Review locale normalization before changing URLs or hreflang.', 'yablonsky-multilingual') . '">' . esc_html__('legacy', 'yablonsky-multilingual') . '</span>';
    }
  }

  private static function render_translation_cell(int $post_id): void {
    $post = get_post($post_id);
    if (!$post) return;

    if ($post->post_type === 'attachment' && class_exists('Yablonsky\\Multilingual\\MediaWorkspace', false)) {
      MediaWorkspace::render_native_family_cell($post_id);
      return;
    }

    $active = Languages::active_codes();
    $effective = EffectiveRelations::post_family($post_id, null, $active);
    $current = (string) ($effective['source'] ?? Translations::get_post_language($post_id));
    $by_language = is_array($effective['translations'] ?? null) ? $effective['translations'] : [];

    $complete = 0;
    foreach ($active as $code) {
      if (isset($by_language[$code])) $complete++;
    }

    $visible = self::visible_locales($active, $current, $by_language);
    $hidden_count = max(0, count($active) - count(array_intersect($visible, $active)));

    echo '<div class="ypm-family" aria-label="' . esc_attr__('Translation family', 'yablonsky-multilingual') . '">';
    echo '<a class="ypm-family-progress ypm-family-more" data-post-id="' . esc_attr((string) $post_id) . '" href="' . esc_url(self::family_detail_url($post_id)) . '" title="' . esc_attr__('Open translation family', 'yablonsky-multilingual') . '">' . esc_html((string) $complete) . '/' . esc_html((string) count($active)) . '</a>';

    foreach ($visible as $code) {
      self::render_locale_chip($post, $post_id, $current, $code, $by_language);
    }

    if ($hidden_count > 0) {
      /* translators: %d: number of additional locales hidden from the compact row. */
      $more_title = sprintf(__('Show %d more locales', 'yablonsky-multilingual'), $hidden_count);
      echo '<a class="ypm-family-chip ypm-family-more is-overflow" data-post-id="' . esc_attr((string) $post_id) . '" href="' . esc_url(self::family_detail_url($post_id)) . '" title="' . esc_attr($more_title) . '">+' . esc_html((string) $hidden_count) . '</a>';
    }

    echo '</div>';
  }

  /**
   * Keep row width bounded when many locales are enabled.
   *
   * @param string[] $active
   * @param array<string,int> $by_language
   * @return string[]
   */
  private static function visible_locales(array $active, string $current, array $by_language): array {
    if (count($active) <= 5) return $active;

    $visible = [];
    $append = static function(string $code) use (&$visible): void {
      if ($code !== '' && !in_array($code, $visible, true)) $visible[] = $code;
    };

    $append($current);

    foreach ($active as $code) {
      if (isset($by_language[$code])) $append($code);
      if (count($visible) >= self::VISIBLE_LOCALE_LIMIT) break;
    }

    foreach ($active as $code) {
      $append($code);
      if (count($visible) >= self::VISIBLE_LOCALE_LIMIT) break;
    }

    return array_slice($visible, 0, self::VISIBLE_LOCALE_LIMIT);
  }

  /**
   * @param array<string,int> $by_language
   */
  private static function render_locale_chip(\WP_Post $post, int $post_id, string $current, string $code, array $by_language): void {
    $name = Languages::display_name($code);
    $label = Languages::display_code($code);

    if (isset($by_language[$code])) {
      $target_id = (int) $by_language[$code];
      $edit_url = get_edit_post_link($target_id, '');
      $is_current = $target_id === $post_id;
      $class = $is_current ? 'ypm-family-chip is-current' : 'ypm-family-chip has-translation';
      if ($is_current) {
        /* translators: %s: locale or language display name. */
        $title = sprintf(__('%s — current item', 'yablonsky-multilingual'), $name);
      } else {
        /* translators: %s: locale or language display name. */
        $title = sprintf(__('%s — edit translation', 'yablonsky-multilingual'), $name);
      }

      if ($edit_url) {
        echo '<a class="' . esc_attr($class) . '" href="' . esc_url($edit_url) . '" title="' . esc_attr($title) . '">' . esc_html($label) . '</a>';
      } else {
        echo '<span class="' . esc_attr($class) . '" title="' . esc_attr($title) . '">' . esc_html($label) . '</span>';
      }
      return;
    }

    if (!Languages::is_active($code)) return;
    $create_url = self::create_translation_url($post, $code);
    /* translators: %s: locale or language display name. */
    $create_title = sprintf(__('Create %s translation', 'yablonsky-multilingual'), $name);
    echo '<a class="ypm-family-chip is-missing" href="' . esc_url($create_url) . '" title="' . esc_attr($create_title) . '">+ ' . esc_html($label) . '</a>';
  }

  public static function create_translation_url(\WP_Post $post, string $code): string {
    $url = add_query_arg([
      'post_type' => $post->post_type,
      'ypm_action' => 'create_translation',
      'source' => $post->ID,
      'lang' => $code,
    ], admin_url('post-new.php'));

    return wp_nonce_url($url, 'ypm_create_translation');
  }

  public static function family_detail_url(int $post_id): string {
    return add_query_arg([
      'page' => 'ypm-translation-family',
      'post' => $post_id,
    ], admin_url('admin.php'));
  }

  public static function register_family_fallback_page(): void {
    add_submenu_page(
      null,
      __('Translation Family', 'yablonsky-multilingual'),
      __('Translation Family', 'yablonsky-multilingual'),
      'read',
      'ypm-translation-family',
      [__CLASS__, 'render_family_fallback_page']
    );
  }

  public static function render_family_fallback_page(): void {
    $post_raw = filter_input(INPUT_GET, 'post', FILTER_VALIDATE_INT);
    $post_id = is_int($post_raw) ? $post_raw : 0;
    if (!$post_id || !current_user_can('edit_post', $post_id)) {
      wp_die(esc_html__('Translation-family view permission is required for this action.', 'yablonsky-multilingual'));
    }

    $post = get_post($post_id);
    if (!$post) wp_die(esc_html__('Content resource is unavailable. Return to the content list and choose an existing item.', 'yablonsky-multilingual'));

    $active = Languages::active_codes();
    $effective = EffectiveRelations::post_family($post_id, null, $active);
    $by_language = is_array($effective['translations'] ?? null) ? $effective['translations'] : [];
    $complete = count(array_intersect(array_keys($by_language), $active));
    $missing = max(0, count($active) - $complete);

    echo '<div class="wrap ypm-admin ypm-admin--family">';
    Admin::render_shell_header(
      __('Translation Family', 'yablonsky-multilingual'),
      __('Work across every enabled locale for one content family while WordPress remains the native editing model.', 'yablonsky-multilingual'),
      $missing > 0 ? 'attention' : 'pass',
      $missing > 0 ? __('Needs translation', 'yablonsky-multilingual') : __('Complete', 'yablonsky-multilingual')
    );
    echo '<div class="ypm-family-page-bar"><a class="button" href="' . esc_url(admin_url('admin.php?page=ypm-translations')) . '">&larr; ' . esc_html__('Back to Translations', 'yablonsky-multilingual') . '</a><a class="button" href="' . esc_url(get_edit_post_link($post_id, '')) . '">' . esc_html__('Edit current content', 'yablonsky-multilingual') . '</a></div>';
    echo '<div class="ypm-metrics ypm-metrics--4">';
    Admin::render_metric(__('Content', 'yablonsky-multilingual'), '#' . (string) $post_id, get_the_title($post_id) ?: (string) $post->post_type, 'neutral');
    Admin::render_metric(__('Coverage', 'yablonsky-multilingual'), $complete . '/' . count($active), __('Enabled locales', 'yablonsky-multilingual'), $missing === 0 ? 'pass' : 'attention');
    Admin::render_metric(__('Missing', 'yablonsky-multilingual'), (string) $missing, __('Translations to create', 'yablonsky-multilingual'), $missing > 0 ? 'attention' : 'pass');
    Admin::render_metric(__('Post type', 'yablonsky-multilingual'), (string) $post->post_type, __('Native WordPress objects', 'yablonsky-multilingual'), 'neutral');
    echo '</div>';
    echo '<section class="ypm-card ypm-card--standalone ypm-family-page-card">';
    echo self::family_panel_html($post_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside renderer.
    echo '</section></div>';
  }

  public static function ajax_translation_family(): void {
    check_ajax_referer('ypm_translation_family');
    $post_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;
    if (!$post_id || !current_user_can('edit_post', $post_id)) {
      wp_send_json_error(['message' => __('Forbidden', 'yablonsky-multilingual')], 403);
    }

    wp_send_json_success(['html' => self::family_panel_html($post_id)]);
  }

  private static function family_panel_html(int $post_id): string {
    $post = get_post($post_id);
    if (!$post) return '<p>' . esc_html__('Content resource is unavailable. Return to the content list and choose an existing item.', 'yablonsky-multilingual') . '</p>';

    $active = Languages::active_codes();
    $effective = EffectiveRelations::post_family($post_id, null, $active);
    $current = (string) ($effective['source'] ?? Translations::get_post_language($post_id));
    $by_language = is_array($effective['translations'] ?? null) ? $effective['translations'] : [];

    $complete = 0;
    foreach ($active as $code) if (isset($by_language[$code])) $complete++;

    ob_start();
    echo '<div class="ypm-family-panel" data-family-post="' . esc_attr((string) $post_id) . '">';
    echo '<div class="ypm-family-panel__header">';
    echo '<div><strong class="ypm-family-panel__title">' . esc_html(get_the_title($post_id)) . '</strong>';
    /* translators: 1: number of existing locale versions, 2: number of enabled locales. */
    $coverage = sprintf(__('%1$d of %2$d locales', 'yablonsky-multilingual'), $complete, count($active));
    echo '<div class="ypm-family-panel__coverage">' . esc_html($coverage) . '</div></div>';
    echo '</div>';
    echo '<label class="screen-reader-text" for="ypm-family-search-' . esc_attr((string) $post_id) . '">' . esc_html__('Search locales', 'yablonsky-multilingual') . '</label>';
    echo '<input id="ypm-family-search-' . esc_attr((string) $post_id) . '" class="ypm-family-search" type="search" placeholder="' . esc_attr__('Search by language, native name, or code…', 'yablonsky-multilingual') . '" />';
    echo '<div class="ypm-family-list">';

    foreach ($active as $code) {
      $meta = Languages::locale_metadata($code);
      $english = (string) ($meta['english_name'] ?? $meta['name'] ?? $code);
      $native = (string) ($meta['native_name'] ?? '');
      $display = Languages::display_code($code);
      $search = strtolower($english . ' ' . $native . ' ' . $display . ' ' . $code);
      $exists = isset($by_language[$code]);
      $is_current = $exists && (int) $by_language[$code] === $post_id;

      echo '<div class="ypm-family-row" data-search="' . esc_attr($search) . '">';
      echo '<div class="ypm-family-row__name"><strong>' . esc_html($english) . '</strong>';
      if ($native !== '' && $native !== $english) echo '<span>' . esc_html($native) . '</span>';
      echo '</div>';
      echo '<code class="ypm-family-row__code">' . esc_html($display) . '</code>';
      echo '<div class="ypm-family-row__action">';
      if ($is_current) {
        echo '<span class="ypm-family-status is-current">' . esc_html__('Current', 'yablonsky-multilingual') . '</span>';
      } elseif ($exists) {
        $edit_url = get_edit_post_link((int) $by_language[$code], '');
        if ($edit_url) echo '<a class="button button-small" href="' . esc_url($edit_url) . '">' . esc_html__('Edit', 'yablonsky-multilingual') . '</a>';
      } else {
        echo '<a class="button button-small" href="' . esc_url(self::create_translation_url($post, $code)) . '">' . esc_html__('Create', 'yablonsky-multilingual') . '</a>';
        if (class_exists('Yablonsky\Multilingual\AITranslation') && AITranslation::ready() && AITranslation::current_user_can_compute()) {
          AIAdmin::render_content_draft_button($post_id, $code, __('AI draft', 'yablonsky-multilingual'), 'button button-small button-primary ymulti-ai-cta');
        }
      }
      echo '</div></div>';
    }

    echo '</div></div>';
    return (string) ob_get_clean();
  }

  public static function render_language_filter(string $post_type, string $which): void {
    if ($which !== 'top') return;
    if ($post_type !== 'attachment' && !in_array($post_type, Languages::supported_post_types(), true)) return;

    $selected_raw = filter_input(INPUT_GET, 'ypm_lang', FILTER_UNSAFE_RAW);
    $selected = is_string($selected_raw) ? sanitize_text_field($selected_raw) : '';
    if ($selected === '' && class_exists('Yablonsky\Multilingual\AdminLanguageContext')) $selected = AdminLanguageContext::current();
    $active = EntityContract::active_locales();

    echo '<label class="screen-reader-text" for="ypm-lang-filter">' . esc_html__('Source language', 'yablonsky-multilingual') . '</label>';
    echo '<select name="ypm_lang" id="ypm-lang-filter" class="ypm-native-language-filter" data-ymulti-native-select="1">';
    echo '<option value="">' . esc_html__('All languages', 'yablonsky-multilingual') . '</option>';
    foreach ($active as $code) {
      $name = Languages::display_name($code);
      echo '<option value="' . esc_attr($code) . '"' . selected($selected, $code, false) . '>' . esc_html($name . ' (' . Languages::display_code($code) . ')') . '</option>';
    }
    echo '<option value="__unassigned"' . selected($selected, '__unassigned', false) . '>' . esc_html__('Unassigned language', 'yablonsky-multilingual') . '</option>';
    echo '</select>';
  }

  public static function apply_language_filter(\WP_Query $query): void {
    if (!is_admin() || !$query->is_main_query()) return;

    $post_type = $query->get('post_type');
    if (is_array($post_type)) return;
    $post_type = $post_type ? (string) $post_type : 'post';
    if ($post_type !== 'attachment' && !in_array($post_type, Languages::supported_post_types(), true)) return;

    $selected_raw = filter_input(INPUT_GET, 'ypm_lang', FILTER_UNSAFE_RAW);
    $selected = is_string($selected_raw) ? sanitize_text_field($selected_raw) : '';
    if ($selected === '' && class_exists('Yablonsky\Multilingual\AdminLanguageContext')) $selected = AdminLanguageContext::current();
    if ($selected === '' || ($selected !== '__unassigned' && !in_array($selected, EntityContract::active_locales(), true))) return;

    // One effective-language query contract is shared by the native WordPress
    // list table and YMulti workspaces. It preserves the native default-language
    // fallback for unassigned objects and follows the incumbent graph in bridge mode.
    $query->set('ymulti_effective_lang', $selected);
  }

  /**
   * Apply the effective incumbent language to the native WordPress post list
   * without materializing all matching IDs in PHP. This is deliberately
   * read-only and only activates when pre_get_posts set the private query var.
   *
   * @param array<string,string> $clauses
   * @return array<string,string>
   */
  /**
   * Add the canonical effective source-language constraint to any WP_Query args.
   * The posts_clauses filter below resolves both native YMulti identity and the
   * read-only incumbent bridge without materializing a potentially huge ID list.
   *
   * @param array<string,mixed> $args
   * @return array<string,mixed>
   */
  public static function with_source_language(array $args, string $language): array {
    self::ensure_effective_query_filter();
    $language = sanitize_text_field($language);
    if ($language !== '' && ($language === '__unassigned' || in_array($language, EntityContract::active_locales(), true))) {
      $args['ymulti_effective_lang'] = $language;
    }
    return $args;
  }


  /**
   * Collapse physical translated post objects to one logical family row when a
   * catalog is not constrained to a concrete Source locale. This private query
   * flag is consumed by apply_provider_language_clauses() together with the
   * source filter so rows, totals and pagination use the same cardinality.
   *
   * @param array<string,mixed> $args
   * @return array<string,mixed>
   */
  public static function with_family_representative(array $args): array {
    self::ensure_effective_query_filter();
    $args['ymulti_family_representative'] = 1;
    return $args;
  }


  /**
   * Ensure effective source-language SQL resolution is active in this request.
   * Background workers call with_source_language() outside wp-admin, so the
   * query contract cannot depend on the admin-only bootstrap path.
   */
  private static function ensure_effective_query_filter(): void {
    if (self::$effective_query_filter_registered) return;
    add_filter('posts_clauses', [__CLASS__, 'apply_provider_language_clauses'], 20, 2);
    self::$effective_query_filter_registered = true;
  }

  /**
   * Resolve the private `ymulti_effective_lang` query var at SQL level.
   *
   * Provider bridge: use WPML's read-only relation table.
   * Native YMulti: explicit language identity wins; an unassigned object is
   * effectively in the default language, matching RuntimeAuthority fallback.
   *
   * @param array<string,string> $clauses
   * @return array<string,string>
   */
  public static function apply_provider_language_clauses(array $clauses, \WP_Query $query): array {
    $selected = sanitize_text_field((string) $query->get('ymulti_effective_lang'));
    $family_representative = (int) $query->get('ymulti_family_representative') === 1;
    if ($selected === '' && !$family_representative) return $clauses;
    if ($selected !== '' && $selected !== '__unassigned' && !in_array($selected, EntityContract::active_locales(), true)) return $clauses;

    global $wpdb;
    $posts_table = esc_sql($wpdb->posts);

    if (RuntimeAuthority::wpml_translation_table_available()) {
      $table = esc_sql(RuntimeAuthority::wpml_translation_table());
      $alias = 'ymulti_effective_lang';
      $base_join = ' ON ' . $alias . '.element_id = ' . $posts_table . '.ID AND ' . $alias . ".element_type = CONCAT('post_', " . $posts_table . '.post_type)';

      if ($selected === '__unassigned') {
        $clauses['join'] .= ' LEFT JOIN ' . $table . ' ' . $alias . $base_join;
        $clauses['where'] .= ' AND ' . $alias . '.translation_id IS NULL';
        return $clauses;
      }

      if ($selected !== '') {
        $provider_code = RuntimeAuthority::provider_code_for_language($selected);
        if ($provider_code === '') {
          $clauses['where'] .= ' AND 1 = 0';
          return $clauses;
        }
        $clauses['join'] .= ' INNER JOIN ' . $table . ' ' . $alias . $base_join
          . $wpdb->prepare(' AND ymulti_effective_lang.language_code = %s', $provider_code);
        return $clauses; // one member per family for one concrete locale.
      }

      if ($family_representative) {
        // WPML stores exactly one original/source member per trid. Selecting that
        // member gives one stable catalog row per family without GROUP BY hacks,
        // so found_posts and pagination stay truthful on very large libraries.
        $clauses['join'] .= ' INNER JOIN ' . $table . ' ' . $alias . $base_join;
        $clauses['where'] .= ' AND ' . $alias . '.trid > 0 AND ' . $alias . '.source_language_code IS NULL';
      }
      return $clauses;
    }

    // The public incumbent owns the source-language assignments during
    // coexistence. Unsynchronized Polylang posts must be filtered by the
    // Polylang language taxonomy, not YMulti's staging taxonomy.
    $polylang_owner = RuntimeAuthority::public_owner() === 'polylang';
    $taxonomy = $polylang_owner ? 'language' : Languages::TAX;
    $term_relationships = esc_sql($wpdb->term_relationships);
    $term_taxonomy = esc_sql($wpdb->term_taxonomy);
    $terms = esc_sql($wpdb->terms);
    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table identifiers are trusted wpdb properties; taxonomy/slug values remain prepared.
    $any_language = $wpdb->prepare(
      'EXISTS (SELECT 1 FROM ' . $term_relationships . ' yml_tr INNER JOIN ' . $term_taxonomy . ' yml_tt ON yml_tt.term_taxonomy_id = yml_tr.term_taxonomy_id WHERE yml_tr.object_id = ' . $posts_table . '.ID AND yml_tt.taxonomy = %s)',
      $taxonomy
    );

    if ($selected === '__unassigned') {
      $clauses['where'] .= ' AND NOT ' . $any_language;
      return $clauses;
    }

    if ($selected !== '') {
      $slug = $polylang_owner
        ? RuntimeAuthority::provider_code_for_language($selected)
        : Languages::code_to_slug($selected);
      if ($slug === '') {
        $clauses['where'] .= ' AND 1 = 0';
        return $clauses;
      }
      $selected_language = $wpdb->prepare(
        'EXISTS (SELECT 1 FROM ' . $term_relationships . ' yml_sr INNER JOIN ' . $term_taxonomy . ' yml_st ON yml_st.term_taxonomy_id = yml_sr.term_taxonomy_id INNER JOIN ' . $terms . ' yml_s ON yml_s.term_id = yml_st.term_id WHERE yml_sr.object_id = ' . $posts_table . '.ID AND yml_st.taxonomy = %s AND yml_s.slug = %s)',
        $taxonomy,
        $slug
      );
      if (!$polylang_owner && $selected === Languages::default_code()) {
        $clauses['where'] .= ' AND (' . $selected_language . ' OR NOT ' . $any_language . ')';
      } else {
        $clauses['where'] .= ' AND ' . $selected_language;
      }
      return $clauses; // language identity is unique inside a healthy family.
    }

    if ($family_representative) {
      // Polylang stores related post members in its own post_translations
      // taxonomy; YMulti's group terms may not exist before admin sync.
      $group_tax = $polylang_owner ? 'post_translations' : Translations::GROUP_TAX;
      // Choose the lowest WordPress object ID inside a native YMulti family and
      // keep ungrouped objects as singleton families. This is deterministic and
      // makes SQL count/pagination cardinality match the logical family model.
      $same_group_lower = $wpdb->prepare(
        'EXISTS (SELECT 1 FROM ' . $term_relationships . ' ymg_self INNER JOIN ' . $term_taxonomy . ' ymg_tt ON ymg_tt.term_taxonomy_id = ymg_self.term_taxonomy_id INNER JOIN ' . $term_relationships . ' ymg_other ON ymg_other.term_taxonomy_id = ymg_self.term_taxonomy_id WHERE ymg_self.object_id = ' . $posts_table . '.ID AND ymg_tt.taxonomy = %s AND ymg_other.object_id < ' . $posts_table . '.ID)',
        $group_tax
      );
      // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
      $clauses['where'] .= ' AND NOT ' . $same_group_lower;
    }
    return $clauses;
  }

  public static function prime_list_rows(array $posts, \WP_Query $query): array {
    if (!is_admin() || !$query->is_main_query() || !$posts) return $posts;

    $post_type = $query->get('post_type');
    if (is_array($post_type)) return $posts;
    $post_type = $post_type ? (string) $post_type : 'post';
    if ($post_type !== 'attachment' && !in_array($post_type, Languages::supported_post_types(), true)) return $posts;

    $ids = [];
    foreach ($posts as $post) {
      if ($post instanceof \WP_Post) $ids[] = (int) $post->ID;
    }
    if ($ids) Translations::prime_posts($ids);

    return $posts;
  }

  public static function enqueue_assets(string $hook): void {
    $screen = get_current_screen();
    if (!$screen) return;

    $base = (string) ($screen->base ?? '');
    $is_content_list = $base === 'edit'
      && !empty($screen->post_type)
      && in_array((string) $screen->post_type, Languages::supported_post_types(), true);
    $is_media_list = $base === 'upload';
    $is_content_editor = $base === 'post'
      && !empty($screen->post_type)
      && in_array((string) $screen->post_type, Languages::supported_post_types(), true);
    $page_raw = filter_input(INPUT_GET, 'page', FILTER_UNSAFE_RAW);
    $page = is_string($page_raw) ? sanitize_key($page_raw) : '';
    $is_family_fallback = $page === 'ypm-translation-family';
    $is_translation_workspace = $page === 'ypm-translations';
    $is_family_workspace = in_array($page, ['ypm-taxonomies', 'ypm-menu-translation', 'ypm-media'], true);
    if (!$is_content_list && !$is_media_list && !$is_content_editor && !$is_family_fallback && !$is_translation_workspace && !$is_family_workspace) return;

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

    wp_localize_script('ypm-admin-content', 'YPMAdminContent', self::drawer_config());
  }

  /** Shared Family Drawer configuration for every language-owned surface. */
  public static function drawer_config(): array {
    return [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('ypm_translation_family'),
      'postNonce' => wp_create_nonce('ypm_translation_family'),
      'termNonce' => wp_create_nonce('ypm_term_translation_family'),
      'menuNonce' => wp_create_nonce('ymulti_menu_translation_family'),
      'mediaNonce' => wp_create_nonce('ymulti_media_translation_family'),
      'loading' => __('Loading translations…', 'yablonsky-multilingual'),
      'loadFailed' => __('Needs review', 'yablonsky-multilingual'),
      'searchPlaceholder' => __('Search by language, native name, or code…', 'yablonsky-multilingual'),
      'current' => __('Current', 'yablonsky-multilingual'),
      'close' => __('Close', 'yablonsky-multilingual'),
    ];
  }
}
