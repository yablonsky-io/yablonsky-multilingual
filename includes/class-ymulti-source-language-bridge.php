<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Read-only source-language discovery for coexistence/migration.
 *
 * When an established multilingual plugin already owns the live site, YMulti
 * mirrors that source's active locale set into its internal translation scope.
 * Imported locales remain staged (not public in YMulti) while the source plugin
 * stays authoritative, so admin workspaces can inspect/import the full language
 * graph without creating competing frontend routes.
 */
final class SourceLanguageBridge {
  private const OPT = 'ymulti_source_language_bridge_v1';

  public static function init(): void {
    add_action('admin_init', [__CLASS__, 'maybe_sync'], 3);
  }

  public static function maybe_sync(): void {
    if (!current_user_can('manage_options')) return;
    if (!self::wpml_active()) return;

    $source = self::discover_wpml();
    if (!$source['locales']) return;

    $fingerprint = sha1(wp_json_encode([$source['default'], $source['locales']]));
    $state = get_option(self::OPT, []);
    if (!is_array($state)) $state = [];
    if (($state['source'] ?? '') === 'wpml' && ($state['fingerprint'] ?? '') === $fingerprint) return;

    self::apply_source_locales('wpml', $source['default'], $source['locales'], $fingerprint);
  }

  /** Explicit migration hook; currently WPML has the source-locale bridge. */
  public static function sync_source(string $source): void {
    if ($source !== 'wpml' || !self::wpml_active()) return;
    $discovered = self::discover_wpml();
    if (!$discovered['locales']) return;
    $fingerprint = sha1(wp_json_encode([$discovered['default'], $discovered['locales']]));
    self::apply_source_locales('wpml', $discovered['default'], $discovered['locales'], $fingerprint);
  }

  private static function wpml_active(): bool {
    if (class_exists('Yablonsky\\Multilingual\\RuntimeAuthority')) return RuntimeAuthority::wpml_active();
    return defined('ICL_SITEPRESS_VERSION') || class_exists('SitePress') || has_filter('wpml_active_languages');
  }

  /** @return array{default:string,locales:string[]} */
  private static function discover_wpml(): array {
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML public hook contract.
    $rows = apply_filters('wpml_active_languages', null, 'skip_missing=0&orderby=id&order=asc');
    if (!is_array($rows)) $rows = [];

    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML public hook contract.
    $default_raw = apply_filters('wpml_default_language', null);
    $default_raw = is_string($default_raw) ? sanitize_text_field($default_raw) : '';
    if ($default_raw === '') {
      $settings = get_option('icl_sitepress_settings', []);
      if (is_array($settings)) $default_raw = sanitize_text_field((string) ($settings['default_language'] ?? ''));
    }
    $mapped = [];
    $source_to_ymulti = [];

    foreach ($rows as $key => $row) {
      if (!is_array($row)) continue;
      $source_code = sanitize_text_field((string) ($row['language_code'] ?? $row['code'] ?? $key));
      if ($source_code === '') continue;
      $wp_locale = sanitize_text_field((string) ($row['default_locale'] ?? $row['locale'] ?? ''));
      if ($wp_locale === '') $wp_locale = self::wpml_locale_for_code($source_code);
      $ymulti = self::map_source_locale($source_code, $wp_locale);
      if ($ymulti === '') continue;
      $source_to_ymulti[$source_code] = $ymulti;
      if (!in_array($ymulti, $mapped, true)) $mapped[] = $ymulti;
    }

    // Some WPML installations expose the active-language filter late or through
    // a reduced payload. Fall back to WPML's own active-language table, never
    // the historical translation relation set.
    if (!$mapped) {
      global $wpdb;
      $table = $wpdb->prefix . 'icl_languages';
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration discovery.
      $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
      if ($exists) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Read-only migration discovery with an internal table name.
        $db_rows = $wpdb->get_results('SELECT code, default_locale FROM ' . esc_sql($table) . ' WHERE active = 1 ORDER BY id ASC');
        if (is_array($db_rows)) foreach ($db_rows as $db_row) {
          $source_code = sanitize_text_field((string) ($db_row->code ?? ''));
          if ($source_code === '') continue;
          $wp_locale = sanitize_text_field((string) ($db_row->default_locale ?? ''));
          $ymulti = self::map_source_locale($source_code, $wp_locale !== '' ? $wp_locale : self::wpml_locale_for_code($source_code));
          if ($ymulti === '') continue;
          $source_to_ymulti[$source_code] = $ymulti;
          if (!in_array($ymulti, $mapped, true)) $mapped[] = $ymulti;
        }
      }
    }

    $default = isset($source_to_ymulti[$default_raw]) ? $source_to_ymulti[$default_raw] : '';
    if ($default === '' && $default_raw !== '') $default = self::map_source_locale($default_raw, self::wpml_locale_for_code($default_raw));
    if ($default === '' && $mapped) $default = $mapped[0];

    if ($default !== '') {
      $mapped = array_values(array_unique(array_merge([$default], $mapped)));
    }

    return ['default' => $default, 'locales' => $mapped];
  }

  private static function wpml_locale_for_code(string $source_code): string {
    global $sitepress, $wpdb;
    if (is_object($sitepress) && method_exists($sitepress, 'get_locale')) {
      $locale = $sitepress->get_locale($source_code);
      if (is_string($locale) && $locale !== '') return $locale;
    }

    $table = $wpdb->prefix . 'icl_locale_map';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration discovery.
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    if (!$exists) return '';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only migration discovery.
    $locale = $wpdb->get_var($wpdb->prepare('SELECT locale FROM ' . esc_sql($table) . ' WHERE code = %s LIMIT 1', $source_code));
    return is_string($locale) ? $locale : '';
  }

  private static function map_source_locale(string $source_code, string $wp_locale): string {
    $catalog = Languages::catalog();
    if ($wp_locale !== '') {
      $mapped = Languages::site_locale_to_content_locale($wp_locale);
      if (isset($catalog[$mapped]) && empty($catalog[$mapped]['legacy'])) return $mapped;
    }

    $normalized = str_replace('_', '-', trim($source_code));
    if (isset($catalog[$normalized]) && empty($catalog[$normalized]['legacy'])) return $normalized;

    $lower = strtolower($normalized);
    foreach ($catalog as $code => $row) {
      if (!empty($row['legacy'])) continue;
      if (strtolower((string) $code) === $lower || strtolower(Languages::code_to_slug((string) $code)) === $lower) return (string) $code;
    }

    $family = sanitize_key((string) strtok($normalized, '-'));
    if ($family === '') return '';
    foreach ($catalog as $code => $row) {
      if (!empty($row['legacy'])) continue;
      if (($row['family'] ?? '') === $family) return (string) $code;
    }
    return '';
  }

  /** @param string[] $source_locales */
  private static function apply_source_locales(string $source, string $source_default, array $source_locales, string $fingerprint): void {
    $catalog = Languages::catalog();
    $source_locales = array_values(array_unique(array_filter(array_map('strval', $source_locales), static function(string $code) use ($catalog): bool {
      return isset($catalog[$code]) && empty($catalog[$code]['legacy']);
    })));
    if (!$source_locales) return;

    Languages::ensure_options();
    $existing_active = Languages::active_codes();
    $existing_public = Languages::public_codes();
    $existing_default = Languages::default_code();

    $state = get_option(self::OPT, []);
    if (!is_array($state)) $state = [];
    $first_sync = empty($state['source']);
    $fresh_like = count($existing_active) === 1 && count($existing_public) === 1 && $existing_active[0] === $existing_default && $existing_public[0] === $existing_default;

    $default = $existing_default;
    if (($first_sync && $fresh_like) && $source_default !== '' && in_array($source_default, $source_locales, true)) {
      $default = $source_default;
    }

    // Source languages become available to every admin translation surface.
    // Never remove YMulti locales here; coexistence discovery is additive.
    $active = $first_sync && $fresh_like ? $source_locales : array_values(array_unique(array_merge($existing_active, $source_locales)));
    if (!in_array($default, $active, true)) array_unshift($active, $default);

    update_option(Languages::OPT_DEFAULT, $default, false);
    update_option(Languages::OPT_ACTIVE, $active, false);

    // Keep source-imported locales staged while another router is still active.
    // Existing explicit YMulti publication choices remain intact.
    $public = ($first_sync && $fresh_like) ? [$default] : array_values(array_intersect($existing_public, $active));
    if (!in_array($default, $public, true)) array_unshift($public, $default);
    update_option(Languages::OPT_PUBLIC, array_values(array_unique($public)), false);

    update_option(self::OPT, [
      'source' => $source,
      'fingerprint' => $fingerprint,
      'source_default' => $source_default,
      'source_locales' => $source_locales,
      'active_locales' => $active,
      'synced_at' => gmdate('c'),
    ], false);
    Languages::flush_request_cache();
  }
}
