<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Continuous translation autopilot.
 *
 * Autopilot is opt-in and review-first. Content saves only enqueue local work;
 * remote AI is performed by a durable background runner, one queue item at a
 * time; large content may advance through several bounded stages. Existing translations are never overwritten automatically: source drift
 * becomes a professional review job instead.
 */
final class Autopilot {
  public const TABLE_SUFFIX = 'ymulti_autopilot_queue';
  public const OPT_SETTINGS = 'ymulti_autopilot_settings_v1';
  public const OPT_DAILY = 'ymulti_autopilot_daily_v1';
  private const HOOK = 'yablonsky_multilingual_autopilot_tick';
  private const QUEUE_VIEW_CACHE_KEY = 'ymulti_autopilot_latest_40_v1';
  private const QUEUE_VIEW_CACHE_GROUP = 'yablonsky_multilingual';
  private const LOCK = 'ymulti_autopilot_lock_v1';
  private const WATCHDOG = 'ymulti_autopilot_watchdog_v1';
  private const OPT_RUNNER_EVIDENCE = 'ymulti_autopilot_runner_evidence_v1';
  private const GROUP = 'ymulti';
  private const MAX_BACKLOG_POSTS = 500;
  private const MAX_BACKLOG_TERMS = 500;
  private const MAX_BACKLOG_MEDIA = 500;

  public static function init(): void {
    add_action(self::HOOK, [__CLASS__, 'cron_tick']);
    add_action('save_post', [__CLASS__, 'on_save_post'], 165, 3);
    add_action('created_term', [__CLASS__, 'on_term_change'], 165, 3);
    add_action('edited_term', [__CLASS__, 'on_term_change'], 165, 3);
    add_action('add_attachment', [__CLASS__, 'on_attachment_change'], 165);
    add_action('edit_attachment', [__CLASS__, 'on_attachment_change'], 165);

    if (!is_admin()) return;
    add_action('admin_init', [__CLASS__, 'watchdog']);
    add_action('admin_menu', [__CLASS__, 'menu'], 31);
    add_action('admin_post_ymulti_autopilot_save', [__CLASS__, 'handle_save']);
    add_action('admin_post_ymulti_autopilot_backlog', [__CLASS__, 'handle_backlog']);
    add_action('admin_post_ymulti_autopilot_run', [__CLASS__, 'handle_run']);
    add_action('admin_post_ymulti_autopilot_pause', [__CLASS__, 'handle_pause']);
    add_action('admin_post_ymulti_autopilot_resume', [__CLASS__, 'handle_resume']);
    add_action('admin_post_ymulti_autopilot_clear', [__CLASS__, 'handle_clear']);
  }

  public static function table(): string {
    global $wpdb;
    return $wpdb->prefix . self::TABLE_SUFFIX;
  }

  public static function install_table(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = self::table();
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      queue_key CHAR(40) NOT NULL,
      kind VARCHAR(32) NOT NULL,
      source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      source_type VARCHAR(64) NOT NULL DEFAULT '',
      taxonomy VARCHAR(64) NOT NULL DEFAULT '',
      source_locale VARCHAR(32) NOT NULL DEFAULT '',
      target_locale VARCHAR(32) NOT NULL DEFAULT '',
      source_hash CHAR(64) NOT NULL DEFAULT '',
      status VARCHAR(24) NOT NULL DEFAULT 'queued',
      attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      result_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      payload_json LONGTEXT NOT NULL,
      message TEXT NOT NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      processed_at DATETIME NULL DEFAULT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ymulti_queue_key (queue_key),
      KEY ymulti_status_id (status(24), id),
      KEY ymulti_source (source_id, kind(24)),
      KEY ymulti_target (target_locale(32), status(24)),
      KEY ymulti_updated (updated_at)
    ) {$charset};";
    dbDelta($sql);
  }

  /** @return array<string,mixed> */
  public static function settings(): array {
    $raw = get_option(self::OPT_SETTINGS, []);
    if (!is_array($raw)) $raw = [];
    $active = Languages::active_codes();
    $source = sanitize_text_field((string) ($raw['source_locale'] ?? Languages::default_code()));
    if (!in_array($source, $active, true)) $source = Languages::default_code();
    $targets = isset($raw['target_locales']) && is_array($raw['target_locales']) ? array_values(array_unique(array_filter(array_map('sanitize_text_field', $raw['target_locales'])))) : [];
    $targets = array_values(array_filter($targets, static function(string $code) use ($active, $source): bool { return $code !== $source && in_array($code, $active, true); }));
    $types = isset($raw['post_types']) && is_array($raw['post_types']) ? array_values(array_unique(array_filter(array_map('sanitize_key', $raw['post_types'])))) : [];
    $allowed_types = Languages::supported_post_types();
    $types = array_values(array_intersect($types, $allowed_types));
    if (!$types) $types = array_values(array_intersect(['post','page','product'], $allowed_types));
    $taxonomies = isset($raw['taxonomies']) && is_array($raw['taxonomies']) ? array_values(array_unique(array_filter(array_map('sanitize_key', $raw['taxonomies'])))) : [];
    $allowed_taxonomies = class_exists('Yablonsky\\Multilingual\\AdminTerms') ? AdminTerms::manageable_taxonomies() : [];
    $taxonomies = array_values(array_intersect($taxonomies, $allowed_taxonomies));
    $cap = (int) ($raw['daily_cap'] ?? 100);
    if (!in_array($cap, [0,25,50,100,250,500], true)) $cap = 100;
    return [
      'enabled' => !empty($raw['enabled']),
      'paused' => !empty($raw['paused']),
      'owner_user_id' => absint($raw['owner_user_id'] ?? 0),
      'source_locale' => $source,
      'target_locales' => $targets,
      'post_types' => $types,
      'taxonomies' => $taxonomies,
      'content_missing' => array_key_exists('content_missing', $raw) ? !empty($raw['content_missing']) : true,
      'content_stale' => !empty($raw['content_stale']),
      'terms' => !empty($raw['terms']),
      'media' => !empty($raw['media']),
      'daily_cap' => $cap,
    ];
  }

  public static function enabled(): bool {
    $s = self::settings();
    return !empty($s['enabled']) && empty($s['paused']);
  }

  /** @return array<string,int> */
  public static function stats(): array {
    global $wpdb;
    $table = esc_sql(self::table());
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Admin/background aggregate on fixed YMulti-owned queue table.
    $rows = $wpdb->get_results('SELECT status, COUNT(*) AS total FROM ' . $table . ' GROUP BY status', ARRAY_A);
    $out = ['queued'=>0,'processing'=>0,'review_ready'=>0,'completed'=>0,'skipped'=>0,'failed'=>0];
    if (is_array($rows)) foreach ($rows as $row) {
      $key = sanitize_key((string) ($row['status'] ?? ''));
      if (isset($out[$key])) $out[$key] = max(0, (int) ($row['total'] ?? 0));
    }
    return $out;
  }

  public static function pending_count(): int {
    $stats = self::stats();
    return (int) $stats['queued'] + (int) $stats['processing'];
  }


  /** @return array<int,array<string,mixed>> */
  public static function review_rows(int $limit = 12): array {
    global $wpdb;
    $limit = max(1, min(50, $limit));
    $table = esc_sql(self::table());
    // The table identifier is generated only from $wpdb->prefix + self::TABLE_SUFFIX; all value data remains prepared.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bounded admin review projection on a fixed YMulti-owned queue table.
    $rows = $wpdb->get_results($wpdb->prepare(
      'SELECT * FROM ' . $table . ' WHERE status=%s ORDER BY id DESC LIMIT %d',
      'review_ready', $limit
    ), ARRAY_A);
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
    return is_array($rows) ? $rows : [];
  }

  /** @param array<string,mixed> $row @return array{url:string,label:string} */
  public static function review_link(array $row): array {
    return self::result_link($row);
  }


  /** @return array<string,mixed> */
  public static function term_suggestion_for(int $source_id, string $taxonomy, string $target_locale): array {
    if ($source_id <= 0 || $taxonomy === '' || $target_locale === '') return [];
    global $wpdb;
    $table = esc_sql(self::table());
    // The table identifier is generated only from $wpdb->prefix + self::TABLE_SUFFIX; all value data remains prepared.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Indexed review lookup on a fixed YMulti-owned queue table.
    $json = $wpdb->get_var($wpdb->prepare(
      'SELECT payload_json FROM ' . $table . ' WHERE kind=%s AND source_id=%d AND taxonomy=%s AND target_locale=%s AND status=%s ORDER BY id DESC LIMIT 1',
      'term_missing', $source_id, $taxonomy, $target_locale, 'review_ready'
    ));
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
    if (!is_string($json) || $json === '') return [];
    $payload = json_decode($json, true);
    return is_array($payload) ? $payload : [];
  }

  public static function on_save_post(int $post_id, \WP_Post $post, bool $update): void {
    unset($update);
    if (!self::enabled() || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
    $settings = self::settings();
    if (!in_array((string) $post->post_type, (array) $settings['post_types'], true)) return;
    if (!in_array((string) $post->post_status, ['publish','private'], true)) return;
    if (RuntimeAuthority::effective_post_language($post_id) !== (string) $settings['source_locale']) return;
    self::enqueue_post_targets($post_id, $settings);
  }

  public static function on_term_change(int $term_id, int $tt_id = 0, string $taxonomy = ''): void {
    unset($tt_id);
    if (!self::enabled()) return;
    $settings = self::settings();
    if (empty($settings['terms']) || !in_array($taxonomy, (array) $settings['taxonomies'], true) || !TermTranslations::is_supported_taxonomy($taxonomy)) return;
    if (RuntimeAuthority::effective_term_language($term_id, $taxonomy) !== (string) $settings['source_locale']) return;
    $term = get_term($term_id, $taxonomy);
    if (!($term instanceof \WP_Term)) return;
    $source_hash = hash('sha256', (string) wp_json_encode([(string) $term->name, (string) $term->slug, (string) $term->description], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    foreach ((array) $settings['target_locales'] as $target) {
      if (EffectiveRelations::existing_term_target($term_id, $taxonomy, (string) $target) > 0) continue;
      self::enqueue('term_missing', $term_id, 'term', $taxonomy, (string) $settings['source_locale'], $target, $source_hash);
    }
    self::schedule_next();
  }

  public static function on_attachment_change(int $attachment_id): void {
    if (!self::enabled()) return;
    $settings = self::settings();
    if (empty($settings['media']) || $attachment_id <= 0 || strpos((string) get_post_mime_type($attachment_id), 'image/') !== 0) return;
    if (RuntimeAuthority::effective_post_language($attachment_id) !== (string) $settings['source_locale']) return;
    $path = get_attached_file($attachment_id);
    $source_hash = '';
    if (is_string($path) && $path !== '' && is_file($path)) {
      $hash = hash_file('sha256', $path);
      $source_hash = is_string($hash) ? $hash : '';
    }
    if ($source_hash === '') $source_hash = hash('sha256', (string) $attachment_id . '|' . (string) get_the_title($attachment_id));
    foreach ((array) $settings['target_locales'] as $target) {
      $record = MediaLocalization::record($attachment_id, $target);
      $suggestion = MediaLocalization::ai_suggestion($attachment_id, $target);
      if (self::media_record_complete($record) || self::media_record_complete($suggestion)) continue;
      self::enqueue('media_metadata', $attachment_id, 'attachment', '', (string) $settings['source_locale'], $target, $source_hash);
    }
    self::schedule_next();
  }

  /** @param array<string,mixed> $settings */
  private static function enqueue_post_targets(int $post_id, array $settings): void {
    $source_hash = TranslationFreshness::source_hash($post_id);
    $effective = EffectiveRelations::post_family($post_id);
    $existing = is_array($effective['translations'] ?? null) ? $effective['translations'] : [];
    foreach ((array) $settings['target_locales'] as $target) {
      $target_id = absint($existing[$target] ?? 0);
      if ($target_id <= 0) {
        if (!empty($settings['content_missing'])) self::enqueue('content_missing', $post_id, (string) get_post_type($post_id), '', (string) $settings['source_locale'], $target, $source_hash);
        continue;
      }
      if (!empty($settings['content_stale']) && class_exists('Yablonsky\\Multilingual\\TranslationFreshness')) {
        $freshness = TranslationFreshness::status($target_id);
        if ((string) ($freshness['state'] ?? '') === 'attention') self::enqueue('content_stale', $post_id, (string) get_post_type($post_id), '', (string) $settings['source_locale'], $target, $source_hash);
      }
    }
    self::schedule_next();
  }

  private static function enqueue(string $kind, int $source_id, string $source_type, string $taxonomy, string $source_locale, string $target_locale, string $source_hash): void {
    if ($source_id <= 0 || $target_locale === '' || $source_locale === '' || $source_locale === $target_locale) return;
    global $wpdb;
    $table = self::table();
    $key = sha1($kind . '|' . $source_type . '|' . $taxonomy . '|' . $source_id . '|' . $source_locale . '|' . $target_locale . '|' . $source_hash);
    $now = current_time('mysql', true);
    $data = [
      'queue_key'=>$key,'kind'=>$kind,'source_id'=>$source_id,'source_type'=>$source_type,'taxonomy'=>$taxonomy,
      'source_locale'=>$source_locale,'target_locale'=>$target_locale,'source_hash'=>$source_hash,'status'=>'queued','attempts'=>0,
      'result_id'=>0,'payload_json'=>'','message'=>'','created_at'=>$now,'updated_at'=>$now,'processed_at'=>null,
    ];
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Write-side durable queue on YMulti-owned table; duplicate queue keys are ignored safely.
    $inserted = $wpdb->query($wpdb->prepare(
      'INSERT IGNORE INTO ' . esc_sql($table) . ' (queue_key,kind,source_id,source_type,taxonomy,source_locale,target_locale,source_hash,status,attempts,result_id,payload_json,message,created_at,updated_at,processed_at) VALUES (%s,%s,%d,%s,%s,%s,%s,%s,%s,%d,%d,%s,%s,%s,%s,NULL)',
      $data['queue_key'],$data['kind'],$data['source_id'],$data['source_type'],$data['taxonomy'],$data['source_locale'],$data['target_locale'],$data['source_hash'],$data['status'],$data['attempts'],$data['result_id'],$data['payload_json'],$data['message'],$data['created_at'],$data['updated_at']
    ));
    if ($inserted === 1) self::flush_queue_view_cache();
  }

  public static function cron_tick(): void { self::process_one(); }

  public static function process_one(): void {
    if (!self::enabled() || !self::acquire_lock()) return;
    try {
      $settings = self::settings();
      $owner = absint($settings['owner_user_id'] ?? 0);
      global $wpdb;
      $table = self::table();
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- One indexed background queue lookup on fixed YMulti table.
      $row = $wpdb->get_row('SELECT * FROM ' . esc_sql($table) . " WHERE status='queued' ORDER BY id ASC LIMIT 1", ARRAY_A);
      if (!is_array($row)) return;
      $id = absint($row['id'] ?? 0);
      if ($id <= 0) return;
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic claim of one queued task.
      $claimed = $wpdb->update($table, ['status'=>'processing','updated_at'=>current_time('mysql', true)], ['id'=>$id,'status'=>'queued'], ['%s','%s'], ['%d','%s']);
      if ($claimed !== 1) return;
      self::flush_queue_view_cache();
      $row['status'] = 'processing';

      $kind = sanitize_key((string) ($row['kind'] ?? ''));
      $needs_ai = in_array($kind, ['content_missing','term_missing','media_metadata'], true);
      if ($needs_ai) {
        if ($owner <= 0 || !user_can($owner, AITranslation::compute_capability())) {
          self::finish($id, 'failed', 0, [], __('Autopilot owner authority changed. Assign AI compute authority to continue this task.', 'yablonsky-multilingual'));
          return;
        }
        if ($kind === 'content_missing') {
          $plan = AITranslation::content_stage_plan(absint($row['source_id'] ?? 0), sanitize_text_field((string) ($row['target_locale'] ?? '')));
          if (is_wp_error($plan)) {
            self::finish($id, 'failed', 0, [], sanitize_text_field($plan->get_error_message()));
            return;
          }
          $planned_requests = max(0, absint($plan['stages'] ?? 0));
          $remaining = self::daily_remaining((int) $settings['daily_cap']);
          if ($planned_requests > 0 && $remaining < $planned_requests) {
            self::requeue($id, sprintf(
              /* translators: 1: remaining remote AI requests today, 2: requests needed for this staged item. */
              __('Daily AI budget has room for %1$d request(s), but this item needs %2$d safe stage(s). It will wait rather than start a partial translation.', 'yablonsky-multilingual'),
              $remaining,
              $planned_requests
            ), 3600);
            return;
          }
          self::update_processing_progress($id, [
            'staging' => [
              'stage' => 0,
              'total' => $planned_requests,
              'estimated_input_tokens' => absint($plan['estimated_input_tokens'] ?? 0),
              'model' => sanitize_text_field((string) (($plan['capacity']['model'] ?? '') ?: '')),
              'status' => $planned_requests > 0 ? 'planned' : 'memory',
            ],
          ], $planned_requests > 0 ? sprintf(
            /* translators: %d: number of bounded AI stages planned for the item. */
            _n('Preparing %d safe AI stage.', 'Preparing %d safe AI stages.', $planned_requests, 'yablonsky-multilingual'),
            $planned_requests
          ) : __('Translation Memory can satisfy this item locally and preserve the remote AI budget.', 'yablonsky-multilingual'));
        } elseif (!self::within_daily_cap((int) $settings['daily_cap'])) {
          self::requeue($id, __('Daily Autopilot AI request limit reached; the task remains queued.', 'yablonsky-multilingual'), 3600);
          return;
        }
      }

      $result = self::execute($row, $owner, (int) $settings['daily_cap']);
      if (is_wp_error($result)) {
        $attempts = max(0, (int) ($row['attempts'] ?? 0)) + 1;
        $retryable = $attempts < 3 && self::retryable_error($result);
        if ($retryable) {
          self::requeue($id, sanitize_text_field($result->get_error_message()), 60 * $attempts, $attempts);
        } else {
          self::finish($id, 'failed', 0, [], sanitize_text_field($result->get_error_message()), $attempts);
        }
      } else {
        $status = sanitize_key((string) ($result['status'] ?? 'completed'));
        $result_id = absint($result['result_id'] ?? 0);
        $payload = isset($result['payload']) && is_array($result['payload']) ? $result['payload'] : [];
        $message = sanitize_text_field((string) ($result['message'] ?? ''));
        self::finish($id, $status, $result_id, $payload, $message, max(0, (int) ($row['attempts'] ?? 0)) + ($needs_ai ? 1 : 0));
        if ($needs_ai && $kind !== 'content_missing') self::increment_daily_usage();
      }
    } finally {
      delete_option(self::LOCK);
      if (self::enabled() && self::pending_count() > 0) self::schedule_next();
    }
  }

  /** @param array<string,mixed> $row @return array<string,mixed>|\WP_Error */
  private static function execute(array $row, int $owner, int $daily_cap) {
    $kind = sanitize_key((string) ($row['kind'] ?? ''));
    $source_id = absint($row['source_id'] ?? 0);
    $target = sanitize_text_field((string) ($row['target_locale'] ?? ''));
    if ($source_id <= 0 || !EntityContract::post_target_allowed($source_id, $target)) return new \WP_Error('ymulti_autopilot_invalid', __('Autopilot task scope changed. Refresh the source and target selection.', 'yablonsky-multilingual'));

    if ($kind === 'content_missing') {
      if (!AITranslation::ready()) return new \WP_Error('ymulti_autopilot_ai', __('Verify the text AI route to continue.', 'yablonsky-multilingual'));
      if (EntityContract::existing_post_target($source_id, $target) > 0) return ['status'=>'skipped','message'=>__('Target translation already exists.', 'yablonsky-multilingual')];
      $queue_id = absint($row['id'] ?? 0);
      $stage_progress = static function (array $stage) use ($queue_id): void {
        if ($queue_id <= 0) return;
        $current = max(0, absint($stage['stage'] ?? 0));
        $total = max(1, absint($stage['total'] ?? 1));
        $status = sanitize_key((string) ($stage['status'] ?? 'running'));
        self::update_processing_progress($queue_id, ['staging' => [
          'stage' => $current,
          'total' => $total,
          'estimated_input_tokens' => absint($stage['estimated_input_tokens'] ?? 0),
          'segment_count' => absint($stage['segment_count'] ?? 0),
          'model' => sanitize_text_field((string) ($stage['model'] ?? '')),
          'route' => sanitize_key((string) ($stage['route'] ?? '')),
          'status' => $status,
        ]], sprintf(
          /* translators: 1: current AI stage, 2: total AI stages. */
          __('Translating AI stage %1$d of %2$d.', 'yablonsky-multilingual'),
          max(1, $current),
          $total
        ));
      };
      $stage_gate = static function () use ($daily_cap) {
        if (!self::within_daily_cap($daily_cap)) {
          return new \WP_Error('ymulti_autopilot_daily_cap', __('Daily Autopilot AI request limit was reached before the next stage.', 'yablonsky-multilingual'));
        }
        self::increment_daily_usage();
        return true;
      };
      $draft_id = AITranslation::create_content_draft($source_id, $target, $owner, $stage_progress, $stage_gate);
      if (is_wp_error($draft_id)) return $draft_id;
      return ['status'=>'review_ready','result_id'=>(int) $draft_id,'message'=>__('AI translation draft is ready for human review.', 'yablonsky-multilingual')];
    }

    if ($kind === 'content_stale') {
      $target_id = EffectiveRelations::existing_target($source_id, $target);
      if ($target_id <= 0) return ['status'=>'skipped','message'=>__('Create the target translation to continue this review.', 'yablonsky-multilingual')];
      $freshness = TranslationFreshness::status($target_id);
      if ((string) ($freshness['state'] ?? '') !== 'attention') return ['status'=>'skipped','message'=>__('Target translation is already current.', 'yablonsky-multilingual')];
      $job_id = TranslationJobs::create_job($source_id, $target, 0, 7, '', __('Autopilot detected source changes. Review the existing translation before treating it as current.', 'yablonsky-multilingual'));
      if (is_wp_error($job_id)) {
        if ($job_id->get_error_code() === 'ymulti_job_exists') return ['status'=>'review_ready','message'=>__('An active review job already exists for this stale translation.', 'yablonsky-multilingual')];
        return $job_id;
      }
      return ['status'=>'review_ready','result_id'=>(int) $job_id,'message'=>__('Source drift was converted into a professional review job.', 'yablonsky-multilingual')];
    }

    if ($kind === 'term_missing') {
      if (!AITranslation::ready()) return new \WP_Error('ymulti_autopilot_ai', __('Verify the text AI route to continue.', 'yablonsky-multilingual'));
      $taxonomy = sanitize_key((string) ($row['taxonomy'] ?? ''));
      if (!TermTranslations::is_supported_taxonomy($taxonomy)) return new \WP_Error('ymulti_autopilot_term', __('Add this taxonomy to multilingual scope to continue.', 'yablonsky-multilingual'));
      if (EffectiveRelations::existing_term_target($source_id, $taxonomy, $target) > 0) return ['status'=>'skipped','message'=>__('Term translation already exists.', 'yablonsky-multilingual')];
      $suggestion = AITranslation::suggest_term($source_id, $taxonomy, $target);
      if (is_wp_error($suggestion)) return $suggestion;
      return ['status'=>'review_ready','payload'=>$suggestion,'message'=>__('AI term suggestion is ready for review and native term creation.', 'yablonsky-multilingual')];
    }

    if ($kind === 'media_metadata') {
      if (!AITranslation::vision_ready()) return new \WP_Error('ymulti_autopilot_vision', __('Verify the Vision route to continue.', 'yablonsky-multilingual'));
      $record = MediaLocalization::record($source_id, $target);
      $suggestion = MediaLocalization::ai_suggestion($source_id, $target);
      if (self::media_record_complete($record) || self::media_record_complete($suggestion)) return ['status'=>'skipped','message'=>__('Media metadata already exists or has a complete saved AI draft.', 'yablonsky-multilingual')];
      $analysis = AITranslation::analyze_image_attachment($source_id, $target);
      if (is_wp_error($analysis)) return $analysis;
      MediaLocalization::save_ai_suggestion($source_id, $target, $analysis);
      return ['status'=>'review_ready','payload'=>['model'=>sanitize_text_field((string) ($analysis['model'] ?? ''))],'message'=>__('Vision metadata draft is ready for human review.', 'yablonsky-multilingual')];
    }

    return new \WP_Error('ymulti_autopilot_kind', __('Unknown Autopilot task type.', 'yablonsky-multilingual'));
  }

  /** @param array<string,mixed> $record */
  private static function media_record_complete(array $record): bool {
    foreach (['title','alt','caption','description'] as $field) if (trim((string) ($record[$field] ?? '')) === '') return false;
    return true;
  }

  private static function retryable_error(\WP_Error $error): bool {
    $code = sanitize_key((string) $error->get_error_code());
    foreach (['http','stream','busy','timeout','tempor','rate','429','5'] as $needle) if (strpos($code, $needle) !== false) return true;
    return false;
  }

  private static function requeue(int $id, string $message, int $delay, int $attempts = -1): void {
    global $wpdb;
    $data = ['status'=>'queued','message'=>$message,'updated_at'=>current_time('mysql', true)];
    $formats = ['%s','%s','%s'];
    if ($attempts >= 0) { $data['attempts'] = $attempts; $formats[] = '%d'; }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded queue state update.
    $wpdb->update(self::table(), $data, ['id'=>$id], $formats, ['%d']);
    self::flush_queue_view_cache();
    self::schedule_next($delay);
  }

  /** @param array<string,mixed> $payload */
  private static function update_processing_progress(int $id, array $payload, string $message): void {
    if ($id <= 0) return;
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Durable progress evidence for one claimed YMulti queue row.
    $wpdb->update(self::table(), [
      'payload_json' => (string) wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      'message' => $message,
      'updated_at' => current_time('mysql', true),
    ], ['id' => $id, 'status' => 'processing'], ['%s','%s','%s'], ['%d','%s']);
    self::flush_queue_view_cache();
  }

  /** @param array<string,mixed> $payload */
  private static function finish(int $id, string $status, int $result_id, array $payload, string $message, int $attempts = 0): void {
    global $wpdb;
    $now = current_time('mysql', true);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded queue state update.
    $wpdb->update(self::table(), [
      'status'=>$status,'attempts'=>$attempts,'result_id'=>$result_id,
      'payload_json'=>(string) wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      'message'=>$message,'updated_at'=>$now,'processed_at'=>$now,
    ], ['id'=>$id], ['%s','%d','%d','%s','%s','%s','%s'], ['%d']);
    self::flush_queue_view_cache();
  }

  private static function acquire_lock(): bool {
    $now = time();
    if (add_option(self::LOCK, (string) $now, '', false)) return true;
    $locked = (int) get_option(self::LOCK, 0);
    if ($locked > 0 && ($now - $locked) <= 180) return false;
    delete_option(self::LOCK);
    return add_option(self::LOCK, (string) $now, '', false);
  }

  /** @return array{engine:string,next:int,healthy:bool,pending:int,recovered:int,trimmed:int,watchdog_at:int,rescheduled:bool} */
  public static function runner_state(): array {
    $pending = self::pending_count();
    $engine = function_exists('as_next_scheduled_action') && function_exists('as_schedule_single_action') ? 'action_scheduler' : 'wp_cron';
    $next = 0;
    if ($engine === 'action_scheduler') {
      $scheduled = as_next_scheduled_action(self::HOOK, [], self::GROUP);
      if (is_numeric($scheduled)) $next = max(0, (int) $scheduled);
    } else {
      $scheduled = wp_next_scheduled(self::HOOK);
      if ($scheduled !== false) $next = max(0, (int) $scheduled);
    }
    $settings = self::settings();
    $enabled = !empty($settings['enabled']) && empty($settings['paused']);
    $evidence = get_option(self::OPT_RUNNER_EVIDENCE, []);
    $evidence = is_array($evidence) ? $evidence : [];
    return [
      'engine' => $engine,
      'next' => $next,
      'healthy' => !$enabled || $pending === 0 || $next > 0,
      'pending' => $pending,
      'recovered' => max(0, (int) ($evidence['recovered'] ?? 0)),
      'trimmed' => max(0, (int) ($evidence['trimmed'] ?? 0)),
      'watchdog_at' => max(0, (int) ($evidence['at'] ?? 0)),
      'rescheduled' => !empty($evidence['rescheduled']),
    ];
  }

  /**
   * Admin-only bounded watchdog. It never performs AI compute itself: it only
   * recovers abandoned processing leases, trims old terminal history and makes
   * sure a pending durable queue still has a scheduler continuation.
   */
  public static function watchdog(): void {
    if (get_transient(self::WATCHDOG)) return;
    set_transient(self::WATCHDOG, '1', 5 * MINUTE_IN_SECONDS);
    if (!self::enabled()) return;

    global $wpdb;
    $table = esc_sql(self::table());
    $stale_before = gmdate('Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS);
    // The table identifier is generated only from $wpdb->prefix + self::TABLE_SUFFIX; all value data remains prepared.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Admin-only lease recovery on a fixed YMulti-owned queue table; cutoff is a prepared scalar.
    $recovered = (int) $wpdb->query($wpdb->prepare(
      'UPDATE ' . $table . ' SET status=%s, message=%s, updated_at=%s WHERE status=%s AND updated_at < %s',
      'queued', __('Recovered after an interrupted background lease.', 'yablonsky-multilingual'), current_time('mysql', true), 'processing', $stale_before
    ));
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

    $history_before = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
    // The table identifier is generated only from $wpdb->prefix + self::TABLE_SUFFIX; all value data remains prepared.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bounded cleanup of old terminal YMulti queue history; never touches pending/review/failed records.
    $trimmed = (int) $wpdb->query($wpdb->prepare(
      'DELETE FROM ' . $table . ' WHERE status IN (%s,%s) AND processed_at IS NOT NULL AND processed_at < %s ORDER BY id ASC LIMIT %d',
      'completed', 'skipped', $history_before, 200
    ));
    if ($recovered > 0 || $trimmed > 0) self::flush_queue_view_cache();
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

    $rescheduled = false;
    if (self::pending_count() > 0) {
      $runner = self::runner_state();
      if (empty($runner['next'])) {
        self::schedule_next(5);
        $rescheduled = true;
      }
    }
    update_option(self::OPT_RUNNER_EVIDENCE, [
      'at' => time(),
      'recovered' => max(0, $recovered),
      'trimmed' => max(0, $trimmed),
      'rescheduled' => $rescheduled ? 1 : 0,
    ], false);
  }

  public static function schedule_next(int $delay = 15): void {
    if (!self::enabled() || self::pending_count() <= 0) return;
    $delay = max(5, $delay);
    if (function_exists('as_next_scheduled_action') && function_exists('as_schedule_single_action')) {
      $next = as_next_scheduled_action(self::HOOK, [], self::GROUP);
      if (!$next) as_schedule_single_action(time() + $delay, self::HOOK, [], self::GROUP, true);
      return;
    }
    if (!wp_next_scheduled(self::HOOK)) wp_schedule_single_event(time() + $delay, self::HOOK);
  }

  public static function unschedule(): void {
    wp_clear_scheduled_hook(self::HOOK);
    if (function_exists('as_unschedule_all_actions')) as_unschedule_all_actions(self::HOOK, [], self::GROUP);
  }

  private static function within_daily_cap(int $cap): bool {
    return self::daily_remaining($cap) > 0;
  }

  private static function daily_remaining(int $cap): int {
    if ($cap === 0) return PHP_INT_MAX;
    $usage = get_option(self::OPT_DAILY, []);
    $count = is_array($usage) && (string) ($usage['date'] ?? '') === gmdate('Y-m-d') ? max(0, (int) ($usage['count'] ?? 0)) : 0;
    return max(0, $cap - $count);
  }

  private static function increment_daily_usage(int $amount = 1): void {
    $date = gmdate('Y-m-d');
    $usage = get_option(self::OPT_DAILY, []);
    if (!is_array($usage) || (string) ($usage['date'] ?? '') !== $date) $usage = ['date'=>$date,'count'=>0];
    $usage['count'] = max(0, (int) ($usage['count'] ?? 0)) + max(0, $amount);
    update_option(self::OPT_DAILY, $usage, false);
  }

  /** @return array{date:string,count:int,cap:int} */
  public static function daily_state(): array {
    $settings = self::settings();
    $usage = get_option(self::OPT_DAILY, []);
    $count = is_array($usage) && (string) ($usage['date'] ?? '') === gmdate('Y-m-d') ? max(0, (int) ($usage['count'] ?? 0)) : 0;
    return ['date'=>gmdate('Y-m-d'),'count'=>$count,'cap'=>(int) $settings['daily_cap']];
  }

  public static function menu(): void {
    add_submenu_page('ypm-multilingual', __('Autopilot', 'yablonsky-multilingual'), __('Autopilot', 'yablonsky-multilingual'), 'manage_options', 'ypm-autopilot', [__CLASS__, 'page']);
  }

  public static function page(): void {
    if (!current_user_can('manage_options')) return;
    self::install_table();
    $s = self::settings();
    $stats = self::stats();
    $daily = self::daily_state();
    $state = empty($s['enabled']) ? 'neutral' : (!empty($s['paused']) ? 'attention' : 'pass');
    $label = empty($s['enabled']) ? __('Ready', 'yablonsky-multilingual') : (!empty($s['paused']) ? __('Standby', 'yablonsky-multilingual') : __('Active', 'yablonsky-multilingual'));
    echo '<div class="wrap ypm-admin ypm-admin--autopilot">';
    Admin::render_shell_header(
      __('Autopilot', 'yablonsky-multilingual'),
      __('Turn multilingual gaps into structured review work while visitor requests stay lightweight. Content gaps become AI drafts, source changes become review jobs, and terms and media stay suggestion-first.', 'yablonsky-multilingual'),
      $state,
      $label
    );
    self::notice();
    $text_setup = AITranslation::text_setup_status();
    if (empty($text_setup['ready'])) {
      AIAdmin::render_text_compute_gate($text_setup, admin_url('admin.php?page=ypm-autopilot'));
    } elseif (!empty($s['media'])) {
      $vision_setup = AITranslation::vision_setup_status();
      if (empty($vision_setup['ready'])) AIAdmin::render_vision_compute_gate($vision_setup, admin_url('admin.php?page=ypm-autopilot'));
    }

    echo '<div class="ypm-metrics ypm-metrics--5">';
    Admin::render_metric(__('Queued', 'yablonsky-multilingual'), (string) $stats['queued'], __('Waiting background tasks', 'yablonsky-multilingual'), $stats['queued'] > 0 ? 'attention' : 'neutral');
    Admin::render_metric(__('Review ready', 'yablonsky-multilingual'), (string) $stats['review_ready'], __('Drafts / suggestions / review jobs', 'yablonsky-multilingual'), $stats['review_ready'] > 0 ? 'attention' : 'neutral');
    Admin::render_metric(__('Attention', 'yablonsky-multilingual'), (string) $stats['failed'], __('Tasks after bounded retries', 'yablonsky-multilingual'), $stats['failed'] > 0 ? 'attention' : 'pass');
    $cap_label = $daily['cap'] === 0 ? __('Unlimited', 'yablonsky-multilingual') : ((string) $daily['count'] . '/' . (string) $daily['cap']);
    Admin::render_metric(__('AI today', 'yablonsky-multilingual'), $cap_label, __('Remote AI requests', 'yablonsky-multilingual'), 'neutral');
    $runner = self::runner_state();
    $runner_label = (string) ($runner['engine'] ?? '') === 'action_scheduler' ? __('Action Scheduler', 'yablonsky-multilingual') : __('WP-Cron', 'yablonsky-multilingual');
    $runner_detail = !empty($runner['healthy']) ? __('Durable continuation available', 'yablonsky-multilingual') : __('Scheduler recovery available', 'yablonsky-multilingual');
    Admin::render_metric(__('Runner', 'yablonsky-multilingual'), $runner_label, $runner_detail, !empty($runner['healthy']) ? 'pass' : 'attention');
    echo '</div>';
    if (!empty($s['enabled'])) {
      $next_text = !empty($runner['next']) ? wp_date('Y-m-d H:i:s T', (int) $runner['next']) : __('Scheduler standby', 'yablonsky-multilingual');
      $watchdog_text = !empty($runner['watchdog_at']) ? wp_date('Y-m-d H:i:s T', (int) $runner['watchdog_at']) : __('Available after the first watchdog cycle', 'yablonsky-multilingual');
      echo '<div class="ypm-callout ' . esc_attr(!empty($runner['healthy']) ? 'ypm-callout--pass' : 'ypm-callout--attention') . ' ymulti-runner-evidence"><span class="dashicons dashicons-clock" aria-hidden="true"></span><div><strong>' . esc_html__('Background runner evidence', 'yablonsky-multilingual') . '</strong><p>' . esc_html(sprintf(
        /* translators: 1: next scheduled background run, 2: last watchdog time, 3: recovered interrupted leases, 4: trimmed old terminal rows. */
        __('Next cycle: %1$s · watchdog: %2$s · recovered leases: %3$d · trimmed completed rows: %4$d.', 'yablonsky-multilingual'),
        $next_text, $watchdog_text, (int) ($runner['recovered'] ?? 0), (int) ($runner['trimmed'] ?? 0)
      )) . '</p></div></div>';
    }

    self::render_settings($s);
    self::render_queue();
    echo '</div>';
  }

  /** @param array<string,mixed> $s */
  private static function render_settings(array $s): void {
    $active = Languages::active_codes();
    $public = Languages::public_codes();
    $taxonomies = class_exists('Yablonsky\\Multilingual\\AdminTerms') ? AdminTerms::manageable_taxonomies() : [];
    $capacity = AITranslation::text_capacity_profile();
    $capacity_model = sanitize_text_field((string) ($capacity['model'] ?? ''));
    $safe_stage_tokens = max(1, absint($capacity['safe_stage_input_tokens'] ?? 6000));
    $max_source_chars = max(1, absint($capacity['max_source_chars'] ?? 240000));
    $provider_context = absint($capacity['provider_context_tokens'] ?? 0);
    $provider_output = absint($capacity['provider_max_output_tokens'] ?? 0);
    $stats = self::stats();
    $is_active = !empty($s['enabled']) && empty($s['paused']);
    $is_paused = !empty($s['enabled']) && !empty($s['paused']);
    $runtime_label = $is_active ? __('Active', 'yablonsky-multilingual') : ($is_paused ? __('Standby', 'yablonsky-multilingual') : __('Ready', 'yablonsky-multilingual'));
    $runtime_state = $is_active ? 'pass' : ($is_paused ? 'attention' : 'neutral');

    echo '<section class="ypm-card ypm-card--standalone ymulti-autopilot-workspace"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Continuous translation', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Plan, launch and review one clear Autopilot workflow', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('Autopilot prepares multilingual review work through a durable background queue. Bounded AI stages keep large content predictable, while human approval remains the publication gate.', 'yablonsky-multilingual') . '</p></div></div>';

    echo '<div class="ymulti-autopilot-journey" aria-label="' . esc_attr__('Autopilot workflow', 'yablonsky-multilingual') . '">';
    echo '<div class="ymulti-autopilot-step is-ready"><span>1</span><div><strong>' . esc_html__('Configure', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Choose languages, content surfaces and AI behavior.', 'yablonsky-multilingual') . '</small></div></div>';
    echo '<div class="ymulti-autopilot-step is-ready"><span>2</span><div><strong>' . esc_html__('Review plan', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Confirm scope, request budget and staging before launch.', 'yablonsky-multilingual') . '</small></div></div>';
    echo '<div class="ymulti-autopilot-step ' . esc_attr($is_active ? 'is-active' : 'is-ready') . '"><span>3</span><div><strong>' . esc_html__('Launch', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Scan the current scope and start background processing.', 'yablonsky-multilingual') . '</small></div></div>';
    echo '<div class="ymulti-autopilot-step ' . esc_attr($stats['review_ready'] > 0 ? 'is-active' : 'is-ready') . '"><span>4</span><div><strong>' . esc_html__('Review', 'yablonsky-multilingual') . '</strong><small>' . esc_html(sprintf(
      /* translators: %d: number of review-ready Autopilot items. */
      _n('%d item ready for human review.', '%d items ready for human review.', (int) $stats['review_ready'], 'yablonsky-multilingual'),
      (int) $stats['review_ready']
    )) . '</small></div></div>';
    echo '</div>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ymulti-autopilot-settings" id="ymulti-autopilot-settings">';
    wp_nonce_field('ymulti_autopilot_save', 'ymulti_autopilot_nonce');
    echo '<input type="hidden" name="action" value="ymulti_autopilot_save">';

    $selected_taxonomy_count = count(array_intersect($taxonomies, (array) $s['taxonomies']));
    $taxonomy_count_label = sprintf(
      /* translators: %d: number of selected taxonomies. */
      _n('%d selected', '%d selected', $selected_taxonomy_count, 'yablonsky-multilingual'),
      $selected_taxonomy_count
    );
    $open_taxonomy_scope = $selected_taxonomy_count > 0 || !empty($s['terms']);

    echo '<div class="ymulti-autopilot-control-plane">';
    echo '<section class="ymulti-autopilot-panel ymulti-autopilot-panel--scope">';
    echo '<div class="ymulti-autopilot-panel__head"><div><span class="ypm-eyebrow">' . esc_html__('1 · Campaign scope', 'yablonsky-multilingual') . '</span><h3>' . esc_html__('Choose the work Autopilot will prepare', 'yablonsky-multilingual') . '</h3><p>' . esc_html__('Set the source language, target languages and content surfaces. Taxonomy controls expand when term automation is selected.', 'yablonsky-multilingual') . '</p></div></div>';
    echo '<div class="ymulti-autopilot-quicksetup"><div><strong>' . esc_html__('Quick setup', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Start with a focused pilot or select every public target in one action.', 'yablonsky-multilingual') . '</small></div><div class="ymulti-autopilot-presets"><button type="button" class="button" id="ymulti-autopilot-safe-preset">' . esc_html__('Safe pilot', 'yablonsky-multilingual') . '</button><button type="button" class="button" id="ymulti-autopilot-all-targets">' . esc_html__('All public languages', 'yablonsky-multilingual') . '</button><button type="button" class="button button-link" id="ymulti-autopilot-clear-targets">' . esc_html__('Clear target selection', 'yablonsky-multilingual') . '</button></div></div>';
    echo '<label class="ymulti-autopilot-control"><span>' . esc_html__('Source language', 'yablonsky-multilingual') . '</span><select name="source_locale">';
    foreach ($active as $code) echo '<option value="' . esc_attr($code) . '"' . selected((string) $s['source_locale'], $code, false) . '>' . esc_html(Languages::display_name($code) . ' · ' . Languages::display_code($code)) . '</option>';
    echo '</select></label>';

    echo '<div class="ymulti-autopilot-field"><span>' . esc_html__('Target languages', 'yablonsky-multilingual') . '</span><div class="ypm-site-plan-checks">';
    foreach ($active as $code) {
      if ($code === (string) $s['source_locale']) continue;
      echo '<label><input type="checkbox" name="target_locales[]" value="' . esc_attr($code) . '" data-ymulti-public="' . (in_array($code, $public, true) ? '1' : '0') . '"' . checked(in_array($code, (array) $s['target_locales'], true), true, false) . '> <span>' . esc_html(Languages::display_name($code)) . '</span><code>' . esc_html(Languages::display_code($code)) . '</code></label>';
    }
    echo '</div></div>';

    echo '<div class="ymulti-autopilot-scope-groups">';
    echo '<div class="ymulti-autopilot-field"><span>' . esc_html__('Content types', 'yablonsky-multilingual') . '</span><div class="ypm-site-plan-checks">';
    foreach (Languages::supported_post_types() as $post_type) {
      $obj = get_post_type_object($post_type);
      if (!$obj || empty($obj->public)) continue;
      $name = (string) ($obj->labels->name ?? $post_type);
      echo '<label><input type="checkbox" name="post_types[]" value="' . esc_attr($post_type) . '"' . checked(in_array($post_type, (array) $s['post_types'], true), true, false) . '> <span>' . esc_html($name) . '</span><code>' . esc_html($post_type) . '</code></label>';
    }
    echo '</div></div>';
    if ($taxonomies) {
      echo '<details class="ymulti-autopilot-disclosure" data-ymulti-taxonomy-scope' . ($open_taxonomy_scope ? ' open' : '') . '><summary><span><strong>' . esc_html__('Taxonomy scope', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Categories, tags and product attributes', 'yablonsky-multilingual') . '</small></span><b data-ymulti-taxonomy-count>' . esc_html($taxonomy_count_label) . '</b></summary><div class="ypm-site-plan-checks">';
      foreach ($taxonomies as $taxonomy) {
        $obj = get_taxonomy($taxonomy);
        $name = $obj && isset($obj->labels->name) ? (string) $obj->labels->name : $taxonomy;
        echo '<label><input type="checkbox" name="taxonomies[]" value="' . esc_attr($taxonomy) . '"' . checked(in_array($taxonomy, (array) $s['taxonomies'], true), true, false) . '> <span>' . esc_html($name) . '</span><code>' . esc_html($taxonomy) . '</code></label>';
      }
      echo '</div></details>';
    }
    echo '</div></section>';

    echo '<section class="ymulti-autopilot-panel ymulti-autopilot-panel--execution">';
    echo '<div class="ymulti-autopilot-panel__head"><div><span class="ypm-eyebrow">' . esc_html__('2 · AI execution', 'yablonsky-multilingual') . '</span><h3>' . esc_html__('Choose how review work is prepared', 'yablonsky-multilingual') . '</h3><p>' . esc_html__('Behavior, request budget and adaptive staging form one execution policy. Large items move through bounded stages and arrive as review work.', 'yablonsky-multilingual') . '</p></div></div>';
    echo '<div class="ymulti-autopilot-field"><span>' . esc_html__('Behavior', 'yablonsky-multilingual') . '</span><div class="ypm-site-plan-checks">';
    echo '<label><input type="checkbox" name="content_missing" value="1"' . checked(!empty($s['content_missing']), true, false) . '> <span>' . esc_html__('Content gap → AI draft', 'yablonsky-multilingual') . '</span></label>';
    echo '<label><input type="checkbox" name="content_stale" value="1"' . checked(!empty($s['content_stale']), true, false) . '> <span>' . esc_html__('Source change → review job', 'yablonsky-multilingual') . '</span></label>';
    echo '<label><input type="checkbox" name="terms" value="1"' . checked(!empty($s['terms']), true, false) . '> <span>' . esc_html__('Term translation gap → AI suggestions', 'yablonsky-multilingual') . '</span></label>';
    echo '<label><input type="checkbox" name="media" value="1"' . checked(!empty($s['media']), true, false) . '> <span>' . esc_html__('New images → Vision metadata drafts', 'yablonsky-multilingual') . '</span></label>';
    echo '</div></div>';
    echo '<label class="ymulti-autopilot-control ymulti-autopilot-cap"><span>' . esc_html__('Daily AI request limit', 'yablonsky-multilingual') . '</span><select name="daily_cap">';
    foreach ([25,50,100,250,500,0] as $cap) echo '<option value="' . esc_attr((string) $cap) . '"' . selected((int) $s['daily_cap'], $cap, false) . '>' . esc_html($cap === 0 ? __('Unlimited', 'yablonsky-multilingual') : (string) $cap) . '</option>';
    echo '</select><small>' . esc_html__('Counts actual remote AI requests. YMulti starts each item when its planned safe stages fit the remaining daily budget.', 'yablonsky-multilingual') . '</small></label>';
    echo '<div class="ymulti-ai-stage-plan"><div class="ymulti-ai-stage-plan__head"><div><strong>' . esc_html__('Adaptive AI staging', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Large content is split automatically; YMulti creates the WordPress draft after every stage validates.', 'yablonsky-multilingual') . '</small></div><span class="ypm-status-badge ypm-status-badge--pass">' . esc_html__('Automatic', 'yablonsky-multilingual') . '</span></div><div class="ymulti-ai-stage-plan__metrics">';
    echo '<div><span>' . esc_html__('Text model', 'yablonsky-multilingual') . '</span><strong>' . esc_html($capacity_model !== '' ? $capacity_model : __('Workspace default', 'yablonsky-multilingual')) . '</strong></div>';
    echo '<div><span>' . esc_html__('Safe stage', 'yablonsky-multilingual') . '</span><strong>≈' . esc_html(number_format_i18n($safe_stage_tokens)) . ' ' . esc_html__('input tokens', 'yablonsky-multilingual') . '</strong></div>';
    echo '<div><span>' . esc_html__('Local ceiling', 'yablonsky-multilingual') . '</span><strong>' . esc_html(number_format_i18n($max_source_chars)) . ' ' . esc_html__('source chars', 'yablonsky-multilingual') . '</strong></div>';
    echo '</div>';
    if ($provider_context > 0 && $provider_output > 0) {
      echo '<p>' . esc_html(sprintf(
        /* translators: 1: provider context-window tokens, 2: provider maximum output tokens. */
        __('Provider capacity: %1$s context tokens / %2$s output tokens. YMulti uses a smaller operational stage budget for predictable translation JSON, retries and gateway timing.', 'yablonsky-multilingual'),
        number_format_i18n($provider_context),
        number_format_i18n($provider_output)
      )) . '</p>';
    } else {
      echo '<p>' . esc_html__('Route metadata omits provider context/output limits for this model, so YMulti applies its conservative operational stage budget.', 'yablonsky-multilingual') . '</p>';
    }
    echo '</div>';
    echo '<div class="ymulti-autopilot-readiness"><span class="dashicons dashicons-backup" aria-hidden="true"></span><div><strong>' . esc_html__('Production readiness', 'yablonsky-multilingual') . '</strong><p>' . esc_html__('Start with a current database/files backup or staging snapshot. Review-first AI keeps publication under human approval, and the daily request limit keeps compute predictable.', 'yablonsky-multilingual') . '</p><small>' . esc_html__('Manual, selected-row, page and filtered-scope translation workflows remain available alongside Autopilot.', 'yablonsky-multilingual') . '</small></div></div>';
    echo '</section></div>';

    echo '<section class="ymulti-autopilot-launch-card">';
    echo '<div class="ymulti-autopilot-launch-card__head"><div><span class="ypm-eyebrow">' . esc_html__('3 · Launch', 'yablonsky-multilingual') . '</span><h3>' . esc_html__('Review the plan and launch Autopilot', 'yablonsky-multilingual') . '</h3><p>' . esc_html__('The launch plan summarizes the active scope. Save keeps the configuration ready for later; Launch stores the plan, scans the current scope, prepares the backlog and starts continuous background processing.', 'yablonsky-multilingual') . '</p></div><span class="ypm-status-badge ypm-status-badge--' . esc_attr($runtime_state) . '">' . esc_html($runtime_label) . '</span></div>';
    echo '<div class="ymulti-autopilot-launch-grid"><div><strong>' . esc_html__('Launch plan', 'yablonsky-multilingual') . '</strong><div class="ymulti-autopilot-scope-summary" id="ymulti-autopilot-scope-summary" aria-live="polite"><span data-ymulti-scope-targets>0 ' . esc_html__('targets', 'yablonsky-multilingual') . '</span><span data-ymulti-scope-types>0 ' . esc_html__('content types', 'yablonsky-multilingual') . '</span><span data-ymulti-scope-behaviors>0 ' . esc_html__('behaviors', 'yablonsky-multilingual') . '</span><span data-ymulti-scope-cap>' . esc_html__('AI cap', 'yablonsky-multilingual') . ': —</span><span>' . esc_html__('Model', 'yablonsky-multilingual') . ': ' . esc_html($capacity_model !== '' ? $capacity_model : __('workspace default', 'yablonsky-multilingual')) . '</span><span>≈' . esc_html(number_format_i18n($safe_stage_tokens)) . ' ' . esc_html__('tokens/stage', 'yablonsky-multilingual') . '</span></div></div>';
    echo '<div class="ymulti-autopilot-launch-actions"><button class="button" type="submit" name="ymulti_autopilot_intent" value="save">' . esc_html__('Save plan for later', 'yablonsky-multilingual') . '</button><button class="button button-primary ymulti-ai-cta" type="submit" name="ymulti_autopilot_intent" value="launch" id="ymulti-autopilot-launch-button">' . esc_html($is_active ? __('Apply plan & refresh backlog', 'yablonsky-multilingual') : __('Launch Autopilot', 'yablonsky-multilingual')) . '</button></div>';
    echo '</div><p class="ymulti-autopilot-launch-hint" id="ymulti-autopilot-launch-hint" aria-live="polite">' . esc_html__('Choose at least one target language, content type and behavior to make the launch plan ready.', 'yablonsky-multilingual') . '</p>';
    echo '</section></form>';

    echo '<details class="ymulti-autopilot-manual"><summary><span><strong>' . esc_html__('Manual controls', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Testing, queue refresh and runtime maintenance', 'yablonsky-multilingual') . '</small></span></summary><div class="ymulti-autopilot-manual-controls">';
    self::action_form('ymulti_autopilot_backlog', 'ymulti_autopilot_backlog', __('Refresh current backlog', 'yablonsky-multilingual'), 'button');
    self::action_form('ymulti_autopilot_run', 'ymulti_autopilot_run', __('Process one queued item', 'yablonsky-multilingual'), 'button');
    if (!empty($s['paused'])) self::action_form('ymulti_autopilot_resume', 'ymulti_autopilot_resume', __('Resume Autopilot', 'yablonsky-multilingual'), 'button button-primary');
    elseif (!empty($s['enabled'])) self::action_form('ymulti_autopilot_pause', 'ymulti_autopilot_pause', __('Pause Autopilot', 'yablonsky-multilingual'), 'button');
    echo '</div><p class="ymulti-autopilot-saved-settings-warning" id="ymulti-autopilot-saved-settings-warning" hidden>' . esc_html__('Save or launch the updated plan before using manual controls. Manual controls use the last saved plan.', 'yablonsky-multilingual') . '</p></details>';
    echo '</section>';
  }

  private static function action_form(string $action, string $nonce_action, string $label, string $class): void {
    $uses_saved_settings = in_array($action, ['ymulti_autopilot_backlog', 'ymulti_autopilot_run'], true);
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . ($uses_saved_settings ? ' data-ymulti-autopilot-saved-scope="1"' : '') . '>';
    wp_nonce_field($nonce_action, 'ymulti_autopilot_nonce');
    echo '<input type="hidden" name="action" value="' . esc_attr($action) . '"><button class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
  }

  private static function flush_queue_view_cache(): void {
    wp_cache_delete(self::QUEUE_VIEW_CACHE_KEY, self::QUEUE_VIEW_CACHE_GROUP);
  }

  private static function render_queue(): void {
    global $wpdb;
    $active = Languages::active_codes();
    $source_raw = filter_input(INPUT_GET, 'queue_source', FILTER_UNSAFE_RAW);
    $target_raw = filter_input(INPUT_GET, 'queue_target', FILTER_UNSAFE_RAW);
    $status_raw = filter_input(INPUT_GET, 'queue_status', FILTER_UNSAFE_RAW);
    $source_locale = is_string($source_raw) ? sanitize_text_field($source_raw) : '';
    $target_locale = is_string($target_raw) ? sanitize_text_field($target_raw) : '';
    $status_filter = is_string($status_raw) ? sanitize_key($status_raw) : '';
    if ($source_locale !== '' && !in_array($source_locale, $active, true)) $source_locale = '';
    if ($target_locale !== '' && !in_array($target_locale, $active, true)) $target_locale = '';
    $statuses = ['queued','processing','review_ready','completed','skipped','failed'];
    if ($status_filter !== '' && !in_array($status_filter, $statuses, true)) $status_filter = '';
    $per_raw = (int) filter_input(INPUT_GET, 'queue_per_page', FILTER_VALIDATE_INT);
    $per_page = in_array($per_raw, [25,50,100], true) ? $per_raw : 25;
    $paged = max(1, (int) filter_input(INPUT_GET, 'queue_paged', FILTER_VALIDATE_INT));

    $where = ['1=1'];
    $args = [];
    if ($source_locale !== '') { $where[] = 'source_locale = %s'; $args[] = $source_locale; }
    if ($target_locale !== '') { $where[] = 'target_locale = %s'; $args[] = $target_locale; }
    if ($status_filter !== '') { $where[] = 'status = %s'; $args[] = $status_filter; }
    $where_sql = implode(' AND ', $where);
    $table = esc_sql(self::table());
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WHERE fragments are fixed allowlisted predicates; values remain placeholders.
    $count_sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where_sql;
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic SQL contains only the plugin-owned identifier and fixed predicates.
    $count_prepared = $args ? $wpdb->prepare($count_sql, ...$args) : $count_sql;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Bounded admin aggregate on YMulti-owned queue table.
    $total = (int) $wpdb->get_var($count_prepared);
    $pages = max(1, (int) ceil($total / $per_page));
    if ($paged > $pages) $paged = $pages;
    $offset = ($paged - 1) * $per_page;
    $list_args = array_merge($args, [$per_page, $offset]);
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WHERE fragments are fixed and pagination values use placeholders.
    $list_sql = 'SELECT * FROM ' . $table . ' WHERE ' . $where_sql . ' ORDER BY id DESC LIMIT %d OFFSET %d';
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic SQL contains only fixed internal fragments and placeholders.
    $prepared = $wpdb->prepare($list_sql, ...$list_args);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Prepared bounded admin queue listing on YMulti-owned table.
    $rows = $wpdb->get_results($prepared, ARRAY_A);
    $rows = is_array($rows) ? $rows : [];

    echo '<section class="ypm-card ypm-card--standalone ymulti-autopilot-queue"><div class="ypm-card__head ymulti-autopilot-queue-head"><div><span class="ypm-eyebrow">' . esc_html__('4 · Review', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Latest Autopilot work', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('Review-ready items move through human approval, native editor actions or professional review. Scheduled work continues in the background while you work elsewhere in WordPress.', 'yablonsky-multilingual') . '</p></div>';
    if ($rows) {
      echo '<div class="ymulti-autopilot-queue-tools">';
      self::action_form('ymulti_autopilot_clear', 'ymulti_autopilot_clear', __('Clear completed history', 'yablonsky-multilingual'), 'button');
      echo '</div>';
    }
    echo '</div>';
    echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="ypm-workspace-filter-form ypm-workspace-filter-form--compact"><input type="hidden" name="page" value="ypm-autopilot">';
    echo '<label class="ypm-workspace-filter"><span>' . esc_html__('Source language', 'yablonsky-multilingual') . '</span><select name="queue_source"><option value="">' . esc_html__('Any source language', 'yablonsky-multilingual') . '</option>';
    foreach ($active as $code) echo '<option value="' . esc_attr($code) . '"' . selected($source_locale, $code, false) . '>' . esc_html(Languages::display_name($code) . ' · ' . Languages::display_code($code)) . '</option>';
    echo '</select></label><label class="ypm-workspace-filter"><span>' . esc_html__('Target language', 'yablonsky-multilingual') . '</span><select name="queue_target"><option value="">' . esc_html__('Any target language', 'yablonsky-multilingual') . '</option>';
    foreach ($active as $code) echo '<option value="' . esc_attr($code) . '"' . selected($target_locale, $code, false) . '>' . esc_html(Languages::display_name($code) . ' · ' . Languages::display_code($code)) . '</option>';
    echo '</select></label><label class="ypm-workspace-filter"><span>' . esc_html__('Status', 'yablonsky-multilingual') . '</span><select name="queue_status"><option value="">' . esc_html__('All statuses', 'yablonsky-multilingual') . '</option>';
    foreach ($statuses as $candidate_status) echo '<option value="' . esc_attr($candidate_status) . '"' . selected($status_filter, $candidate_status, false) . '>' . esc_html(self::status_label($candidate_status)) . '</option>';
    echo '</select></label><label class="ypm-workspace-filter"><span>' . esc_html__('Rows per page', 'yablonsky-multilingual') . '</span><select name="queue_per_page">';
    foreach ([25,50,100] as $size) echo '<option value="' . esc_attr((string)$size) . '"' . selected($per_page, $size, false) . '>' . esc_html((string)$size) . '</option>';
    echo '</select></label><div class="ypm-workspace-filter-actions"><button class="button button-primary">' . esc_html__('Apply filters', 'yablonsky-multilingual') . '</button><a class="button" href="' . esc_url(admin_url('admin.php?page=ypm-autopilot')) . '">' . esc_html__('Reset', 'yablonsky-multilingual') . '</a></div></form>';

    if (!$rows) {
      echo '<div class="ypm-empty-state ymulti-autopilot-empty"><strong>' . esc_html__('Autopilot activity starts here', 'yablonsky-multilingual') . '</strong><span>' . esc_html__('Launch a plan to prepare current work. Future source changes continue adding review items while Autopilot is active.', 'yablonsky-multilingual') . '</span></div></section>';
      return;
    }
    echo '<div class="ypm-table-wrap"><table class="widefat"><thead><tr><th>' . esc_html__('Work', 'yablonsky-multilingual') . '</th><th>' . esc_html__('Source', 'yablonsky-multilingual') . '</th><th>' . esc_html__('Target', 'yablonsky-multilingual') . '</th><th>' . esc_html__('Status', 'yablonsky-multilingual') . '</th><th>' . esc_html__('Result', 'yablonsky-multilingual') . '</th></tr></thead><tbody>';
    foreach ($rows as $row) {
      $kind = sanitize_key((string) ($row['kind'] ?? ''));
      $row_source = sanitize_text_field((string) ($row['source_locale'] ?? ''));
      $target = sanitize_text_field((string) ($row['target_locale'] ?? ''));
      $status = sanitize_key((string) ($row['status'] ?? ''));
      $title = self::kind_label($kind);
      $source = self::source_label($row);
      $link = self::result_link($row);
      $state = $status === 'review_ready' ? 'attention' : ($status === 'failed' ? 'fail' : ($status === 'completed' ? 'pass' : 'neutral'));
      echo '<tr><td><strong>' . esc_html($title) . '</strong><small class="ymulti-autopilot-message">' . esc_html((string) ($row['message'] ?? '')) . '</small></td><td><div class="ymulti-source-compact">' . wp_kses_post(Admin::language_badge($row_source)) . '<span>' . esc_html($source) . '</span></div></td><td><span class="ypm-family-chip">' . esc_html(Languages::display_code($target)) . '</span></td><td>' . wp_kses_post(Admin::status_badge($state, self::status_label($status))) . '</td><td>';
      if ($link['url'] !== '') echo '<a class="button button-small" href="' . esc_url($link['url']) . '">' . esc_html($link['label']) . '</a>'; else echo '<span>—</span>';
      echo '</td></tr>';
    }
    echo '</tbody></table></div>';
    $start = $total > 0 ? $offset + 1 : 0;
    $end = min($total, $offset + count($rows));
    echo '<div class="ypm-pagination"><span>' . esc_html(sprintf(
      /* translators: 1: first visible queue item, 2: last visible queue item, 3: total matching queue items. */
      __('Showing %1$d–%2$d of %3$d', 'yablonsky-multilingual'), $start, $end, $total
    )) . '</span>';
    if ($pages > 1) {
      $base = add_query_arg(['page'=>'ypm-autopilot','queue_source'=>$source_locale,'queue_target'=>$target_locale,'queue_status'=>$status_filter,'queue_per_page'=>$per_page,'queue_paged'=>'%#%'], admin_url('admin.php'));
      $links = paginate_links(['base'=>$base,'format'=>'','current'=>$paged,'total'=>$pages,'prev_text'=>'‹','next_text'=>'›','type'=>'array']);
      if (is_array($links) && $links) echo '<span class="ypm-pagination__links">' . wp_kses_post(implode('', $links)) . '</span>';
    }
    echo '</div></section>';
  }


  /** @param array<string,mixed> $row @return array{url:string,label:string} */
  private static function result_link(array $row): array {
    $kind=sanitize_key((string)($row['kind']??'')); $source_id=absint($row['source_id']??0); $result_id=absint($row['result_id']??0); $target=sanitize_text_field((string)($row['target_locale']??''));
    if ($kind==='content_missing' && $result_id>0) { $url=get_edit_post_link($result_id,''); return ['url'=>is_string($url)?$url:'','label'=>__('Review draft','yablonsky-multilingual')]; }
    if ($kind==='content_stale') { return ['url'=>$result_id>0?admin_url('admin.php?page=ypm-translation-job&job=' . $result_id):admin_url('admin.php?page=ypm-translation-jobs'),'label'=>__('Open review job','yablonsky-multilingual')]; }
    if ($kind==='term_missing') { $taxonomy=sanitize_key((string)($row['taxonomy']??'')); return ['url'=>class_exists('Yablonsky\\Multilingual\\AdminTerms')?AdminTerms::create_translation_url($source_id,$taxonomy,$target):'','label'=>__('Review term','yablonsky-multilingual')]; }
    if ($kind==='media_metadata') { $source_locale=sanitize_text_field((string)($row['source_locale']??'')); return ['url'=>add_query_arg(['page'=>'ypm-media','source_locale'=>$source_locale,'locale'=>$target,'edit_media'=>$source_id],admin_url('admin.php')),'label'=>__('Review media','yablonsky-multilingual')]; }
    return ['url'=>'','label'=>''];
  }

  /** @param array<string,mixed> $row */
  private static function source_label(array $row): string {
    $kind=sanitize_key((string)($row['kind']??'')); $id=absint($row['source_id']??0);
    if ($kind==='term_missing') { $term=get_term($id,sanitize_key((string)($row['taxonomy']??''))); return $term instanceof \WP_Term ? $term->name . ' #' . $id : '#' . $id; }
    $post=get_post($id); return $post instanceof \WP_Post ? ((get_the_title($id)?:$post->post_name?:('#'.$id)) . ' #' . $id) : '#' . $id;
  }

  private static function kind_label(string $kind): string {
    $map=['content_missing'=>__('Content gap','yablonsky-multilingual'),'content_stale'=>__('Source changed','yablonsky-multilingual'),'term_missing'=>__('Term translation gap','yablonsky-multilingual'),'media_metadata'=>__('Media metadata','yablonsky-multilingual')];
    return (string)($map[$kind]??$kind);
  }

  private static function status_label(string $status): string {
    $map=['queued'=>__('Queued','yablonsky-multilingual'),'processing'=>__('Processing','yablonsky-multilingual'),'review_ready'=>__('Review ready','yablonsky-multilingual'),'completed'=>__('Completed','yablonsky-multilingual'),'skipped'=>__('Skipped','yablonsky-multilingual'),'failed'=>__('Failed','yablonsky-multilingual')];
    return (string)($map[$status]??$status);
  }

  public static function handle_save(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ymulti_autopilot_save', 'ymulti_autopilot_nonce');
    $current = self::settings();
    $intent_raw = filter_input(INPUT_POST, 'ymulti_autopilot_intent', FILTER_UNSAFE_RAW);
    $intent = is_string($intent_raw) ? sanitize_key($intent_raw) : 'save';
    if (!in_array($intent, ['save', 'launch'], true)) $intent = 'save';

    $active = Languages::active_codes();
    $source_raw = filter_input(INPUT_POST, 'source_locale', FILTER_UNSAFE_RAW);
    $source = is_string($source_raw) ? sanitize_text_field($source_raw) : Languages::default_code();
    if (!in_array($source, $active, true)) $source = Languages::default_code();
    $targets = self::posted_array('target_locales', 'sanitize_text_field');
    $targets = array_values(array_filter(array_unique($targets), static function(string $code) use ($active, $source): bool {
      return $code !== $source && in_array($code, $active, true);
    }));
    $types = array_values(array_intersect(self::posted_array('post_types', 'sanitize_key'), Languages::supported_post_types()));
    $taxonomies = self::posted_array('taxonomies', 'sanitize_key');
    $allowed = class_exists('Yablonsky\\Multilingual\\AdminTerms') ? AdminTerms::manageable_taxonomies() : [];
    $taxonomies = array_values(array_intersect($taxonomies, $allowed));
    $cap_raw = filter_input(INPUT_POST, 'daily_cap', FILTER_VALIDATE_INT);
    $cap = in_array((int) $cap_raw, [0,25,50,100,250,500], true) ? (int) $cap_raw : 100;

    $behaviors_selected = (bool) filter_input(INPUT_POST, 'content_missing', FILTER_VALIDATE_INT)
      || (bool) filter_input(INPUT_POST, 'content_stale', FILTER_VALIDATE_INT)
      || (bool) filter_input(INPUT_POST, 'terms', FILTER_VALIDATE_INT)
      || (bool) filter_input(INPUT_POST, 'media', FILTER_VALIDATE_INT);
    if ($intent === 'launch' && (!$targets || !$types || !$behaviors_selected)) {
      set_transient('ymulti_autopilot_notice_' . get_current_user_id(), __('Complete the launch plan by choosing at least one target language, content type and behavior.', 'yablonsky-multilingual'), 60);
      self::redirect('plan_ready');
    }

    $settings = [
      'enabled' => $intent === 'launch' ? true : !empty($current['enabled']),
      'paused' => $intent === 'launch' ? false : !empty($current['paused']),
      'owner_user_id' => get_current_user_id(),
      'source_locale' => $source,
      'target_locales' => $targets,
      'post_types' => $types,
      'taxonomies' => $taxonomies,
      'content_missing' => (bool) filter_input(INPUT_POST, 'content_missing', FILTER_VALIDATE_INT),
      'content_stale' => (bool) filter_input(INPUT_POST, 'content_stale', FILTER_VALIDATE_INT),
      'terms' => (bool) filter_input(INPUT_POST, 'terms', FILTER_VALIDATE_INT),
      'media' => (bool) filter_input(INPUT_POST, 'media', FILTER_VALIDATE_INT),
      'daily_cap' => $cap,
    ];
    update_option(self::OPT_SETTINGS, $settings, false);

    if ($intent === 'launch') {
      $counts = self::build_backlog();
      if (self::pending_count() > 0) self::schedule_next();
      set_transient('ymulti_autopilot_notice_' . get_current_user_id(), sprintf(
        /* translators: 1: scanned content items, 2: scanned term items, 3: scanned media items. */
        __('Autopilot launched. Current scan covered %1$d content, %2$d term and %3$d media items; background processing continues through the saved plan.', 'yablonsky-multilingual'),
        $counts['content'], $counts['terms'], $counts['media']
      ), 60);
      self::redirect('launched');
    }

    if (empty($settings['enabled']) || !empty($settings['paused'])) self::unschedule();
    elseif (self::pending_count() > 0) self::schedule_next();
    $saved_state = !empty($settings['enabled']) ? (!empty($settings['paused']) ? __('Standby', 'yablonsky-multilingual') : __('Active', 'yablonsky-multilingual')) : __('Ready', 'yablonsky-multilingual');
    set_transient('ymulti_autopilot_notice_' . get_current_user_id(), sprintf(
      /* translators: %s: current Autopilot runtime state. */
      __('Autopilot plan saved. Runtime state: %s.', 'yablonsky-multilingual'),
      $saved_state
    ), 60);
    self::redirect('saved');
  }

  public static function handle_backlog(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden','yablonsky-multilingual'));
    check_admin_referer('ymulti_autopilot_backlog','ymulti_autopilot_nonce');
    $counts=self::build_backlog();
    if (self::enabled() && self::pending_count()>0) self::schedule_next();
    set_transient('ymulti_autopilot_notice_' . get_current_user_id(), sprintf(
      /* translators: 1: queued content items, 2: queued term items, 3: queued media items. */
      __('Backlog refreshed: current scan covered %1$d content, %2$d term and %3$d media items.', 'yablonsky-multilingual'), $counts['content'],$counts['terms'],$counts['media']
    ), 60);
    self::redirect('backlog');
  }

  /** @return array{content:int,terms:int,media:int} */
  public static function build_backlog(): array {
    $s = self::settings();
    $counts = ['content' => 0, 'terms' => 0, 'media' => 0];
    if (!$s['target_locales']) return $counts;

    $source = (string) $s['source_locale'];
    $content_args = [
      'post_type' => $s['post_types'],
      'post_status' => ['publish', 'private'],
      'posts_per_page' => self::MAX_BACKLOG_POSTS,
      'orderby' => 'ID',
      'order' => 'ASC',
      'fields' => 'ids',
      'no_found_rows' => true,
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false,
    ];
    $content_args = AdminContent::with_source_language($content_args, $source);
    $q = new \WP_Query($content_args);
    $ids = array_values(array_filter(array_map('absint', (array) $q->posts)));
    wp_reset_postdata();
    if ($ids) Translations::prime_translation_families($ids);
    foreach ($ids as $id) {
      $id = absint($id);
      if ($id <= 0 || RuntimeAuthority::effective_post_language($id) !== $source) continue;
      self::enqueue_post_targets($id, $s);
      $counts['content']++;
    }

    if (!empty($s['terms'])) foreach ((array) $s['taxonomies'] as $taxonomy) {
      $taxonomy = sanitize_key((string) $taxonomy);
      if ($taxonomy === '') continue;
      $term_ids = class_exists('Yablonsky\Multilingual\AdminTerms')
        ? AdminTerms::workspace_term_ids($taxonomy, $source, '', self::MAX_BACKLOG_TERMS)
        : [];
      foreach ($term_ids as $term_id) {
        $term_id = absint($term_id);
        if ($term_id <= 0 || RuntimeAuthority::effective_term_language($term_id, $taxonomy) !== $source) continue;
        $term = get_term($term_id, $taxonomy);
        if (!$term instanceof \WP_Term) continue;
        $hash = hash('sha256', (string) wp_json_encode([(string) $term->name, (string) $term->slug, (string) $term->description], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        foreach ((array) $s['target_locales'] as $target) {
          if (EffectiveRelations::existing_term_target($term_id, $taxonomy, (string) $target) > 0) continue;
          self::enqueue('term_missing', $term_id, 'term', $taxonomy, $source, (string) $target, $hash);
        }
        $counts['terms']++;
      }
    }

    if (!empty($s['media'])) {
      $media_args = ['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'posts_per_page' => self::MAX_BACKLOG_MEDIA, 'orderby' => 'ID', 'order' => 'DESC', 'fields' => 'ids', 'no_found_rows' => true];
      $media_args = AdminContent::with_source_language($media_args, $source);
      $mq = new \WP_Query($media_args);
      foreach ((array) $mq->posts as $attachment_id) {
        $attachment_id = absint($attachment_id);
        if ($attachment_id <= 0) continue;
        self::on_attachment_change($attachment_id);
        $counts['media']++;
      }
      wp_reset_postdata();
    }
    return $counts;
  }

  public static function handle_run(): void { if(!current_user_can('manage_options'))wp_die(esc_html__('Forbidden','yablonsky-multilingual'));check_admin_referer('ymulti_autopilot_run','ymulti_autopilot_nonce');self::process_one();self::redirect('run'); }
  public static function handle_pause(): void { if(!current_user_can('manage_options'))wp_die(esc_html__('Forbidden','yablonsky-multilingual'));check_admin_referer('ymulti_autopilot_pause','ymulti_autopilot_nonce');$s=self::settings();$s['paused']=true;update_option(self::OPT_SETTINGS,$s,false);self::unschedule();self::redirect('paused'); }
  public static function handle_resume(): void { if(!current_user_can('manage_options'))wp_die(esc_html__('Forbidden','yablonsky-multilingual'));check_admin_referer('ymulti_autopilot_resume','ymulti_autopilot_nonce');$s=self::settings();$s['paused']=false;$s['enabled']=true;$s['owner_user_id']=get_current_user_id();update_option(self::OPT_SETTINGS,$s,false);self::schedule_next();self::redirect('resumed'); }
  public static function handle_clear(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ymulti_autopilot_clear', 'ymulti_autopilot_nonce');
    global $wpdb;
    $table = self::table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit administrator cleanup of finished YMulti queue history on the plugin-owned table.
    $wpdb->delete($table, ['status' => 'completed'], ['%s']);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit administrator cleanup of finished YMulti queue history on the plugin-owned table.
    $wpdb->delete($table, ['status' => 'skipped'], ['%s']);
    self::flush_queue_view_cache();
    self::redirect('cleared');
  }

  /** @return string[] */
  private static function posted_array(string $key, string $sanitizer): array {
    $raw=filter_input(INPUT_POST,$key,FILTER_DEFAULT,FILTER_REQUIRE_ARRAY); if(!is_array($raw))return[]; $out=[]; foreach(wp_unslash($raw) as $value){$value=is_scalar($value)?(string)$value:'';$clean=call_user_func($sanitizer,$value);if(is_string($clean)&&$clean!=='')$out[]=$clean;} return array_values(array_unique($out));
  }

  private static function redirect(string $notice): void { wp_safe_redirect(add_query_arg(['page'=>'ypm-autopilot','ymulti_autopilot_notice'=>$notice],admin_url('admin.php')));exit; }
  private static function notice(): void { $msg=get_transient('ymulti_autopilot_notice_' . get_current_user_id()); if(is_string($msg)&&$msg!==''){delete_transient('ymulti_autopilot_notice_' . get_current_user_id());echo '<div class="notice notice-success inline"><p>' . esc_html($msg) . '</p></div>'; } }
}
