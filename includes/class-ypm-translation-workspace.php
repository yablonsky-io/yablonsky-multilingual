<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Server-filtered, bounded translation-family workspace. */
final class TranslationWorkspace {
  private const PAGE_SIZE = 25;
  private const PAGE_SIZES = [10, 25, 50, 100];

  public static function init(): void {
    if (!is_admin()) return;
    add_action('admin_menu', [__CLASS__, 'register_page'], 29);
    add_action('admin_post_ymulti_workspace_ai_bulk', [__CLASS__, 'handle_ai_bulk']);
  }

  public static function register_page(): void {
    add_submenu_page(
      'ypm-multilingual',
      __('Translations', 'yablonsky-multilingual'),
      __('Translations', 'yablonsky-multilingual'),
      'edit_posts',
      'ypm-translations',
      [__CLASS__, 'render_page']
    );
  }

  public static function render_page(): void {
    if (!current_user_can('edit_posts')) return;

    $active = Languages::active_codes();
    $active_count = count($active);
    $filters = self::read_filters($active);
    $page_size = Admin::requested_page_size(self::PAGE_SIZE, self::PAGE_SIZES);
    $paged = max(1, (int) filter_input(INPUT_GET, 'paged', FILTER_VALIDATE_INT));

    $overall_total = self::count_matching_families(self::empty_filters(), $active);
    // Target alone changes the editing context, not the catalog scope. Reuse
    // the already computed Families count when all effective filters are empty,
    // avoiding a second potentially expensive WPML/native family COUNT query.
    // A target-state filter remains a distinct query, even if other fields are empty.
    $count_filters = $filters;
    if ($count_filters['target_state'] === 'all') $count_filters['target'] = '';
    $total = $count_filters === self::empty_filters()
      ? $overall_total
      : self::count_matching_families($filters, $active);
    $pages = max(1, (int) ceil($total / $page_size));
    if ($paged > $pages && $total > 0) {
      $redirect = self::workspace_url($filters, $page_size, $pages);
      wp_safe_redirect($redirect);
      exit;
    }

    $offset = ($paged - 1) * $page_size;
    $family_rows = self::matching_family_rows($filters, $active, $page_size, $offset);
    $families = self::hydrate_families($family_rows, $filters, $active);
    $visible = count($families);
    $complete_on_page = 0;
    foreach ($families as $family) {
      if (!empty($family['complete'])) $complete_on_page++;
    }
    $needs_on_page = max(0, $visible - $complete_on_page);

    $ai_ready = class_exists('Yablonsky\\Multilingual\\AITranslation') && AITranslation::ready() && AITranslation::current_user_can_compute();
    $ai_job = class_exists('Yablonsky\\Multilingual\\AIJobs') ? AIJobs::job() : [];

    echo '<div class="wrap ypm-admin ypm-admin--workspace">';
    Admin::render_shell_header(
      __('Translations', 'yablonsky-multilingual'),
      __('Search the whole translation catalog, choose the source and target locale, narrow by content type or status, then work only on the bounded page you need.', 'yablonsky-multilingual'),
      $needs_on_page > 0 ? 'attention' : 'pass',
      $needs_on_page > 0 ? __('Work queue', 'yablonsky-multilingual') : __('Filtered page complete', 'yablonsky-multilingual')
    );

    $ai_notice_key = 'ypm_ai_notice_' . get_current_user_id();
    $ai_notice = get_transient($ai_notice_key);
    if (is_array($ai_notice) && !empty($ai_notice['message'])) {
      delete_transient($ai_notice_key);
      $notice_type = strpos((string) ($ai_notice['key'] ?? ''), 'error') !== false ? 'error' : 'success';
      echo '<div class="notice notice-' . esc_attr($notice_type) . ' inline"><p>' . esc_html((string) $ai_notice['message']) . '</p></div>';
    }

    if (class_exists('Yablonsky\Multilingual\AITranslation')) {
      $text_setup = AITranslation::text_setup_status();
      if (empty($text_setup['ready'])) AIAdmin::render_text_compute_gate($text_setup, admin_url('admin.php?page=ypm-translations'));
    }

    echo '<div class="ypm-metrics ypm-metrics--4">';
    Admin::render_metric(__('Families', 'yablonsky-multilingual'), (string) $overall_total, __('Grouped + ungrouped content families', 'yablonsky-multilingual'), 'neutral');
    Admin::render_metric(__('Matches', 'yablonsky-multilingual'), (string) $total, __('After current filters', 'yablonsky-multilingual'), $total < $overall_total ? 'authority' : 'neutral');
    Admin::render_metric(__('Active locales', 'yablonsky-multilingual'), (string) $active_count, __('Configured languages', 'yablonsky-multilingual'), 'neutral');
    Admin::render_metric(__('Needs translation', 'yablonsky-multilingual'), (string) $needs_on_page, __('On this bounded page', 'yablonsky-multilingual'), $needs_on_page > 0 ? 'attention' : 'pass');
    echo '</div>';

    if ($ai_job) {
      $job_status = sanitize_key((string) ($ai_job['status'] ?? ''));
      $job_ids = isset($ai_job['source_ids']) && is_array($ai_job['source_ids']) ? $ai_job['source_ids'] : [];
      $job_total = count($job_ids);
      $job_cursor = min($job_total, max(0, (int) ($ai_job['cursor'] ?? 0)));
      $job_target = sanitize_text_field((string) ($ai_job['target_locale'] ?? ''));
      $stage_current = max(0, (int) ($ai_job['stage_current'] ?? 0));
      $stage_total = max(0, (int) ($ai_job['stage_total'] ?? 0));
      $remote_actions = max(0, (int) ($ai_job['remote_actions'] ?? 0));
      $stage_model = sanitize_text_field((string) ($ai_job['stage_model'] ?? ''));
      $progress_before = max(0, $job_cursor - (($stage_total > 0 && $job_status === AITranslation::STATUS_RUNNING) ? 1 : 0));
      $progress_fraction = $stage_total > 0 ? min(1, max(0, ($stage_current - 1) / $stage_total)) : 0;
      $job_percent = $job_total > 0 ? (int) floor((($progress_before + $progress_fraction) / $job_total) * 100) : 0;
      if ($job_status === AITranslation::STATUS_COMPLETED) $job_percent = 100;
      $status_nonce = wp_create_nonce('ypm_ai_batch_status');
      echo '<section class="ypm-card ypm-card--standalone ypm-ai-job-card" data-ymulti-ai-job-panel data-job-status="' . esc_attr($job_status) . '" data-job-nonce="' . esc_attr($status_nonce) . '"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('AI batch', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Reviewed draft queue', 'yablonsky-multilingual') . '</h2></div>';
      $job_status_labels = [
        AITranslation::STATUS_RUNNING => __('Running', 'yablonsky-multilingual'),
        AITranslation::STATUS_COMPLETED => __('Completed', 'yablonsky-multilingual'),
        AITranslation::STATUS_FAILED => __('Failed', 'yablonsky-multilingual'),
        AITranslation::STATUS_CANCELLED => __('Cancelled', 'yablonsky-multilingual'),
      ];
      $job_status_label = $job_status_labels[$job_status] ?? __('Unknown', 'yablonsky-multilingual');
      echo wp_kses_post(Admin::status_badge($job_status === AITranslation::STATUS_RUNNING ? 'running' : ($job_status === AITranslation::STATUS_COMPLETED ? 'pass' : ($job_status === AITranslation::STATUS_FAILED ? 'fail' : 'neutral')), $job_status_label));
      echo '</div><div class="ypm-ai-job-stage-progress"><div><strong data-ymulti-ai-job-percent>' . esc_html((string) $job_percent . '%') . '</strong><span data-ymulti-ai-job-stage>' . esc_html($stage_total > 1 && $stage_current > 0 ? sprintf(
        /* translators: 1: current AI stage, 2: total AI stages for current content item. */
        __('Stage %1$d of %2$d', 'yablonsky-multilingual'),
        $stage_current,
        $stage_total
      ) : __('Adaptive staging', 'yablonsky-multilingual')) . '</span></div><span class="ymulti-coverage-rail"><i data-ymulti-ai-job-progress-fill style="width:' . esc_attr((string) max(0, min(100, $job_percent))) . '%"></i></span><small data-ymulti-ai-job-progress-copy>' . esc_html(sprintf(
        /* translators: 1: current content item number, 2: total content items. */
        __('Item %1$d of %2$d', 'yablonsky-multilingual'),
        $job_cursor,
        $job_total
      )) . '</small></div><div class="ypm-ai-job-progress"><strong data-ymulti-ai-job-items>' . esc_html((string) $job_cursor . '/' . (string) $job_total) . '</strong><span>' . esc_html($job_target !== '' ? Languages::display_name($job_target) . ' (' . Languages::display_code($job_target) . ')' : '—') . '</span><span>' . esc_html(sprintf(
        /* translators: 1: number of AI drafts created, 2: number of failed items, 3: number of skipped items. */
        __('%1$d drafts · %2$d failed · %3$d skipped', 'yablonsky-multilingual'),
        (int) ($ai_job['created'] ?? 0),
        (int) ($ai_job['failed'] ?? 0),
        (int) ($ai_job['skipped'] ?? 0)
      )) . '</span>';
      $credits_used = is_numeric($ai_job['credits_used'] ?? null) ? (float) $ai_job['credits_used'] : 0.0;
      if ($credits_used > 0) {
        echo '<span>' . esc_html(sprintf(
          /* translators: %s: total AI credits consumed by the current/recent batch. */
          __('%s credits used', 'yablonsky-multilingual'),
          rtrim(rtrim(number_format($credits_used, 4, '.', ''), '0'), '.')
        )) . '</span>';
      }
      echo '<span data-ymulti-ai-job-requests>' . esc_html(sprintf(
        /* translators: %d: staged remote AI requests performed by the batch. */
        __('%d AI requests', 'yablonsky-multilingual'),
        $remote_actions
      )) . '</span>';
      echo '<span data-ymulti-ai-job-model' . ($stage_model === '' ? ' hidden' : '') . '><code>' . esc_html($stage_model) . '</code></span>';
      echo '</div>';
      $draft_ids = isset($ai_job['draft_ids']) && is_array($ai_job['draft_ids']) ? array_values(array_filter(array_map('absint', $ai_job['draft_ids']))) : [];
      $last_draft_id = $draft_ids ? (int) end($draft_ids) : 0;
      if ($job_status === AITranslation::STATUS_RUNNING && AIJobs::current_user_can_control_job()) {
        echo '<div class="ypm-inline-actions"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ypm_ai_batch_next', 'ypm_ai_batch_nonce');
        echo '<input type="hidden" name="action" value="ypm_ai_batch_next"><button class="button">' . esc_html__('Run next now', 'yablonsky-multilingual') . '</button></form><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ypm_ai_batch_cancel', 'ypm_ai_batch_nonce');
        echo '<input type="hidden" name="action" value="ypm_ai_batch_cancel"><button class="button button-link-delete">' . esc_html__('Cancel batch', 'yablonsky-multilingual') . '</button></form>';
        if ($last_draft_id > 0 && current_user_can('edit_post', $last_draft_id)) {
          $last_edit = get_edit_post_link($last_draft_id, '');
          if ($last_edit) echo '<a class="button" href="' . esc_url($last_edit) . '">' . esc_html__('Open latest draft', 'yablonsky-multilingual') . '</a>';
        }
        echo '</div>';
      } elseif ($last_draft_id > 0 && current_user_can('edit_post', $last_draft_id)) {
        $last_edit = get_edit_post_link($last_draft_id, '');
        if ($last_edit) echo '<div class="ypm-inline-actions"><a class="button" href="' . esc_url($last_edit) . '">' . esc_html__('Open latest draft', 'yablonsky-multilingual') . '</a></div>';
      }
      $job_errors = isset($ai_job['errors']) && is_array($ai_job['errors']) ? array_slice($ai_job['errors'], -5) : [];
      if ($job_errors) {
        echo '<details class="ypm-disclosure ypm-ai-job-errors"><summary><span><strong>' . esc_html__('Recent batch issues', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Only the latest bounded error messages are retained.', 'yablonsky-multilingual') . '</small></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary><div class="ypm-disclosure__body"><ul>';
        foreach ($job_errors as $job_error) {
          if (!is_array($job_error)) continue;
          $error_source = absint($job_error['source_id'] ?? 0);
          $error_message = sanitize_text_field((string) ($job_error['message'] ?? ''));
          if ($error_message === '') continue;
          $prefix = $error_source > 0 ? '#' . $error_source . ' — ' : '';
          echo '<li>' . esc_html($prefix . $error_message) . '</li>';
        }
        echo '</ul></div></details>';
      }
      echo '</section>';
    }
    echo '<section class="ypm-card ypm-card--standalone ypm-workspace-card">';
    echo '<div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Translation families', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Translation workspace', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('Filters apply server-side across the complete catalog, including rows beyond the current browser page.', 'yablonsky-multilingual') . '</p></div></div>';
    self::render_filter_form($filters, $active, $page_size);
    if ($families) {
      self::render_ai_batch_launcher($families, $filters, $active, $ai_ready, $total);
      self::render_professional_jobs_launcher($families, $filters);
    }

    if (!$families) {
      echo '<div class="ypm-empty-panel"><span class="dashicons dashicons-filter" aria-hidden="true"></span><strong>' . esc_html__('0 translation families match these filters', 'yablonsky-multilingual') . '</strong><p>' . esc_html__('Reset the filters or create/connect a translation from a normal WordPress content editor.', 'yablonsky-multilingual') . '</p><a class="button" href="' . esc_url(admin_url('admin.php?page=ypm-translations')) . '">' . esc_html__('Reset filters', 'yablonsky-multilingual') . '</a></div>';
    } else {
      echo '<div class="ypm-table-wrap"><table class="widefat ymulti-entity-table ymulti-entity-table--content ypm-workspace-table ypm-workspace-table--catalog"><thead><tr><th class="ymulti-bulk-check"><input type="checkbox" data-ymulti-bulk-all="workspace" aria-label="' . esc_attr__('Select this page', 'yablonsky-multilingual') . '"></th><th>' . esc_html__('Content', 'yablonsky-multilingual') . '</th><th>' . esc_html__('Source', 'yablonsky-multilingual') . '</th><th>' . esc_html__('Coverage', 'yablonsky-multilingual') . '</th><th>' . esc_html__('Translations', 'yablonsky-multilingual') . '</th><th class="ypm-col-action">' . esc_html__('Action', 'yablonsky-multilingual') . '</th></tr></thead><tbody>';
      foreach ($families as $family) self::render_family_row($family, $filters, $active, $active_count, $ai_ready);
      echo '</tbody></table></div>';
    }

    self::render_pagination($filters, $page_size, $paged, $pages, $total, $offset, $visible);
    echo '</section>';
    if (class_exists('Yablonsky\\Multilingual\\NavigationAudit')) NavigationAudit::render_panel();
    if (class_exists('Yablonsky\\Multilingual\\TranslationMemory')) TranslationMemory::render_panel();
    if (class_exists('Yablonsky\\Multilingual\\TranslationGlossary')) TranslationGlossary::render_panel();
    echo '</div>';
  }

  /** @param string[] $active */
  private static function read_filters(array $active): array {
    $search_raw = filter_input(INPUT_GET, 's', FILTER_UNSAFE_RAW);
    $post_type_raw = filter_input(INPUT_GET, 'post_type_filter', FILTER_UNSAFE_RAW);
    $status_raw = filter_input(INPUT_GET, 'post_status_filter', FILTER_UNSAFE_RAW);
    $term_raw = filter_input(INPUT_GET, 'term_filter', FILTER_UNSAFE_RAW);
    $source_raw = filter_input(INPUT_GET, 'source_locale', FILTER_UNSAFE_RAW);
    $target_raw = filter_input(INPUT_GET, 'target_locale', FILTER_UNSAFE_RAW);
    $coverage_raw = filter_input(INPUT_GET, 'coverage', FILTER_UNSAFE_RAW);
    $target_state_raw = filter_input(INPUT_GET, 'target_state', FILTER_UNSAFE_RAW);

    $search = is_string($search_raw) ? sanitize_text_field(wp_unslash($search_raw)) : '';
    $post_type = is_string($post_type_raw) ? sanitize_key(wp_unslash($post_type_raw)) : '';
    $status = is_string($status_raw) ? sanitize_key(wp_unslash($status_raw)) : '';
    $term = is_string($term_raw) ? sanitize_text_field(wp_unslash($term_raw)) : '';
    $source = is_string($source_raw) ? sanitize_text_field(wp_unslash($source_raw)) : '';
    $target = is_string($target_raw) ? sanitize_text_field(wp_unslash($target_raw)) : '';
    if ($target === '' && class_exists('Yablonsky\Multilingual\AdminLanguageContext')) $target = AdminLanguageContext::preferred_target();
    $coverage = is_string($coverage_raw) ? sanitize_key(wp_unslash($coverage_raw)) : 'all';
    $target_state = is_string($target_state_raw) ? sanitize_key(wp_unslash($target_state_raw)) : 'all';

    $post_types = Languages::supported_post_types();
    if (!in_array($post_type, $post_types, true)) $post_type = '';
    $allowed_statuses = ['publish', 'draft', 'pending', 'private', 'future'];
    if (!in_array($status, $allowed_statuses, true)) $status = '';
    if (!in_array($source, $active, true)) $source = '';
    if (!in_array($target, $active, true)) $target = '';
    if (!in_array($coverage, ['all', 'open', 'complete'], true)) $coverage = 'all';
    if (!in_array($target_state, ['all', 'missing', 'present'], true) || $target === '') $target_state = 'all';

    return [
      'search' => $search,
      'post_type' => $post_type,
      'status' => $status,
      'term' => $term,
      'source' => $source,
      'target' => $target,
      'coverage' => $coverage,
      'target_state' => $target_state,
    ];
  }

  private static function empty_filters(): array {
    return [
      'search' => '',
      'post_type' => '',
      'status' => '',
      'term' => '',
      'source' => '',
      'target' => '',
      'coverage' => 'all',
      'target_state' => 'all',
    ];
  }

  /** @param string[] $active */
  private static function build_group_where(array $filters, array $active): array {
    global $wpdb;
    $args = [Translations::GROUP_TAX];
    $where = ['gtt.taxonomy = %s'];

    $supported = array_values(array_unique(array_filter(array_map('sanitize_key', Languages::supported_post_types()))));
    if (!$supported) $supported = ['post', 'page'];
    $member = ['p.post_type IN (' . implode(',', array_fill(0, count($supported), '%s')) . ')'];
    array_push($args, ...$supported);
    $member[] = "p.post_status NOT IN ('trash','auto-draft','inherit')";

    if ($filters['post_type'] !== '') {
      $member[] = 'p.post_type = %s';
      $args[] = $filters['post_type'];
    }
    if ($filters['status'] !== '') {
      $member[] = 'p.post_status = %s';
      $args[] = $filters['status'];
    }
    if ($filters['search'] !== '') {
      $like = '%' . $wpdb->esc_like($filters['search']) . '%';
      if (ctype_digit($filters['search'])) {
        $member[] = '(p.post_title LIKE %s OR p.post_name LIKE %s OR p.ID = %d)';
        $args[] = $like;
        $args[] = $like;
        $args[] = (int) $filters['search'];
      } else {
        $member[] = '(p.post_title LIKE %s OR p.post_name LIKE %s)';
        $args[] = $like;
        $args[] = $like;
      }
    }
    if ($filters['term'] !== '') {
      $term_like = '%' . $wpdb->esc_like($filters['term']) . '%';
      $member[] = 'EXISTS (SELECT 1 FROM ' . $wpdb->term_relationships . ' ctr INNER JOIN ' . $wpdb->term_taxonomy . ' ctt ON ctt.term_taxonomy_id = ctr.term_taxonomy_id INNER JOIN ' . $wpdb->terms . ' ct ON ct.term_id = ctt.term_id WHERE ctr.object_id = p.ID AND ctt.taxonomy NOT IN (%s,%s) AND (ct.name LIKE %s OR ct.slug LIKE %s))';
      $args[] = Translations::GROUP_TAX;
      $args[] = Languages::TAX;
      $args[] = $term_like;
      $args[] = $term_like;
    }
    if ($filters['source'] !== '') {
      $lang_exists = 'EXISTS (SELECT 1 FROM ' . $wpdb->term_relationships . ' slr INNER JOIN ' . $wpdb->term_taxonomy . ' sltt ON sltt.term_taxonomy_id = slr.term_taxonomy_id INNER JOIN ' . $wpdb->terms . ' slt ON slt.term_id = sltt.term_id WHERE slr.object_id = p.ID AND sltt.taxonomy = %s AND slt.slug = %s)';
      $args[] = Languages::TAX;
      $args[] = Languages::code_to_slug($filters['source']);
      if ($filters['source'] === Languages::default_code()) {
        $member[] = '(' . $lang_exists . ' OR NOT EXISTS (SELECT 1 FROM ' . $wpdb->term_relationships . ' ulr INNER JOIN ' . $wpdb->term_taxonomy . ' ultt ON ultt.term_taxonomy_id = ulr.term_taxonomy_id WHERE ulr.object_id = p.ID AND ultt.taxonomy = %s))';
        $args[] = Languages::TAX;
      } else {
        $member[] = $lang_exists;
      }
    }

    $where[] = 'EXISTS (SELECT 1 FROM ' . $wpdb->term_relationships . ' gmr INNER JOIN ' . $wpdb->posts . ' p ON p.ID = gmr.object_id WHERE gmr.term_taxonomy_id = gtt.term_taxonomy_id AND ' . implode(' AND ', $member) . ')';

    if ($filters['target'] !== '' && $filters['target_state'] !== 'all') {
      $target_explicit = 'EXISTS (SELECT 1 FROM ' . $wpdb->term_relationships . ' tlr INNER JOIN ' . $wpdb->term_taxonomy . ' tltt ON tltt.term_taxonomy_id = tlr.term_taxonomy_id INNER JOIN ' . $wpdb->terms . ' tlt ON tlt.term_id = tltt.term_id WHERE tlr.object_id = tgr.object_id AND tltt.taxonomy = %s AND tlt.slug = %s)';
      $args[] = Languages::TAX;
      $args[] = Languages::code_to_slug($filters['target']);
      $target_language_match = $target_explicit;
      if ($filters['target'] === Languages::default_code()) {
        $target_language_match = '(' . $target_explicit . ' OR NOT EXISTS (SELECT 1 FROM ' . $wpdb->term_relationships . ' tulr INNER JOIN ' . $wpdb->term_taxonomy . ' tultt ON tultt.term_taxonomy_id = tulr.term_taxonomy_id WHERE tulr.object_id = tgr.object_id AND tultt.taxonomy = %s))';
        $args[] = Languages::TAX;
      }
      $target_exists = 'EXISTS (SELECT 1 FROM ' . $wpdb->term_relationships . ' tgr WHERE tgr.term_taxonomy_id = gtt.term_taxonomy_id AND ' . $target_language_match . ')';
      $where[] = ($filters['target_state'] === 'missing' ? 'NOT ' : '') . $target_exists;
    }

    if ($filters['coverage'] !== 'all' && $active) {
      $active_slugs = array_values(array_unique(array_map([Languages::class, 'code_to_slug'], $active)));
      $default_slug = Languages::code_to_slug(Languages::default_code());
      $language_map = '(SELECT alr.object_id, alt.slug FROM ' . $wpdb->term_relationships . ' alr INNER JOIN ' . $wpdb->term_taxonomy . ' altt ON altt.term_taxonomy_id = alr.term_taxonomy_id INNER JOIN ' . $wpdb->terms . ' alt ON alt.term_id = altt.term_id WHERE altt.taxonomy = %s)';
      $coverage_sql = '(SELECT COUNT(DISTINCT COALESCE(alm.slug,%s)) FROM ' . $wpdb->term_relationships . ' agr LEFT JOIN ' . $language_map . ' alm ON alm.object_id = agr.object_id WHERE agr.term_taxonomy_id = gtt.term_taxonomy_id AND COALESCE(alm.slug,%s) IN (' . implode(',', array_fill(0, count($active_slugs), '%s')) . '))';
      $args[] = $default_slug;
      $args[] = Languages::TAX;
      $args[] = $default_slug;
      array_push($args, ...$active_slugs);
      $where[] = $coverage_sql . ($filters['coverage'] === 'complete' ? ' >= %d' : ' < %d');
      $args[] = count($active_slugs);
    }

    return [implode(' AND ', $where), $args];
  }

  /**
   * Build the same catalog filters for translatable content that does not yet
   * belong to a stored translation group. These objects are first-class
   * singleton families: the workspace must not hide content merely because no
   * translation has been created yet.
   *
   * @param string[] $active
   */
  private static function build_singleton_where(array $filters, array $active): array {
    global $wpdb;

    $supported = array_values(array_unique(array_filter(array_map('sanitize_key', Languages::supported_post_types()))));
    if (!$supported) $supported = ['post', 'page'];

    $args = $supported;
    $where = [
      'p.post_type IN (' . implode(',', array_fill(0, count($supported), '%s')) . ')',
      "p.post_status NOT IN ('trash','auto-draft','inherit')",
    ];

    // A post with no materialized group is still a valid one-member family.
    $where[] = 'NOT EXISTS (SELECT 1 FROM ' . $wpdb->term_relationships . ' ugr INNER JOIN ' . $wpdb->term_taxonomy . ' ugtt ON ugtt.term_taxonomy_id = ugr.term_taxonomy_id WHERE ugr.object_id = p.ID AND ugtt.taxonomy = %s)';
    $args[] = Translations::GROUP_TAX;

    if ($filters['post_type'] !== '') {
      $where[] = 'p.post_type = %s';
      $args[] = $filters['post_type'];
    }
    if ($filters['status'] !== '') {
      $where[] = 'p.post_status = %s';
      $args[] = $filters['status'];
    }
    if ($filters['search'] !== '') {
      $like = '%' . $wpdb->esc_like($filters['search']) . '%';
      if (ctype_digit($filters['search'])) {
        $where[] = '(p.post_title LIKE %s OR p.post_name LIKE %s OR p.ID = %d)';
        $args[] = $like;
        $args[] = $like;
        $args[] = (int) $filters['search'];
      } else {
        $where[] = '(p.post_title LIKE %s OR p.post_name LIKE %s)';
        $args[] = $like;
        $args[] = $like;
      }
    }
    if ($filters['term'] !== '') {
      $term_like = '%' . $wpdb->esc_like($filters['term']) . '%';
      $where[] = 'EXISTS (SELECT 1 FROM ' . $wpdb->term_relationships . ' ctr INNER JOIN ' . $wpdb->term_taxonomy . ' ctt ON ctt.term_taxonomy_id = ctr.term_taxonomy_id INNER JOIN ' . $wpdb->terms . ' ct ON ct.term_id = ctt.term_id WHERE ctr.object_id = p.ID AND ctt.taxonomy NOT IN (%s,%s) AND (ct.name LIKE %s OR ct.slug LIKE %s))';
      $args[] = Translations::GROUP_TAX;
      $args[] = Languages::TAX;
      $args[] = $term_like;
      $args[] = $term_like;
    }
    if ($filters['source'] !== '') {
      $where[] = self::post_language_match_sql('p.ID', $filters['source'], $args);
    }
    if ($filters['target'] !== '' && $filters['target_state'] !== 'all') {
      $target_match = self::post_language_match_sql('p.ID', $filters['target'], $args);
      $where[] = $filters['target_state'] === 'present' ? $target_match : 'NOT (' . $target_match . ')';
    }

    // A singleton has exactly one translated locale. Coverage filtering can be
    // resolved without scanning or materializing any translation family.
    $active_count = count($active);
    if ($filters['coverage'] === 'complete' && $active_count > 1) $where[] = '1 = 0';
    if ($filters['coverage'] === 'open' && $active_count <= 1) $where[] = '1 = 0';

    return [implode(' AND ', $where), $args];
  }

  /** Append placeholders for a post's effective language, including the default-language fallback. */
  private static function post_language_match_sql(string $post_expression, string $language, array &$args): string {
    global $wpdb;
    $exists = 'EXISTS (SELECT 1 FROM ' . $wpdb->term_relationships . ' plr INNER JOIN ' . $wpdb->term_taxonomy . ' pltt ON pltt.term_taxonomy_id = plr.term_taxonomy_id INNER JOIN ' . $wpdb->terms . ' plt ON plt.term_id = pltt.term_id WHERE plr.object_id = ' . $post_expression . ' AND pltt.taxonomy = %s AND plt.slug = %s)';
    $args[] = Languages::TAX;
    $args[] = Languages::code_to_slug($language);

    if ($language !== Languages::default_code()) return $exists;

    $args[] = Languages::TAX;
    return '(' . $exists . ' OR NOT EXISTS (SELECT 1 FROM ' . $wpdb->term_relationships . ' pulr INNER JOIN ' . $wpdb->term_taxonomy . ' pultt ON pultt.term_taxonomy_id = pulr.term_taxonomy_id WHERE pulr.object_id = ' . $post_expression . ' AND pultt.taxonomy = %s))';
  }

  private static function wpml_catalog_table(): string {
    return RuntimeAuthority::wpml_translation_table();
  }

  private static function wpml_catalog_available(): bool {
    return class_exists(RuntimeAuthority::class, false) && RuntimeAuthority::wpml_translation_table_available();
  }

  /** @return string[] */
  private static function wpml_element_types(): array {
    $out = [];
    foreach (Languages::supported_post_types() as $post_type) {
      $post_type = sanitize_key((string) $post_type);
      if ($post_type !== '') $out[] = 'post_' . $post_type;
    }
    return array_values(array_unique($out ?: ['post_post', 'post_page']));
  }

  /**
   * Build the predicate for the concrete WPML family member represented by a
   * row. Keeping this in one helper guarantees that Source, search, type and
   * status filter the same object that is rendered as the row source.
   *
   * @return array{0:string,1:array<int,mixed>}
   */
  private static function build_wpml_member_where(array $filters, string $relation_alias, string $post_alias): array {
    global $wpdb;
    $element_types = self::wpml_element_types();
    $supported = array_values(array_unique(array_filter(array_map('sanitize_key', Languages::supported_post_types()))));
    if (!$supported) $supported = ['post', 'page'];

    $member = [$relation_alias . '.element_type IN (' . implode(',', array_fill(0, count($element_types), '%s')) . ')'];
    $args = $element_types;
    $member[] = $post_alias . '.post_type IN (' . implode(',', array_fill(0, count($supported), '%s')) . ')';
    array_push($args, ...$supported);
    $member[] = $post_alias . ".post_status NOT IN ('trash','auto-draft','inherit')";

    if ($filters['post_type'] !== '') {
      $member[] = $post_alias . '.post_type = %s';
      $args[] = $filters['post_type'];
    }
    if ($filters['status'] !== '') {
      $member[] = $post_alias . '.post_status = %s';
      $args[] = $filters['status'];
    }
    if ($filters['search'] !== '') {
      $like = '%' . $wpdb->esc_like($filters['search']) . '%';
      if (ctype_digit($filters['search'])) {
        $member[] = '(' . $post_alias . '.post_title LIKE %s OR ' . $post_alias . '.post_name LIKE %s OR ' . $post_alias . '.ID = %d)';
        $args[] = $like;
        $args[] = $like;
        $args[] = (int) $filters['search'];
      } else {
        $member[] = '(' . $post_alias . '.post_title LIKE %s OR ' . $post_alias . '.post_name LIKE %s)';
        $args[] = $like;
        $args[] = $like;
      }
    }
    if ($filters['term'] !== '') {
      $term_like = '%' . $wpdb->esc_like($filters['term']) . '%';
      $member[] = 'EXISTS (SELECT 1 FROM ' . $wpdb->term_relationships . ' ctr INNER JOIN ' . $wpdb->term_taxonomy . ' ctt ON ctt.term_taxonomy_id = ctr.term_taxonomy_id INNER JOIN ' . $wpdb->terms . ' ct ON ct.term_id = ctt.term_id WHERE ctr.object_id = ' . $post_alias . '.ID AND ctt.taxonomy NOT IN (%s,%s) AND (ct.name LIKE %s OR ct.slug LIKE %s))';
      $args[] = Translations::GROUP_TAX;
      $args[] = Languages::TAX;
      $args[] = $term_like;
      $args[] = $term_like;
    }
    if ($filters['source'] !== '') {
      $provider_code = RuntimeAuthority::provider_code_for_language((string) $filters['source']);
      if ($provider_code === '') {
        $member[] = '1 = 0';
      } else {
        $member[] = $relation_alias . '.language_code = %s';
        $args[] = $provider_code;
      }
    }

    return [implode(' AND ', $member), $args];
  }

  /**
   * Provider-aware family predicate for WPML bridge mode. Every visible row is
   * one incumbent trid, not one translated object. Source/content filters are
   * applied to the same family member so the rendered source row matches the
   * selected language and search/status/type scope.
   *
   * @param string[] $active
   * @return array{0:string,1:array<int,mixed>}
   */
  private static function build_wpml_family_where(array $filters, array $active): array {
    global $wpdb;
    $table = self::wpml_catalog_table();
    $element_types = self::wpml_element_types();

    $args = $element_types;
    $where = ['root.trid > 0', 'root.element_type IN (' . implode(',', array_fill(0, count($element_types), '%s')) . ')'];

    [$member_where, $member_args] = self::build_wpml_member_where($filters, 'fm', 'p');
    $where[] = 'EXISTS (SELECT 1 FROM ' . esc_sql($table) . ' fm INNER JOIN ' . $wpdb->posts . ' p ON p.ID = fm.element_id WHERE fm.trid = root.trid AND ' . $member_where . ')';
    array_push($args, ...$member_args);

    if ($filters['target'] !== '' && $filters['target_state'] !== 'all') {
      $target_code = RuntimeAuthority::provider_code_for_language((string) $filters['target']);
      if ($target_code === '') {
        if ($filters['target_state'] === 'present') $where[] = '1 = 0';
      } else {
        $target_exists = 'EXISTS (SELECT 1 FROM ' . esc_sql($table) . ' tm INNER JOIN ' . $wpdb->posts . " tp ON tp.ID = tm.element_id WHERE tm.trid = root.trid AND tm.language_code = %s AND tm.element_type IN (" . implode(',', array_fill(0, count($element_types), '%s')) . ") AND tp.post_status NOT IN ('trash','auto-draft','inherit'))";
        $args[] = $target_code;
        array_push($args, ...$element_types);
        $where[] = ($filters['target_state'] === 'missing' ? 'NOT ' : '') . $target_exists;
      }
    }

    if ($filters['coverage'] !== 'all' && $active) {
      $provider_codes = [];
      foreach ($active as $code) {
        $mapped = RuntimeAuthority::provider_code_for_language((string) $code);
        if ($mapped !== '' && !in_array($mapped, $provider_codes, true)) $provider_codes[] = $mapped;
      }
      $required = count($active);
      if ($filters['coverage'] === 'complete' && count($provider_codes) < $required) {
        $where[] = '1 = 0';
      } elseif ($provider_codes) {
        $coverage_sql = '(SELECT COUNT(DISTINCT cm.language_code) FROM ' . esc_sql($table) . ' cm INNER JOIN ' . $wpdb->posts . " cp ON cp.ID = cm.element_id WHERE cm.trid = root.trid AND cm.language_code IN (" . implode(',', array_fill(0, count($provider_codes), '%s')) . ') AND cm.element_type IN (' . implode(',', array_fill(0, count($element_types), '%s')) . ") AND cp.post_status NOT IN ('trash','auto-draft','inherit'))";
        array_push($args, ...$provider_codes);
        array_push($args, ...$element_types);
        $where[] = $coverage_sql . ($filters['coverage'] === 'complete' ? ' >= %d' : ' < %d');
        $args[] = $required;
      }
    }

    return [implode(' AND ', $where), $args];
  }

  /** @param string[] $active */
  private static function count_matching_wpml_families(array $filters, array $active): int {
    global $wpdb;
    [$where, $args] = self::build_wpml_family_where($filters, $active);
    $table = self::wpml_catalog_table();
    $sql = 'SELECT COUNT(DISTINCT root.trid) FROM ' . esc_sql($table) . ' root WHERE ' . $where;
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only incumbent family catalog; all request values use prepare() placeholders.
    return (int) $wpdb->get_var($wpdb->prepare($sql, $args));
  }

  /** @param string[] $active @return array<int,object> */
  private static function matching_wpml_family_rows(array $filters, array $active, int $limit, int $offset): array {
    global $wpdb;
    [$where, $where_args] = self::build_wpml_family_where($filters, $active);
    [$pick_where, $pick_args] = self::build_wpml_member_where($filters, 'pick', 'pp');
    $table = self::wpml_catalog_table();
    $preferred = $filters['source'] !== '' ? RuntimeAuthority::provider_code_for_language((string) $filters['source']) : RuntimeAuthority::provider_code_for_language(RuntimeAuthority::default_language());
    if ($preferred === '') $preferred = '__ymulti_unmapped__';

    $sql = "SELECT 'provider' AS family_kind, 0 AS term_id, 0 AS term_taxonomy_id, '' AS slug, "
      . 'COALESCE(MIN(CASE WHEN pick.language_code = %s THEN pick.element_id END),MIN(CASE WHEN pick.source_language_code IS NULL THEN pick.element_id END),MIN(pick.element_id)) AS post_id, '
      . 'COALESCE(MIN(CASE WHEN pick.source_language_code IS NULL THEN pick.element_id END),MIN(pick.element_id)) AS sort_key, root.trid AS provider_group '
      . 'FROM ' . esc_sql($table) . ' root INNER JOIN ' . esc_sql($table) . ' pick ON pick.trid = root.trid INNER JOIN ' . $wpdb->posts . ' pp ON pp.ID = pick.element_id '
      . 'WHERE ' . $where . ' AND ' . $pick_where . ' GROUP BY root.trid ORDER BY sort_key DESC, root.trid DESC LIMIT %d OFFSET %d';
    $args = array_merge([$preferred], $where_args, $pick_args, [$limit, $offset]);
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only incumbent family pagination; all request values use prepare() placeholders.
    $rows = $wpdb->get_results($wpdb->prepare($sql, $args));
    return is_array($rows) ? $rows : [];
  }

  /** @param string[] $active */
  private static function count_matching_families(array $filters, array $active): int {
    if (self::wpml_catalog_available()) return self::count_matching_wpml_families($filters, $active);
    global $wpdb;
    [$group_where, $group_args] = self::build_group_where($filters, $active);
    [$post_where, $post_args] = self::build_singleton_where($filters, $active);

    $sql = 'SELECT ('
      . 'SELECT COUNT(*) FROM ' . $wpdb->term_taxonomy . ' gtt INNER JOIN ' . $wpdb->terms . ' gt ON gt.term_id = gtt.term_id WHERE ' . $group_where
      . ') + ('
      . 'SELECT COUNT(*) FROM ' . $wpdb->posts . ' p WHERE ' . $post_where
      . ')';
    $args = array_merge($group_args, $post_args);

    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only bounded catalog query; every request value is represented by a prepare() placeholder in the two builders above.
    return (int) $wpdb->get_var($wpdb->prepare($sql, $args));
  }

  /** @param string[] $active @return array<int,object> */
  private static function matching_family_rows(array $filters, array $active, int $limit, int $offset): array {
    if (self::wpml_catalog_available()) return self::matching_wpml_family_rows($filters, $active, $limit, $offset);
    global $wpdb;
    [$group_where, $group_args] = self::build_group_where($filters, $active);
    [$post_where, $post_args] = self::build_singleton_where($filters, $active);

    // One family must keep one catalog position for its entire lifetime. A
    // singleton used to sort by post ID, but the same item sorted by the
    // unrelated term_taxonomy_id as soon as its first translation created a
    // stored group. That made rows jump between pages after normal work.
    // New groups persist the original source post as an anchor. Existing
    // groups fall back to their oldest member, which is the pre-2.47.2
    // equivalent of the original singleton in the normal create-translation
    // flow.
    $group_sort_key = 'COALESCE(NULLIF(CAST((SELECT yoma.meta_value FROM ' . $wpdb->termmeta . ' yoma WHERE yoma.term_id = gtt.term_id AND yoma.meta_key = %s LIMIT 1) AS UNSIGNED),0),(SELECT MIN(yorm.object_id) FROM ' . $wpdb->term_relationships . ' yorm WHERE yorm.term_taxonomy_id = gtt.term_taxonomy_id))';
    $sql = 'SELECT family_kind, term_id, term_taxonomy_id, slug, post_id, sort_key FROM ('
      . "SELECT 'group' AS family_kind, gtt.term_id AS term_id, gtt.term_taxonomy_id AS term_taxonomy_id, gt.slug AS slug, 0 AS post_id, " . $group_sort_key . ' AS sort_key '
      . 'FROM ' . $wpdb->term_taxonomy . ' gtt INNER JOIN ' . $wpdb->terms . ' gt ON gt.term_id = gtt.term_id WHERE ' . $group_where
      . ' UNION ALL '
      . "SELECT 'post' AS family_kind, 0 AS term_id, 0 AS term_taxonomy_id, '' AS slug, p.ID AS post_id, p.ID AS sort_key "
      . 'FROM ' . $wpdb->posts . ' p WHERE ' . $post_where
      . ') ypm_catalog ORDER BY sort_key DESC, family_kind ASC, term_taxonomy_id DESC, post_id DESC LIMIT %d OFFSET %d';
    $args = array_merge([Translations::GROUP_ORDER_ANCHOR_META], $group_args, $post_args, [$limit, $offset]);

    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only bounded server pagination; every request value is represented by prepare() placeholders in the builders above.
    $rows = $wpdb->get_results($wpdb->prepare($sql, $args));
    return is_array($rows) ? $rows : [];
  }

  /** @param array<int,object> $family_rows @param string[] $active */
  private static function hydrate_families(array $family_rows, array $filters, array $active): array {
    if (!$family_rows) return [];

    $group_term_ids = [];
    $singleton_ids = [];
    foreach ($family_rows as $row) {
      $kind = isset($row->family_kind) ? (string) $row->family_kind : 'group';
      if (($kind === 'post' || $kind === 'provider') && isset($row->post_id)) {
        $singleton_ids[] = (int) $row->post_id;
      } elseif (isset($row->term_id)) {
        $group_term_ids[] = (int) $row->term_id;
      }
    }

    if ($group_term_ids) {
      $all_ids = get_objects_in_term(array_values(array_unique($group_term_ids)), Translations::GROUP_TAX);
      if (!is_wp_error($all_ids) && $all_ids) Translations::prime_translation_families(array_map('intval', $all_ids));
    }
    if ($singleton_ids) Translations::prime_languages(array_values(array_unique($singleton_ids)));

    $families = [];
    $active_count = count($active);
    foreach ($family_rows as $row) {
      $kind = isset($row->family_kind) ? (string) $row->family_kind : 'group';

      if ($kind === 'post' || $kind === 'provider') {
        $seed = isset($row->post_id) ? (int) $row->post_id : 0;
        if ($seed <= 0) continue;
        $post = get_post($seed);
        if (!($post instanceof \WP_Post)) continue;
        if ($kind === 'provider') {
          $native = null;
        } else {
          $assigned = Translations::assigned_post_language($seed);
          $native = $assigned !== null ? [$assigned => $seed] : [];
        }
      } else {
        $slug = isset($row->slug) ? (string) $row->slug : '';
        if ($slug === '') continue;
        $native = Translations::translations_by_language_for_group($slug);
        if (!$native) continue;
        $seed = self::choose_seed($native, $filters);
        if ($seed <= 0) continue;
        $post = get_post($seed);
        if (!($post instanceof \WP_Post)) continue;
      }

      $effective = EffectiveRelations::post_family($seed, is_array($native) ? $native : null, $active);
      $by_language = is_array($effective['translations'] ?? null) ? $effective['translations'] : [];
      if (!$by_language) continue;
      $source = (string) ($effective['source'] ?? Translations::get_post_language($seed));
      $coverage = count(array_intersect(array_keys($by_language), $active));

      $families[] = [
        'seed' => $seed,
        'source' => $source,
        'title' => get_the_title($seed) ?: ('#' . $seed),
        'post_type' => (string) $post->post_type,
        'status' => (string) $post->post_status,
        'coverage' => $coverage,
        'complete' => $active_count > 0 && $coverage >= $active_count,
        'translations' => $by_language,
        'virtual' => $kind === 'post' || $kind === 'provider',
        'provenance' => is_array($effective['provenance'] ?? null) ? $effective['provenance'] : [],
        'bridge_provider' => (string) ($effective['provider'] ?? ''),
        'bridge_conflicts' => is_array($effective['conflicts'] ?? null) ? $effective['conflicts'] : [],
        'family_key' => (string) ($effective['family_key'] ?? ''),
      ];
    }
    return $families;
  }

  /** @param array<string,int> $by_language */
  private static function choose_seed(array $by_language, array $filters): int {
    if ($filters['source'] !== '' && isset($by_language[$filters['source']])) return (int) $by_language[$filters['source']];
    $default = Languages::default_code();
    if (isset($by_language[$default])) return (int) $by_language[$default];
    $first = reset($by_language);
    return $first ? (int) $first : 0;
  }

  /** @param string[] $active */
  private static function render_filter_form(array $filters, array $active, int $page_size): void {
    $post_types = Languages::supported_post_types();
    $statuses = [
      'publish' => __('Published', 'yablonsky-multilingual'),
      'draft' => __('Draft', 'yablonsky-multilingual'),
      'pending' => __('Pending review', 'yablonsky-multilingual'),
      'private' => __('Private', 'yablonsky-multilingual'),
      'future' => __('Scheduled', 'yablonsky-multilingual'),
    ];

    echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="ypm-workspace-filter-form">';
    echo '<input type="hidden" name="page" value="ypm-translations">';
    echo '<label class="ypm-workspace-filter ypm-workspace-filter--search"><span>' . esc_html__('Search', 'yablonsky-multilingual') . '</span><input type="search" name="s" value="' . esc_attr($filters['search']) . '" placeholder="' . esc_attr__('Title, slug or ID', 'yablonsky-multilingual') . '"></label>';

    echo '<label class="ypm-workspace-filter"><span>' . esc_html__('Content type', 'yablonsky-multilingual') . '</span><select name="post_type_filter"><option value="">' . esc_html__('All content types', 'yablonsky-multilingual') . '</option>';
    foreach ($post_types as $post_type) {
      $object = get_post_type_object($post_type);
      $label = $object && isset($object->labels->name) ? (string) $object->labels->name : $post_type;
      echo '<option value="' . esc_attr($post_type) . '"' . selected($filters['post_type'], $post_type, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label>';

    echo '<label class="ypm-workspace-filter"><span>' . esc_html__('Status', 'yablonsky-multilingual') . '</span><select name="post_status_filter"><option value="">' . esc_html__('All statuses', 'yablonsky-multilingual') . '</option>';
    foreach ($statuses as $value => $label) echo '<option value="' . esc_attr($value) . '"' . selected($filters['status'], $value, false) . '>' . esc_html($label) . '</option>';
    echo '</select></label>';

    echo '<label class="ypm-workspace-filter"><span>' . esc_html__('Category / term', 'yablonsky-multilingual') . '</span><input type="search" name="term_filter" value="' . esc_attr($filters['term']) . '" placeholder="' . esc_attr__('Name or slug', 'yablonsky-multilingual') . '"></label>';

    echo '<label class="ypm-workspace-filter"><span>' . esc_html__('Source language', 'yablonsky-multilingual') . '</span><select name="source_locale"><option value="">' . esc_html__('Any source language', 'yablonsky-multilingual') . '</option>';
    foreach ($active as $code) echo '<option value="' . esc_attr($code) . '"' . selected($filters['source'], $code, false) . '>' . esc_html(Languages::display_name($code) . ' · ' . Languages::display_code($code)) . '</option>';
    echo '</select></label>';

    echo '<label class="ypm-workspace-filter"><span>' . esc_html__('Target language', 'yablonsky-multilingual') . '</span><select id="ymulti-content-target-language" name="target_locale"><option value="">' . esc_html__('Any target language', 'yablonsky-multilingual') . '</option>';
    foreach ($active as $code) echo '<option value="' . esc_attr($code) . '"' . selected($filters['target'], $code, false) . '>' . esc_html(Languages::display_name($code) . ' · ' . Languages::display_code($code)) . '</option>';
    echo '</select></label>';

    echo '<label class="ypm-workspace-filter ypm-workspace-filter--target-state"><span>' . esc_html__('Target state', 'yablonsky-multilingual') . '</span><select name="target_state"><option value="all"' . selected($filters['target_state'], 'all', false) . '>' . esc_html__('Any target state', 'yablonsky-multilingual') . '</option><option value="missing"' . selected($filters['target_state'], 'missing', false) . '>' . esc_html__('Target to create', 'yablonsky-multilingual') . '</option><option value="present"' . selected($filters['target_state'], 'present', false) . '>' . esc_html__('Target exists', 'yablonsky-multilingual') . '</option></select><small>' . esc_html__('Choose a target language first.', 'yablonsky-multilingual') . '</small></label>';

    echo '<label class="ypm-workspace-filter"><span>' . esc_html__('Coverage', 'yablonsky-multilingual') . '</span><select name="coverage"><option value="all"' . selected($filters['coverage'], 'all', false) . '>' . esc_html__('Any coverage', 'yablonsky-multilingual') . '</option><option value="open"' . selected($filters['coverage'], 'open', false) . '>' . esc_html__('Needs translation', 'yablonsky-multilingual') . '</option><option value="complete"' . selected($filters['coverage'], 'complete', false) . '>' . esc_html__('Complete', 'yablonsky-multilingual') . '</option></select></label>';

    echo '<div class="ypm-workspace-filter-actions"><button class="button button-primary">' . esc_html__('Apply filters', 'yablonsky-multilingual') . '</button><a class="button" href="' . esc_url(admin_url('admin.php?page=ypm-translations')) . '">' . esc_html__('Reset', 'yablonsky-multilingual') . '</a></div>';
    echo '</form>';
  }

  /** @param string[] $active */
  private static function render_family_row(array $family, array $filters, array $active, int $active_count, bool $ai_ready): void {
    $percentage = $active_count > 0 ? (int) round(((int) $family['coverage'] / $active_count) * 100) : 0;
    $seed = (int) $family['seed'];
    $source = (string) $family['source'];
    $translations = is_array($family['translations']) ? $family['translations'] : [];
    $provenance = isset($family['provenance']) && is_array($family['provenance']) ? $family['provenance'] : [];
    $target = (string) $filters['target'];
    $target_id = $target !== '' && isset($translations[$target]) ? (int) $translations[$target] : 0;
    $seed_post = get_post($seed);

    /* translators: %s: content item title. */
    $select_family_label = sprintf(__('Select %s', 'yablonsky-multilingual'), (string) $family['title']);
    echo '<tr><td class="ymulti-bulk-check"><input type="checkbox" value="' . esc_attr((string) $seed) . '" data-ymulti-bulk-row="workspace" aria-label="' . esc_attr($select_family_label) . '"></td><td><div class="ypm-content-cell"><strong>' . esc_html((string) $family['title']) . '</strong><span>' . esc_html((string) $family['post_type']) . ' · ' . esc_html((string) $family['status']) . ' · #' . esc_html((string) $seed) . '</span></div></td>';
    echo '<td class="ymulti-col-source">' . wp_kses_post(Admin::language_badge($source)) . '</td>';
    echo '<td><div class="ypm-coverage"><div><strong>' . esc_html((string) $family['coverage'] . '/' . (string) $active_count) . '</strong>' . wp_kses_post(Admin::status_badge(!empty($family['complete']) ? 'pass' : 'attention', !empty($family['complete']) ? __('Complete', 'yablonsky-multilingual') : __('Open', 'yablonsky-multilingual'))) . '</div><span class="ypm-coverage-bar"><i style="width:' . esc_attr((string) $percentage) . '%"></i></span></div></td>';

    echo '<td>';
    self::render_compact_locales($seed, $source, $translations, $active, $target, $provenance);
    echo '</td><td class="ypm-col-action"><div class="ypm-row-actions">';

    if ($target !== '') {
      if ($target_id > 0) {
        $edit_url = get_edit_post_link($target_id, '');
        if ($edit_url) echo '<a class="button button-small button-primary" href="' . esc_url($edit_url) . '">' . esc_html__('Edit target', 'yablonsky-multilingual') . '</a>';
      } elseif ($seed_post instanceof \WP_Post) {
        $create_url = AdminContent::create_translation_url($seed_post, $target);
        if ($create_url !== '') echo '<a class="button button-small" href="' . esc_url($create_url) . '">' . esc_html__('Create target', 'yablonsky-multilingual') . '</a>';
        if ($ai_ready && $target !== $source) AIAdmin::render_content_draft_button($seed, $target, __('AI draft', 'yablonsky-multilingual'), 'button button-small button-primary ymulti-ai-cta');
        if (class_exists('Yablonsky\Multilingual\TranslationJobs') && current_user_can(TranslationJobs::CAP_MANAGE) && $target !== $source) {
          echo '<a class="button button-small" href="' . esc_url(TranslationJobs::create_job_url($seed, $target)) . '">' . esc_html__('Assign job', 'yablonsky-multilingual') . '</a>';
        }
      }
    } else {
      $next_missing = '';
      $next_missing_code = '';
      foreach ($active as $code) {
        if (isset($translations[$code])) continue;
        if ($seed_post instanceof \WP_Post) {
          $next_missing = AdminContent::create_translation_url($seed_post, $code);
          $next_missing_code = $code;
        }
        if ($next_missing !== '') break;
      }
      if ($next_missing !== '') echo '<a class="button button-small" href="' . esc_url($next_missing) . '">' . esc_html__('Create next', 'yablonsky-multilingual') . '</a>';
      if ($ai_ready && $next_missing_code !== '') AIAdmin::render_content_draft_button($seed, $next_missing_code, __('AI draft', 'yablonsky-multilingual'), 'button button-small button-primary ymulti-ai-cta');
    }

    echo '<a class="button button-small ypm-family-more" data-post-id="' . esc_attr((string) $seed) . '" href="' . esc_url(AdminContent::family_detail_url($seed)) . '">' . esc_html__('Open family', 'yablonsky-multilingual') . '</a></div></td></tr>';
  }

  /** @param array<string,int> $translations @param string[] $active @param array<string,string> $provenance */
  private static function render_compact_locales(int $seed, string $source, array $translations, array $active, string $target, array $provenance = []): void {
    $visible = [];
    $append = static function (string $code) use (&$visible): void {
      if ($code !== '' && !in_array($code, $visible, true)) $visible[] = $code;
    };
    $max_visible = $target !== '' ? 4 : 5;
    $append($source);
    if ($target !== '') $append($target);
    foreach ($active as $code) {
      if (isset($translations[$code])) $append($code);
      if (count($visible) >= $max_visible) break;
    }
    foreach ($active as $code) {
      $append($code);
      if (count($visible) >= $max_visible) break;
    }

    echo '<div class="ypm-family" aria-label="' . esc_attr__('Translation family', 'yablonsky-multilingual') . '">';
    foreach ($visible as $code) {
      $exists = isset($translations[$code]);
      $label = Languages::display_name($code) . ' (' . Languages::display_code($code) . ')';
      if ($exists) {
        $url = get_edit_post_link((int) $translations[$code], '');
        $class = 'ypm-family-chip' . ($code === $source ? ' is-current' : '');
        $origin = isset($provenance[$code]) ? (string) $provenance[$code] : 'native';
        if ($origin !== 'native' && $origin !== 'native-fallback') $class .= ' is-bridged';
        if ($url) echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '" title="' . esc_attr(__('Edit', 'yablonsky-multilingual') . ' ' . $label) . '">' . esc_html(Languages::display_code($code)) . '</a>';
        else echo '<span class="' . esc_attr($class) . '" title="' . esc_attr($label) . '">' . esc_html(Languages::display_code($code)) . '</span>';
      } else {
        $post = get_post($seed);
        $url = $post instanceof \WP_Post ? AdminContent::create_translation_url($post, $code) : '';
        echo '<a class="ypm-family-chip is-missing" href="' . esc_url($url) . '" title="' . esc_attr(__('Create translation', 'yablonsky-multilingual') . ': ' . $label) . '">+' . esc_html(Languages::display_code($code)) . '</a>';
      }
    }
    $hidden = max(0, count($active) - count(array_intersect($visible, $active)));
    if ($hidden > 0) {
      /* translators: %d: number of additional locales hidden from the compact row. */
      $title = sprintf(__('Show %d more locales', 'yablonsky-multilingual'), $hidden);
      echo '<a class="ypm-family-chip ypm-family-more is-overflow" data-post-id="' . esc_attr((string) $seed) . '" href="' . esc_url(AdminContent::family_detail_url($seed)) . '" title="' . esc_attr($title) . '">+' . esc_html((string) $hidden) . '</a>';
    }
    echo '</div>';
  }


  /** @param array<int,array<string,mixed>> $families */
  private static function render_professional_jobs_launcher(array $families, array $filters): void {
    if (!class_exists('Yablonsky\\Multilingual\\TranslationJobs') || !current_user_can(TranslationJobs::CAP_MANAGE)) return;
    $target = sanitize_text_field((string) ($filters['target'] ?? ''));
    if ($target === '' || !Languages::is_active($target)) return;
    $translators = TranslationJobs::translator_users();
    echo '<div class="ypm-ai-batch-launcher ypm-ai-batch-launcher--workspace"><div class="ypm-ai-batch-launcher__copy"><span class="ypm-ai-batch-launcher__icon dashicons dashicons-groups" aria-hidden="true"></span><div><strong>' . esc_html__('Professional jobs for this page', 'yablonsky-multilingual') . '</strong><span>' . esc_html__('Create durable human translation assignments for the visible families in the selected target language. Existing translations and active jobs are skipped safely.', 'yablonsky-multilingual') . '</span></div></div>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ymulti-bulk-form" data-ymulti-bulk-form="workspace">';
    wp_nonce_field('ymulti_translation_jobs_create_page', 'ymulti_translation_job_nonce');
    echo '<input type="hidden" name="action" value="ymulti_translation_jobs_create_page"><input type="hidden" name="target_locale" value="' . esc_attr($target) . '"><div data-ymulti-selected-inputs></div>';
    foreach ($families as $family) echo '<input type="hidden" name="page_ids[]" value="' . esc_attr((string) (int) $family['seed']) . '">';
    echo '<label><span class="screen-reader-text">' . esc_html__('Assignee', 'yablonsky-multilingual') . '</span><select name="assignee_user_id"><option value="0">' . esc_html__('Unassigned', 'yablonsky-multilingual') . '</option>';
    foreach ($translators as $user) echo '<option value="' . esc_attr((string) $user->ID) . '">' . esc_html($user->display_name) . '</option>';
    echo '</select></label><div class="ymulti-bulk-actions"><button class="button button-primary" name="job_mode" value="selected" data-ymulti-bulk-selected disabled>' . esc_html__('Create jobs for selected', 'yablonsky-multilingual') . ' <span data-ymulti-selected-count>(0)</span></button><button class="button" name="job_mode" value="page">' . esc_html(sprintf(
      /* translators: %d: visible translation families. */
      __('Create jobs for this page (%d)', 'yablonsky-multilingual'),
      count($families)
    )) . '</button></div></form></div>';
  }

  /** @param array<int,array<string,mixed>> $families @param string[] $active */
  private static function render_ai_batch_launcher(array $families, array $filters, array $active, bool $ai_ready, int $total): void {
    echo '<div class="ypm-ai-batch-launcher ypm-ai-batch-launcher--workspace ymulti-bulk-launcher" data-ymulti-bulk-scope="workspace"><div class="ypm-ai-batch-launcher__copy"><span class="ypm-ai-batch-launcher__icon dashicons dashicons-translation" aria-hidden="true"></span><div><strong>' . esc_html__('AI translation actions', 'yablonsky-multilingual') . '</strong><span>' . esc_html__('Choose individual rows, the visible page, or the complete filtered result. Every content translation is created as a review draft; publishing remains human-controlled.', 'yablonsky-multilingual') . '</span></div></div>';
    $capacity = AITranslation::text_capacity_profile();
    $capacity_model = sanitize_text_field((string) ($capacity['model'] ?? ''));
    $safe_tokens = max(1, absint($capacity['safe_stage_input_tokens'] ?? 6000));
    echo '<aside class="ymulti-ai-batch-plan ymulti-ai-batch-plan--compact" aria-label="' . esc_attr__('Adaptive AI staging', 'yablonsky-multilingual') . '"><span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span><strong>' . esc_html__('Adaptive staging', 'yablonsky-multilingual') . '</strong><span>' . esc_html(sprintf(
      /* translators: 1: selected text model, 2: conservative estimated input-token budget per remote stage. */
      __('%1$s · about %2$s input tokens per safe stage · long items continue through validated Translation Memory stages.', 'yablonsky-multilingual'),
      $capacity_model !== '' ? $capacity_model : __('Workspace default model', 'yablonsky-multilingual'),
      number_format_i18n($safe_tokens)
    )) . '</span></aside>';
    if (!AIJobs::is_running()) {
      $available_attr = $ai_ready ? '1' : '0';
      $setup = AITranslation::text_setup_status();
      $unavailable_message = !$ai_ready ? sanitize_text_field((string) ($setup['message'] ?? '')) : '';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ymulti-bulk-form" data-ymulti-bulk-form="workspace" data-ymulti-bulk-status="1" data-ymulti-bulk-available="' . esc_attr($available_attr) . '" data-ymulti-bulk-unavailable-message="' . esc_attr($unavailable_message) . '">';
      wp_nonce_field('ymulti_workspace_ai_bulk', 'ymulti_workspace_ai_bulk_nonce');
      echo '<input type="hidden" name="action" value="ymulti_workspace_ai_bulk"><div data-ymulti-selected-inputs></div>';
      foreach ($families as $family) echo '<input type="hidden" name="page_ids[]" value="' . esc_attr((string) (int) $family['seed']) . '">';
      foreach (['search' => 's', 'post_type' => 'post_type_filter', 'status' => 'post_status_filter', 'term' => 'term_filter', 'source' => 'source_locale', 'target' => 'target_locale', 'target_state' => 'target_state', 'coverage' => 'coverage'] as $key => $field) {
        echo '<input type="hidden" name="filter_' . esc_attr($field) . '" value="' . esc_attr((string) ($filters[$key] ?? '')) . '">';
      }
      $fixed_target = sanitize_text_field((string) ($filters['target'] ?? ''));
      if ($fixed_target !== '' && in_array($fixed_target, $active, true)) {
        echo '<input type="hidden" name="target_locale" value="' . esc_attr($fixed_target) . '"><div class="ymulti-bulk-target"><span>' . esc_html__('Translate to', 'yablonsky-multilingual') . '</span><strong>' . esc_html(Languages::display_name($fixed_target) . ' · ' . Languages::display_code($fixed_target)) . '</strong></div>';
      } else {
        echo '<label class="ymulti-bulk-target"><span>' . esc_html__('Translate to', 'yablonsky-multilingual') . '</span><select name="target_locale" required><option value="">' . esc_html__('Target locale…', 'yablonsky-multilingual') . '</option>';
        foreach ($active as $code) echo '<option value="' . esc_attr($code) . '">' . esc_html(Languages::display_name($code) . ' (' . Languages::display_code($code) . ')') . '</option>';
        echo '</select></label>';
      }
      echo '<div class="ymulti-bulk-action-grid">';
      echo '<div class="ymulti-bulk-action-card is-selected"><strong>' . esc_html__('Selected rows', 'yablonsky-multilingual') . '</strong><span>' . esc_html__('Translate only the rows checked in the table below.', 'yablonsky-multilingual') . '</span><button class="button button-primary" name="bulk_mode" value="selected" data-ymulti-bulk-selected disabled>' . esc_html__('Translate selected', 'yablonsky-multilingual') . ' <span data-ymulti-selected-count>(0)</span></button></div>';
      echo '<div class="ymulti-bulk-action-card"><strong>' . esc_html__('Current page', 'yablonsky-multilingual') . '</strong><span>' . esc_html(sprintf(
        /* translators: %d: visible translation family count. */
        __('Translate all %d rows visible on this page.', 'yablonsky-multilingual'), count($families)
      )) . '</span><button class="button" name="bulk_mode" value="page"';
      if (!$ai_ready) echo ' disabled aria-disabled="true"';
      echo '>' . esc_html(sprintf(
        /* translators: %d: visible translation family count. */
        __('Translate this page (%d)', 'yablonsky-multilingual'), count($families)
      )) . '</button></div>';
      echo '<div class="ymulti-bulk-action-card is-all"><strong>' . esc_html__('Entire filtered scope', 'yablonsky-multilingual') . '</strong><span>' . esc_html(sprintf(
        /* translators: %d: total matching translation families. */
        __('Queue all %d families matching the current filters.', 'yablonsky-multilingual'), max(0, $total)
      )) . '</span><label class="ymulti-bulk-confirm"><input type="checkbox" name="confirm_all" value="1" data-ymulti-bulk-confirm-all';
      echo '> <span>' . esc_html__('I understand this can consume AI credits.', 'yablonsky-multilingual') . '</span></label><button class="button" name="bulk_mode" value="all" data-ymulti-bulk-all-action disabled>' . esc_html(sprintf(
        /* translators: %d: total matching translation families. */
        __('Translate all matching (%d)', 'yablonsky-multilingual'), max(0, $total)
      )) . '</button></div></div></form>';
    } else {
      echo '<span class="ypm-status-badge ypm-status-badge--running">' . esc_html__('Batch running', 'yablonsky-multilingual') . '</span>';
    }
    echo '</div>';
  }

  public static function handle_ai_bulk(): void {
    if (!current_user_can('edit_posts')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ymulti_workspace_ai_bulk', 'ymulti_workspace_ai_bulk_nonce');
    if (!class_exists('Yablonsky\\Multilingual\\AIJobs') || !class_exists('Yablonsky\\Multilingual\\AITranslation')) wp_die(esc_html__('AI translation queue setup required. Reload YMulti and review System if this state persists.', 'yablonsky-multilingual'));
    if (!AITranslation::current_user_can_compute() || !AITranslation::ready()) wp_die(esc_html__('Verify Yablonsky AI before starting a translation batch.', 'yablonsky-multilingual'));

    $mode_raw = filter_input(INPUT_POST, 'bulk_mode', FILTER_UNSAFE_RAW);
    $target_raw = filter_input(INPUT_POST, 'target_locale', FILTER_UNSAFE_RAW);
    $mode = is_string($mode_raw) ? sanitize_key($mode_raw) : 'selected';
    $target = is_string($target_raw) ? sanitize_text_field($target_raw) : '';
    if (!in_array($mode, ['selected', 'page', 'all'], true) || !Languages::is_active($target)) wp_die(esc_html__('Invalid bulk translation request.', 'yablonsky-multilingual'));
    $confirm_all = (int) filter_input(INPUT_POST, 'confirm_all', FILTER_VALIDATE_INT);
    if ($mode === 'all' && $confirm_all !== 1) wp_die(esc_html__('Confirm the complete filtered-scope AI campaign before starting it.', 'yablonsky-multilingual'));

    $ids = [];
    if ($mode === 'selected' || $mode === 'page') {
      $field = $mode === 'selected' ? 'source_ids' : 'page_ids';
      $posted = filter_input(INPUT_POST, $field, FILTER_SANITIZE_NUMBER_INT, FILTER_REQUIRE_ARRAY);
      if (!is_array($posted)) $posted = [];
      foreach ($posted as $value) { $id = absint($value); if ($id > 0 && current_user_can('edit_post', $id) && !in_array($id, $ids, true)) $ids[] = $id; }
    } else {
      $active = Languages::active_codes();
      $filters = self::bulk_filters_from_post($active);
      // Target-state semantics must follow the locale this AI campaign will actually create.
      $filters['target'] = $target;
      $ids = self::matching_seed_ids($filters, $active, 10000);
    }
    $result = AIJobs::start_job($ids, $target, get_current_user_id());
    if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()));
    wp_safe_redirect(admin_url('admin.php?page=ypm-translations&ai_batch_started=1'));
    exit;
  }

  /** @param string[] $active */
  private static function bulk_filters_from_post(array $active): array {
    $map = ['search'=>'filter_s','post_type'=>'filter_post_type_filter','status'=>'filter_post_status_filter','term'=>'filter_term_filter','source'=>'filter_source_locale','target'=>'filter_target_locale','target_state'=>'filter_target_state','coverage'=>'filter_coverage'];
    $filters = self::empty_filters();
    foreach ($map as $key => $field) {
      $raw = filter_input(INPUT_POST, $field, FILTER_UNSAFE_RAW);
      $value = is_string($raw) ? sanitize_text_field(wp_unslash($raw)) : '';
      $filters[$key] = $value;
    }
    $filters['post_type'] = sanitize_key((string) $filters['post_type']);
    $filters['status'] = sanitize_key((string) $filters['status']);
    $filters['target_state'] = sanitize_key((string) $filters['target_state']);
    $filters['coverage'] = sanitize_key((string) $filters['coverage']);
    if ($filters['source'] !== '' && !in_array($filters['source'], $active, true)) $filters['source'] = '';
    if ($filters['target'] !== '' && !in_array($filters['target'], $active, true)) $filters['target'] = '';
    if (!in_array($filters['coverage'], ['all','open','complete'], true)) $filters['coverage'] = 'all';
    if (!in_array($filters['target_state'], ['all','missing','present'], true)) $filters['target_state'] = 'all';
    return $filters;
  }

  /** @param string[] $active @return int[] */
  private static function matching_seed_ids(array $filters, array $active, int $limit): array {
    $ids = [];
    $offset = 0;
    $chunk = 100;
    while (count($ids) < $limit) {
      $rows = self::matching_family_rows($filters, $active, min($chunk, $limit - count($ids)), $offset);
      if (!$rows) break;
      $families = self::hydrate_families($rows, $filters, $active);
      foreach ($families as $family) {
        $id = absint($family['seed'] ?? 0);
        if ($id > 0 && current_user_can('edit_post', $id) && !in_array($id, $ids, true)) $ids[] = $id;
        if (count($ids) >= $limit) break;
      }
      $offset += count($rows);
      if (count($rows) < $chunk) break;
    }
    return $ids;
  }

  private static function workspace_url(array $filters, int $page_size, int $paged = 1): string {
    $args = ['page' => 'ypm-translations', 'per_page' => $page_size];
    if ($filters['search'] !== '') $args['s'] = $filters['search'];
    if ($filters['post_type'] !== '') $args['post_type_filter'] = $filters['post_type'];
    if ($filters['status'] !== '') $args['post_status_filter'] = $filters['status'];
    if ($filters['term'] !== '') $args['term_filter'] = $filters['term'];
    if ($filters['source'] !== '') $args['source_locale'] = $filters['source'];
    if ($filters['target'] !== '') $args['target_locale'] = $filters['target'];
    if ($filters['coverage'] !== 'all') $args['coverage'] = $filters['coverage'];
    if ($filters['target_state'] !== 'all') $args['target_state'] = $filters['target_state'];
    if ($paged > 1) $args['paged'] = $paged;
    return add_query_arg($args, admin_url('admin.php'));
  }

  private static function render_pagination(array $filters, int $page_size, int $paged, int $pages, int $total, int $offset, int $visible): void {
    $start = $total > 0 && $visible > 0 ? $offset + 1 : 0;
    $end = $visible > 0 ? min($total, $offset + $visible) : 0;
    echo '<div class="ypm-pagination"><span>' . esc_html(sprintf(
      /* translators: 1: first visible result, 2: last visible result, 3: total matching results. */
      __('Showing %1$d–%2$d of %3$d', 'yablonsky-multilingual'),
      $start,
      $end,
      $total
    )) . '</span>';
    Admin::render_server_page_size([
      'page' => 'ypm-translations',
      's' => (string) ($filters['search'] ?? ''),
      'post_type_filter' => (string) ($filters['post_type'] ?? ''),
      'post_status_filter' => (string) ($filters['status'] ?? ''),
      'term_filter' => (string) ($filters['term'] ?? ''),
      'source_locale' => (string) ($filters['source'] ?? ''),
      'target_locale' => (string) ($filters['target'] ?? ''),
      'target_state' => (string) ($filters['target_state'] ?? ''),
      'coverage' => (string) ($filters['coverage'] ?? ''),
    ], $page_size, self::PAGE_SIZES);
    if ($pages > 1) {
      $base = self::workspace_url($filters, $page_size, 1);
      $links = paginate_links([
        'base' => add_query_arg('paged', '%#%', $base),
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
  }
}
