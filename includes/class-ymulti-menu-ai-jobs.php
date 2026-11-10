<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Durable, review-first AI drafts for native WordPress menu labels. */
final class MenuAIJobs {
  public const OPT_JOB = 'ymulti_menu_ai_job_v1';
  private const HOOK = 'yablonsky_multilingual_ai_menu_job_tick';
  private const LOCK = 'ymulti_menu_ai_job_lock_v1';
  private const MAX_TASKS = 5000;

  public static function init(): void {
    add_action(self::HOOK, [__CLASS__, 'cron_tick']);
    if (is_admin()) {
      add_action('admin_post_ymulti_menu_ai_job_start', [__CLASS__, 'handle_start']);
      add_action('admin_post_ymulti_menu_ai_job_tick', [__CLASS__, 'handle_tick']);
      add_action('admin_post_ymulti_menu_ai_job_cancel', [__CLASS__, 'handle_cancel']);
    }
  }

  /** @return array<string,mixed> */
  public static function job(): array {
    $raw = get_option(self::OPT_JOB, []);
    return is_array($raw) ? $raw : [];
  }

  /** @param array<int,\WP_Post> $visible_items */
  public static function render_panel(int $menu_id, string $source_locale, string $target_locale, string $search, array $visible_items, int $scope_total): void {
    if ($menu_id < 0 || !Languages::is_active($target_locale)) return;
    $job = self::job();
    $same = (int)($job['menu_id'] ?? 0) === $menu_id && (string)($job['source_locale'] ?? '') === $source_locale && (string)($job['target_locale'] ?? '') === $target_locale;
    $status = $same ? sanitize_key((string)($job['status'] ?? '')) : '';
    $stats = $same && isset($job['stats']) && is_array($job['stats']) ? $job['stats'] : [];
    $setup = AITranslation::text_setup_status();
    $ready = !empty($setup['ready']);

    echo '<div class="ymulti-bulk-launcher ymulti-bulk-launcher--menus" data-ymulti-bulk-scope="menu">';
    echo '<div class="ymulti-bulk-launcher__copy"><strong>' . esc_html__('AI menu label drafts', 'yablonsky-multilingual') . '</strong><span>' . esc_html__('Prepare review-only label suggestions for selected rows, this page or the complete filtered menu. You choose Use AI draft and then Save label to publish the reviewed menu text.', 'yablonsky-multilingual') . '</span></div>';

    if ($same && $job) {
      echo '<div class="ymulti-menu-ai-stats">';
      echo '<span>' . esc_html__('Status', 'yablonsky-multilingual') . ': <strong>' . esc_html($status !== '' ? ucfirst($status) : '—') . '</strong></span>';
      echo '<span>' . esc_html__('Prepared', 'yablonsky-multilingual') . ': <strong>' . esc_html((string)(int)($stats['prepared'] ?? 0)) . '</strong></span>';
      echo '<span>' . esc_html__('Skipped', 'yablonsky-multilingual') . ': <strong>' . esc_html((string)(int)($stats['skipped'] ?? 0)) . '</strong></span>';
      echo '<span>' . esc_html__('Failed', 'yablonsky-multilingual') . ': <strong>' . esc_html((string)(int)($stats['failed'] ?? 0)) . '</strong></span></div>';
    }

    if ($status === 'running') {
      echo '<div class="ypm-row-actions"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('ymulti_menu_ai_job_tick', 'ymulti_menu_ai_job_nonce');
      echo '<input type="hidden" name="action" value="ymulti_menu_ai_job_tick"><button class="button">' . esc_html__('Run next task now', 'yablonsky-multilingual') . '</button></form>';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('ymulti_menu_ai_job_cancel', 'ymulti_menu_ai_job_nonce');
      echo '<input type="hidden" name="action" value="ymulti_menu_ai_job_cancel"><button class="button">' . esc_html__('Cancel queue', 'yablonsky-multilingual') . '</button></form></div></div>';
      return;
    }

    if (!$visible_items) {
      echo '</div>'; return;
    }

    $available_attr = $ready ? '1' : '0';
    $unavailable_message = !$ready ? sanitize_text_field((string) ($setup['message'] ?? '')) : '';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ymulti-bulk-form" data-ymulti-bulk-form="menu" data-ymulti-bulk-status="1" data-ymulti-bulk-available="' . esc_attr($available_attr) . '" data-ymulti-bulk-unavailable-message="' . esc_attr($unavailable_message) . '">';
    wp_nonce_field('ymulti_menu_ai_job_start', 'ymulti_menu_ai_job_nonce');
    echo '<input type="hidden" name="action" value="ymulti_menu_ai_job_start"><input type="hidden" name="menu_id" value="' . esc_attr((string)$menu_id) . '"><input type="hidden" name="source_locale" value="' . esc_attr($source_locale) . '"><input type="hidden" name="target_locale" value="' . esc_attr($target_locale) . '"><input type="hidden" name="search" value="' . esc_attr($search) . '"><div data-ymulti-selected-inputs></div>';
    echo '<div class="ymulti-bulk-target-static"><span>' . esc_html__('Translate to', 'yablonsky-multilingual') . '</span><strong>' . esc_html(Languages::display_name($target_locale) . ' · ' . Languages::display_code($target_locale)) . '</strong></div>';
    foreach ($visible_items as $item) if ($item instanceof \WP_Post) echo '<input type="hidden" name="page_ids[]" value="' . esc_attr((string)$item->ID) . '">';
    echo '<div class="ymulti-bulk-action-grid">';
    echo '<div class="ymulti-bulk-action-card is-selected"><strong>' . esc_html__('Selected menu items', 'yablonsky-multilingual') . '</strong><span>' . esc_html__('Prepare review-only label drafts for checked rows.', 'yablonsky-multilingual') . '</span><button class="button button-primary" name="mode" value="selected" data-ymulti-bulk-selected disabled>' . esc_html__('Translate selected', 'yablonsky-multilingual') . ' <span data-ymulti-selected-count>(0)</span></button></div>';
    echo '<div class="ymulti-bulk-action-card"><strong>' . esc_html__('Current page', 'yablonsky-multilingual') . '</strong><span>' . esc_html(sprintf(
      /* translators: %d: visible menu item count. */
      __('Prepare label drafts for all %d visible menu items.', 'yablonsky-multilingual'), count($visible_items)
    )) . '</span><button class="button" name="mode" value="page"';
    if (!$ready) echo ' disabled aria-disabled="true"';
    echo '>' . esc_html(sprintf(
      /* translators: %d: visible menu item count. */
      __('Translate this page (%d)', 'yablonsky-multilingual'), count($visible_items)
    )) . '</button></div>';
    echo '<div class="ymulti-bulk-action-card is-all"><strong>' . esc_html__('Entire filtered scope', 'yablonsky-multilingual') . '</strong><span>' . esc_html(sprintf(
      /* translators: %d: total matching menu items. */
      __('Queue all %d menu items matching the current filters.', 'yablonsky-multilingual'), max(0, $scope_total)
    )) . '</span><label class="ymulti-bulk-confirm"><input type="checkbox" name="confirm_all" value="1" data-ymulti-bulk-confirm-all';
    if (!$ready) echo ' disabled aria-disabled="true"';
    echo '> <span>' . esc_html__('I understand this can consume AI credits.', 'yablonsky-multilingual') . '</span></label><button class="button" name="mode" value="all" data-ymulti-bulk-all-action disabled>' . esc_html(sprintf(
      /* translators: %d: total matching menu items. */
      __('Translate all matching (%d)', 'yablonsky-multilingual'), max(0, $scope_total)
    )) . '</button></div></div></form>';
    echo '</div>';
  }

  public static function render_single_action(int $menu_id, int $item_id, string $source_locale, string $target_locale, string $search = ''): void {
    if ($menu_id <= 0 || $item_id <= 0 || !AITranslation::ready() || !AITranslation::current_user_can_compute()) return;
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ymulti-inline-ai-form">';
    wp_nonce_field('ymulti_menu_ai_job_start', 'ymulti_menu_ai_job_nonce');
    echo '<input type="hidden" name="action" value="ymulti_menu_ai_job_start"><input type="hidden" name="mode" value="selected"><input type="hidden" name="menu_id" value="' . esc_attr((string)$menu_id) . '"><input type="hidden" name="source_locale" value="' . esc_attr($source_locale) . '"><input type="hidden" name="target_locale" value="' . esc_attr($target_locale) . '"><input type="hidden" name="search" value="' . esc_attr($search) . '"><input type="hidden" name="source_ids[]" value="' . esc_attr((string)$item_id) . '"><button class="button button-small button-primary ymulti-ai-cta">' . esc_html__('AI draft', 'yablonsky-multilingual') . '</button></form>';
  }

  public static function handle_start(): void {
    check_admin_referer('ymulti_menu_ai_job_start', 'ymulti_menu_ai_job_nonce');
    if (!current_user_can('edit_theme_options') || !AITranslation::current_user_can_compute() || !AITranslation::ready()) wp_die(esc_html__('AI menu campaign requires menu editing permission, AI compute permission and a ready text route. Review the scope and Yablonsky AI settings, then try again.', 'yablonsky-multilingual'));

    $menu_raw = filter_input(INPUT_POST, 'menu_id', FILTER_VALIDATE_INT);
    $source_raw = filter_input(INPUT_POST, 'source_locale', FILTER_UNSAFE_RAW);
    $target_raw = filter_input(INPUT_POST, 'target_locale', FILTER_UNSAFE_RAW);
    $mode_raw = filter_input(INPUT_POST, 'mode', FILTER_UNSAFE_RAW);
    $search_raw = filter_input(INPUT_POST, 'search', FILTER_UNSAFE_RAW);
    $menu_id = $menu_raw ? (int)$menu_raw : 0;
    $source_locale = is_string($source_raw) ? sanitize_text_field($source_raw) : '';
    if ($source_locale !== '' && !Languages::is_active($source_locale)) $source_locale = '';
    $target = is_string($target_raw) ? sanitize_text_field($target_raw) : '';
    $mode = is_string($mode_raw) ? sanitize_key($mode_raw) : 'selected';
    $search = is_string($search_raw) ? trim(sanitize_text_field($search_raw)) : '';
    if ($menu_id < 0 || ($menu_id > 0 && !wp_get_nav_menu_object($menu_id)) || !Languages::is_active($target)) wp_die(esc_html__('Invalid menu AI request.', 'yablonsky-multilingual'));
    if ($source_locale !== '' && $source_locale === $target) wp_die(esc_html__('Source and target languages must be different.', 'yablonsky-multilingual'));
    if (!in_array($mode, ['selected', 'page', 'all'], true)) $mode = 'selected';
    $confirm_all = (int) filter_input(INPUT_POST, 'confirm_all', FILTER_VALIDATE_INT);
    if ($mode === 'all' && $confirm_all !== 1) wp_die(esc_html__('Confirm the complete filtered-scope AI campaign before starting it.', 'yablonsky-multilingual'));

    $items = self::matching_items($menu_id, $search, $source_locale);
    $allowed = [];
    foreach ($items as $item) if ($item instanceof \WP_Post) $allowed[(int)$item->ID] = true;
    $ids = [];
    if ($mode === 'all') {
      foreach (array_keys($allowed) as $id) { $ids[] = (int)$id; if (count($ids) >= self::MAX_TASKS) break; }
    } else {
      $field = $mode === 'page' ? 'page_ids' : 'source_ids';
      $posted = filter_input(INPUT_POST, $field, FILTER_SANITIZE_NUMBER_INT, FILTER_REQUIRE_ARRAY);
      if (!is_array($posted)) $posted = [];
      foreach ($posted as $value) {
        $id = absint($value);
        if ($id > 0 && isset($allowed[$id]) && !in_array($id, $ids, true)) $ids[] = $id;
        if (count($ids) >= self::MAX_TASKS) break;
      }
    }

    $tasks = [];
    foreach ($ids as $id) {
      if (RuntimeAuthority::effective_post_language($id) === $target) continue;
      if (MenuTranslations::label($id, $target) !== '') continue;
      $suggestion = MenuTranslations::ai_suggestion($id, $target);
      if (!empty($suggestion['label'])) continue;
      $tasks[] = ['source_id' => $id];
    }

    $job = [
      'status' => $tasks ? 'running' : 'completed', 'menu_id' => $menu_id, 'source_locale' => $source_locale, 'target_locale' => $target, 'search' => $search, 'mode' => $mode,
      'tasks' => $tasks, 'cursor' => 0, 'stats' => ['prepared' => 0, 'skipped' => 0, 'failed' => 0], 'errors' => [],
      'started_by' => get_current_user_id(), 'started_at' => gmdate('c'),
    ];
    update_option(self::OPT_JOB, $job, false);
    if ($tasks) self::schedule();
    self::redirect($menu_id, $source_locale, $target, $search);
  }

  public static function handle_tick(): void {
    check_admin_referer('ymulti_menu_ai_job_tick', 'ymulti_menu_ai_job_nonce');
    if (!current_user_can('edit_theme_options') || !AITranslation::current_user_can_compute()) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    self::process_one();
    $job = self::job();
    self::redirect((int)($job['menu_id'] ?? 0), sanitize_text_field((string)($job['source_locale'] ?? '')), sanitize_text_field((string)($job['target_locale'] ?? '')), sanitize_text_field((string)($job['search'] ?? '')));
  }

  public static function handle_cancel(): void {
    check_admin_referer('ymulti_menu_ai_job_cancel', 'ymulti_menu_ai_job_nonce');
    if (!current_user_can('edit_theme_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    $job = self::job();
    $job['status'] = 'cancelled'; $job['finished_at'] = gmdate('c');
    update_option(self::OPT_JOB, $job, false); self::unschedule();
    self::redirect((int)($job['menu_id'] ?? 0), sanitize_text_field((string)($job['source_locale'] ?? '')), sanitize_text_field((string)($job['target_locale'] ?? '')), sanitize_text_field((string)($job['search'] ?? '')));
  }

  public static function cron_tick(): void { self::process_one(); }

  private static function process_one(): void {
    if (get_transient(self::LOCK)) return;
    set_transient(self::LOCK, 1, 2 * MINUTE_IN_SECONDS);
    try {
      $job = self::job();
      if ((string)($job['status'] ?? '') !== 'running') return;
      $tasks = isset($job['tasks']) && is_array($job['tasks']) ? $job['tasks'] : [];
      $cursor = max(0, (int)($job['cursor'] ?? 0));
      if (!isset($tasks[$cursor]) || !is_array($tasks[$cursor])) { self::complete($job); return; }
      $id = absint($tasks[$cursor]['source_id'] ?? 0);
      $target = sanitize_text_field((string)($job['target_locale'] ?? ''));
      $menu_id = (int)($job['menu_id'] ?? 0);
      $job['cursor'] = $cursor + 1;
      $item = $id > 0 ? get_post($id) : null;
      $source_locale = sanitize_text_field((string)($job['source_locale'] ?? ''));
      $member_ids = self::matching_item_ids($menu_id, '', $source_locale);

      if (!($item instanceof \WP_Post) || $item->post_type !== 'nav_menu_item' || !in_array($id, $member_ids, true) || !Languages::is_active($target) || RuntimeAuthority::effective_post_language($id) === $target || MenuTranslations::label($id, $target) !== '') {
        $job['stats']['skipped'] = (int)($job['stats']['skipped'] ?? 0) + 1;
      } else {
        $source = MenuTranslations::source_label($id);
        if ($source === '') {
          $job['stats']['skipped'] = (int)($job['stats']['skipped'] ?? 0) + 1;
        } else {
          $result = AITranslation::suggest_string($source, $target, 'nav_menu', 'WordPress navigation menu label');
          if (is_wp_error($result)) {
            $job['stats']['failed'] = (int)($job['stats']['failed'] ?? 0) + 1;
            $job['errors'][] = ['source_id' => $id, 'message' => sanitize_text_field($result->get_error_message()), 'at' => gmdate('c')];
            $job['errors'] = array_slice($job['errors'], -10);
          } else {
            $doc = isset($result['document']) && is_array($result['document']) ? $result['document'] : [];
            MenuTranslations::save_ai_suggestion($id, $target, [
              'label' => sanitize_text_field((string)($result['translation'] ?? '')),
              'source_hash' => hash('sha256', $source),
              'model' => sanitize_text_field((string)($doc['model'] ?? '')),
              'cost' => is_numeric($doc['cost'] ?? null) ? (float)$doc['cost'] : null,
              'prepared_at' => gmdate('c'),
            ]);
            $job['stats']['prepared'] = (int)($job['stats']['prepared'] ?? 0) + 1;
          }
        }
      }

      if ((int)$job['cursor'] >= count($tasks)) self::complete($job); else { update_option(self::OPT_JOB, $job, false); self::schedule(); }
    } finally { delete_transient(self::LOCK); }
  }

  /** @return array<int,\WP_Post> */
  private static function matching_items(int $menu_id, string $search, string $source_locale = ''): array {
    $items = [];
    $menu_names = [];
    $families = [];
    $menus = $menu_id > 0 ? [wp_get_nav_menu_object($menu_id)] : wp_get_nav_menus();
    foreach ($menus as $menu) {
      if (!$menu || empty($menu->term_id)) continue;
      $query_args = ['post_status' => 'any'];
      if ($source_locale !== '') $query_args = AdminContent::with_source_language($query_args, $source_locale);
      $rows = wp_get_nav_menu_items((int) $menu->term_id, $query_args);
      if (!is_array($rows)) continue;
      foreach ($rows as $item) {
        if (!($item instanceof \WP_Post)) continue;
        $id = (int) $item->ID;
        if ($id <= 0) continue;
        if ($source_locale !== '' && EntityContract::post_source_locale($id) !== $source_locale) continue;
        $family_key = EntityContract::post_family_key($id);
        if (isset($families[$family_key])) continue;
        $name = sanitize_text_field((string) ($menu->name ?? ''));
        if ($search !== '') {
          $needle = strtolower($search);
          $hay = strtolower(implode(' ', [(string)$item->title, (string)$item->url, (string)$item->object, (string)$item->type, $name]));
          if (strpos($hay, $needle) === false) continue;
        }
        $families[$family_key] = true;
        $menu_names[$id] = $name;
        $items[] = $item;
        if (count($items) >= self::MAX_TASKS) break 2;
      }
    }
    return array_slice($items, 0, self::MAX_TASKS);
  }

  /** @return int[] */
  private static function matching_item_ids(int $menu_id, string $search, string $source_locale = ''): array {
    return array_values(array_map(static function(\WP_Post $item): int { return (int)$item->ID; }, self::matching_items($menu_id, $search, $source_locale)));
  }

  /** @param array<string,mixed> $job */
  private static function complete(array $job): void {
    $job['status'] = 'completed'; $job['finished_at'] = gmdate('c'); update_option(self::OPT_JOB, $job, false); self::unschedule();
  }

  private static function schedule(): void {
    if (function_exists('as_schedule_single_action') && function_exists('as_next_scheduled_action')) {
      if (!as_next_scheduled_action(self::HOOK, [], 'ymulti')) as_schedule_single_action(time() + 5, self::HOOK, [], 'ymulti');
      return;
    }
    if (!wp_next_scheduled(self::HOOK)) wp_schedule_single_event(time() + 15, self::HOOK);
  }

  private static function unschedule(): void {
    if (function_exists('as_unschedule_all_actions')) as_unschedule_all_actions(self::HOOK, [], 'ymulti');
    wp_clear_scheduled_hook(self::HOOK);
  }

  private static function redirect(int $menu_id, string $source_locale, string $target, string $search = ''): void {
    $args = ['page' => 'ypm-menu-translation', 'menu_id' => max(0, $menu_id), 'source_locale' => $source_locale, 'locale' => $target];
    if ($search !== '') $args['s'] = $search;
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php'))); exit;
  }
}
