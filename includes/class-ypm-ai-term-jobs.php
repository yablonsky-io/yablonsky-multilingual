<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Bounded AI suggestions for taxonomy terms. Suggestions never create or overwrite terms. */
final class AITermJobs {
  public const OPT_JOB = 'ypm_ai_term_job_v1';
  private const HOOK = 'yablonsky_multilingual_ai_term_job_tick';
  private const LOCK = 'ypm_ai_term_job_lock_v1';
  private const MAX_TASKS = 5000;

  public static function init(): void {
    add_action(self::HOOK, [__CLASS__, 'cron_tick']);
    if (is_admin()) {
      add_action('admin_post_ypm_ai_term_job_start', [__CLASS__, 'handle_start']);
      add_action('admin_post_ypm_ai_term_job_tick', [__CLASS__, 'handle_tick']);
      add_action('admin_post_ypm_ai_term_job_cancel', [__CLASS__, 'handle_cancel']);
    }
  }

  /** @return array<string,mixed> */
  public static function job(): array {
    $job = get_option(self::OPT_JOB, []);
    return is_array($job) ? $job : [];
  }

  public static function is_running(): bool {
    return (string) (self::job()['status'] ?? '') === 'running';
  }

  /** @return array<string,mixed> */
  public static function suggestion_for(int $source_id, string $taxonomy, string $target_locale): array {
    $job = self::job();
    $suggestions = isset($job['suggestions']) && is_array($job['suggestions']) ? $job['suggestions'] : [];
    $key = self::task_key($source_id, $taxonomy, $target_locale);
    return isset($suggestions[$key]) && is_array($suggestions[$key]) ? $suggestions[$key] : [];
  }

  public static function clear_suggestion(int $source_id, string $taxonomy, string $target_locale): void {
    $job = self::job();
    if (!$job) return;
    $key = self::task_key($source_id, $taxonomy, $target_locale);
    if (isset($job['suggestions'][$key])) {
      unset($job['suggestions'][$key]);
      update_option(self::OPT_JOB, $job, false);
    }
  }

  /** @param array<int,\WP_Term> $visible_terms */
  public static function render_panel(string $taxonomy, array $visible_terms, string $default_target = '', string $search = '', int $scope_total = 0, string $source_locale = ''): void {
    if (!TermTranslations::is_supported_taxonomy($taxonomy)) return;
    $job = self::job();
    $same = (string) ($job['taxonomy'] ?? '') === $taxonomy;
    $status = $same ? (string) ($job['status'] ?? '') : '';
    $target = $same ? sanitize_text_field((string) ($job['target_locale'] ?? '')) : '';
    $stats = $same && isset($job['stats']) && is_array($job['stats']) ? $job['stats'] : [];
    $suggestions = $same && isset($job['suggestions']) && is_array($job['suggestions']) ? $job['suggestions'] : [];
    $setup = AITranslation::text_setup_status();
    $ready = !empty($setup['ready']);

    echo '<section class="ypm-card ypm-card--standalone"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('AI term review', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Prepare taxonomy translations in a bounded queue', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('Yablonsky AI prepares selected, page or filtered-scope term suggestions, one remote request per background tick. Suggestions remain review-only until you open the normal Create translation form and submit it.', 'yablonsky-multilingual') . '</p></div>';
    echo wp_kses_post(Admin::status_badge($status === 'running' ? 'running' : ($suggestions ? 'attention' : 'neutral'), $status === 'running' ? __('Running', 'yablonsky-multilingual') : ($suggestions ? __('Review ready', 'yablonsky-multilingual') : __('Idle', 'yablonsky-multilingual'))));
    echo '</div>';

    if ($same && $job) {
      echo '<div class="ypm-metrics ypm-metrics--4">';
      Admin::render_metric(__('Target', 'yablonsky-multilingual'), $target !== '' ? Languages::display_code($target) : '—', __('Locale', 'yablonsky-multilingual'), 'neutral');
      Admin::render_metric(__('Prepared', 'yablonsky-multilingual'), (string) (int) ($stats['prepared'] ?? 0), __('Review suggestions', 'yablonsky-multilingual'), $suggestions ? 'pass' : 'neutral');
      Admin::render_metric(__('Skipped', 'yablonsky-multilingual'), (string) (int) ($stats['skipped'] ?? 0), __('Already translated / changed', 'yablonsky-multilingual'), 'neutral');
      Admin::render_metric(__('Failed', 'yablonsky-multilingual'), (string) (int) ($stats['failed'] ?? 0), __('Latest bounded run', 'yablonsky-multilingual'), !empty($stats['failed']) ? 'attention' : 'pass');
      echo '</div>';
    }

    if ($suggestions) {
      echo '<div class="ypm-family-list">';
      $shown = 0;
      foreach ($suggestions as $suggestion) {
        if ($shown >= self::MAX_TASKS || !is_array($suggestion)) break;
        $source_id = absint($suggestion['source_id'] ?? 0);
        $locale = sanitize_text_field((string) ($suggestion['target_locale'] ?? ''));
        if ($source_id <= 0 || $locale === '') continue;
        $source = get_term($source_id, $taxonomy);
        if (!($source instanceof \WP_Term)) continue;
        $shown++;
        $url = AdminTerms::create_translation_url($source_id, $taxonomy, $locale);
        echo '<div class="ypm-family-row"><div class="ypm-family-row__name"><strong>' . esc_html($source->name) . '</strong><span>' . esc_html((string) ($suggestion['name'] ?? '')) . '</span></div><span class="ypm-family-chip ypm-family-row__code">' . esc_html(Languages::display_code($locale)) . '</span><div class="ypm-family-row__action"><a class="button button-small button-primary" href="' . esc_url($url) . '">' . esc_html__('Review & create', 'yablonsky-multilingual') . '</a></div></div>';
      }
      echo '</div>';
    }

    echo '<div class="ymulti-bulk-launcher ymulti-bulk-launcher--terms" data-ymulti-bulk-scope="taxonomy">';
    if ($status === 'running') {
      echo '<div class="ypm-row-actions"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('ypm_ai_term_job_tick', 'ypm_ai_term_job_nonce');
      echo '<input type="hidden" name="action" value="ypm_ai_term_job_tick"><button class="button">' . esc_html__('Run next task now', 'yablonsky-multilingual') . '</button></form>';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('ypm_ai_term_job_cancel', 'ypm_ai_term_job_nonce');
      echo '<input type="hidden" name="action" value="ypm_ai_term_job_cancel"><button class="button">' . esc_html__('Cancel queue', 'yablonsky-multilingual') . '</button></form></div>';
    } elseif ($visible_terms) {
      $available_attr = $ready ? '1' : '0';
      $unavailable_message = !$ready ? sanitize_text_field((string) ($setup['message'] ?? '')) : '';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ymulti-bulk-form" data-ymulti-bulk-form="taxonomy" data-ymulti-bulk-status="1" data-ymulti-bulk-available="' . esc_attr($available_attr) . '" data-ymulti-bulk-unavailable-message="' . esc_attr($unavailable_message) . '">';
      wp_nonce_field('ypm_ai_term_job_start', 'ypm_ai_term_job_nonce');
      echo '<input type="hidden" name="action" value="ypm_ai_term_job_start"><input type="hidden" name="taxonomy" value="' . esc_attr($taxonomy) . '"><input type="hidden" name="search" value="' . esc_attr($search) . '"><input type="hidden" name="source_locale" value="' . esc_attr($source_locale) . '"><div data-ymulti-selected-inputs></div>';
      foreach ($visible_terms as $visible_term) if ($visible_term instanceof \WP_Term) echo '<input type="hidden" name="page_ids[]" value="' . esc_attr((string) $visible_term->term_id) . '">';
      if ($default_target !== '' && Languages::is_active($default_target)) {
        echo '<input type="hidden" name="target_locale" value="' . esc_attr($default_target) . '"><div class="ymulti-bulk-target"><span>' . esc_html__('Translate to', 'yablonsky-multilingual') . '</span><strong>' . esc_html(Languages::display_name($default_target) . ' · ' . Languages::display_code($default_target)) . '</strong></div>';
      } else {
        echo '<label class="ymulti-bulk-target"><span>' . esc_html__('Translate to', 'yablonsky-multilingual') . '</span><select name="target_locale" required><option value="">' . esc_html__('Target locale…', 'yablonsky-multilingual') . '</option>';
        foreach (Languages::active_codes() as $code) echo '<option value="' . esc_attr($code) . '">' . esc_html(Languages::display_name($code) . ' (' . Languages::display_code($code) . ')') . '</option>';
        echo '</select></label>';
      }
      echo '<div class="ymulti-bulk-action-grid">';
      echo '<div class="ymulti-bulk-action-card is-selected"><strong>' . esc_html__('Selected terms', 'yablonsky-multilingual') . '</strong><span>' . esc_html__('Prepare suggestions only for checked taxonomy rows.', 'yablonsky-multilingual') . '</span><button class="button button-primary" name="mode" value="selected" data-ymulti-bulk-selected disabled>' . esc_html__('Translate selected', 'yablonsky-multilingual') . ' <span data-ymulti-selected-count>(0)</span></button></div>';
      echo '<div class="ymulti-bulk-action-card"><strong>' . esc_html__('Current page', 'yablonsky-multilingual') . '</strong><span>' . esc_html(sprintf(
        /* translators: %d: visible term count. */
        __('Prepare suggestions for all %d visible terms.', 'yablonsky-multilingual'), count($visible_terms)
      )) . '</span><button class="button" name="mode" value="page"';
    if (!$ready) echo ' disabled aria-disabled="true"';
    echo '>' . esc_html(sprintf(
        /* translators: %d: visible term count. */
        __('Translate this page (%d)', 'yablonsky-multilingual'), count($visible_terms)
      )) . '</button></div>';
      echo '<div class="ymulti-bulk-action-card is-all"><strong>' . esc_html__('Entire filtered scope', 'yablonsky-multilingual') . '</strong><span>' . esc_html(sprintf(
        /* translators: %d: total terms matching the current server filter. */
        __('Queue all %d terms matching the current filters.', 'yablonsky-multilingual'), max(0, $scope_total)
      )) . '</span><label class="ymulti-bulk-confirm"><input type="checkbox" name="confirm_all" value="1" data-ymulti-bulk-confirm-all';
    echo '> <span>' . esc_html__('I understand this can consume AI credits.', 'yablonsky-multilingual') . '</span></label><button class="button" name="mode" value="all" data-ymulti-bulk-all-action disabled>' . esc_html(sprintf(
        /* translators: %d: total terms matching the current server filter. */
        __('Translate all matching (%d)', 'yablonsky-multilingual'), max(0, $scope_total)
      )) . '</button></div></div></form>';
    }
    echo '</div></section>';
  }

  public static function handle_start(): void {
    check_admin_referer('ypm_ai_term_job_start', 'ypm_ai_term_job_nonce');
    $taxonomy_raw = filter_input(INPUT_POST, 'taxonomy', FILTER_UNSAFE_RAW);
    $target_raw = filter_input(INPUT_POST, 'target_locale', FILTER_UNSAFE_RAW);
    $taxonomy = is_string($taxonomy_raw) ? sanitize_key($taxonomy_raw) : '';
    $target = is_string($target_raw) ? sanitize_text_field($target_raw) : '';
    if (!self::can_manage($taxonomy) || !Languages::is_active($target) || !AITranslation::current_user_can_compute() || !AITranslation::ready()) wp_die(esc_html__('AI term campaign requires a manageable taxonomy, active target language, AI compute permission and a ready text route. Review the scope and Yablonsky AI settings, then try again.', 'yablonsky-multilingual'));

    $mode_raw = filter_input(INPUT_POST, 'mode', FILTER_UNSAFE_RAW);
    $search_raw = filter_input(INPUT_POST, 'search', FILTER_UNSAFE_RAW);
    $source_raw = filter_input(INPUT_POST, 'source_locale', FILTER_UNSAFE_RAW);
    $mode = is_string($mode_raw) ? sanitize_key($mode_raw) : 'selected';
    $search = is_string($search_raw) ? trim(sanitize_text_field($search_raw)) : '';
    $source_locale = is_string($source_raw) ? sanitize_text_field($source_raw) : '';
    if ($source_locale !== '' && !Languages::is_active($source_locale)) $source_locale = '';
    if (!in_array($mode, ['selected', 'page', 'all'], true)) $mode = 'selected';
    $confirm_all = (int) filter_input(INPUT_POST, 'confirm_all', FILTER_VALIDATE_INT);
    if ($mode === 'all' && $confirm_all !== 1) wp_die(esc_html__('Confirm the complete filtered-scope AI campaign before starting it.', 'yablonsky-multilingual'));

    $ids = [];
    if ($mode === 'selected' || $mode === 'page') {
      $field = $mode === 'selected' ? 'source_ids' : 'page_ids';
      $posted = filter_input(INPUT_POST, $field, FILTER_SANITIZE_NUMBER_INT, FILTER_REQUIRE_ARRAY);
      if (!is_array($posted)) $posted = [];
      foreach ($posted as $value) { $id = absint($value); if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id; if (count($ids) >= self::MAX_TASKS) break; }
    } else {
      $ids = class_exists('Yablonsky\Multilingual\AdminTerms') ? AdminTerms::workspace_term_ids($taxonomy, $source_locale, $search, self::MAX_TASKS) : [];
    }

    $tasks = [];
    foreach ($ids as $id) {
      $id = absint($id);
      if ($id <= 0 || RuntimeAuthority::effective_term_language($id, $taxonomy) === $target || EffectiveRelations::existing_term_target($id, $taxonomy, $target)) continue;
      $tasks[] = ['source_id' => $id];
      if (count($tasks) >= self::MAX_TASKS) break;
    }
    $job = [
      'status' => $tasks ? 'running' : 'completed', 'taxonomy' => $taxonomy, 'target_locale' => $target, 'source_locale' => $source_locale, 'search' => $search, 'mode' => $mode,
      'tasks' => $tasks, 'cursor' => 0, 'suggestions' => [], 'errors' => [],
      'stats' => ['prepared' => 0, 'skipped' => 0, 'failed' => 0], 'started_at' => gmdate('c'), 'started_by' => get_current_user_id(),
    ];
    update_option(self::OPT_JOB, $job, false);
    if ($tasks) self::schedule();
    self::redirect($taxonomy, $target, $search, $source_locale);
  }

  public static function handle_tick(): void {
    check_admin_referer('ypm_ai_term_job_tick', 'ypm_ai_term_job_nonce');
    $job = self::job();
    $taxonomy = sanitize_key((string) ($job['taxonomy'] ?? ''));
    if (!self::can_manage($taxonomy)) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    self::process_one();
    self::redirect($taxonomy, sanitize_text_field((string) ($job['target_locale'] ?? '')), sanitize_text_field((string) ($job['search'] ?? '')), sanitize_text_field((string) ($job['source_locale'] ?? '')));
  }

  public static function handle_cancel(): void {
    check_admin_referer('ypm_ai_term_job_cancel', 'ypm_ai_term_job_nonce');
    $job = self::job();
    $taxonomy = sanitize_key((string) ($job['taxonomy'] ?? ''));
    if (!self::can_manage($taxonomy)) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    $job['status'] = 'cancelled';
    $job['finished_at'] = gmdate('c');
    update_option(self::OPT_JOB, $job, false);
    wp_clear_scheduled_hook(self::HOOK);
    self::redirect($taxonomy, sanitize_text_field((string) ($job['target_locale'] ?? '')), sanitize_text_field((string) ($job['search'] ?? '')), sanitize_text_field((string) ($job['source_locale'] ?? '')));
  }

  public static function cron_tick(): void { self::process_one(); }

  private static function process_one(): void {
    if (get_transient(self::LOCK)) return;
    set_transient(self::LOCK, 1, 2 * MINUTE_IN_SECONDS);
    try {
      $job = self::job();
      if ((string) ($job['status'] ?? '') !== 'running') return;
      $tasks = isset($job['tasks']) && is_array($job['tasks']) ? $job['tasks'] : [];
      $cursor = max(0, (int) ($job['cursor'] ?? 0));
      if (!isset($tasks[$cursor]) || !is_array($tasks[$cursor])) { self::complete($job); return; }
      $taxonomy = sanitize_key((string) ($job['taxonomy'] ?? ''));
      $target = sanitize_text_field((string) ($job['target_locale'] ?? ''));
      $source_id = absint($tasks[$cursor]['source_id'] ?? 0);
      $job['cursor'] = $cursor + 1;

      if ($source_id <= 0 || !TermTranslations::is_supported_taxonomy($taxonomy) || !Languages::is_active($target) || EffectiveRelations::existing_term_target($source_id, $taxonomy, $target) || RuntimeAuthority::effective_term_language($source_id, $taxonomy) === $target) {
        $job['stats']['skipped'] = (int) ($job['stats']['skipped'] ?? 0) + 1;
      } else {
        $result = AITranslation::suggest_term($source_id, $taxonomy, $target);
        if (is_wp_error($result)) {
          $job['stats']['failed'] = (int) ($job['stats']['failed'] ?? 0) + 1;
          $job['errors'][] = ['source_id' => $source_id, 'message' => sanitize_text_field($result->get_error_message()), 'at' => gmdate('c')];
          $job['errors'] = array_slice($job['errors'], -10);
        } else {
          $doc = isset($result['document']) && is_array($result['document']) ? $result['document'] : [];
          $job['suggestions'][self::task_key($source_id, $taxonomy, $target)] = [
            'source_id' => $source_id, 'taxonomy' => $taxonomy, 'target_locale' => $target,
            'name' => sanitize_text_field((string) ($result['name'] ?? '')), 'slug' => sanitize_title((string) ($result['slug'] ?? '')),
            'description' => wp_kses_post((string) ($result['description'] ?? '')), 'model' => sanitize_text_field((string) ($doc['model'] ?? '')),
            'cost' => is_numeric($doc['cost'] ?? null) ? (float) $doc['cost'] : null, 'prepared_at' => gmdate('c'),
          ];
          $job['stats']['prepared'] = (int) ($job['stats']['prepared'] ?? 0) + 1;
        }
      }
      if ((int) $job['cursor'] >= count($tasks)) self::complete($job); else { update_option(self::OPT_JOB, $job, false); self::schedule(); }
    } finally { delete_transient(self::LOCK); }
  }

  /** @param array<string,mixed> $job */
  private static function complete(array $job): void {
    $job['status'] = 'completed'; $job['finished_at'] = gmdate('c'); update_option(self::OPT_JOB, $job, false); wp_clear_scheduled_hook(self::HOOK);
  }

  private static function schedule(): void {
    if (!wp_next_scheduled(self::HOOK)) wp_schedule_single_event(time() + 15, self::HOOK);
  }

  private static function can_manage(string $taxonomy): bool {
    $object = get_taxonomy($taxonomy);
    return $object && !empty($object->cap->manage_terms) && current_user_can((string) $object->cap->manage_terms);
  }

  private static function task_key(int $source_id, string $taxonomy, string $target): string { return sha1($taxonomy . '|' . $source_id . '|' . $target); }

  private static function redirect(string $taxonomy, string $target = '', string $search = '', string $source_locale = ''): void {
    $args = ['page' => 'ypm-taxonomies', 'taxonomy' => $taxonomy];
    if ($source_locale !== '') $args['source_locale'] = $source_locale;
    if ($target !== '') $args['target_locale'] = $target;
    if ($search !== '') $args['s'] = $search;
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php'))); exit;
  }
}
