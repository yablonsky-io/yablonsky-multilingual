<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Human-managed locale media metadata and alternate-asset workspace. */
final class MediaWorkspace {
  public static function init(): void {
    if (!is_admin()) return;
    add_action('admin_menu', [__CLASS__, 'register_page'], 33);
    add_action('admin_post_ymulti_media_workspace_save', [__CLASS__, 'handle_save']);
    add_action('admin_post_ymulti_media_ai_suggest', [__CLASS__, 'handle_ai_suggest']);
    add_action('wp_ajax_ymulti_media_ai_inline_suggest', [__CLASS__, 'handle_ai_suggest_ajax']);
    add_action('wp_ajax_ymulti_media_translation_family', [__CLASS__, 'ajax_translation_family']);
  }

  public static function register_page(): void {
    add_submenu_page('ypm-multilingual', __('Media Localization', 'yablonsky-multilingual'), __('Media Localization', 'yablonsky-multilingual'), 'upload_files', 'ypm-media', [__CLASS__, 'render_page']);
  }

  public static function render_page(): void {
    if (!current_user_can('upload_files')) return;
    $active = Languages::active_codes();
    $source_raw = filter_input(INPUT_GET, 'source_locale', FILTER_UNSAFE_RAW);
    $source_locale = is_string($source_raw) ? sanitize_text_field($source_raw) : '';
    if ($source_locale !== '' && !in_array($source_locale, $active, true)) $source_locale = '';
    $targets = $active;
    $locale_supplied = filter_has_var(INPUT_GET, 'locale');
    $locale_raw = filter_input(INPUT_GET, 'locale', FILTER_UNSAFE_RAW);
    $locale = is_string($locale_raw) ? sanitize_text_field($locale_raw) : '';
    if (!$locale_supplied && $locale === '' && class_exists('Yablonsky\Multilingual\AdminLanguageContext')) $locale = AdminLanguageContext::preferred_target();
    if ($locale !== '' && !in_array($locale, $targets, true)) $locale = '';
    $search_raw = filter_input(INPUT_GET, 's', FILTER_UNSAFE_RAW);
    $search = is_string($search_raw) ? trim(sanitize_text_field($search_raw)) : '';
    $paged = max(1, (int) filter_input(INPUT_GET, 'paged', FILTER_VALIDATE_INT));
    $per = Admin::requested_page_size(25, [25, 50, 100]);
    $open_media_raw = filter_input(INPUT_GET, 'edit_media', FILTER_VALIDATE_INT);
    $open_media = $open_media_raw ? (int) $open_media_raw : 0;

    $query_args = ['post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>'image','posts_per_page'=>$per,'paged'=>$paged,'s'=>$search,'orderby'=>'ID','order'=>'DESC','no_found_rows'=>false];
    if ($source_locale !== '') $query_args = AdminContent::with_source_language($query_args, $source_locale); else $query_args = AdminContent::with_family_representative($query_args);
    $query = new \WP_Query($query_args);
    $rows = $query->posts;
    $total = (int) $query->found_posts;
    $pages = max(1, (int) $query->max_num_pages);
    $row_ids = array_values(array_filter(array_map(static function($row): int { return $row instanceof \WP_Post ? (int) $row->ID : 0; }, $rows)));
    if ($row_ids) update_meta_cache('post', $row_ids);
    $localized = 0;
    $alternates = 0;
    foreach ($rows as $row) {
      if (!($row instanceof \WP_Post)) continue;
      $row_id = (int) $row->ID;
      if ($locale !== '') {
        $rec = MediaLocalization::record($row_id, $locale);
        if ($rec['title'] !== '' || $rec['alt'] !== '' || $rec['caption'] !== '' || $rec['description'] !== '') $localized++;
        if (MediaLocalization::alternate_attachment_id($row_id, $locale) > 0) $alternates++;
        continue;
      }
      $localized_locales = MediaLocalization::localized_locales($row_id);
      if ($localized_locales) $localized++;
      foreach ($targets as $candidate_locale) {
        if (MediaLocalization::alternate_attachment_id($row_id, $candidate_locale) > 0) { $alternates++; break; }
      }
    }

    echo '<div class="wrap ypm-admin ypm-admin--media">';
    $saved_notice = (int) filter_input(INPUT_GET, 'saved', FILTER_VALIDATE_INT);
    $integration_notice = (int) filter_input(INPUT_GET, 'integration_warning', FILTER_VALIDATE_INT);
    if ($saved_notice === 1) {
      echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Media localization saved.', 'yablonsky-multilingual') . '</p></div>';
    }
    if ($integration_notice === 1) {
      echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Media localization was saved, but a post-save integration listener reported an error. YMulti kept the saved data and logged the listener failure for diagnosis.', 'yablonsky-multilingual') . '</p></div>';
    }
    Admin::render_shell_header(__('Media Localization','yablonsky-multilingual'), __('Localize image title, alt text, caption and description, or map an alternate image per locale while each Media Library item keeps one native identity.','yablonsky-multilingual'), 'neutral', __('Human-approved','yablonsky-multilingual'));
    if (class_exists('Yablonsky\Multilingual\AITranslation')) {
      $vision_setup_page = AITranslation::vision_setup_status();
      if (empty($vision_setup_page['ready'])) AIAdmin::render_vision_compute_gate($vision_setup_page, add_query_arg(['page'=>'ypm-media','source_locale'=>$source_locale,'locale'=>$locale !== '' ? $locale : 'all','s'=>$search], admin_url('admin.php')));
    }
    $media_site_coverage = SiteCoverage::media();
    echo '<div class="ypm-metrics ypm-metrics--4">';
    Admin::render_metric(__('Images','yablonsky-multilingual'), (string) $total, __('After current search','yablonsky-multilingual'), 'neutral');
    Admin::render_metric(__('Target locale','yablonsky-multilingual'), $locale !== '' ? Languages::display_code($locale) : '—', $locale !== '' ? Languages::display_name($locale) : __('All languages','yablonsky-multilingual'), 'neutral');
    Admin::render_metric(__('Site coverage','yablonsky-multilingual'), (string) $media_site_coverage['percent'] . '%', (string) $media_site_coverage['detail'], (string) $media_site_coverage['state']);
    Admin::render_metric(__('This page','yablonsky-multilingual'), (string) $localized, sprintf(
      /* translators: %d: alternate locale-specific assets on the current page. */
      __('Localized metadata · %d alternate assets', 'yablonsky-multilingual'),
      $alternates
    ), 'neutral');
    echo '</div>';

    echo '<section class="ypm-card ypm-card--standalone"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Media localization','yablonsky-multilingual') . '</span><h2>' . esc_html__('Localized media workspace','yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('Native attachment fields stay untouched. Locale sidecars are used only for the matching language; alternate assets are optional.','yablonsky-multilingual') . '</p></div></div>';
    echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="ypm-workspace-filter-form ypm-workspace-filter-form--compact"><input type="hidden" name="page" value="ypm-media"><label class="ypm-workspace-filter"><span>' . esc_html__('Source language','yablonsky-multilingual') . '</span><select name="source_locale"><option value="">' . esc_html__('Any source language','yablonsky-multilingual') . '</option>';
    foreach ($active as $code) echo '<option value="' . esc_attr($code) . '"' . selected($source_locale, $code, false) . '>' . esc_html(Languages::display_name($code) . ' · ' . Languages::display_code($code)) . '</option>';
    echo '</select></label><label class="ypm-workspace-filter"><span>' . esc_html__('Target language','yablonsky-multilingual') . '</span><select id="ymulti-media-target-language" name="locale" data-ymulti-searchable-select="1"><option value="all"' . selected($locale, '', false) . '>' . esc_html__('All languages · overview','yablonsky-multilingual') . '</option>';
    foreach ($targets as $code) echo '<option value="' . esc_attr($code) . '"' . selected($locale, $code, false) . '>' . esc_html(Languages::display_name($code) . ' · ' . Languages::display_code($code)) . '</option>';
    echo '</select></label><label class="ypm-workspace-filter ypm-workspace-filter--search"><span>' . esc_html__('Search','yablonsky-multilingual') . '</span><input type="search" name="s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Image title','yablonsky-multilingual') . '"></label><div class="ypm-workspace-filter-actions"><button class="button button-primary">' . esc_html__('Apply filters','yablonsky-multilingual') . '</button><a class="button" href="' . esc_url(admin_url('admin.php?page=ypm-media')) . '">' . esc_html__('Reset','yablonsky-multilingual') . '</a></div></form>';
    echo '<p class="ymulti-media-filter-note">' . esc_html__('Bulk AI uses the current source language, target language and search across all matching pages. Prepared AI drafts are marked inside each image editor and are skipped by later campaigns until reviewed or cleared.','yablonsky-multilingual') . '</p>';

    if ($locale !== '' && $rows && class_exists('Yablonsky\\Multilingual\\MediaAIJobs')) MediaAIJobs::render_panel($source_locale, $locale, $search, $total, count($row_ids), $per, $paged);

    if (!$rows) {
      echo '<div class="ypm-empty-panel"><span class="dashicons dashicons-format-image" aria-hidden="true"></span><strong>' . esc_html__('0 images match this view','yablonsky-multilingual') . '</strong></div>';
    } else {
      echo '<div class="ypm-table-wrap"><table class="widefat ymulti-entity-table ymulti-entity-table--media ypm-media-table"><thead><tr><th class="ymulti-bulk-check"><input type="checkbox" data-ymulti-bulk-all="media" aria-label="' . esc_attr__('Select this page', 'yablonsky-multilingual') . '"></th><th class="ymulti-col-primary">' . esc_html__('Image','yablonsky-multilingual') . '</th><th class="ymulti-col-source">' . esc_html__('Source','yablonsky-multilingual') . '</th><th class="ymulti-col-coverage">' . esc_html__('Coverage','yablonsky-multilingual') . '</th><th class="ymulti-col-translations">' . esc_html__('Translations','yablonsky-multilingual') . '</th><th class="ymulti-col-action ypm-col-action">' . esc_html__('Action','yablonsky-multilingual') . '</th></tr></thead><tbody>';
      foreach ($rows as $attachment) {
        if (!($attachment instanceof \WP_Post)) continue;
        self::render_row($attachment, $targets, $source_locale, $locale, $search, $per, $paged, $open_media);
      }
      echo '</tbody></table></div>';
    }

    $start = $total > 0 ? (($paged - 1) * $per) + 1 : 0;
    $end = min($total, $paged * $per);
    /* translators: 1: first visible item, 2: last visible item, 3: total matching items. */
    echo '<div class="ypm-pagination"><span>' . esc_html(sprintf(__('Showing %1$d–%2$d of %3$d','yablonsky-multilingual'), $start, $end, $total)) . '</span>';
    $locale_query = $locale !== '' ? $locale : 'all';
    Admin::render_server_page_size(['page'=>'ypm-media','source_locale'=>$source_locale,'locale'=>$locale_query,'s'=>$search], $per, [25,50,100]);
    if ($pages > 1) {
      $links = paginate_links(['base'=>add_query_arg(['page'=>'ypm-media','source_locale'=>$source_locale,'locale'=>$locale_query,'s'=>$search,'per_page'=>$per,'paged'=>'%#%'], admin_url('admin.php')), 'format'=>'', 'current'=>$paged, 'total'=>$pages, 'prev_text'=>'‹', 'next_text'=>'›', 'type'=>'array']);
      if (is_array($links) && $links) echo '<span class="ypm-pagination__links">' . wp_kses_post(implode('', $links)) . '</span>';
    }
    echo '</div></section></div>';
  }

  /**
   * Native Media Library uses the same family interaction as YMulti workspaces.
   */
  public static function render_native_family_cell(int $attachment_id): void {
    $attachment = get_post($attachment_id);
    if (!($attachment instanceof \WP_Post) || $attachment->post_type !== 'attachment') return;
    $active = Languages::active_codes();
    // Native Media Library rows do not carry a requested workspace source.
    // Resolve the row's actual effective source directly; referencing the
    // workspace-only $requested_source here truncated upload.php during column
    // rendering and prevented WordPress footer scripts from loading.
    $source = RuntimeAuthority::effective_post_language($attachment_id);
    if ($source === '') $source = EntityContract::post_source_locale($attachment_id);
    if ($source === '') $source = Languages::default_code();
    $coverage_locales = MediaLocalization::coverage_locales($attachment_id);
    echo wp_kses(
      self::family_chips($attachment_id, $coverage_locales, $active, $source, '', 25, 1, true),
      LanguageUI::allowed_html()
    );
  }

  /** @param string[] $coverage_locales @param string[] $active */
  private static function family_chips(int $attachment_id, array $coverage_locales, array $active, string $source, string $search, int $per, int $paged, bool $include_progress = false): string {
    $coverage_set = array_fill_keys($coverage_locales, true);
    $visible = self::visible_family_locales($active, $source, $coverage_locales, 5);
    ob_start();
    echo '<div class="ypm-family" aria-label="' . esc_attr__('Translation family', 'yablonsky-multilingual') . '">';
    if ($include_progress) {
      $complete = count(array_intersect($coverage_locales, $active));
      $fallback = get_edit_post_link($attachment_id, '') ?: admin_url('upload.php');
      echo '<a class="ypm-family-progress ypm-family-more" data-media-id="' . esc_attr((string) $attachment_id) . '" href="' . esc_url($fallback) . '" title="' . esc_attr__('Open translation family', 'yablonsky-multilingual') . '">' . esc_html((string) $complete) . '/' . esc_html((string) count($active)) . '</a>';
    }
    foreach ($visible as $code) {
      $display = Languages::display_code($code);
      if ($code === $source) {
        echo '<span class="ypm-family-chip is-current" title="' . esc_attr__('Source language', 'yablonsky-multilingual') . '">' . esc_html($display) . '</span>';
        continue;
      }
      $exists = isset($coverage_set[$code]);
      $url = self::target_editor_url($attachment_id, $source, $code, $search, $per, $paged);
      echo '<a class="ypm-family-chip' . ($exists ? ' is-existing' : ' is-missing') . '" href="' . esc_url($url) . '" title="' . esc_attr(($exists ? __('Edit', 'yablonsky-multilingual') : __('Create', 'yablonsky-multilingual')) . ' ' . Languages::display_name($code)) . '">' . esc_html(($exists ? '' : '+') . $display) . '</a>';
    }
    $hidden = max(0, count($active) - count(array_intersect($visible, $active)));
    if ($hidden > 0) {
      $fallback = get_edit_post_link($attachment_id, '') ?: admin_url('upload.php');
      echo '<a class="ypm-family-chip ypm-family-more is-overflow" data-media-id="' . esc_attr((string) $attachment_id) . '" href="' . esc_url($fallback) . '" title="' . esc_attr__('Open translation family', 'yablonsky-multilingual') . '">+' . esc_html((string) $hidden) . '</a>';
    }
    echo '</div>';
    return (string) ob_get_clean();
  }

  /** @param string[] $active @param string[] $covered */
  private static function visible_family_locales(array $active, string $source, array $covered, int $limit): array {
    if (count($active) <= $limit) return $active;
    $out = [];
    $append = static function(string $code) use (&$out): void { if ($code !== '' && !in_array($code, $out, true)) $out[] = $code; };
    $append($source);
    $covered_set = array_fill_keys($covered, true);
    foreach ($active as $code) { if (isset($covered_set[$code])) $append($code); if (count($out) >= $limit) break; }
    foreach ($active as $code) { $append($code); if (count($out) >= $limit) break; }
    return array_slice($out, 0, $limit);
  }

  private static function target_editor_url(int $attachment_id, string $source, string $target, string $search = '', int $per = 25, int $paged = 1): string {
    return add_query_arg([
      'page' => 'ypm-media',
      'source_locale' => $source,
      'locale' => $target,
      's' => $search,
      'per_page' => $per,
      'paged' => max(1, $paged),
      'edit_media' => $attachment_id,
    ], admin_url('admin.php')) . '#ymulti-media-row-' . $attachment_id;
  }

  /** @param string[] $covered_locales @param string[] $active */
  private static function family_payload(int $attachment_id, array $covered_locales, array $active, string $source, string $search = '', int $per = 25, int $paged = 1): array {
    $covered = array_fill_keys($covered_locales, true);
    $rows = [];
    foreach ($active as $code) {
      $meta = Languages::locale_metadata($code);
      $name = (string) ($meta['english_name'] ?? $meta['name'] ?? Languages::display_name($code));
      $native = (string) ($meta['native_name'] ?? '');
      $display = Languages::display_code($code);
      $current = $code === $source;
      $exists = isset($covered[$code]);
      $state = $current ? __('Current', 'yablonsky-multilingual') : ($exists ? __('Edit', 'yablonsky-multilingual') : __('Create', 'yablonsky-multilingual'));
      $rows[] = [
        'name' => $name,
        'native' => $native,
        'code' => $display,
        'current' => $current,
        'primary' => !$current && !$exists,
        'state' => $state,
        'url' => $current ? '' : self::target_editor_url($attachment_id, $source, $code, $search, $per, $paged),
        'search' => strtolower($name . ' ' . $native . ' ' . $display . ' ' . $code),
      ];
    }
    $attachment = get_post($attachment_id);
    $title = $attachment instanceof \WP_Post ? sanitize_text_field((string) $attachment->post_title) : '';
    if ($title === '') $title = '#' . $attachment_id;
    return [
      'title' => $title,
      'coverage' => sprintf(
        /* translators: 1: localized locales, 2: active locales. */
        __('%1$d of %2$d locales connected', 'yablonsky-multilingual'),
        count(array_intersect($covered_locales, $active)),
        count($active)
      ),
      'rows' => $rows,
    ];
  }

  public static function ajax_translation_family(): void {
    check_ajax_referer('ymulti_media_translation_family');
    $id_raw = filter_input(INPUT_POST, 'attachment_id', FILTER_VALIDATE_INT);
    $source_raw = filter_input(INPUT_POST, 'source_locale', FILTER_UNSAFE_RAW);
    $id = $id_raw ? (int) $id_raw : 0;
    $source = is_string($source_raw) ? sanitize_text_field($source_raw) : '';
    if ($id <= 0 || !current_user_can('edit_post', $id) || get_post_type($id) !== 'attachment') {
      wp_send_json_error(['message' => __('Forbidden translation family request.', 'yablonsky-multilingual')], 403);
    }
    wp_send_json_success(['html' => self::family_panel_html($id, $source)]);
  }

  private static function family_panel_html(int $attachment_id, string $requested_source = ''): string {
    $attachment = get_post($attachment_id);
    if (!($attachment instanceof \WP_Post)) return '';
    $active = Languages::active_codes();
    $source = EntityContract::post_source_locale($attachment_id);
    if ($source === '') $source = Languages::default_code();
    $covered = MediaLocalization::coverage_locales($attachment_id);
    $covered_set = array_fill_keys($covered, true);
    $coverage = count(array_intersect($covered, $active));
    $title = sanitize_text_field((string) $attachment->post_title);
    if ($title === '') $title = '#' . $attachment_id;
    ob_start();
    echo '<div class="ypm-family-panel"><strong class="ypm-family-panel__title">' . esc_html($title) . '</strong>';
    echo '<div class="ypm-family-panel__coverage">' . esc_html(sprintf(
      /* translators: 1: localized locales, 2: active locales. */
      __('%1$d of %2$d locales connected', 'yablonsky-multilingual'),
      $coverage,
      count($active)
    )) . '</div>';
    echo '<input class="ypm-family-search" type="search" placeholder="' . esc_attr__('Search languages…', 'yablonsky-multilingual') . '"><div class="ypm-family-list">';
    foreach ($active as $code) {
      $name = Languages::display_name($code);
      $display = Languages::display_code($code);
      $search = strtolower($name . ' ' . $display . ' ' . $code);
      echo '<div class="ypm-family-row" data-search="' . esc_attr($search) . '"><div class="ypm-family-row__name"><strong>' . esc_html($name) . '</strong><span>' . esc_html($code) . '</span></div><span class="ypm-family-chip ypm-family-row__code' . ($code === $source ? ' is-current' : '') . '">' . esc_html($display) . '</span><div class="ypm-family-row__action">';
      if ($code === $source) {
        echo '<span class="ypm-family-status is-current">' . esc_html__('Current', 'yablonsky-multilingual') . '</span>';
      } else {
        $exists = isset($covered_set[$code]);
        $url = self::target_editor_url($attachment_id, $source, $code);
        echo '<a class="button button-small' . ($exists ? '' : ' button-primary') . '" href="' . esc_url($url) . '">' . esc_html($exists ? __('Edit', 'yablonsky-multilingual') : __('Create', 'yablonsky-multilingual')) . '</a>';
      }
      echo '</div></div>';
    }
    echo '</div></div>';
    return (string) ob_get_clean();
  }

  /** @param array<int,string> $targets */
  private static function render_row(\WP_Post $attachment, array $targets, string $source_locale, string $locale, string $search, int $per, int $paged, int $open_media = 0): void {
    $id = (int) $attachment->ID;
    $thumb = wp_get_attachment_image($id, [72,72], false, ['loading'=>'lazy']);
    $native_title = sanitize_text_field((string) $attachment->post_title);
    $native_alt = sanitize_text_field((string) get_post_meta($id, '_wp_attachment_image_alt', true));
    $native_caption = sanitize_textarea_field((string) $attachment->post_excerpt);
    $native_description = sanitize_textarea_field((string) $attachment->post_content);
    $rec = $locale !== '' ? MediaLocalization::record($id, $locale) : ['title'=>'','alt'=>'','caption'=>'','description'=>''];
    $suggestion = $locale !== '' ? MediaLocalization::ai_suggestion($id, $locale) : [];
    $alternate = $locale !== '' ? MediaLocalization::alternate_attachment_id($id, $locale) : 0;
    $row_source = RuntimeAuthority::effective_post_language($id);
    if ($row_source === '') $row_source = Languages::default_code();
    $coverage_locales = MediaLocalization::coverage_locales($id);
    $family = self::family_chips($id, $coverage_locales, $targets, $row_source, $search, $per, $paged);
    $alt_thumb = $alternate > 0 ? wp_get_attachment_image($alternate, [72,72], false, ['loading'=>'lazy']) : '';
    $edit = 'ymulti-media-edit-' . $id;

    $active = Languages::active_codes();
    $coverage = count(array_intersect($coverage_locales, $active));
    $active_count = max(1, count($active));
    $coverage = min($active_count, $coverage);
    $coverage_percent = min(100, (int) round(($coverage / $active_count) * 100));
    $coverage_open = $coverage < $active_count;

    $media_selection_name = $native_title !== '' ? $native_title : ('#' . $id);
    /* translators: %s: media attachment title or ID. */
    $select_media_label = sprintf(__('Select %s', 'yablonsky-multilingual'), $media_selection_name);
    $has_localized = $rec['title'] !== '' || $rec['alt'] !== '' || $rec['caption'] !== '' || $rec['description'] !== '';
    $suggestion_coverage = 0;
    foreach (['title','alt','caption','description'] as $field) if (trim((string) ($suggestion[$field] ?? '')) !== '') $suggestion_coverage++;
    $has_suggestion = $suggestion_coverage > 0;
    $vision_setup = $locale !== '' ? AITranslation::vision_setup_status() : [];
    $vision_ready = $locale !== '' && !empty($vision_setup['ready']) && AITranslation::current_user_can_compute();

    echo '<tr id="ymulti-media-row-' . esc_attr((string) $id) . '"><td class="ymulti-bulk-check"><input type="checkbox" value="' . esc_attr((string)$id) . '" data-ymulti-bulk-row="media" aria-label="' . esc_attr($select_media_label) . '"></td>';
    echo '<td class="ymulti-col-primary"><div class="ypm-media-cell"><span class="ypm-media-thumb">' . wp_kses_post($thumb) . '</span><div><strong>' . esc_html($native_title !== '' ? $native_title : __('Untitled image', 'yablonsky-multilingual')) . '</strong><span>#' . esc_html((string)$id) . ($native_alt !== '' ? ' · ' . esc_html($native_alt) : '') . '</span></div></div></td>';
    echo '<td class="ymulti-col-source">' . wp_kses_post(Admin::language_badge($row_source)) . '</td>';
    echo '<td class="ymulti-col-coverage"><div class="ypm-coverage"><div><strong>' . esc_html((string)$coverage . '/' . (string)$active_count) . '</strong></div><span class="ypm-coverage-bar"><i style="width:' . esc_attr((string)$coverage_percent) . '%"></i></span><small>' . esc_html($coverage_open ? __('Needs translation', 'yablonsky-multilingual') : __('Complete', 'yablonsky-multilingual')) . '</small></div></td>';
    echo '<td class="ymulti-col-translations">' . wp_kses($family, LanguageUI::allowed_html()) . '</td>';
    echo '<td class="ymulti-col-action ypm-col-action"><div class="ypm-row-actions">';
    if ($locale === $row_source && $locale !== '') {
      $source_edit_url = current_user_can('edit_post', $id) ? get_edit_post_link($id, '') : '';
      if ($source_edit_url) echo '<a class="button button-small" href="' . esc_url($source_edit_url) . '">' . esc_html__('Edit source', 'yablonsky-multilingual') . '</a>';
    } elseif ($locale !== '') {
      if ($has_suggestion) {
        echo '<button type="button" class="button button-small button-primary" data-ypm-edit-row="' . esc_attr($edit) . '" aria-expanded="false">' . esc_html__('Review draft', 'yablonsky-multilingual') . '</button>';
      } elseif ($vision_ready) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ymulti-inline-ai-form">';
        wp_nonce_field('ymulti_media_ai_suggest_' . $id . '_' . $locale, 'ymulti_media_ai_nonce');
        echo '<input type="hidden" name="action" value="ymulti_media_ai_suggest"><input type="hidden" name="attachment_id" value="' . esc_attr((string)$id) . '"><input type="hidden" name="source_locale" value="' . esc_attr($row_source) . '"><input type="hidden" name="locale" value="' . esc_attr($locale) . '"><button class="button button-small button-primary ymulti-ai-cta">' . esc_html__('AI draft', 'yablonsky-multilingual') . '</button></form>';
      }
      echo '<button type="button" class="button button-small" data-ypm-edit-row="' . esc_attr($edit) . '" aria-expanded="false">' . esc_html__('Edit target', 'yablonsky-multilingual') . '</button>';
    }
    $family_payload = self::family_payload($id, $coverage_locales, $active, $row_source, $search, $per, $paged);
    echo '<button type="button" class="button button-small ypm-family-more" data-media-id="' . esc_attr((string) $id) . '" data-family-source="' . esc_attr($row_source) . '" data-family-payload="' . esc_attr(wp_json_encode($family_payload)) . '">' . esc_html__('Open family', 'yablonsky-multilingual') . '</button>';
    echo '</div></td></tr>';
    if ($locale === '' || $locale === $row_source) return;
    echo '<tr id="' . esc_attr($edit) . '" class="ypm-string-edit-row"' . ($open_media === $id ? '' : ' hidden') . '><td colspan="6">';

    echo '<div class="ymulti-media-ai-tools"><div><strong>' . esc_html__('AI media metadata','yablonsky-multilingual') . '</strong><span>' . esc_html__('Vision creates a review draft for localized title, accessibility-first alt text, a concise caption and a distinct description. Human approval publishes these fields.','yablonsky-multilingual') . '</span></div><div class="ymulti-media-ai-actions">';
    if ($vision_ready) {
      echo '<button type="button" class="button button-primary ymulti-ai-cta" data-ymulti-media-ai-suggest data-attachment-id="' . esc_attr((string)$id) . '" data-locale="' . esc_attr($locale) . '" data-nonce="' . esc_attr(wp_create_nonce('ymulti_media_ai_inline_' . $id . '_' . $locale)) . '">' . esc_html__('Draft all metadata with AI','yablonsky-multilingual') . '</button>';
    }
    echo '<span class="ymulti-media-ai-status" data-ymulti-media-ai-status aria-live="polite"></span></div></div>';

    if ($has_suggestion) {
      /* translators: 1: model id, 2: number of populated fields out of four. */
      $suggestion_meta = sprintf(__('%1$s · %2$d/4 fields','yablonsky-multilingual'), $suggestion['model'], $suggestion_coverage);
      echo '<div class="ymulti-media-ai-suggestion" data-ymulti-media-suggestion data-title="' . esc_attr($suggestion['title']) . '" data-alt="' . esc_attr($suggestion['alt']) . '" data-caption="' . esc_attr($suggestion['caption']) . '" data-description="' . esc_attr($suggestion['description']) . '"><div><span>' . esc_html__('Saved AI draft · review pending','yablonsky-multilingual') . '</span><strong>' . esc_html($suggestion['title'] !== '' ? $suggestion['title'] : ($suggestion['alt'] !== '' ? $suggestion['alt'] : __('Media metadata draft','yablonsky-multilingual'))) . '</strong><p>' . esc_html($suggestion['caption'] !== '' ? $suggestion['caption'] : $suggestion['description']) . '</p><small>' . esc_html($suggestion_meta) . '</small></div><button type="button" class="button button-primary" data-ymulti-media-use-suggestion>' . esc_html__('Load draft into fields','yablonsky-multilingual') . '</button></div>';
    }

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ypm-media-edit-form">';
    wp_nonce_field('ymulti_media_workspace_save_' . $id . '_' . $locale, 'ymulti_media_nonce');
    echo '<input type="hidden" name="action" value="ymulti_media_workspace_save"><input type="hidden" name="attachment_id" value="' . esc_attr((string)$id) . '"><input type="hidden" name="source_locale" value="' . esc_attr($row_source) . '"><input type="hidden" name="locale" value="' . esc_attr($locale) . '">';
    echo '<div class="ymulti-media-metadata-grid">';
    echo '<label><span>' . esc_html__('Localized title','yablonsky-multilingual') . '</span><input type="text" name="title" value="' . esc_attr($rec['title']) . '" placeholder="' . esc_attr($native_title) . '"><small>' . esc_html__('Human-facing media title for this language.','yablonsky-multilingual') . '</small></label>';
    echo '<label><span>' . esc_html__('Localized alt text','yablonsky-multilingual') . '</span><input type="text" name="alt" value="' . esc_attr($rec['alt']) . '" placeholder="' . esc_attr($native_alt) . '"><small>' . esc_html__('Describe the image naturally in the selected language. Keep it concise and useful for accessibility.','yablonsky-multilingual') . '</small></label>';
    echo '<label><span>' . esc_html__('Localized caption','yablonsky-multilingual') . '</span><textarea name="caption" rows="3" placeholder="' . esc_attr($native_caption) . '">' . esc_textarea($rec['caption']) . '</textarea><small>' . esc_html__('Optional visible caption. Add one when it contributes useful context; otherwise the current image presentation remains active.','yablonsky-multilingual') . '</small></label>';
    echo '<label><span>' . esc_html__('Localized description','yablonsky-multilingual') . '</span><textarea name="description" rows="3" placeholder="' . esc_attr($native_description) . '">' . esc_textarea($rec['description']) . '</textarea><small>' . esc_html__('Optional longer attachment description for this language.','yablonsky-multilingual') . '</small></label>';
    echo '</div>';
    echo '<div class="ymulti-media-asset-card"><label><span>' . esc_html__('Alternate image','yablonsky-multilingual') . '</span><input type="hidden" name="alternate_attachment_id" value="' . esc_attr((string)$alternate) . '" data-ymulti-media-id><div class="ypm-media-picker-preview" data-ymulti-media-preview>' . ($alternate > 0 ? wp_kses_post($alt_thumb) : '<span>' . esc_html__('Source image remains active','yablonsky-multilingual') . '</span>') . '</div><small>' . esc_html__('Optional locale-specific asset. The source attachment remains the identity authority.','yablonsky-multilingual') . '</small><div class="ypm-row-actions"><button type="button" class="button" data-ymulti-media-pick>' . esc_html__('Choose image','yablonsky-multilingual') . '</button><button type="button" class="button" data-ymulti-media-clear>' . esc_html__('Use source image','yablonsky-multilingual') . '</button></div></label></div>';
    echo '<div class="ypm-string-inline-form__actions"><span>' . esc_html__('AI drafts run through explicit admin actions. Saved locale metadata and asset mappings use local lookups during frontend requests.','yablonsky-multilingual') . '</span><div><button type="button" class="button" data-ypm-close-row="' . esc_attr($edit) . '">' . esc_html__('Cancel','yablonsky-multilingual') . '</button> <button class="button button-primary">' . esc_html__('Save localization','yablonsky-multilingual') . '</button></div></div></form></td></tr>';
  }

  public static function handle_ai_suggest_ajax(): void {
    if (!current_user_can('upload_files') || !AITranslation::current_user_can_compute()) wp_send_json_error(['message'=>__('AI media metadata permission is required for this action.','yablonsky-multilingual')], 403);
    $id_raw = filter_input(INPUT_POST, 'attachment_id', FILTER_VALIDATE_INT);
    $locale_raw = filter_input(INPUT_POST, 'locale', FILTER_UNSAFE_RAW);
    $id = $id_raw ? (int) $id_raw : 0;
    $locale = is_string($locale_raw) ? sanitize_text_field($locale_raw) : '';
    if ($id <= 0 || !current_user_can('edit_post', $id) || !EntityContract::post_target_allowed($id, $locale)) wp_send_json_error(['message'=>__('Invalid media AI request.','yablonsky-multilingual')], 400);
    check_ajax_referer('ymulti_media_ai_inline_' . $id . '_' . $locale, 'nonce');
    if (!AITranslation::vision_ready()) wp_send_json_error(['message'=>__('Connect Yablonsky AI and choose a Vision model first.','yablonsky-multilingual')], 409);
    $analysis = AITranslation::analyze_image_attachment($id, $locale);
    if (is_wp_error($analysis)) wp_send_json_error(['message'=>$analysis->get_error_message()], 502);
    MediaLocalization::save_ai_suggestion($id, $locale, $analysis);
    wp_send_json_success([
      'title'=>sanitize_text_field((string) ($analysis['suggested_title'] ?? '')),
      'alt'=>sanitize_text_field((string) ($analysis['suggested_alt'] ?? '')),
      'caption'=>sanitize_textarea_field((string) ($analysis['suggested_caption'] ?? '')),
      'description'=>sanitize_textarea_field((string) ($analysis['suggested_description'] ?? '')),
      'model'=>sanitize_text_field((string) ($analysis['model'] ?? '')),
      'coverage'=>4,
      'message'=>__('AI draft ready · 4/4 metadata fields. Review them, then save manually.','yablonsky-multilingual'),
    ]);
  }

  public static function handle_ai_suggest(): void {
    if (!current_user_can('upload_files')) wp_die(esc_html__('Forbidden','yablonsky-multilingual'));
    $id_raw = filter_input(INPUT_POST,'attachment_id',FILTER_VALIDATE_INT);
    $source_raw = filter_input(INPUT_POST,'source_locale',FILTER_UNSAFE_RAW);
    $locale_raw = filter_input(INPUT_POST,'locale',FILTER_UNSAFE_RAW);
    $id = $id_raw ? (int) $id_raw : 0;
    $source_locale = is_string($source_raw ?? null) ? sanitize_text_field($source_raw) : '';
    $locale = is_string($locale_raw) ? sanitize_text_field($locale_raw) : '';
    if ($id <= 0 || !current_user_can('edit_post',$id) || !EntityContract::post_target_allowed($id, $locale)) wp_die(esc_html__('Invalid media AI request.','yablonsky-multilingual'));
    if (!AITranslation::current_user_can_compute() || !AITranslation::vision_ready()) wp_die(esc_html__('Vision setup or permission required. Verify the workspace, Vision model and AI compute permission.','yablonsky-multilingual'));
    check_admin_referer('ymulti_media_ai_suggest_'.$id.'_'.$locale,'ymulti_media_ai_nonce');
    $analysis = AITranslation::analyze_image_attachment($id,$locale);
    if (is_wp_error($analysis)) {
      wp_safe_redirect(add_query_arg(['page'=>'ypm-media','source_locale'=>$source_locale,'locale'=>$locale,'edit_media'=>$id,'ymulti_media_ai_error'=>rawurlencode($analysis->get_error_message())], admin_url('admin.php'))); exit;
    }
    MediaLocalization::save_ai_suggestion($id,$locale,$analysis);
    wp_safe_redirect(add_query_arg(['page'=>'ypm-media','source_locale'=>$source_locale,'locale'=>$locale,'edit_media'=>$id,'ymulti_media_ai_ready'=>1], admin_url('admin.php'))); exit;
  }

  public static function handle_save(): void {
    if (!current_user_can('upload_files')) wp_die(esc_html__('Forbidden','yablonsky-multilingual'));
    $id_raw = filter_input(INPUT_POST,'attachment_id',FILTER_VALIDATE_INT);
    $source_raw = filter_input(INPUT_POST,'source_locale',FILTER_UNSAFE_RAW);
    $locale_raw = filter_input(INPUT_POST,'locale',FILTER_UNSAFE_RAW);
    $title_raw = filter_input(INPUT_POST,'title',FILTER_UNSAFE_RAW);
    $alt_raw = filter_input(INPUT_POST,'alt',FILTER_UNSAFE_RAW);
    $caption_raw = filter_input(INPUT_POST,'caption',FILTER_UNSAFE_RAW);
    $description_raw = filter_input(INPUT_POST,'description',FILTER_UNSAFE_RAW);
    $alternate_raw = filter_input(INPUT_POST,'alternate_attachment_id',FILTER_VALIDATE_INT);
    $id = $id_raw ? (int) $id_raw : 0;
    $source_locale = is_string($source_raw) ? sanitize_text_field($source_raw) : '';
    $locale = is_string($locale_raw) ? sanitize_text_field($locale_raw) : '';
    $title = is_string($title_raw) ? sanitize_text_field($title_raw) : '';
    $alt = is_string($alt_raw) ? sanitize_text_field($alt_raw) : '';
    $caption = is_string($caption_raw) ? sanitize_textarea_field($caption_raw) : '';
    $description = is_string($description_raw) ? sanitize_textarea_field($description_raw) : '';
    $alternate = $alternate_raw ? (int) $alternate_raw : 0;
    if ($id <= 0 || !current_user_can('edit_post',$id) || !EntityContract::post_target_allowed($id, $locale)) wp_die(esc_html__('Invalid media localization request.','yablonsky-multilingual'));
    check_admin_referer('ymulti_media_workspace_save_'.$id.'_'.$locale,'ymulti_media_nonce');
    if (!MediaLocalization::save_full($id, $locale, $title, $alt, $caption, $description, $alternate)) wp_die(esc_html__('Media localization save needs attention. Review the fields and try again.','yablonsky-multilingual'));
    $integration_warning = false;
    try {
      MediaLocalization::clear_ai_suggestion($id, $locale);
    } catch (\Throwable $error) {
      $integration_warning = true;
      MultilingualSEOProvider::record_integration_error('media_ai_draft_cleanup', $error);
    }
    if (!MultilingualSEOProvider::notify_invalidation(['resource_type'=>'global','reason'=>'media_localization_changed','option'=>'media_localization'])) {
      $integration_warning = true;
    }
    $args = ['page'=>'ypm-media','source_locale'=>$source_locale,'locale'=>$locale,'saved'=>1];
    if ($integration_warning) $args['integration_warning'] = 1;
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php'))); exit;
  }
}
