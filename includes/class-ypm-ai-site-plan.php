<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Bounded site-level AI translation campaign.
 *
 * Planning is local and credit-free. Execution creates reviewed WordPress
 * drafts only, one task per tick, and never publishes automatically.
 */
final class AISitePlan {
  public const OPT_PLAN = 'ypm_ai_site_translation_plan_v1';
  private const LOCK = 'ypm_ai_site_translation_plan_lock_v1';
  private const HOOK = 'yablonsky_multilingual_ai_site_plan_tick';
  private const MAX_TASKS = 250;
  private const QUERY_BATCH = 100;
  private const SCAN_LIMIT = 2000;

  public static function init(): void {
    add_action(self::HOOK, [__CLASS__, 'cron_tick']);
    if (!is_admin()) return;
    add_action('admin_menu', [__CLASS__, 'register_page'], 25);
    add_action('admin_post_ypm_ai_site_plan_preview', [__CLASS__, 'handle_preview']);
    add_action('admin_post_ypm_ai_site_plan_start', [__CLASS__, 'handle_start']);
    add_action('admin_post_ypm_ai_site_plan_next', [__CLASS__, 'handle_next']);
    add_action('admin_post_ypm_ai_site_plan_pause', [__CLASS__, 'handle_pause']);
    add_action('admin_post_ypm_ai_site_plan_resume', [__CLASS__, 'handle_resume']);
    add_action('admin_post_ypm_ai_site_plan_cancel', [__CLASS__, 'handle_cancel']);
    add_action('admin_post_ypm_ai_site_plan_reset', [__CLASS__, 'handle_reset']);
  }

  /** @return array<string,mixed> */

  public static function register_page(): void {
    add_submenu_page(
      'ypm-multilingual',
      __('Site Translation Plan', 'yablonsky-multilingual'),
      __('Plan', 'yablonsky-multilingual'),
      'manage_options',
      'ypm-plan',
      [__CLASS__, 'render_page']
    );
  }

  public static function render_page(): void {
    if (!current_user_can('manage_options')) return;
    echo '<div class="wrap ypm-admin ypm-admin--ai">';
    Admin::render_shell_header(
      __('Site Translation Plan', 'yablonsky-multilingual'),
      __('Build a local inventory of translation gaps, then create reviewed AI drafts one bounded task at a time. Planning uses local data; AI credits begin with explicit campaign execution.', 'yablonsky-multilingual'),
      self::is_running() ? 'running' : 'neutral',
      self::is_running() ? __('Running', 'yablonsky-multilingual') : __('Local preview', 'yablonsky-multilingual')
    );
    $text_setup = AITranslation::text_setup_status();
    if (empty($text_setup['ready'])) AIAdmin::render_text_compute_gate($text_setup, admin_url('admin.php?page=ypm-plan'));
    self::render_panel();
    echo '</div>';
  }

  public static function plan(): array {
    $plan = get_option(self::OPT_PLAN, []);
    return is_array($plan) ? $plan : [];
  }

  public static function is_running(): bool {
    $plan = self::plan();
    return ($plan['status'] ?? '') === AITranslation::STATUS_RUNNING;
  }

  public static function render_panel(): void {
    if (!current_user_can('manage_options')) return;
    $active = Languages::active_codes();
    $plan = self::plan();
    $source = sanitize_text_field((string) ($plan['source_locale'] ?? Languages::default_code()));
    if (!in_array($source, $active, true)) $source = Languages::default_code();
    $targets = isset($plan['target_locales']) && is_array($plan['target_locales']) ? array_values(array_filter(array_map('sanitize_text_field', $plan['target_locales']))) : [];
    $selected_types = isset($plan['post_types']) && is_array($plan['post_types']) ? array_values(array_filter(array_map('sanitize_key', $plan['post_types']))) : self::default_post_types();
    $limit = max(25, min(self::MAX_TASKS, (int) ($plan['task_limit'] ?? 50)));
    $status = sanitize_key((string) ($plan['status'] ?? AITranslation::STATUS_DRAFT));
    $tasks = isset($plan['tasks']) && is_array($plan['tasks']) ? $plan['tasks'] : [];
    $task_count = count($tasks);

    $badge_state = 'neutral';
    $badge_label = __('Local preview', 'yablonsky-multilingual');
    if ($status === AITranslation::STATUS_RUNNING) { $badge_state = 'running'; $badge_label = __('Running', 'yablonsky-multilingual'); }
    elseif ($status === AITranslation::STATUS_PAUSED) { $badge_state = 'attention'; $badge_label = __('Paused', 'yablonsky-multilingual'); }
    elseif ($status === AITranslation::STATUS_COMPLETED) { $badge_state = 'pass'; $badge_label = __('Completed', 'yablonsky-multilingual'); }
    elseif ($status === AITranslation::STATUS_FAILED) { $badge_state = 'fail'; $badge_label = __('Failed', 'yablonsky-multilingual'); }
    elseif ($status === AITranslation::STATUS_CANCELLED) { $badge_state = 'neutral'; $badge_label = __('Cancelled', 'yablonsky-multilingual'); }
    elseif ($task_count > 0) { $badge_state = 'attention'; $badge_label = __('Ready to start', 'yablonsky-multilingual'); }

    echo '<section class="ypm-card ypm-card--standalone ypm-ai-site-plan" id="ypm-ai-site-plan">';
    echo '<div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Site campaign', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Site Translation Plan', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('Build a local inventory of translation gaps, then create reviewed AI drafts one bounded task at a time. Planning uses local data; AI credits begin with explicit campaign execution.', 'yablonsky-multilingual') . '</p></div>' . wp_kses_post(Admin::status_badge($badge_state, $badge_label)) . '</div>';

    if (count($active) < 2) {
      echo '<div class="ypm-empty-panel ypm-empty-panel--compact"><strong>' . esc_html__('Add another active locale first', 'yablonsky-multilingual') . '</strong><p>' . esc_html__('A site translation plan needs one source locale and at least one different target locale.', 'yablonsky-multilingual') . '</p></div></section>';
      return;
    }

    if ($status !== AITranslation::STATUS_RUNNING) self::render_builder($active, $source, $targets, $selected_types, $limit);
    if ($task_count > 0 || in_array($status, [AITranslation::STATUS_RUNNING, AITranslation::STATUS_PAUSED, AITranslation::STATUS_COMPLETED, AITranslation::STATUS_FAILED, AITranslation::STATUS_CANCELLED], true)) {
      self::render_plan_state($plan);
    }
    echo '</section>';
  }

  /** @param string[] $active @param string[] $targets @param string[] $selected_types */
  private static function render_builder(array $active, string $source, array $targets, array $selected_types, int $limit): void {
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ypm-site-plan-builder">';
    wp_nonce_field('ypm_ai_site_plan_preview', 'ypm_ai_plan_nonce');
    echo '<input type="hidden" name="action" value="ypm_ai_site_plan_preview">';
    echo '<div class="ypm-site-plan-grid ypm-site-plan-grid--compact">';

    echo '<div class="ypm-site-plan-column ypm-site-plan-column--source">';
    echo '<label class="ypm-site-plan-field ypm-site-plan-field--source"><span>' . esc_html__('Source locale', 'yablonsky-multilingual') . '</span><select name="source_locale" required>';
    foreach ($active as $code) echo '<option value="' . esc_attr($code) . '"' . selected($source, $code, false) . '>' . esc_html(Languages::display_name($code) . ' (' . Languages::display_code($code) . ')') . '</option>';
    echo '</select><small>' . esc_html__('Published and private content in the selected types forms the source inventory.', 'yablonsky-multilingual') . '</small></label>';

    $types = self::available_post_types();
    echo '<div class="ypm-site-plan-field ypm-site-plan-field--types"><span>' . esc_html__('Content types', 'yablonsky-multilingual') . '</span><div class="ypm-site-plan-checks">';
    foreach ($types as $post_type => $label) {
      echo '<label><input type="checkbox" name="post_types[]" value="' . esc_attr($post_type) . '"' . checked(in_array($post_type, $selected_types, true), true, false) . '> <span>' . esc_html($label) . '</span><code>' . esc_html($post_type) . '</code></label>';
    }
    echo '</div></div></div>';

    echo '<div class="ypm-site-plan-column ypm-site-plan-column--targets">';
    echo '<div class="ypm-site-plan-field ypm-site-plan-field--targets"><span>' . esc_html__('Target locales', 'yablonsky-multilingual') . '</span><div class="ypm-site-plan-checks">';
    foreach ($active as $code) {
      if ($code === $source) continue;
      $checked = in_array($code, $targets, true) || (!$targets && $code !== $source);
      echo '<label><input type="checkbox" name="target_locales[]" value="' . esc_attr($code) . '"' . checked($checked, true, false) . '> <span>' . esc_html(Languages::display_name($code)) . '</span><code>' . esc_html(Languages::display_code($code)) . '</code></label>';
    }
    echo '</div></div>';

    echo '<div class="ypm-site-plan-runbar"><label class="ypm-site-plan-limit"><span>' . esc_html__('Maximum draft tasks', 'yablonsky-multilingual') . '</span><select name="task_limit">';
    foreach ([25, 50, 100, 250] as $candidate) echo '<option value="' . esc_attr((string) $candidate) . '"' . selected($limit, $candidate, false) . '>' . esc_html((string) $candidate) . '</option>';
    echo '</select></label><div class="ypm-site-plan-runbar__copy"><strong>' . esc_html__('Local plan preview', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Inventory planning uses local data. AI credits begin when you explicitly start execution after review.', 'yablonsky-multilingual') . '</small></div><button class="button button-primary">' . esc_html__('Build local plan preview', 'yablonsky-multilingual') . '</button></div>';
    echo '</div></div></form>';
  }

  /** @param array<string,mixed> $plan */
  private static function render_plan_state(array $plan): void {
    $status = sanitize_key((string) ($plan['status'] ?? AITranslation::STATUS_DRAFT));
    $tasks = isset($plan['tasks']) && is_array($plan['tasks']) ? $plan['tasks'] : [];
    $total = count($tasks);
    $cursor = min($total, max(0, (int) ($plan['cursor'] ?? 0)));
    $created = max(0, (int) ($plan['created'] ?? 0));
    $skipped = max(0, (int) ($plan['skipped'] ?? 0));
    $failed = max(0, (int) ($plan['failed'] ?? 0));
    $scanned = max(0, (int) ($plan['scanned'] ?? 0));
    $has_more = !empty($plan['has_more']);
    $credits = is_numeric($plan['credits_used'] ?? null) ? (float) $plan['credits_used'] : 0.0;
    $by_target = isset($plan['by_target']) && is_array($plan['by_target']) ? $plan['by_target'] : [];
    $by_type = isset($plan['by_type']) && is_array($plan['by_type']) ? $plan['by_type'] : [];

    echo '<div class="ypm-site-plan-summary">';
    echo '<div><span>' . esc_html__('Scanned', 'yablonsky-multilingual') . '</span><strong>' . esc_html((string) $scanned) . '</strong><small>' . esc_html__('source items', 'yablonsky-multilingual') . '</small></div>';
    echo '<div><span>' . esc_html__('Translation opportunities', 'yablonsky-multilingual') . '</span><strong>' . esc_html((string) $total) . '</strong><small>' . esc_html($has_more ? __('bounded preview · more may remain', 'yablonsky-multilingual') : __('within scanned scope', 'yablonsky-multilingual')) . '</small></div>';
    echo '<div><span>' . esc_html__('Progress', 'yablonsky-multilingual') . '</span><strong>' . esc_html((string) $cursor . '/' . (string) $total) . '</strong><small>' . esc_html(sprintf(
      /* translators: 1: created drafts, 2: skipped tasks, 3: failed tasks. */
      __('%1$d created · %2$d skipped · %3$d failed', 'yablonsky-multilingual'),
      $created,
      $skipped,
      $failed
    )) . '</small></div>';
    echo '<div><span>' . esc_html__('Credits used', 'yablonsky-multilingual') . '</span><strong>' . esc_html(rtrim(rtrim(number_format($credits, 4, '.', ''), '0'), '.') ?: '0') . '</strong><small>' . esc_html__('execution only', 'yablonsky-multilingual') . '</small></div>';
    echo '</div>';

    if ($by_target) {
      echo '<div class="ypm-site-plan-targets" aria-label="' . esc_attr__('Translation opportunities by target locale', 'yablonsky-multilingual') . '">';
      foreach ($by_target as $code => $count) {
        $code = sanitize_text_field((string) $code);
        if ($code === '' || !is_numeric($count)) continue;
        echo '<span><strong>' . esc_html(Languages::display_code($code)) . '</strong> ' . esc_html((string) (int) $count) . '</span>';
      }
      echo '</div>';
    }

    if ($by_type) {
      echo '<div class="ypm-site-plan-targets" aria-label="' . esc_attr__('Translation opportunities by content type', 'yablonsky-multilingual') . '">';
      foreach ($by_type as $post_type => $count) {
        $post_type = sanitize_key((string) $post_type);
        if ($post_type === '' || !is_numeric($count)) continue;
        $object = get_post_type_object($post_type);
        $label = $object && isset($object->labels->name) ? (string) $object->labels->name : $post_type;
        echo '<span><strong>' . esc_html($label) . '</strong> ' . esc_html((string) (int) $count) . '</span>';
      }
      echo '</div>';
    }

    if ($has_more && in_array($status, [AITranslation::STATUS_DRAFT, AITranslation::STATUS_COMPLETED], true)) {
      echo '<div class="ypm-callout ypm-callout--attention"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><div><strong>' . esc_html__('Bounded preview reached its limit', 'yablonsky-multilingual') . '</strong><p>' . esc_html__('This campaign intentionally stops at the selected task/scan bound. After reviewing these drafts, build another preview to continue remaining content.', 'yablonsky-multilingual') . '</p></div></div>';
    }

    echo '<div class="ypm-site-plan-controls">';
    if ($status === AITranslation::STATUS_DRAFT && $total > 0) {
      if (!AITranslation::ready()) {
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=ypm-system-status#ypm-ai-canary')) . '">' . esc_html__('Run the text-model canary before starting', 'yablonsky-multilingual') . '</a>';
      } elseif (class_exists('Yablonsky\\Multilingual\\AIJobs') && AIJobs::is_running()) {
        echo wp_kses_post(Admin::status_badge('attention', __('Current-page AI batch is running', 'yablonsky-multilingual')));
      } else {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ypm-site-plan-start">';
        wp_nonce_field('ypm_ai_site_plan_start', 'ypm_ai_plan_nonce');
        echo '<input type="hidden" name="action" value="ypm_ai_site_plan_start"><label><input type="checkbox" name="confirm" value="1" required> <span>' . esc_html(sprintf(
          /* translators: %d: number of AI draft tasks in the plan. */
          __('I understand that this may create up to %d review drafts and consume workspace credits.', 'yablonsky-multilingual'),
          $total
        )) . '</span></label><button class="button button-primary">' . esc_html__('Start reviewed AI campaign', 'yablonsky-multilingual') . '</button></form>';
      }
    } elseif ($status === AITranslation::STATUS_RUNNING) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('ypm_ai_site_plan_next', 'ypm_ai_plan_nonce');
      echo '<input type="hidden" name="action" value="ypm_ai_site_plan_next"><button class="button button-primary">' . esc_html__('Run next now', 'yablonsky-multilingual') . '</button></form>';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('ypm_ai_site_plan_pause', 'ypm_ai_plan_nonce');
      echo '<input type="hidden" name="action" value="ypm_ai_site_plan_pause"><button class="button">' . esc_html__('Pause', 'yablonsky-multilingual') . '</button></form>';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('ypm_ai_site_plan_cancel', 'ypm_ai_plan_nonce');
      echo '<input type="hidden" name="action" value="ypm_ai_site_plan_cancel"><button class="button button-link-delete">' . esc_html__('Cancel', 'yablonsky-multilingual') . '</button></form>';
    } elseif ($status === AITranslation::STATUS_PAUSED) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('ypm_ai_site_plan_resume', 'ypm_ai_plan_nonce');
      echo '<input type="hidden" name="action" value="ypm_ai_site_plan_resume"><button class="button button-primary">' . esc_html__('Resume campaign', 'yablonsky-multilingual') . '</button></form>';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('ypm_ai_site_plan_cancel', 'ypm_ai_plan_nonce');
      echo '<input type="hidden" name="action" value="ypm_ai_site_plan_cancel"><button class="button button-link-delete">' . esc_html__('Cancel', 'yablonsky-multilingual') . '</button></form>';
    }

    if ($status !== AITranslation::STATUS_RUNNING) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('ypm_ai_site_plan_reset', 'ypm_ai_plan_nonce');
      echo '<input type="hidden" name="action" value="ypm_ai_site_plan_reset"><button class="button">' . esc_html__('Reset plan', 'yablonsky-multilingual') . '</button></form>';
    }
    echo '</div>';

    $errors = isset($plan['errors']) && is_array($plan['errors']) ? array_slice($plan['errors'], -8) : [];
    if ($errors) {
      echo '<details class="ypm-disclosure ypm-ai-job-errors"><summary><span><strong>' . esc_html__('Recent campaign issues', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Only the latest bounded errors are retained.', 'yablonsky-multilingual') . '</small></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary><div class="ypm-disclosure__body"><ul>';
      foreach ($errors as $error) {
        if (!is_array($error)) continue;
        $source_id = absint($error['source_id'] ?? 0);
        $target = sanitize_text_field((string) ($error['target_locale'] ?? ''));
        $message = sanitize_text_field((string) ($error['message'] ?? ''));
        if ($message === '') continue;
        $prefix = ($source_id > 0 ? '#' . $source_id : '') . ($target !== '' ? ' → ' . Languages::display_code($target) : '');
        echo '<li>' . esc_html(($prefix !== '' ? $prefix . ' — ' : '') . $message) . '</li>';
      }
      echo '</ul></div></details>';
    }
  }

  public static function handle_preview(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_ai_site_plan_preview', 'ypm_ai_plan_nonce');
    $source_raw = filter_input(INPUT_POST, 'source_locale', FILTER_UNSAFE_RAW);
    $source = is_string($source_raw) ? sanitize_text_field($source_raw) : '';
    $targets = self::posted_string_array('target_locales');
    $post_types = array_map('sanitize_key', self::posted_string_array('post_types'));
    $limit_raw = filter_input(INPUT_POST, 'task_limit', FILTER_VALIDATE_INT);
    $limit = $limit_raw ? (int) $limit_raw : 50;

    $preview = self::build_preview($source, $targets, $post_types, $limit);
    if (is_wp_error($preview)) self::redirect_notice('error', $preview->get_error_message());
    update_option(self::OPT_PLAN, $preview, false);
    self::redirect_notice('success', __('Local Site Translation Plan preview built. Review the scope before starting AI execution.', 'yablonsky-multilingual'));
  }

  public static function handle_start(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_ai_site_plan_start', 'ypm_ai_plan_nonce');
    $confirm = filter_input(INPUT_POST, 'confirm', FILTER_VALIDATE_BOOLEAN);
    if (!$confirm) self::redirect_notice('error', __('Confirm the bounded AI draft campaign before starting.', 'yablonsky-multilingual'));
    if (!AITranslation::current_user_can_compute() || !AITranslation::ready()) self::redirect_notice('error', __('The connected Yablonsky AI text route must pass the current live canary first.', 'yablonsky-multilingual'));
    if (class_exists('Yablonsky\\Multilingual\\AIJobs') && AIJobs::is_running()) self::redirect_notice('error', __('Finish or cancel the current-page AI batch before starting a site campaign.', 'yablonsky-multilingual'));
    if (self::is_running()) self::redirect_notice('error', __('A Site Translation Plan is already running.', 'yablonsky-multilingual'));

    $plan = self::plan();
    $tasks = isset($plan['tasks']) && is_array($plan['tasks']) ? $plan['tasks'] : [];
    if (!$tasks) self::redirect_notice('error', __('Build a local plan preview with at least one task, then start the campaign.', 'yablonsky-multilingual'));
    $plan['status'] = AITranslation::STATUS_RUNNING;
    $plan['owner_user_id'] = get_current_user_id();
    $plan['cursor'] = 0;
    $plan['created'] = 0;
    $plan['skipped'] = 0;
    $plan['failed'] = 0;
    $plan['credits_used'] = 0.0;
    $plan['draft_ids'] = [];
    $plan['errors'] = [];
    $plan['started_at'] = gmdate('c');
    $plan['updated_at'] = gmdate('c');
    update_option(self::OPT_PLAN, $plan, false);
    self::schedule_next();
    self::redirect_notice('success', __('Site Translation Plan started. Drafts will be created one bounded task at a time.', 'yablonsky-multilingual'));
  }

  public static function handle_next(): void {
    if (!self::current_user_can_control()) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_ai_site_plan_next', 'ypm_ai_plan_nonce');
    self::tick();
    wp_safe_redirect(admin_url('admin.php?page=ypm-plan#ypm-ai-site-plan'));
    exit;
  }

  public static function handle_pause(): void {
    if (!self::current_user_can_control()) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_ai_site_plan_pause', 'ypm_ai_plan_nonce');
    $plan = self::plan();
    if (($plan['status'] ?? '') === AITranslation::STATUS_RUNNING) {
      $plan['status'] = AITranslation::STATUS_PAUSED;
      $plan['updated_at'] = gmdate('c');
      update_option(self::OPT_PLAN, $plan, false);
    }
    wp_clear_scheduled_hook(self::HOOK);
    self::redirect_notice('success', __('Site Translation Plan is on standby. Resume it to continue AI tasks.', 'yablonsky-multilingual'));
  }

  public static function handle_resume(): void {
    if (!self::current_user_can_control()) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_ai_site_plan_resume', 'ypm_ai_plan_nonce');
    if (!AITranslation::ready()) self::redirect_notice('error', __('Refresh the workspace/model canary, then resume the plan.', 'yablonsky-multilingual'));
    if (class_exists('Yablonsky\\Multilingual\\AIJobs') && AIJobs::is_running()) self::redirect_notice('error', __('Finish or cancel the current-page AI batch before resuming the site campaign.', 'yablonsky-multilingual'));
    $plan = self::plan();
    if (($plan['status'] ?? '') !== AITranslation::STATUS_PAUSED) self::redirect_notice('error', __('Site Translation Plan is already active or complete.', 'yablonsky-multilingual'));
    $plan['status'] = AITranslation::STATUS_RUNNING;
    $plan['updated_at'] = gmdate('c');
    update_option(self::OPT_PLAN, $plan, false);
    self::schedule_next();
    self::redirect_notice('success', __('Site Translation Plan resumed.', 'yablonsky-multilingual'));
  }

  public static function handle_cancel(): void {
    if (!self::current_user_can_control()) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_ai_site_plan_cancel', 'ypm_ai_plan_nonce');
    $plan = self::plan();
    if ($plan) {
      $plan['status'] = AITranslation::STATUS_CANCELLED;
      $plan['updated_at'] = gmdate('c');
      update_option(self::OPT_PLAN, $plan, false);
    }
    wp_clear_scheduled_hook(self::HOOK);
    self::redirect_notice('success', __('Site Translation Plan cancelled. Existing review drafts were kept.', 'yablonsky-multilingual'));
  }

  public static function handle_reset(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_ai_site_plan_reset', 'ypm_ai_plan_nonce');
    if (self::is_running()) self::redirect_notice('error', __('Pause or cancel the running Site Translation Plan before resetting it.', 'yablonsky-multilingual'));
    delete_option(self::OPT_PLAN);
    delete_option(self::LOCK);
    wp_clear_scheduled_hook(self::HOOK);
    self::redirect_notice('success', __('Site Translation Plan reset.', 'yablonsky-multilingual'));
  }

  public static function cron_tick(): void {
    self::tick();
  }

  public static function tick(): void {
    if (!self::acquire_lock()) return;
    try {
      $plan = self::plan();
      if (!$plan || ($plan['status'] ?? '') !== AITranslation::STATUS_RUNNING) return;
      $tasks = isset($plan['tasks']) && is_array($plan['tasks']) ? array_values($plan['tasks']) : [];
      $cursor = max(0, (int) ($plan['cursor'] ?? 0));
      if ($cursor >= count($tasks)) { self::complete($plan); return; }

      $owner = absint($plan['owner_user_id'] ?? 0);
      $compute_capability = AITranslation::compute_capability();
      if ($owner <= 0 || !user_can($owner, $compute_capability) || !AITranslation::ready()) {
        self::fail_plan($plan, 0, '', __('Campaign authority or AI route verification changed. Refresh authority and route verification to continue.', 'yablonsky-multilingual'));
        return;
      }

      $task = is_array($tasks[$cursor]) ? $tasks[$cursor] : [];
      $source_id = absint($task['source_id'] ?? 0);
      $target = sanitize_text_field((string) ($task['target_locale'] ?? ''));
      $plan['cursor'] = $cursor + 1;
      $plan['updated_at'] = gmdate('c');

      $source_locale = sanitize_text_field((string) ($plan['source_locale'] ?? ''));
      $source_post = $source_id > 0 ? get_post($source_id) : null;
      if ($source_id <= 0 || !($source_post instanceof \WP_Post) || !in_array($source_post->post_status, ['publish', 'private'], true) || !user_can($owner, 'edit_post', $source_id) || !Languages::is_active($target) || RuntimeAuthority::effective_post_language($source_id) !== $source_locale) {
        $plan['skipped'] = (int) ($plan['skipped'] ?? 0) + 1;
        update_option(self::OPT_PLAN, $plan, false);
        self::schedule_or_complete($plan);
        return;
      }

      if (EffectiveRelations::existing_target($source_id, $target) > 0) {
        $plan['skipped'] = (int) ($plan['skipped'] ?? 0) + 1;
        update_option(self::OPT_PLAN, $plan, false);
        self::schedule_or_complete($plan);
        return;
      }

      $result = AITranslation::create_content_draft($source_id, $target, $owner);
      if (is_wp_error($result)) {
        $plan['failed'] = (int) ($plan['failed'] ?? 0) + 1;
        $errors = isset($plan['errors']) && is_array($plan['errors']) ? $plan['errors'] : [];
        $errors[] = ['source_id' => $source_id, 'target_locale' => $target, 'message' => sanitize_text_field($result->get_error_message())];
        $plan['errors'] = array_slice($errors, -8);
        $code = sanitize_key((string) $result->get_error_code());
        foreach (['ypm_ai_http', 'ypm_ai_stream', 'ypm_ai_not_ready', 'ypm_ai_secret', 'ypm_ai_workspace', 'ypm_ai_model'] as $fatal_prefix) {
          if (strpos($code, $fatal_prefix) === 0) { $plan['status'] = AITranslation::STATUS_FAILED; break; }
        }
      } else {
        $plan['created'] = (int) ($plan['created'] ?? 0) + 1;
        $draft_ids = isset($plan['draft_ids']) && is_array($plan['draft_ids']) ? array_values(array_filter(array_map('absint', $plan['draft_ids']))) : [];
        $draft_ids[] = (int) $result;
        $plan['draft_ids'] = array_slice(array_values(array_unique($draft_ids)), -self::MAX_TASKS);
        $provenance = get_post_meta((int) $result, AITranslation::META_PROVENANCE, true);
        if (is_array($provenance) && is_numeric($provenance['cost'] ?? null)) $plan['credits_used'] = (float) ($plan['credits_used'] ?? 0) + (float) $provenance['cost'];
      }
      update_option(self::OPT_PLAN, $plan, false);
      if (($plan['status'] ?? '') === AITranslation::STATUS_RUNNING) self::schedule_or_complete($plan); else wp_clear_scheduled_hook(self::HOOK);
    } finally {
      delete_option(self::LOCK);
    }
  }

  /** @return array<string,mixed>|\WP_Error */
  private static function build_preview(string $source, array $targets, array $post_types, int $limit) {
    $active = Languages::active_codes();
    if (!in_array($source, $active, true)) return new \WP_Error('ypm_ai_plan_source', __('Choose an active source locale.', 'yablonsky-multilingual'));
    $targets = array_values(array_unique(array_filter(array_map('sanitize_text_field', $targets), static function (string $code) use ($active, $source): bool {
      return $code !== $source && in_array($code, $active, true);
    })));
    if (!$targets) return new \WP_Error('ypm_ai_plan_targets', __('Choose at least one active target locale different from the source.', 'yablonsky-multilingual'));

    $allowed_types = array_keys(self::available_post_types());
    $post_types = array_values(array_unique(array_filter(array_map('sanitize_key', $post_types), static function (string $post_type) use ($allowed_types): bool {
      return in_array($post_type, $allowed_types, true);
    })));
    if (!$post_types) return new \WP_Error('ypm_ai_plan_types', __('Choose at least one supported content type.', 'yablonsky-multilingual'));
    $limit = max(25, min(self::MAX_TASKS, $limit));

    $tasks = [];
    $by_target = array_fill_keys($targets, 0);
    $by_type = [];
    $scanned = 0;
    $page = 1;
    $has_more = false;
    $provider_catalog = RuntimeAuthority::wpml_translation_table_available();

    while ($scanned < self::SCAN_LIMIT && count($tasks) < $limit) {
      if ($provider_catalog) {
        $ids = RuntimeAuthority::wpml_post_ids_for_language(
          $source,
          $post_types,
          ['publish', 'private'],
          self::QUERY_BATCH,
          ($page - 1) * self::QUERY_BATCH,
          'ASC'
        );
      } else {
        $args = [
          'post_type' => $post_types,
          'post_status' => ['publish', 'private'],
          'posts_per_page' => self::QUERY_BATCH,
          'paged' => $page,
          'orderby' => 'ID',
          'order' => 'ASC',
          'fields' => 'ids',
          'no_found_rows' => true,
          'update_post_meta_cache' => false,
          'update_post_term_cache' => false,
        ];
        $args = AdminContent::with_source_language($args, $source);
        $query = new \WP_Query($args);
        $ids = array_values(array_filter(array_map('absint', (array) $query->posts)));
      }
      if (!$ids) break;
      $scanned += count($ids);
      Translations::prime_translation_families($ids);
      foreach ($ids as $source_id) {
        if (RuntimeAuthority::effective_post_language($source_id) !== $source) continue;
        if (!current_user_can('edit_post', $source_id)) continue;
        $family = EffectiveRelations::post_family($source_id);
        $existing = is_array($family['translations'] ?? null) ? $family['translations'] : [];
        foreach ($targets as $target) {
          if (!empty($existing[$target])) continue;
          $post_type = (string) get_post_type($source_id);
          $tasks[] = ['source_id' => $source_id, 'target_locale' => $target, 'post_type' => $post_type];
          $by_target[$target] = (int) ($by_target[$target] ?? 0) + 1;
          $by_type[$post_type] = (int) ($by_type[$post_type] ?? 0) + 1;
          if (count($tasks) >= $limit) { $has_more = true; break 2; }
        }
      }
      if (!$provider_catalog) wp_reset_postdata();
      if (count($ids) < self::QUERY_BATCH) break;
      $page++;
    }
    if ($scanned >= self::SCAN_LIMIT) $has_more = true;
    wp_reset_postdata();

    return [
      'id' => wp_generate_uuid4(),
      'status' => AITranslation::STATUS_DRAFT,
      'source_locale' => $source,
      'target_locales' => $targets,
      'post_types' => $post_types,
      'task_limit' => $limit,
      'tasks' => $tasks,
      'scanned' => $scanned,
      'has_more' => $has_more,
      'by_target' => $by_target,
      'by_type' => $by_type,
      'cursor' => 0,
      'created' => 0,
      'skipped' => 0,
      'failed' => 0,
      'credits_used' => 0.0,
      'draft_ids' => [],
      'errors' => [],
      'review_policy' => 'review_before_publish',
      'publish_policy' => 'draft',
      'built_at' => gmdate('c'),
    ];
  }

  /** @return array<string,string> */
  private static function available_post_types(): array {
    $result = [];
    foreach (Languages::supported_post_types() as $post_type) {
      $object = get_post_type_object($post_type);
      if (!$object || empty($object->public)) continue;
      $label = isset($object->labels->name) ? (string) $object->labels->name : $post_type;
      $result[$post_type] = $label;
    }
    if (!$result) $result = ['post' => __('Posts', 'yablonsky-multilingual'), 'page' => __('Pages', 'yablonsky-multilingual')];
    return $result;
  }

  /** @return string[] */
  private static function default_post_types(): array {
    $available = array_keys(self::available_post_types());
    $preferred = array_values(array_intersect(['page', 'post', 'product'], $available));
    return $preferred ?: array_slice($available, 0, 3);
  }

  /** @return string[] */
  private static function posted_string_array(string $key): array {
    // Callers verify their action-specific admin nonce before reading request data.
    // filter_input() reads the original request array without WordPress magic quotes;
    // every scalar value is still sanitized explicitly below before use.
    $raw = filter_input(INPUT_POST, $key, FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
    if (!is_array($raw)) return [];

    $result = [];
    foreach ($raw as $value) {
      if (!is_scalar($value)) continue;
      $clean = sanitize_text_field((string) $value);
      if ($clean !== '') $result[] = $clean;
    }
    return array_values(array_unique($result));
  }

  private static function current_user_can_control(): bool {
    $plan = self::plan();
    if (!$plan) return false;
    $owner = absint($plan['owner_user_id'] ?? 0);
    return current_user_can('manage_options') || ($owner > 0 && $owner === get_current_user_id());
  }

  private static function acquire_lock(): bool {
    $now = time();
    if (add_option(self::LOCK, (string) $now, '', false)) return true;
    $locked_at = (int) get_option(self::LOCK, 0);
    if ($locked_at > 0 && ($now - $locked_at) <= 300) return false;
    delete_option(self::LOCK);
    return add_option(self::LOCK, (string) $now, '', false);
  }

  /** @param array<string,mixed> $plan */
  private static function schedule_or_complete(array $plan): void {
    $cursor = max(0, (int) ($plan['cursor'] ?? 0));
    $tasks = isset($plan['tasks']) && is_array($plan['tasks']) ? $plan['tasks'] : [];
    if ($cursor >= count($tasks)) self::complete($plan); else self::schedule_next();
  }

  /** @param array<string,mixed> $plan */
  private static function complete(array $plan): void {
    $plan['status'] = AITranslation::STATUS_COMPLETED;
    $plan['updated_at'] = gmdate('c');
    $plan['completed_at'] = gmdate('c');
    update_option(self::OPT_PLAN, $plan, false);
    wp_clear_scheduled_hook(self::HOOK);
  }

  /** @param array<string,mixed> $plan */
  private static function fail_plan(array $plan, int $source_id, string $target, string $message): void {
    $plan['status'] = AITranslation::STATUS_FAILED;
    $plan['failed'] = (int) ($plan['failed'] ?? 0) + 1;
    $errors = isset($plan['errors']) && is_array($plan['errors']) ? $plan['errors'] : [];
    $errors[] = ['source_id' => $source_id, 'target_locale' => $target, 'message' => sanitize_text_field($message)];
    $plan['errors'] = array_slice($errors, -8);
    $plan['updated_at'] = gmdate('c');
    update_option(self::OPT_PLAN, $plan, false);
    wp_clear_scheduled_hook(self::HOOK);
  }

  private static function schedule_next(): void {
    if (!wp_next_scheduled(self::HOOK)) wp_schedule_single_event(time() + 10, self::HOOK);
  }

  private static function redirect_notice(string $type, string $message): void {
    set_transient('ypm_ai_admin_notice_' . get_current_user_id(), ['type' => $type === 'error' ? 'error' : 'success', 'message' => sanitize_text_field($message)], 60);
    wp_safe_redirect(admin_url('admin.php?page=ypm-plan#ypm-ai-site-plan'));
    exit;
  }
}
