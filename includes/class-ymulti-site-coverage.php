<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Admin-only, site-specific localization coverage.
 *
 * Coverage is deliberately different from feature availability: a capability
 * may exist in YMulti while the installed site still has untranslated work.
 * Every measurable result is expressed as completed locale records / required
 * locale records for the site's currently active locales.
 */
final class SiteCoverage {
  /** @var array<string,array<string,mixed>> */
  private static array $request_cache = [];

  public static function reset(): void {
    self::$request_cache = [];
  }

  /** @return string[] */
  public static function target_locales(): array {
    $default = Languages::default_code();
    return array_values(array_filter(Languages::active_codes(), static function(string $code) use ($default): bool {
      return $code !== $default;
    }));
  }

  /** @return array{done:int,total:int,percent:int,state:string,label:string,detail:string} */
  public static function metric(int $done, int $total, string $detail = ''): array {
    $done = max(0, $done);
    $total = max(0, $total);
    if ($total > 0) $done = min($done, $total);
    $percent = $total > 0 ? (int) floor(($done / $total) * 100) : 0;
    if ($done < $total && $percent >= 100) $percent = 99;
    if ($total <= 0) {
      $state = 'neutral';
      $label = __('Ready when applicable', 'yablonsky-multilingual');
    } elseif ($done >= $total) {
      $state = 'pass';
      $label = __('100% complete', 'yablonsky-multilingual');
    } elseif ($done <= 0) {
      $state = 'neutral';
      $label = __('0% · Ready to start', 'yablonsky-multilingual');
    } elseif ($percent >= 90) {
      $state = 'near';
      /* translators: %d: localization completion percentage. */
      $label = sprintf(__('%d%% complete', 'yablonsky-multilingual'), $percent);
    } else {
      $state = 'progress';
      /* translators: %d: localization completion percentage. */
      $label = sprintf(__('%d%% complete', 'yablonsky-multilingual'), $percent);
    }
    return compact('done', 'total', 'percent', 'state', 'label', 'detail');
  }

  /** @param string[] $post_types @return array{done:int,total:int,percent:int,state:string,label:string,detail:string} */
  public static function posts(array $post_types = []): array {
    $post_types = array_values(array_unique(array_filter(array_map('sanitize_key', $post_types ?: Languages::supported_post_types()))));
    if (!$post_types) $post_types = ['post', 'page'];
    sort($post_types);
    $key = 'posts:' . implode(',', $post_types) . ':' . implode(',', Languages::active_codes());
    if (isset(self::$request_cache[$key])) return self::$request_cache[$key];

    global $wpdb;
    $active = Languages::active_codes();
    if (RuntimeAuthority::wpml_translation_table_available() && $active) {
      $provider_codes = [];
      foreach ($active as $code) {
        $provider = RuntimeAuthority::provider_code_for_language($code);
        if ($provider !== '' && !in_array($provider, $provider_codes, true)) $provider_codes[] = $provider;
      }
      if (!$provider_codes) return self::$request_cache[$key] = self::metric(0, 0);
      $table = RuntimeAuthority::wpml_translation_table();
      $types = array_map(static function(string $type): string { return 'post_' . sanitize_key($type); }, $post_types);
      $type_ph = implode(',', array_fill(0, count($types), '%s'));
      $post_type_ph = implode(',', array_fill(0, count($post_types), '%s'));
      $lang_ph = implode(',', array_fill(0, count($provider_codes), '%s'));
      $sql = "SELECT COUNT(DISTINCT it.trid) AS families, COUNT(DISTINCT CONCAT(it.trid,':',it.language_code)) AS completed "
        . 'FROM ' . esc_sql($table) . ' it INNER JOIN ' . $wpdb->posts . ' p ON p.ID = it.element_id '
        . 'WHERE it.trid > 0 AND it.element_type IN (' . $type_ph . ') AND p.post_type IN (' . $post_type_ph . ") AND p.post_status NOT IN ('trash','auto-draft','inherit') "
        . 'AND it.language_code IN (' . $lang_ph . ')';
      $args = array_merge($types, $post_types, $provider_codes);
      // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only incumbent aggregate with prepared values.
      $row = $wpdb->get_row($wpdb->prepare($sql, $args));
      $families = is_object($row) ? max(0, (int) ($row->families ?? 0)) : 0;
      $done = is_object($row) ? max(0, (int) ($row->completed ?? 0)) : 0;
      $total = $families * count($active);
      /* translators: 1: localized records, 2: required locale records, 3: content families. */
      $detail = sprintf(__('%1$d of %2$d locale records · %3$d content families', 'yablonsky-multilingual'), $done, $total, $families);
      return self::$request_cache[$key] = self::metric($done, $total, $detail);
    }
    $active_slugs = array_values(array_unique(array_map([Languages::class, 'code_to_slug'], $active)));
    $default_slug = Languages::code_to_slug(Languages::default_code());
    if (!$active_slugs) return self::$request_cache[$key] = self::metric(0, 0);

    $active_ph = implode(',', array_fill(0, count($active_slugs), '%s'));
    $types_ph = implode(',', array_fill(0, count($post_types), '%s'));

    $group_sql = 'SELECT gtt.term_taxonomy_id AS family_id, '
      . 'COUNT(DISTINCT CASE WHEN COALESCE(lm.slug,%s) IN (' . $active_ph . ') THEN COALESCE(lm.slug,%s) END) AS locale_count '
      . 'FROM ' . $wpdb->term_taxonomy . ' gtt '
      . 'INNER JOIN ' . $wpdb->term_relationships . ' gr ON gr.term_taxonomy_id = gtt.term_taxonomy_id '
      . 'INNER JOIN ' . $wpdb->posts . ' p ON p.ID = gr.object_id '
      . 'LEFT JOIN (SELECT lr.object_id, lt.slug FROM ' . $wpdb->term_relationships . ' lr '
      . 'INNER JOIN ' . $wpdb->term_taxonomy . ' ltt ON ltt.term_taxonomy_id = lr.term_taxonomy_id '
      . 'INNER JOIN ' . $wpdb->terms . ' lt ON lt.term_id = ltt.term_id WHERE ltt.taxonomy = %s) lm ON lm.object_id = p.ID '
      . 'WHERE gtt.taxonomy = %s AND p.post_type IN (' . $types_ph . ") AND p.post_status NOT IN ('trash','auto-draft','inherit') "
      . 'GROUP BY gtt.term_taxonomy_id';
    $group_args = array_merge([$default_slug], $active_slugs, [$default_slug, Languages::TAX, Translations::GROUP_TAX], $post_types);

    $single_sql = 'SELECT -p.ID AS family_id, '
      . 'CASE WHEN COALESCE(lm.slug,%s) IN (' . $active_ph . ') THEN 1 ELSE 0 END AS locale_count '
      . 'FROM ' . $wpdb->posts . ' p '
      . 'LEFT JOIN (SELECT lr.object_id, lt.slug FROM ' . $wpdb->term_relationships . ' lr '
      . 'INNER JOIN ' . $wpdb->term_taxonomy . ' ltt ON ltt.term_taxonomy_id = lr.term_taxonomy_id '
      . 'INNER JOIN ' . $wpdb->terms . ' lt ON lt.term_id = ltt.term_id WHERE ltt.taxonomy = %s) lm ON lm.object_id = p.ID '
      . 'WHERE p.post_type IN (' . $types_ph . ") AND p.post_status NOT IN ('trash','auto-draft','inherit') "
      . 'AND NOT EXISTS (SELECT 1 FROM ' . $wpdb->term_relationships . ' ugr INNER JOIN ' . $wpdb->term_taxonomy . ' ugtt ON ugtt.term_taxonomy_id = ugr.term_taxonomy_id WHERE ugr.object_id = p.ID AND ugtt.taxonomy = %s)';
    $single_args = array_merge([$default_slug], $active_slugs, [Languages::TAX], $post_types, [Translations::GROUP_TAX]);

    $sql = 'SELECT COUNT(*) AS families, COALESCE(SUM(locale_count),0) AS completed FROM (' . $group_sql . ' UNION ALL ' . $single_sql . ') ymulti_coverage';
    $args = array_merge($group_args, $single_args);
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only aggregate; identifiers are WordPress-owned and all variable values use placeholders.
    $row = $wpdb->get_row($wpdb->prepare($sql, $args));
    $families = is_object($row) ? max(0, (int) ($row->families ?? 0)) : 0;
    $done = is_object($row) ? max(0, (int) ($row->completed ?? 0)) : 0;
    $total = $families * count($active);
    /* translators: 1: localized records, 2: required locale records, 3: content families. */
    $detail = sprintf(__('%1$d of %2$d locale records · %3$d content families', 'yablonsky-multilingual'), $done, $total, $families);
    return self::$request_cache[$key] = self::metric($done, $total, $detail);
  }

  /** @param string[] $taxonomies @return array{done:int,total:int,percent:int,state:string,label:string,detail:string} */
  public static function terms(array $taxonomies): array {
    $taxonomies = array_values(array_unique(array_filter(array_map('sanitize_key', $taxonomies))));
    sort($taxonomies);
    $key = 'terms:' . implode(',', $taxonomies) . ':' . implode(',', Languages::active_codes());
    if (isset(self::$request_cache[$key])) return self::$request_cache[$key];
    if (!$taxonomies) return self::$request_cache[$key] = self::metric(0, 0);

    global $wpdb;
    $active = Languages::active_codes();
    if (!$active) return self::$request_cache[$key] = self::metric(0, 0);
    if (RuntimeAuthority::wpml_translation_table_available()) {
      $provider_codes = [];
      foreach ($active as $code) {
        $provider = RuntimeAuthority::provider_code_for_language($code);
        if ($provider !== '' && !in_array($provider, $provider_codes, true)) $provider_codes[] = $provider;
      }
      if (!$provider_codes) return self::$request_cache[$key] = self::metric(0, 0);
      $table = RuntimeAuthority::wpml_translation_table();
      $tax_ph = implode(',', array_fill(0, count($taxonomies), '%s'));
      $lang_ph = implode(',', array_fill(0, count($provider_codes), '%s'));
      $sql = "SELECT COUNT(DISTINCT it.trid) AS families, COUNT(DISTINCT CONCAT(it.trid,':',it.language_code)) AS completed "
        . 'FROM ' . esc_sql($table) . ' it INNER JOIN ' . $wpdb->term_taxonomy . ' tt ON tt.term_taxonomy_id = it.element_id '
        . "WHERE it.trid > 0 AND it.element_type = CONCAT('tax_',tt.taxonomy) AND tt.taxonomy IN (" . $tax_ph . ') AND it.language_code IN (' . $lang_ph . ')';
      $args = array_merge($taxonomies, $provider_codes);
      // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only incumbent taxonomy aggregate with prepared values.
      $row = $wpdb->get_row($wpdb->prepare($sql, $args));
      $families = is_object($row) ? max(0, (int) ($row->families ?? 0)) : 0;
      $done = is_object($row) ? max(0, (int) ($row->completed ?? 0)) : 0;
      $total = $families * count($active);
      /* translators: 1: localized records, 2: required locale records, 3: term families. */
      $detail = sprintf(__('%1$d of %2$d locale records · %3$d term families', 'yablonsky-multilingual'), $done, $total, $families);
      return self::$request_cache[$key] = self::metric($done, $total, $detail);
    }
    $active_ph = implode(',', array_fill(0, count($active), '%s'));
    $tax_ph = implode(',', array_fill(0, count($taxonomies), '%s'));
    $default = Languages::default_code();

    $sql = 'SELECT COUNT(*) AS families, COALESCE(SUM(locale_count),0) AS completed FROM ('
      . 'SELECT tt.taxonomy, COALESCE(NULLIF(g.meta_value,\'\'), CONCAT(\'term:\',t.term_id)) AS family_key, '
      . 'COUNT(DISTINCT CASE WHEN COALESCE(NULLIF(l.meta_value,\'\'),%s) IN (' . $active_ph . ') THEN COALESCE(NULLIF(l.meta_value,\'\'),%s) END) AS locale_count '
      . 'FROM ' . $wpdb->terms . ' t INNER JOIN ' . $wpdb->term_taxonomy . ' tt ON tt.term_id = t.term_id '
      . 'LEFT JOIN ' . $wpdb->termmeta . ' g ON g.term_id = t.term_id AND g.meta_key = %s '
      . 'LEFT JOIN ' . $wpdb->termmeta . ' l ON l.term_id = t.term_id AND l.meta_key = %s '
      . 'WHERE tt.taxonomy IN (' . $tax_ph . ') '
      . 'GROUP BY tt.taxonomy, family_key) ymulti_term_coverage';
    $args = array_merge([$default], $active, [$default, TermTranslations::META_GROUP, TermTranslations::META_LANGUAGE], $taxonomies);
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only aggregate with fixed table names and prepared values.
    $row = $wpdb->get_row($wpdb->prepare($sql, $args));
    $families = is_object($row) ? max(0, (int) ($row->families ?? 0)) : 0;
    $done = is_object($row) ? max(0, (int) ($row->completed ?? 0)) : 0;
    $total = $families * count($active);
    /* translators: 1: localized records, 2: required locale records, 3: term families. */
    $detail = sprintf(__('%1$d of %2$d locale records · %3$d term families', 'yablonsky-multilingual'), $done, $total, $families);
    return self::$request_cache[$key] = self::metric($done, $total, $detail);
  }

  /** @return array{done:int,total:int,percent:int,state:string,label:string,detail:string} */
  public static function menus(): array {
    $key = 'menus:' . implode(',', Languages::active_codes());
    if (isset(self::$request_cache[$key])) return self::$request_cache[$key];
    $menus = wp_get_nav_menus();
    $items = [];
    foreach ($menus as $menu) {
      $menu_items = wp_get_nav_menu_items((int) $menu->term_id, ['post_status' => 'any']);
      if (is_array($menu_items)) foreach ($menu_items as $item) if ($item instanceof \WP_Post) $items[] = $item;
    }
    $active = Languages::active_codes();
    if (!$items || !$active) return self::$request_cache[$key] = self::metric(0, 0, __('Native menu items: 0 in the current scope.', 'yablonsky-multilingual'));

    $item_ids = array_map(static function(\WP_Post $item): int { return (int) $item->ID; }, $items);
    if ($item_ids) update_meta_cache('post', $item_ids);

    // Count one logical menu-item family once. WPML/Polylang can persist a
    // physical nav_menu_item object per locale; counting each object as a new
    // source made both totals and per-item Coverage lie during coexistence.
    $families = [];
    $done = 0;
    foreach ($items as $item) {
      $effective = EffectiveRelations::post_family((int) $item->ID, null, $active);
      $family_key = sanitize_text_field((string) ($effective['family_key'] ?? ''));
      if ($family_key === '') $family_key = 'menu-item:' . (int) $item->ID;
      if (isset($families[$family_key])) continue;
      $families[$family_key] = true;
      $labels = MenuTranslations::labels((int) $item->ID);
      $resolved = MenuTranslations::resolved_locales($item, $labels, $active);
      $done += count(array_intersect($resolved, $active));
    }

    $family_count = count($families);
    $total = $family_count * count($active);
    /* translators: 1: completed menu locale records, 2: required menu locale records, 3: logical menu item families. */
    $detail = sprintf(__('%1$d of %2$d locale records · %3$d menu items', 'yablonsky-multilingual'), $done, $total, $family_count);
    return self::$request_cache[$key] = self::metric($done, $total, $detail);
  }

  /** @return array{done:int,total:int,percent:int,state:string,label:string,detail:string} */
  public static function media(): array {
    $key = 'media:' . implode(',', Languages::active_codes());
    if (isset(self::$request_cache[$key])) return self::$request_cache[$key];
    global $wpdb;
    $active = Languages::active_codes();
    if (!$active) return self::$request_cache[$key] = self::metric(0, 0);

    // Incumbent bridge: count the actual attachment translation graph, not only
    // YMulti sidecars. This makes existing WPML media families visible before
    // migration and uses the same family truth as the Media table.
    if (RuntimeAuthority::wpml_translation_table_available()) {
      $provider_codes = [];
      foreach ($active as $code) {
        $provider = RuntimeAuthority::provider_code_for_language($code);
        if ($provider !== '' && !in_array($provider, $provider_codes, true)) $provider_codes[] = $provider;
      }
      if ($provider_codes) {
        $table = RuntimeAuthority::wpml_translation_table();
        $lang_ph = implode(',', array_fill(0, count($provider_codes), '%s'));
        $image_like = $wpdb->esc_like('image/') . '%';
        $sql = 'SELECT COUNT(DISTINCT it.trid) AS families, COUNT(DISTINCT CONCAT(it.trid,\':\',it.language_code)) AS completed '
          . 'FROM ' . esc_sql($table) . ' it INNER JOIN ' . $wpdb->posts . ' p ON p.ID = it.element_id '
          . "WHERE it.trid > 0 AND it.element_type = %s AND p.post_type = 'attachment' AND p.post_mime_type LIKE %s AND p.post_status <> 'trash' "
          . 'AND it.language_code IN (' . $lang_ph . ')';
        $args = array_merge(['post_attachment', $image_like], $provider_codes);
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only incumbent media-family aggregate with prepared values.
        $row = $wpdb->get_row($wpdb->prepare($sql, $args));
        $families = is_object($row) ? max(0, (int) ($row->families ?? 0)) : 0;
        $done = is_object($row) ? max(0, (int) ($row->completed ?? 0)) : 0;
        $total = $families * count($active);
        /* translators: 1: completed image locale records, 2: required locale records, 3: logical image families. */
        $detail = sprintf(__('%1$d of %2$d locale records · %3$d images', 'yablonsky-multilingual'), $done, $total, $families);
        return self::$request_cache[$key] = self::metric($done, $total, $detail);
      }
    }

    // Native YMulti: aggregate physical attachment-family identity in SQL, then
    // add sidecar/alternate-asset locales that do not have a separate family
    // member. This preserves both migration-style families and YMulti's
    // single-attachment localization model.
    $active_slugs = array_values(array_unique(array_map([Languages::class, 'code_to_slug'], $active)));
    $default_slug = Languages::code_to_slug(Languages::default_code());
    $active_ph = implode(',', array_fill(0, count($active_slugs), '%s'));

    $group_sql = 'SELECT gtt.term_taxonomy_id AS family_id, '
      . 'COUNT(DISTINCT CASE WHEN COALESCE(lm.slug,%s) IN (' . $active_ph . ') THEN COALESCE(lm.slug,%s) END) AS locale_count '
      . 'FROM ' . $wpdb->term_taxonomy . ' gtt '
      . 'INNER JOIN ' . $wpdb->term_relationships . ' gr ON gr.term_taxonomy_id = gtt.term_taxonomy_id '
      . 'INNER JOIN ' . $wpdb->posts . ' p ON p.ID = gr.object_id '
      . 'LEFT JOIN (SELECT lr.object_id, lt.slug FROM ' . $wpdb->term_relationships . ' lr '
      . 'INNER JOIN ' . $wpdb->term_taxonomy . ' ltt ON ltt.term_taxonomy_id = lr.term_taxonomy_id '
      . 'INNER JOIN ' . $wpdb->terms . ' lt ON lt.term_id = ltt.term_id WHERE ltt.taxonomy = %s) lm ON lm.object_id = p.ID '
      . "WHERE gtt.taxonomy = %s AND p.post_type = 'attachment' AND p.post_mime_type LIKE %s AND p.post_status <> 'trash' "
      . 'GROUP BY gtt.term_taxonomy_id';
    $image_like = $wpdb->esc_like('image/') . '%';
    $group_args = array_merge([$default_slug], $active_slugs, [$default_slug, Languages::TAX, Translations::GROUP_TAX, $image_like]);

    $single_sql = 'SELECT -p.ID AS family_id, '
      . 'CASE WHEN COALESCE(lm.slug,%s) IN (' . $active_ph . ') THEN 1 ELSE 0 END AS locale_count '
      . 'FROM ' . $wpdb->posts . ' p '
      . 'LEFT JOIN (SELECT lr.object_id, lt.slug FROM ' . $wpdb->term_relationships . ' lr '
      . 'INNER JOIN ' . $wpdb->term_taxonomy . ' ltt ON ltt.term_taxonomy_id = lr.term_taxonomy_id '
      . 'INNER JOIN ' . $wpdb->terms . ' lt ON lt.term_id = ltt.term_id WHERE ltt.taxonomy = %s) lm ON lm.object_id = p.ID '
      . "WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s AND p.post_status <> 'trash' "
      . 'AND NOT EXISTS (SELECT 1 FROM ' . $wpdb->term_relationships . ' ugr INNER JOIN ' . $wpdb->term_taxonomy . ' ugtt ON ugtt.term_taxonomy_id = ugr.term_taxonomy_id WHERE ugr.object_id = p.ID AND ugtt.taxonomy = %s)';
    $single_args = array_merge([$default_slug], $active_slugs, [Languages::TAX, $image_like, Translations::GROUP_TAX]);

    $sql = 'SELECT COUNT(*) AS families, COALESCE(SUM(locale_count),0) AS completed FROM (' . $group_sql . ' UNION ALL ' . $single_sql . ') ymulti_media_coverage';
    $args = array_merge($group_args, $single_args);
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only native media-family aggregate; all variable values use prepare placeholders.
    $row = $wpdb->get_row($wpdb->prepare($sql, $args));
    $families = is_object($row) ? max(0, (int) ($row->families ?? 0)) : 0;
    $done = is_object($row) ? max(0, (int) ($row->completed ?? 0)) : 0;

    // Sidecar/alternate records are sparse; inspect only those rows and count a
    // locale when the native family itself does not already contain it.
    $meta_keys = [MediaLocalization::META, MediaLocalization::META_ASSET];
    $postmeta_table = esc_sql($wpdb->postmeta);
    $posts_table = esc_sql($wpdb->posts);
    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted core table identifiers; sparse meta keys and mime value remain prepared.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Sparse YMulti-owned media meta scan.
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT pm.post_id, pm.meta_key, pm.meta_value FROM " . $postmeta_table . " pm INNER JOIN " . $posts_table . " p ON p.ID=pm.post_id WHERE pm.meta_key IN (%s,%s) AND p.post_type='attachment' AND p.post_mime_type LIKE %s AND p.post_status <> 'trash'",
      $meta_keys[0],
      $meta_keys[1],
      $image_like
    ));
    // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $extra = [];
    if (is_array($rows)) foreach ($rows as $meta_row) {
      $post_id = absint($meta_row->post_id ?? 0);
      if ($post_id <= 0) continue;
      $value = maybe_unserialize((string) ($meta_row->meta_value ?? ''));
      if (!is_array($value)) continue;
      $family = EffectiveRelations::post_family($post_id, null, $active);
      $existing = array_fill_keys(array_keys((array) ($family['translations'] ?? [])), true);
      foreach ($value as $locale => $record) {
        $locale = sanitize_text_field((string) $locale);
        if (!in_array($locale, $active, true) || isset($existing[$locale])) continue;
        $ready = false;
        if ((string) ($meta_row->meta_key ?? '') === MediaLocalization::META && is_array($record)) {
          $ready = trim((string) ($record['title'] ?? '')) !== '' || trim((string) ($record['alt'] ?? '')) !== '' || trim((string) ($record['caption'] ?? '')) !== '' || trim((string) ($record['description'] ?? '')) !== '';
        } elseif ((string) ($meta_row->meta_key ?? '') === MediaLocalization::META_ASSET) {
          $ready = absint($record) > 0;
        }
        if ($ready) $extra[EntityContract::post_family_key($post_id) . '|' . $locale] = true;
      }
    }
    $done += count($extra);
    $total = $families * count($active);
    /* translators: 1: completed image locale records, 2: required locale records, 3: logical image families. */
    $detail = sprintf(__('%1$d of %2$d locale records · %3$d images', 'yablonsky-multilingual'), $done, $total, $families);
    return self::$request_cache[$key] = self::metric($done, $total, $detail);
  }

  /** @param string[] $attribute_taxonomies @return array{done:int,total:int,percent:int,state:string,label:string,detail:string} */
  public static function attribute_labels(array $attribute_taxonomies): array {
    $attribute_taxonomies = array_values(array_unique(array_filter(array_map('sanitize_key', $attribute_taxonomies))));
    $active = Languages::active_codes();
    $count = count($attribute_taxonomies);
    if ($count <= 0 || !$active) return self::metric(0, 0, __('Global attributes: 0 configured.', 'yablonsky-multilingual'));
    $all = get_option('ypm_woo_attribute_labels_v1', []);
    if (!is_array($all)) $all = [];
    $done = $count; // source/default labels.
    foreach (self::target_locales() as $locale) {
      $labels = isset($all[$locale]) && is_array($all[$locale]) ? $all[$locale] : [];
      foreach ($attribute_taxonomies as $taxonomy) if (isset($labels[$taxonomy]) && trim((string) $labels[$taxonomy]) !== '') $done++;
    }
    $total = $count * count($active);
    /* translators: 1: localized attribute labels, 2: required attribute-label locale records, 3: attributes. */
    $detail = sprintf(__('%1$d of %2$d locale records · %3$d global attributes', 'yablonsky-multilingual'), $done, $total, $count);
    return self::metric($done, $total, $detail);
  }

  /** @return array{done:int,total:int,percent:int,state:string,label:string,detail:string} */
  public static function commerce_emails(): array {
    if (!function_exists('WC')) return self::metric(0, 0, __('WooCommerce integration becomes available when WooCommerce is active.', 'yablonsky-multilingual'));
    $wc = WC();
    $mailer = $wc ? $wc->mailer() : null;
    $emails = $mailer && method_exists($mailer, 'get_emails') ? $mailer->get_emails() : [];
    if (!is_array($emails)) $emails = [];
    $ids = [];
    foreach ($emails as $email) if (is_object($email) && !empty($email->id)) $ids[] = (string) $email->id;
    $ids = array_values(array_unique($ids));
    $active = Languages::active_codes();
    if (!$ids || !$active) return self::metric(0, 0, __('Commerce email templates: 0 registered.', 'yablonsky-multilingual'));
    $all = get_option('ypm_woo_email_overrides', []);
    if (!is_array($all)) $all = [];
    $done = count($ids); // native/default email settings.
    foreach (self::target_locales() as $locale) {
      foreach ($ids as $id) {
        $values = isset($all[$id][$locale]) && is_array($all[$id][$locale]) ? $all[$id][$locale] : [];
        $subject = trim((string) ($values['subject'] ?? ''));
        $heading = trim((string) ($values['heading'] ?? ''));
        // Additional content is optional; subject + heading define localized merchant-facing email identity.
        if ($subject !== '' && $heading !== '') $done++;
      }
    }
    $total = count($ids) * count($active);
    /* translators: 1: completed email locale records, 2: required email locale records, 3: registered emails. */
    $detail = sprintf(__('%1$d of %2$d locale records · %3$d registered emails', 'yablonsky-multilingual'), $done, $total, count($ids));
    return self::metric($done, $total, $detail);
  }

  /** @param array<int,array{done:int,total:int}> $metrics @return array{done:int,total:int,percent:int,state:string,label:string,detail:string} */
  public static function combine(array $metrics, string $detail = ''): array {
    $done = 0;
    $total = 0;
    foreach ($metrics as $metric) {
      $done += max(0, (int) ($metric['done'] ?? 0));
      $total += max(0, (int) ($metric['total'] ?? 0));
    }
    return self::metric($done, $total, $detail);
  }
}
