<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Single-owner arbitration for public multilingual runtime surfaces.
 *
 * YMulti remains fully available as an admin/workspace product while an
 * incumbent multilingual runtime (currently WPML/Polylang) keeps temporary
 * ownership of public routing during coexistence and migration. This class is
 * intentionally read-only toward incumbent providers.
 */
final class RuntimeAuthority {
  public const MODE_STANDALONE = 'standalone';
  public const MODE_BRIDGE = 'bridge';
  public const MODE_SAFE = 'safe';

  /** @var string[]|null */
  private static ?array $incumbents = null;
  /** @var array<string,mixed>|null */
  private static ?array $request_identity = null;
  /** @var array<string,array<string,mixed>>|null */
  private static ?array $wpml_languages = null;
  /** @var array<string,int> */
  private static array $translated_post_ids = [];
  /** @var array<string,int> */
  private static array $translated_term_ids = [];
  /** @var array<string,string> */
  private static array $effective_object_languages = [];
  private static ?bool $wpml_translation_table_available = null;
  /** @var string[] Request-local Polylang slugs, scoped to the current blog. */
  private static array $polylang_codes = [];
  private static ?int $polylang_codes_blog_id = null;
  /** @var array<string,string>|null Canonical YMulti locale => WPML code. */
  private static ?array $wpml_reverse_codes = null;
  /** @var array<string,string>|null Canonical YMulti locale => Polylang slug. */
  private static ?array $polylang_reverse_codes = null;

  public static function reset(): void {
    self::$incumbents = null;
    self::$request_identity = null;
    self::$wpml_languages = null;
    self::$translated_post_ids = [];
    self::$translated_term_ids = [];
    self::$effective_object_languages = [];
    self::$wpml_translation_table_available = null;
    self::$polylang_codes = [];
    self::$polylang_codes_blog_id = null;
    self::$wpml_reverse_codes = null;
    self::$polylang_reverse_codes = null;
    if (class_exists('Yablonsky\Multilingual\EffectiveRelations', false)) EffectiveRelations::reset();
  }

  /**
   * WordPress Multisite may read multiple sites within a single PHP request.
   * Request caches keyed only by post ID, locale or option name cannot survive
   * switch_to_blog()/restore_current_blog(): those IDs are site-local.
   *
   * Only in-memory projections are reset here. This hook NEVER mutates
   * options, translation data, rewrite rules or a database schema.
   */
  public static function on_switch_blog(int $new_blog_id, int $previous_blog_id, string $context): void {
    unset($new_blog_id, $previous_blog_id, $context);
    Languages::flush_request_cache();
    self::reset();
    // Additional lazy-loaded modules may not exist yet during an early switch.
    foreach ([
      'LocaleRuntime' => 'reset',
      'RequestContext' => 'reset',
      'Router' => 'reset_request_cache',
      'Translations' => 'reset_request_cache',
      'TermTranslations' => 'reset_request_cache',
      'Urls' => 'clear_request_cache',
      'StringTranslations' => 'reset_request_cache',
      'CommerceStrings' => 'reset_request_cache',
      'SiteCoverage' => 'reset',
      'RoutingModes' => 'reset_request_cache',
      'LocalizedBases' => 'reset_request_cache',
      'MultiCurrency' => 'reset_request_cache',
      'FXRates' => 'reset_request_cache',
    ] as $short_name => $method) {
      $class = __NAMESPACE__ . '\\' . $short_name;
      if (class_exists($class, false)) $class::$method();
    }
  }

  /** @return string[] */
  public static function incumbent_providers(): array {
    if (self::$incumbents !== null) return self::$incumbents;

    $providers = [];
    if (self::wpml_active()) $providers[] = 'wpml';
    if (self::polylang_active()) $providers[] = 'polylang';
    self::$incumbents = array_values(array_unique($providers));
    return self::$incumbents;
  }

  public static function mode(): string {
    if (class_exists('Yablonsky\\Multilingual\\SafeMode') && SafeMode::enabled()) return self::MODE_SAFE;
    return self::incumbent_providers() ? self::MODE_BRIDGE : self::MODE_STANDALONE;
  }

  public static function ymulti_owns_public_runtime(): bool {
    return self::mode() === self::MODE_STANDALONE;
  }

  public static function workspace_active(): bool {
    return true;
  }

  public static function provider(): string {
    $providers = self::incumbent_providers();
    if (count($providers) === 1) return (string) $providers[0];
    if (count($providers) > 1) return 'multiple';
    return 'ymulti';
  }

  public static function public_owner(): string {
    if (self::ymulti_owns_public_runtime()) return 'ymulti';
    $provider = self::provider();
    return $provider === 'ymulti' ? 'none' : $provider;
  }

  public static function default_language(): string {
    if (self::public_owner() === 'wpml') {
      // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML public hook contract.
      $raw = apply_filters('wpml_default_language', null);
      $raw = is_string($raw) ? sanitize_text_field($raw) : '';
      if ($raw === '') {
        $settings = get_option('icl_sitepress_settings', []);
        if (is_array($settings)) $raw = sanitize_text_field((string) ($settings['default_language'] ?? ''));
      }
      $mapped = self::map_wpml_code_to_ymulti($raw);
      if ($mapped !== '') return $mapped;
    }
    if (self::public_owner() === 'polylang' && function_exists('pll_default_language')) {
      // Public language identity is owned by Polylang, not YMulti staging.
      $raw = pll_default_language('slug');
      $mapped = is_string($raw) ? self::map_generic_code_to_ymulti($raw, '') : '';
      if ($mapped !== '') return $mapped;
    }
    return class_exists('Yablonsky\Multilingual\Languages') ? Languages::default_code() : '';
  }

  /** @return array<string,mixed> */
  public static function status(): array {
    return [
      'mode' => self::mode(),
      'runtime_owner' => self::public_owner(),
      'provider' => self::provider(),
      'workspace_active' => true,
      'safe_mode' => class_exists('Yablonsky\\Multilingual\\SafeMode') && SafeMode::enabled(),
      'incumbents' => self::incumbent_providers(),
      'default_language' => self::default_language(),
    ];
  }

  /**
   * Read-only request identity for first-party consumers.
   *
   * @return array<string,mixed>
   */
  public static function request_identity(): array {
    if (self::$request_identity !== null) return self::$request_identity;

    $status = self::status();
    $language = '';
    $provider_language = '';
    $wp_locale = '';
    $canonical_url = '';
    $alternates = [];

    if (($status['runtime_owner'] ?? '') === 'wpml') {
      $provider_language = self::wpml_current_language();
      $language = self::map_wpml_code_to_ymulti($provider_language);
      $rows = self::wpml_language_rows();

      foreach ($rows as $source_code => $row) {
        $ymulti_code = self::map_wpml_code_to_ymulti((string) $source_code);
        $url = isset($row['url']) && is_string($row['url']) ? esc_url_raw((string) $row['url']) : '';
        if ($ymulti_code !== '' && $url !== '') $alternates[$ymulti_code] = $url;
        if ($source_code === $provider_language && $url !== '') $canonical_url = $url;
      }

      if ($provider_language !== '') {
        $row = $rows[$provider_language] ?? [];
        if (is_array($row)) {
          $wp_locale = sanitize_text_field((string) ($row['default_locale'] ?? $row['locale'] ?? ''));
        }
      }
      if ($wp_locale === '' && function_exists('get_locale')) $wp_locale = sanitize_text_field((string) get_locale());
    } elseif (($status['runtime_owner'] ?? '') === 'polylang') {
      if (function_exists('pll_current_language')) {
        $raw = pll_current_language('slug');
        $provider_language = is_string($raw) ? sanitize_text_field($raw) : '';
        $language = self::map_generic_code_to_ymulti($provider_language, function_exists('get_locale') ? (string) get_locale() : '');
      }
      if (function_exists('get_locale')) $wp_locale = sanitize_text_field((string) get_locale());
    } elseif (($status['runtime_owner'] ?? '') === 'ymulti') {
      if (class_exists('Yablonsky\\Multilingual\\Router')) $language = Router::current_lang();
      if ($language === '' && class_exists('Yablonsky\\Multilingual\\Languages')) $language = Languages::default_code();
      if (class_exists('Yablonsky\\Multilingual\\Languages')) {
        $catalog = Languages::catalog();
        if (isset($catalog[$language]['wp_locale'])) $wp_locale = sanitize_text_field((string) $catalog[$language]['wp_locale']);
      }
    }

    if ($canonical_url === '') $canonical_url = self::current_public_url();
    if ($language === '' && class_exists('Yablonsky\\Multilingual\\Languages')) $language = Languages::default_code();

    $cache_key = ($status['runtime_owner'] ?? 'none') . ':' . ($provider_language !== '' ? $provider_language : $language);
    self::$request_identity = array_merge($status, [
      'language' => $language,
      'provider_language' => $provider_language,
      'wp_locale' => $wp_locale,
      'canonical_url' => $canonical_url,
      'alternate_urls' => $alternates,
      'cache_namespace' => sanitize_key(str_replace([':', '-'], '_', $cache_key)),
    ]);
    return self::$request_identity;
  }

  /** @return string[] */
  public static function public_language_codes(): array {
    // No single public routing owner: do not expose staged YMulti locales to
    // YSEO as published alternatives in safe mode or competing-plugin mode.
    if (self::mode() === self::MODE_SAFE || self::public_owner() === 'multiple') return [];
    if (self::public_owner() === 'wpml') {
      $out = [];
      foreach (array_keys(self::wpml_language_rows()) as $source_code) {
        $mapped = self::map_wpml_code_to_ymulti((string) $source_code);
        if ($mapped !== '' && !in_array($mapped, $out, true)) $out[] = $mapped;
      }
      return $out;
    }
    if (self::public_owner() === 'polylang') {
      // Admin-only source-language sync need not have run on a visitor request.
      // Use the incumbent public catalog, or fail closed if its API is absent.
      $codes = self::polylang_provider_codes();
      $out = [];
      foreach ($codes as $code) {
        if (!is_string($code)) continue;
        $mapped = self::map_generic_code_to_ymulti($code, '');
        if ($mapped !== '' && !in_array($mapped, $out, true)) $out[] = $mapped;
      }
      return $out;
    }
    return class_exists('Yablonsky\\Multilingual\\Languages') ? Languages::public_codes() : [];
  }


  public static function wpml_translation_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'icl_translations';
  }

  public static function wpml_translation_table_available(): bool {
    if (self::$wpml_translation_table_available !== null) return self::$wpml_translation_table_available;
    if (self::public_owner() !== 'wpml') {
      self::$wpml_translation_table_available = false;
      return false;
    }
    global $wpdb;
    $table = self::wpml_translation_table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only incumbent catalog capability probe during bridge mode.
    self::$wpml_translation_table_available = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    return self::$wpml_translation_table_available;
  }

  /**
   * Map a YMulti locale to the current provider code without changing provider data.
   */
  /**
   * Return WPML post IDs for one effective source locale without materializing
   * YMulti identity. Used by bounded admin/worker planning surfaces that need
   * exact provider-aware source selection before a native migration exists.
   *
   * @param string[] $post_types
   * @param string[] $statuses
   * @return int[]
   */
  public static function wpml_post_ids_for_language(string $language, array $post_types, array $statuses, int $limit, int $offset = 0, string $order = 'ASC'): array {
    if (!self::wpml_translation_table_available() || $language === '') return [];
    $provider_code = self::provider_code_for_language($language);
    if ($provider_code === '') return [];

    $post_types = array_values(array_unique(array_filter(array_map('sanitize_key', $post_types))));
    $statuses = array_values(array_unique(array_filter(array_map('sanitize_key', $statuses))));
    if (!$post_types || !$statuses) return [];
    $limit = max(1, min(5000, $limit));
    $offset = max(0, $offset);
    $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

    global $wpdb;
    $table = esc_sql(self::wpml_translation_table());
    $element_types = array_map(static fn(string $type): string => 'post_' . $type, $post_types);
    $sql = 'SELECT p.ID FROM ' . $table . ' rel INNER JOIN ' . $wpdb->posts . ' p ON p.ID = rel.element_id '
      . 'WHERE rel.language_code = %s AND rel.element_type IN (' . implode(',', array_fill(0, count($element_types), '%s')) . ') '
      . 'AND p.post_type IN (' . implode(',', array_fill(0, count($post_types), '%s')) . ') '
      . 'AND p.post_status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ') '
      . 'ORDER BY p.ID ' . $order . ' LIMIT %d OFFSET %d';
    $args = array_merge([$provider_code], $element_types, $post_types, $statuses, [$limit, $offset]);
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only incumbent source inventory; every dynamic value is represented by a prepare() placeholder and the order token is allow-listed above.
    $rows = $wpdb->get_col($wpdb->prepare($sql, $args));
    return is_array($rows) ? array_values(array_filter(array_map('absint', $rows))) : [];
  }

  /**
   * Return WPML taxonomy term IDs for one effective source locale. The WPML
   * translation table stores term_taxonomy_id for taxonomy elements, so the
   * join resolves that identity back to stable WordPress term IDs read-only.
   *
   * @return int[]
   */
  public static function wpml_term_ids_for_language(string $language, string $taxonomy, int $limit, int $offset = 0, string $order = 'ASC'): array {
    $taxonomy = sanitize_key($taxonomy);
    if (!self::wpml_translation_table_available() || $language === '' || $taxonomy === '') return [];
    $provider_code = self::provider_code_for_language($language);
    if ($provider_code === '') return [];
    $limit = max(1, min(5000, $limit));
    $offset = max(0, $offset);
    $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

    global $wpdb;
    $table = esc_sql(self::wpml_translation_table());
    $sql = 'SELECT tt.term_id FROM ' . $table . ' rel INNER JOIN ' . $wpdb->term_taxonomy . ' tt ON tt.term_taxonomy_id = rel.element_id '
      . 'WHERE rel.language_code = %s AND rel.element_type = %s AND tt.taxonomy = %s ORDER BY tt.term_id ' . $order . ' LIMIT %d OFFSET %d';
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only incumbent taxonomy source inventory; every dynamic value is prepared and the order token is allow-listed above.
    $rows = $wpdb->get_col($wpdb->prepare($sql, $provider_code, 'tax_' . $taxonomy, $taxonomy, $limit, $offset));
    return is_array($rows) ? array_values(array_filter(array_map('absint', $rows))) : [];
  }

  public static function provider_code_for_language(string $language): string {
    if ($language === '') return '';
    $owner = self::public_owner();
    if ($owner === 'wpml') return self::map_ymulti_to_wpml_code($language);
    if ($owner === 'polylang') return self::map_ymulti_to_polylang_code($language);
    return $language;
  }

  /**
   * Map an incumbent provider code back to the canonical YMulti locale.
   */
  public static function language_for_provider_code(string $provider_code): string {
    if ($provider_code === '') return '';
    $owner = self::public_owner();
    if ($owner === 'wpml') return self::map_wpml_code_to_ymulti($provider_code);
    if ($owner === 'polylang') return self::map_generic_code_to_ymulti($provider_code, '');
    return $provider_code;
  }

  /**
   * Effective language identity for one post during coexistence. Native explicit
   * identity wins; otherwise the incumbent relation graph is inspected read-only.
   */
  public static function effective_post_language(int $post_id): string {
    if ($post_id <= 0) return '';
    $owner = self::public_owner();
    $cache_key = 'post:' . $owner . ':' . $post_id;
    if (array_key_exists($cache_key, self::$effective_object_languages)) return self::$effective_object_languages[$cache_key];

    if (class_exists('Yablonsky\Multilingual\Translations')) {
      $assigned = Translations::assigned_post_language($post_id);
      if ($assigned !== null && $assigned !== '') {
        self::$effective_object_languages[$cache_key] = $assigned;
        return $assigned;
      }
    }

    if ($owner === 'polylang' && function_exists('pll_get_post_language')) {
      $code = pll_get_post_language($post_id, 'slug');
      $mapped = is_string($code) ? self::map_generic_code_to_ymulti($code, '') : '';
      if ($mapped !== '') {
        self::$effective_object_languages[$cache_key] = $mapped;
        return $mapped;
      }
    }

    if (in_array($owner, ['wpml', 'polylang'], true) && class_exists('Yablonsky\Multilingual\Languages')) {
      foreach (array_values(array_unique(array_merge(Languages::active_codes(), self::public_language_codes()))) as $language) {
        if (self::translated_post_id($post_id, (string) $language) === $post_id) {
          self::$effective_object_languages[$cache_key] = (string) $language;
          return (string) $language;
        }
      }
    }

    $fallback = class_exists('Yablonsky\Multilingual\Translations') ? Translations::get_post_language($post_id) : '';
    self::$effective_object_languages[$cache_key] = $fallback;
    return $fallback;
  }

  /**
   * Resolve one translated taxonomy term through the active runtime owner.
   * Incumbent lookups are read-only and request-cached.
   */
  public static function translated_term_id(int $term_id, string $taxonomy, string $language): int {
    $taxonomy = sanitize_key($taxonomy);
    if ($term_id <= 0 || $taxonomy === '' || $language === '') return 0;
    $owner = self::public_owner();
    $cache_key = $owner . ':' . $taxonomy . ':' . $term_id . ':' . $language;
    if (array_key_exists($cache_key, self::$translated_term_ids)) return self::$translated_term_ids[$cache_key];

    $target_id = 0;
    if ($owner === 'wpml') {
      $provider_code = self::map_ymulti_to_wpml_code($language);
      if ($provider_code !== '') {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML public hook contract.
        $resolved = apply_filters('wpml_object_id', $term_id, $taxonomy, false, $provider_code);
        $target_id = is_numeric($resolved) ? (int) $resolved : 0;
        if ($target_id > 0) {
          $term = get_term($target_id, $taxonomy);
          if (!($term instanceof \WP_Term)) $target_id = 0;
        }
      }
    } elseif ($owner === 'polylang' && function_exists('pll_get_term')) {
      $provider_code = self::map_ymulti_to_polylang_code($language);
      $resolved = $provider_code !== '' ? pll_get_term($term_id, $provider_code) : 0;
      $target_id = is_numeric($resolved) ? (int) $resolved : 0;
    } elseif ($owner === 'ymulti' && class_exists('Yablonsky\Multilingual\TermTranslations')) {
      $resolved = TermTranslations::get_translation($term_id, $taxonomy, $language);
      $target_id = is_numeric($resolved) ? (int) $resolved : 0;
      if ($target_id <= 0 && TermTranslations::get_term_language($term_id) === $language) $target_id = $term_id;
    }

    self::$translated_term_ids[$cache_key] = max(0, $target_id);
    return self::$translated_term_ids[$cache_key];
  }

  /** Effective language identity for one taxonomy term during coexistence. */
  public static function effective_term_language(int $term_id, string $taxonomy): string {
    $taxonomy = sanitize_key($taxonomy);
    if ($term_id <= 0 || $taxonomy === '') return '';
    $owner = self::public_owner();
    $cache_key = 'term:' . $owner . ':' . $taxonomy . ':' . $term_id;
    if (array_key_exists($cache_key, self::$effective_object_languages)) return self::$effective_object_languages[$cache_key];

    if (class_exists('Yablonsky\Multilingual\TermTranslations')) {
      $assigned = TermTranslations::assigned_term_language($term_id);
      if ($assigned !== null && $assigned !== '') {
        self::$effective_object_languages[$cache_key] = $assigned;
        return $assigned;
      }
    }

    if ($owner === 'polylang' && function_exists('pll_get_term_language')) {
      $code = pll_get_term_language($term_id, 'slug');
      $mapped = is_string($code) ? self::map_generic_code_to_ymulti($code, '') : '';
      if ($mapped !== '') {
        self::$effective_object_languages[$cache_key] = $mapped;
        return $mapped;
      }
    }

    if (in_array($owner, ['wpml', 'polylang'], true) && class_exists('Yablonsky\Multilingual\Languages')) {
      foreach (array_values(array_unique(array_merge(Languages::active_codes(), self::public_language_codes()))) as $language) {
        if (self::translated_term_id($term_id, $taxonomy, (string) $language) === $term_id) {
          self::$effective_object_languages[$cache_key] = (string) $language;
          return (string) $language;
        }
      }
    }

    $fallback = class_exists('Yablonsky\Multilingual\TermTranslations') ? TermTranslations::get_term_language($term_id) : '';
    self::$effective_object_languages[$cache_key] = $fallback;
    return $fallback;
  }

  /**
   * Resolve one translated WordPress object through the current runtime owner.
   * Incumbent lookups are read-only and request-cached.
   */
  public static function translated_post_id(int $post_id, string $language): int {
    if ($post_id <= 0 || $language === '') return 0;
    $owner = self::public_owner();
    $cache_key = $owner . ':' . $post_id . ':' . $language;
    if (array_key_exists($cache_key, self::$translated_post_ids)) return self::$translated_post_ids[$cache_key];

    $target_id = 0;
    if ($owner === 'wpml') {
      $provider_code = self::map_ymulti_to_wpml_code($language);
      $post_type = get_post_type($post_id);
      if ($provider_code !== '' && is_string($post_type) && $post_type !== '') {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML public hook contract.
        $resolved = apply_filters('wpml_object_id', $post_id, $post_type, false, $provider_code);
        $target_id = is_numeric($resolved) ? (int) $resolved : 0;
        if ($target_id > 0 && get_post_type($target_id) !== $post_type) $target_id = 0;
      }
    } elseif ($owner === 'polylang' && function_exists('pll_get_post')) {
      $provider_code = self::map_ymulti_to_polylang_code($language);
      $resolved = $provider_code !== '' ? pll_get_post($post_id, $provider_code) : 0;
      $target_id = is_numeric($resolved) ? (int) $resolved : 0;
    } elseif ($owner === 'ymulti' && class_exists('Yablonsky\Multilingual\Translations')) {
      $resolved = Translations::get_translation($post_id, $language);
      $target_id = is_numeric($resolved) ? (int) $resolved : 0;
      if ($target_id <= 0 && Translations::get_post_language($post_id) === $language) $target_id = $post_id;
    }

    self::$translated_post_ids[$cache_key] = max(0, $target_id);
    return self::$translated_post_ids[$cache_key];
  }

  /**
   * Resolve a post URL through the active public runtime owner.
   * Read-only for incumbent providers.
   */
  public static function localized_post_url(int $post_id, string $language): string {
    if ($post_id <= 0 || $language === '') return '';

    if (in_array(self::public_owner(), ['wpml', 'polylang'], true)) {
      $target_id = self::translated_post_id($post_id, $language);
      if ($target_id <= 0) return '';
      $url = get_permalink($target_id);
      return is_string($url) ? $url : '';
    }

    if (self::public_owner() === 'ymulti' && class_exists('Yablonsky\Multilingual\Urls')) {
      return Urls::for_post($post_id, $language);
    }

    return '';
  }

  public static function wpml_active(): bool {
    if (defined('ICL_SITEPRESS_VERSION') || class_exists('SitePress') || has_filter('wpml_active_languages')) return true;
    return self::plugin_basename_active(['sitepress-multilingual-cms/sitepress.php']);
  }

  public static function polylang_active(): bool {
    if (defined('POLYLANG_VERSION') || function_exists('pll_current_language') || class_exists('PLL_Model')) return true;
    return self::plugin_basename_active(['polylang/polylang.php', 'polylang-pro/polylang.php']);
  }

  /** @param string[] $basenames */
  private static function plugin_basename_active(array $basenames): bool {
    $active = (array) get_option('active_plugins', []);
    foreach ($basenames as $basename) if (in_array($basename, $active, true)) return true;

    if (is_multisite()) {
      $network = (array) get_site_option('active_sitewide_plugins', []);
      foreach ($basenames as $basename) if (isset($network[$basename])) return true;
    }
    return false;
  }

  private static function wpml_current_language(): string {
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML public hook contract.
    $code = apply_filters('wpml_current_language', null);
    if (is_string($code) && $code !== '') return sanitize_text_field($code);
    global $sitepress;
    if (is_object($sitepress) && method_exists($sitepress, 'get_current_language')) {
      $code = $sitepress->get_current_language();
      if (is_string($code)) return sanitize_text_field($code);
    }
    return '';
  }

  /** @return array<string,array<string,mixed>> */
  private static function wpml_language_rows(): array {
    if (self::$wpml_languages !== null) return self::$wpml_languages;
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML public hook contract.
    $rows = apply_filters('wpml_active_languages', null, 'skip_missing=0&orderby=id&order=asc');
    if (!is_array($rows)) $rows = [];
    $out = [];
    foreach ($rows as $key => $row) {
      if (!is_array($row)) continue;
      $code = sanitize_text_field((string) ($row['language_code'] ?? $row['code'] ?? $key));
      if ($code === '') continue;
      $out[$code] = $row;
    }
    self::$wpml_languages = $out;
    return self::$wpml_languages;
  }

  private static function map_wpml_code_to_ymulti(string $source_code): string {
    if ($source_code === '' || !class_exists('Yablonsky\\Multilingual\\Languages')) return '';
    $rows = self::wpml_language_rows();
    $row = $rows[$source_code] ?? [];
    $wp_locale = is_array($row) ? sanitize_text_field((string) ($row['default_locale'] ?? $row['locale'] ?? '')) : '';
    return self::map_generic_code_to_ymulti($source_code, $wp_locale);
  }

  private static function map_ymulti_to_wpml_code(string $language): string {
    // Translation family resolution calls this for every post/locale pair.
    // Build once per request instead of re-scanning the provider's entire
    // locale catalog for every related object. First-match semantics remain.
    if (self::$wpml_reverse_codes === null) {
      $reverse = [];
      foreach (array_keys(self::wpml_language_rows()) as $source_code) {
        $canonical = self::map_wpml_code_to_ymulti((string) $source_code);
        if ($canonical !== '' && !isset($reverse[$canonical])) $reverse[$canonical] = (string) $source_code;
      }
      self::$wpml_reverse_codes = $reverse;
    }
    return self::$wpml_reverse_codes[$language] ?? '';
  }

  /** Read the incumbent catalog once per request/blog; no per-resource API scan. */
  private static function polylang_provider_codes(): array {
    $blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
    if (self::$polylang_codes_blog_id === $blog_id) return self::$polylang_codes;
    self::$polylang_codes_blog_id = $blog_id;
    self::$polylang_codes = [];
    self::$polylang_reverse_codes = null;
    if (!function_exists('pll_languages_list')) return [];
    $rows = pll_languages_list(['fields' => 'slug']);
    if (!is_array($rows)) return [];
    foreach ($rows as $code) {
      if (is_string($code) && $code !== '') self::$polylang_codes[] = $code;
    }
    self::$polylang_codes = array_values(array_unique(self::$polylang_codes));
    return self::$polylang_codes;
  }

  /** Map canonical locale to the currently configured Polylang slug. */
  private static function map_ymulti_to_polylang_code(string $language): string {
    // Reuse the request-local catalog and a one-time reverse lookup. In native
    // standalone mode this adapter is never called.
    $codes = self::polylang_provider_codes();
    if (self::$polylang_reverse_codes === null) {
      $reverse = [];
      foreach ($codes as $code) {
        if (!is_string($code)) continue;
        $canonical = self::map_generic_code_to_ymulti($code, '');
        if ($canonical !== '' && !isset($reverse[$canonical])) $reverse[$canonical] = $code;
      }
      self::$polylang_reverse_codes = $reverse;
    }
    return self::$polylang_reverse_codes[$language] ?? '';
  }

  private static function map_generic_code_to_ymulti(string $source_code, string $wp_locale): string {
    if (!class_exists('Yablonsky\\Multilingual\\Languages')) return '';
    $catalog = Languages::catalog();
    if ($wp_locale !== '') {
      $mapped = Languages::site_locale_to_content_locale($wp_locale);
      if (isset($catalog[$mapped]) && empty($catalog[$mapped]['legacy'])) return $mapped;
    }

    $normalized = str_replace('_', '-', trim($source_code));
    // Legacy/script aliases carry a deliberate target in the language catalog.
    // Resolve that target BEFORE family fallback: `zh-Hant` means Traditional
    // Chinese (`zh-TW`), not the first Chinese variant (`zh-CN`).
    // This also preserves other product-defined legacy aliases without
    // making a regional/script guess at runtime.
    foreach ($catalog as $code => $row) {
      if (strcasecmp((string) $code, $normalized) !== 0) continue;
      if (empty($row['legacy'])) return (string) $code;
      $fallback = isset($row['fallback']) && is_string($row['fallback']) ? $row['fallback'] : '';
      if ($fallback !== '' && isset($catalog[$fallback]) && empty($catalog[$fallback]['legacy'])) return $fallback;
      // Generic legacy family labels (en/fr/es/pt) intentionally have no
      // explicit successor: retain the established canonical-family fallback.
      break;
    }
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

  private static function current_public_url(): string {
    if ((defined('WP_ADMIN') && WP_ADMIN) || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI)) return '';
    $home = (string) get_option('home', '');
    $parts = $home !== '' ? wp_parse_url($home) : false;
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
    $path = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
    $path_parts = wp_parse_url($path);
    $path_only = is_array($path_parts) && isset($path_parts['path']) ? (string) $path_parts['path'] : '/';
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    return esc_url_raw((string) $parts['scheme'] . '://' . (string) $parts['host'] . $port . '/' . ltrim($path_only, '/'));
  }
}
