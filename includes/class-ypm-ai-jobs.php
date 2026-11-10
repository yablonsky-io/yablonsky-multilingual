<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Durable, bounded background runner for AI content drafts. */
final class AIJobs {
  public const OPT_JOB = 'ypm_ai_translation_job_v1';
  private const LOCK = 'ypm_ai_translation_job_lock_v1';
  private const HOOK = 'yablonsky_multilingual_ai_translation_tick';
  private const MAX_ITEMS = 10000;

  public static function init(): void {
    add_action(self::HOOK, [__CLASS__, 'cron_tick']);
    if (is_admin()) {
      add_action('admin_post_ypm_ai_batch_start', [__CLASS__, 'handle_start']);
      add_action('admin_post_ypm_ai_batch_next', [__CLASS__, 'handle_next']);
      add_action('admin_post_ypm_ai_batch_cancel', [__CLASS__, 'handle_cancel']);
      add_action('wp_ajax_ypm_ai_batch_status', [__CLASS__, 'ajax_status']);
    }
  }

  /** @return array<string,mixed> */
  public static function job(): array {
    $job = get_option(self::OPT_JOB, []);
    return is_array($job) ? $job : [];
  }

  public static function is_running(): bool {
    $job = self::job();
    return ($job['status'] ?? '') === AITranslation::STATUS_RUNNING;
  }

  public static function handle_start(): void {
    if (!current_user_can('edit_posts')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_ai_batch_start', 'ypm_ai_batch_nonce');
    if (!AITranslation::current_user_can_compute()) self::redirect_with('ai_batch_error', __('AI compute permission is required for the connected account.', 'yablonsky-multilingual'));
    if (!AITranslation::ready()) self::redirect_with('ai_batch_error', __('Verify Yablonsky AI for this workspace to continue.', 'yablonsky-multilingual'));
    if (self::is_running()) self::redirect_with('ai_batch_error', __('An AI translation batch is already running.', 'yablonsky-multilingual'));
    if (class_exists('Yablonsky\\Multilingual\\AISitePlan') && AISitePlan::is_running()) self::redirect_with('ai_batch_error', __('A Site Translation Plan is already running. Pause or finish it before starting a current-page batch.', 'yablonsky-multilingual'));

    $target_raw = filter_input(INPUT_POST, 'target_locale', FILTER_UNSAFE_RAW);
    $target = is_string($target_raw) ? sanitize_text_field($target_raw) : '';
    if (!Languages::is_active($target)) self::redirect_with('ai_batch_error', __('Choose an active target locale.', 'yablonsky-multilingual'));

    $ids = [];
    $posted_ids = filter_input(INPUT_POST, 'source_ids', FILTER_SANITIZE_NUMBER_INT, FILTER_REQUIRE_ARRAY);
    if (!is_array($posted_ids)) $posted_ids = [];
    foreach ($posted_ids as $value) {
      $id = absint($value);
      if ($id <= 0 || !current_user_can('edit_post', $id)) continue;
      if (!in_array($id, $ids, true)) $ids[] = $id;
      if (count($ids) >= self::MAX_ITEMS) break;
    }
    $started = self::start_job($ids, $target, get_current_user_id());
    if (is_wp_error($started)) self::redirect_with('ai_batch_error', $started->get_error_message());
    self::redirect_with('ai_batch_started', __('AI draft batch started.', 'yablonsky-multilingual'));
  }

  /** @param int[] $ids @return true|\WP_Error */
  public static function start_job(array $ids, string $target, int $owner_user_id = 0) {
    if (self::is_running()) return new \WP_Error('ypm_ai_batch_running', __('An AI translation batch is already running.', 'yablonsky-multilingual'));
    if (class_exists('Yablonsky\Multilingual\AISitePlan') && AISitePlan::is_running()) return new \WP_Error('ypm_ai_site_plan_running', __('A Site Translation Plan is already running. Pause or finish it before starting a list batch.', 'yablonsky-multilingual'));
    if (!Languages::is_active($target)) return new \WP_Error('ypm_ai_batch_target', __('Choose an active target locale.', 'yablonsky-multilingual'));
    if (!AITranslation::ready()) return new \WP_Error('ypm_ai_not_ready', __('Verify Yablonsky AI for this workspace to continue.', 'yablonsky-multilingual'));
    $owner_user_id = $owner_user_id > 0 ? $owner_user_id : get_current_user_id();
    if ($owner_user_id <= 0 || !user_can($owner_user_id, AITranslation::compute_capability())) return new \WP_Error('ypm_ai_batch_compute', __('Assign AI compute permission to the batch owner to continue.', 'yablonsky-multilingual'));

    $clean = [];
    foreach ($ids as $value) {
      $id = absint($value);
      if ($id <= 0 || !user_can($owner_user_id, 'edit_post', $id)) continue;
      if (!in_array($id, $clean, true)) $clean[] = $id;
      if (count($clean) >= self::MAX_ITEMS) break;
    }
    if (!$clean) return new \WP_Error('ypm_ai_batch_empty', __('Editable content families: 0 in this batch.', 'yablonsky-multilingual'));

    $job = [
      'id' => wp_generate_uuid4(),
      'status' => AITranslation::STATUS_RUNNING,
      'owner_user_id' => $owner_user_id,
      'target_locale' => sanitize_text_field($target),
      'source_ids' => $clean,
      'cursor' => 0,
      'created' => 0,
      'skipped' => 0,
      'failed' => 0,
      'credits_used' => 0.0,
      'draft_ids' => [],
      'errors' => [],
      'remote_actions' => 0,
      'current_source_id' => 0,
      'stage_current' => 0,
      'stage_total' => 0,
      'stage_status' => '',
      'stage_estimated_tokens' => 0,
      'stage_model' => AITranslation::resolved_text_model(),
      'started_at' => gmdate('c'),
      'updated_at' => gmdate('c'),
    ];
    update_option(self::OPT_JOB, $job, false);
    self::schedule_next();
    return true;
  }

  public static function handle_next(): void {
    if (!current_user_can('edit_posts')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_ai_batch_next', 'ypm_ai_batch_nonce');
    if (!self::current_user_can_control_job()) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    self::tick();
    wp_safe_redirect(admin_url('admin.php?page=ypm-translations'));
    exit;
  }

  public static function handle_cancel(): void {
    if (!current_user_can('edit_posts')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_ai_batch_cancel', 'ypm_ai_batch_nonce');
    if (!self::current_user_can_control_job()) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    $job = self::job();
    if ($job) {
      $job['status'] = AITranslation::STATUS_CANCELLED;
      $job['updated_at'] = gmdate('c');
      update_option(self::OPT_JOB, $job, false);
    }
    wp_clear_scheduled_hook(self::HOOK);
    wp_safe_redirect(admin_url('admin.php?page=ypm-translations&ai_batch_cancelled=1'));
    exit;
  }

  public static function cron_tick(): void {
    self::tick();
  }

  public static function current_user_can_control_job(): bool {
    $job = self::job();
    if (!$job) return false;
    $owner = absint($job['owner_user_id'] ?? 0);
    return current_user_can('manage_options') || ($owner > 0 && $owner === get_current_user_id());
  }

  public static function tick(): void {
    if (!self::acquire_lock()) return;
    try {
      $job = self::job();
      if (!$job || ($job['status'] ?? '') !== AITranslation::STATUS_RUNNING) return;
      $ids = isset($job['source_ids']) && is_array($job['source_ids']) ? array_values(array_map('absint', $job['source_ids'])) : [];
      $cursor = max(0, (int) ($job['cursor'] ?? 0));
      if ($cursor >= count($ids)) {
        self::complete($job);
        return;
      }

      $source_id = (int) $ids[$cursor];
      $target = sanitize_text_field((string) ($job['target_locale'] ?? ''));
      $owner = absint($job['owner_user_id'] ?? 0);
      $compute_capability = AITranslation::compute_capability();
      if ($owner <= 0 || !user_can($owner, $compute_capability)) {
        $job['status'] = AITranslation::STATUS_FAILED;
        $job['failed'] = (int) ($job['failed'] ?? 0) + 1;
        $errors = isset($job['errors']) && is_array($job['errors']) ? $job['errors'] : [];
        $errors[] = ['source_id' => $source_id, 'message' => __('Batch-owner AI compute permission changed. Assign authority to continue.', 'yablonsky-multilingual')];
        $job['errors'] = array_slice($errors, -5);
        $job['updated_at'] = gmdate('c');
        update_option(self::OPT_JOB, $job, false);
        wp_clear_scheduled_hook(self::HOOK);
        return;
      }
      $job['cursor'] = $cursor + 1;
      $job['current_source_id'] = $source_id;
      $job['stage_current'] = 0;
      $job['stage_total'] = 0;
      $job['stage_status'] = '';
      $job['stage_estimated_tokens'] = 0;
      $job['updated_at'] = gmdate('c');

      if ($source_id <= 0 || $owner <= 0 || !user_can($owner, 'edit_post', $source_id) || !EntityContract::post_target_allowed($source_id, $target)) {
        $job['skipped'] = (int) ($job['skipped'] ?? 0) + 1;
        update_option(self::OPT_JOB, $job, false);
        self::schedule_or_complete($job);
        return;
      }

      $effective_target = EntityContract::existing_post_target($source_id, $target);
      if ($effective_target > 0) {
        $job['skipped'] = (int) ($job['skipped'] ?? 0) + 1;
        update_option(self::OPT_JOB, $job, false);
        self::schedule_or_complete($job);
        return;
      }

      $progress = static function (array $stage) use (&$job, $source_id): void {
        $job['current_source_id'] = $source_id;
        $job['stage_current'] = max(0, (int) ($stage['stage'] ?? 0));
        $job['stage_total'] = max(0, (int) ($stage['total'] ?? 0));
        $job['stage_status'] = sanitize_key((string) ($stage['status'] ?? ''));
        $job['stage_estimated_tokens'] = max(0, (int) ($stage['estimated_input_tokens'] ?? 0));
        $job['stage_model'] = sanitize_text_field((string) ($stage['model'] ?? AITranslation::resolved_text_model()));
        if ($job['stage_status'] === 'running') $job['remote_actions'] = max(0, (int) ($job['remote_actions'] ?? 0)) + 1;
        $job['updated_at'] = gmdate('c');
        update_option(self::OPT_JOB, $job, false);
      };
      $result = AITranslation::create_content_draft($source_id, $target, $owner, $progress);
      if (is_wp_error($result)) {
        $job['stage_status'] = 'failed';
        $job['failed'] = (int) ($job['failed'] ?? 0) + 1;
        $errors = isset($job['errors']) && is_array($job['errors']) ? $job['errors'] : [];
        $errors[] = ['source_id' => $source_id, 'message' => sanitize_text_field($result->get_error_message())];
        $job['errors'] = array_slice($errors, -5);
        $code = sanitize_key((string) $result->get_error_code());
        foreach (['ypm_ai_http', 'ypm_ai_stream', 'ypm_ai_not_ready', 'ypm_ai_secret', 'ypm_ai_workspace'] as $fatal_prefix) {
          if (strpos($code, $fatal_prefix) === 0) {
            $job['status'] = AITranslation::STATUS_FAILED;
            break;
          }
        }
      } else {
        $job['created'] = (int) ($job['created'] ?? 0) + 1;
        $draft_ids = isset($job['draft_ids']) && is_array($job['draft_ids']) ? array_values(array_filter(array_map('absint', $job['draft_ids']))) : [];
        $draft_ids[] = (int) $result;
        $job['draft_ids'] = array_slice(array_values(array_unique($draft_ids)), -self::MAX_ITEMS);
        $provenance = get_post_meta((int) $result, AITranslation::META_PROVENANCE, true);
        if (is_array($provenance) && is_numeric($provenance['cost'] ?? null)) {
          $job['credits_used'] = (float) ($job['credits_used'] ?? 0) + (float) $provenance['cost'];
        }
      }
      update_option(self::OPT_JOB, $job, false);
      if (($job['status'] ?? '') === AITranslation::STATUS_RUNNING) self::schedule_or_complete($job); else wp_clear_scheduled_hook(self::HOOK);
    } finally {
      delete_option(self::LOCK);
    }
  }

  public static function ajax_status(): void {
    check_ajax_referer('ypm_ai_batch_status', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error(['message' => __('Forbidden', 'yablonsky-multilingual')], 403);
    $job = self::job();
    if (!$job) wp_send_json_success(['status' => 'none', 'percent' => 0, 'message' => __('AI translation batch status: idle.', 'yablonsky-multilingual')]);
    $owner = absint($job['owner_user_id'] ?? 0);
    if (!current_user_can('manage_options') && ($owner <= 0 || $owner !== get_current_user_id())) wp_send_json_error(['message' => __('Forbidden', 'yablonsky-multilingual')], 403);

    $ids = isset($job['source_ids']) && is_array($job['source_ids']) ? $job['source_ids'] : [];
    $total = count($ids);
    $cursor = min($total, max(0, (int) ($job['cursor'] ?? 0)));
    $stage_current = max(0, (int) ($job['stage_current'] ?? 0));
    $stage_total = max(0, (int) ($job['stage_total'] ?? 0));
    $stage_status = sanitize_key((string) ($job['stage_status'] ?? ''));
    $completed_before = max(0, $cursor - (($stage_total > 0 && ($job['status'] ?? '') === AITranslation::STATUS_RUNNING) ? 1 : 0));
    $stage_fraction = 0.0;
    if ($stage_total > 0) {
      $done_stages = $stage_status === 'completed' ? $stage_current : max(0, $stage_current - 1);
      $stage_fraction = min(1.0, max(0.0, $done_stages / $stage_total));
    }
    $progress_units = $total > 0 ? min((float) $total, $completed_before + $stage_fraction) : 0.0;
    if (($job['status'] ?? '') === AITranslation::STATUS_COMPLETED) $progress_units = (float) $total;
    $percent = $total > 0 ? (int) floor(($progress_units / $total) * 100) : 0;
    if (($job['status'] ?? '') === AITranslation::STATUS_COMPLETED) $percent = 100;

    $message = sprintf(
      /* translators: 1: current content item number, 2: total content items. */
      __('Item %1$d of %2$d', 'yablonsky-multilingual'),
      $cursor,
      $total
    );
    if ($stage_total > 1 && $stage_current > 0) {
      $message .= ' · ' . sprintf(
        /* translators: 1: current AI stage, 2: total AI stages for the current item. */
        __('stage %1$d of %2$d', 'yablonsky-multilingual'),
        $stage_current,
        $stage_total
      );
    }

    wp_send_json_success([
      'status' => sanitize_key((string) ($job['status'] ?? '')),
      'percent' => max(0, min(100, $percent)),
      'cursor' => $cursor,
      'total' => $total,
      'stage_current' => $stage_current,
      'stage_total' => $stage_total,
      'stage_status' => $stage_status,
      'remote_actions' => max(0, (int) ($job['remote_actions'] ?? 0)),
      'model' => sanitize_text_field((string) ($job['stage_model'] ?? '')),
      'message' => $message,
      'created' => max(0, (int) ($job['created'] ?? 0)),
      'failed' => max(0, (int) ($job['failed'] ?? 0)),
      'skipped' => max(0, (int) ($job['skipped'] ?? 0)),
    ]);
  }

  private static function acquire_lock(): bool {
    $now = time();
    if (add_option(self::LOCK, (string) $now, '', false)) return true;

    $locked_at = (int) get_option(self::LOCK, 0);
    if ($locked_at > 0 && ($now - $locked_at) <= 120) return false;

    delete_option(self::LOCK);
    return add_option(self::LOCK, (string) $now, '', false);
  }

  /** @param array<string,mixed> $job */
  private static function schedule_or_complete(array $job): void {
    $cursor = (int) ($job['cursor'] ?? 0);
    $count = isset($job['source_ids']) && is_array($job['source_ids']) ? count($job['source_ids']) : 0;
    if ($cursor >= $count) self::complete($job); else self::schedule_next();
  }

  /** @param array<string,mixed> $job */
  private static function complete(array $job): void {
    $job['status'] = AITranslation::STATUS_COMPLETED;
    $job['updated_at'] = gmdate('c');
    $job['completed_at'] = gmdate('c');
    update_option(self::OPT_JOB, $job, false);
    wp_clear_scheduled_hook(self::HOOK);
  }

  private static function schedule_next(): void {
    if (!wp_next_scheduled(self::HOOK)) wp_schedule_single_event(time() + 10, self::HOOK);
  }

  private static function redirect_with(string $key, string $message): void {
    set_transient('ypm_ai_notice_' . get_current_user_id(), ['key' => $key, 'message' => $message], 60);
    wp_safe_redirect(admin_url('admin.php?page=ypm-translations'));
    exit;
  }
}
