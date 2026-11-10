<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

final class Languages {
  public const TAX = 'ypm_language';
  public const OPT_DEFAULT = 'ypm_default_language';
  public const OPT_ACTIVE  = 'ypm_active_languages';
  public const OPT_PUBLIC  = 'ymulti_public_languages_v1';

  /** Request-local projections: language identity is read frequently on every multilingual request. */
  private static ?array $catalog_cache = null;
  /** Own-selector sort is request-invariant until language caches are flushed. */
  private static ?array $selection_catalog_cache = null;
  private static ?string $default_cache = null;
  private static ?array $active_cache = null;
  private static ?array $public_cache = null;

  public static function flush_request_cache(): void {
    self::$catalog_cache = null;
    self::$selection_catalog_cache = null;
    self::$default_cache = null;
    self::$active_cache = null;
    self::$public_cache = null;
  }

  /**
   * Built-in language/locale catalog.
   *
   * The 36-family core is the product baseline; two supplemental families extend it without changing the schema limit.
   * Locale variants are separate catalog entries that point to one family.
   */
  public static function catalog(): array {
    if (self::$catalog_cache !== null) return self::$catalog_cache;
    $built = [
      // English family. Generic `en` is retained only for legacy compatibility.
      'en'    => self::row('en', 'en', 'English', 'English', '', '', 'en', 'en', false, '', true),
      'en-US' => self::row('en', 'en-US', 'English (United States)', 'English', 'United States', 'en_US', 'en-US', 'en-us', false, 'en'),
      'en-GB' => self::row('en', 'en-GB', 'English (United Kingdom)', 'English', 'United Kingdom', 'en_GB', 'en-GB', 'en-gb', false, 'en'),
      'en-CA' => self::row('en', 'en-CA', 'English (Canada)', 'English', 'Canada', 'en_CA', 'en-CA', 'en-ca', false, 'en'),
      'en-AU' => self::row('en', 'en-AU', 'English (Australia)', 'English', 'Australia', 'en_AU', 'en-AU', 'en-au', false, 'en'),

      // Canonical 31 language families and selected market variants.
      'ar' => self::row('ar', 'ar', 'Arabic', 'العربية', '', 'ar', 'ar', 'ar', true),
      'bg' => self::row('bg', 'bg', 'Bulgarian', 'Български', '', 'bg_BG', 'bg', 'bg'),
      'zh-CN' => self::row('zh', 'zh-CN', 'Chinese (China, Simplified)', '简体中文', 'China', 'zh_CN', 'zh-CN', 'zh-cn'),
      'zh-TW' => self::row('zh', 'zh-TW', 'Chinese (Taiwan, Traditional)', '繁體中文', 'Taiwan', 'zh_TW', 'zh-TW', 'zh-tw'),
      'zh-HK' => self::row('zh', 'zh-HK', 'Chinese (Hong Kong)', '繁體中文', 'Hong Kong', 'zh_HK', 'zh-HK', 'zh-hk'),
      'zh-Hans' => self::row('zh', 'zh-Hans', 'Chinese (Simplified, legacy script code)', '简体中文', '', '', 'zh-Hans', 'zh-hans', false, 'zh-CN', true),
      'zh-Hant' => self::row('zh', 'zh-Hant', 'Chinese (Traditional, legacy script code)', '繁體中文', '', '', 'zh-Hant', 'zh-hant', false, 'zh-TW', true),
      'hr' => self::row('hr', 'hr', 'Croatian', 'Hrvatski', '', 'hr', 'hr', 'hr'),
      'cs' => self::row('cs', 'cs', 'Czech', 'Čeština', '', 'cs_CZ', 'cs', 'cs'),
      'da' => self::row('da', 'da', 'Danish', 'Dansk', '', 'da_DK', 'da', 'da'),
      'nl' => self::row('nl', 'nl', 'Dutch', 'Nederlands', '', 'nl_NL', 'nl', 'nl'),
      'et' => self::row('et', 'et', 'Estonian', 'Eesti', '', 'et', 'et', 'et'),
      'fi' => self::row('fi', 'fi', 'Finnish', 'Suomi', '', 'fi', 'fi', 'fi'),
      'fr' => self::row('fr', 'fr', 'French', 'Français', '', '', 'fr', 'fr', false, '', true),
      'fr-FR' => self::row('fr', 'fr-FR', 'French (France)', 'Français', 'France', 'fr_FR', 'fr-FR', 'fr-fr', false, 'fr'),
      'fr-CA' => self::row('fr', 'fr-CA', 'French (Canada)', 'Français', 'Canada', 'fr_CA', 'fr-CA', 'fr-ca', false, 'fr'),
      'de' => self::row('de', 'de', 'German', 'Deutsch', '', 'de_DE', 'de', 'de'),
      'el' => self::row('el', 'el', 'Greek', 'Ελληνικά', '', 'el', 'el', 'el'),
      'hu' => self::row('hu', 'hu', 'Hungarian', 'Magyar', '', 'hu_HU', 'hu', 'hu'),
      'id' => self::row('id', 'id', 'Indonesian', 'Bahasa Indonesia', '', 'id_ID', 'id', 'id'),
      'ga' => self::row('ga', 'ga', 'Irish', 'Gaeilge', '', 'ga', 'ga', 'ga'),
      'it' => self::row('it', 'it', 'Italian', 'Italiano', '', 'it_IT', 'it', 'it'),
      'ja' => self::row('ja', 'ja', 'Japanese', '日本語', '', 'ja', 'ja', 'ja'),
      'lv' => self::row('lv', 'lv', 'Latvian', 'Latviešu', '', 'lv', 'lv', 'lv'),
      'lt' => self::row('lt', 'lt', 'Lithuanian', 'Lietuvių', '', 'lt_LT', 'lt', 'lt'),
      'ms' => self::row('ms', 'ms', 'Malay', 'Bahasa Melayu', '', 'ms_MY', 'ms', 'ms'),
      'mt' => self::row('mt', 'mt', 'Maltese', 'Malti', '', 'mlt', 'mt', 'mt'),
      'pl' => self::row('pl', 'pl', 'Polish', 'Polski', '', 'pl_PL', 'pl', 'pl'),
      'pt' => self::row('pt', 'pt', 'Portuguese', 'Português', '', '', 'pt', 'pt', false, '', true),
      'pt-PT' => self::row('pt', 'pt-PT', 'Portuguese (Portugal)', 'Português', 'Portugal', 'pt_PT', 'pt-PT', 'pt-pt', false, 'pt'),
      'pt-BR' => self::row('pt', 'pt-BR', 'Portuguese (Brazil)', 'Português', 'Brazil', 'pt_BR', 'pt-BR', 'pt-br', false, 'pt'),
      'ro' => self::row('ro', 'ro', 'Romanian', 'Română', '', 'ro_RO', 'ro', 'ro'),
      'ru' => self::row('ru', 'ru', 'Russian', 'Русский', '', 'ru_RU', 'ru', 'ru'),
      'sk' => self::row('sk', 'sk', 'Slovak', 'Slovenčina', '', 'sk_SK', 'sk', 'sk'),
      'sl' => self::row('sl', 'sl', 'Slovenian', 'Slovenščina', '', 'sl_SI', 'sl', 'sl'),
      'es' => self::row('es', 'es', 'Spanish', 'Español', '', '', 'es', 'es', false, '', true),
      'es-ES' => self::row('es', 'es-ES', 'Spanish (Spain)', 'Español', 'Spain', 'es_ES', 'es-ES', 'es-es', false, 'es'),
      'sv' => self::row('sv', 'sv', 'Swedish', 'Svenska', '', 'sv_SE', 'sv', 'sv'),
      'uk' => self::row('uk', 'uk', 'Ukrainian', 'Українська', '', 'uk', 'uk', 'uk'),

      // Useful additional families. The canonical 31 are a minimum, not a whitelist.
      'he' => self::row('he', 'he', 'Hebrew', 'עברית', '', 'he_IL', 'he', 'he', true),
      'hi' => self::row('hi', 'hi', 'Hindi', 'हिन्दी', '', 'hi_IN', 'hi', 'hi'),
      'ko' => self::row('ko', 'ko', 'Korean', '한국어', '', 'ko_KR', 'ko', 'ko'),
      'no' => self::row('no', 'no', 'Norwegian', 'Norsk', '', 'nb_NO', 'no', 'no'),
      'th' => self::row('th', 'th', 'Thai', 'ไทย', '', 'th', 'th', 'th'),
      'tr' => self::row('tr', 'tr', 'Turkish', 'Türkçe', '', 'tr_TR', 'tr', 'tr'),
      'vi' => self::row('vi', 'vi', 'Vietnamese', 'Tiếng Việt', '', 'vi', 'vi', 'vi'),
    ];

    $custom = get_option('ypm_custom_languages');
    if (is_array($custom)) {
      foreach ($custom as $code => $row) {
        if (!is_string($code) || !is_array($row)) continue;
        $code = trim($code);
        if ($code === '' || isset($built[$code])) continue;
        $family = isset($row['family']) && is_string($row['family']) && $row['family'] !== ''
          ? sanitize_key($row['family'])
          : sanitize_key(strtok(str_replace('_', '-', $code), '-'));
        $name = isset($row['name']) ? (string) $row['name'] : $code;
        $native = isset($row['native_name']) ? (string) $row['native_name'] : $name;
        $built[$code] = self::row(
          $family,
          $code,
          $name,
          $native,
          isset($row['market_name']) ? (string) $row['market_name'] : '',
          isset($row['wp_locale']) ? (string) $row['wp_locale'] : '',
          isset($row['hreflang']) ? (string) $row['hreflang'] : $code,
          isset($row['slug']) ? (string) $row['slug'] : self::normalize_slug($code),
          !empty($row['rtl']),
          isset($row['fallback']) ? (string) $row['fallback'] : ''
        );
      }
    }

    self::$catalog_cache = $built;
    return self::$catalog_cache;
  }


  /**
   * Popularity-first presentation for YMulti-owned language selectors only.
   * Ranking: total L1+L2 speaker estimates, 2026 Ethnologue-derived ordering;
   * country/script variants remain next to their language family. This MUST
   * NOT reorder the canonical catalog used by routing or WordPress selectors.
   */
  public static function selection_catalog(): array {
    if (self::$selection_catalog_cache !== null) return self::$selection_catalog_cache;
    $catalog = self::catalog();
    $families = [
      'en', 'zh', 'hi', 'es', 'ar', 'fr', 'pt', 'id', 'ru', 'de',
      'ja', 'vi', 'tr', 'ko', 'th', 'it', 'pl', 'uk', 'ms', 'ro',
      'nl', 'el', 'hu', 'cs', 'sv', 'bg', 'da', 'fi', 'sk', 'no',
      'hr', 'lt', 'sl', 'lv', 'et', 'he', 'ga', 'mt',
    ];
    $rank = array_flip($families);
    $variants = ['en-US', 'en-GB', 'en-CA', 'en-AU', 'zh-CN', 'zh-TW', 'zh-HK'];
    $variant_rank = array_flip($variants);
    $codes = array_keys($catalog);
    usort($codes, static function (string $left, string $right) use ($catalog, $rank, $variant_rank): int {
      $l = $catalog[$left];
      $r = $catalog[$right];
      $legacy_cmp = (int) !empty($l['legacy']) <=> (int) !empty($r['legacy']);
      if ($legacy_cmp !== 0) return $legacy_cmp;
      $la = $rank[(string) ($l['family'] ?? '')] ?? 999;
      $ra = $rank[(string) ($r['family'] ?? '')] ?? 999;
      if ($la !== $ra) return $la <=> $ra;
      $lv = $variant_rank[$left] ?? 100;
      $rv = $variant_rank[$right] ?? 100;
      if ($lv !== $rv) return $lv <=> $rv;
      return strnatcasecmp($left, $right);
    });
    $ordered = [];
    foreach ($codes as $code) $ordered[$code] = $catalog[$code];
    self::$selection_catalog_cache = $ordered;
    return self::$selection_catalog_cache;
  }

  /** Preserve enabled/visible membership while ordering YMulti's own selector options. */
  public static function selection_codes(array $codes): array {
    $wanted = array_fill_keys(array_map('strval', $codes), true);
    $out = [];
    foreach (self::selection_catalog() as $code => $unused) {
      if (isset($wanted[$code])) {
        $out[] = $code;
        unset($wanted[$code]);
      }
    }
    // Do not hide saved/custom locale codes that are not in the current
    // catalog. Keep their existing relative order after ranked languages.
    foreach ($codes as $code) {
      $code = (string) $code;
      if (isset($wanted[$code])) {
        $out[] = $code;
        unset($wanted[$code]);
      }
    }
    return $out;
  }

  private static function row(
    string $family,
    string $locale,
    string $english_name,
    string $native_name,
    string $market_name,
    string $wp_locale,
    string $hreflang,
    string $slug,
    bool $rtl = false,
    string $fallback = '',
    bool $legacy = false
  ): array {
    return [
      'family' => $family,
      'locale' => $locale,
      'name' => $english_name,
      'english_name' => $english_name,
      'native_name' => $native_name,
      'market_name' => $market_name,
      'wp_locale' => $wp_locale,
      'hreflang' => $hreflang,
      'slug' => $slug,
      'direction' => $rtl ? 'rtl' : 'ltr',
      'rtl' => $rtl,
      'fallback' => $fallback,
      'legacy' => $legacy,
    ];
  }

  public static function init(): void {
    add_action('init', [__CLASS__, 'register_taxonomy'], 0);
  }

  public static function ensure_options(): void {
    if (get_option(self::OPT_DEFAULT) === false) {
      $initial = self::site_locale_to_content_locale((string) get_locale());
      add_option(self::OPT_DEFAULT, $initial, '', false);
    }
    if (get_option(self::OPT_ACTIVE) === false) {
      $initial = (string) get_option(self::OPT_DEFAULT, self::site_locale_to_content_locale((string) get_locale()));
      add_option(self::OPT_ACTIVE, [$initial], '', false);
    }
    if (get_option('ypm_custom_languages') === false) add_option('ypm_custom_languages', [], '', false);
    if (get_option(self::OPT_PUBLIC) === false) {
      $active = self::active_codes();
      add_option(self::OPT_PUBLIC, $active, '', false);
    }
  }

  public static function site_locale_to_content_locale(string $wp_locale): string {
    $wp_locale = trim($wp_locale);
    if ($wp_locale === '') return 'en-US';
    // Legacy WordPress Maltese locale alias (official WordPress locale is mlt).
    if (strcasecmp($wp_locale, 'mt_MT') === 0) return 'mt';

    $catalog = self::catalog();
    foreach ($catalog as $code => $row) {
      if (!empty($row['legacy'])) continue;
      if (!empty($row['wp_locale']) && strcasecmp((string) $row['wp_locale'], $wp_locale) === 0) {
        return (string) $code;
      }
    }

    $bcp47 = str_replace('_', '-', $wp_locale);
    // Resolve registered legacy/script aliases using their explicit successor.
    // In particular zh_Hant must never silently fall through to zh-CN.
    foreach ($catalog as $code => $row) {
      if (strcasecmp((string) $code, $bcp47) !== 0) continue;
      if (empty($row['legacy'])) return (string) $code;
      $fallback = isset($row['fallback']) && is_string($row['fallback']) ? $row['fallback'] : '';
      if ($fallback !== '' && isset($catalog[$fallback]) && empty($catalog[$fallback]['legacy'])) return $fallback;
      break;
    }

    $family = strtolower((string) strtok($bcp47, '-'));
    if (isset($catalog[$family]) && empty($catalog[$family]['legacy'])) return $family;

    foreach ($catalog as $code => $row) {
      if (($row['family'] ?? '') === $family && empty($row['legacy'])) return (string) $code;
    }

    return 'en-US';
  }

  public static function default_code(): string {
    if (self::$default_cache !== null) return self::$default_cache;
    $fallback = self::site_locale_to_content_locale((string) get_locale());
    $code = (string) get_option(self::OPT_DEFAULT, $fallback);
    self::$default_cache = $code !== '' ? $code : $fallback;
    return self::$default_cache;
  }

  public static function active_codes(): array {
    if (self::$active_cache !== null) return self::$active_cache;
    $fallback = self::default_code();
    $val = get_option(self::OPT_ACTIVE, [$fallback]);
    if (!is_array($val)) $val = [$fallback];
    $val = array_values(array_unique(array_filter(array_map('strval', $val))));
    if (!$val) $val = [$fallback];
    self::$active_cache = $val;
    return self::$active_cache;
  }

  public static function is_active(string $code): bool {
    return in_array($code, self::active_codes(), true);
  }

  /**
   * Locales that are visible to public visitors. Active-but-unpublished locales
   * remain fully available in wp-admin so translation work can be completed
   * before a language is launched. The default locale is always public.
   *
   * @return string[]
   */
  public static function public_codes(): array {
    if (self::$public_cache !== null) return self::$public_cache;
    $active = self::active_codes();
    $stored = get_option(self::OPT_PUBLIC, $active);
    if (!is_array($stored)) $stored = $active;
    $public = [];
    foreach ($active as $code) {
      if (in_array($code, $stored, true)) $public[] = $code;
    }
    $default = self::default_code();
    if (in_array($default, $active, true) && !in_array($default, $public, true)) array_unshift($public, $default);
    if (!$public && $active) $public[] = $active[0];
    self::$public_cache = array_values(array_unique($public));
    return self::$public_cache;
  }

  public static function is_public(string $code): bool {
    return in_array($code, self::public_codes(), true);
  }

  /** @return string[] */
  public static function staged_codes(): array {
    return array_values(array_diff(self::active_codes(), self::public_codes()));
  }

  public static function normalize_slug(string $code): string {
    return sanitize_title(strtolower(str_replace('_', '-', $code)));
  }

  public static function code_to_slug(string $code): string {
    $cat = self::catalog();
    if (isset($cat[$code]['slug']) && is_string($cat[$code]['slug'])) return $cat[$code]['slug'];
    return self::normalize_slug($code);
  }

  public static function display_code(string $code): string {
    return strtoupper(str_replace('_', '-', $code));
  }

  public static function display_name(string $code): string {
    $catalog = self::catalog();
    return isset($catalog[$code]['name']) ? (string) $catalog[$code]['name'] : $code;
  }

  public static function family_code(string $code): string {
    $catalog = self::catalog();
    if (isset($catalog[$code]['family'])) return (string) $catalog[$code]['family'];
    return sanitize_key((string) strtok(str_replace('_', '-', $code), '-'));
  }

  public static function is_legacy_code(string $code): bool {
    $catalog = self::catalog();
    return !empty($catalog[$code]['legacy']);
  }

  public static function hreflang_code(string $code): string {
    $catalog = self::catalog();
    if (isset($catalog[$code]['hreflang']) && is_string($catalog[$code]['hreflang'])) {
      return (string) $catalog[$code]['hreflang'];
    }
    return $code;
  }

  public static function locale_metadata(string $code): array {
    $catalog = self::catalog();
    return isset($catalog[$code]) && is_array($catalog[$code]) ? $catalog[$code] : [];
  }

  /** @return array<string,mixed> */
  public static function quality_report(): array {
    $catalog = self::catalog();
    $required = self::canonical_family_codes();
    $supplemental = self::supplemental_family_codes();
    $families = [];
    $current_locales = 0;
    $legacy_aliases = 0;
    $slug_seen = [];
    $hreflang_seen = [];
    $duplicate_slugs = [];
    $duplicate_hreflang = [];
    $invalid_fallbacks = [];

    foreach ($catalog as $code => $row) {
      if (!empty($row['legacy'])) {
        $legacy_aliases++;
        continue;
      }
      $current_locales++;
      $family = isset($row['family']) ? sanitize_key((string) $row['family']) : self::family_code((string) $code);
      if ($family !== '') $families[$family] = true;

      $slug = isset($row['slug']) ? (string) $row['slug'] : '';
      if ($slug !== '') {
        if (isset($slug_seen[$slug])) $duplicate_slugs[] = $slug;
        $slug_seen[$slug] = true;
      }
      $hreflang = isset($row['hreflang']) ? (string) $row['hreflang'] : '';
      if ($hreflang !== '') {
        if (isset($hreflang_seen[$hreflang])) $duplicate_hreflang[] = $hreflang;
        $hreflang_seen[$hreflang] = true;
      }
      $fallback = isset($row['fallback']) ? (string) $row['fallback'] : '';
      if ($fallback !== '' && !isset($catalog[$fallback])) $invalid_fallbacks[] = (string) $code;
    }

    $missing_families = array_values(array_diff($required, array_keys($families)));
    $missing_supplemental = array_values(array_diff($supplemental, array_keys($families)));
    $active_missing_metadata = [];
    $active_rtl = [];
    foreach (self::active_codes() as $code) {
      $row = isset($catalog[$code]) && is_array($catalog[$code]) ? $catalog[$code] : [];
      if (!$row || empty($row['hreflang']) || empty($row['slug']) || empty($row['name'])) $active_missing_metadata[] = $code;
      if (!empty($row['rtl'])) $active_rtl[] = $code;
    }

    return [
      'catalog_locales' => count($catalog),
      'current_locales' => $current_locales,
      'legacy_aliases' => $legacy_aliases,
      'catalog_families' => count($families),
      'required_families' => count($required),
      'covered_families' => count(array_intersect($required, array_keys($families))),
      'supplemental_families' => count($supplemental),
      'covered_supplemental' => count(array_intersect($supplemental, array_keys($families))),
      'missing_families' => $missing_families,
      'missing_supplemental' => $missing_supplemental,
      'duplicate_slugs' => array_values(array_unique($duplicate_slugs)),
      'duplicate_hreflang' => array_values(array_unique($duplicate_hreflang)),
      'invalid_fallbacks' => array_values(array_unique($invalid_fallbacks)),
      'active_missing_metadata' => $active_missing_metadata,
      'active_rtl' => $active_rtl,
      'complete' => !$missing_families && !$missing_supplemental && !$duplicate_slugs && !$duplicate_hreflang && !$invalid_fallbacks && !$active_missing_metadata,
    ];
  }

  /**
   * Product localization/content-language core: all EU official language families,
   * Ukrainian, plus major world-language families used by the Yablonsky product line.
   */
  public static function canonical_family_codes(): array {
    return [
      // EU official languages (24 families).
      'bg','hr','cs','da','nl','en','et','fi','fr','de','el','hu','ga','it','lv','lt',
      'mt','pl','pt','ro','sk','sl','es','sv',
      // Ukraine + major world languages (12 families including Ukrainian).
      'uk','ar','zh','he','hi','id','ja','ko','ru','th','tr','vi',
    ];
  }

  /** @return string[] */
  public static function supplemental_family_codes(): array {
    return ['ms','no'];
  }

  public static function catalog_families(): array {
    $families = [];
    foreach (self::catalog() as $code => $row) {
      $family = isset($row['family']) ? (string) $row['family'] : self::family_code((string) $code);
      if (!isset($families[$family])) {
        $families[$family] = [
          'family' => $family,
          'name' => (string) ($row['english_name'] ?? $row['name'] ?? $family),
          'native_name' => (string) ($row['native_name'] ?? ''),
          'locales' => [],
        ];
      }
      $families[$family]['locales'][] = (string) $code;
    }
    return $families;
  }

  public static function supported_post_types(): array {
    $pts = get_post_types(['public' => true], 'names');
    $exclude = ['attachment', 'revision', 'nav_menu_item'];
    $pts = array_values(array_diff($pts, $exclude));
    $pts = apply_filters('yablonsky_multilingual_supported_post_types', $pts);
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Legacy YPM public compatibility hook.
    return apply_filters('ypm_supported_post_types', $pts);
  }

  public static function supported_taxonomies(): array {
    $tax = ['category','post_tag','product_cat','product_tag','download_category','download_tag'];
    $tax = array_values(array_filter($tax, function($t){ return taxonomy_exists($t); }));
    $tax = apply_filters('yablonsky_multilingual_supported_taxonomies', $tax);
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Legacy YPM public compatibility hook.
    return apply_filters('ypm_supported_taxonomies', $tax);
  }

  public static function register_taxonomy(): void {
    self::ensure_options();

    register_taxonomy(self::TAX, self::supported_post_types(), [
      'labels' => [
        'name' => __('Languages', 'yablonsky-multilingual'),
        'singular_name' => __('Language', 'yablonsky-multilingual'),
      ],
      'public' => false,
      'show_ui' => false,
      'show_in_nav_menus' => false,
      'show_admin_column' => false,
      'rewrite' => false,
      'hierarchical' => false,
      'query_var' => false,
    ]);

    $catalog = self::catalog();
    foreach (self::active_codes() as $code) {
      $slug = self::code_to_slug($code);
      if (!term_exists($slug, self::TAX)) {
        $name = $catalog[$code]['name'] ?? $code;
        wp_insert_term($name, self::TAX, ['slug' => $slug]);
      }
    }
  }

  public static function preset_global_top(): array {
    return ['en-US','es-ES','de','ja','fr-FR','pt-PT','ru','it','nl','pl','zh-CN','ar','id','lt','uk'];
  }

  public static function preset_eu_commerce(): array {
    return ['en-US','bg','hr','cs','da','nl','et','fi','fr-FR','de','el','hu','ga','it','lv','lt','mt','pl','pt-PT','ro','sk','sl','es-ES','sv','uk'];
  }

  /** One representative locale per canonical 36 language family. */
  public static function preset_core_36(): array {
    return [
      'bg','hr','cs','da','nl','en-US','et','fi','fr-FR','de','el','hu','ga','it','lv','lt',
      'mt','pl','pt-PT','ro','sk','sl','es-ES','sv','uk','ar','zh-CN','he','hi','id','ja','ko',
      'ru','th','tr','vi',
    ];
  }

  /** Canonical 36 plus the two supported supplemental families. */
  public static function preset_all_families(): array {
    return array_merge(self::preset_core_36(), ['ms','no']);
  }
}
