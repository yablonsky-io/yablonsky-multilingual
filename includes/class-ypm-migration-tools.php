<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Admin-only resumable migration runner.
 * Detect -> Dry Run -> Explicit Import. Sources are never auto-disabled.
 */
final class MigrationTools {
  private const JOB_OPT = 'ypm_migration_job';
  private const DRYRUN_OPT = 'ypm_migration_dryrun';
  private const CRON_HOOK = 'yablonsky_multilingual_migration_tick';
  private const LOCK_TRANSIENT = 'ypm_migration_runner_lock';
  private const BATCH = 250;
  private const LEGACY_LOCK_OPT = 'ypm_legacy_group_migration_lock';

  public static function init(): void {
    add_action('admin_menu', [__CLASS__, 'register_page'], 40);
    add_action('admin_post_ypm_migration_start', [__CLASS__, 'handle_start']);
    add_action('admin_post_ypm_migration_import', [__CLASS__, 'handle_import']);
    add_action('admin_post_ypm_migration_tick', [__CLASS__, 'handle_manual_tick']);
    add_action('admin_post_ypm_migration_export', [__CLASS__, 'handle_export']);
    add_action('admin_post_ypm_legacy_group_batch', [__CLASS__, 'handle_legacy_group_batch']);
    add_action('admin_post_ypm_legacy_lock_reset', [__CLASS__, 'handle_legacy_lock_reset']);
    add_action('wp_ajax_ypm_migration_tick', [__CLASS__, 'ajax_tick']);
    add_action(self::CRON_HOOK, [__CLASS__, 'cron_tick']);
  }

  public static function register_page(): void {
    add_submenu_page(
      'ypm-multilingual',
      __('Migration', 'yablonsky-multilingual'),
      __('Migration', 'yablonsky-multilingual'),
      'manage_options',
      'ypm-migration',
      [__CLASS__, 'render_page']
    );
  }

  public static function render_page(): void {
    if (!current_user_can('manage_options')) return;
    $detected = self::detect_sources();
    $job = get_option(self::JOB_OPT, []);
    if (!is_array($job)) $job = [];
    $dryruns = get_option(self::DRYRUN_OPT, []);
    if (!is_array($dryruns)) $dryruns = [];
    $job_running = ($job['status'] ?? '') === 'running';

    $source_labels = ['wpml' => 'WPML', 'polylang' => 'Polylang', 'fitoterra' => 'Fitoterra Multilingual'];
    $detected_count = count(array_filter($detected));
    $ready_dryruns = 0;
    foreach (array_keys($source_labels) as $source) {
      if (!empty($dryruns[$source]) && ($dryruns[$source]['signature'] ?? '') === self::source_signature($source)) $ready_dryruns++;
    }
    $job_status = !empty($job['status']) ? strtoupper((string) $job['status']) : __('Idle', 'yablonsky-multilingual');
    $job_state = ($job['status'] ?? '') === 'complete' ? 'pass' : (($job['status'] ?? '') === 'running' ? 'attention' : 'neutral');

    echo '<div class="wrap ypm-admin ypm-admin--migration">';
    $migration_complete = ($job['status'] ?? '') === 'complete';
    Admin::render_shell_header(
      __('Migration', 'yablonsky-multilingual'),
      __('Move multilingual relationships into YMulti with a controlled sequence: detect, dry run, review evidence, then explicitly import.', 'yablonsky-multilingual'),
      $migration_complete ? 'pass' : ($detected_count > 0 ? 'attention' : 'neutral'),
      $migration_complete ? __('Complete', 'yablonsky-multilingual') : ($detected_count > 0 ? __('Action available', 'yablonsky-multilingual') : __('Ready as needed', 'yablonsky-multilingual'))
    );

    if (filter_input(INPUT_GET, 'busy', FILTER_VALIDATE_BOOLEAN) === true) {
      echo '<div class="ypm-callout ypm-callout--attention"><span class="dashicons dashicons-warning" aria-hidden="true"></span><div><strong>' . esc_html__('A migration job is already running.', 'yablonsky-multilingual') . '</strong><p>' . esc_html__('Finish or let the current bounded job complete before starting another source.', 'yablonsky-multilingual') . '</p></div></div>';
    }

    echo '<div class="ypm-metrics ypm-metrics--4">';
    Admin::render_metric(__('Detected sources', 'yablonsky-multilingual'), (string) $detected_count, __('Of 3 supported', 'yablonsky-multilingual'), 'neutral');
    Admin::render_metric(__('Fresh dry runs', 'yablonsky-multilingual'), (string) $ready_dryruns, __('Eligible for import', 'yablonsky-multilingual'), $ready_dryruns > 0 ? 'attention' : 'neutral');
    Admin::render_metric(__('Source data', 'yablonsky-multilingual'), __('Preserved', 'yablonsky-multilingual'), __('Stays enabled until the administrator changes it', 'yablonsky-multilingual'), 'pass');
    Admin::render_metric(__('Current job', 'yablonsky-multilingual'), $job_status, !empty($job['source']) ? strtoupper((string) $job['source']) : __('Migration ready to configure', 'yablonsky-multilingual'), $job_state);
    echo '</div>';

    echo '<div class="ypm-safety-strip ypm-safety-strip--migration"><span>' . esc_html__('Dry run before import', 'yablonsky-multilingual') . '</span><span>' . esc_html__('Source remains untouched', 'yablonsky-multilingual') . '</span><span>' . esc_html__('Resumable batches', 'yablonsky-multilingual') . '</span><span>' . esc_html__('Evidence export', 'yablonsky-multilingual') . '</span></div>';

    echo '<section class="ypm-card ypm-card--standalone ypm-migration-flow"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Workflow', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Migration writes after explicit administrator approval', 'yablonsky-multilingual') . '</h2></div></div><div class="ypm-flow-steps">';
    foreach ([
      ['1', __('Detect', 'yablonsky-multilingual'), __('Find supported source data', 'yablonsky-multilingual')],
      ['2', __('Dry run', 'yablonsky-multilingual'), __('Read and classify only', 'yablonsky-multilingual')],
      ['3', __('Review', 'yablonsky-multilingual'), __('Check conflicts and evidence', 'yablonsky-multilingual')],
      ['4', __('Import', 'yablonsky-multilingual'), __('Explicit bounded write', 'yablonsky-multilingual')],
    ] as $step) {
      echo '<div class="ypm-flow-step"><strong>' . esc_html($step[0]) . '</strong><span><b>' . esc_html($step[1]) . '</b><small>' . esc_html($step[2]) . '</small></span></div>';
    }
    echo '</div></section>';

    if (!empty($job)) self::render_job($job);

    echo '<div class="ypm-source-grid">';
    foreach ($source_labels as $source => $label) {
      $info = $detected[$source] ?? false;
      $fresh = !empty($dryruns[$source]) && ($dryruns[$source]['signature'] ?? '') === self::source_signature($source);
      $stats = $fresh && is_array($dryruns[$source]['stats'] ?? null) ? $dryruns[$source]['stats'] : [];
      echo '<section class="ypm-card ypm-source-card ' . esc_attr($info ? 'is-detected' : 'is-absent') . '"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Source', 'yablonsky-multilingual') . '</span><h2>' . esc_html($label) . '</h2></div>';
      echo wp_kses_post(Admin::status_badge($info ? 'pass' : 'neutral', $info ? __('Detected', 'yablonsky-multilingual') : __('Detection pending', 'yablonsky-multilingual')));
      echo '</div>';
      echo '<p class="ypm-card__copy">' . esc_html($info ? (string) $info['summary'] : __('Source discovery is ready; supported data appears here when detected.', 'yablonsky-multilingual')) . '</p>';

      if ($fresh) {
        echo '<div class="ypm-dryrun-summary"><span><strong>' . esc_html((string) (int) ($stats['ready'] ?? 0)) . '</strong><small>' . esc_html__('Ready', 'yablonsky-multilingual') . '</small></span><span><strong>' . esc_html((string) (int) ($stats['conflict'] ?? 0)) . '</strong><small>' . esc_html__('Conflicts', 'yablonsky-multilingual') . '</small></span><span><strong>' . esc_html((string) (int) ($stats['skipped'] ?? 0)) . '</strong><small>' . esc_html__('Skipped', 'yablonsky-multilingual') . '</small></span></div>';
      }

      if ($info) {
        echo '<div class="ypm-source-actions"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ypm_migration_start_' . $source, 'ypm_migration_nonce');
        echo '<input type="hidden" name="action" value="ypm_migration_start"><input type="hidden" name="source" value="' . esc_attr($source) . '"><button class="button ' . esc_attr($fresh ? '' : 'button-primary') . '"' . disabled($job_running, true, false) . '>' . esc_html($fresh ? __('Run dry run again', 'yablonsky-multilingual') : __('Run dry run', 'yablonsky-multilingual')) . '</button></form>';
        if ($fresh) {
          echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
          wp_nonce_field('ypm_migration_import_' . $source, 'ypm_migration_nonce');
          echo '<input type="hidden" name="action" value="ypm_migration_import"><input type="hidden" name="source" value="' . esc_attr($source) . '"><button class="button button-primary" data-ypm-confirm="import"' . disabled($job_running, true, false) . '>' . esc_html__('Start import', 'yablonsky-multilingual') . '</button></form>';
          $export = wp_nonce_url(admin_url('admin-post.php?action=ypm_migration_export&source=' . rawurlencode($source)), 'ypm_migration_export_' . $source);
          echo '<a class="button" href="' . esc_url($export) . '">' . esc_html__('Evidence JSON', 'yablonsky-multilingual') . '</a>';
        }
        if ($job_running) echo '<span class="description">' . esc_html__('Current job must finish first.', 'yablonsky-multilingual') . '</span>';
        echo '</div>';
      }
      echo '</section>';
    }
    echo '</div>';
    self::render_legacy_group_migration();
    echo '</div>';
  }

  /**
   * Manual legacy-family repair. Preview is a pure read; each write requires
   * an administrator POST with a group-scoped nonce. No cron/polling or auto run.
   */
  private static function render_legacy_group_migration(): void {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only preview input; writes require a separately verified group-specific POST nonce.
    $raw = isset($_GET['ymulti_legacy_group']) && is_string($_GET['ymulti_legacy_group'])
      ? sanitize_text_field(wp_unslash($_GET['ymulti_legacy_group'])) : '';
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
    $group = self::valid_legacy_group($raw);
    $lock_time = (int) get_option(self::LEGACY_LOCK_OPT, 0);
    $preview = $group !== '' ? Translations::legacy_group_migration_preview($group) : null;
    echo '<section class="ypm-card ypm-card--standalone" aria-label="' . esc_attr__('Legacy', 'yablonsky-multilingual') . '">';
    echo '<div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Legacy', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Translation family', 'yablonsky-multilingual') . '</h2></div></div>';
    echo '<p class="ypm-card__copy">' . esc_html__('Migration writes after explicit administrator approval', 'yablonsky-multilingual') . '</p>';
    echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="ypm-source-actions">';
    echo '<input type="hidden" name="page" value="ypm-migration">';
    echo '<label class="screen-reader-text" for="ymulti-legacy-group">' . esc_html__('Translation family', 'yablonsky-multilingual') . '</label>';
    echo '<input id="ymulti-legacy-group" name="ymulti_legacy_group" class="regular-text" required maxlength="191" value="' . esc_attr($group) . '" placeholder="' . esc_attr__('Translation family', 'yablonsky-multilingual') . '">';
    echo '<button class="button">' . esc_html__('Review', 'yablonsky-multilingual') . '</button></form>';
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display status, restricted to an explicit allowlist.
    $previous = isset($_GET['ymulti_legacy_result']) && is_string($_GET['ymulti_legacy_result'])
      ? sanitize_key(wp_unslash($_GET['ymulti_legacy_result'])) : '';
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
    if (in_array($previous, ['busy', 'failed', 'complete', 'progress'], true)) {
      $label = $previous === 'complete' ? __('Complete', 'yablonsky-multilingual')
        : ($previous === 'progress' ? __('Ready', 'yablonsky-multilingual')
        : __('Review required', 'yablonsky-multilingual'));
      echo '<p class="ypm-job-note" role="status">' . esc_html($label) . '</p>';
    }
    if ($preview !== null) {
      echo '<div class="ypm-dryrun-summary" role="status">';
      echo '<span><strong>' . esc_html((string) $preview['page']) . '</strong><small>' . esc_html__('Processed', 'yablonsky-multilingual') . '</small></span>';
      echo '<span><strong>' . esc_html((string) $preview['ready']) . '</strong><small>' . esc_html__('Ready', 'yablonsky-multilingual') . '</small></span>';
      echo '<span><strong>' . esc_html($preview['complete'] ? __('Complete', 'yablonsky-multilingual') : ($preview['valid'] ? __('Review', 'yablonsky-multilingual') : __('Review required', 'yablonsky-multilingual'))) . '</strong><small>' . esc_html__('Status', 'yablonsky-multilingual') . '</small></span>';
      echo '<span><strong>' . esc_html((string) (int) ($preview['conflicts'] ?? 0)) . '</strong><small>' . esc_html__('Conflicts', 'yablonsky-multilingual') . '</small></span>';
      echo '</div>';
      if ($preview['valid'] && !$preview['complete'] && empty($preview['conflicts']) && !$lock_time && !self::job_is_running()) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ypm-source-actions">';
        wp_nonce_field('ypm_legacy_group_batch_' . $group, 'ypm_legacy_nonce');
        echo '<input type="hidden" name="action" value="ypm_legacy_group_batch">';
        echo '<input type="hidden" name="group" value="' . esc_attr($group) . '">';
        echo '<button type="submit" class="button button-primary" data-ypm-confirm="import">' . esc_html__('Run next batch now', 'yablonsky-multilingual') . '</button>';
        echo '</form>';
      }
    }
    if ($lock_time > 0 && $lock_time <= time() - 900) {
      // Stale lock recovery also requires an explicit administrator POST.
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ypm-source-actions">';
      wp_nonce_field('ypm_legacy_lock_reset', 'ypm_legacy_nonce');
      echo '<input type="hidden" name="action" value="ypm_legacy_lock_reset">';
      echo '<button type="submit" class="button">' . esc_html__('Reset', 'yablonsky-multilingual') . '</button></form>';
    }
    echo '</section>';
  }

  /** Reject ambiguous post input rather than silently migrating another slug. */
  private static function valid_legacy_group($raw): string {
    if (!is_string($raw) || $raw === '' || strlen($raw) > 191) return '';
    $group = sanitize_title($raw);
    return $group !== '' && $group === $raw ? $group : '';
  }

  public static function handle_legacy_group_batch(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    $group = self::valid_legacy_group(isset($_POST['group']) ? sanitize_text_field(wp_unslash($_POST['group'])) : '');
    if ($group === '') wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_legacy_group_batch_' . $group, 'ypm_legacy_nonce');
    $status = 'busy';
    // A stored per-blog option has a unique name in wp_options. add_option()
    // is an atomic DB-backed claim even without a persistent object cache.
    if (!self::job_is_running() && add_option(self::LEGACY_LOCK_OPT, time(), '', false)) {
      try {
        $result = Translations::materialize_legacy_group_batch($group);
        $status = empty($result['valid']) ? 'failed' : (!empty($result['complete']) ? 'complete' : 'progress');
      } finally {
        delete_option(self::LEGACY_LOCK_OPT);
      }
    }
    $url = add_query_arg(['page' => 'ypm-migration', 'ymulti_legacy_group' => $group, 'ymulti_legacy_result' => $status], admin_url('admin.php'));
    wp_safe_redirect($url);
    exit;
  }

  public static function handle_legacy_lock_reset(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_legacy_lock_reset', 'ypm_legacy_nonce');
    $lock_time = (int) get_option(self::LEGACY_LOCK_OPT, 0);
    if ($lock_time > 0 && $lock_time <= time() - 900) delete_option(self::LEGACY_LOCK_OPT);
    wp_safe_redirect(admin_url('admin.php?page=ypm-migration'));
    exit;
  }

  private static function render_job(array $job): void {
    $stats = is_array($job['stats'] ?? null) ? $job['stats'] : [];
    $status = (string) ($job['status'] ?? '');
    $mode = (string) ($job['mode'] ?? 'dry-run');
    $processed = (int) ($stats['processed'] ?? 0);
    $outcome_total = (int) ($stats['ready'] ?? 0) + (int) ($stats['imported'] ?? 0) + (int) ($stats['conflict'] ?? 0) + (int) ($stats['skipped'] ?? 0);
    echo '<section id="ypm-migration-job" class="ypm-card ypm-card--standalone ypm-current-job" data-status="' . esc_attr($status) . '"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Current migration job', 'yablonsky-multilingual') . '</span><h2>' . esc_html(strtoupper((string) ($job['source'] ?? '')) . ' · ' . $mode) . '</h2></div>' . wp_kses_post(Admin::status_badge($status === 'complete' ? 'pass' : ($status === 'failed' ? 'fail' : 'running'), strtoupper($status ?: 'RUNNING'))) . '</div>';
    echo '<div class="ypm-job-stats"><span><strong data-ypm-job-stat="processed">' . esc_html((string) $processed) . '</strong><small>' . esc_html__('Processed', 'yablonsky-multilingual') . '</small></span><span><strong data-ypm-job-stat="ready">' . esc_html((string) (int) ($stats['ready'] ?? 0)) . '</strong><small>' . esc_html__('Ready', 'yablonsky-multilingual') . '</small></span><span><strong data-ypm-job-stat="imported">' . esc_html((string) (int) ($stats['imported'] ?? 0)) . '</strong><small>' . esc_html__('Imported', 'yablonsky-multilingual') . '</small></span><span><strong data-ypm-job-stat="conflict">' . esc_html((string) (int) ($stats['conflict'] ?? 0)) . '</strong><small>' . esc_html__('Conflicts', 'yablonsky-multilingual') . '</small></span><span><strong data-ypm-job-stat="skipped">' . esc_html((string) (int) ($stats['skipped'] ?? 0)) . '</strong><small>' . esc_html__('Skipped', 'yablonsky-multilingual') . '</small></span></div>';
    if ($outcome_total > 0) echo '<p class="ypm-job-note">' . esc_html__('Each batch is bounded, and the job continues safely through short admin/background steps.', 'yablonsky-multilingual') . '</p>';
    if ($status === 'running') {
      $url = wp_nonce_url(admin_url('admin-post.php?action=ypm_migration_tick'), 'ypm_migration_tick');
      echo '<div class="ypm-source-actions ypm-job-controls"><a class="button" href="' . esc_url($url) . '">' . esc_html__('Run next batch now', 'yablonsky-multilingual') . '</a><span id="ypm-migration-live-state" class="description" role="status" aria-live="polite">' . esc_html__('This page continues bounded batches automatically; WP-Cron remains the fallback.', 'yablonsky-multilingual') . '</span></div>';
    }
    echo '</section>';
  }

  /** @return array<string,array<string,mixed>|false> */
  public static function detect_sources(): array {
    global $wpdb;
    $wpml_table = $wpdb->prefix . 'icl_translations';
    $fml_table = $wpdb->prefix . 'fml_post_translations';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpml_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpml_table)) === $wpml_table;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $fml_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $fml_table)) === $fml_table;
    $pll = function_exists('pll_get_post_language') && function_exists('pll_get_post_translations');

    return [
      'wpml' => $wpml_exists ? ['summary' => __('WPML translation relation table detected.', 'yablonsky-multilingual')] : false,
      'polylang' => $pll ? ['summary' => __('Polylang API detected; posts and terms can be normalized through native API data.', 'yablonsky-multilingual')] : false,
      'fitoterra' => $fml_exists ? ['summary' => __('Fitoterra translation table detected. Existing relation data can be normalized into YMulti families.', 'yablonsky-multilingual')] : false,
    ];
  }

  public static function handle_start(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    $source = isset($_POST['source']) ? sanitize_key(wp_unslash($_POST['source'])) : '';
    check_admin_referer('ypm_migration_start_' . $source, 'ypm_migration_nonce');
    if (self::job_is_running()) {
      wp_safe_redirect(admin_url('admin.php?page=ypm-migration&busy=1'));
      exit;
    }
    self::start_job($source, 'dry-run');
    wp_safe_redirect(admin_url('admin.php?page=ypm-migration'));
    exit;
  }

  public static function handle_import(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    $source = isset($_POST['source']) ? sanitize_key(wp_unslash($_POST['source'])) : '';
    check_admin_referer('ypm_migration_import_' . $source, 'ypm_migration_nonce');
    if (self::job_is_running()) {
      wp_safe_redirect(admin_url('admin.php?page=ypm-migration&busy=1'));
      exit;
    }
    $dryruns = get_option(self::DRYRUN_OPT, []);
    if (!is_array($dryruns) || empty($dryruns[$source]) || ($dryruns[$source]['signature'] ?? '') !== self::source_signature($source)) {
      wp_die(esc_html__('Run a fresh dry run before importing this source.', 'yablonsky-multilingual'));
    }
    if (class_exists(SourceLanguageBridge::class, false)) SourceLanguageBridge::sync_source($source);
    self::start_job($source, 'import');
    wp_safe_redirect(admin_url('admin.php?page=ypm-migration'));
    exit;
  }

  public static function handle_export(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    $source_raw = filter_input(INPUT_GET, 'source', FILTER_UNSAFE_RAW);
    $source = is_string($source_raw) ? sanitize_key($source_raw) : '';
    check_admin_referer('ypm_migration_export_' . $source);
    $dryruns = get_option(self::DRYRUN_OPT, []);
    $record = is_array($dryruns) && isset($dryruns[$source]) && is_array($dryruns[$source]) ? $dryruns[$source] : [];
    if (!$record) wp_die(esc_html__('Complete a dry run to create source evidence before import.', 'yablonsky-multilingual'));
    $payload = [
      'product' => 'Yablonsky Multilingual',
      'version' => defined('YPM_VERSION') ? YPM_VERSION : '',
      'source' => $source,
      'generated_at' => gmdate('c'),
      'source_signature' => (string) ($record['signature'] ?? ''),
      'stats' => $record['stats'] ?? [],
      'source_evidence' => $record['evidence'] ?? [],
      'notes' => [
        __('Dry run is read-only.', 'yablonsky-multilingual'),
        __('Existing YMulti identities remain authoritative when a conflict is detected.', 'yablonsky-multilingual'),
        __('Source plugins remain under administrator control throughout migration.', 'yablonsky-multilingual'),
      ],
    ];
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="ymulti-migration-' . rawurlencode($source) . '-' . gmdate('Ymd-His') . '.json"');
    echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download response.
    exit;
  }

  public static function handle_manual_tick(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_migration_tick');
    self::process_job();
    wp_safe_redirect(admin_url('admin.php?page=ypm-migration'));
    exit;
  }

  public static function ajax_tick(): void {
    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Forbidden', 'yablonsky-multilingual')], 403);
    }
    check_ajax_referer('ypm_migration_tick_ajax', 'nonce');
    self::process_job();
    $job = get_option(self::JOB_OPT, []);
    if (!is_array($job)) $job = [];
    wp_send_json_success(['job' => $job]);
  }

  public static function cron_tick(): void {
    self::process_job();
  }

  private static function job_is_running(): bool {
    $job = get_option(self::JOB_OPT, []);
    return is_array($job) && ($job['status'] ?? '') === 'running';
  }

  private static function start_job(string $source, string $mode): void {
    $detected = self::detect_sources();
    if (!in_array($source, ['wpml', 'polylang', 'fitoterra'], true) || empty($detected[$source])) return;
    $job = [
      'source' => $source,
      'mode' => $mode,
      'status' => 'running',
      'phase' => 'posts',
      'cursor' => 0,
      'signature' => self::source_signature($source),
      'started_at' => time(),
      'stats' => ['processed' => 0, 'ready' => 0, 'imported' => 0, 'conflict' => 0, 'skipped' => 0],
      'evidence' => [],
    ];
    update_option(self::JOB_OPT, $job, false);
    self::schedule_tick();
  }

  private static function schedule_tick(): void {
    if (!wp_next_scheduled(self::CRON_HOOK)) wp_schedule_single_event(time() + 5, self::CRON_HOOK);
  }

  private static function process_job(): void {
    if (get_transient(self::LOCK_TRANSIENT)) return;
    set_transient(self::LOCK_TRANSIENT, 1, MINUTE_IN_SECONDS);

    try {
      $job = get_option(self::JOB_OPT, []);
      if (!is_array($job) || ($job['status'] ?? '') !== 'running') return;
      $source = (string) ($job['source'] ?? '');
      if (($job['signature'] ?? '') !== self::source_signature($source)) {
        $job['status'] = 'failed';
        $job['evidence'][] = __('Source data changed since the job started.', 'yablonsky-multilingual');
        $job['updated_at'] = time();
        update_option(self::JOB_OPT, $job, false);
        return;
      }

      if ($source === 'wpml') $more = self::process_wpml($job);
      elseif ($source === 'polylang') $more = self::process_polylang($job);
      elseif ($source === 'fitoterra') $more = self::process_fitoterra($job);
      else $more = false;

      $job['updated_at'] = time();
      if (!$more) {
        $job['status'] = 'complete';
        $job['finished_at'] = time();
        if (($job['mode'] ?? '') === 'dry-run') {
          $all = get_option(self::DRYRUN_OPT, []);
          if (!is_array($all)) $all = [];
          $all[$source] = ['signature' => (string) $job['signature'], 'stats' => $job['stats'], 'evidence' => $job['evidence'] ?? [], 'finished_at' => time()];
          update_option(self::DRYRUN_OPT, $all, false);
        }
      }
      update_option(self::JOB_OPT, $job, false);
      if ($more) self::schedule_tick();
    } finally {
      delete_transient(self::LOCK_TRANSIENT);
    }
  }

  /** @param array<string,mixed> $job */
  private static function process_wpml(array &$job): bool {
    global $wpdb;
    $table = $wpdb->prefix . 'icl_translations';
    $cursor = (int) ($job['cursor'] ?? 0);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only bounded migration read.
    $rows = $wpdb->get_results($wpdb->prepare(
      'SELECT translation_id, element_type, element_id, trid, language_code FROM ' . esc_sql($table) . ' WHERE translation_id > %d ORDER BY translation_id ASC LIMIT %d',
      $cursor,
      self::BATCH
    ));
    if (!$rows) return false;

    $tax_ids = [];
    foreach ($rows as $row) if (isset($row->element_type, $row->element_id) && strpos((string) $row->element_type, 'tax_') === 0) $tax_ids[] = (int) $row->element_id;
    $tax_map = self::wpml_term_taxonomy_map($tax_ids);

    foreach ($rows as $row) {
      $job['cursor'] = max((int) $job['cursor'], (int) ($row->translation_id ?? 0));
      $job['stats']['processed']++;
      $language = self::normalize_language((string) ($row->language_code ?? ''));
      if ($language === '') { $job['stats']['skipped']++; continue; }
      $group = sanitize_title('wpml-' . (string) ($row->trid ?? ''));
      $type = (string) ($row->element_type ?? '');
      $element_id = (int) ($row->element_id ?? 0);
      if ($group === '' || $element_id <= 0) { $job['stats']['skipped']++; continue; }

      if (strpos($type, 'post_') === 0) {
        self::apply_post_identity($element_id, $language, $group, $job);
      } elseif (strpos($type, 'tax_') === 0 && isset($tax_map[$element_id])) {
        self::apply_term_identity((int) $tax_map[$element_id]['term_id'], (string) $tax_map[$element_id]['taxonomy'], $language, $group, $job);
      } else {
        $job['stats']['skipped']++;
      }
    }
    return count($rows) >= self::BATCH;
  }

  /** @param int[] $ids @return array<int,array{term_id:int,taxonomy:string}> */
  private static function wpml_term_taxonomy_map(array $ids): array {
    global $wpdb;
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only bounded migration lookup.
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT term_taxonomy_id, term_id, taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN (" . implode(',', array_fill(0, count($ids), '%d')) . ')',
      $ids
    ));
    $out = [];
    if (is_array($rows)) foreach ($rows as $row) $out[(int) $row->term_taxonomy_id] = ['term_id' => (int) $row->term_id, 'taxonomy' => (string) $row->taxonomy];
    return $out;
  }

  /** @param array<string,mixed> $job */
  private static function process_polylang(array &$job): bool {
    if (($job['phase'] ?? 'posts') === 'posts') {
      global $wpdb;
      $types = Languages::supported_post_types();
      $types = array_values(array_unique(array_filter(array_map('sanitize_key', $types))));
      if (!$types) { $job['phase'] = 'terms'; $job['cursor'] = 0; return true; }
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only bounded migration lookup.
      $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN (" . implode(',', array_fill(0, count($types), '%s')) . ") AND post_status <> 'trash' ORDER BY ID ASC LIMIT %d",
        array_merge([(int) ($job['cursor'] ?? 0)], $types, [self::BATCH])
      ));
      if (!$ids) { $job['phase'] = 'terms'; $job['cursor'] = 0; return true; }
      foreach ($ids as $id_raw) {
        $id = (int) $id_raw;
        $job['cursor'] = max((int) $job['cursor'], $id);
        $job['stats']['processed']++;
        $lang = function_exists('pll_get_post_language') ? self::normalize_language((string) pll_get_post_language($id, 'slug')) : '';
        $family = function_exists('pll_get_post_translations') ? pll_get_post_translations($id) : [];
        if ($lang === '' || !is_array($family) || !$family) { $job['stats']['skipped']++; continue; }
        $family_ids = array_values(array_filter(array_map('intval', $family)));
        $group = sanitize_title('pll-post-' . (string) min($family_ids ?: [$id]));
        self::apply_post_identity($id, $lang, $group, $job);
      }
      return count($ids) >= self::BATCH;
    }

    global $wpdb;
    $taxonomies = Languages::supported_taxonomies();
    $taxonomies = array_values(array_unique(array_filter(array_map('sanitize_key', $taxonomies))));
    if (!$taxonomies) return false;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only bounded migration lookup.
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT t.term_id, tt.taxonomy FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE t.term_id > %d AND tt.taxonomy IN (" . implode(',', array_fill(0, count($taxonomies), '%s')) . ') ORDER BY t.term_id ASC LIMIT %d',
      array_merge([(int) ($job['cursor'] ?? 0)], $taxonomies, [self::BATCH])
    ));
    if (!$rows) return false;
    foreach ($rows as $row) {
      $term_id = (int) $row->term_id;
      $taxonomy = (string) $row->taxonomy;
      $job['cursor'] = max((int) $job['cursor'], $term_id);
      $job['stats']['processed']++;
      $lang = function_exists('pll_get_term_language') ? self::normalize_language((string) pll_get_term_language($term_id, 'slug')) : '';
      $family = function_exists('pll_get_term_translations') ? pll_get_term_translations($term_id) : [];
      if ($lang === '' || !is_array($family) || !$family) { $job['stats']['skipped']++; continue; }
      $family_ids = array_values(array_filter(array_map('intval', $family)));
      $group = sanitize_title('pll-term-' . (string) min($family_ids ?: [$term_id]));
      self::apply_term_identity($term_id, $taxonomy, $lang, $group, $job);
    }
    return count($rows) >= self::BATCH;
  }

  /** @param array<string,mixed> $job */
  private static function process_fitoterra(array &$job): bool {
    global $wpdb;
    $phase = (string) ($job['phase'] ?? 'posts');

    if ($phase === 'posts') {
      $table = $wpdb->prefix . 'fml_post_translations';
      $cursor = (int) ($job['cursor'] ?? 0);
      // Fitoterra can contain more than one row per post ID, so use a stable
      // bounded offset under the source-signature guard instead of an ID cursor.
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only bounded migration read.
      $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT post_id, lang, group_id FROM ' . esc_sql($table) . ' ORDER BY post_id ASC, lang ASC LIMIT %d OFFSET %d',
        self::BATCH,
        $cursor
      ));
      if (!$rows) {
        $job['phase'] = 'orders';
        $job['cursor'] = 0;
        return true;
      }
      foreach ($rows as $row) {
        $post_id = (int) ($row->post_id ?? 0);
        $job['stats']['processed']++;
        $lang = self::normalize_language((string) ($row->lang ?? ''));
        $group = sanitize_title('fml-' . (string) ($row->group_id ?? ''));
        if ($post_id <= 0 || $lang === '' || $group === '') { $job['stats']['skipped']++; continue; }
        self::apply_post_identity($post_id, $lang, $group, $job);
      }
      $job['cursor'] = $cursor + count($rows);
      return true;
    }

    if ($phase === 'orders') {
      $cursor = (int) ($job['cursor'] ?? 0);
      // Historical Fitoterra order language was stored in post meta. This read
      // remains bounded and import writes through WooCommerce order CRUD.
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
      $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_id > %d AND meta_key = %s ORDER BY meta_id ASC LIMIT %d",
        $cursor,
        '_fml_order_lang',
        self::BATCH
      ));
      if (!$rows) {
        $job['phase'] = 'preserved';
        $job['cursor'] = 0;
        $strings_table = $wpdb->prefix . 'fml_strings';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $strings_table)) === $strings_table;
        if ($exists) {
          // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only source evidence.
          $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . esc_sql($strings_table));
          $job['evidence'][] = sprintf(
            /* translators: %d: number of preserved Fitoterra source string rows. */
            __('Fitoterra fml_strings rows preserved in source storage: %d. They are not guessed into a text domain during automatic import.', 'yablonsky-multilingual'),
            $count
          );
        }
        return false;
      }
      foreach ($rows as $row) {
        $job['cursor'] = max((int) $job['cursor'], (int) ($row->meta_id ?? 0));
        $job['stats']['processed']++;
        $lang = self::normalize_language((string) ($row->meta_value ?? ''));
        if ($lang === '' || !function_exists('wc_get_order')) { $job['stats']['skipped']++; continue; }
        $order = wc_get_order((int) ($row->post_id ?? 0));
        if (!$order) { $job['stats']['skipped']++; continue; }
        $existing = sanitize_text_field((string) $order->get_meta('_ypm_order_language', true));
        if ($existing !== '' && $existing !== $lang) { $job['stats']['conflict']++; continue; }
        $job['stats']['ready']++;
        if (($job['mode'] ?? '') === 'import') {
          $order->update_meta_data('_ypm_order_language', $lang);
          if (method_exists($order, 'save_meta_data')) $order->save_meta_data(); else $order->save();
          $job['stats']['imported']++;
        }
      }
      return true;
    }

    return false;
  }

  /** @param array<string,mixed> $job */
  private static function apply_post_identity(int $post_id, string $language, string $group, array &$job): void {
    if (!get_post($post_id) || !Languages::is_active($language)) { $job['stats']['skipped']++; return; }
    $existing_lang = Translations::assigned_post_language($post_id);
    $existing_group = Translations::group_id($post_id);
    if (($existing_lang !== null && $existing_lang !== $language) || ($existing_group !== '' && $existing_group !== $group)) {
      $job['stats']['conflict']++;
      return;
    }
    $job['stats']['ready']++;
    if (($job['mode'] ?? '') === 'import') {
      Translations::set_post_language($post_id, $language);
      Translations::set_group_id($post_id, $group);
      $job['stats']['imported']++;
    }
  }

  /** @param array<string,mixed> $job */
  private static function apply_term_identity(int $term_id, string $taxonomy, string $language, string $group, array &$job): void {
    if (!TermTranslations::is_supported_taxonomy($taxonomy) || !get_term($term_id, $taxonomy) || !Languages::is_active($language)) { $job['stats']['skipped']++; return; }
    $existing_lang = TermTranslations::assigned_term_language($term_id);
    $existing_group = TermTranslations::group_id($term_id);
    if (($existing_lang !== null && $existing_lang !== $language) || ($existing_group !== '' && $existing_group !== $group)) {
      $job['stats']['conflict']++;
      return;
    }
    $job['stats']['ready']++;
    if (($job['mode'] ?? '') === 'import') {
      TermTranslations::set_term_language($term_id, $language);
      TermTranslations::set_group_id($term_id, $group);
      $job['stats']['imported']++;
    }
  }

  private static function normalize_language(string $raw): string {
    $raw = str_replace('_', '-', trim($raw));
    if ($raw === '') return '';
    foreach (Languages::active_codes() as $code) {
      if (strcasecmp($code, $raw) === 0 || strcasecmp(Languages::code_to_slug($code), strtolower($raw)) === 0) return $code;
    }
    $family = strtolower((string) strtok($raw, '-'));
    foreach (Languages::active_codes() as $code) if (Languages::family_code($code) === $family) return $code;
    return '';
  }

  private static function source_signature(string $source): string {
    global $wpdb;
    if ($source === 'wpml') {
      $table = $wpdb->prefix . 'icl_translations';
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only source fingerprint.
      $row = $wpdb->get_row('SELECT COUNT(*) AS row_count, MAX(translation_id) AS max_id FROM ' . esc_sql($table));
      return sha1('wpml|' . (int) ($row->row_count ?? 0) . '|' . (int) ($row->max_id ?? 0));
    }
    if ($source === 'fitoterra') {
      $table = $wpdb->prefix . 'fml_post_translations';
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only source fingerprint.
      $row = $wpdb->get_row('SELECT COUNT(*) AS row_count, MAX(post_id) AS max_id FROM ' . esc_sql($table));
      return sha1('fitoterra|' . (int) ($row->row_count ?? 0) . '|' . (int) ($row->max_id ?? 0));
    }
    if ($source === 'polylang') {
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only source fingerprint.
      $max_post = (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}");
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only source fingerprint.
      $max_term = (int) $wpdb->get_var("SELECT MAX(term_id) FROM {$wpdb->terms}");
      return sha1('polylang|' . (defined('POLYLANG_VERSION') ? (string) POLYLANG_VERSION : 'api') . '|' . $max_post . '|' . $max_term);
    }
    return '';
  }
}
