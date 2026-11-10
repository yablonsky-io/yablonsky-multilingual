<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Locale-specific menu labels on top of one native WordPress menu structure. */
final class MenuTranslations {
  public const META = '_ymulti_menu_labels_v1';
  public const META_AI_SUGGESTIONS = '_ymulti_menu_label_ai_suggestion_v1';

  public static function init(): void {
    add_filter('nav_menu_item_title', [__CLASS__, 'filter_title'], 30, 4);
    if (is_admin()) {
      add_action('admin_menu', [__CLASS__, 'register_page'], 30);
      add_action('admin_post_ymulti_menu_label_save', [__CLASS__, 'handle_save']);
      add_action('wp_ajax_ymulti_menu_translation_family', [__CLASS__, 'ajax_translation_family']);
    }
  }

  public static function register_page(): void {
    add_submenu_page('ypm-multilingual', __('Menus', 'yablonsky-multilingual'), __('Menus', 'yablonsky-multilingual'), 'edit_theme_options', 'ypm-menu-translation', [__CLASS__, 'render_page']);
  }

  public static function label(int $menu_item_id, string $locale): string {
    if ($menu_item_id <= 0 || !Languages::is_active($locale)) return '';
    $all = self::labels($menu_item_id);
    $explicit = sanitize_text_field((string) ($all[$locale] ?? ''));
    if ($explicit !== '') return $explicit;

    // Compatibility/migration fallback: WPML/Polylang may store one translated
    // nav_menu_item object per locale instead of a YMulti label sidecar. Reuse
    // that existing object read-only in bridge mode and through the materialized
    // YMulti family after migration, so translated menu labels are not lost.
    if (class_exists('Yablonsky\\Multilingual\\EffectiveRelations', false)) {
      $target_id = EffectiveRelations::existing_target($menu_item_id, $locale);
      if ($target_id > 0 && $target_id !== $menu_item_id && get_post_type($target_id) === 'nav_menu_item') {
        return self::source_label($target_id);
      }
    }
    return '';
  }

  /** @return array<string,string> */
  public static function labels(int $menu_item_id): array {
    if ($menu_item_id <= 0) return [];
    $raw = EntityContract::merged_post_family_meta($menu_item_id, self::META);
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $code => $label) {
      if (!is_string($code) || !Languages::is_active($code)) continue;
      $label = sanitize_text_field((string) $label);
      if ($label !== '') $out[$code] = $label;
    }
    return $out;
  }

  public static function source_label(int $menu_item_id): string {
    if ($menu_item_id <= 0) return '';
    $post = get_post($menu_item_id);
    if (!($post instanceof \WP_Post) || $post->post_type !== 'nav_menu_item') return '';
    if (function_exists('wp_setup_nav_menu_item')) {
      $item = wp_setup_nav_menu_item($post);
      if ($item instanceof \WP_Post && trim((string)$item->title) !== '') return sanitize_text_field((string)$item->title);
    }
    $title = trim((string)$post->post_title);
    if ($title !== '') return sanitize_text_field($title);
    return sanitize_text_field((string)get_post_meta($menu_item_id, '_menu_item_url', true));
  }

  /** @return array<string,array<string,mixed>> */
  public static function ai_suggestions(int $menu_item_id): array {
    if ($menu_item_id <= 0) return [];
    $raw = EntityContract::merged_post_family_meta($menu_item_id, self::META_AI_SUGGESTIONS);
    return is_array($raw) ? $raw : [];
  }

  /** @return array<string,mixed> */
  public static function ai_suggestion(int $menu_item_id, string $locale): array {
    if ($menu_item_id <= 0 || !EntityContract::post_target_allowed($menu_item_id, $locale)) return [];
    $all = self::ai_suggestions($menu_item_id);
    $row = isset($all[$locale]) && is_array($all[$locale]) ? $all[$locale] : [];
    if (!$row) return [];
    $item = get_post($menu_item_id);
    if (!($item instanceof \WP_Post) || $item->post_type !== 'nav_menu_item') return [];
    $source = self::source_label($menu_item_id);
    $hash = sanitize_text_field((string)($row['source_hash'] ?? ''));
    if ($source === '' || $hash === '' || !hash_equals($hash, hash('sha256', $source))) return [];
    $label = sanitize_text_field((string)($row['label'] ?? ''));
    if ($label === '') return [];
    $row['label'] = $label;
    return $row;
  }

  /** @param array<string,mixed> $suggestion */
  public static function save_ai_suggestion(int $menu_item_id, string $locale, array $suggestion): void {
    if ($menu_item_id <= 0 || !EntityContract::post_target_allowed($menu_item_id, $locale)) return;
    $label = sanitize_text_field((string)($suggestion['label'] ?? ''));
    if ($label === '') return;
    $all = self::ai_suggestions($menu_item_id);
    $all[$locale] = [
      'label' => $label,
      'source_hash' => sanitize_text_field((string)($suggestion['source_hash'] ?? '')),
      'model' => sanitize_text_field((string)($suggestion['model'] ?? '')),
      'cost' => is_numeric($suggestion['cost'] ?? null) ? (float)$suggestion['cost'] : null,
      'prepared_at' => sanitize_text_field((string)($suggestion['prepared_at'] ?? gmdate('c'))),
    ];
    EntityContract::write_post_family_meta($menu_item_id, self::META_AI_SUGGESTIONS, $all);
  }

  public static function clear_ai_suggestion(int $menu_item_id, string $locale): void {
    $all = self::ai_suggestions($menu_item_id);
    if (!isset($all[$locale])) return;
    unset($all[$locale]);
    EntityContract::write_post_family_meta($menu_item_id, self::META_AI_SUGGESTIONS, $all);
  }

  public static function filter_title(string $title, $menu_item, $args, int $depth): string {
    unset($args, $depth);
    if (is_admin() || !($menu_item instanceof \WP_Post)) return $title;
    $locale = Router::current_lang();
    if (!Languages::is_active($locale) || EntityContract::post_source_locale((int) $menu_item->ID) === $locale) return $title;
    $localized = self::label((int) $menu_item->ID, $locale);
    return $localized !== '' ? $localized : $title;
  }

  /** @return string[] */
  public static function resolved_locales(\WP_Post $item, array $labels, array $active): array {
    $resolved = [];
    $row_source = RuntimeAuthority::effective_post_language((int) $item->ID);
    if ($row_source === '') $row_source = Languages::default_code();
    if (in_array($row_source, $active, true)) $resolved[$row_source] = true;

    // A translated menu item is itself a translation family. This is the
    // missing provider-neutral layer that previously made WPML menus appear
    // as 1/N even when every locale already existed.
    $item_family = EffectiveRelations::post_family((int) $item->ID, null, $active);
    foreach (array_keys((array) ($item_family['translations'] ?? [])) as $code) {
      if (in_array($code, $active, true)) $resolved[$code] = true;
    }

    foreach (array_keys($labels) as $code) if (in_array($code, $active, true)) $resolved[$code] = true;

    $object_id = absint($item->object_id ?? 0);
    if ($object_id > 0 && (string)($item->type ?? '') === 'post_type') {
      $family = EffectiveRelations::post_family($object_id, null, $active);
      foreach (array_keys((array) ($family['translations'] ?? [])) as $code) if (in_array($code, $active, true)) $resolved[$code] = true;
    } elseif ($object_id > 0 && (string)($item->type ?? '') === 'taxonomy') {
      $taxonomy = sanitize_key((string)($item->object ?? ''));
      if ($taxonomy !== '') {
        $family = EffectiveRelations::term_family($object_id, $taxonomy, null, $active);
        foreach (array_keys((array) ($family['translations'] ?? [])) as $code) if (in_array($code, $active, true)) $resolved[$code] = true;
      }
    }

    return array_values(array_keys($resolved));
  }

  public static function render_page(): void {
    if (!current_user_can('edit_theme_options')) return;
    $menus = wp_get_nav_menus();
    $menu_raw = filter_input(INPUT_GET, 'menu_id', FILTER_VALIDATE_INT);
    $menu_id = $menu_raw ? (int) $menu_raw : 0;
    if ($menu_id > 0 && !wp_get_nav_menu_object($menu_id)) $menu_id = 0;
    $active = Languages::active_codes();
    $source_raw = filter_input(INPUT_GET, 'source_locale', FILTER_UNSAFE_RAW);
    $source_locale = is_string($source_raw) ? sanitize_text_field($source_raw) : '';
    if ($source_locale !== '' && !in_array($source_locale, $active, true)) $source_locale = '';
    $target_locales = $active;
    $locale_supplied = filter_has_var(INPUT_GET, 'locale');
    $locale_raw = filter_input(INPUT_GET, 'locale', FILTER_UNSAFE_RAW);
    $locale = is_string($locale_raw) ? sanitize_text_field($locale_raw) : '';
    if (!$locale_supplied && $locale === '' && class_exists('Yablonsky\Multilingual\AdminLanguageContext')) $locale = AdminLanguageContext::preferred_target();
    if ($locale !== '' && !in_array($locale, $target_locales, true)) $locale = '';
    $search_raw = filter_input(INPUT_GET, 's', FILTER_UNSAFE_RAW);
    $search = is_string($search_raw) ? trim(sanitize_text_field($search_raw)) : '';
    $paged = max(1, (int) filter_input(INPUT_GET, 'paged', FILTER_VALIDATE_INT));
    $per_page = Admin::requested_page_size(25, [25, 50, 100]);
    $open_item_raw = filter_input(INPUT_GET, 'edit_menu_item', FILTER_VALIDATE_INT);
    $open_item = $open_item_raw ? (int) $open_item_raw : 0;

    $item_menu_ids = [];
    $item_menu_names = [];
    $items = self::items_for_scope($menus, $menu_id, $item_menu_ids, $item_menu_names, $source_locale);
    if ($search !== '') {
      $needle = strtolower($search);
      $items = array_values(array_filter($items, static function($item) use ($needle, $item_menu_names): bool {
        if (!($item instanceof \WP_Post)) return false;
        $hay = strtolower(implode(' ', [(string) $item->title, (string) $item->url, (string) $item->object, (string) $item->type, (string) ($item_menu_names[(int) $item->ID] ?? '')]));
        return strpos($hay, $needle) !== false;
      }));
    }
    $total = count($items);
    $pages = max(1, (int) ceil($total / $per_page));
    if ($paged > $pages) $paged = $pages;
    $page_items = array_slice($items, ($paged - 1) * $per_page, $per_page);
    $page_ids = array_values(array_filter(array_map(static function($item): int { return $item instanceof \WP_Post ? (int) $item->ID : 0; }, $page_items)));
    if ($page_ids) update_meta_cache('post', $page_ids);
    $localized_count = 0;
    if ($locale !== '') foreach ($items as $item) if ($item instanceof \WP_Post && self::label((int) $item->ID, $locale) !== '') $localized_count++;

    echo '<div class="wrap ypm-admin ypm-admin--menus">';
    Admin::render_shell_header(__('Menus', 'yablonsky-multilingual'), __('Keep one native WordPress menu structure, resolve linked objects automatically, and localize only the human-facing menu labels that actually need different wording.', 'yablonsky-multilingual'), 'neutral', __('Native structure', 'yablonsky-multilingual'));
    if (class_exists('Yablonsky\Multilingual\AITranslation')) {
      $text_setup = AITranslation::text_setup_status();
      if (empty($text_setup['ready'])) AIAdmin::render_text_compute_gate($text_setup, add_query_arg(['page'=>'ypm-menu-translation','menu_id'=>$menu_id,'source_locale'=>$source_locale,'locale'=>$locale !== '' ? $locale : 'all','s'=>$search], admin_url('admin.php')));
    }
    $menu_site_coverage = SiteCoverage::menus();
    echo '<div class="ypm-metrics ypm-metrics--4">';
    Admin::render_metric(__('Menus', 'yablonsky-multilingual'), (string) count($menus), __('Native WordPress menus', 'yablonsky-multilingual'), 'neutral');
    Admin::render_metric(__('Items', 'yablonsky-multilingual'), (string) $total, __('After current search', 'yablonsky-multilingual'), 'neutral');
    Admin::render_metric(__('Target locale', 'yablonsky-multilingual'), $locale !== '' ? Languages::display_code($locale) : '—', $locale !== '' ? Languages::display_name($locale) : __('All languages', 'yablonsky-multilingual'), 'neutral');
    Admin::render_metric(__('Coverage', 'yablonsky-multilingual'), (string) $menu_site_coverage['percent'] . '%', (string) $menu_site_coverage['detail'], (string) $menu_site_coverage['state']);
    echo '</div>';

    echo '<section class="ypm-card ypm-card--standalone"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Menu translation', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Localized menu labels', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('Linked posts, pages, products and terms already resolve to their translated objects. The automatic/native title remains active until you save an override.', 'yablonsky-multilingual') . '</p></div></div>';
    echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="ypm-workspace-filter-form ypm-workspace-filter-form--compact"><input type="hidden" name="page" value="ypm-menu-translation">';
    echo '<label class="ypm-workspace-filter"><span>' . esc_html__('Menu', 'yablonsky-multilingual') . '</span><select name="menu_id" data-ymulti-searchable-select="1">';
    echo '<option value="0"' . selected($menu_id, 0, false) . '>' . esc_html__('All menus', 'yablonsky-multilingual') . '</option>';
    foreach ($menus as $menu) echo '<option value="' . esc_attr((string) $menu->term_id) . '"' . selected($menu_id, (int) $menu->term_id, false) . '>' . esc_html((string) $menu->name) . '</option>';
    echo '</select></label><label class="ypm-workspace-filter"><span>' . esc_html__('Source language', 'yablonsky-multilingual') . '</span><select name="source_locale"><option value="">' . esc_html__('Any source language', 'yablonsky-multilingual') . '</option>';
    foreach ($active as $code) echo '<option value="' . esc_attr($code) . '"' . selected($source_locale, $code, false) . '>' . esc_html(Languages::display_name($code) . ' · ' . Languages::display_code($code)) . '</option>';
    echo '</select></label><label class="ypm-workspace-filter"><span>' . esc_html__('Target language', 'yablonsky-multilingual') . '</span><select id="ymulti-menu-target-language" name="locale" data-ymulti-searchable-select="1"><option value="all"' . selected($locale, '', false) . '>' . esc_html__('All languages · overview', 'yablonsky-multilingual') . '</option>';
    foreach ($target_locales as $code) echo '<option value="' . esc_attr($code) . '"' . selected($locale, $code, false) . '>' . esc_html(Languages::display_name($code) . ' · ' . Languages::display_code($code)) . '</option>';
    echo '</select></label><label class="ypm-workspace-filter ypm-workspace-filter--search"><span>' . esc_html__('Search', 'yablonsky-multilingual') . '</span><input type="search" name="s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Title, URL or object type', 'yablonsky-multilingual') . '"></label><div class="ypm-workspace-filter-actions"><button class="button button-primary">' . esc_html__('Apply filters', 'yablonsky-multilingual') . '</button><a class="button" href="' . esc_url(admin_url('admin.php?page=ypm-menu-translation')) . '">' . esc_html__('Reset', 'yablonsky-multilingual') . '</a></div></form>';

    if ($menus && $locale !== '') MenuAIJobs::render_panel($menu_id, $source_locale, $locale, $search, $page_items, $total);

    if (!$menus) {
      echo '<div class="ypm-empty-panel"><span class="dashicons dashicons-menu" aria-hidden="true"></span><strong>' . esc_html__('Create a native WordPress menu to begin', 'yablonsky-multilingual') . '</strong><p>' . esc_html__('Create a WordPress menu first; YMulti links multilingual identity to the native menu editor.', 'yablonsky-multilingual') . '</p></div>';
    } else {
      echo '<div class="ypm-table-wrap"><table class="widefat ymulti-entity-table ymulti-entity-table--menus ypm-menu-translation-table"><thead><tr><th class="ymulti-bulk-check"><input type="checkbox" data-ymulti-bulk-all="menu" aria-label="' . esc_attr__('Select this page', 'yablonsky-multilingual') . '"></th><th class="ymulti-col-primary">' . esc_html__('Menu item', 'yablonsky-multilingual') . '</th><th class="ymulti-col-source">' . esc_html__('Source', 'yablonsky-multilingual') . '</th><th class="ymulti-col-coverage">' . esc_html__('Coverage', 'yablonsky-multilingual') . '</th><th class="ymulti-col-translations">' . esc_html__('Translations', 'yablonsky-multilingual') . '</th><th class="ymulti-col-action ypm-col-action">' . esc_html__('Action', 'yablonsky-multilingual') . '</th></tr></thead><tbody>';
      foreach ($page_items as $item) {
        if (!($item instanceof \WP_Post)) continue;
        $id = (int) $item->ID;
        $row_source = RuntimeAuthority::effective_post_language($id);
        if ($row_source === '') $row_source = Languages::default_code();
        $labels = self::labels($id);
        $localized = $locale !== '' ? sanitize_text_field((string)($labels[$locale] ?? '')) : '';
        $ai = $locale !== '' ? self::ai_suggestion($id, $locale) : [];
        $ai_label = sanitize_text_field((string)($ai['label'] ?? ''));
        $type = sanitize_key((string)($item->object ?: $item->type));
        $row_menu_id = (int) ($item_menu_ids[$id] ?? $menu_id);
        $row_menu_name = sanitize_text_field((string) ($item_menu_names[$id] ?? ''));
        $resolved_locales = self::resolved_locales($item, $labels, $active);
        $family = self::family_chips($item, $resolved_locales, $active, $row_source, $row_menu_id, $search, $per_page, $paged);
        $coverage = count(array_intersect($resolved_locales, $active));
        $active_count = max(1, count($active));
        $coverage_percent = min(100, (int) round(($coverage / $active_count) * 100));
        $coverage_open = $coverage < $active_count;
        $state_copy = $locale === '' ? __('Automatic/native title', 'yablonsky-multilingual') : ($localized !== '' ? $localized : ($ai_label !== '' ? __('AI draft ready', 'yablonsky-multilingual') : __('Automatic/native title', 'yablonsky-multilingual')));
        /* translators: %s: menu item title. */
        $select_menu_item_label = sprintf(__('Select menu item %s', 'yablonsky-multilingual'), (string)$item->title);
        echo '<tr id="ymulti-menu-row-' . esc_attr((string) $id) . '"><td class="ymulti-bulk-check"><input type="checkbox" value="' . esc_attr((string)$id) . '" data-ymulti-bulk-row="menu" aria-label="' . esc_attr($select_menu_item_label) . '"></td>';
        $row_context = ($menu_id === 0 && $row_menu_name !== '') ? $row_menu_name . ' · #' . (string) $id . ' · ' . $state_copy : '#' . (string) $id . ' · ' . $state_copy;
        echo '<td class="ymulti-col-primary"><div class="ypm-content-cell"><strong>' . esc_html((string)$item->title) . '</strong><span>' . esc_html($row_context) . '</span></div></td>';
        echo '<td class="ymulti-col-source"><div class="ymulti-source-compact">' . wp_kses_post(Admin::language_badge($row_source)) . '<code class="ymulti-source-type">' . esc_html($type) . '</code><span class="ymulti-source-meta" title="' . esc_attr((string)$item->url) . '">' . esc_html((string)$item->url) . '</span></div></td>';
        echo '<td class="ymulti-col-coverage"><div class="ypm-coverage"><div><strong>' . esc_html((string)$coverage . '/' . (string)$active_count) . '</strong></div><span class="ypm-coverage-bar"><i style="width:' . esc_attr((string)$coverage_percent) . '%"></i></span><small>' . esc_html($coverage_open ? __('Needs localization', 'yablonsky-multilingual') : __('Complete', 'yablonsky-multilingual')) . '</small></div></td>';
        echo '<td class="ymulti-col-translations">' . wp_kses($family, LanguageUI::allowed_html()) . '</td>';
        echo '<td class="ymulti-col-action ypm-col-action"><div class="ypm-row-actions">';
        if ($locale === $row_source && $locale !== '') {
          $source_edit_url = $row_menu_id > 0 ? add_query_arg(['action' => 'edit', 'menu' => $row_menu_id], admin_url('nav-menus.php')) : admin_url('nav-menus.php');
          echo '<a class="button button-small" href="' . esc_url($source_edit_url) . '">' . esc_html__('Edit source', 'yablonsky-multilingual') . '</a>';
        } elseif ($locale !== '') {
          if ($ai_label !== '' && $localized === '') {
            echo '<button type="button" class="button button-small button-primary" data-ypm-edit-row="ymulti-menu-edit-' . esc_attr((string)$id) . '" aria-expanded="false">' . esc_html__('Review draft', 'yablonsky-multilingual') . '</button>';
          } elseif ($localized === '') {
            MenuAIJobs::render_single_action($row_menu_id, $id, $row_source, $locale, $search);
          }
          echo '<button type="button" class="button button-small" data-ypm-edit-row="ymulti-menu-edit-' . esc_attr((string)$id) . '" aria-expanded="false">' . esc_html__('Edit target', 'yablonsky-multilingual') . '</button>';
        }
        $family_payload = self::family_payload($item, $resolved_locales, $active, $row_source, $row_menu_id, $search, $per_page, $paged);
        echo '<button type="button" class="button button-small ypm-family-more" data-menu-item-id="' . esc_attr((string) $id) . '" data-family-source="' . esc_attr($row_source) . '" data-family-payload="' . esc_attr(wp_json_encode($family_payload)) . '">' . esc_html__('Open family', 'yablonsky-multilingual') . '</button>';
        echo '</div></td></tr>';
        if ($locale === '' || $locale === $row_source) continue;
        $is_open = $open_item === $id;
        echo '<tr id="ymulti-menu-edit-' . esc_attr((string)$id) . '" class="ypm-string-edit-row"' . ($is_open ? '' : ' hidden') . '><td colspan="6">';
        if ($ai_label !== '' && $localized === '') {
          echo '<div class="ymulti-menu-ai-suggestion"><small>' . esc_html__('AI draft', 'yablonsky-multilingual') . '</small><strong>' . esc_html($ai_label) . '</strong><button type="button" class="button-link" data-ymulti-menu-use-ai="ymulti-menu-edit-' . esc_attr((string)$id) . '" data-ymulti-menu-ai-label="' . esc_attr($ai_label) . '">' . esc_html__('Use AI draft', 'yablonsky-multilingual') . '</button></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ypm-string-inline-form">';
        wp_nonce_field('ymulti_menu_label_save_' . $id . '_' . $locale, 'ymulti_menu_nonce');
        echo '<input type="hidden" name="action" value="ymulti_menu_label_save"><input type="hidden" name="menu_item_id" value="' . esc_attr((string)$id) . '"><input type="hidden" name="menu_id" value="' . esc_attr((string)$row_menu_id) . '"><input type="hidden" name="source_locale" value="' . esc_attr($row_source) . '"><input type="hidden" name="locale" value="' . esc_attr($locale) . '"><label><span>' . esc_html__('Localized label', 'yablonsky-multilingual') . '</span><input type="text" name="label" value="' . esc_attr($localized) . '" placeholder="' . esc_attr((string)$item->title) . '"></label><div class="ypm-string-inline-form__actions"><span>' . esc_html__('Automatic/native title remains active until you save an override.', 'yablonsky-multilingual') . '</span><div><button type="button" class="button" data-ypm-close-row="ymulti-menu-edit-' . esc_attr((string)$id) . '">' . esc_html__('Cancel', 'yablonsky-multilingual') . '</button> <button class="button button-primary">' . esc_html__('Save label', 'yablonsky-multilingual') . '</button></div></div></form></td></tr>';
      }
      echo '</tbody></table></div>';
      self::render_pagination($menu_id,$source_locale,$locale,$search,$per_page,$paged,$pages,$total);
    }
    echo '<div class="ypm-row-actions"><a class="button" href="' . esc_url(admin_url('nav-menus.php')) . '">' . esc_html__('Open native Menus', 'yablonsky-multilingual') . '</a></div></section></div>';
  }

  /**
   * Row-level family chips follow the same interaction contract as Translations:
   * Source is current, existing locales edit directly, missing locales open the
   * target editor, and overflow opens the shared Family Drawer.
   *
   * @param string[] $resolved_locales
   * @param string[] $active
   */
  private static function family_chips(\WP_Post $item, array $resolved_locales, array $active, string $source, int $menu_id, string $search, int $per_page, int $paged): string {
    $resolved = array_fill_keys($resolved_locales, true);
    $visible = self::visible_family_locales($active, $source, $resolved_locales, 5);
    ob_start();
    echo '<div class="ypm-family" aria-label="' . esc_attr__('Translation family', 'yablonsky-multilingual') . '">';
    foreach ($visible as $code) {
      $label = Languages::display_code($code);
      if ($code === $source) {
        echo '<span class="ypm-family-chip is-current" title="' . esc_attr__('Source language', 'yablonsky-multilingual') . '">' . esc_html($label) . '</span>';
        continue;
      }
      $url = self::target_editor_url((int) $item->ID, $menu_id, $source, $code, $search, $per_page, $paged);
      $exists = isset($resolved[$code]);
      echo '<a class="ypm-family-chip' . ($exists ? ' is-existing' : ' is-missing') . '" href="' . esc_url($url) . '" title="' . esc_attr(($exists ? __('Edit', 'yablonsky-multilingual') : __('Create', 'yablonsky-multilingual')) . ' ' . Languages::display_name($code)) . '">' . esc_html(($exists ? '' : '+') . $label) . '</a>';
    }
    $hidden = max(0, count($active) - count(array_intersect($visible, $active)));
    if ($hidden > 0) {
      echo '<a class="ypm-family-chip ypm-family-more is-overflow" data-menu-item-id="' . esc_attr((string) $item->ID) . '" href="' . esc_url(self::native_menu_url($menu_id, (int) $item->ID)) . '" title="' . esc_attr__('Open translation family', 'yablonsky-multilingual') . '">+' . esc_html((string) $hidden) . '</a>';
    }
    echo '</div>';
    return (string) ob_get_clean();
  }

  /** @param string[] $active @param string[] $resolved */
  private static function visible_family_locales(array $active, string $source, array $resolved, int $limit): array {
    if (count($active) <= $limit) return $active;
    $out = [];
    $append = static function(string $code) use (&$out): void { if ($code !== '' && !in_array($code, $out, true)) $out[] = $code; };
    $append($source);
    $resolved_set = array_fill_keys($resolved, true);
    foreach ($active as $code) { if (isset($resolved_set[$code])) $append($code); if (count($out) >= $limit) break; }
    foreach ($active as $code) { $append($code); if (count($out) >= $limit) break; }
    return array_slice($out, 0, $limit);
  }

  private static function target_editor_url(int $item_id, int $menu_id, string $source, string $target, string $search = '', int $per_page = 25, int $paged = 1): string {
    return add_query_arg([
      'page' => 'ypm-menu-translation',
      'menu_id' => max(0, $menu_id),
      'source_locale' => $source,
      'locale' => $target,
      's' => $search,
      'per_page' => $per_page,
      'paged' => max(1, $paged),
      'edit_menu_item' => $item_id,
    ], admin_url('admin.php')) . '#ymulti-menu-row-' . $item_id;
  }

  private static function native_menu_url(int $menu_id, int $item_id = 0): string {
    $url = $menu_id > 0 ? add_query_arg(['action' => 'edit', 'menu' => $menu_id], admin_url('nav-menus.php')) : admin_url('nav-menus.php');
    return $item_id > 0 ? $url . '#menu-item-' . $item_id : $url;
  }

  private static function menu_id_for_item(int $item_id): int {
    $ids = wp_get_object_terms($item_id, 'nav_menu', ['fields' => 'ids']);
    if (is_wp_error($ids) || !is_array($ids) || !$ids) return 0;
    return absint($ids[0] ?? 0);
  }

  /** @param string[] $resolved_locales @param string[] $active */
  private static function family_payload(\WP_Post $item, array $resolved_locales, array $active, string $source, int $menu_id, string $search = '', int $per_page = 25, int $paged = 1): array {
    $resolved = array_fill_keys($resolved_locales, true);
    $rows = [];
    foreach ($active as $code) {
      $meta = Languages::locale_metadata($code);
      $name = (string) ($meta['english_name'] ?? $meta['name'] ?? Languages::display_name($code));
      $native = (string) ($meta['native_name'] ?? '');
      $display = Languages::display_code($code);
      $current = $code === $source;
      $exists = isset($resolved[$code]);
      $state = $current ? __('Current', 'yablonsky-multilingual') : ($exists ? __('Edit', 'yablonsky-multilingual') : __('Create', 'yablonsky-multilingual'));
      $rows[] = [
        'name' => $name,
        'native' => $native,
        'code' => $display,
        'current' => $current,
        'primary' => !$current && !$exists,
        'state' => $state,
        'url' => $current ? '' : self::target_editor_url((int) $item->ID, $menu_id, $source, $code, $search, $per_page, $paged),
        'search' => strtolower($name . ' ' . $native . ' ' . $display . ' ' . $code),
      ];
    }
    return [
      'title' => self::source_label((int) $item->ID),
      'coverage' => sprintf(
        /* translators: 1: translated locales, 2: active locales. */
        __('%1$d of %2$d locales connected', 'yablonsky-multilingual'),
        count(array_intersect($resolved_locales, $active)),
        count($active)
      ),
      'rows' => $rows,
    ];
  }

  public static function ajax_translation_family(): void {
    check_ajax_referer('ymulti_menu_translation_family');
    if (!current_user_can('edit_theme_options')) wp_send_json_error(['message' => __('Forbidden translation family request.', 'yablonsky-multilingual')], 403);
    $item_raw = filter_input(INPUT_POST, 'menu_item_id', FILTER_VALIDATE_INT);
    $source_raw = filter_input(INPUT_POST, 'source_locale', FILTER_UNSAFE_RAW);
    $item_id = $item_raw ? (int) $item_raw : 0;
    $source = is_string($source_raw) ? sanitize_text_field($source_raw) : '';
    $item = $item_id > 0 ? get_post($item_id) : null;
    if (!($item instanceof \WP_Post) || $item->post_type !== 'nav_menu_item') wp_send_json_error(['message' => __('Forbidden translation family request.', 'yablonsky-multilingual')], 404);
    wp_send_json_success(['html' => self::family_panel_html($item, $source)]);
  }

  private static function family_panel_html(\WP_Post $item, string $requested_source = ''): string {
    $id = (int) $item->ID;
    $active = Languages::active_codes();
    $source = Languages::is_active($requested_source) ? $requested_source : EntityContract::post_source_locale($id);
    if ($source === '') $source = RuntimeAuthority::effective_post_language($id);
    if ($source === '') $source = Languages::default_code();
    $labels = self::labels($id);
    $resolved = self::resolved_locales($item, $labels, $active);
    $resolved_set = array_fill_keys($resolved, true);
    $coverage = count(array_intersect($resolved, $active));
    $menu_id = self::menu_id_for_item($id);
    ob_start();
    echo '<div class="ypm-family-panel"><strong class="ypm-family-panel__title">' . esc_html(self::source_label($id)) . '</strong>';
    echo '<div class="ypm-family-panel__coverage">' . esc_html(sprintf(
      /* translators: 1: translated locales, 2: active locales. */
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
        $url = self::target_editor_url($id, $menu_id, $source, $code);
        $exists = isset($resolved_set[$code]);
        echo '<a class="button button-small' . ($exists ? '' : ' button-primary') . '" href="' . esc_url($url) . '">' . esc_html($exists ? __('Edit', 'yablonsky-multilingual') : __('Create', 'yablonsky-multilingual')) . '</a>';
      }
      echo '</div></div>';
    }
    echo '</div></div>';
    return (string) ob_get_clean();
  }

  /**
   * Return menu items for one menu or the aggregate All menus scope while
   * preserving each item's source menu context.
   *
   * @param array<int,object> $menus
   * @param array<int,int> $item_menu_ids
   * @param array<int,string> $item_menu_names
   * @return array<int,\WP_Post>
   */
  private static function items_for_scope(array $menus, int $menu_id, array &$item_menu_ids, array &$item_menu_names, string $source_locale = ''): array {
    $out = [];
    $families = [];
    foreach ($menus as $menu) {
      $id = isset($menu->term_id) ? (int) $menu->term_id : 0;
      if ($id <= 0 || ($menu_id > 0 && $id !== $menu_id)) continue;
      $query_args = ['post_status' => 'any'];
      if ($source_locale !== '') $query_args = AdminContent::with_source_language($query_args, $source_locale);
      $rows = wp_get_nav_menu_items($id, $query_args);
      if (!is_array($rows)) continue;
      foreach ($rows as $item) {
        if (!($item instanceof \WP_Post)) continue;
        $item_id = (int) $item->ID;
        if ($item_id <= 0) continue;
        if ($source_locale !== '' && EntityContract::post_source_locale($item_id) !== $source_locale) continue;
        $family_key = EntityContract::post_family_key($item_id);
        if (isset($families[$family_key])) continue;
        $families[$family_key] = true;
        $item_menu_ids[$item_id] = $id;
        $item_menu_names[$item_id] = sanitize_text_field((string) ($menu->name ?? ''));
        $out[] = $item;
      }
    }
    return $out;
  }

  private static function render_pagination(int $menu_id,string $source_locale,string $locale,string $search,int $per_page,int $paged,int $pages,int $total): void {
    $start=$total>0?(($paged-1)*$per_page)+1:0; $end=min($total,$paged*$per_page);
    $locale_query = $locale !== '' ? $locale : 'all';
    /* translators: 1: first visible item, 2: last visible item, 3: total matching items. */
    echo '<div class="ypm-pagination"><span>' . esc_html(sprintf(__('Showing %1$d–%2$d of %3$d', 'yablonsky-multilingual'),$start,$end,$total)) . '</span>';
    Admin::render_server_page_size(['page'=>'ypm-menu-translation','menu_id'=>$menu_id,'source_locale'=>$source_locale,'locale'=>$locale_query,'s'=>$search], $per_page, [25,50,100]);
    if($pages>1){$links=paginate_links(['base'=>add_query_arg(['page'=>'ypm-menu-translation','menu_id'=>$menu_id,'source_locale'=>$source_locale,'locale'=>$locale_query,'s'=>$search,'per_page'=>$per_page,'paged'=>'%#%'],admin_url('admin.php')),'format'=>'','current'=>$paged,'total'=>$pages,'prev_text'=>'‹','next_text'=>'›','type'=>'array']);if(is_array($links)&&$links)echo '<span class="ypm-pagination__links">'.wp_kses_post(implode('',$links)).'</span>';}
    echo '</div>';
  }

  public static function handle_save(): void {
    if (!current_user_can('edit_theme_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    $item_raw=filter_input(INPUT_POST,'menu_item_id',FILTER_VALIDATE_INT);$menu_raw=filter_input(INPUT_POST,'menu_id',FILTER_VALIDATE_INT);$source_raw=filter_input(INPUT_POST,'source_locale',FILTER_UNSAFE_RAW);$locale_raw=filter_input(INPUT_POST,'locale',FILTER_UNSAFE_RAW);$label_raw=filter_input(INPUT_POST,'label',FILTER_UNSAFE_RAW);
    $item_id=$item_raw?(int)$item_raw:0;$menu_id=$menu_raw?(int)$menu_raw:0;$source_locale=is_string($source_raw)?sanitize_text_field($source_raw):'';$locale=is_string($locale_raw)?sanitize_text_field($locale_raw):'';$label=is_string($label_raw)?sanitize_text_field($label_raw):'';
    if($item_id<=0||get_post_type($item_id)!=='nav_menu_item'||!EntityContract::post_target_allowed($item_id,$locale)) wp_die(esc_html__('Invalid menu translation request.', 'yablonsky-multilingual'));
    check_admin_referer('ymulti_menu_label_save_'.$item_id.'_'.$locale,'ymulti_menu_nonce');
    $all=EntityContract::merged_post_family_meta($item_id,self::META);if($label==='')unset($all[$locale]);else $all[$locale]=$label;EntityContract::write_post_family_meta($item_id,self::META,$all);
    if($label!=='')self::clear_ai_suggestion($item_id,$locale);
    if (class_exists('Yablonsky\Multilingual\SiteCoverage', false)) SiteCoverage::reset();
    MultilingualSEOProvider::notify_invalidation(['resource_type'=>'global','reason'=>'menu_label_changed','option'=>'menu_labels']);
    wp_safe_redirect(add_query_arg(['page'=>'ypm-menu-translation','menu_id'=>$menu_id,'source_locale'=>$source_locale,'locale'=>$locale,'saved'=>1],admin_url('admin.php')));exit;
  }
}
