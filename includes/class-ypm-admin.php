<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

final class Admin {
  public static function init(): void {
    add_action('admin_menu', [__CLASS__, 'menu'], 5);
    add_action('admin_menu', [__CLASS__, 'normalize_submenu'], 999);
    add_action('admin_post_ypm_save_languages', [__CLASS__, 'handle_save_languages']);
    add_action('admin_post_ypm_update_active_locales', [__CLASS__, 'handle_update_active_locales']);
    add_action('admin_post_ymulti_update_public_locales', [__CLASS__, 'handle_update_public_locales']);
    add_action('admin_post_ypm_quick_action', [__CLASS__, 'handle_quick_action']);
    add_action('admin_post_ypm_add_custom_language', [__CLASS__, 'handle_add_custom_language']);
    add_action('admin_post_ypm_toggle_safe_mode', [__CLASS__, 'handle_toggle_safe_mode']);
    add_action('admin_post_ypm_rest_selftest', [__CLASS__, 'handle_rest_selftest']);
    add_action('wp_ajax_ypm_rest_selftest', [__CLASS__, 'ajax_rest_selftest']);
    add_action('admin_init', [__CLASS__, 'maybe_create_translation']);
    add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

  }


  /** Return a bounded list page size from the current request. */
  public static function requested_page_size(int $default = 40, array $allowed = [20, 40, 80]): int {
    $raw = filter_input(INPUT_GET, 'per_page', FILTER_VALIDATE_INT);
    $value = $raw ? (int) $raw : $default;
    return in_array($value, $allowed, true) ? $value : $default;
  }

  /** Render a server-side Rows per page control inside the pagination bar. */
  public static function render_server_page_size(array $query, int $current, array $sizes): void {
    $sizes = array_values(array_unique(array_filter(array_map('intval', $sizes), static function (int $size): bool { return $size > 0 && $size <= 250; })));
    if (!$sizes) return;
    if (!in_array($current, $sizes, true)) $current = (int) $sizes[0];
    echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="ypm-pagination__size-form">';
    foreach ($query as $key => $value) {
      if ($key === 'per_page' || $key === 'paged' || $value === '' || $value === null) continue;
      echo '<input type="hidden" name="' . esc_attr((string) $key) . '" value="' . esc_attr((string) $value) . '">';
    }
    echo '<label><span>' . esc_html__('Rows per page', 'yablonsky-multilingual') . '</span><select name="per_page" onchange="this.form.submit()">';
    foreach ($sizes as $size) echo '<option value="' . esc_attr((string) $size) . '"' . selected($current, $size, false) . '>' . esc_html((string) $size) . '</option>';
    echo '</select></label></form>';
  }

  /** Render the shared client-side pager used by finite admin catalogs/forms. */
  public static function render_client_pager(string $id, int $default = 25, array $sizes = [25, 50, 100]): void {
    $id = sanitize_html_class($id);
    $sizes = array_values(array_unique(array_filter(array_map('intval', $sizes), static function (int $size): bool { return $size > 0 && $size <= 250; })));
    if (!$sizes) $sizes = [$default];
    if (!in_array($default, $sizes, true)) $default = (int) $sizes[0];
    echo '<div class="ypm-client-pager" id="' . esc_attr($id) . '" data-ypm-client-pager data-page-size="' . esc_attr((string) $default) . '">';
    echo '<span class="ypm-client-pager__summary" data-ypm-pager-summary aria-live="polite"></span>';
    echo '<div class="ypm-client-pager__nav"><button type="button" class="button" data-ypm-pager-first aria-label="' . esc_attr__('First page', 'yablonsky-multilingual') . '">«</button><button type="button" class="button" data-ypm-pager-prev aria-label="' . esc_attr__('Previous page', 'yablonsky-multilingual') . '">‹</button><span class="ypm-client-pager__pages" data-ypm-pager-pages></span><button type="button" class="button" data-ypm-pager-next aria-label="' . esc_attr__('Next page', 'yablonsky-multilingual') . '">›</button><button type="button" class="button" data-ypm-pager-last aria-label="' . esc_attr__('Last page', 'yablonsky-multilingual') . '">»</button></div>';
    echo '<label class="ypm-client-pager__size"><span>' . esc_html__('Rows per page', 'yablonsky-multilingual') . '</span><select data-ypm-pager-size>';
    foreach ($sizes as $size) echo '<option value="' . esc_attr((string) $size) . '"' . selected($default, $size, false) . '>' . esc_html((string) $size) . '</option>';
    echo '</select></label></div>';
  }

  public static function menu(): void {
    add_menu_page(
      __('Yablonsky Multilingual', 'yablonsky-multilingual'),
      __('Yablonsky Multilingual', 'yablonsky-multilingual'),
      'manage_options',
      'ypm-multilingual',
      [__CLASS__, 'page_dashboard'],
      'dashicons-translation',
      58
    );

    add_submenu_page(
      'ypm-multilingual',
      __('Dashboard', 'yablonsky-multilingual'),
      __('Dashboard', 'yablonsky-multilingual'),
      'manage_options',
      'ypm-multilingual',
      [__CLASS__, 'page_dashboard']
    );

    add_submenu_page(
      'ypm-multilingual',
      __('Languages', 'yablonsky-multilingual'),
      __('Languages', 'yablonsky-multilingual'),
      'manage_options',
      'ypm-languages',
      [__CLASS__, 'page_languages']
    );

    add_submenu_page(
      'ypm-multilingual',
      __('System', 'yablonsky-multilingual'),
      __('System', 'yablonsky-multilingual'),
      'manage_options',
      'ypm-system-status',
      [__CLASS__, 'page_system_status']
    );
  }

  /**
   * Canonical product information architecture used by both YMulti navigations.
   * Feature classes still own their callbacks; this registry owns order, labels,
   * capabilities and the Drafts attention badge so the two menus cannot drift.
   *
   * @return array<int,array{slug:string,label:string,cap:string,badge?:int}>
   */
  public static function navigation_items(): array {
    $items = [
      ['slug' => 'ypm-multilingual', 'label' => __('Dashboard', 'yablonsky-multilingual'), 'cap' => 'manage_options'],
      ['slug' => 'ypm-languages', 'label' => __('Languages', 'yablonsky-multilingual'), 'cap' => 'manage_options'],
      ['slug' => 'ypm-plan', 'label' => __('Plan', 'yablonsky-multilingual'), 'cap' => 'manage_options'],
      ['slug' => 'ypm-translations', 'label' => __('Translations', 'yablonsky-multilingual'), 'cap' => 'edit_posts'],
    ];

    if (class_exists('Yablonsky\Multilingual\AdminTerms') && AdminTerms::manageable_taxonomies()) {
      $items[] = ['slug' => 'ypm-taxonomies', 'label' => __('Taxonomies', 'yablonsky-multilingual'), 'cap' => 'read'];
    }

    $items[] = ['slug' => 'ypm-menu-translation', 'label' => __('Menus', 'yablonsky-multilingual'), 'cap' => 'edit_theme_options'];
    $items[] = ['slug' => 'ypm-strings', 'label' => __('Strings', 'yablonsky-multilingual'), 'cap' => 'manage_options'];
    $items[] = ['slug' => 'ypm-string-discovery', 'label' => __('String Discovery', 'yablonsky-multilingual'), 'cap' => 'manage_options'];
    $items[] = ['slug' => 'ypm-media', 'label' => __('Media Localization', 'yablonsky-multilingual'), 'cap' => 'upload_files'];
    $items[] = ['slug' => 'ypm-custom-fields', 'label' => __('Custom Fields', 'yablonsky-multilingual'), 'cap' => 'manage_options'];

    if (current_user_can('manage_woocommerce') && (class_exists('WooCommerce') || function_exists('WC'))) {
      $items[] = ['slug' => 'ypm-woocommerce', 'label' => __('WooCommerce', 'yablonsky-multilingual'), 'cap' => 'manage_woocommerce'];
      $items[] = ['slug' => 'ypm-woo-emails', 'label' => __('Commerce Emails', 'yablonsky-multilingual'), 'cap' => 'manage_woocommerce'];
    }

    $items[] = ['slug' => 'ypm-autopilot', 'label' => __('Autopilot', 'yablonsky-multilingual'), 'cap' => 'manage_options'];
    $items[] = ['slug' => 'ypm-translation-jobs', 'label' => __('Jobs', 'yablonsky-multilingual'), 'cap' => 'ymulti_translate'];
    $items[] = ['slug' => 'ypm-drafts', 'label' => __('Drafts', 'yablonsky-multilingual'), 'cap' => 'edit_posts', 'badge' => class_exists('Yablonsky\Multilingual\DraftsWorkspace') ? DraftsWorkspace::pending_count() : 0];
    $items[] = ['slug' => 'ypm-launch-center', 'label' => __('Launch Center', 'yablonsky-multilingual'), 'cap' => 'manage_options'];
    $items[] = ['slug' => 'ypm-ai', 'label' => __('Yablonsky AI', 'yablonsky-multilingual'), 'cap' => 'manage_options'];
    $items[] = ['slug' => 'ypm-routing', 'label' => __('Routing', 'yablonsky-multilingual'), 'cap' => 'manage_options'];
    $items[] = ['slug' => 'ypm-migration', 'label' => __('Migration', 'yablonsky-multilingual'), 'cap' => 'manage_options'];
    $items[] = ['slug' => 'ypm-system-status', 'label' => __('System', 'yablonsky-multilingual'), 'cap' => 'manage_options'];
    return $items;
  }

  /** Keep the WordPress sidebar in the exact same product order. */
  public static function normalize_submenu(): void {
    global $submenu;
    if (empty($submenu['ypm-multilingual']) || !is_array($submenu['ypm-multilingual'])) return;

    $order = [];
    $labels = [];
    foreach (self::navigation_items() as $rank => $item) {
      $order[(string) $item['slug']] = $rank;
      $labels[(string) $item['slug']] = (string) $item['label'];
    }

    foreach ($submenu['ypm-multilingual'] as &$row) {
      $slug = isset($row[2]) ? (string) $row[2] : '';
      if (isset($labels[$slug])) {
        $label = $labels[$slug];
        if ($slug === 'ypm-drafts' && class_exists('Yablonsky\Multilingual\DraftsWorkspace')) {
          $count = DraftsWorkspace::pending_count();
          if ($count > 0) {
            /* translators: %d: multilingual drafts awaiting checking. */
            $screen = sprintf(_n('%d multilingual draft awaiting checking', '%d multilingual drafts awaiting checking', $count, 'yablonsky-multilingual'), $count);
            $label .= ' <span class="awaiting-mod count-' . (int) $count . '"><span class="pending-count" aria-hidden="true">' . (int) $count . '</span><span class="screen-reader-text">' . esc_html($screen) . '</span></span>';
          }
        }
        $row[0] = $label;
      }
    }
    unset($row);

    usort($submenu['ypm-multilingual'], static function (array $a, array $b) use ($order): int {
      $a_slug = isset($a[2]) ? (string) $a[2] : '';
      $b_slug = isset($b[2]) ? (string) $b[2] : '';
      $a_rank = $order[$a_slug] ?? 999;
      $b_rank = $order[$b_slug] ?? 999;
      if ($a_rank === $b_rank) return strcmp($a_slug, $b_slug);
      return $a_rank <=> $b_rank;
    });
  }

  public static function page_system_status(): void {
    if (!current_user_can('manage_options')) return;
    Languages::ensure_options();

    $report = Status::report();
    $last = self::get_last_rest_selftest();
    $check_total = count($report['checks']);
    $check_pass = 0;
    foreach ($report['checks'] as $check) {
      if (!empty($check['ok'])) $check_pass++;
    }
    $check_warn = max(0, $check_total - $check_pass);
    $overall = $check_warn === 0 ? 'pass' : 'attention';
    $active_count = count(Languages::active_codes());

    echo '<div class="wrap ypm-admin">';
    self::render_shell_header(
      __('System Status', 'yablonsky-multilingual'),
      __('Compatibility, REST health and runtime facts — summarized first, technical detail on demand.', 'yablonsky-multilingual'),
      $overall,
      $overall === 'pass' ? __('Healthy', 'yablonsky-multilingual') : __('Attention', 'yablonsky-multilingual')
    );

    self::render_routing_compatibility_panel();

    echo '<div class="ypm-metrics ypm-metrics--4">';
    self::render_metric(__('Compatibility', 'yablonsky-multilingual'), $check_pass . '/' . $check_total, $check_warn === 0 ? __('All checks pass', 'yablonsky-multilingual') : __('Review warnings', 'yablonsky-multilingual'), $overall);
    self::render_metric(__('Active locales', 'yablonsky-multilingual'), (string) $active_count, __('Routing scope', 'yablonsky-multilingual'), 'neutral');
    self::render_metric(__('WordPress', 'yablonsky-multilingual'), (string) ($report['wordpress'][__('WordPress version', 'yablonsky-multilingual')] ?? '—'), __('Runtime', 'yablonsky-multilingual'), 'neutral');
    self::render_metric(__('PHP', 'yablonsky-multilingual'), (string) ($report['server'][__('PHP version', 'yablonsky-multilingual')] ?? '—'), __('Execution', 'yablonsky-multilingual'), 'neutral');
    echo '</div>';

    if (class_exists('Yablonsky\\Multilingual\\AIAdmin')) AIAdmin::render_canary_panel();

    echo '<div class="ypm-dashboard-grid">';
    echo '<section class="ypm-card ypm-card--primary">';
    echo '<div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Compatibility', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Core readiness', 'yablonsky-multilingual') . '</h2></div>';
    echo wp_kses_post(self::status_badge($overall, $overall === 'pass' ? __('Ready', 'yablonsky-multilingual') : __('Review', 'yablonsky-multilingual')));
    echo '</div>';
    echo '<div class="ypm-check-list">';
    foreach ($report['checks'] as $c) {
      $state = !empty($c['ok']) ? 'pass' : 'attention';
      echo '<div class="ypm-check-row"><span class="ypm-status-dot ypm-status-dot--' . esc_attr($state) . '"></span><div><strong>' . esc_html($c['label']) . '</strong><small>' . esc_html($c['value']) . ' · ' . esc_html($c['expected']) . '</small></div>' . wp_kses_post(self::status_badge($state, !empty($c['ok']) ? __('PASS', 'yablonsky-multilingual') : __('ATTENTION', 'yablonsky-multilingual'))) . '</div>';
    }
    echo '</div></section>';

    echo '<section class="ypm-card ypm-card--test" id="ypm-rest-test-card">';
    self::render_rest_test_card($last);
    echo '</section>';
    echo '</div>';

    if (class_exists('Yablonsky\Multilingual\IntegrityAudit')) IntegrityAudit::render_panel();
    if (class_exists('Yablonsky\Multilingual\EditorCompatibility')) EditorCompatibility::render_status_panel();
    if (class_exists('Yablonsky\Multilingual\RuntimeContract')) RuntimeContract::render_status_panel();

    if (class_exists('Yablonsky\Multilingual\MultilingualSEOProvider')) {
      $seo_provider = MultilingualSEOProvider::instance();
      $seo_caps = $seo_provider->capabilities();
      $sitemap_owner = class_exists('Yablonsky\Multilingual\SEOOwnership') ? SEOOwnership::sitemap_owner() : 'none';
      $seo_connected = class_exists('Yablonsky\Multilingual\SEOOwnership') && $sitemap_owner === SEOOwnership::OWNER_YPS;
      echo '<section class="ypm-card ypm-card--standalone"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Multilingual SEO', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Language graph provider', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('YMulti exposes language, translation-family and localized-URL truth. The SEO layer owns sitemap rendering and consumes YMulti language authority.', 'yablonsky-multilingual') . '</p></div>' . wp_kses_post(self::status_badge($seo_connected ? 'pass' : 'neutral', $seo_connected ? __('YSEO connected', 'yablonsky-multilingual') : __('Provider ready', 'yablonsky-multilingual'))) . '</div>';
      echo '<div class="ypm-metrics ypm-metrics--4">';
      self::render_metric(__('Contract', 'yablonsky-multilingual'), (string) ($seo_caps['contract'] ?? 'v1'), __('Stable read-only provider', 'yablonsky-multilingual'), 'pass');
      self::render_metric(__('Bulk graph', 'yablonsky-multilingual'), !empty($seo_caps['bulk_alternates']) ? __('Ready', 'yablonsky-multilingual') : __('Review', 'yablonsky-multilingual'), __('Batch-primed relation lookup', 'yablonsky-multilingual'), !empty($seo_caps['bulk_alternates']) ? 'pass' : 'attention');
      self::render_metric(__('Localized URLs', 'yablonsky-multilingual'), !empty($seo_caps['localized_urls']) ? __('Ready', 'yablonsky-multilingual') : __('Review', 'yablonsky-multilingual'), __('Owned by YMulti', 'yablonsky-multilingual'), !empty($seo_caps['localized_urls']) ? 'pass' : 'attention');
      self::render_metric(__('Taxonomy graph', 'yablonsky-multilingual'), !empty($seo_caps['taxonomy_alternates_bulk']) ? __('Ready', 'yablonsky-multilingual') : __('Review', 'yablonsky-multilingual'), __('Bounded Category / Tag / Woo term graph', 'yablonsky-multilingual'), !empty($seo_caps['taxonomy_alternates_bulk']) ? 'pass' : 'attention');
      self::render_metric(__('Request context', 'yablonsky-multilingual'), !empty($seo_caps['request_context']) ? __('Ready', 'yablonsky-multilingual') : __('Review', 'yablonsky-multilingual'), __('Content vs admin / REST / cron identity', 'yablonsky-multilingual'), !empty($seo_caps['request_context']) ? 'pass' : 'attention');
      self::render_metric(__('Sitemap output', 'yablonsky-multilingual'), __('Delegated', 'yablonsky-multilingual'), __('One SEO authority only', 'yablonsky-multilingual'), 'neutral');
      echo '</div></section>';
    }

    if (class_exists('Yablonsky\Multilingual\SemanticInvalidation')) {
      $perf_caps = SemanticInvalidation::capabilities();
      $perf_connected = has_action('yablonsky_multilingual_semantic_invalidated_v1') !== false;
      echo '<section class="ypm-card ypm-card--standalone"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Performance integration', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Semantic invalidation contract', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('YMulti identifies exact multilingual changes; YPerf remains the cache and purge execution authority.', 'yablonsky-multilingual') . '</p></div>' . wp_kses_post(self::status_badge($perf_connected ? 'pass' : 'neutral', $perf_connected ? __('Consumer connected', 'yablonsky-multilingual') : __('Contract ready', 'yablonsky-multilingual'))) . '</div><div class="ypm-metrics ypm-metrics--4">';
      self::render_metric(__('Post targets', 'yablonsky-multilingual'), !empty($perf_caps['exact_post_targets']) ? __('Exact', 'yablonsky-multilingual') : __('Review', 'yablonsky-multilingual'), __('Localized URLs included', 'yablonsky-multilingual'), !empty($perf_caps['exact_post_targets']) ? 'pass' : 'attention');
      self::render_metric(__('Term targets', 'yablonsky-multilingual'), !empty($perf_caps['exact_term_targets']) ? __('Exact', 'yablonsky-multilingual') : __('Review', 'yablonsky-multilingual'), __('Taxonomy-aware payload', 'yablonsky-multilingual'), !empty($perf_caps['exact_term_targets']) ? 'pass' : 'attention');
      self::render_metric(__('Purge executor', 'yablonsky-multilingual'), empty($perf_caps['purge_executor']) ? __('Delegated', 'yablonsky-multilingual') : __('YMulti', 'yablonsky-multilingual'), __('Native cache ownership', 'yablonsky-multilingual'), empty($perf_caps['purge_executor']) ? 'pass' : 'attention');
      self::render_metric(__('Remote calls', 'yablonsky-multilingual'), empty($perf_caps['remote_calls']) ? __('None', 'yablonsky-multilingual') : __('Detected', 'yablonsky-multilingual'), __('Write-side local signal only', 'yablonsky-multilingual'), empty($perf_caps['remote_calls']) ? 'pass' : 'attention');
      echo '</div></section>';
    }

    if (class_exists('Yablonsky\Multilingual\Switcher')) {
      $a11y = Switcher::accessibility_capabilities();
      $locale_quality = Languages::quality_report();
      $a11y_ready = !empty($a11y['unique_control_id']) && !empty($a11y['keyboard_native_select']) && empty($a11y['inline_event_handler']) && !empty($a11y['conditional_assets']);
      echo '<section class="ypm-card ypm-card--standalone"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Frontend quality', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Accessibility & locale integrity', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('Code-level readiness for the language switcher and locale registry. Lighthouse remains the live acceptance authority for the deployed page.', 'yablonsky-multilingual') . '</p></div>' . wp_kses_post(self::status_badge($a11y_ready && !empty($locale_quality['complete']) ? 'pass' : 'attention', $a11y_ready && !empty($locale_quality['complete']) ? __('Ready for live audit', 'yablonsky-multilingual') : __('Review', 'yablonsky-multilingual'))) . '</div><div class="ypm-metrics ypm-metrics--4">';
      self::render_metric(__('Switcher name', 'yablonsky-multilingual'), !empty($a11y['accessible_name']) ? __('Labelled', 'yablonsky-multilingual') : __('Review', 'yablonsky-multilingual'), __('Unique accessible control name', 'yablonsky-multilingual'), !empty($a11y['accessible_name']) ? 'pass' : 'attention');
      self::render_metric(__('Switcher JS', 'yablonsky-multilingual'), empty($a11y['inline_event_handler']) ? __('Delegated', 'yablonsky-multilingual') : __('Inline', 'yablonsky-multilingual'), __('Loaded only with dropdown UI', 'yablonsky-multilingual'), empty($a11y['inline_event_handler']) && !empty($a11y['conditional_assets']) ? 'pass' : 'attention');
      self::render_metric(__('Locale families', 'yablonsky-multilingual'), (string) ($locale_quality['covered_families'] ?? 0) . '/' . (string) ($locale_quality['required_families'] ?? 0), __('Canonical registry coverage', 'yablonsky-multilingual'), !empty($locale_quality['complete']) ? 'pass' : 'attention');
      self::render_metric(__('RTL active', 'yablonsky-multilingual'), (string) count((array) ($locale_quality['active_rtl'] ?? [])), __('Direction metadata retained', 'yablonsky-multilingual'), 'neutral');
      echo '</div></section>';
    }

    echo '<details class="ypm-disclosure ypm-disclosure--wide">';
    echo '<summary><span><strong>' . esc_html__('Technical details', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('YMulti, WordPress, plugin and server facts', 'yablonsky-multilingual') . '</small></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary>';
    echo '<div class="ypm-disclosure__body ypm-tech-grid">';
    self::render_kv_section(__('YMulti', 'yablonsky-multilingual'), $report['ypm']);
    self::render_kv_section(__('WordPress', 'yablonsky-multilingual'), $report['wordpress']);
    self::render_kv_section(__('Plugins', 'yablonsky-multilingual'), $report['plugins']);
    self::render_kv_section(__('Server', 'yablonsky-multilingual'), $report['server']);
    echo '</div></details>';

    echo '<details class="ypm-disclosure ypm-disclosure--wide">';
    echo '<summary><span><strong>' . esc_html__('Recommended requirements', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Reference values; current status is shown above', 'yablonsky-multilingual') . '</small></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary>';
    echo '<div class="ypm-disclosure__body"><ul class="ypm-requirements">';
    echo '<li><strong>' . esc_html__('WordPress', 'yablonsky-multilingual') . '</strong><span>' . esc_html(YPM_MIN_WP) . '+</span></li>';
    echo '<li><strong>' . esc_html__('PHP', 'yablonsky-multilingual') . '</strong><span>' . esc_html(YPM_MIN_PHP) . '+ · ' . esc_html__('8.3+ recommended', 'yablonsky-multilingual') . '</span></li>';
    echo '<li><strong>' . esc_html__('Pretty permalinks', 'yablonsky-multilingual') . '</strong><span>' . esc_html__('Enabled', 'yablonsky-multilingual') . '</span></li>';
    echo '<li><strong>' . esc_html__('Memory', 'yablonsky-multilingual') . '</strong><span>' . esc_html__('128M+ · 256M preferred', 'yablonsky-multilingual') . '</span></li>';
    echo '</ul></div></details>';

    echo '</div>';
  }

  public static function render_shell_header(string $title, string $description, string $state = 'neutral', string $state_label = ''): void {
    $safe_mode = class_exists('Yablonsky\\Multilingual\\SafeMode') && SafeMode::enabled();
    $mode_label = $safe_mode ? __('YMulti SAFE', 'yablonsky-multilingual') : __('YMulti LIVE', 'yablonsky-multilingual');
    echo '<header class="ypm-product-header">';
    echo '<div class="ypm-product-title"><h1>' . esc_html__('Yablonsky Multilingual', 'yablonsky-multilingual') . '</h1><p>' . esc_html__('Set up your languages, translate pages and products, and manage your multilingual website in one place.', 'yablonsky-multilingual') . '</p></div>';
    echo '<div class="ypm-product-actions"><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=ypm-translations')) . '">' . esc_html__('Translation Workspace', 'yablonsky-multilingual') . '</a>';
    echo '<span class="ypm-family-pill ' . esc_attr($safe_mode ? 'ypm-family-pill--attention' : 'ypm-family-pill--muted') . '">' . esc_html($mode_label) . '</span>';
    echo '<span class="ypm-family-pill">v' . esc_html(YPM_VERSION) . '</span>';
    echo '<span class="ypm-family-pill ypm-family-pill--stable">' . esc_html__('Stable', 'yablonsky-multilingual') . '</span>';
    echo '<span class="ypm-family-pill ypm-family-pill--free">' . esc_html__('Local features · Free', 'yablonsky-multilingual') . '</span></div>';
    echo '</header>';
    self::render_product_nav();
    if ($title !== __('Dashboard', 'yablonsky-multilingual')) {
      echo '<div class="ypm-context-head"><div><span class="ypm-context-kicker">' . esc_html__('Workspace', 'yablonsky-multilingual') . '</span><h2>' . esc_html($title) . '</h2><p>' . esc_html($description) . '</p></div>';
      if ($state_label !== '') echo wp_kses_post(self::status_badge($state, $state_label));
      echo '</div>';
    }
  }

  /**
   * Keep the YMulti working surfaces connected without deep WordPress menus.
   * Capability checks keep commerce/admin-only destinations out of sight for
   * users who cannot operate them. Hidden detail screens map back to their
   * owning workspace so the current location remains understandable.
   */
  private static function render_product_nav(): void {
    $page_raw = filter_input(INPUT_GET, 'page', FILTER_UNSAFE_RAW);
    $page = is_string($page_raw) ? sanitize_key($page_raw) : 'ypm-multilingual';
    $active_page = $page === 'ypm-translation-family' ? 'ypm-translations' : ($page === 'ypm-term-translation' ? 'ypm-taxonomies' : ($page === 'ypm-translation-job' ? 'ypm-translation-jobs' : $page));

    echo '<nav class="ypm-product-nav" aria-label="' . esc_attr__('YMulti sections', 'yablonsky-multilingual') . '"><div class="ypm-product-nav__track">';
    foreach (self::navigation_items() as $item) {
      if (!current_user_can((string) $item['cap'])) continue;
      $current = $active_page === $item['slug'];
      echo '<a class="ypm-product-nav__item' . esc_attr($current ? ' is-current' : '') . '" href="' . esc_url(admin_url('admin.php?page=' . $item['slug'])) . '"' . ($current ? ' aria-current="page"' : '') . '>' . esc_html((string) $item['label']);
      if (!empty($item['badge'])) {
        /* translators: %d: multilingual drafts awaiting checking. */
        $badge_label = sprintf(__('Drafts waiting: %d', 'yablonsky-multilingual'), (int) $item['badge']);
        echo '<span class="ypm-nav-badge" aria-label="' . esc_attr($badge_label) . '">' . esc_html((string) (int) $item['badge']) . '</span>';
      }
      echo '</a>';
    }
    echo '</div></nav>';
  }


/** @return array<string,array{ok:bool,url:string,hook:string}> */
public static function feature_route_health(): array {
  $out = [];
  foreach (['ypm-routing', 'ypm-visual-review', 'ypm-launch-center'] as $slug) {
    $hook = function_exists('get_plugin_page_hookname') ? get_plugin_page_hookname($slug, 'ypm-multilingual') : '';
    global $_registered_pages;
    $ok = $hook !== '' && is_array($_registered_pages ?? null) && isset($_registered_pages[$hook]);
    $out[$slug] = ['ok' => $ok, 'url' => admin_url('admin.php?page=' . $slug), 'hook' => (string) $hook];
  }
  return $out;
}

  public static function render_metric(string $label, string $value, string $note, string $state): void {
    echo '<div class="ypm-metric ypm-metric--' . esc_attr($state) . '"><span>' . esc_html($label) . '</span><strong>' . esc_html($value) . '</strong><small>' . esc_html($note) . '</small></div>';
  }

  public static function status_badge(string $state, string $label): string {
    $allowed = ['pass', 'near', 'progress', 'attention', 'fail', 'skip', 'neutral', 'running'];
    if (!in_array($state, $allowed, true)) $state = 'neutral';
    return '<span class="ypm-status-badge ypm-status-badge--' . esc_attr($state) . '">' . esc_html($label) . '</span>';
  }

  public static function language_badge(string $locale): string {
    $locale = sanitize_text_field($locale);
    if ($locale === '') return '<span class="ypm-lang-badge ypm-lang-badge--empty">—</span>';
    return '<span class="ypm-lang-badge" title="' . esc_attr(Languages::display_name($locale)) . '">' . esc_html(Languages::display_code($locale)) . '</span>';
  }

  private static function rest_test_summary(?array $last): array {
    $summary = ['pass' => 0, 'fail' => 0, 'skip' => 0, 'items' => []];
    if (!$last || empty($last['tests']) || !is_array($last['tests'])) return $summary;

    $internal = isset($last['tests']['internal_rest_do_request']) && is_array($last['tests']['internal_rest_do_request']) ? $last['tests']['internal_rest_do_request'] : [];
    foreach ([
      'widget_types' => __('Widget REST types', 'yablonsky-multilingual'),
      'block_renderer' => __('Block renderer', 'yablonsky-multilingual'),
    ] as $key => $label) {
      $row = isset($internal[$key]) && is_array($internal[$key]) ? $internal[$key] : [];
      $ok = empty($row['error']) && empty($row['is_error']) && isset($row['status']) && (int) $row['status'] >= 200 && (int) $row['status'] < 300;
      $state = $ok ? 'pass' : 'fail';
      $summary[$state]++;
      $summary['items'][] = ['label' => $label, 'state' => $state, 'value' => isset($row['status']) ? 'HTTP ' . (int) $row['status'] : __('Response pending', 'yablonsky-multilingual')];
    }

    $http = isset($last['tests']['http_rest_root']) && is_array($last['tests']['http_rest_root']) ? $last['tests']['http_rest_root'] : [];
    if (!empty($http['disabled'])) {
      $summary['skip']++;
      $summary['items'][] = ['label' => __('External self-HTTP', 'yablonsky-multilingual'), 'state' => 'skip', 'value' => __('Available by design', 'yablonsky-multilingual')];
    }
    return $summary;
  }

  private static function render_rest_test_card(?array $last): void {
    $summary = self::rest_test_summary($last);
    $has_run = $last !== null;
    $state = !$has_run ? 'neutral' : ($summary['fail'] > 0 ? 'fail' : 'pass');
    echo '<div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Runtime self-test', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('REST health', 'yablonsky-multilingual') . '</h2></div><div id="ypm-rest-summary-badge">';
    echo wp_kses_post(self::status_badge($state, !$has_run ? __('Ready to run', 'yablonsky-multilingual') : ($summary['fail'] > 0 ? __('Needs review', 'yablonsky-multilingual') : __('Healthy', 'yablonsky-multilingual'))));
    echo '</div></div>';
    echo '<p class="ypm-card__copy">' . esc_html__('Checks the internal WordPress REST endpoints used by Widgets and the block renderer while site content stays unchanged.', 'yablonsky-multilingual') . '</p>';
    echo '<div class="ypm-safety-strip" aria-label="' . esc_attr__('Self-test safety properties', 'yablonsky-multilingual') . '"><span>' . esc_html__('Safe', 'yablonsky-multilingual') . '</span><span>' . esc_html__('Read-only', 'yablonsky-multilingual') . '</span><span>' . esc_html__('Internal', 'yablonsky-multilingual') . '</span></div>';

    $progress_class = !$has_run ? '' : ($summary['fail'] > 0 ? ' is-fail' : ' is-complete');
    echo '<div class="ypm-test-progress' . esc_attr($progress_class) . '" id="ypm-rest-progress" aria-hidden="true"><span></span></div>';
    echo '<p class="ypm-test-message" id="ypm-rest-message">' . esc_html($has_run ? __('Last result is shown below.', 'yablonsky-multilingual') : __('Ready to run. Usually completes in a few seconds.', 'yablonsky-multilingual')) . '</p>';
    echo '<div class="ypm-test-totals" id="ypm-rest-totals"' . ($has_run ? '' : ' hidden') . '>';
    if ($has_run) {
      echo '<span class="is-pass">' . esc_html__('PASS', 'yablonsky-multilingual') . ' ' . esc_html((string) $summary['pass']) . '</span><span class="is-fail">' . esc_html__('FAIL', 'yablonsky-multilingual') . ' ' . esc_html((string) $summary['fail']) . '</span><span class="is-skip">' . esc_html__('SKIP', 'yablonsky-multilingual') . ' ' . esc_html((string) $summary['skip']) . '</span>';
    }
    echo '</div>';

    echo '<div id="ypm-rest-meaning">';
    if ($has_run) {
      if ($summary['fail'] > 0) {
        echo '<div class="ypm-result-meaning ypm-result-meaning--fail"><strong>' . esc_html__('Internal REST needs review.', 'yablonsky-multilingual') . '</strong><span>' . esc_html__('At least one YMulti runtime contract returned an unexpected internal REST response. Review the highlighted row and technical result below.', 'yablonsky-multilingual') . '</span></div>';
      } else {
        echo '<div class="ypm-result-meaning ypm-result-meaning--pass"><strong>' . esc_html__('Internal REST contracts are healthy.', 'yablonsky-multilingual') . '</strong><span>' . esc_html__('Widget types and the block renderer answered successfully. External self-HTTP is intentionally skipped to avoid CDN or proxy loop risk.', 'yablonsky-multilingual') . '</span></div>';
      }
    }
    echo '</div>';

    echo '<div class="ypm-test-results" id="ypm-rest-results">';
    if ($has_run) {
      foreach ($summary['items'] as $item) {
        echo '<div class="ypm-mini-result"><span class="ypm-status-dot ypm-status-dot--' . esc_attr($item['state']) . '"></span><div><strong>' . esc_html($item['label']) . '</strong><small>' . esc_html($item['value']) . '</small></div>' . wp_kses_post(self::status_badge($item['state'], strtoupper($item['state']))) . '</div>';
      }
    } else {
      echo '<div class="ypm-empty-state">' . esc_html__('Self-test ready for this user.', 'yablonsky-multilingual') . '</div>';
    }
    echo '</div>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ypm-test-form" id="ypm-rest-test-form">';
    echo '<input type="hidden" name="action" value="ypm_rest_selftest" />';
    wp_nonce_field('ypm_rest_selftest', 'ypm_rest_selftest_nonce');
    echo '<input type="hidden" id="ypm-rest-ajax-nonce" value="' . esc_attr(wp_create_nonce('ypm_rest_selftest_ajax')) . '" />';
    echo '<button type="submit" class="button button-primary" id="ypm-rest-test-button">' . esc_html__('Run REST self-test', 'yablonsky-multilingual') . '</button>';
    echo '<span class="ypm-test-meta">' . esc_html__('Runs in place. This page stays open while the result is prepared.', 'yablonsky-multilingual') . '</span>';
    echo '</form>';

    $raw = $last ? wp_json_encode($last, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';
    echo '<details class="ypm-inline-details" id="ypm-rest-raw-details"><summary><span><strong>' . esc_html__('Raw technical result', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Local JSON evidence for support, QA, or comparison.', 'yablonsky-multilingual') . '</small></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary><div class="ypm-raw-report"><div class="ypm-raw-toolbar">';
    echo '<button type="button" class="button" id="ypm-rest-copy"';
    if ($raw === '') echo ' disabled="disabled"';
    echo '>' . esc_html__('Copy JSON', 'yablonsky-multilingual') . '</button>';
    echo '<button type="button" class="button" id="ypm-rest-save"';
    if ($raw === '') echo ' disabled="disabled"';
    echo '>' . esc_html__('Save JSON', 'yablonsky-multilingual') . '</button>';
    echo '<span class="ypm-raw-feedback" id="ypm-rest-raw-feedback" role="status" aria-live="polite"></span></div><pre id="ypm-rest-raw">' . esc_html($raw !== '' ? $raw : __('Ready to run.', 'yablonsky-multilingual')) . '</pre></div></details>';
  }

  /**
   * Persist last REST self-test result per user.
   */
  private static function get_last_rest_selftest(): ?array {
    $uid = get_current_user_id();
    if (!$uid) return null;
    $key = 'ypm_rest_selftest_' . $uid;
    $val = get_transient($key);
    return is_array($val) ? $val : null;
  }

  private static function set_last_rest_selftest(array $result): void {
    $uid = get_current_user_id();
    if (!$uid) return;
    $key = 'ypm_rest_selftest_' . $uid;
    set_transient($key, $result, 15 * MINUTE_IN_SECONDS);
  }

  /**
   * Run a server-side REST self-test without relying on browser fetch.
   * This avoids JS/minify/CSP issues and helps diagnose caching/WAF problems.
   */
  public static function handle_rest_selftest(): void {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Insufficient permissions.', 'yablonsky-multilingual'));
    }

    $nonce = isset($_POST['ypm_rest_selftest_nonce']) ? sanitize_text_field(wp_unslash($_POST['ypm_rest_selftest_nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'ypm_rest_selftest')) {
      wp_die(esc_html__('Security token needs a refresh. Reload this page and repeat the action.', 'yablonsky-multilingual'));
    }

    self::set_last_rest_selftest(self::run_rest_selftest());
    wp_safe_redirect(admin_url('admin.php?page=ypm-system-status&rest_test=done'));
    exit;
  }

  public static function ajax_rest_selftest(): void {
    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Insufficient permissions.', 'yablonsky-multilingual')], 403);
    }
    check_ajax_referer('ypm_rest_selftest_ajax', 'nonce');

    $result = self::run_rest_selftest();
    self::set_last_rest_selftest($result);
    wp_send_json_success([
      'result' => $result,
      'summary' => self::rest_test_summary($result),
    ]);
  }

  private static function run_rest_selftest(): array {
    $result = [
      'time' => gmdate('c'),
      'site' => home_url('/'),
      'tests' => [],
    ];

    $internal = [];
    try {
      $req = new \WP_REST_Request('GET', '/wp/v2/widget-types');
      $req->set_param('context', 'edit');
      $req->set_param('per_page', 1);
      $res = rest_do_request($req);
      $data = $res instanceof \WP_REST_Response ? $res->get_data() : null;
      $internal['widget_types'] = [
        'status' => $res instanceof \WP_REST_Response ? $res->get_status() : 0,
        'is_error' => is_wp_error($res),
        'sample' => is_array($data) ? array_slice(array_keys($data), 0, 3) : (is_object($data) ? get_class($data) : gettype($data)),
      ];
    } catch (\Throwable $e) {
      $internal['widget_types'] = ['error' => $e->getMessage()];
    }

    try {
      $req2 = new \WP_REST_Request('GET', '/wp/v2/block-renderer/core/shortcode');
      $req2->set_param('context', 'edit');
      $req2->set_param('attributes', ['text' => '[ypm_language_switcher]']);
      $res2 = rest_do_request($req2);
      $data2 = $res2 instanceof \WP_REST_Response ? $res2->get_data() : null;
      $internal['block_renderer'] = [
        'status' => $res2 instanceof \WP_REST_Response ? $res2->get_status() : 0,
        'is_error' => is_wp_error($res2),
        'keys' => is_array($data2) ? array_keys($data2) : (is_object($data2) ? get_class($data2) : gettype($data2)),
      ];
    } catch (\Throwable $e) {
      $internal['block_renderer'] = ['error' => $e->getMessage()];
    }

    $result['tests']['internal_rest_do_request'] = $internal;
    $result['tests']['http_rest_root'] = [
      'disabled' => true,
      'reason' => 'Self-HTTP tests may trigger 502/loops on some setups (CDN/proxy).',
    ];
    return $result;
  }

  private static function render_kv_section(string $title, array $rows): void {
    echo '<div class="ypm-tech-card"><h3>' . esc_html($title) . '</h3><dl>';
    foreach ($rows as $k => $v) {
      echo '<div><dt>' . esc_html((string) $k) . '</dt><dd><code>' . esc_html((string) $v) . '</code></dd></div>';
    }
    echo '</dl></div>';
  }

  public static function enqueue_assets(string $hook): void {
    $page_raw = filter_input(INPUT_GET, 'page', FILTER_UNSAFE_RAW);
    $page = is_string($page_raw) ? sanitize_key($page_raw) : '';
    $ypm_pages = ['ypm-multilingual', 'ypm-languages', 'ypm-plan', 'ypm-translations', 'ypm-translation-family', 'ypm-translation-jobs', 'ypm-translation-job', 'ypm-drafts', 'ypm-taxonomies', 'ypm-strings', 'ypm-migration', 'ypm-system-status', 'ypm-ai', 'ypm-woocommerce', 'ypm-woo-emails', 'ypm-term-translation', 'ypm-menu-translation', 'ypm-string-discovery', 'ypm-media', 'ypm-routing', 'ypm-visual-review', 'ypm-launch-center', 'ypm-custom-fields', 'ypm-autopilot'];
    if (!in_array($page, $ypm_pages, true)) return;

    wp_enqueue_style(
      'ypm-admin-shell',
      YPM_ASSET_URL . 'admin-shell.css',
      [],
      YPM_VERSION
    );

    wp_enqueue_style('ymulti-language-ui', YPM_ASSET_URL . 'language-ui.css', [], YPM_VERSION);
    wp_enqueue_script('ymulti-language-ui', YPM_ASSET_URL . 'language-ui.js', [], YPM_VERSION, true);
    LanguageUI::localize_script();

    wp_enqueue_script(
      'ypm-admin-shell',
      YPM_ASSET_URL . 'admin-shell.js',
      [],
      YPM_VERSION,
      true
    );

    wp_localize_script('ypm-admin-shell', 'ypmAdminShell', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'running' => __('Running…', 'yablonsky-multilingual'),
      'saving' => __('Saving…', 'yablonsky-multilingual'),
      'checking' => __('Checking internal WordPress REST endpoints…', 'yablonsky-multilingual'),
      'complete' => __('Self-test complete.', 'yablonsky-multilingual'),
      'failed' => __('Self-test needs attention. The server-post fallback is available after reloading this page.', 'yablonsky-multilingual'),
      'healthy' => __('Healthy', 'yablonsky-multilingual'),
      'review' => __('Needs review', 'yablonsky-multilingual'),
      'notRun' => __('Ready to run', 'yablonsky-multilingual'),
      'pass' => __('PASS', 'yablonsky-multilingual'),
      'fail' => __('FAIL', 'yablonsky-multilingual'),
      'skip' => __('SKIP', 'yablonsky-multilingual'),
      'result' => __('Result', 'yablonsky-multilingual'),
      'runTest' => __('Run REST self-test', 'yablonsky-multilingual'),
      'confirmDelete' => __('Delete this local string override? WordPress will immediately fall back to the native translation.', 'yablonsky-multilingual'),
      'confirmImport' => __('Start the approved migration import? YMulti will write only non-conflicting multilingual identity in bounded batches; source data remains untouched.', 'yablonsky-multilingual'),
      'migrationNonce' => wp_create_nonce('ypm_migration_tick_ajax'),
      'migrationWorking' => __('Continuing migration…', 'yablonsky-multilingual'),
      'migrationComplete' => __('Migration job complete. Refreshing results…', 'yablonsky-multilingual'),
      'migrationPaused' => __('Continuous translation is configured but paused; queued work remains durable until resumed.', 'yablonsky-multilingual'),
      'copyJson' => __('Copy JSON', 'yablonsky-multilingual'),
      'copied' => __('Copied to clipboard.', 'yablonsky-multilingual'),
      'copyFailed' => __('Automatic copy needs attention. Select the JSON and copy it manually.', 'yablonsky-multilingual'),
      'saveJson' => __('Save JSON', 'yablonsky-multilingual'),
      'saved' => __('JSON file prepared.', 'yablonsky-multilingual'),
      'meaningPassTitle' => __('Internal REST contracts are healthy.', 'yablonsky-multilingual'),
      'meaningPass' => __('Widget types and the block renderer answered successfully. External self-HTTP is intentionally skipped to avoid CDN or proxy loop risk.', 'yablonsky-multilingual'),
      'meaningFailTitle' => __('Internal REST needs review.', 'yablonsky-multilingual'),
      'meaningFail' => __('At least one YMulti runtime contract returned an unexpected internal REST response. Review the highlighted row and technical result below.', 'yablonsky-multilingual'),
      'aiSuggestNonce' => wp_create_nonce('ypm_ai_suggest'),
      'aiModelCatalogNonce' => wp_create_nonce('ypm_ai_model_catalog_refresh'),
      'aiModelRefreshing' => __('Refreshing model catalog…', 'yablonsky-multilingual'),
      'aiModelRefreshed' => __('Model catalog refreshed. Reloading…', 'yablonsky-multilingual'),
      'aiModelRefreshFailed' => __('Model catalog refresh needs another try. Review the AI connection and refresh again.', 'yablonsky-multilingual'),
      'aiSuggesting' => __('Translating with Yablonsky AI…', 'yablonsky-multilingual'),
      'aiSuggest' => __('Suggest with AI', 'yablonsky-multilingual'),
      'aiSuggestFailed' => __('AI suggestion needs another attempt. Verify the route and source text, then retry.', 'yablonsky-multilingual'),
      'aiSuggestionReady' => __('AI suggestion ready for review.', 'yablonsky-multilingual'),
      'aiStringSuggestionReady' => __('AI suggestion ready for review. Save only if it is correct.', 'yablonsky-multilingual'),
      'aiSourceRequired' => __('Enter the exact source string first.', 'yablonsky-multilingual'),
      'confirmAiDisconnect' => __('Disconnect the Yablonsky AI key from this WordPress site? Local multilingual functionality will remain unchanged.', 'yablonsky-multilingual'),
      'pagerOf' => __('of', 'yablonsky-multilingual'),
      'autopilotTaxonomiesSelected' => __('selected', 'yablonsky-multilingual'),
      'autopilotTargetOne' => __('target', 'yablonsky-multilingual'),
      'autopilotTargetMany' => __('targets', 'yablonsky-multilingual'),
      'autopilotTypeOne' => __('content type', 'yablonsky-multilingual'),
      'autopilotTypeMany' => __('content types', 'yablonsky-multilingual'),
      'autopilotBehaviorOne' => __('behavior', 'yablonsky-multilingual'),
      'autopilotBehaviorMany' => __('behaviors', 'yablonsky-multilingual'),
      'autopilotAiCap' => __('AI cap', 'yablonsky-multilingual'),
      'autopilotDailyLimit' => __('Daily AI request limit', 'yablonsky-multilingual'),
      'autopilotUnlimited' => __('Unlimited', 'yablonsky-multilingual'),
      'autopilotLaunchReady' => __('Launch plan ready. The current scope will be scanned and background processing will start.', 'yablonsky-multilingual'),
      'autopilotLaunchIncomplete' => __('Choose at least one target language, content type and behavior to make the launch plan ready.', 'yablonsky-multilingual'),
      'bulkRowSelectedOne' => __('1 row selected', 'yablonsky-multilingual'),
      'bulkRowsSelected' => __('rows selected', 'yablonsky-multilingual'),
      'bulkTarget' => __('Target', 'yablonsky-multilingual'),
      'bulkAiReadyAction' => __('Verify the selected AI route to activate these actions.', 'yablonsky-multilingual'),
      'bulkAiAttention' => __('AI readiness', 'yablonsky-multilingual'),
      'aiStage' => __('Stage', 'yablonsky-multilingual'),
      'aiStageOf' => __('of', 'yablonsky-multilingual'),
      'aiAdaptiveStaging' => __('Adaptive staging', 'yablonsky-multilingual'),
      'aiRequests' => __('AI requests', 'yablonsky-multilingual'),
    ]);

    if ($page === 'ypm-media') {
      wp_enqueue_media();
      wp_enqueue_script('ymulti-media-workspace', YPM_ASSET_URL . 'media-workspace.js', [], YPM_VERSION, true);
      wp_localize_script('ymulti-media-workspace', 'YMultiMediaWorkspaceI18n', [
        'drafting' => __('Drafting…', 'yablonsky-multilingual'),
        'analyzing' => __('Analyzing image with the selected Vision model…', 'yablonsky-multilingual'),
        'draftFailed' => __('AI media draft needs another attempt.', 'yablonsky-multilingual'),
        'draftReady' => __('AI draft ready for review.', 'yablonsky-multilingual'),
        'refreshDraft' => __('Refresh AI draft', 'yablonsky-multilingual'),
        'sourceActive' => __('Source image remains active', 'yablonsky-multilingual'),
        'chooseImage' => __('Choose localized image', 'yablonsky-multilingual'),
        'useImage' => __('Use this image', 'yablonsky-multilingual'),
        'statusAttention' => __('Status needs attention', 'yablonsky-multilingual'),
        'complete' => __('complete', 'yablonsky-multilingual'),
      ]);
    }
  }

  public static function page_dashboard(): void {
    if (!current_user_can('manage_options')) return;
    Languages::ensure_options();

    $active = Languages::active_codes();
    $public = Languages::public_codes();
    $staged = Languages::staged_codes();
    $default = Languages::default_code();
    $families = 0; // resolved after effective SiteCoverage below
    $overrides = StringTranslations::total_overrides();
    $custom_field_policies = class_exists('Yablonsky\\Multilingual\\CustomFields') ? CustomFields::policies() : [];
    $report = Status::report();
    $checks = isset($report['checks']) && is_array($report['checks']) ? $report['checks'] : [];
    $passed = 0;
    foreach ($checks as $check) if (!empty($check['ok'])) $passed++;
    $healthy = count($checks) === $passed;
    $sources = MigrationTools::detect_sources();
    $detected = 0;
    foreach ($sources as $source) if (is_array($source)) $detected++;
    $woo = current_user_can('manage_woocommerce') && (class_exists('WooCommerce') || function_exists('WC'));
    $taxonomy_scope = class_exists('Yablonsky\Multilingual\AdminTerms') ? AdminTerms::manageable_taxonomies() : [];
    $taxonomy_count = count($taxonomy_scope);
    $languages_configured = count($active) >= 2;
    $translation_coverage = SiteCoverage::posts();
    $active_locale_count = count(Languages::active_codes());
    $families = $active_locale_count > 0 ? (int) floor(((int) ($translation_coverage['total'] ?? 0)) / $active_locale_count) : 0;
    $taxonomy_coverage = $taxonomy_count > 0 ? SiteCoverage::terms($taxonomy_scope) : SiteCoverage::metric(0, 0, __('Taxonomy automation becomes available when managed taxonomies are registered.', 'yablonsky-multilingual'));
    $menu_coverage = SiteCoverage::menus();
    $media_coverage = SiteCoverage::media();

    $ai_text_ready = class_exists('Yablonsky\Multilingual\AITranslation') && AITranslation::ready() && AITranslation::current_user_can_compute();
    $ai_vision_ready = class_exists('Yablonsky\Multilingual\AITranslation') && AITranslation::vision_ready() && AITranslation::current_user_can_compute();
    $ai_connected = class_exists('Yablonsky\Multilingual\AITranslation') && AITranslation::has_key() && AITranslation::workspace_id() !== '';
    $ai_operational = $ai_connected && ($ai_text_ready || $ai_vision_ready);
    if ($ai_text_ready && $ai_vision_ready) $ai_copy = __('Connected and verified for reviewed text translation and Vision-assisted media metadata.', 'yablonsky-multilingual');
    elseif ($ai_vision_ready) $ai_copy = __('Connected and operational for Vision-assisted media metadata. Text translation still has a separate canary gate.', 'yablonsky-multilingual');
    elseif ($ai_connected) $ai_copy = __('Connected. Review the selected model routes before using AI compute.', 'yablonsky-multilingual');
    else $ai_copy = __('Optional user-owned AI for reviewed translation drafts and suggestions.', 'yablonsky-multilingual');

    $autopilot_settings = class_exists('Yablonsky\Multilingual\Autopilot') ? Autopilot::settings() : [];
    $autopilot_stats = class_exists('Yablonsky\Multilingual\Autopilot') ? Autopilot::stats() : ['queued'=>0,'processing'=>0,'review_ready'=>0,'completed'=>0,'skipped'=>0,'failed'=>0];
    $autopilot_enabled = !empty($autopilot_settings['enabled']);
    $autopilot_paused = !empty($autopilot_settings['paused']);
    $autopilot_pending = (int) ($autopilot_stats['queued'] ?? 0) + (int) ($autopilot_stats['processing'] ?? 0);
    $autopilot_review = (int) ($autopilot_stats['review_ready'] ?? 0);
    $autopilot_failed = (int) ($autopilot_stats['failed'] ?? 0);
    $autopilot_state = !$autopilot_enabled ? 'neutral' : (($autopilot_paused || $autopilot_failed > 0) ? 'attention' : 'pass');
    if (!$autopilot_enabled) {
      $autopilot_label = __('Off', 'yablonsky-multilingual');
      $autopilot_copy = __('Optional continuous translation is off. Enable it only when this site should turn future source changes into bounded review work automatically.', 'yablonsky-multilingual');
    } elseif ($autopilot_paused) {
      $autopilot_label = __('Paused', 'yablonsky-multilingual');
      $autopilot_copy = __('Continuous translation is configured but paused; queued work remains durable until resumed.', 'yablonsky-multilingual');
    } elseif ($autopilot_failed > 0) {
      $autopilot_label = sprintf(
        /* translators: %d: failed Autopilot tasks. */
        _n('%d failed task', '%d failed tasks', $autopilot_failed, 'yablonsky-multilingual'),
        $autopilot_failed
      );
      $autopilot_copy = __('Autopilot is watching, but one or more bounded tasks need review after retry.', 'yablonsky-multilingual');
    } else {
      $autopilot_label = __('Watching', 'yablonsky-multilingual');
      $autopilot_copy = sprintf(
        /* translators: 1: queued/processing tasks, 2: review-ready tasks. */
        __('Continuous translation is active: %1$d pending and %2$d review-ready tasks.', 'yablonsky-multilingual'),
        $autopilot_pending,
        $autopilot_review
      );
    }

    global $wpdb;
    $job_active = 0;
    if (class_exists('Yablonsky\Multilingual\TranslationJobs')) {
      $job_table = TranslationJobs::table();
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Dashboard-only aggregate on the fixed YMulti-owned jobs table; no request data enters SQL.
      $job_active = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . esc_sql($job_table) . " WHERE status IN ('open','assigned','in_progress','review')");
    }
    $discovered_strings = 0;
    if (class_exists('Yablonsky\Multilingual\StringDiscovery')) {
      $discovery_table = StringDiscovery::table();
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Dashboard-only aggregate on the fixed YMulti-owned discovery table; no request data enters SQL.
      $discovered_strings = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . esc_sql($discovery_table));
    }

    $woo_coverage = SiteCoverage::metric(0, 0);
    $email_coverage = SiteCoverage::metric(0, 0);
    $attribute_label_coverage = SiteCoverage::metric(0, 0);
    if ($woo && class_exists('Yablonsky\Multilingual\Compat\Woo')) {
      $woo_matrix = Compat\Woo::localization_coverage_matrix();
      $woo_parts = [];
      foreach ($woo_matrix as $key => $metric) {
        if ($key === 'variations') continue;
        $woo_parts[] = $metric;
      }
      $woo_coverage = SiteCoverage::combine($woo_parts);
      $email_coverage = isset($woo_matrix['commerce_emails']) && is_array($woo_matrix['commerce_emails']) ? $woo_matrix['commerce_emails'] : SiteCoverage::metric(0, 0);
      $attribute_label_coverage = isset($woo_matrix['global_attributes']) && is_array($woo_matrix['global_attributes']) ? $woo_matrix['global_attributes'] : SiteCoverage::metric(0, 0);
    }

    $site_coverage_parts = [$translation_coverage, $taxonomy_coverage, $menu_coverage, $media_coverage];
    if ($woo) { $site_coverage_parts[] = $email_coverage; $site_coverage_parts[] = $attribute_label_coverage; }
    $site_coverage = SiteCoverage::combine($site_coverage_parts);

    echo '<div class="wrap ypm-admin ypm-admin--dashboard">';
    self::render_shell_header(
      __('Dashboard', 'yablonsky-multilingual'),
      __('A compact view of multilingual coverage, local overrides, commerce integration and system readiness — with the next useful action one click away.', 'yablonsky-multilingual'),
      $healthy ? 'pass' : 'attention',
      $healthy ? __('System healthy', 'yablonsky-multilingual') : __('Review system', 'yablonsky-multilingual')
    );

    self::render_dashboard_ai_onboarding();

    echo '<div class="ypm-metrics ypm-metrics--4">';
    self::render_metric(__('Active locales', 'yablonsky-multilingual'), (string) count($active), Languages::display_name($default) . ' · ' . Languages::display_code($default), 'neutral');
    self::render_metric(__('Translation families', 'yablonsky-multilingual'), (string) $families, __('Connected content groups', 'yablonsky-multilingual'), 'neutral');
    self::render_metric(__('String overrides', 'yablonsky-multilingual'), (string) $overrides, __('Explicit local corrections', 'yablonsky-multilingual'), 'neutral');
    self::render_metric(__('System checks', 'yablonsky-multilingual'), $passed . '/' . count($checks), $healthy ? __('All checks pass', 'yablonsky-multilingual') : __('Review attention items', 'yablonsky-multilingual'), $healthy ? 'pass' : 'attention');
    echo '</div>';

    $languages_copy = sprintf(
      /* translators: %d: number of enabled locales. */
      __('%d locales enabled. Manage routing, default locale and switcher order.', 'yablonsky-multilingual'),
      count($active)
    );

    $translations_copy = (string) $translation_coverage['detail'];

    $taxonomies_copy = $taxonomy_count > 0
      ? (string) $taxonomy_coverage['detail']
      : __('Supported taxonomy management becomes available when a manageable taxonomy is registered.', 'yablonsky-multilingual');

    $strings_copy = sprintf(
      /* translators: %d: number of explicit local string overrides. */
      __('%d explicit overrides. Native language packs remain the fallback.', 'yablonsky-multilingual'),
      $overrides
    );

    if ($detected > 0) {
      $migration_copy = sprintf(
        /* translators: %d: number of detected migration sources. */
        __('%d migration source(s) detected. Dry-run before any import.', 'yablonsky-multilingual'),
        $detected
      );
    } else {
      $migration_copy = __('Migration source discovery is ready for supported source data.', 'yablonsky-multilingual');
    }

    $workspace_summary = $site_coverage['total'] > 0
      ? (string) $site_coverage['label']
      : __('Localization workload ready for measurement', 'yablonsky-multilingual');
    echo '<section class="ypm-card ypm-card--standalone ypm-dashboard-journey"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Workspaces', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Continue multilingual work', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('Statuses below describe this installed site: actual localized records versus the records required by the active languages. Green represents measured completion for the installed workload.', 'yablonsky-multilingual') . '</p></div>' . wp_kses_post(self::status_badge((string) $site_coverage['state'], $workspace_summary)) . '</div>';
    if ((int) $site_coverage['total'] > 0) {
      echo '<div class="ymulti-site-progress is-' . esc_attr((string) $site_coverage['state']) . '"><div><strong>' . esc_html((string) $site_coverage['label']) . '</strong><span>' . esc_html(sprintf(
        /* translators: 1: completed locale records, 2: required locale records. */
        __('%1$d of %2$d measured locale records complete', 'yablonsky-multilingual'),
        (int) $site_coverage['done'],
        (int) $site_coverage['total']
      )) . '</span></div><span class="ymulti-coverage-rail"><i style="width:' . esc_attr((string) $site_coverage['percent']) . '%"></i></span></div>';
    }
    echo '<div class="ypm-action-grid">';
    self::render_dashboard_action('dashicons-admin-site-alt3', __('Languages', 'yablonsky-multilingual'), $languages_copy, 'ypm-languages', __('Manage languages', 'yablonsky-multilingual'), $languages_configured ? 'neutral' : 'attention', $languages_configured ? sprintf(
      /* translators: %d: active locales. */
      __('%d active', 'yablonsky-multilingual'), count($active)
    ) : __('Add languages', 'yablonsky-multilingual'));
    self::render_dashboard_action('dashicons-translation', __('Translations', 'yablonsky-multilingual'), $translations_copy, 'ypm-translations', __('Open work queue', 'yablonsky-multilingual'), (string) $translation_coverage['state'], (string) $translation_coverage['label'], $translation_coverage);
    self::render_dashboard_action('dashicons-groups', __('Translation Jobs', 'yablonsky-multilingual'), __('Optional professional workflow for assignments, review drafts and XLIFF interchange.', 'yablonsky-multilingual'), 'ypm-translation-jobs', __('Open jobs', 'yablonsky-multilingual'), $job_active > 0 ? 'attention' : 'neutral', $job_active > 0 ? sprintf(
      /* translators: %d: active translation jobs. */
      _n('%d active job', '%d active jobs', $job_active, 'yablonsky-multilingual'), $job_active
    ) : __('0 active jobs', 'yablonsky-multilingual'));
    if ($taxonomy_count > 0) self::render_dashboard_action('dashicons-category', __('Taxonomies', 'yablonsky-multilingual'), $taxonomies_copy, 'ypm-taxonomies', __('Open term queue', 'yablonsky-multilingual'), (string) $taxonomy_coverage['state'], (string) $taxonomy_coverage['label'], $taxonomy_coverage);
    self::render_dashboard_action('dashicons-menu', __('Menus', 'yablonsky-multilingual'), (string) $menu_coverage['detail'], 'ypm-menu-translation', __('Translate menus', 'yablonsky-multilingual'), (string) $menu_coverage['state'], (string) $menu_coverage['label'], $menu_coverage);
    self::render_dashboard_action('dashicons-superhero-alt', __('Yablonsky AI', 'yablonsky-multilingual'), $ai_copy, 'ypm-ai', $ai_operational ? __('Open AI', 'yablonsky-multilingual') : __('Configure AI', 'yablonsky-multilingual'), $ai_operational ? 'pass' : ($ai_connected ? 'attention' : 'neutral'), $ai_operational ? __('Operational', 'yablonsky-multilingual') : ($ai_connected ? __('Review routes', 'yablonsky-multilingual') : __('Connection setup available', 'yablonsky-multilingual')));
    self::render_dashboard_action('dashicons-controls-repeat', __('Autopilot', 'yablonsky-multilingual'), $autopilot_copy, 'ypm-autopilot', $autopilot_enabled ? __('Open Autopilot', 'yablonsky-multilingual') : __('Configure Autopilot', 'yablonsky-multilingual'), $autopilot_state, $autopilot_label);
    self::render_dashboard_action('dashicons-editor-quote', __('Strings', 'yablonsky-multilingual'), $strings_copy, 'ypm-strings', __('Open dictionary', 'yablonsky-multilingual'), 'neutral', $overrides > 0 ? sprintf(
      /* translators: %d: explicit string overrides. */
      _n('%d override', '%d overrides', $overrides, 'yablonsky-multilingual'), $overrides
    ) : __('Native fallback', 'yablonsky-multilingual'));
    self::render_dashboard_action('dashicons-database', __('Custom Fields', 'yablonsky-multilingual'), __('Define explicit copy/sync/independent behavior for public custom fields and ACF-style post meta.', 'yablonsky-multilingual'), 'ypm-custom-fields', __('Manage field policies', 'yablonsky-multilingual'), 'neutral', count($custom_field_policies) > 0 ? sprintf(
      /* translators: %d: configured custom-field policies. */
      _n('%d policy', '%d policies', count($custom_field_policies), 'yablonsky-multilingual'),
      count($custom_field_policies)
    ) : __('Optional', 'yablonsky-multilingual'));
    self::render_dashboard_action('dashicons-search', __('String Discovery', 'yablonsky-multilingual'), __('On-demand inventory of static translation calls in active themes and plugins. Discovery inventories candidates while override decisions remain explicit.', 'yablonsky-multilingual'), 'ypm-string-discovery', __('Discover strings', 'yablonsky-multilingual'), 'neutral', $discovered_strings > 0 ? sprintf(
      /* translators: %d: discovered strings. */
      _n('%d discovered', '%d discovered', $discovered_strings, 'yablonsky-multilingual'), $discovered_strings
    ) : __('Ready to scan', 'yablonsky-multilingual'));
    self::render_dashboard_action('dashicons-format-image', __('Media Localization', 'yablonsky-multilingual'), (string) $media_coverage['detail'], 'ypm-media', __('Localize media', 'yablonsky-multilingual'), (string) $media_coverage['state'], (string) $media_coverage['label'], $media_coverage);
    if ($woo) {
      self::render_dashboard_action('dashicons-cart', __('WooCommerce', 'yablonsky-multilingual'), __('Live completion across products, product taxonomies, global attributes and commerce emails.', 'yablonsky-multilingual'), 'ypm-woocommerce', __('Open commerce', 'yablonsky-multilingual'), (string) $woo_coverage['state'], (string) $woo_coverage['label'], $woo_coverage);
      self::render_dashboard_action('dashicons-email-alt2', __('Commerce Emails', 'yablonsky-multilingual'), (string) $email_coverage['detail'], 'ypm-woo-emails', __('Manage emails', 'yablonsky-multilingual'), (string) $email_coverage['state'], (string) $email_coverage['label'], $email_coverage);
    }
    $migration_job = get_option('ypm_migration_job', []);
    $migration_complete = is_array($migration_job) && ($migration_job['status'] ?? '') === 'complete';
    self::render_dashboard_action('dashicons-database-import', __('Migration', 'yablonsky-multilingual'), $migration_copy, 'ypm-migration', __('Open migration', 'yablonsky-multilingual'), $migration_complete ? 'pass' : ($detected > 0 ? 'attention' : 'neutral'), $migration_complete ? __('Complete', 'yablonsky-multilingual') : ($detected > 0 ? __('Action available', 'yablonsky-multilingual') : __('Ready as needed', 'yablonsky-multilingual')));
    self::render_dashboard_teaser_action('dashicons-yes-alt', __('Launch Center', 'yablonsky-multilingual'), __('Check real installed-site completion and runtime launch gates before exposing every configured language publicly.', 'yablonsky-multilingual'), __('Live', 'yablonsky-multilingual'), __('Open launch gates', 'yablonsky-multilingual'), admin_url('admin.php?page=ypm-launch-center'));
    echo '</div>';
    echo '</section>';

    echo '<div class="ypm-dashboard-grid ypm-dashboard-grid--home">';
    echo '<section class="ypm-card ypm-card--primary"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('System', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Runtime readiness', 'yablonsky-multilingual') . '</h2></div>' . wp_kses_post(self::status_badge($healthy ? 'pass' : 'attention', $healthy ? __('Healthy', 'yablonsky-multilingual') : __('Review', 'yablonsky-multilingual'))) . '</div><p class="ypm-card__copy">' . esc_html__('Compatibility stays summarized here; REST evidence and technical details remain on the System screen.', 'yablonsky-multilingual') . '</p><a class="button" href="' . esc_url(admin_url('admin.php?page=ypm-system-status')) . '">' . esc_html__('Open System', 'yablonsky-multilingual') . '</a></section>';
    echo '<section class="ypm-card"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Architecture', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Native-first translation flow', 'yablonsky-multilingual') . '</h2></div></div><p class="ypm-card__copy">' . esc_html__('YMulti keeps content editing in WordPress, uses explicit language identity and bounded admin tools, and keeps native editors as the content authority.', 'yablonsky-multilingual') . '</p><a class="button" href="' . esc_url(admin_url('admin.php?page=ypm-translations')) . '">' . esc_html__('Continue translations', 'yablonsky-multilingual') . '</a></section>';
    echo '</div></div>';
  }

  /** @param array<string,mixed>|null $coverage */
  private static function render_dashboard_action(string $icon, string $title, string $copy, string $slug, string $button, string $state = 'neutral', string $status = '', ?array $coverage = null): void {
    $status = $status !== '' ? $status : __('Available', 'yablonsky-multilingual');
    echo '<article class="ypm-action-card ypm-action-card--' . esc_attr($state) . '"><span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span><div class="ypm-action-card__body"><div class="ypm-action-card__heading"><h3>' . esc_html($title) . '</h3>' . wp_kses_post(self::status_badge($state, $status)) . '</div><p>' . esc_html($copy) . '</p>';
    if (is_array($coverage) && (int) ($coverage['total'] ?? 0) > 0) {
      $percent = max(0, min(100, (int) ($coverage['percent'] ?? 0)));
      echo '<div class="ymulti-action-progress"><span class="ymulti-coverage-rail"><i style="width:' . esc_attr((string) $percent) . '%"></i></span><small>' . esc_html(sprintf(
        /* translators: 1: completed locale records, 2: required locale records. */
        __('%1$d / %2$d locale records', 'yablonsky-multilingual'),
        (int) ($coverage['done'] ?? 0),
        (int) ($coverage['total'] ?? 0)
      )) . '</small></div>';
    }
    echo '</div><a class="button" href="' . esc_url(admin_url('admin.php?page=' . $slug)) . '">' . esc_html($button) . '</a></article>';
  }


private static function render_dashboard_teaser_action(string $icon, string $title, string $copy, string $badge, string $button, string $href): void {
  echo '<article class="ypm-action-card ypm-action-card--teaser"><span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span><div class="ypm-action-card__top"><div><h3>' . esc_html($title) . '</h3><p>' . esc_html($copy) . '</p></div><span class="ypm-action-card__badge">' . esc_html($badge) . '</span></div><a class="button" href="' . esc_url($href) . '">' . esc_html($button) . '</a></article>';
}

/** @return array<int,array<string,mixed>> */
private static function dashboard_release_train(): array {
  return [
    [
      'version' => '1.16.0',
      'title' => __('Dashboard preview tile', 'yablonsky-multilingual'),
      'copy' => __('The spare workspace slot becomes a calm teaser with a clear next action.', 'yablonsky-multilingual'),
      'done' => version_compare(YPM_VERSION, '1.16.0', '>='),
    ],
    [
      'version' => '1.17.0',
      'title' => __('Broader AI model discovery', 'yablonsky-multilingual'),
      'copy' => __('Workspace, plan and route-specific model catalogs are projected more completely.', 'yablonsky-multilingual'),
      'done' => version_compare(YPM_VERSION, '1.17.0', '>='),
    ],
    [
      'version' => '1.18.0',
      'title' => __('Manual route extras', 'yablonsky-multilingual'),
      'copy' => __('Exact text, vision and image model IDs can be added locally when needed.', 'yablonsky-multilingual'),
      'done' => version_compare(YPM_VERSION, '1.18.0', '>='),
    ],
    [
      'version' => '1.19.0',
      'title' => __('Catalog diagnostics', 'yablonsky-multilingual'),
      'copy' => __('Admins can see discovered counts, refresh evidence and route-specific model chips.', 'yablonsky-multilingual'),
      'done' => version_compare(YPM_VERSION, '1.19.0', '>='),
    ],
    [
      'version' => '1.20.0',
      'title' => __('Coverage guidance', 'yablonsky-multilingual'),
      'copy' => __('Narrow catalogs now surface clear next actions instead of quiet confusion.', 'yablonsky-multilingual'),
      'done' => version_compare(YPM_VERSION, '1.20.0', '>='),
    ],
  ];
}

/** @param array<int,array<string,mixed>> $items */
private static function render_dashboard_release_train(array $items): void {
  $total = count($items);
  $done = 0;
  foreach ($items as $item) if (!empty($item['done'])) $done++;
  $remaining = max(0, $total - $done);
  $percent = $total > 0 ? (int) floor(($done / $total) * 100) : 0;

  /* translators: 1: delivered release-train milestones, 2: total release-train milestones. */
  $delivered_label = sprintf(__(' %1$d/%2$d delivered ', 'yablonsky-multilingual'), $done, $total);
  /* translators: %d: percentage of the current release train completed. */
  $complete_label = sprintf(__('%d%% complete', 'yablonsky-multilingual'), $percent);
  /* translators: 1: delivered release-train milestones, 2: remaining release-train milestones. */
  $remaining_label = sprintf(__('%1$d delivered · %2$d remaining', 'yablonsky-multilingual'), $done, $remaining);

  echo '<div class="ypm-release-train" id="ypm-release-train"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Completed release train', 'yablonsky-multilingual') . '</span><h3>' . esc_html__('Historical delivery milestones', 'yablonsky-multilingual') . '</h3></div>' . wp_kses_post(self::status_badge($remaining === 0 && $total > 0 ? 'pass' : 'attention', $delivered_label)) . '</div>';
  echo '<p class="ypm-card__copy">' . esc_html__('This bar tracks the listed 1.16.0–1.20.0 delivery milestones. Overall product readiness, production acceptance and competitive parity use separate evidence.', 'yablonsky-multilingual') . '</p>';
  echo '<div class="ypm-progress"><div class="ypm-progress__meta"><strong>' . esc_html($complete_label) . '</strong><span>' . esc_html($remaining_label) . '</span></div><div class="ypm-progress__rail"><div class="ypm-progress__fill" style="width:' . esc_attr((string) $percent) . '%"></div></div></div>';
  echo '<div class="ypm-release-train__list">';
  foreach ($items as $item) {
    $title = sanitize_text_field((string) ($item['title'] ?? ''));
    $copy = sanitize_text_field((string) ($item['copy'] ?? ''));
    $version = sanitize_text_field((string) ($item['version'] ?? ''));
    $is_done = !empty($item['done']);
    echo '<article class="ypm-release-train__item"><span>' . esc_html($version) . '</span><strong>' . esc_html($title) . '</strong><small>' . esc_html($copy) . '</small>' . wp_kses_post(self::status_badge($is_done ? 'pass' : 'neutral', $is_done ? __('Delivered', 'yablonsky-multilingual') : __('Planned', 'yablonsky-multilingual'))) . '</article>';
  }
  echo '</div></div>';
}

  public static function page_languages(): void {
    if (!current_user_can('manage_options')) return;
    Languages::ensure_options();

    $catalog = Languages::selection_catalog();
    $locale_quality = Languages::quality_report();
    $active = Languages::active_codes();
    $public = Languages::public_codes();
    $staged = Languages::staged_codes();
    $default = Languages::default_code();
    $custom = get_option('ypm_custom_languages');
    if (!is_array($custom)) $custom = [];
    $action_url = admin_url('admin-post.php');
    $safe_mode = SafeMode::enabled();
    $hide_default = (bool) get_option('ypm_hide_default_slug', false);
    $routing_settings = class_exists('Yablonsky\\Multilingual\\RoutingModes') ? RoutingModes::settings() : [];
    $browser_detection = !empty($routing_settings['browser_detection']);
    $domain_mode = !empty($routing_settings['domain_mode']);
    $mapped_domains = 0;
    if ($domain_mode && isset($routing_settings['domains']) && is_array($routing_settings['domains'])) {
      foreach ($routing_settings['domains'] as $mapped_domain) if (trim((string) $mapped_domain) !== '') $mapped_domains++;
    }

    echo '<div class="wrap ypm-admin">';
    self::render_shell_header(
      __('Languages', 'yablonsky-multilingual'),
      __('Your active locales, routing policy and language catalog — compact by default, full controls when you need them.', 'yablonsky-multilingual'),
      $safe_mode ? 'attention' : 'pass',
      $safe_mode ? __('Safe Mode', 'yablonsky-multilingual') : __('Live routing', 'yablonsky-multilingual')
    );

    $order_saved = filter_input(INPUT_GET, 'order_saved', FILTER_VALIDATE_BOOLEAN) === true;
    if ($order_saved) {
      echo '<div class="ypm-callout ypm-callout--pass"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><div><strong>' . esc_html__('Language order saved', 'yablonsky-multilingual') . '</strong><p>' . esc_html__('The enabled-locale order now drives YMulti frontend switchers and dropdowns.', 'yablonsky-multilingual') . '</p></div></div>';
    }

    echo '<div class="ypm-metrics ypm-metrics--5">';
    self::render_metric(__('Active locales', 'yablonsky-multilingual'), (string) count($active), __('Available for translation work', 'yablonsky-multilingual'), 'neutral');
    self::render_metric(__('Public locales', 'yablonsky-multilingual'), (string) count($public), $staged ? sprintf(
      /* translators: %d: number of active locales still staged. */
      __('%d staged', 'yablonsky-multilingual'),
      count($staged)
    ) : __('All active locales are public', 'yablonsky-multilingual'), $staged ? 'attention' : 'pass');
    self::render_metric(__('Default locale', 'yablonsky-multilingual'), Languages::display_code($default), Languages::display_name($default), 'neutral');
    self::render_metric(__('Default URL', 'yablonsky-multilingual'), $hide_default ? '/' : '/' . Languages::code_to_slug($default) . '/', $hide_default ? __('Root canonical', 'yablonsky-multilingual') : __('Prefixed', 'yablonsky-multilingual'), 'neutral');
    self::render_metric(__('Safe Mode', 'yablonsky-multilingual'), $safe_mode ? __('ON', 'yablonsky-multilingual') : __('OFF', 'yablonsky-multilingual'), $safe_mode ? __('Frontend multilingual logic paused', 'yablonsky-multilingual') : __('Frontend multilingual logic active', 'yablonsky-multilingual'), $safe_mode ? 'attention' : 'pass');
    echo '</div>';

    echo '<div class="ypm-dashboard-grid ypm-dashboard-grid--languages">';
    echo '<section class="ypm-card ypm-card--primary ypm-card--active-locales">';
    echo '<div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Site languages', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Enabled locales', 'yablonsky-multilingual') . '</h2></div><button type="button" class="button" data-ypm-open-details="ypm-language-manager">' . esc_html__('Manage languages', 'yablonsky-multilingual') . '</button></div>';
    echo '<p class="ypm-card__copy">' . esc_html__('This ordered set is used by the frontend language switcher and dropdowns. Drag to reorder; removing a locale disables routing but keeps its translation data.', 'yablonsky-multilingual') . '</p>';
    echo '<form method="post" action="' . esc_url($action_url) . '" class="ypm-active-locales-form" id="ypm-active-locales-form" data-clean-label="' . esc_attr__('Drag order = frontend switcher order.', 'yablonsky-multilingual') . '" data-dirty-label="' . esc_attr__('Unsaved changes — save to update the frontend switcher.', 'yablonsky-multilingual') . '">';
    wp_nonce_field('ypm_update_active_locales');
    echo '<input type="hidden" name="action" value="ypm_update_active_locales" />';
    echo '<div class="ypm-active-locales-frame" id="ypm-active-locales-frame">';
    echo '<div class="ypm-locale-chips ypm-locale-chips--sortable" id="ypm-enabled-locales" data-default-code="' . esc_attr($default) . '">';
    foreach ($active as $code) {
      $is_default = $code === $default;
      echo '<div class="ypm-locale-chip' . ($is_default ? ' is-default' : '') . '" data-locale-code="' . esc_attr($code) . '" draggable="true">';
      echo '<button type="button" class="ypm-locale-drag" aria-label="' . esc_attr__('Drag to reorder locale', 'yablonsky-multilingual') . '" title="' . esc_attr__('Drag to reorder', 'yablonsky-multilingual') . '"><span class="dashicons dashicons-move" aria-hidden="true"></span></button>';
      echo '<strong>' . esc_html(strtoupper(Languages::family_code($code))) . '</strong><span>' . esc_html(Languages::display_name($code)) . '</span><code>' . esc_html(Languages::display_code($code)) . '</code>';
      echo '<label class="ypm-locale-public" title="' . esc_attr__('Public locales', 'yablonsky-multilingual') . '"><input type="checkbox" name="public_codes[]" value="' . esc_attr($code) . '"' . checked(in_array($code, $public, true), true, false) . ($is_default ? ' disabled' : '') . '><span class="screen-reader-text">' . esc_html__('Public locales', 'yablonsky-multilingual') . '</span></label>';
      if ($is_default) {
        echo '<input type="hidden" name="public_codes[]" value="' . esc_attr($code) . '"><em>' . esc_html__('Default', 'yablonsky-multilingual') . '</em>';
      } else {
        /* translators: %s: locale display name. */
        $remove_label = sprintf(__('Remove %s from enabled locales', 'yablonsky-multilingual'), Languages::display_name($code));
        echo '<button type="button" class="ypm-locale-remove" data-ypm-remove-locale aria-label="' . esc_attr($remove_label) . '" title="' . esc_attr__('Remove locale from active set', 'yablonsky-multilingual') . '"><span aria-hidden="true">×</span></button>';
      }
      echo '<input type="hidden" name="active_codes[]" value="' . esc_attr($code) . '" /></div>';
    }
    echo '</div>';
    echo '<div class="ypm-active-locales-fade" aria-hidden="true"></div>';
    echo '</div>';
    echo '<div class="ypm-active-locales-controls"><button type="button" class="button button-link ypm-active-locales-toggle" id="ypm-active-locales-toggle" hidden data-show-label="' . esc_attr__('Show all', 'yablonsky-multilingual') . '" data-hide-label="' . esc_attr__('Collapse', 'yablonsky-multilingual') . '">' . esc_html__('Show all', 'yablonsky-multilingual') . '</button><div class="ypm-active-locales-actions"><button type="button" class="button button-link" id="ypm-sort-locales-az">' . esc_html__('Sort A–Z', 'yablonsky-multilingual') . '</button><span class="ypm-order-hint" id="ypm-active-locales-state">' . esc_html__('Drag order = frontend switcher order.', 'yablonsky-multilingual') . '</span><button type="submit" class="button button-primary" id="ypm-save-active-locales">' . esc_html__('Save language settings', 'yablonsky-multilingual') . '</button></div></div>';
    echo '</form></section>';

    echo '<section class="ypm-card ypm-card--routing-mode">';
    echo '<div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Safety', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Routing mode', 'yablonsky-multilingual') . '</h2></div>' . wp_kses_post(self::status_badge($safe_mode ? 'attention' : 'pass', $safe_mode ? __('SAFE', 'yablonsky-multilingual') : __('LIVE', 'yablonsky-multilingual'))) . '</div>';
    echo '<p class="ypm-card__copy">' . esc_html($safe_mode ? __('Safe Mode is suppressing frontend routing, redirects, hreflang and query filtering while preserving admin translation data.', 'yablonsky-multilingual') : __('YMulti frontend routing is active. Only enabled locales participate in URLs, switchers and language filtering.', 'yablonsky-multilingual')) . '</p>';
    echo '<div class="ypm-check-list ypm-routing-mode-summary">';
    echo '<div class="ypm-check-row"><span class="ypm-status-dot ypm-status-dot--' . esc_attr($safe_mode ? 'attention' : 'pass') . '"></span><div><strong>' . esc_html__('Frontend routing', 'yablonsky-multilingual') . '</strong><small>' . esc_html($safe_mode ? __('Paused by Safe Mode', 'yablonsky-multilingual') : __('URLs, switchers and language filtering are active', 'yablonsky-multilingual')) . '</small></div>' . wp_kses_post(self::status_badge($safe_mode ? 'attention' : 'pass', $safe_mode ? __('Paused', 'yablonsky-multilingual') : __('Active', 'yablonsky-multilingual'))) . '</div>';
    echo '<div class="ypm-check-row"><span class="ypm-status-dot ypm-status-dot--pass"></span><div><strong>' . esc_html__('Public languages', 'yablonsky-multilingual') . '</strong><small>' . esc_html(sprintf(
      /* translators: 1: number of public locales, 2: number of active locales. */
      __('%1$d of %2$d enabled locales are visible to visitors', 'yablonsky-multilingual'), count($public), count($active)
    )) . '</small></div>' . wp_kses_post(self::status_badge($staged ? 'attention' : 'pass', $staged ? __('Staged', 'yablonsky-multilingual') : __('All public', 'yablonsky-multilingual'))) . '</div>';
    echo '<div class="ypm-check-row"><span class="ypm-status-dot ypm-status-dot--neutral"></span><div><strong>' . esc_html__('Browser language', 'yablonsky-multilingual') . '</strong><small>' . esc_html($browser_detection ? __('First-visit home detection is enabled', 'yablonsky-multilingual') : __('Optional first-visit detection is off', 'yablonsky-multilingual')) . '</small></div>' . wp_kses_post(self::status_badge($browser_detection ? 'pass' : 'neutral', $browser_detection ? __('On', 'yablonsky-multilingual') : __('Off', 'yablonsky-multilingual'))) . '</div>';
    $domain_detail = $domain_mode ? sprintf(
      /* translators: %d: number of configured language-domain mappings. */
      __('%d language-domain mappings configured', 'yablonsky-multilingual'), $mapped_domains
    ) : __('Path-based routing remains in use', 'yablonsky-multilingual');
    echo '<div class="ypm-check-row"><span class="ypm-status-dot ypm-status-dot--neutral"></span><div><strong>' . esc_html__('Separate domains', 'yablonsky-multilingual') . '</strong><small>' . esc_html($domain_detail) . '</small></div>' . wp_kses_post(self::status_badge($domain_mode ? 'pass' : 'neutral', $domain_mode ? __('On', 'yablonsky-multilingual') : __('Off', 'yablonsky-multilingual'))) . '</div>';
    echo '</div><div class="ypm-routing-mode-actions">';
    echo '<form method="post" action="' . esc_url($action_url) . '" class="ypm-inline-action">';
    wp_nonce_field('ypm_toggle_safe_mode');
    echo '<input type="hidden" name="action" value="ypm_toggle_safe_mode" /><input type="hidden" name="enabled" value="' . ($safe_mode ? '0' : '1') . '" />';
    echo '<button class="button" type="submit">' . esc_html($safe_mode ? __('Return to Live routing', 'yablonsky-multilingual') : __('Enable Safe Mode', 'yablonsky-multilingual')) . '</button></form>';
    echo '<a class="button button-link" href="' . esc_url(admin_url('admin.php?page=ypm-routing')) . '">' . esc_html__('Open routing settings', 'yablonsky-multilingual') . '</a>';
    echo '</div></section></div>';

    $site_locale = (string) get_locale();
    $suggested = Languages::site_locale_to_content_locale($site_locale);
    if (Languages::is_legacy_code($default) && $suggested !== $default) {
      echo '<div class="ypm-callout ypm-callout--attention"><span class="dashicons dashicons-warning" aria-hidden="true"></span><div><strong>' . esc_html__('Legacy locale identity detected', 'yablonsky-multilingual') . '</strong><p>';
      /* translators: 1: current YPM locale code, 2: WordPress site locale, 3: suggested normalized YPM locale code. */
      echo esc_html(sprintf(__('Current YMulti default: %1$s. WordPress Site Language: %2$s. Suggested normalized locale: %3$s.', 'yablonsky-multilingual'), Languages::display_code($default), $site_locale, Languages::display_code($suggested)));
      echo ' ' . esc_html__('YMulti preserves current assignments until you approve normalization after reviewing URL and hreflang impact.', 'yablonsky-multilingual') . '</p></div></div>';
    }

    echo '<details class="ypm-disclosure ypm-disclosure--wide" id="ypm-language-manager">';
    echo '<summary><span><strong>' . esc_html__('Manage language catalog', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Enable locales, choose default routing and search the full catalog', 'yablonsky-multilingual') . '</small></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary>';
    echo '<div class="ypm-disclosure__body">';

    echo '<form method="post" action="' . esc_url($action_url) . '" class="ypm-language-form">';
    wp_nonce_field('ypm_save_languages');
    echo '<input type="hidden" name="action" value="ypm_save_languages" />';
    echo '<div class="ypm-form-toolbar"><label><span>' . esc_html__('Default content locale', 'yablonsky-multilingual') . '</span><select name="default_code">';
    foreach ($catalog as $code => $row) {
      $label = ($row['name'] ?? $code) . ' — ' . Languages::display_code((string) $code);
      if (!empty($row['legacy'])) $label .= ' · ' . __('legacy', 'yablonsky-multilingual');
      echo '<option value="' . esc_attr((string) $code) . '"' . selected($default, $code, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label><label class="ypm-check-control"><input type="checkbox" name="hide_default_slug" value="1" ' . checked($hide_default, true, false) . ' /><span><strong>' . esc_html__('Hide default locale slug', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Keep the default locale at the site root.', 'yablonsky-multilingual') . '</small></span></label></div>';

    echo '<div class="ypm-manager-bar"><label for="ypm-language-search"><span>' . esc_html__('Find a language or locale', 'yablonsky-multilingual') . '</span><input id="ypm-language-search" type="search" placeholder="' . esc_attr__('Search English/native name, family, locale or WordPress locale…', 'yablonsky-multilingual') . '" /></label><span class="ypm-manager-count">' . esc_html((string) count($catalog)) . ' ' . esc_html__('available', 'yablonsky-multilingual') . '</span></div>';
    echo '<div class="ymulti-language-catalog-summary">';
    echo '<span><strong>' . esc_html((string) ($locale_quality['covered_families'] ?? 0)) . '/' . esc_html((string) ($locale_quality['required_families'] ?? 0)) . '</strong> ' . esc_html__('core families', 'yablonsky-multilingual') . '</span>';
    echo '<span><strong>' . esc_html((string) ($locale_quality['catalog_families'] ?? 0)) . '</strong> ' . esc_html__('families total', 'yablonsky-multilingual') . '</span>';
    echo '<span><strong>' . esc_html((string) ($locale_quality['catalog_locales'] ?? 0)) . '</strong> ' . esc_html__('locale entries', 'yablonsky-multilingual') . '</span>';
    echo '<span><strong>' . esc_html((string) ($locale_quality['legacy_aliases'] ?? 0)) . '</strong> ' . esc_html__('legacy aliases', 'yablonsky-multilingual') . '</span>';
    echo wp_kses_post(self::status_badge(!empty($locale_quality['complete']) ? 'pass' : 'attention', !empty($locale_quality['complete']) ? __('Catalog verified', 'yablonsky-multilingual') : __('Catalog review', 'yablonsky-multilingual')));
    echo '</div>';

    echo '<div class="ypm-language-table-wrap"><table id="ypm-language-table" class="widefat striped ypm-data-table"><thead><tr><th>' . esc_html__('On', 'yablonsky-multilingual') . '</th><th>' . esc_html__('Language', 'yablonsky-multilingual') . '</th><th>' . esc_html__('Locale', 'yablonsky-multilingual') . '</th><th>' . esc_html__('WP locale', 'yablonsky-multilingual') . '</th><th>' . esc_html__('URL', 'yablonsky-multilingual') . '</th><th>' . esc_html__('State', 'yablonsky-multilingual') . '</th></tr></thead><tbody>';
    foreach ($catalog as $code => $row) {
      $is_active = in_array($code, $active, true);
      $name = (string) ($row['english_name'] ?? $row['name'] ?? $code);
      $native = (string) ($row['native_name'] ?? '');
      $family = (string) ($row['family'] ?? Languages::family_code((string) $code));
      $wp_locale = (string) ($row['wp_locale'] ?? '');
      $slug = Languages::code_to_slug((string) $code);
      $state = !empty($row['legacy']) ? __('Legacy', 'yablonsky-multilingual') : __('Current', 'yablonsky-multilingual');
      $search = strtolower(implode(' ', [(string) $code, $name, $native, $family, $wp_locale, $slug]));
      echo '<tr data-ypm-language-search="' . esc_attr($search) . '"><td><input type="checkbox" name="active_codes[]" value="' . esc_attr((string) $code) . '"' . checked($is_active, true, false) . ' /></td><td><strong>' . esc_html($name) . '</strong><small>' . esc_html($native !== '' ? $native : strtoupper($family)) . '</small></td><td><code>' . esc_html(Languages::display_code((string) $code)) . '</code></td><td><code>' . esc_html($wp_locale !== '' ? $wp_locale : '—') . '</code></td><td><code>/' . esc_html($slug) . '/</code></td><td>' . wp_kses_post(self::status_badge(!empty($row['legacy']) ? 'attention' : 'neutral', $state)) . '</td></tr>';
    }
    echo '</tbody></table>';
    self::render_client_pager('ypm-language-pager', 25, [25, 50, 100]);
    echo '<p id="ypm-language-search-empty" class="ypm-empty-state" style="display:none">' . esc_html__('0 languages or locales match this search.', 'yablonsky-multilingual') . '</p></div>';
    echo '<div class="ypm-sticky-actions"><span>' . esc_html__('Changes affect routing only after Save.', 'yablonsky-multilingual') . '</span><button class="button button-primary" type="submit">' . esc_html__('Save language settings', 'yablonsky-multilingual') . '</button></div></form>';

    echo '<div class="ypm-secondary-actions"><strong>' . esc_html__('Presets', 'yablonsky-multilingual') . '</strong><form method="post" action="' . esc_url($action_url) . '">';
    wp_nonce_field('ypm_quick_action');
    echo '<input type="hidden" name="action" value="ypm_quick_action" /><button class="button" name="qa" value="global_top" type="submit">' . esc_html__('Global Top', 'yablonsky-multilingual') . '</button><button class="button" name="qa" value="eu_commerce" type="submit">' . esc_html__('EU Commerce', 'yablonsky-multilingual') . '</button><button class="button" name="qa" value="core_36" type="submit" data-ymulti-language-preset-confirm="' . esc_attr__('Enable one representative locale for all 36 core language families? This expands the active translation set. Review Publication before exposing new languages to visitors.', 'yablonsky-multilingual') . '">' . esc_html__('Core 36', 'yablonsky-multilingual') . '</button><button class="button" name="qa" value="all_families" type="submit" data-ymulti-language-preset-confirm="' . esc_attr__('Enable all 38 supported language families? This expands the active translation set. Review Publication before exposing new languages to visitors.', 'yablonsky-multilingual') . '">' . esc_html__('All 38 families', 'yablonsky-multilingual') . '</button><button class="button" name="qa" value="disable_all" type="submit">' . esc_html__('Default only', 'yablonsky-multilingual') . '</button></form></div>';
    echo '</div></details>';

    echo '<details class="ypm-disclosure ypm-disclosure--wide"><summary><span><strong>' . esc_html__('Custom locales', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Add a locale beyond the built-in catalog', 'yablonsky-multilingual') . '</small></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary><div class="ypm-disclosure__body">';
    echo '<form method="post" action="' . esc_url($action_url) . '" class="ypm-custom-locale-form">';
    wp_nonce_field('ypm_add_custom_language');
    echo '<input type="hidden" name="action" value="ypm_add_custom_language" /><label><span>' . esc_html__('Name', 'yablonsky-multilingual') . '</span><input type="text" name="name" placeholder="' . esc_attr__('French (Switzerland)', 'yablonsky-multilingual') . '"></label><label><span>' . esc_html__('Code', 'yablonsky-multilingual') . '</span><input type="text" name="code" placeholder="fr-CH"></label><label><span>' . esc_html__('Slug', 'yablonsky-multilingual') . '</span><input type="text" name="slug" placeholder="fr-ch"></label><label class="ypm-check-control ypm-check-control--compact"><input type="checkbox" name="rtl" value="1"><span><strong>' . esc_html__('RTL', 'yablonsky-multilingual') . '</strong></span></label><button class="button" type="submit">' . esc_html__('Add locale', 'yablonsky-multilingual') . '</button></form>';
    if (!empty($custom)) {
      echo '<div class="ypm-custom-list" id="ypm-custom-locale-list">';
      foreach ($custom as $code => $row) {
        $name = isset($row['name']) ? (string) $row['name'] : (string) $code;
        $slug = isset($row['slug']) ? (string) $row['slug'] : Languages::normalize_slug((string) $code);
        echo '<span data-ypm-custom-locale><strong>' . esc_html($name) . '</strong><code>' . esc_html(Languages::display_code((string) $code)) . '</code><small>/' . esc_html($slug) . '/ · ' . esc_html(!empty($row['rtl']) ? 'RTL' : 'LTR') . '</small></span>';
      }
      echo '</div>';
      self::render_client_pager('ypm-custom-locale-pager', 20, [20, 40, 80]);
    }
    echo '</div></details>';

    echo '<details class="ypm-disclosure ypm-disclosure--wide"><summary><span><strong>' . esc_html__('Architecture boundary', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Ownership and AI/service boundaries', 'yablonsky-multilingual') . '</small></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary><div class="ypm-disclosure__body ypm-prose">';
    echo '<p>' . esc_html__('YMulti owns locale identity, translation relationships, localized URLs and routing. When Yablonsky SEO is active, Yablonsky SEO remains the single write authority for SEO metadata and final SEO output.', 'yablonsky-multilingual') . '</p><p>' . esc_html__('Local and self-hosted functionality remains free. Optional Yablonsky AI translation uses the connected account’s remote compute and credits; the API key authorizes that remote compute layer.', 'yablonsky-multilingual') . '</p></div></details>';
    echo '</div>';
  }

  public static function handle_update_public_locales(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ymulti_update_public_locales');
    $active = Languages::active_codes();
    $default = Languages::default_code();
    $raw = filter_input(INPUT_POST, 'public_codes', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
    $raw = is_array($raw) ? $raw : [];
    $public = [];
    foreach ($raw as $code) {
      if (!is_scalar($code)) continue;
      $code = sanitize_text_field((string) $code);
      if (in_array($code, $active, true) && !in_array($code, $public, true)) $public[] = $code;
    }
    if (!in_array($default, $public, true)) array_unshift($public, $default);
    update_option(Languages::OPT_PUBLIC, $public, false);
    Languages::flush_request_cache();
    if (class_exists('Yablonsky\Multilingual\Router')) Router::maybe_flush_rewrites(false);
    wp_safe_redirect(admin_url('admin.php?page=ypm-languages&publication_saved=1'));
    exit;
  }

  private static function sync_public_locales_after_active_change(array $active): void {
    $default = Languages::default_code();
    $public = array_values(array_intersect(Languages::public_codes(), $active));
    if (in_array($default, $active, true) && !in_array($default, $public, true)) array_unshift($public, $default);
    if (!$public && $active) $public[] = $active[0];
    update_option(Languages::OPT_PUBLIC, array_values(array_unique($public)), false);
    Languages::flush_request_cache();
  }

  public static function handle_quick_action(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_quick_action');

    $qa = isset($_POST['qa']) ? sanitize_text_field(wp_unslash($_POST['qa'])) : '';
    $default = Languages::default_code();

    if ($qa === 'global_top') $set = Languages::preset_global_top();
    elseif ($qa === 'eu_commerce') $set = Languages::preset_eu_commerce();
    elseif ($qa === 'core_36') $set = Languages::preset_core_36();
    elseif ($qa === 'all_families') $set = Languages::preset_all_families();
    elseif ($qa === 'disable_all') $set = [$default];
    else $set = Languages::active_codes();

    if (!in_array($default, $set, true)) $set[] = $default;
    $set = array_values(array_unique($set));
    update_option(Languages::OPT_ACTIVE, $set, false);
    Languages::flush_request_cache();
    self::sync_public_locales_after_active_change($set);

    // Flush only when rewrite signature changes.
    if (class_exists('Yablonsky\\Multilingual\\Router')) {
      Router::maybe_flush_rewrites(false);
    }
    Languages::register_taxonomy();

    wp_safe_redirect(admin_url('admin.php?page=ypm-languages'));
    exit;
  }

  /**
   * Update the ordered active-locale set from the compact dashboard.
   * Translation data is preserved when a locale is disabled.
   */
  public static function handle_update_active_locales(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_update_active_locales');

    $catalog = Languages::catalog();
    $default = Languages::default_code();
    $old_active = Languages::active_codes();
    $old_public = Languages::public_codes();

    $active_raw = filter_input(INPUT_POST, 'active_codes', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
    if (!is_array($active_raw)) $active_raw = [];

    $active = [];
    foreach ($active_raw as $active_code) {
      if (!is_scalar($active_code)) continue;
      $active_code = sanitize_text_field((string) $active_code);
      if ($active_code === '' || !isset($catalog[$active_code]) || in_array($active_code, $active, true)) continue;
      $active[] = $active_code;
    }

    if (!in_array($default, $active, true)) array_unshift($active, $default);
    if (!$active) $active = [$default];

    $old_set = $old_active;
    $new_set = $active;
    sort($old_set, SORT_STRING);
    sort($new_set, SORT_STRING);

    $public_raw = filter_input(INPUT_POST, 'public_codes', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
    $public_raw = is_array($public_raw) ? $public_raw : [];
    $public = [];
    foreach ($public_raw as $public_code) {
      if (!is_scalar($public_code)) continue;
      $public_code = sanitize_text_field((string) $public_code);
      if (in_array($public_code, $active, true) && !in_array($public_code, $public, true)) $public[] = $public_code;
    }
    if (!in_array($default, $public, true)) array_unshift($public, $default);

    $old_public_set = $old_public;
    $new_public_set = $public;
    sort($old_public_set, SORT_STRING);
    sort($new_public_set, SORT_STRING);

    update_option(Languages::OPT_ACTIVE, $active, false);
    update_option(Languages::OPT_PUBLIC, $public, false);
    Languages::flush_request_cache();

    if (class_exists('Yablonsky\Multilingual\Router')) {
      if ($old_set !== $new_set || $old_public_set !== $new_public_set) {
        Router::maybe_flush_rewrites(false);
      } else {
        // Reordering affects switcher presentation only, not route membership.
        update_option(Router::OPT_REWRITE_SIG, Router::compute_rewrite_signature(), false);
      }
    }

    Languages::register_taxonomy();

    wp_safe_redirect(admin_url('admin.php?page=ypm-languages&order_saved=1'));
    exit;
  }

  public static function handle_save_languages(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_save_languages');

    $default = isset($_POST['default_code'])
      ? sanitize_text_field(wp_unslash($_POST['default_code']))
      : Languages::default_code();

    $active_raw = filter_input(INPUT_POST, 'active_codes', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
    if (!is_array($active_raw)) $active_raw = [];
    $submitted = [];
    foreach ($active_raw as $active_code) {
      if (!is_scalar($active_code)) continue;
      $active_code = sanitize_text_field((string) $active_code);
      if ($active_code !== '' && !in_array($active_code, $submitted, true)) $submitted[] = $active_code;
    }

    // Keep the operator-defined switcher order for locales that remain enabled.
    $active = [];
    foreach (Languages::active_codes() as $active_code) {
      if (in_array($active_code, $submitted, true)) $active[] = $active_code;
    }
    foreach ($submitted as $active_code) {
      if (!in_array($active_code, $active, true)) $active[] = $active_code;
    }

    $hide_default_slug = filter_input(INPUT_POST, 'hide_default_slug', FILTER_VALIDATE_BOOLEAN) === true;

    if ($default === '' || !isset(Languages::catalog()[$default])) $default = Languages::default_code();
    if (!in_array($default, $active, true)) array_unshift($active, $default);

    update_option(Languages::OPT_DEFAULT, $default, false);
    update_option(Languages::OPT_ACTIVE, $active, false);
    Languages::flush_request_cache();
    self::sync_public_locales_after_active_change($active);
    update_option('ypm_hide_default_slug', $hide_default_slug ? 1 : 0, false);

    // Flush only when rewrite signature changes.
    if (class_exists('Yablonsky\\Multilingual\\Router')) {
      Router::maybe_flush_rewrites(false);
    }
    Languages::register_taxonomy();

    wp_safe_redirect(admin_url('admin.php?page=ypm-languages&saved=1'));
    exit;
  }

  public static function handle_add_custom_language(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_add_custom_language');

    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    $code = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';
    $slug = isset($_POST['slug']) ? sanitize_text_field(wp_unslash($_POST['slug'])) : '';
    $rtl  = filter_input(INPUT_POST, 'rtl', FILTER_VALIDATE_BOOLEAN) === true;

    $code = trim($code);
    if ($code === '') {
      wp_safe_redirect(admin_url('admin.php?page=ypm-languages&err=missing_code'));
      exit;
    }

    if ($name === '') $name = $code;
    if ($slug === '') $slug = Languages::normalize_slug($code);

    $custom = get_option('ypm_custom_languages');
    if (!is_array($custom)) $custom = [];

    $custom[$code] = ['name' => $name, 'slug' => $slug, 'rtl' => $rtl ? 1 : 0];
    update_option('ypm_custom_languages', $custom, false);
    Languages::flush_request_cache();

    // Ensure term exists if user activates it later
    Languages::register_taxonomy();

    wp_safe_redirect(admin_url('admin.php?page=ypm-languages&added=1'));
    exit;
  }

  public static function maybe_create_translation(): void {
    if (!current_user_can('edit_posts')) return;

    $action = isset($_GET['ypm_action']) ? sanitize_text_field(wp_unslash($_GET['ypm_action'])) : '';
    if ($action !== 'create_translation') return;

    check_admin_referer('ypm_create_translation');

    $source_id = isset($_GET['source']) ? absint(wp_unslash($_GET['source'])) : 0;
    $target_lang = isset($_GET['lang']) ? sanitize_text_field(wp_unslash($_GET['lang'])) : '';

    if (!$source_id || $target_lang === '' || !Languages::is_active($target_lang)) {
      wp_die(esc_html__('Invalid translation request.', 'yablonsky-multilingual'));
    }

    $source = get_post($source_id);
    if (!$source) wp_die(esc_html__('Source post is unavailable. Return to the content list and choose an existing source post.', 'yablonsky-multilingual'));
    if (!current_user_can('edit_post', $source_id)) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));

    $existing_target = class_exists('Yablonsky\Multilingual\EffectiveRelations', false) ? EffectiveRelations::existing_target($source_id, $target_lang) : 0;
    if ($existing_target > 0) {
      $edit_url = get_edit_post_link($existing_target, '');
      if (is_string($edit_url) && $edit_url !== '') {
        wp_safe_redirect($edit_url);
        exit;
      }
      wp_die(esc_html__('A translation already exists for the target locale.', 'yablonsky-multilingual'));
    }

    $new_id = wp_insert_post([
      'post_type' => $source->post_type,
      'post_status' => 'draft',
      'post_title' => $source->post_title,
      'post_content' => $source->post_content,
      'post_excerpt' => $source->post_excerpt,
      'post_author' => get_current_user_id(),
    ], true);

    if (is_wp_error($new_id)) {
      /* translators: %s: WordPress error message. */
      wp_die(esc_html(sprintf(__('Translation creation returned an error: %s. Review the source item and try again.', 'yablonsky-multilingual'), $new_id->get_error_message())));
    }

    $group = Translations::ensure_group_id((int)$source_id);
    Translations::set_group_id((int) $new_id, $group);

    $thumb = get_post_thumbnail_id($source_id);
    if ($thumb) set_post_thumbnail((int)$new_id, $thumb);

    Translations::set_post_language((int)$new_id, $target_lang);
    do_action('yablonsky_multilingual_translation_draft_initialized', (int) $source_id, (int) $new_id, 'manual');

    if ($source->post_type === 'product' && class_exists('Yablonsky\\Multilingual\\Compat\\Woo')) {
      Compat\Woo::initialize_translation_draft((int) $source_id, (int) $new_id);
    }

    wp_safe_redirect(get_edit_post_link((int)$new_id, ''));
    exit;
  }


  public static function handle_toggle_safe_mode(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_toggle_safe_mode');

    $enabled = isset($_POST['enabled']) ? absint(wp_unslash($_POST['enabled'])) : 0;
    SafeMode::set_enabled($enabled === 1);

    // Flush rewrites when leaving Safe Mode (entering Full Mode), but only if needed.
    if (!SafeMode::enabled()) {
      if (class_exists('Yablonsky\\Multilingual\\Router')) {
        Router::maybe_flush_rewrites(true);
      } else {
        flush_rewrite_rules();
      }
      Languages::register_taxonomy();
    }

    wp_safe_redirect(admin_url('admin.php?page=ypm-languages'));
    exit;
  }

  /** Technical runtime-owner compatibility belongs to System, never a global admin notice. */
  private static function render_routing_compatibility_panel(): void {
    if (!class_exists('Yablonsky\\Multilingual\\RuntimeAuthority')) return;
    $status = RuntimeAuthority::status();
    if (($status['mode'] ?? '') !== RuntimeAuthority::MODE_BRIDGE) return;
    $providers = isset($status['incumbents']) && is_array($status['incumbents']) ? $status['incumbents'] : [];
    if (!$providers) return;
    $labels = array_map(static function (string $provider): string {
      if ($provider === 'wpml') return 'WPML';
      if ($provider === 'polylang') return 'Polylang';
      return strtoupper($provider);
    }, array_map('sanitize_key', $providers));
    $list = implode(', ', $labels);
    echo '<section class="ypm-card ypm-card--standalone ymulti-routing-compat" aria-label="' . esc_attr__('Compatibility', 'yablonsky-multilingual') . '">';
    echo '<div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Routing', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Compatibility', 'yablonsky-multilingual') . '</h2>';
    /* translators: %s: incumbent multilingual provider name, for example WPML. */
    echo '<p class="ypm-card__copy"><strong>' . esc_html(sprintf(__('%s routing detected · YMulti workspace active', 'yablonsky-multilingual'), $list)) . '</strong> ';
    echo esc_html__('YMulti keeps the existing public language routing in place while translation, migration and review work remain available.', 'yablonsky-multilingual') . '</p></div>';
    echo wp_kses_post(self::status_badge('neutral', __('Bridge mode', 'yablonsky-multilingual'))) . '</div>';
    echo '</section>';
  }

  /** A single high-visibility onboarding step on Dashboard, adaptive to the live AI connection state. */
  private static function render_dashboard_ai_onboarding(): void {
    if (!class_exists('Yablonsky\\Multilingual\\AITranslation')) return;
    $setup = AITranslation::text_setup_status();
    $ready = !empty($setup['ready']);
    $disconnected = ($setup['state'] ?? '') === 'disconnected';
    $href = (string) ($setup['url'] ?? admin_url('admin.php?page=ypm-ai'));
    if (!$ready && ($setup['state'] ?? '') === 'canary') $href = admin_url('admin.php?page=ypm-system-status#ypm-ai-canary');
    if (!$ready && ($setup['state'] ?? '') === 'permission') $href = admin_url('admin.php?page=ypm-ai');
    $label = sanitize_text_field((string) ($setup['label'] ?? __('Connect Yablonsky AI', 'yablonsky-multilingual')));
    $title = $ready
      ? __('Automatic translation is ready', 'yablonsky-multilingual')
      : __('Translate your website automatically with AI', 'yablonsky-multilingual');
    $description = $ready
      ? __('Create and review AI translation drafts in your selected languages.', 'yablonsky-multilingual')
      : sanitize_text_field((string) ($setup['message'] ?? ''));
    $button = $ready ? __('Translation Workspace', 'yablonsky-multilingual') : $label;
    $target = $ready ? admin_url('admin.php?page=ypm-translations') : $href;

    echo '<section class="ymulti-ai-onboarding' . ($ready ? ' ymulti-ai-onboarding--ready' : ($disconnected ? ' ymulti-ai-onboarding--disconnected' : ' ymulti-ai-onboarding--setup')) . '" aria-label="' . esc_attr__('Yablonsky AI', 'yablonsky-multilingual') . '">';
    echo '<div class="ymulti-ai-onboarding__icon"><span class="dashicons dashicons-translation" aria-hidden="true"></span></div>';
    echo '<div class="ymulti-ai-onboarding__content"><h2>' . esc_html($title) . '</h2><p>' . esc_html($description) . '</p>';
    if ($disconnected) echo '<small>' . esc_html__('Optional · Local translation tools stay available', 'yablonsky-multilingual') . '</small>';
    echo '</div><div class="ymulti-ai-onboarding__actions">';
    echo '<a class="button button-primary" href="' . esc_url($target) . '">' . esc_html($button) . '</a>';
    if ($ready) echo '<a class="ymulti-ai-onboarding__secondary" href="' . esc_url(admin_url('admin.php?page=ypm-ai')) . '">' . esc_html__('Yablonsky AI', 'yablonsky-multilingual') . '</a>';
    echo '</div></section>';
  }}
