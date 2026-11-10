<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Durable, bounded, review-first AI metadata suggestions for Media Localization. */
final class MediaAIJobs {
  public const OPT_JOB = 'ymulti_ai_media_job_v3';
  private const HOOK = 'yablonsky_multilingual_ai_media_job_tick';
  private const LOCK = 'ymulti_ai_media_job_lock_v3';
  private const SCAN_PAGE_SIZE = 50;
  private const SCAN_BUDGET_PER_TICK = 100;
  private const MAX_RETRIES_PER_IMAGE = 2;

  public static function init(): void {
    add_action(self::HOOK, [__CLASS__, 'cron_tick']);
    if (is_admin()) {
      add_action('admin_post_ymulti_ai_media_job_start', [__CLASS__, 'handle_start']);
      add_action('admin_post_ymulti_ai_media_job_tick', [__CLASS__, 'handle_tick']);
      add_action('admin_post_ymulti_ai_media_job_cancel', [__CLASS__, 'handle_cancel']);
      add_action('admin_post_ymulti_ai_media_job_resume', [__CLASS__, 'handle_resume']);
      add_action('admin_post_ymulti_ai_media_job_dismiss', [__CLASS__, 'handle_dismiss']);
      add_action('wp_ajax_ymulti_ai_media_job_status', [__CLASS__, 'handle_status_ajax']);
    }
  }

  /** @return array<string,mixed> */
  public static function job(): array {
    $job = get_option(self::OPT_JOB, []);
    return is_array($job) ? $job : [];
  }

  public static function render_panel(
    string $source_locale,
    string $locale,
    string $search,
    int $total_scope = 0,
    int $visible_count = 0,
    int $per_page = 25,
    int $paged = 1
  ): void {
    if ($locale === '' || !Languages::is_active($locale)) return;

    $job = self::job();
    $job_status = sanitize_key((string)($job['status'] ?? ''));
    $same = self::can_control_job()
      && (string)($job['source_locale'] ?? '') === $source_locale
      && (string)($job['target_locale'] ?? '') === $locale
      && (string)($job['search'] ?? '') === $search;
    $status = $same ? $job_status : '';
    $stats = $same && isset($job['stats']) && is_array($job['stats']) ? $job['stats'] : [];
    $mode = $same ? sanitize_key((string)($job['mode'] ?? '')) : '';
    $setup = AITranslation::vision_setup_status();
    $scope_total = $same ? max(0, (int)($job['total_scope'] ?? $total_scope)) : max(0, $total_scope);
    $job_total = $same ? max(0, (int)($job['job_total'] ?? $scope_total)) : 0;
    $scanned = max(0, (int)($stats['scanned'] ?? 0));
    $prepared = max(0, (int)($stats['prepared'] ?? 0));
    $failed = max(0, (int)($stats['failed'] ?? 0));
    $skipped = max(0, (int)($stats['skipped'] ?? 0));
    $retried = max(0, (int)($stats['retried'] ?? 0));
    $goal = max(1, $job_total ?: $scope_total);
    $percent = $status === 'completed' ? 100 : (int) floor((min($goal, $scanned) / $goal) * 100);
    if ($status === 'running' && $percent >= 100) $percent = 99;
    $runner = self::runner_label();

    if ($status === 'running') self::ensure_scheduled();

    echo '<section class="ypm-card ypm-card--standalone ymulti-media-ai-bulk" data-ymulti-media-job-panel data-job-status="' . esc_attr($status) . '" data-job-nonce="' . esc_attr(wp_create_nonce('ymulti_ai_media_job_status')) . '">';
    echo '<div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('AI bulk media metadata', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Prepare review drafts in a durable queue', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('Choose the visible page for a predictable 25/50/100-item batch, or queue the entire filtered result. The queue runs server-side one image at a time, keeps progress across browser sessions, and stores metadata as review drafts for human approval.', 'yablonsky-multilingual') . '</p></div>';
    echo wp_kses_post(Admin::status_badge(
      $status === 'running' ? 'running' : ($status === 'completed' ? 'pass' : ($status === 'paused' ? 'attention' : 'neutral')),
      $status === 'running' ? __('Running', 'yablonsky-multilingual') : ($status === 'completed' ? __('Review ready', 'yablonsky-multilingual') : ($status === 'paused' ? __('Paused', 'yablonsky-multilingual') : __('Idle', 'yablonsky-multilingual')))
    ));
    echo '</div>';

    if ($status === 'completed' && $same && $job) {
      echo '<div class="ymulti-media-job-complete"><div><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><div><strong>' . esc_html__('Last campaign complete', 'yablonsky-multilingual') . '</strong><span>' . esc_html(sprintf(
        /* translators: 1: prepared count, 2: skipped count, 3: failed count. */
        __('%1$d drafts prepared · %2$d skipped · %3$d failed. Review rows below or start another bounded campaign.', 'yablonsky-multilingual'),
        $prepared,
        $skipped,
        $failed
      )) . '</span></div></div><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('ymulti_ai_media_job_dismiss', 'ymulti_ai_media_job_nonce');
      echo '<input type="hidden" name="action" value="ymulti_ai_media_job_dismiss"><button class="button">' . esc_html__('Dismiss result', 'yablonsky-multilingual') . '</button></form></div>';
    } else {
      echo '<div class="ymulti-media-bulk-summary">';
      echo '<div><span>' . esc_html__('Filtered scope', 'yablonsky-multilingual') . '</span><strong>' . esc_html((string)$scope_total) . '</strong><small>' . esc_html__('matching images across all pages', 'yablonsky-multilingual') . '</small></div>';
      echo '<div><span>' . esc_html__('Visible page', 'yablonsky-multilingual') . '</span><strong>' . esc_html((string)$visible_count) . '</strong><small>' . esc_html(sprintf(
        /* translators: 1: current page number, 2: configured items per page. */
        __('page %1$d · up to %2$d items', 'yablonsky-multilingual'),
        max(1, $paged),
        max(1, $per_page)
      )) . '</small></div>';
      echo '<div><span>' . esc_html__('Source', 'yablonsky-multilingual') . '</span><strong>' . esc_html($source_locale !== '' ? Languages::display_code($source_locale) : '—') . '</strong><small>' . esc_html($source_locale !== '' ? Languages::display_name($source_locale) : __('Any source language', 'yablonsky-multilingual')) . '</small></div>';
      echo '<div><span>' . esc_html__('Target', 'yablonsky-multilingual') . '</span><strong>' . esc_html(Languages::display_code($locale)) . '</strong><small>' . esc_html(Languages::display_name($locale)) . '</small></div>';
      echo '<div><span>' . esc_html__('Queue runner', 'yablonsky-multilingual') . '</span><strong>' . esc_html($runner) . '</strong><small>' . esc_html__('browser may be closed after the campaign starts', 'yablonsky-multilingual') . '</small></div>';
      echo '</div>';
    }

    if ($same && $job && $status !== 'completed') {
      $mode_label = $mode === 'page' ? __('Current page', 'yablonsky-multilingual') : __('All matching', 'yablonsky-multilingual');
      echo '<div class="ymulti-media-job-progress" data-ymulti-job-progress>';
      echo '<div class="ypm-progress__meta"><strong data-ymulti-job-progress-label>' . esc_html(sprintf(
        /* translators: %d: progress percentage. */
        __('%d%% complete', 'yablonsky-multilingual'),
        $percent
      )) . '</strong><span data-ymulti-job-progress-copy>' . esc_html(sprintf(
        /* translators: 1: scanned count, 2: campaign total, 3: prepared count, 4: skipped count, 5: failed count. */
        __('Scanned %1$d/%2$d · prepared %3$d · skipped %4$d · failed %5$d', 'yablonsky-multilingual'),
        $scanned,
        $job_total,
        $prepared,
        $skipped,
        $failed
      )) . '</span></div>';
      echo '<div class="ypm-progress__rail"><div class="ypm-progress__fill" data-ymulti-job-progress-fill style="width:' . esc_attr((string)$percent) . '%"></div></div></div>';
      echo '<div class="ypm-metrics ypm-metrics--4">';
      Admin::render_metric(__('Campaign', 'yablonsky-multilingual'), $mode_label, __('Durable review-draft queue', 'yablonsky-multilingual'), 'neutral');
      Admin::render_metric(__('Prepared', 'yablonsky-multilingual'), (string)$prepared, __('Saved AI suggestions ready for review', 'yablonsky-multilingual'), $prepared ? 'pass' : 'neutral');
      Admin::render_metric(__('Skipped', 'yablonsky-multilingual'), (string)$skipped, __('Already localized / complete AI draft / unavailable', 'yablonsky-multilingual'), 'neutral');
      Admin::render_metric(__('Retries / failed', 'yablonsky-multilingual'), $retried . ' / ' . $failed, __('Transient failures retry at most twice', 'yablonsky-multilingual'), $failed ? 'attention' : 'pass');
      echo '</div>';
    }

    echo '<div class="ymulti-media-bulk-actions">';
    if ($status === 'running') {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('ymulti_ai_media_job_tick', 'ymulti_ai_media_job_nonce');
      echo '<input type="hidden" name="action" value="ymulti_ai_media_job_tick"><button class="button">' . esc_html__('Process next image now', 'yablonsky-multilingual') . '</button></form>';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('ymulti_ai_media_job_cancel', 'ymulti_ai_media_job_nonce');
      echo '<input type="hidden" name="action" value="ymulti_ai_media_job_cancel"><button class="button">' . esc_html__('Cancel campaign', 'yablonsky-multilingual') . '</button></form>';
    } elseif ($status === 'paused' && !empty($setup['ready'])) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('ymulti_ai_media_job_resume', 'ymulti_ai_media_job_nonce');
      echo '<input type="hidden" name="action" value="ymulti_ai_media_job_resume"><button class="button button-primary">' . esc_html__('Resume campaign', 'yablonsky-multilingual') . '</button></form>';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('ymulti_ai_media_job_cancel', 'ymulti_ai_media_job_nonce');
      echo '<input type="hidden" name="action" value="ymulti_ai_media_job_cancel"><button class="button">' . esc_html__('Cancel campaign', 'yablonsky-multilingual') . '</button></form>';
    } elseif ($job_status === 'running' || $job_status === 'paused') {
      $other_source = sanitize_text_field((string)($job['source_locale'] ?? ''));
      $other_locale = sanitize_text_field((string)($job['target_locale'] ?? ''));
      $other_search = sanitize_text_field((string)($job['search'] ?? ''));
      $other_url = add_query_arg(['page' => 'ypm-media', 'source_locale' => $other_source, 'locale' => $other_locale, 's' => $other_search], admin_url('admin.php'));
      echo '<div class="ymulti-ai-setup-cta"><span>' . esc_html__('Another Media AI campaign is already active. Finish, resume or cancel it before starting a new one.', 'yablonsky-multilingual') . '</span><a class="button" href="' . esc_url($other_url) . '">' . esc_html__('Open active campaign', 'yablonsky-multilingual') . '</a></div>';
    } else {
      $ready = !empty($setup['ready']);
      $available_attr = $ready ? '1' : '0';
      $unavailable_message = !$ready ? sanitize_text_field((string) ($setup['message'] ?? '')) : '';
      echo '<div class="ymulti-media-bulk-run-grid" data-ymulti-bulk-scope="media">';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ymulti-media-bulk-run ymulti-media-bulk-run--selected" data-ymulti-bulk-form="media" data-ymulti-bulk-status="1" data-ymulti-bulk-available="' . esc_attr($available_attr) . '" data-ymulti-bulk-unavailable-message="' . esc_attr($unavailable_message) . '">';
      wp_nonce_field('ymulti_ai_media_job_start', 'ymulti_ai_media_job_nonce');
      echo '<input type="hidden" name="action" value="ymulti_ai_media_job_start"><input type="hidden" name="source_locale" value="' . esc_attr($source_locale) . '"><input type="hidden" name="target_locale" value="' . esc_attr($locale) . '"><input type="hidden" name="s" value="' . esc_attr($search) . '"><input type="hidden" name="mode" value="selected"><input type="hidden" name="paged" value="' . esc_attr((string)max(1, $paged)) . '"><input type="hidden" name="per_page" value="' . esc_attr((string)max(1, $per_page)) . '"><div data-ymulti-selected-inputs></div>';
      echo '<div><strong>' . esc_html__('Selected images', 'yablonsky-multilingual') . '</strong><span>' . esc_html__('Prepare Vision metadata drafts only for the checked rows above.', 'yablonsky-multilingual') . '</span></div><button class="button button-primary" data-ymulti-bulk-selected disabled>' . esc_html__('Prepare selected', 'yablonsky-multilingual') . ' <span data-ymulti-selected-count>(0)</span></button></form>';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ymulti-media-bulk-run">';
      wp_nonce_field('ymulti_ai_media_job_start', 'ymulti_ai_media_job_nonce');
      echo '<input type="hidden" name="action" value="ymulti_ai_media_job_start"><input type="hidden" name="source_locale" value="' . esc_attr($source_locale) . '"><input type="hidden" name="target_locale" value="' . esc_attr($locale) . '"><input type="hidden" name="s" value="' . esc_attr($search) . '"><input type="hidden" name="mode" value="page"><input type="hidden" name="paged" value="' . esc_attr((string)max(1, $paged)) . '"><input type="hidden" name="per_page" value="' . esc_attr((string)max(1, $per_page)) . '">';
      echo '<div><strong>' . esc_html__('Current page batch', 'yablonsky-multilingual') . '</strong><span>' . esc_html(sprintf(
        /* translators: 1: visible image count, 2: selected page-size limit. */
        __('Prepare the %1$d visible images. Batch size follows the Rows per page control below the table (%2$d).', 'yablonsky-multilingual'),
        max(0, $visible_count),
        max(1, $per_page)
      )) . '</span></div><button class="button"' . (!$ready || $visible_count <= 0 ? ' disabled aria-disabled="true"' : '') . '>' . esc_html(sprintf(
        /* translators: %d: number of images visible on the current page. */
        __('Prepare this page (%d)', 'yablonsky-multilingual'),
        max(0, $visible_count)
      )) . '</button></form>';

      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ymulti-media-bulk-run ymulti-media-bulk-run--all" data-ymulti-media-available="' . esc_attr($available_attr) . '">';
      wp_nonce_field('ymulti_ai_media_job_start', 'ymulti_ai_media_job_nonce');
      echo '<input type="hidden" name="action" value="ymulti_ai_media_job_start"><input type="hidden" name="source_locale" value="' . esc_attr($source_locale) . '"><input type="hidden" name="target_locale" value="' . esc_attr($locale) . '"><input type="hidden" name="s" value="' . esc_attr($search) . '"><input type="hidden" name="mode" value="all"><input type="hidden" name="paged" value="' . esc_attr((string)max(1, $paged)) . '"><input type="hidden" name="per_page" value="' . esc_attr((string)max(1, $per_page)) . '">';
      echo '<div><strong>' . esc_html__('Entire filtered scope', 'yablonsky-multilingual') . '</strong><span>' . esc_html(sprintf(
        /* translators: %d: total number of images matching the current filters. */
        __('Queue up to %d matching images. Existing localizations and complete AI drafts are skipped, so actual Vision calls may be lower.', 'yablonsky-multilingual'),
        max(0, $scope_total)
      )) . '</span></div>';
      echo '<label class="ymulti-media-bulk-confirm"><input type="checkbox" name="confirm_all" value="1" data-ymulti-media-confirm-all';
      if (!$ready) echo ' disabled aria-disabled="true"';
      echo '> <span>' . esc_html(sprintf(
        /* translators: %d: total number of images matching the current filters. */
        __('I understand this may queue up to %d Vision drafts.', 'yablonsky-multilingual'),
        max(0, $scope_total)
      )) . '</span></label>';
      echo '<button class="button button-primary" data-ymulti-media-run-all disabled>' . esc_html(sprintf(
        /* translators: %d: total number of images matching the current filters. */
        __('Prepare all %d matching images', 'yablonsky-multilingual'),
        max(0, $scope_total)
      )) . '</button></form>';
      echo '</div>';
    }
    echo '</div><p class="ymulti-media-bulk-footnote">' . esc_html__('Page batches are easy to review against what you can see. Full-scope campaigns continue in the background and can be monitored here; complete saved drafts stay stable until you explicitly refresh an individual image.', 'yablonsky-multilingual') . '</p></section>';
  }

  public static function handle_start(): void {
    check_admin_referer('ymulti_ai_media_job_start', 'ymulti_ai_media_job_nonce');
    if (!current_user_can('upload_files') || !AITranslation::current_user_can_compute() || !AITranslation::vision_ready()) {
      wp_die(esc_html__('Media AI campaign setup required. Verify Vision readiness and AI compute permission, then start the campaign again.', 'yablonsky-multilingual'));
    }

    $existing = self::job();
    $existing_status = sanitize_key((string)($existing['status'] ?? ''));
    if (in_array($existing_status, ['running', 'paused'], true)) {
      wp_die(esc_html__('A Media AI campaign is already active. Finish or cancel it before starting another.', 'yablonsky-multilingual'));
    }

    $source_raw = filter_input(INPUT_POST, 'source_locale', FILTER_UNSAFE_RAW);
    $target_raw = filter_input(INPUT_POST, 'target_locale', FILTER_UNSAFE_RAW);
    $search_raw = filter_input(INPUT_POST, 's', FILTER_UNSAFE_RAW);
    $mode_raw = filter_input(INPUT_POST, 'mode', FILTER_UNSAFE_RAW);
    $paged_raw = filter_input(INPUT_POST, 'paged', FILTER_VALIDATE_INT);
    $per_page_raw = filter_input(INPUT_POST, 'per_page', FILTER_VALIDATE_INT);
    $confirm_all_raw = filter_input(INPUT_POST, 'confirm_all', FILTER_VALIDATE_INT);
    $selected_raw = filter_input(INPUT_POST, 'source_ids', FILTER_SANITIZE_NUMBER_INT, FILTER_REQUIRE_ARRAY);

    $source_locale = is_string($source_raw) ? sanitize_text_field($source_raw) : '';
    if ($source_locale !== '' && !Languages::is_active($source_locale)) $source_locale = '';
    $target = is_string($target_raw) ? sanitize_text_field($target_raw) : '';
    $search = is_string($search_raw) ? trim(sanitize_text_field($search_raw)) : '';
    $mode = is_string($mode_raw) ? sanitize_key($mode_raw) : 'page';
    $paged = max(1, (int)$paged_raw);
    $per_page = in_array((int)$per_page_raw, [25, 50, 100], true) ? (int)$per_page_raw : 25;

    if (!in_array($mode, ['selected', 'page', 'all'], true)) $mode = 'page';
    if (!Languages::is_active($target)) {
      wp_die(esc_html__('Choose an active target language.', 'yablonsky-multilingual'));
    }
    if ($source_locale !== '' && $source_locale === $target) {
      wp_die(esc_html__('Source and target languages must be different.', 'yablonsky-multilingual'));
    }
    if ($mode === 'all' && (int)$confirm_all_raw !== 1) {
      wp_die(esc_html__('Confirm the full filtered-scope campaign before starting it.', 'yablonsky-multilingual'));
    }

    $count_args = [
      'post_type' => 'attachment',
      'post_status' => 'inherit',
      'post_mime_type' => 'image',
      'posts_per_page' => 1,
      'paged' => 1,
      's' => $search,
      'orderby' => 'ID',
      'order' => 'ASC',
      'fields' => 'ids',
      'no_found_rows' => false,
    ];
    if ($source_locale !== '') $count_args = AdminContent::with_source_language($count_args, $source_locale); else $count_args = AdminContent::with_family_representative($count_args);
    $count_query = new \WP_Query($count_args);
    $total = max(0, (int)$count_query->found_posts);
    $page_ids = [];
    $selected_ids = [];
    if (is_array($selected_raw)) {
      foreach ($selected_raw as $value) {
        $id = absint($value);
        if ($id <= 0 || !current_user_can('edit_post', $id) || get_post_type($id) !== 'attachment') continue;
        if ($source_locale !== '' && RuntimeAuthority::effective_post_language($id) !== $source_locale) continue;
        if (!in_array($id, $selected_ids, true)) $selected_ids[] = $id;
      }
    }
    $job_total = $mode === 'selected' ? count($selected_ids) : $total;

    if ($mode === 'page') {
      $page_args = [
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'post_mime_type' => 'image',
        'posts_per_page' => $per_page,
        'paged' => $paged,
        's' => $search,
        'orderby' => 'ID',
        'order' => 'DESC',
        'fields' => 'ids',
        'no_found_rows' => true,
      ];
      if ($source_locale !== '') $page_args = AdminContent::with_source_language($page_args, $source_locale); else $page_args = AdminContent::with_family_representative($page_args);
      $page_query = new \WP_Query($page_args);
      $page_ids = is_array($page_query->posts) ? array_values(array_filter(array_map('absint', $page_query->posts))) : [];
      $job_total = count($page_ids);
    }

    $job = [
      'job_id' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('ymulti-media-', true),
      'status' => $job_total > 0 ? 'running' : 'completed',
      'mode' => $mode,
      'source_locale' => $source_locale,
      'target_locale' => $target,
      'search' => $search,
      'paged' => $paged,
      'per_page' => $per_page,
      'page_ids' => $page_ids,
      'selected_ids' => $selected_ids,
      'focus_attachment_id' => $mode === 'selected' && count($selected_ids) === 1 ? (int) $selected_ids[0] : 0,
      'cursor_index' => 0,
      'scan_page' => 1,
      'scan_offset' => 0,
      'pending_retry' => 0,
      'retry_attempts' => [],
      'total_scope' => $total,
      'job_total' => $job_total,
      'errors' => [],
      'stats' => ['scanned' => 0, 'prepared' => 0, 'skipped' => 0, 'failed' => 0, 'retried' => 0],
      'started_at' => gmdate('c'),
      'last_tick_at' => '',
      'started_by' => get_current_user_id(),
    ];

    update_option(self::OPT_JOB, $job, false);
    if ($job_total > 0) self::schedule();
    self::redirect($source_locale, $target, $search, $per_page, $paged);
  }

  public static function handle_tick(): void {
    check_admin_referer('ymulti_ai_media_job_tick', 'ymulti_ai_media_job_nonce');
    if (!current_user_can('upload_files') || !AITranslation::current_user_can_compute() || !self::can_control_job()) {
      wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    }
    self::process_one();
    $job = self::job();
    self::redirect((string)($job['source_locale'] ?? ''), (string)($job['target_locale'] ?? ''), (string)($job['search'] ?? ''), (int)($job['per_page'] ?? 25), (int)($job['paged'] ?? 1));
  }

  public static function handle_cancel(): void {
    check_admin_referer('ymulti_ai_media_job_cancel', 'ymulti_ai_media_job_nonce');
    if (!current_user_can('upload_files') || !AITranslation::current_user_can_compute() || !self::can_control_job()) {
      wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    }
    $job = self::job();
    $job['status'] = 'cancelled';
    $job['finished_at'] = gmdate('c');
    update_option(self::OPT_JOB, $job, false);
    self::unschedule();
    self::redirect((string)($job['source_locale'] ?? ''), (string)($job['target_locale'] ?? ''), (string)($job['search'] ?? ''), (int)($job['per_page'] ?? 25), (int)($job['paged'] ?? 1));
  }

  public static function handle_dismiss(): void {
    check_admin_referer('ymulti_ai_media_job_dismiss', 'ymulti_ai_media_job_nonce');
    if (!current_user_can('upload_files')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    $job = self::job();
    $status = sanitize_key((string)($job['status'] ?? ''));
    if (in_array($status, ['completed', 'cancelled'], true)) delete_option(self::OPT_JOB);
    $source_locale = sanitize_text_field((string)($job['source_locale'] ?? ''));
    $target = sanitize_text_field((string)($job['target_locale'] ?? ''));
    $search = sanitize_text_field((string)($job['search'] ?? ''));
    $per_page = max(1, (int)($job['per_page'] ?? 25));
    $paged = max(1, (int)($job['paged'] ?? 1));
    self::redirect($source_locale, $target, $search, $per_page, $paged);
  }

  public static function handle_resume(): void {
    check_admin_referer('ymulti_ai_media_job_resume', 'ymulti_ai_media_job_nonce');
    if (!current_user_can('upload_files') || !AITranslation::current_user_can_compute() || !self::can_control_job() || !AITranslation::vision_ready()) {
      wp_die(esc_html__('Campaign resume requires a resumable saved state.', 'yablonsky-multilingual'));
    }
    $job = self::job();
    if ((string)($job['status'] ?? '') !== 'paused') {
      wp_die(esc_html__('Only a paused campaign can be resumed.', 'yablonsky-multilingual'));
    }
    $job['status'] = 'running';
    $job['resumed_at'] = gmdate('c');
    update_option(self::OPT_JOB, $job, false);
    self::schedule();
    self::redirect((string)($job['source_locale'] ?? ''), (string)($job['target_locale'] ?? ''), (string)($job['search'] ?? ''), (int)($job['per_page'] ?? 25), (int)($job['paged'] ?? 1));
  }

  public static function handle_status_ajax(): void {
    check_ajax_referer('ymulti_ai_media_job_status', 'nonce');
    if (!current_user_can('upload_files') || !AITranslation::current_user_can_compute()) {
      wp_send_json_error(['message' => __('Forbidden', 'yablonsky-multilingual')], 403);
    }

    $job = self::job();
    if (!self::can_control_job()) {
      wp_send_json_error(['message' => __('Forbidden', 'yablonsky-multilingual')], 403);
    }
    $stats = isset($job['stats']) && is_array($job['stats']) ? $job['stats'] : [];
    $job_total = max(0, (int)($job['job_total'] ?? $job['total_scope'] ?? 0));
    $status = sanitize_key((string)($job['status'] ?? ''));
    $prepared = max(0, (int)($stats['prepared'] ?? 0));
    $failed = max(0, (int)($stats['failed'] ?? 0));
    $scanned = max(0, (int)($stats['scanned'] ?? 0));
    $skipped = max(0, (int)($stats['skipped'] ?? 0));
    $retried = max(0, (int)($stats['retried'] ?? 0));
    $goal = max(1, $job_total);
    $percent = $status === 'completed' ? 100 : (int) floor((min($goal, $scanned) / $goal) * 100);
    if ($status === 'running' && $percent >= 100) $percent = 99;

    if ($status === 'running') self::ensure_scheduled();

    $message = sprintf(
      /* translators: 1: scanned count, 2: campaign total, 3: prepared count, 4: skipped count, 5: failed count. */
      __('Scanned %1$d/%2$d · prepared %3$d · skipped %4$d · failed %5$d', 'yablonsky-multilingual'),
      $scanned,
      $job_total,
      $prepared,
      $skipped,
      $failed
    );
    if ($status === 'running' && $scanned >= $job_total && $job_total > 0) {
      $message = __('AI draft is saved. Finalizing the queue and refreshing the review row…', 'yablonsky-multilingual');
    }

    wp_send_json_success([
      'status' => $status,
      'percent' => $percent,
      'scanned' => $scanned,
      'total' => $job_total,
      'prepared' => $prepared,
      'skipped' => $skipped,
      'failed' => $failed,
      'retried' => $retried,
      'focus_attachment_id' => absint($job['focus_attachment_id'] ?? 0),
      'message' => $message,
    ]);
  }

  public static function cron_tick(): void {
    self::process_one();
  }

  private static function process_one(): void {
    if (get_transient(self::LOCK)) return;
    set_transient(self::LOCK, 1, 2 * MINUTE_IN_SECONDS);

    try {
      $job = self::job();
      if ((string)($job['status'] ?? '') !== 'running') return;

      if (!AITranslation::vision_ready()) {
        $job['status'] = 'paused';
        $job['errors'][] = ['message' => __('Vision setup is no longer ready.', 'yablonsky-multilingual'), 'at' => gmdate('c')];
        $job['errors'] = array_slice($job['errors'], -10);
        update_option(self::OPT_JOB, $job, false);
        return;
      }

      $source_locale = sanitize_text_field((string)($job['source_locale'] ?? ''));
      $target = sanitize_text_field((string)($job['target_locale'] ?? ''));
      $mode = sanitize_key((string)($job['mode'] ?? 'page'));
      if (!Languages::is_active($target)) {
        self::complete($job);
        return;
      }

      $job['last_tick_at'] = gmdate('c');
      $retry_id = absint($job['pending_retry'] ?? 0);
      if ($retry_id > 0) {
        self::attempt_image($job, $retry_id, true);
        return;
      }

      $budget = self::SCAN_BUDGET_PER_TICK;
      if ($mode === 'page' || $mode === 'selected') {
        self::process_page_scope($job, $target, $budget);
      } else {
        self::process_all_scope($job, $target, $budget);
      }
    } finally {
      delete_transient(self::LOCK);
    }
  }

  /** @param array<string,mixed> $job */
  private static function process_page_scope(array $job, string $target, int $budget): void {
    $mode = sanitize_key((string)($job['mode'] ?? 'page'));
    $source_key = $mode === 'selected' ? 'selected_ids' : 'page_ids';
    $ids = isset($job[$source_key]) && is_array($job[$source_key]) ? array_values(array_filter(array_map('absint', $job[$source_key]))) : [];
    $index = max(0, (int)($job['cursor_index'] ?? 0));
    if ($ids) update_meta_cache('post', $ids);

    while ($index < count($ids) && $budget > 0) {
      $id = absint($ids[$index]);
      $index++;
      $budget--;
      $job['cursor_index'] = $index;
      $job['stats']['scanned'] = (int)($job['stats']['scanned'] ?? 0) + 1;

      if (($source_locale = sanitize_text_field((string)($job['source_locale'] ?? ''))) !== '' && RuntimeAuthority::effective_post_language($id) !== $source_locale) {
        $job['stats']['skipped'] = (int)($job['stats']['skipped'] ?? 0) + 1;
        continue;
      }

      if (!self::is_candidate($id, $target)) {
        $job['stats']['skipped'] = (int)($job['stats']['skipped'] ?? 0) + 1;
        continue;
      }

      self::attempt_image($job, $id, false);
      return;
    }

    if ($index >= count($ids)) {
      self::complete($job);
      return;
    }

    update_option(self::OPT_JOB, $job, false);
    self::schedule();
  }

  /** @param array<string,mixed> $job */
  private static function process_all_scope(array $job, string $target, int $budget): void {
    $search = sanitize_text_field((string)($job['search'] ?? ''));
    $source_locale = sanitize_text_field((string)($job['source_locale'] ?? ''));
    $page = max(1, (int)($job['scan_page'] ?? 1));
    $offset = max(0, (int)($job['scan_offset'] ?? 0));

    while ($budget > 0) {
      $scan_args = [
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'post_mime_type' => 'image',
        'posts_per_page' => self::SCAN_PAGE_SIZE,
        'paged' => $page,
        's' => $search,
        'orderby' => 'ID',
        'order' => 'ASC',
        'fields' => 'ids',
        'no_found_rows' => true,
      ];
      if ($source_locale !== '') $scan_args = AdminContent::with_source_language($scan_args, $source_locale); else $scan_args = AdminContent::with_family_representative($scan_args);
      $q = new \WP_Query($scan_args);
      $ids = is_array($q->posts) ? array_values(array_map('absint', $q->posts)) : [];

      if (!$ids || $offset >= count($ids)) {
        if (count($ids) < self::SCAN_PAGE_SIZE) {
          self::complete($job);
          return;
        }
        $page++;
        $offset = 0;
        $job['scan_page'] = $page;
        $job['scan_offset'] = 0;
        continue;
      }

      update_meta_cache('post', $ids);
      for (; $offset < count($ids) && $budget > 0; $offset++) {
        $id = absint($ids[$offset]);
        $budget--;
        $job['scan_page'] = $page;
        $job['scan_offset'] = $offset + 1;
        $job['stats']['scanned'] = (int)($job['stats']['scanned'] ?? 0) + 1;

        if ($source_locale !== '' && RuntimeAuthority::effective_post_language($id) !== $source_locale) {
          $job['stats']['skipped'] = (int)($job['stats']['skipped'] ?? 0) + 1;
          continue;
        }

        if (!self::is_candidate($id, $target)) {
          $job['stats']['skipped'] = (int)($job['stats']['skipped'] ?? 0) + 1;
          continue;
        }

        self::attempt_image($job, $id, false);
        return;
      }

      if ($offset >= count($ids)) {
        if (count($ids) < self::SCAN_PAGE_SIZE) {
          self::complete($job);
          return;
        }
        $page++;
        $offset = 0;
        $job['scan_page'] = $page;
        $job['scan_offset'] = 0;
      }
    }

    update_option(self::OPT_JOB, $job, false);
    self::schedule();
  }

  private static function is_candidate(int $id, string $target): bool {
    if ($id <= 0 || RuntimeAuthority::effective_post_language($id) === $target) return false;
    $record = MediaLocalization::record($id, $target);
    $suggestion = MediaLocalization::ai_suggestion($id, $target);
    $localized = $record['title'] !== '' || $record['alt'] !== '' || $record['caption'] !== '' || $record['description'] !== '';
    $prepared = $suggestion['title'] !== '' && $suggestion['alt'] !== '' && $suggestion['caption'] !== '' && $suggestion['description'] !== '';
    return !$localized && !$prepared;
  }

  /** @param array<string,mixed> $job */
  private static function attempt_image(array $job, int $id, bool $is_retry): void {
    $source_locale = sanitize_text_field((string)($job['source_locale'] ?? ''));
    $target = sanitize_text_field((string)($job['target_locale'] ?? ''));
    if (($source_locale !== '' && RuntimeAuthority::effective_post_language($id) !== $source_locale) || !self::is_candidate($id, $target)) {
      $job['pending_retry'] = 0;
      if ($is_retry) $job['stats']['skipped'] = (int)($job['stats']['skipped'] ?? 0) + 1;
      update_option(self::OPT_JOB, $job, false);
      self::schedule();
      return;
    }

    $analysis = AITranslation::analyze_image_attachment($id, $target);
    if (is_wp_error($analysis)) {
      $attempts = isset($job['retry_attempts'][$id]) ? (int)$job['retry_attempts'][$id] : 0;
      $attempts++;
      $job['retry_attempts'][$id] = $attempts;
      $message = sanitize_text_field($analysis->get_error_message());

      if ($attempts <= self::MAX_RETRIES_PER_IMAGE) {
        $job['pending_retry'] = $id;
        $job['stats']['retried'] = (int)($job['stats']['retried'] ?? 0) + 1;
        $job['errors'][] = ['attachment_id' => $id, 'message' => $message, 'at' => gmdate('c'), 'retry' => $attempts];
      } else {
        $job['pending_retry'] = 0;
        $job['stats']['failed'] = (int)($job['stats']['failed'] ?? 0) + 1;
        $job['errors'][] = ['attachment_id' => $id, 'message' => $message, 'at' => gmdate('c'), 'retry' => $attempts, 'final' => true];
      }
      $job['errors'] = array_slice($job['errors'], -10);
      update_option(self::OPT_JOB, $job, false);
      self::schedule();
      return;
    }

    MediaLocalization::save_ai_suggestion($id, $target, $analysis);
    $job['pending_retry'] = 0;
    unset($job['retry_attempts'][$id]);
    $job['stats']['prepared'] = (int)($job['stats']['prepared'] ?? 0) + 1;
    update_option(self::OPT_JOB, $job, false);
    self::schedule();
  }

  private static function can_control_job(): bool {
    $job = self::job();
    $owner = absint($job['started_by'] ?? 0);
    return current_user_can('manage_options') || ($owner > 0 && $owner === get_current_user_id());
  }

  /** @param array<string,mixed> $job */
  private static function complete(array $job): void {
    $job['status'] = 'completed';
    $job['finished_at'] = gmdate('c');
    $job['pending_retry'] = 0;
    update_option(self::OPT_JOB, $job, false);
    self::unschedule();
  }

  private static function runner_label(): string {
    if (function_exists('as_schedule_single_action') && function_exists('as_next_scheduled_action')) return __('Action Scheduler', 'yablonsky-multilingual');
    return __('WordPress Cron', 'yablonsky-multilingual');
  }

  private static function ensure_scheduled(): void {
    $job = self::job();
    if ((string)($job['status'] ?? '') !== 'running') return;
    self::schedule();
  }

  private static function schedule(): void {
    if (function_exists('as_schedule_single_action') && function_exists('as_next_scheduled_action')) {
      $next = as_next_scheduled_action(self::HOOK, [], 'ymulti');
      if (!$next) as_schedule_single_action(time() + 5, self::HOOK, [], 'ymulti');
      return;
    }
    if (!wp_next_scheduled(self::HOOK)) wp_schedule_single_event(time() + 15, self::HOOK);
  }

  private static function unschedule(): void {
    if (function_exists('as_unschedule_all_actions')) as_unschedule_all_actions(self::HOOK, [], 'ymulti');
    wp_clear_scheduled_hook(self::HOOK);
  }

  private static function redirect(string $source_locale, string $target, string $search, int $per_page = 25, int $paged = 1): void {
    wp_safe_redirect(add_query_arg([
      'page' => 'ypm-media',
      'source_locale' => $source_locale,
      'locale' => $target,
      's' => $search,
      'per_page' => in_array($per_page, [25, 50, 100], true) ? $per_page : 25,
      'paged' => max(1, $paged),
    ], admin_url('admin.php')));
    exit;
  }
}
