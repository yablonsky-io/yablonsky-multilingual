<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Durable professional translation operations.
 *
 * This workflow is intentionally admin-only. It adds no frontend queries or
 * hooks. Jobs are coordination records around native WordPress translation
 * objects, never a second content store.
 */
final class TranslationJobs {
  public const TABLE_SUFFIX = 'ymulti_translation_jobs';
  public const EVENTS_TABLE_SUFFIX = 'ymulti_translation_job_events';
  public const TARGET_LOOKUP_INDEX = 'ymulti_target_post';
  public const TARGET_INDEX_STATUS_OPTION = 'ymulti_target_index_status_v8';
  private const TARGET_INDEX_LOCK_OPTION = 'ymulti_target_index_migration_lock_v8';
  private const TARGET_INDEX_ATTEMPT_OPTION = 'ymulti_target_index_attempt_v8';
  private const MAX_AUTOMATIC_INDEX_ROWS = 25000;
  public const CAP_TRANSLATE = 'ymulti_translate';
  public const CAP_REVIEW = 'ymulti_review_translations';
  public const CAP_MANAGE = 'ymulti_manage_translation_jobs';

  public const STATUS_OPEN = 'open';
  public const STATUS_ASSIGNED = 'assigned';
  public const STATUS_IN_PROGRESS = 'in_progress';
  public const STATUS_REVIEW = 'review';
  public const STATUS_COMPLETED = 'completed';
  public const STATUS_CANCELLED = 'cancelled';

  private const PAGE_SIZES = [25, 50, 100];
  private const DEFAULT_PAGE_SIZE = 25;

  public static function init(): void {
    // Job-scoped edit authority must also exist on REST requests used by the
    // block editor. The filter is a no-op for visitors and for users whose
    // normal WordPress capabilities already satisfy the requested meta cap.
    add_filter('map_meta_cap', [__CLASS__, 'map_job_caps'], 20, 4);
    if (!is_admin()) return;
    add_action('admin_menu', [__CLASS__, 'register_page'], 30);
    add_action('admin_post_ymulti_translation_job_create', [__CLASS__, 'handle_create']);
    add_action('admin_post_ymulti_translation_job_start', [__CLASS__, 'handle_start']);
    add_action('admin_post_ymulti_translation_job_review', [__CLASS__, 'handle_review']);
    add_action('admin_post_ymulti_translation_job_complete', [__CLASS__, 'handle_complete']);
    add_action('admin_post_ymulti_translation_job_cancel', [__CLASS__, 'handle_cancel']);
    add_action('admin_post_ymulti_translation_job_reassign', [__CLASS__, 'handle_reassign']);
    add_action('admin_post_ymulti_translation_jobs_create_page', [__CLASS__, 'handle_create_page']);
    add_action('admin_post_ymulti_translation_jobs_bulk', [__CLASS__, 'handle_bulk']);
  }

  public static function table(): string {
    global $wpdb;
    return $wpdb->prefix . self::TABLE_SUFFIX;
  }

  public static function events_table(): string {
    global $wpdb;
    return $wpdb->prefix . self::EVENTS_TABLE_SUFFIX;
  }

  public static function install_table(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = self::table();
    $charset = $wpdb->get_charset_collate();
    // Existing tables are upgraded separately by schema v8 using online DDL.
    // Do not let dbDelta() silently rebuild an existing production table.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Metadata only; no scan of multilingual content.
    $existing = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    $new_table_index = $existing ? '' : ",\n      KEY ymulti_target_post (target_post_id)";
    $sql = "CREATE TABLE {$table} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      source_post_id BIGINT UNSIGNED NOT NULL,
      target_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      source_locale VARCHAR(32) NOT NULL,
      target_locale VARCHAR(32) NOT NULL,
      assignee_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
      status VARCHAR(24) NOT NULL DEFAULT 'open',
      priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
      source_hash CHAR(64) NOT NULL DEFAULT '',
      exported_hash CHAR(64) NOT NULL DEFAULT '',
      exported_at DATETIME NULL DEFAULT NULL,
      due_at DATETIME NULL DEFAULT NULL,
      notes TEXT NOT NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY ymulti_source_target (source_post_id, target_locale(32)),
      KEY ymulti_assignee_status (assignee_user_id, status(24)),
      KEY ymulti_status_target (status(24), target_locale(32)),
      KEY ymulti_updated (updated_at){$new_table_index}
    ) {$charset};";
    dbDelta($sql);
    $events = self::events_table();
    $events_sql = "CREATE TABLE {$events} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      job_id BIGINT UNSIGNED NOT NULL,
      actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      event_type VARCHAR(48) NOT NULL,
      message TEXT NOT NULL,
      data_json LONGTEXT NOT NULL,
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY ymulti_job_event (job_id, id),
      KEY ymulti_event_type (event_type(48))
    ) {$charset};";
    dbDelta($events_sql);
  }

  /**
   * Does any index begin with target_post_id? The primary key is implicitly
   * appended to secondary indexes by InnoDB, so a single-column index is
   * sufficient for WHERE target_post_id = ? ORDER BY id DESC LIMIT 1.
   * Read-only metadata request; never scans translation content.
   */
  public static function target_lookup_index_exists(): bool {
    return self::target_lookup_index_state() === true;
  }

  /** Null indicates an unavailable metadata result and must fail closed. */
  private static function target_lookup_index_state(): ?bool {
    global $wpdb;
    $table = self::table();
    // The table name is compared as a DATA value, not interpolated into SQL.
    // information_schema returns only index metadata for the selected table
    // in the currently connected database; it does not scan translation rows.
    // This works on WordPress 6.0+ (no identifier placeholder required).
    if (!self::is_safe_sql_identifier($table)) return null;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only index metadata; no content rows, short-lived admin diagnostic.
    $rows = $wpdb->get_results(
      $wpdb->prepare(
        'SELECT SEQ_IN_INDEX AS Seq_in_index, COLUMN_NAME AS Column_name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
        $table
      ),
      ARRAY_A
    );
    if (!is_array($rows)) return null;
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      if ((int) ($row['Seq_in_index'] ?? 0) === 1 && ($row['Column_name'] ?? '') === 'target_post_id') return true;
    }
    return false;
  }

  /**
   * Schema-v8 additive online index upgrade. Always verify before committing
   * the schema option. No content edits, full counts or destructive DDL.
   * Return false to leave v7 recorded and retry on a later admin request.
   */
  public static function ensure_target_lookup_index(): bool {
    global $wpdb;
    $index_state = self::target_lookup_index_state();
    if ($index_state === true) {
      delete_option(self::TARGET_INDEX_STATUS_OPTION);
      return true;
    }
    if ($index_state === null) {
      self::store_index_status('index_metadata_unavailable');
      return false;
    }

    $table = self::table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table-status metadata; TABLE_ROWS is an estimate, no COUNT(*) scan.
    $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $table), ARRAY_A);
    if (!is_array($status) || ($status['Name'] ?? '') !== $table) {
      self::store_index_status('table_unavailable');
      return false;
    }
    if (strtoupper((string) ($status['Engine'] ?? '')) !== 'INNODB') {
      self::store_index_status('online_engine_unavailable');
      return false;
    }
    // A large table is deliberately not indexed during a normal admin request.
    // It needs a separately scheduled, measured maintenance window.
    if (!isset($status['Rows']) || !is_numeric($status['Rows']) || (float) $status['Rows'] > self::MAX_AUTOMATIC_INDEX_ROWS) {
      self::store_index_status('maintenance_window_required');
      return false;
    }

    // Avoid retrying an unsupported/failed DDL on every wp-admin page load.
    $last_attempt = (int) get_option(self::TARGET_INDEX_ATTEMPT_OPTION, 0);
    if ($last_attempt > time() - HOUR_IN_SECONDS) return false;

    // add_option() uses the unique option_name index for an atomic claim.
    // A crashed request leaves only a temporary lock, not half-migrated data.
    $lock_time = (int) get_option(self::TARGET_INDEX_LOCK_OPTION, 0);
    if ($lock_time > 0 && $lock_time < time() - HOUR_IN_SECONDS) {
      delete_option(self::TARGET_INDEX_LOCK_OPTION);
    }
    if (!add_option(self::TARGET_INDEX_LOCK_OPTION, time(), '', false)) return false;

    try {
      $locked_state = self::target_lookup_index_state();
      if ($locked_state === true) {
        delete_option(self::TARGET_INDEX_STATUS_OPTION);
        return true;
      }
      if ($locked_state === null) {
        self::store_index_status('index_metadata_unavailable');
        return false;
      }
      // Explicit online/no-write-lock plan. Unsupported storage engines or
      // servers fail closed, without a potentially blocking ALTER fallback.
      // The ALTER is intrinsically a schema operation; no content is rewritten
      // by YMulti. WordPress does not provide an equivalent high-level API.
      if (!self::is_safe_sql_identifier($table) || !self::is_safe_sql_identifier(self::TARGET_LOOKUP_INDEX)) {
        self::store_index_status('invalid_table_identifier');
        return false;
      }
      update_option(self::TARGET_INDEX_ATTEMPT_OPTION, time(), false);
      // SQL identifiers cannot be bound with %s on supported WP 6.0/6.1.
      // Both names were already validated against a strict [A-Za-z0-9_]
      // allow-list (and the MySQL 64-byte identifier length limit).
      // The table is derived solely from $wpdb->prefix + our fixed suffix;
      // the index is a compile-time YMulti constant, never user input.
      $ddl = sprintf(
        'ALTER TABLE `%s` ADD INDEX `%s` (`target_post_id`), ALGORITHM=INPLACE, LOCK=NONE',
        $table,
        self::TARGET_LOOKUP_INDEX
      );
      // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Validated identifiers, one-time online v8 DDL, no parameterized schema identifier support in WP 6.0; guarded by lock and verified after execution.
      $result = $wpdb->query($ddl);
      if ($result === false || !self::target_lookup_index_exists()) {
        self::store_index_status('online_index_not_confirmed');
        return false;
      }
      delete_option(self::TARGET_INDEX_STATUS_OPTION);
      delete_option(self::TARGET_INDEX_ATTEMPT_OPTION);
      return true;
    } finally {
      delete_option(self::TARGET_INDEX_LOCK_OPTION);
    }
  }


  /** Table/index identifiers are never drawn from request parameters. */
  private static function is_safe_sql_identifier(string $identifier): bool {
    return $identifier !== '' && strlen($identifier) <= 64 && preg_match('/\A[A-Za-z0-9_]+\z/D', $identifier) === 1;
  }

  private static function store_index_status(string $code): void {
    update_option(self::TARGET_INDEX_STATUS_OPTION, $code, false);
  }

  public static function install_roles(): void {
    $admin = get_role('administrator');
    if ($admin) {
      $admin->add_cap(self::CAP_TRANSLATE);
      $admin->add_cap(self::CAP_REVIEW);
      $admin->add_cap(self::CAP_MANAGE);
    }

    $translator = get_role('ymulti_translator');
    if (!$translator) {
      $translator = add_role('ymulti_translator', __('YMulti Translator', 'yablonsky-multilingual'), [
        'read' => true,
        'edit_posts' => true,
        self::CAP_TRANSLATE => true,
      ]);
    } elseif ($translator) {
      $translator->add_cap(self::CAP_TRANSLATE);
    }

    $reviewer = get_role('ymulti_reviewer');
    if (!$reviewer) {
      $reviewer = add_role('ymulti_reviewer', __('YMulti Reviewer', 'yablonsky-multilingual'), [
        'read' => true,
        'edit_posts' => true,
        'edit_others_posts' => true,
        self::CAP_TRANSLATE => true,
        self::CAP_REVIEW => true,
      ]);
    } elseif ($reviewer) {
      $reviewer->add_cap(self::CAP_TRANSLATE);
      $reviewer->add_cap(self::CAP_REVIEW);
    }
  }

  /** @return array<string,string> */
  public static function statuses(): array {
    return [
      self::STATUS_OPEN => __('Open', 'yablonsky-multilingual'),
      self::STATUS_ASSIGNED => __('Assigned', 'yablonsky-multilingual'),
      self::STATUS_IN_PROGRESS => __('In progress', 'yablonsky-multilingual'),
      self::STATUS_REVIEW => __('Review', 'yablonsky-multilingual'),
      self::STATUS_COMPLETED => __('Completed', 'yablonsky-multilingual'),
      self::STATUS_CANCELLED => __('Cancelled', 'yablonsky-multilingual'),
    ];
  }

  public static function register_page(): void {
    if (!current_user_can(self::CAP_TRANSLATE) && !current_user_can(self::CAP_REVIEW) && !current_user_can(self::CAP_MANAGE)) return;
    add_submenu_page(
      'ypm-multilingual',
      __('Translation Jobs', 'yablonsky-multilingual'),
      __('Translation Jobs', 'yablonsky-multilingual'),
      self::CAP_TRANSLATE,
      'ypm-translation-jobs',
      [__CLASS__, 'render_page']
    );
    add_submenu_page(null, __('Translation Job', 'yablonsky-multilingual'), __('Translation Job', 'yablonsky-multilingual'), self::CAP_TRANSLATE, 'ypm-translation-job', [__CLASS__, 'render_detail_page']);
  }

  /** @return array<string,mixed>|null */
  public static function get_job(int $job_id): ?array {
    if ($job_id <= 0) return null;
    global $wpdb;
    $table = self::table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One indexed admin lookup on YMulti-owned workflow table.
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . esc_sql($table) . ' WHERE id = %d LIMIT 1', $job_id), ARRAY_A);
    return is_array($row) ? $row : null;
  }

  public static function can_view_job(array $job, int $user_id = 0): bool {
    $user_id = $user_id > 0 ? $user_id : get_current_user_id();
    if ($user_id <= 0) return false;
    if (user_can($user_id, self::CAP_MANAGE) || user_can($user_id, self::CAP_REVIEW)) return true;
    return user_can($user_id, self::CAP_TRANSLATE) && (int) ($job['assignee_user_id'] ?? 0) === $user_id;
  }

  public static function can_work_job(array $job, int $user_id = 0): bool {
    $user_id = $user_id > 0 ? $user_id : get_current_user_id();
    if ($user_id <= 0) return false;
    if (user_can($user_id, self::CAP_MANAGE)) return true;
    return user_can($user_id, self::CAP_TRANSLATE) && (int) ($job['assignee_user_id'] ?? 0) === $user_id;
  }

  public static function can_review_job(array $job, int $user_id = 0): bool {
    $user_id = $user_id > 0 ? $user_id : get_current_user_id();
    return $user_id > 0 && (user_can($user_id, self::CAP_MANAGE) || user_can($user_id, self::CAP_REVIEW));
  }

  /**
   * Grant the minimum edit/read authority for an assigned translation target.
   *
   * This avoids giving translators broad capabilities such as edit_products
   * merely to edit one assigned product/custom-post translation. Normal core
   * capabilities win first; the indexed job lookup is only used when a YMulti
   * workflow user is missing a primitive capability required by the CPT.
   *
   * @param string[] $caps Primitive capabilities calculated by WordPress.
   * @param mixed[]  $args Meta-cap arguments; the first item is the post ID.
   * @return string[]
   */
  public static function map_job_caps(array $caps, string $cap, int $user_id, array $args): array {
    if (!in_array($cap, ['edit_post', 'read_post'], true) || $user_id <= 0) return $caps;
    $post_id = absint($args[0] ?? 0);
    if ($post_id <= 0) return $caps;

    $user = get_userdata($user_id);
    if (!$user instanceof \WP_User) return $caps;
    $allcaps = is_array($user->allcaps ?? null) ? $user->allcaps : [];

    // If WordPress already grants every primitive capability there is no need
    // to touch the workflow table (important for administrators/list screens).
    $needs_override = false;
    foreach ($caps as $required) {
      if ($required === 'do_not_allow' || empty($allcaps[$required])) {
        $needs_override = true;
        break;
      }
    }
    if (!$needs_override) return $caps;

    $is_manager = !empty($allcaps[self::CAP_MANAGE]);
    $is_reviewer = !empty($allcaps[self::CAP_REVIEW]);
    $is_translator = !empty($allcaps[self::CAP_TRANSLATE]);
    if (!$is_manager && !$is_reviewer && !$is_translator) return $caps;
    if ((int) get_option(Updater::OPT_DB_VERSION, 0) < 4) return $caps;

    static $job_cache = [];
    // map_job_caps() uses a function-local static cache that cannot be cleared
    // by the switch_blog handler. Include the active blog to prevent a job on
    // site A granting capabilities for the same numeric post/user on site B.
    $cache_key = get_current_blog_id() . ':' . $user_id . ':' . $post_id;
    if (!array_key_exists($cache_key, $job_cache)) {
      global $wpdb;
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed authorization lookup, request-cached below, on YMulti-owned workflow table.
      $row = $wpdb->get_row($wpdb->prepare(
        'SELECT assignee_user_id, status FROM ' . esc_sql(self::table()) . ' WHERE target_post_id = %d ORDER BY id DESC LIMIT 1',
        $post_id
      ), ARRAY_A);
      $job_cache[$cache_key] = is_array($row) ? $row : null;
    }
    $job = $job_cache[$cache_key];
    if (!is_array($job)) return $caps;

    if ($is_manager || $is_reviewer) return ['read'];
    $status = sanitize_key((string) ($job['status'] ?? ''));
    $workable = in_array($status, [self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS, self::STATUS_REVIEW], true);
    if ($is_translator && $workable && (int) ($job['assignee_user_id'] ?? 0) === $user_id) return ['read'];
    return $caps;
  }

  /** @return int|\WP_Error */
  public static function create_job(int $source_post_id, string $target_locale, int $assignee_user_id = 0, int $priority = 5, string $due_at = '', string $notes = '') {
    $source = get_post($source_post_id);
    if (!$source instanceof \WP_Post) return new \WP_Error('ymulti_job_source', __('Source content is unavailable. Return to the content list and choose an existing source item.', 'yablonsky-multilingual'));
    if (!in_array($source->post_type, Languages::supported_post_types(), true)) return new \WP_Error('ymulti_job_type', __('Enable this content type in YMulti to create translation work.', 'yablonsky-multilingual'));
    if (!EntityContract::post_target_allowed($source_post_id, $target_locale)) return new \WP_Error('ymulti_job_locale', __('Source and target languages must be different.', 'yablonsky-multilingual'));
    $source_locale = EntityContract::post_source_locale($source_post_id);
    if ($assignee_user_id > 0 && !user_can($assignee_user_id, self::CAP_TRANSLATE)) return new \WP_Error('ymulti_job_assignee', __('Choose a user with the YMulti translator role.', 'yablonsky-multilingual'));

    $existing_target = EntityContract::existing_post_target($source_post_id, $target_locale);

    global $wpdb;
    $table = self::table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed duplicate guard on the YMulti-owned workflow table.
    $existing_job = (int) $wpdb->get_var($wpdb->prepare(
      'SELECT id FROM ' . esc_sql($table) . ' WHERE source_post_id = %d AND target_locale = %s AND status IN (%s,%s,%s,%s) ORDER BY id DESC LIMIT 1',
      $source_post_id,
      $target_locale,
      self::STATUS_OPEN,
      self::STATUS_ASSIGNED,
      self::STATUS_IN_PROGRESS,
      self::STATUS_REVIEW
    ));
    if ($existing_job > 0) return new \WP_Error('ymulti_job_exists', sprintf(
      /* translators: %d: existing translation job ID. */
      __('An active translation job already exists for this target language (#%d).', 'yablonsky-multilingual'),
      $existing_job
    ));

    $priority = max(1, min(9, $priority));
    $status = $assignee_user_id > 0 ? self::STATUS_ASSIGNED : self::STATUS_OPEN;
    if ($existing_target > 0) $status = self::STATUS_REVIEW;
    $now = current_time('mysql', true);
    $due = self::normalize_mysql_datetime($due_at);
    $data = [
      'source_post_id' => $source_post_id,
      'target_post_id' => $existing_target,
      'source_locale' => $source_locale,
      'target_locale' => $target_locale,
      'assignee_user_id' => $assignee_user_id,
      'created_by' => get_current_user_id(),
      'status' => $status,
      'priority' => $priority,
      'source_hash' => self::source_hash($source_post_id),
      'exported_hash' => '',
      'exported_at' => null,
      'due_at' => $due !== '' ? $due : null,
      'notes' => sanitize_textarea_field($notes),
      'created_at' => $now,
      'updated_at' => $now,
    ];
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit admin workflow write to YMulti-owned table.
    $ok = $wpdb->insert($table, $data, ['%d','%d','%s','%s','%d','%d','%s','%d','%s','%s','%s','%s','%s','%s','%s']);
    if ($ok === false) return new \WP_Error('ymulti_job_insert', __('Translation job creation needs attention. Review the source and assignee, then retry.', 'yablonsky-multilingual'));
    $job_id = (int) $wpdb->insert_id;
    self::log_event($job_id, 'created', __('Translation job created.', 'yablonsky-multilingual'), ['assignee_user_id' => $assignee_user_id, 'target_locale' => $target_locale]);
    return $job_id;
  }

  public static function source_hash(int $post_id): string {
    $post = get_post($post_id);
    if (!$post instanceof \WP_Post) return '';
    $payload = wp_json_encode([
      'id' => (int) $post->ID,
      'type' => (string) $post->post_type,
      'title' => (string) $post->post_title,
      'excerpt' => (string) $post->post_excerpt,
      'content' => (string) $post->post_content,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return hash('sha256', is_string($payload) ? $payload : '');
  }

  /** @return int|\WP_Error */
  public static function ensure_target_draft(array $job, int $actor_user_id = 0) {
    $job_id = absint($job['id'] ?? 0);
    $target_id = absint($job['target_post_id'] ?? 0);
    if ($target_id > 0 && get_post($target_id) instanceof \WP_Post) return $target_id;

    $source_id = absint($job['source_post_id'] ?? 0);
    $target_locale = sanitize_text_field((string) ($job['target_locale'] ?? ''));
    $source = get_post($source_id);
    if (!$source instanceof \WP_Post || !Languages::is_active($target_locale)) return new \WP_Error('ymulti_job_target', __('Target draft creation requires a valid source and translation-job state.', 'yablonsky-multilingual'));

    $effective_target = EntityContract::existing_post_target($source_id, $target_locale);
    if ($effective_target > 0) {
      self::update_job($job_id, ['target_post_id' => $effective_target]);
      return $effective_target;
    }

    if (!EntityContract::post_target_allowed($source_id, $target_locale)) return new \WP_Error('ymulti_job_target_locale', __('Source and target locales must be different.', 'yablonsky-multilingual'));
    $group = Translations::ensure_group_id($source_id);

    $assignee = absint($job['assignee_user_id'] ?? 0);
    $author = $assignee > 0 ? $assignee : ($actor_user_id > 0 ? $actor_user_id : get_current_user_id());
    if ($author <= 0 || !user_can($author, 'edit_posts')) $author = get_current_user_id();
    $new_id = wp_insert_post([
      'post_type' => $source->post_type,
      'post_status' => 'draft',
      'post_title' => $source->post_title,
      'post_content' => $source->post_content,
      'post_excerpt' => $source->post_excerpt,
      'post_author' => $author,
    ], true);
    if (is_wp_error($new_id)) return $new_id;

    Translations::set_group_id((int) $new_id, $group);
    Translations::set_post_language((int) $new_id, $target_locale);
    do_action('yablonsky_multilingual_translation_draft_initialized', $source_id, (int) $new_id, 'job');
    $thumb = get_post_thumbnail_id($source_id);
    if ($thumb) set_post_thumbnail((int) $new_id, $thumb);
    if ($source->post_type === 'product' && class_exists('Yablonsky\\Multilingual\\Compat\\Woo')) {
      Compat\Woo::initialize_translation_draft($source_id, (int) $new_id);
    }

    self::update_job($job_id, ['target_post_id' => (int) $new_id, 'status' => self::STATUS_IN_PROGRESS]);
    return (int) $new_id;
  }

  /** @param array<string,mixed> $fields */
  public static function update_job(int $job_id, array $fields): bool {
    if ($job_id <= 0 || !$fields) return false;
    $allowed = ['target_post_id','assignee_user_id','status','priority','source_hash','exported_hash','exported_at','due_at','notes'];
    $data = [];
    $formats = [];
    foreach ($fields as $key => $value) {
      if (!in_array($key, $allowed, true)) continue;
      if (in_array($key, ['target_post_id','assignee_user_id','priority'], true)) {
        $data[$key] = (int) $value;
        $formats[] = '%d';
      } else {
        $data[$key] = $value === null ? null : (string) $value;
        $formats[] = '%s';
      }
    }
    if (!$data) return false;
    $data['updated_at'] = current_time('mysql', true);
    $formats[] = '%s';
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit bounded workflow update on YMulti-owned table.
    return $wpdb->update(self::table(), $data, ['id' => $job_id], $formats, ['%d']) !== false;
  }

  public static function render_page(): void {
    if (!current_user_can(self::CAP_TRANSLATE) && !current_user_can(self::CAP_REVIEW) && !current_user_can(self::CAP_MANAGE)) return;
    self::install_table();
    $filters = self::read_filters();
    $per_page = Admin::requested_page_size(self::DEFAULT_PAGE_SIZE, self::PAGE_SIZES);
    $paged = max(1, (int) filter_input(INPUT_GET, 'paged', FILTER_VALIDATE_INT));
    $offset = ($paged - 1) * $per_page;
    $total = self::count_jobs($filters);
    $pages = max(1, (int) ceil($total / $per_page));
    if ($paged > $pages && $total > 0) {
      wp_safe_redirect(self::page_url($filters, $per_page, $pages));
      exit;
    }
    $rows = self::list_jobs($filters, $per_page, $offset);
    $stats = self::status_counts();

    echo '<div class="wrap ypm-admin ypm-admin--translation-jobs">';
    Admin::render_shell_header(
      __('Translation Jobs', 'yablonsky-multilingual'),
      __('Assign durable translation work, keep native WordPress drafts as the content authority, and hand jobs between translators and reviewers while frontend requests stay local and bounded.', 'yablonsky-multilingual'),
      'neutral',
      __('Professional workflow', 'yablonsky-multilingual')
    );

    self::render_notice();
    echo '<div class="ypm-metrics ypm-metrics--4">';
    Admin::render_metric(__('Open / assigned', 'yablonsky-multilingual'), (string) ((int) ($stats[self::STATUS_OPEN] ?? 0) + (int) ($stats[self::STATUS_ASSIGNED] ?? 0)), __('Waiting for translation work', 'yablonsky-multilingual'), 'neutral');
    Admin::render_metric(__('In progress', 'yablonsky-multilingual'), (string) (int) ($stats[self::STATUS_IN_PROGRESS] ?? 0), __('Active translator work', 'yablonsky-multilingual'), (int) ($stats[self::STATUS_IN_PROGRESS] ?? 0) > 0 ? 'running' : 'neutral');
    Admin::render_metric(__('Review', 'yablonsky-multilingual'), (string) (int) ($stats[self::STATUS_REVIEW] ?? 0), __('Ready for human review', 'yablonsky-multilingual'), (int) ($stats[self::STATUS_REVIEW] ?? 0) > 0 ? 'attention' : 'neutral');
    Admin::render_metric(__('Completed', 'yablonsky-multilingual'), (string) (int) ($stats[self::STATUS_COMPLETED] ?? 0), __('Workflow records closed', 'yablonsky-multilingual'), (int) ($stats[self::STATUS_COMPLETED] ?? 0) > 0 ? 'pass' : 'neutral');
    echo '</div>';

    if (current_user_can(self::CAP_MANAGE)) self::render_create_card();
    if (class_exists('Yablonsky\Multilingual\XLIFFExchange')) self::render_xliff_card();
    self::render_jobs_card($rows, $filters, $per_page, $paged, $pages, $total, $offset);
    echo '</div>';
  }

  private static function render_create_card(): void {
    $prefill_source = (int) filter_input(INPUT_GET, 'source', FILTER_VALIDATE_INT);
    $target_raw = filter_input(INPUT_GET, 'target_locale', FILTER_UNSAFE_RAW);
    $prefill_target = is_string($target_raw) ? sanitize_text_field($target_raw) : '';
    $translators = self::translator_users();
    echo '<section class="ypm-card ypm-card--standalone ymulti-jobs-create"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Assignment', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Create translation job', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('Create one durable job around a native WordPress source. Starting the job creates or reuses the target draft, with publication remaining a human approval step.', 'yablonsky-multilingual') . '</p></div></div>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ypm-workspace-filter-form ymulti-jobs-create-form">';
    wp_nonce_field('ymulti_translation_job_create', 'ymulti_translation_job_nonce');
    echo '<input type="hidden" name="action" value="ymulti_translation_job_create">';
    echo '<label class="ypm-workspace-filter"><span>' . esc_html__('Source post ID', 'yablonsky-multilingual') . '</span><input type="number" min="1" name="source_post_id" value="' . esc_attr($prefill_source > 0 ? (string) $prefill_source : '') . '" required></label>';
    echo '<label class="ypm-workspace-filter"><span>' . esc_html__('Target language', 'yablonsky-multilingual') . '</span><select name="target_locale" required><option value="">' . esc_html__('Choose target…', 'yablonsky-multilingual') . '</option>';
    foreach (Languages::active_codes() as $code) echo '<option value="' . esc_attr($code) . '"' . selected($prefill_target, $code, false) . '>' . esc_html(Languages::display_name($code) . ' · ' . Languages::display_code($code)) . '</option>';
    echo '</select></label>';
    echo '<label class="ypm-workspace-filter"><span>' . esc_html__('Assignee', 'yablonsky-multilingual') . '</span><select name="assignee_user_id"><option value="0">' . esc_html__('Unassigned', 'yablonsky-multilingual') . '</option>';
    foreach ($translators as $user) echo '<option value="' . esc_attr((string) $user->ID) . '">' . esc_html($user->display_name . ' · ' . $user->user_login) . '</option>';
    echo '</select></label>';
    echo '<label class="ypm-workspace-filter"><span>' . esc_html__('Priority', 'yablonsky-multilingual') . '</span><select name="priority"><option value="3">' . esc_html__('High', 'yablonsky-multilingual') . '</option><option value="5" selected>' . esc_html__('Normal', 'yablonsky-multilingual') . '</option><option value="7">' . esc_html__('Low', 'yablonsky-multilingual') . '</option></select></label>';
    echo '<label class="ypm-workspace-filter"><span>' . esc_html__('Due date', 'yablonsky-multilingual') . '</span><input type="date" name="due_at"></label>';
    echo '<label class="ypm-workspace-filter ypm-workspace-filter--search"><span>' . esc_html__('Notes', 'yablonsky-multilingual') . '</span><input type="text" name="notes" maxlength="1000" placeholder="' . esc_attr__('Optional handoff note', 'yablonsky-multilingual') . '"></label>';
    echo '<div class="ypm-workspace-filter-actions"><button class="button button-primary">' . esc_html__('Create job', 'yablonsky-multilingual') . '</button></div></form></section>';
  }


  private static function render_xliff_card(): void {
    $available = XLIFFExchange::available();
    echo '<section class="ypm-card ypm-card--standalone ymulti-jobs-xliff"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Professional interchange', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('XLIFF import & export', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('Export assigned WordPress content as XLIFF 1.2 or 2.0, translate it in a CAT tool, then import it back into the same review-only job. YMulti rejects stale packages when the source changed after export.', 'yablonsky-multilingual') . '</p></div>' . wp_kses_post(Admin::status_badge($available ? 'pass' : 'attention', $available ? __('XLIFF ready', 'yablonsky-multilingual') : __('Setup required · PHP DOM', 'yablonsky-multilingual'))) . '</div>';
    if ($available) {
      echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="ypm-workspace-filter-form ypm-workspace-filter-form--compact">';
      wp_nonce_field('ymulti_xliff_import', 'ymulti_xliff_nonce');
      echo '<input type="hidden" name="action" value="ymulti_xliff_import"><label class="ypm-workspace-filter ypm-workspace-filter--search"><span>' . esc_html__('XLIFF file', 'yablonsky-multilingual') . '</span><input type="file" name="xliff_file" accept=".xlf,.xliff,.xml,application/xml,text/xml" required></label><div class="ypm-workspace-filter-actions"><button class="button button-primary">' . esc_html__('Import to review draft', 'yablonsky-multilingual') . '</button></div></form>';
      echo '<p class="description">' . esc_html__('Safety: maximum 5 MB; DTD/entities are rejected; job ownership is rechecked; imports create reviewed content with human publication approval.', 'yablonsky-multilingual') . '</p>';
    }
    echo '</section>';
  }

  /** @param array<int,array<string,mixed>> $rows */
  private static function render_jobs_card(array $rows, array $filters, int $per_page, int $paged, int $pages, int $total, int $offset): void {
    $translators = self::translator_users();
    echo '<section class="ypm-card ypm-card--standalone ymulti-jobs-queue"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Operations', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Translation job queue', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('Filters are server-side. Translators see only assigned jobs unless they also hold review or management authority.', 'yablonsky-multilingual') . '</p></div></div>';
    echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="ypm-workspace-filter-form ypm-workspace-filter-form--compact"><input type="hidden" name="page" value="ypm-translation-jobs">';
    echo '<label class="ypm-workspace-filter ypm-workspace-filter--search"><span>' . esc_html__('Search', 'yablonsky-multilingual') . '</span><input type="search" name="s" value="' . esc_attr((string) $filters['search']) . '" placeholder="' . esc_attr__('Title or source ID', 'yablonsky-multilingual') . '"></label>';
    echo '<label class="ypm-workspace-filter"><span>' . esc_html__('Status', 'yablonsky-multilingual') . '</span><select name="status"><option value="">' . esc_html__('All statuses', 'yablonsky-multilingual') . '</option>';
    foreach (self::statuses() as $status => $label) echo '<option value="' . esc_attr($status) . '"' . selected((string) $filters['status'], $status, false) . '>' . esc_html($label) . '</option>';
    echo '</select></label>';
    echo '<label class="ypm-workspace-filter"><span>' . esc_html__('Source language', 'yablonsky-multilingual') . '</span><select name="source_locale"><option value="">' . esc_html__('Any source language', 'yablonsky-multilingual') . '</option>';
    foreach (Languages::active_codes() as $code) echo '<option value="' . esc_attr($code) . '"' . selected((string) $filters['source_locale'], $code, false) . '>' . esc_html(Languages::display_name($code) . ' · ' . Languages::display_code($code)) . '</option>';
    echo '</select></label>';
    echo '<label class="ypm-workspace-filter"><span>' . esc_html__('Target language', 'yablonsky-multilingual') . '</span><select name="target_locale"><option value="">' . esc_html__('All languages', 'yablonsky-multilingual') . '</option>';
    foreach (Languages::active_codes() as $code) echo '<option value="' . esc_attr($code) . '"' . selected((string) $filters['target_locale'], $code, false) . '>' . esc_html(Languages::display_name($code) . ' · ' . Languages::display_code($code)) . '</option>';
    echo '</select></label>';
    if (current_user_can(self::CAP_REVIEW) || current_user_can(self::CAP_MANAGE)) {
      echo '<label class="ypm-workspace-filter"><span>' . esc_html__('Assignee', 'yablonsky-multilingual') . '</span><select name="assignee"><option value="0">' . esc_html__('All assignees', 'yablonsky-multilingual') . '</option>';
      foreach ($translators as $user) echo '<option value="' . esc_attr((string) $user->ID) . '"' . selected((int) $filters['assignee'], (int) $user->ID, false) . '>' . esc_html($user->display_name) . '</option>';
      echo '</select></label>';
    }
    echo '<div class="ypm-workspace-filter-actions"><button class="button button-primary">' . esc_html__('Apply filters', 'yablonsky-multilingual') . '</button><a class="button" href="' . esc_url(admin_url('admin.php?page=ypm-translation-jobs')) . '">' . esc_html__('Reset', 'yablonsky-multilingual') . '</a></div></form>';

    if (!$rows) {
      echo '<div class="ypm-empty-panel"><span class="dashicons dashicons-translation" aria-hidden="true"></span><strong>' . esc_html__('0 translation jobs match this view', 'yablonsky-multilingual') . '</strong><p>' . esc_html__('Create a job from a source post or adjust the filters.', 'yablonsky-multilingual') . '</p></div>';
    } else {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ymulti-list-bulkbar ymulti-bulk-form" data-ymulti-bulk-form="jobs">';
      wp_nonce_field('ymulti_translation_jobs_bulk', 'ymulti_translation_job_nonce');
      echo '<input type="hidden" name="action" value="ymulti_translation_jobs_bulk"><div data-ymulti-selected-inputs></div><div class="ymulti-list-bulkbar__copy"><strong>' . esc_html__('Bulk actions', 'yablonsky-multilingual') . '</strong><span>' . esc_html__('Apply only valid state transitions to the checked jobs; ineligible rows are skipped safely.', 'yablonsky-multilingual') . '</span></div><div class="ymulti-list-bulkbar__actions"><select name="bulk_operation" aria-label="' . esc_attr__('Bulk action', 'yablonsky-multilingual') . '"><option value="start">' . esc_html__('Start selected', 'yablonsky-multilingual') . '</option><option value="review">' . esc_html__('Send selected to review', 'yablonsky-multilingual') . '</option><option value="complete">' . esc_html__('Complete selected', 'yablonsky-multilingual') . '</option>';
      if (current_user_can(self::CAP_MANAGE)) echo '<option value="cancel">' . esc_html__('Cancel selected', 'yablonsky-multilingual') . '</option><option value="reassign">' . esc_html__('Reassign selected', 'yablonsky-multilingual') . '</option>';
      echo '</select>';
      if (current_user_can(self::CAP_MANAGE)) { echo '<select name="assignee_user_id" aria-label="' . esc_attr__('Bulk assignee', 'yablonsky-multilingual') . '"><option value="0">' . esc_html__('Unassigned', 'yablonsky-multilingual') . '</option>'; foreach ($translators as $user) echo '<option value="' . esc_attr((string) $user->ID) . '">' . esc_html($user->display_name) . '</option>'; echo '</select>'; }
      echo '<button class="button button-primary" data-ymulti-bulk-selected disabled>' . esc_html__('Apply to selected', 'yablonsky-multilingual') . ' <span data-ymulti-selected-count>(0)</span></button></div></form>';
      echo '<div class="ypm-table-wrap"><table class="widefat"><thead><tr><th class="ymulti-bulk-check"><input type="checkbox" data-ymulti-bulk-all="jobs" aria-label="' . esc_attr__('Select this page', 'yablonsky-multilingual') . '"></th><th>' . esc_html__('Source', 'yablonsky-multilingual') . '</th><th>' . esc_html__('Target', 'yablonsky-multilingual') . '</th><th>' . esc_html__('Assignee', 'yablonsky-multilingual') . '</th><th>' . esc_html__('Status', 'yablonsky-multilingual') . '</th><th>' . esc_html__('Updated', 'yablonsky-multilingual') . '</th><th class="ypm-col-action">' . esc_html__('Action', 'yablonsky-multilingual') . '</th></tr></thead><tbody>';
      foreach ($rows as $job) self::render_job_row($job, $translators);
      echo '</tbody></table></div>';
    }

    $visible = count($rows);
    $start = $visible > 0 ? $offset + 1 : 0;
    $end = $visible > 0 ? min($total, $offset + $visible) : 0;
    echo '<div class="ypm-pagination"><span>' . esc_html(sprintf(
      /* translators: 1: first visible job, 2: last visible job, 3: total matching jobs. */
      __('Showing %1$d–%2$d of %3$d', 'yablonsky-multilingual'),
      $start,
      $end,
      $total
    )) . '</span>';
    Admin::render_server_page_size(array_merge(['page' => 'ypm-translation-jobs'], $filters), $per_page, self::PAGE_SIZES);
    if ($pages > 1) {
      $base = self::page_url($filters, $per_page, 1);
      $links = paginate_links(['base' => add_query_arg('paged', '%#%', $base), 'format' => '', 'current' => $paged, 'total' => $pages, 'prev_text' => '‹', 'next_text' => '›', 'type' => 'array']);
      if (is_array($links) && $links) echo '<span class="ypm-pagination__links">' . wp_kses_post(implode('', $links)) . '</span>';
    }
    echo '</div></section>';
  }

  /** @param array<int,\WP_User> $translators */
  private static function render_job_row(array $job, array $translators): void {
    $job_id = absint($job['id'] ?? 0);
    $source_id = absint($job['source_post_id'] ?? 0);
    $target_id = absint($job['target_post_id'] ?? 0);
    $source_title = (string) ($job['source_title'] ?? get_the_title($source_id));
    $source_locale = sanitize_text_field((string) ($job['source_locale'] ?? ''));
    $target_locale = sanitize_text_field((string) ($job['target_locale'] ?? ''));
    $status = sanitize_key((string) ($job['status'] ?? self::STATUS_OPEN));
    $status_label = self::statuses()[$status] ?? $status;
    $state = $status === self::STATUS_COMPLETED ? 'pass' : ($status === self::STATUS_REVIEW ? 'attention' : ($status === self::STATUS_IN_PROGRESS ? 'running' : ($status === self::STATUS_CANCELLED ? 'neutral' : 'neutral')));
    $assignee = absint($job['assignee_user_id'] ?? 0);
    $assignee_name = $assignee > 0 ? (string) get_the_author_meta('display_name', $assignee) : __('Unassigned', 'yablonsky-multilingual');

    /* translators: %d: translation job ID. */
    $select_job_label = sprintf(__('Select translation job #%d', 'yablonsky-multilingual'), $job_id);
    echo '<tr><td class="ymulti-bulk-check"><input type="checkbox" value="' . esc_attr((string) $job_id) . '" data-ymulti-bulk-row="jobs" aria-label="' . esc_attr($select_job_label) . '"></td><td><div class="ypm-content-cell"><strong>' . esc_html($source_title !== '' ? $source_title : ('#' . $source_id)) . '</strong><span>#' . esc_html((string) $source_id) . ' · ' . esc_html(Languages::display_code($source_locale)) . '</span></div></td>';
    echo '<td><div class="ypm-content-cell"><strong>' . esc_html(Languages::display_name($target_locale)) . '</strong><span>' . esc_html(Languages::display_code($target_locale)) . ($target_id > 0 ? ' · #' . esc_html((string) $target_id) : '') . '</span></div></td>';
    echo '<td>' . esc_html($assignee_name) . '</td><td>' . wp_kses_post(Admin::status_badge($state, $status_label)) . '</td><td>' . esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) ($job['updated_at'] ?? ''), true)) . '</td><td class="ypm-col-action"><div class="ypm-row-actions">';

    echo '<a class="button" href="' . esc_url(add_query_arg(['page' => 'ypm-translation-job', 'job_id' => $job_id], admin_url('admin.php'))) . '">' . esc_html__('Details', 'yablonsky-multilingual') . '</a>';
    if (self::can_work_job($job) && !in_array($status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
      $label = $target_id > 0 ? __('Continue', 'yablonsky-multilingual') : __('Start', 'yablonsky-multilingual');
      self::action_form('ymulti_translation_job_start', 'ymulti_translation_job_start_' . $job_id, $job_id, $label, $target_id > 0 ? 'button' : 'button button-primary');
    }
    if (class_exists('Yablonsky\Multilingual\XLIFFExchange') && XLIFFExchange::available() && self::can_view_job($job)) {
      echo '<a class="button" href="' . esc_url(XLIFFExchange::export_url($job_id, '12')) . '">' . esc_html__('XLIFF 1.2', 'yablonsky-multilingual') . '</a>';
      echo '<a class="button" href="' . esc_url(XLIFFExchange::export_url($job_id, '20')) . '">' . esc_html__('XLIFF 2.0', 'yablonsky-multilingual') . '</a>';
    }
    if ($target_id > 0 && current_user_can('edit_post', $target_id)) {
      $edit = get_edit_post_link($target_id, '');
      if ($edit) echo '<a class="button" href="' . esc_url($edit) . '">' . esc_html__('Edit draft', 'yablonsky-multilingual') . '</a>';
    }
    if (self::can_work_job($job) && in_array($status, [self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS], true)) {
      self::action_form('ymulti_translation_job_review', 'ymulti_translation_job_review_' . $job_id, $job_id, __('Send to review', 'yablonsky-multilingual'), 'button');
    }
    if (self::can_review_job($job) && $status === self::STATUS_REVIEW) {
      self::action_form('ymulti_translation_job_complete', 'ymulti_translation_job_complete_' . $job_id, $job_id, __('Complete', 'yablonsky-multilingual'), 'button button-primary');
    }
    if (current_user_can(self::CAP_MANAGE) && !in_array($status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
      self::action_form('ymulti_translation_job_cancel', 'ymulti_translation_job_cancel_' . $job_id, $job_id, __('Cancel', 'yablonsky-multilingual'), 'button button-link-delete');
    }
    echo '</div>';

    if (current_user_can(self::CAP_MANAGE) && !in_array($status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
      echo '<details class="ypm-disclosure"><summary>' . esc_html__('Assignment', 'yablonsky-multilingual') . '</summary><div class="ypm-disclosure__body"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('ymulti_translation_job_reassign_' . $job_id, 'ymulti_translation_job_nonce');
      echo '<input type="hidden" name="action" value="ymulti_translation_job_reassign"><input type="hidden" name="job_id" value="' . esc_attr((string) $job_id) . '"><select name="assignee_user_id"><option value="0">' . esc_html__('Unassigned', 'yablonsky-multilingual') . '</option>';
      foreach ($translators as $user) echo '<option value="' . esc_attr((string) $user->ID) . '"' . selected($assignee, (int) $user->ID, false) . '>' . esc_html($user->display_name) . '</option>';
      echo '</select> <button class="button">' . esc_html__('Save', 'yablonsky-multilingual') . '</button></form></div></details>';
    }
    echo '</td></tr>';
  }

  private static function action_form(string $action, string $nonce_action, int $job_id, string $label, string $class): void {
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ymulti-inline-form">';
    wp_nonce_field($nonce_action, 'ymulti_translation_job_nonce');
    echo '<input type="hidden" name="action" value="' . esc_attr($action) . '"><input type="hidden" name="job_id" value="' . esc_attr((string) $job_id) . '"><button class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
  }

  public static function handle_create(): void {
    if (!current_user_can(self::CAP_MANAGE)) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ymulti_translation_job_create', 'ymulti_translation_job_nonce');
    $source_id = isset($_POST['source_post_id']) ? absint(wp_unslash($_POST['source_post_id'])) : 0;
    $target = isset($_POST['target_locale']) ? sanitize_text_field(wp_unslash($_POST['target_locale'])) : '';
    $assignee = isset($_POST['assignee_user_id']) ? absint(wp_unslash($_POST['assignee_user_id'])) : 0;
    $priority = isset($_POST['priority']) ? absint(wp_unslash($_POST['priority'])) : 5;
    $due = isset($_POST['due_at']) ? sanitize_text_field(wp_unslash($_POST['due_at'])) : '';
    $notes = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
    if ($source_id <= 0 || !current_user_can('edit_post', $source_id)) self::redirect_notice('error', __('Choose a source that supports a YMulti translation job.', 'yablonsky-multilingual'));
    $created = self::create_job($source_id, $target, $assignee, $priority, $due, $notes);
    if (is_wp_error($created)) self::redirect_notice('error', $created->get_error_message());
    self::redirect_notice('success', sprintf(
      /* translators: %d: new translation job ID. */
      __('Translation job #%d created.', 'yablonsky-multilingual'),
      (int) $created
    ));
  }

  public static function handle_start(): void {
    $job = self::require_job_from_post('start');
    if (!self::can_work_job($job)) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    $target = self::ensure_target_draft($job, get_current_user_id());
    if (is_wp_error($target)) self::redirect_notice('error', $target->get_error_message());
    self::update_job((int) $job['id'], ['status' => self::STATUS_IN_PROGRESS, 'target_post_id' => (int) $target]);
    self::log_event((int) $job['id'], 'started', __('Translation work started.', 'yablonsky-multilingual'), ['target_post_id' => (int) $target]);
    $edit = get_edit_post_link((int) $target, '');
    if ($edit) {
      wp_safe_redirect($edit);
      exit;
    }
    self::redirect_notice('success', __('Translation job started.', 'yablonsky-multilingual'));
  }

  public static function handle_review(): void {
    $job = self::require_job_from_post('review');
    if (!self::can_work_job($job)) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    $target = absint($job['target_post_id'] ?? 0);
    if ($target <= 0 || !get_post($target)) self::redirect_notice('error', __('Start the job and create its target draft before sending it to review.', 'yablonsky-multilingual'));
    self::update_job((int) $job['id'], ['status' => self::STATUS_REVIEW]);
    self::log_event((int) $job['id'], 'review_requested', __('Translation sent to review.', 'yablonsky-multilingual'));
    self::redirect_notice('success', __('Translation sent to review.', 'yablonsky-multilingual'));
  }

  public static function handle_complete(): void {
    $job = self::require_job_from_post('complete');
    if (!self::can_review_job($job)) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    $target = absint($job['target_post_id'] ?? 0);
    if ($target <= 0 || !get_post($target)) self::redirect_notice('error', __('Target translation draft is unavailable. Start the job to create its target draft, then complete the review.', 'yablonsky-multilingual'));
    self::update_job((int) $job['id'], ['status' => self::STATUS_COMPLETED]);
    self::log_event((int) $job['id'], 'completed', __('Translation job completed.', 'yablonsky-multilingual'));
    self::redirect_notice('success', __('Translation job completed. Publishing remains a separate WordPress editorial decision.', 'yablonsky-multilingual'));
  }

  public static function handle_cancel(): void {
    $job = self::require_job_from_post('cancel');
    if (!current_user_can(self::CAP_MANAGE)) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    self::update_job((int) $job['id'], ['status' => self::STATUS_CANCELLED]);
    self::log_event((int) $job['id'], 'cancelled', __('Translation job cancelled.', 'yablonsky-multilingual'));
    self::redirect_notice('success', __('Translation job cancelled. Existing content remains intact.', 'yablonsky-multilingual'));
  }



  public static function handle_bulk(): void {
    check_admin_referer('ymulti_translation_jobs_bulk', 'ymulti_translation_job_nonce');
    $operation_raw = filter_input(INPUT_POST, 'bulk_operation', FILTER_UNSAFE_RAW);
    $operation = is_string($operation_raw) ? sanitize_key($operation_raw) : '';
    $ids_raw = filter_input(INPUT_POST, 'source_ids', FILTER_SANITIZE_NUMBER_INT, FILTER_REQUIRE_ARRAY);
    $assignee = max(0, (int) filter_input(INPUT_POST, 'assignee_user_id', FILTER_VALIDATE_INT));
    $allowed_operations = ['start', 'review', 'complete'];
    if (current_user_can(self::CAP_MANAGE)) $allowed_operations = array_merge($allowed_operations, ['cancel', 'reassign']);
    if (!in_array($operation, $allowed_operations, true) || !is_array($ids_raw)) self::redirect_notice('error', __('Choose a valid bulk job action and at least one job.', 'yablonsky-multilingual'));
    if ($operation === 'reassign' && $assignee > 0 && !user_can($assignee, self::CAP_TRANSLATE)) self::redirect_notice('error', __('Choose a user with the YMulti translator role.', 'yablonsky-multilingual'));
    $ids = array_slice(array_values(array_unique(array_filter(array_map('absint', $ids_raw)))), 0, 100);
    $applied = 0; $skipped = 0;
    foreach ($ids as $job_id) {
      $job = self::get_job($job_id);
      if (!$job) { $skipped++; continue; }
      $status = sanitize_key((string) ($job['status'] ?? self::STATUS_OPEN));
      if ($operation === 'start') {
        if (!self::can_work_job($job) || in_array($status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) { $skipped++; continue; }
        $target = self::ensure_target_draft($job, get_current_user_id());
        if (is_wp_error($target)) { $skipped++; continue; }
        self::update_job($job_id, ['status' => self::STATUS_IN_PROGRESS, 'target_post_id' => (int) $target]);
        self::log_event($job_id, 'started', __('Translation work started by bulk action.', 'yablonsky-multilingual'), ['target_post_id' => (int) $target]); $applied++; continue;
      }
      if ($operation === 'review') {
        $target = absint($job['target_post_id'] ?? 0);
        if (!self::can_work_job($job) || !in_array($status, [self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS], true) || $target <= 0 || !get_post($target)) { $skipped++; continue; }
        self::update_job($job_id, ['status' => self::STATUS_REVIEW]); self::log_event($job_id, 'review_requested', __('Translation sent to review by bulk action.', 'yablonsky-multilingual')); $applied++; continue;
      }
      if ($operation === 'complete') {
        $target = absint($job['target_post_id'] ?? 0);
        if (!self::can_review_job($job) || $status !== self::STATUS_REVIEW || $target <= 0 || !get_post($target)) { $skipped++; continue; }
        self::update_job($job_id, ['status' => self::STATUS_COMPLETED]); self::log_event($job_id, 'completed', __('Translation job completed by bulk action.', 'yablonsky-multilingual')); $applied++; continue;
      }
      if ($operation === 'cancel') {
        if (!current_user_can(self::CAP_MANAGE) || in_array($status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) { $skipped++; continue; }
        self::update_job($job_id, ['status' => self::STATUS_CANCELLED]); self::log_event($job_id, 'cancelled', __('Translation job cancelled by bulk action.', 'yablonsky-multilingual')); $applied++; continue;
      }
      if ($operation === 'reassign') {
        if (!current_user_can(self::CAP_MANAGE) || in_array($status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) { $skipped++; continue; }
        $next_status = $status;
        if (in_array($status, [self::STATUS_OPEN, self::STATUS_ASSIGNED], true)) $next_status = $assignee > 0 ? self::STATUS_ASSIGNED : self::STATUS_OPEN;
        self::update_job($job_id, ['assignee_user_id' => $assignee, 'status' => $next_status]);
        self::log_event($job_id, 'reassigned', __('Translation job assignment updated by bulk action.', 'yablonsky-multilingual'), ['assignee_user_id' => $assignee]); $applied++; continue;
      }
      $skipped++;
    }
    self::redirect_notice('success', sprintf(
      /* translators: 1: jobs changed, 2: jobs skipped because the selected transition was not valid. */
      __('Bulk action updated %1$d jobs; %2$d were skipped safely.', 'yablonsky-multilingual'),
      $applied,
      $skipped
    ));
  }

  /** Create bounded professional jobs for the currently visible translation page. */
  public static function handle_create_page(): void {
    if (!current_user_can(self::CAP_MANAGE)) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ymulti_translation_jobs_create_page', 'ymulti_translation_job_nonce');
    $target = isset($_POST['target_locale']) ? sanitize_text_field(wp_unslash($_POST['target_locale'])) : '';
    $assignee = isset($_POST['assignee_user_id']) ? absint(wp_unslash($_POST['assignee_user_id'])) : 0;
    $mode_raw = filter_input(INPUT_POST, 'job_mode', FILTER_UNSAFE_RAW);
    $mode = is_string($mode_raw) ? sanitize_key($mode_raw) : 'page';
    if (!in_array($mode, ['selected', 'page'], true)) $mode = 'page';
    $posted_ids = filter_input(INPUT_POST, $mode === 'selected' ? 'source_ids' : 'page_ids', FILTER_SANITIZE_NUMBER_INT, FILTER_REQUIRE_ARRAY);
    if (!is_array($posted_ids)) $posted_ids = [];
    $ids = [];
    foreach ($posted_ids as $value) {
      $id = absint($value);
      if ($id <= 0 || !current_user_can('edit_post', $id) || in_array($id, $ids, true)) continue;
      $ids[] = $id;
      if (count($ids) >= 100) break;
    }
    if (!$ids || !Languages::is_active($target)) self::redirect_notice('error', __('Choose a target language and at least one editable source in the selected scope.', 'yablonsky-multilingual'), admin_url('admin.php?page=ypm-translations'));
    if ($assignee > 0 && !user_can($assignee, self::CAP_TRANSLATE)) self::redirect_notice('error', __('Choose a user with the YMulti translator role.', 'yablonsky-multilingual'), admin_url('admin.php?page=ypm-translations'));

    $created = 0;
    $skipped = 0;
    foreach ($ids as $source_id) {
      if (RuntimeAuthority::effective_post_language($source_id) === $target) { $skipped++; continue; }
      $result = self::create_job($source_id, $target, $assignee);
      if (is_wp_error($result)) { $skipped++; continue; }
      $created++;
    }
    $message = sprintf(
      /* translators: 1: created translation jobs, 2: sources skipped because a target/job already existed or was invalid. */
      __('Created %1$d translation jobs; %2$d sources were skipped.', 'yablonsky-multilingual'),
      $created,
      $skipped
    );
    self::redirect_notice('success', $message, admin_url('admin.php?page=ypm-translation-jobs'));
  }

  public static function create_job_url(int $source_id, string $target_locale): string {
    return add_query_arg([
      'page' => 'ypm-translation-jobs',
      'source' => $source_id,
      'target_locale' => $target_locale,
    ], admin_url('admin.php'));
  }

  public static function handle_reassign(): void {
    $job = self::require_job_from_post('reassign');
    if (!current_user_can(self::CAP_MANAGE)) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- require_job_from_post() verifies the job-scoped nonce before this request value is read.
    $assignee = isset($_POST['assignee_user_id']) ? absint(wp_unslash($_POST['assignee_user_id'])) : 0;
    if ($assignee > 0 && !user_can($assignee, self::CAP_TRANSLATE)) self::redirect_notice('error', __('Choose a user with the YMulti translator role.', 'yablonsky-multilingual'));
    $status = sanitize_key((string) ($job['status'] ?? self::STATUS_OPEN));
    if (in_array($status, [self::STATUS_OPEN, self::STATUS_ASSIGNED], true)) $status = $assignee > 0 ? self::STATUS_ASSIGNED : self::STATUS_OPEN;
    self::update_job((int) $job['id'], ['assignee_user_id' => $assignee, 'status' => $status]);
    self::log_event((int) $job['id'], 'reassigned', __('Translation job assignment updated.', 'yablonsky-multilingual'), ['assignee_user_id' => $assignee]);
    self::redirect_notice('success', __('Translation job assignment updated.', 'yablonsky-multilingual'));
  }

  /** @return array<string,mixed> */
  private static function require_job_from_post(string $operation): array {
    $job_id = isset($_POST['job_id']) ? absint(wp_unslash($_POST['job_id'])) : 0;
    check_admin_referer('ymulti_translation_job_' . $operation . '_' . $job_id, 'ymulti_translation_job_nonce');
    $job = self::get_job($job_id);
    if (!$job) wp_die(esc_html__('Translation job is unavailable. Return to Translation Jobs and choose an existing job.', 'yablonsky-multilingual'));
    return $job;
  }

  /** @return array<string,mixed> */
  private static function read_filters(): array {
    $search_raw = filter_input(INPUT_GET, 's', FILTER_UNSAFE_RAW);
    $status_raw = filter_input(INPUT_GET, 'status', FILTER_UNSAFE_RAW);
    $source_raw = filter_input(INPUT_GET, 'source_locale', FILTER_UNSAFE_RAW);
    $target_raw = filter_input(INPUT_GET, 'target_locale', FILTER_UNSAFE_RAW);
    $search = is_string($search_raw) ? trim(sanitize_text_field($search_raw)) : '';
    $status = is_string($status_raw) ? sanitize_key($status_raw) : '';
    if ($status !== '' && !isset(self::statuses()[$status])) $status = '';
    $source = is_string($source_raw) ? sanitize_text_field($source_raw) : '';
    if ($source !== '' && !Languages::is_active($source)) $source = '';
    $target = is_string($target_raw) ? sanitize_text_field($target_raw) : '';
    if ($target !== '' && !Languages::is_active($target)) $target = '';
    $assignee = max(0, (int) filter_input(INPUT_GET, 'assignee', FILTER_VALIDATE_INT));
    if (!current_user_can(self::CAP_REVIEW) && !current_user_can(self::CAP_MANAGE)) $assignee = get_current_user_id();
    return ['search' => $search, 'status' => $status, 'source_locale' => $source, 'target_locale' => $target, 'assignee' => $assignee];
  }

  private static function count_jobs(array $filters): int {
    global $wpdb;
    [$where, $args] = self::where_sql($filters);
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WHERE fragments come only from fixed allowlisted predicates; request values remain placeholders.
    $sql = 'SELECT COUNT(*) FROM ' . esc_sql(self::table()) . ' j LEFT JOIN ' . esc_sql($wpdb->posts) . ' p ON p.ID = j.source_post_id WHERE ' . $where;
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic SQL contains only internal identifiers/allowlisted fragments and placeholders.
    $prepared = $args ? $wpdb->prepare($sql, ...$args) : $sql;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Prepared bounded admin count on YMulti-owned table joined to posts for title search.
    return (int) $wpdb->get_var($prepared);
  }

  /** @return array<int,array<string,mixed>> */
  private static function list_jobs(array $filters, int $limit, int $offset): array {
    global $wpdb;
    [$where, $args] = self::where_sql($filters);
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WHERE fragments come only from fixed allowlisted predicates; LIMIT/OFFSET and request values are placeholders.
    $sql = 'SELECT j.*, p.post_title AS source_title, p.post_type AS source_post_type FROM ' . esc_sql(self::table()) . ' j LEFT JOIN ' . esc_sql($wpdb->posts) . ' p ON p.ID = j.source_post_id WHERE ' . $where . ' ORDER BY j.priority ASC, j.updated_at DESC, j.id DESC LIMIT %d OFFSET %d';
    $args[] = $limit;
    $args[] = $offset;
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic SQL contains only internal identifiers/allowlisted fragments and placeholders.
    $prepared = $wpdb->prepare($sql, ...$args);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Prepared bounded admin listing on YMulti-owned table.
    $rows = $wpdb->get_results($prepared, ARRAY_A);
    return is_array($rows) ? $rows : [];
  }

  /** @return array{0:string,1:array<int,mixed>} */
  private static function where_sql(array $filters): array {
    global $wpdb;
    $where = ['1=1'];
    $args = [];
    if ((string) $filters['status'] !== '') { $where[] = 'j.status = %s'; $args[] = (string) $filters['status']; }
    if ((string) $filters['source_locale'] !== '') { $where[] = 'j.source_locale = %s'; $args[] = (string) $filters['source_locale']; }
    if ((string) $filters['target_locale'] !== '') { $where[] = 'j.target_locale = %s'; $args[] = (string) $filters['target_locale']; }
    if ((int) $filters['assignee'] > 0) { $where[] = 'j.assignee_user_id = %d'; $args[] = (int) $filters['assignee']; }
    if ((string) $filters['search'] !== '') {
      $search = (string) $filters['search'];
      if (ctype_digit($search)) {
        $where[] = '(j.source_post_id = %d OR j.target_post_id = %d OR j.id = %d)';
        $id = (int) $search;
        array_push($args, $id, $id, $id);
      } else {
        $where[] = 'p.post_title LIKE %s';
        $args[] = '%' . $wpdb->esc_like($search) . '%';
      }
    }
    return [implode(' AND ', $where), $args];
  }

  /** @return array<string,int> */
  private static function status_counts(): array {
    global $wpdb;
    $table = self::table();
    $where = '1=1';
    $args = [];
    if (!current_user_can(self::CAP_REVIEW) && !current_user_can(self::CAP_MANAGE)) {
      $where = 'assignee_user_id = %d';
      $args[] = get_current_user_id();
    }
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Predicate is fixed and optional user ID remains a placeholder.
    $sql = 'SELECT status, COUNT(*) AS total FROM ' . esc_sql($table) . ' WHERE ' . $where . ' GROUP BY status';
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic SQL contains only fixed internal fragments.
    $prepared = $args ? $wpdb->prepare($sql, ...$args) : $sql;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Small workflow aggregate on YMulti-owned table.
    $rows = $wpdb->get_results($prepared);
    $out = [];
    if (is_array($rows)) foreach ($rows as $row) $out[(string) $row->status] = (int) $row->total;
    return $out;
  }

  /** @return array<int,\WP_User> */
  public static function translator_users(): array {
    $users = get_users(['number' => 250, 'orderby' => 'display_name', 'order' => 'ASC', 'fields' => 'all']);
    $out = [];
    foreach ($users as $user) if ($user instanceof \WP_User && user_can($user, self::CAP_TRANSLATE)) $out[] = $user;
    return $out;
  }

  private static function page_url(array $filters, int $per_page, int $paged = 1): string {
    $args = ['page' => 'ypm-translation-jobs', 'per_page' => $per_page];
    if ((string) $filters['search'] !== '') $args['s'] = (string) $filters['search'];
    if ((string) $filters['status'] !== '') $args['status'] = (string) $filters['status'];
    if ((string) $filters['source_locale'] !== '') $args['source_locale'] = (string) $filters['source_locale'];
    if ((string) $filters['target_locale'] !== '') $args['target_locale'] = (string) $filters['target_locale'];
    if ((int) $filters['assignee'] > 0 && (current_user_can(self::CAP_REVIEW) || current_user_can(self::CAP_MANAGE))) $args['assignee'] = (int) $filters['assignee'];
    if ($paged > 1) $args['paged'] = $paged;
    return add_query_arg($args, admin_url('admin.php'));
  }


  /** @param array<string,mixed> $data */
  public static function log_event(int $job_id, string $event_type, string $message, array $data = [], int $actor_user_id = 0): void {
    if ($job_id <= 0) return;
    global $wpdb;
    $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Append-only workflow audit record in YMulti-owned table.
    $wpdb->insert(self::events_table(), [
      'job_id' => $job_id,
      'actor_user_id' => $actor_user_id > 0 ? $actor_user_id : get_current_user_id(),
      'event_type' => sanitize_key($event_type),
      'message' => sanitize_text_field($message),
      'data_json' => is_string($json) ? $json : '{}',
      'created_at' => current_time('mysql', true),
    ], ['%d','%d','%s','%s','%s','%s']);
  }

  /** @return array<int,array<string,mixed>> */
  public static function events_for_job(int $job_id, int $limit = 50): array {
    if ($job_id <= 0) return [];
    global $wpdb;
    $limit = max(1, min(100, $limit));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded audit timeline for one authorized admin job.
    $rows = $wpdb->get_results($wpdb->prepare('SELECT id, actor_user_id, event_type, message, data_json, created_at FROM ' . esc_sql(self::events_table()) . ' WHERE job_id = %d ORDER BY id DESC LIMIT %d', $job_id, $limit), ARRAY_A);
    return is_array($rows) ? $rows : [];
  }

  public static function render_detail_page(): void {
    $job_id = (int) filter_input(INPUT_GET, 'job_id', FILTER_VALIDATE_INT);
    $job = self::get_job($job_id);
    if (!$job || !self::can_view_job($job)) wp_die(esc_html__('Translation job access requires an available job and matching permission.', 'yablonsky-multilingual'));
    $source_id = absint($job['source_post_id'] ?? 0);
    $target_id = absint($job['target_post_id'] ?? 0);
    $source_hash = self::source_hash($source_id);
    $exported_hash = (string) ($job['exported_hash'] ?? '');
    $drift = $exported_hash !== '' && $source_hash !== '' && !hash_equals($exported_hash, $source_hash);
    $events = self::events_for_job($job_id, 50);

    echo '<div class="wrap ypm-admin ypm-admin--translation-job">';
    Admin::render_shell_header(
      sprintf(
        /* translators: %d: translation job ID. */
        __('Translation Job #%d', 'yablonsky-multilingual'),
        $job_id
      ),
      __('Inspect one professional translation assignment, source snapshot and bounded workflow history.', 'yablonsky-multilingual'),
      $drift ? 'attention' : 'neutral',
      $drift ? __('Source changed', 'yablonsky-multilingual') : __('Snapshot current', 'yablonsky-multilingual')
    );
    echo '<div class="ypm-family-page-bar"><a class="button" href="' . esc_url(admin_url('admin.php?page=ypm-translation-jobs')) . '">&larr; ' . esc_html__('Back to jobs', 'yablonsky-multilingual') . '</a>';
    if ($source_id > 0 && current_user_can('edit_post', $source_id)) { $url = get_edit_post_link($source_id, ''); if ($url) echo '<a class="button" href="' . esc_url($url) . '">' . esc_html__('Open source', 'yablonsky-multilingual') . '</a>'; }
    if ($target_id > 0 && current_user_can('edit_post', $target_id)) { $url = get_edit_post_link($target_id, ''); if ($url) echo '<a class="button button-primary" href="' . esc_url($url) . '">' . esc_html__('Open target draft', 'yablonsky-multilingual') . '</a>'; }
    echo '</div><div class="ypm-metrics ypm-metrics--4">';
    Admin::render_metric(__('Source', 'yablonsky-multilingual'), '#' . (string) $source_id, get_the_title($source_id) ?: __('WordPress content', 'yablonsky-multilingual'), 'neutral');
    Admin::render_metric(__('Language pair', 'yablonsky-multilingual'), Languages::display_code((string) $job['source_locale']) . ' → ' . Languages::display_code((string) $job['target_locale']), Languages::display_name((string) $job['target_locale']), 'neutral');
    Admin::render_metric(__('Status', 'yablonsky-multilingual'), self::statuses()[(string) $job['status']] ?? (string) $job['status'], __('Human workflow state', 'yablonsky-multilingual'), (string) $job['status'] === self::STATUS_REVIEW ? 'attention' : ((string) $job['status'] === self::STATUS_COMPLETED ? 'pass' : 'neutral'));
    Admin::render_metric(__('Source snapshot', 'yablonsky-multilingual'), $drift ? __('Changed', 'yablonsky-multilingual') : __('Current', 'yablonsky-multilingual'), $exported_hash !== '' ? __('Compared with latest XLIFF export', 'yablonsky-multilingual') : __('XLIFF export ready to create', 'yablonsky-multilingual'), $drift ? 'attention' : 'pass');
    echo '</div>';
    if ($drift) echo '<div class="ypm-callout ypm-callout--attention"><span class="dashicons dashicons-warning" aria-hidden="true"></span><div><strong>' . esc_html__('Source drift detected', 'yablonsky-multilingual') . '</strong><span>' . esc_html__('A previously exported XLIFF package is now stale. Export a fresh package before importing external translations.', 'yablonsky-multilingual') . '</span></div></div>';
    echo '<section class="ypm-card ypm-card--standalone"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Audit trail', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Job history', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('Latest bounded workflow events. Content remains in native WordPress posts; this log records coordination only.', 'yablonsky-multilingual') . '</p></div></div>';
    if (!$events) {
      echo '<div class="ypm-empty-panel"><strong>' . esc_html__('Audit events appear after the first job action', 'yablonsky-multilingual') . '</strong></div>';
    } else {
      echo '<div class="ypm-check-list">';
      foreach ($events as $event) {
        $actor = absint($event['actor_user_id'] ?? 0);
        $actor_name = $actor > 0 ? (string) get_the_author_meta('display_name', $actor) : __('System', 'yablonsky-multilingual');
        echo '<div class="ypm-check-row"><span class="ypm-status-dot ypm-status-dot--neutral"></span><div><strong>' . esc_html((string) ($event['message'] ?? '')) . '</strong><small>' . esc_html($actor_name . ' · ' . mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) ($event['created_at'] ?? ''), true)) . '</small></div><code>' . esc_html((string) ($event['event_type'] ?? 'event')) . '</code></div>';
      }
      echo '</div>';
    }
    echo '</section></div>';
  }

  private static function normalize_mysql_datetime(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    $timestamp = strtotime($value . ' 23:59:59 UTC');
    if (!$timestamp) return '';
    return gmdate('Y-m-d H:i:s', $timestamp);
  }

  private static function render_notice(): void {
    $notice = get_transient('ymulti_translation_job_notice_' . get_current_user_id());
    if (!is_array($notice) || empty($notice['message'])) return;
    delete_transient('ymulti_translation_job_notice_' . get_current_user_id());
    $type = ($notice['type'] ?? '') === 'error' ? 'error' : 'success';
    echo '<div class="notice notice-' . esc_attr($type) . ' inline"><p>' . esc_html((string) $notice['message']) . '</p></div>';
  }

  private static function redirect_notice(string $type, string $message, string $url = ''): void {
    set_transient('ymulti_translation_job_notice_' . get_current_user_id(), ['type' => $type, 'message' => sanitize_text_field($message)], 60);
    wp_safe_redirect($url !== '' ? $url : admin_url('admin.php?page=ypm-translation-jobs'));
    exit;
  }
}
