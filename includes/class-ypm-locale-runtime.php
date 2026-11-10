<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Maps the current YPM content locale to the native WordPress request locale.
 *
 * The mapping is resolved once per request from the URL and YMulti language
 * settings. This lets WordPress, WooCommerce, themes and plugins use their
 * normal .mo/.json translations without a database lookup on every gettext()
 * call.
 */
final class LocaleRuntime {
  private static bool $initialized = false;

  /** @var array<string,mixed>|null */
  private static ?array $resolved = null;

  public static function init(): void {
    if (self::$initialized) return;
    if (class_exists('Yablonsky\\Multilingual\\RuntimeAuthority') && !RuntimeAuthority::ymulti_owns_public_runtime()) return;
    self::$initialized = true;

    add_filter('pre_determine_locale', [__CLASS__, 'filter_pre_determine_locale'], 20);
    add_filter('locale', [__CLASS__, 'filter_locale'], 20);
  }

  public static function filter_pre_determine_locale($locale) {
    $resolved = self::resolved();
    if (!$resolved['eligible'] || $resolved['wp_locale'] === '') return $locale;
    return $resolved['wp_locale'];
  }

  public static function filter_locale(string $locale): string {
    $resolved = self::resolved();
    if (!$resolved['eligible'] || $resolved['wp_locale'] === '') return $locale;
    return (string) $resolved['wp_locale'];
  }

  /**
   * Current content locale inferred from the public request path.
   * Returns an empty string when YPM must not own request locale selection.
   */
  public static function content_language(): string {
    $resolved = self::resolved();
    return $resolved['eligible'] ? (string) $resolved['language'] : '';
  }

  /**
   * Current mapped WordPress locale for the public content request.
   */
  public static function wp_locale(): string {
    $resolved = self::resolved();
    return $resolved['eligible'] ? (string) $resolved['wp_locale'] : '';
  }

  /**
   * Expose small diagnostic state for System Status / integrations.
   *
   * @return array<string,mixed>
   */
  public static function diagnostics(): array {
    return self::resolved();
  }

  public static function reset(): void {
    self::$resolved = null;
  }

  /**
   * @return array<string,mixed>
   */
  private static function resolved(): array {
    if (self::$resolved !== null) return self::$resolved;

    if (class_exists('Yablonsky\\Multilingual\\SafeMode') && SafeMode::enabled()) {
      self::$resolved = [
        'eligible' => false,
        'language' => '',
        'wp_locale' => '',
        'path' => '/',
        'reason' => 'safe-mode',
      ];
      return self::$resolved;
    }

    $result = [
      'eligible' => false,
      'language' => '',
      'wp_locale' => '',
      'path' => '/',
      'reason' => 'not-public-content',
    ];

    if (self::is_non_content_runtime()) {
      self::$resolved = $result;
      return self::$resolved;
    }

    $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
    $parts = wp_parse_url($uri);
    $path = is_array($parts) && isset($parts['path']) ? (string) $parts['path'] : '/';
    $result['path'] = $path;

    $active = get_option(Languages::OPT_ACTIVE, []);
    if (!is_array($active) || !$active) {
      $result['reason'] = 'no-active-locales';
      self::$resolved = $result;
      return self::$resolved;
    }

    $active = array_values(array_unique(array_filter(array_map('strval', $active))));
    $catalog = Languages::catalog();
    $slug_to_code = [];
    foreach ($active as $code) {
      if (!isset($catalog[$code])) continue;
      $slug = isset($catalog[$code]['slug']) ? sanitize_title((string) $catalog[$code]['slug']) : Languages::normalize_slug($code);
      if ($slug !== '') $slug_to_code[$slug] = $code;
    }

    $relative = self::site_relative_path($path);
    $trim = ltrim($relative, '/');
    $segments = $trim === '' ? [] : explode('/', $trim);
    $first = isset($segments[0]) ? sanitize_title((string) $segments[0]) : '';

    $language = '';
    $remainder = $trim;
    if ($first !== '' && isset($slug_to_code[$first])) {
      $language = (string) $slug_to_code[$first];
      array_shift($segments);
      $remainder = implode('/', $segments);
    } else {
      $default = (string) get_option(Languages::OPT_DEFAULT, '');
      if ($default !== '' && in_array($default, $active, true)) $language = $default;
    }

    if ($language === '') {
      $result['reason'] = 'unresolved-language';
      self::$resolved = $result;
      return self::$resolved;
    }

    if (self::is_system_relative_path($remainder)) {
      $result['reason'] = 'system-route';
      self::$resolved = $result;
      return self::$resolved;
    }

    $row = isset($catalog[$language]) && is_array($catalog[$language]) ? $catalog[$language] : [];
    $wp_locale = isset($row['wp_locale']) ? sanitize_text_field((string) $row['wp_locale']) : '';

    if ($wp_locale === '') {
      $result['reason'] = 'no-wp-locale-mapping';
      $result['language'] = $language;
      self::$resolved = $result;
      return self::$resolved;
    }

    $result['eligible'] = true;
    $result['language'] = $language;
    $result['wp_locale'] = $wp_locale;
    $result['reason'] = 'content-locale';
    self::$resolved = $result;
    return self::$resolved;
  }

  private static function is_non_content_runtime(): bool {
    if (defined('WP_ADMIN') && WP_ADMIN) return true;
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return true;
    if (function_exists('wp_doing_cron') && wp_doing_cron()) return true;
    if (defined('REST_REQUEST') && REST_REQUEST) return true;
    if (filter_input(INPUT_GET, 'rest_route', FILTER_UNSAFE_RAW) !== null) return true;
    if (defined('WP_CLI') && WP_CLI) return true;
    return false;
  }

  private static function site_relative_path(string $path): string {
    $home = (string) get_option('home', '');
    $home_parts = $home !== '' ? wp_parse_url($home) : false;
    $home_path = is_array($home_parts) && isset($home_parts['path']) ? trim((string) $home_parts['path'], '/') : '';
    $trim = ltrim($path, '/');

    if ($home_path === '') return $trim;
    if ($trim === $home_path) return '';
    if (strpos($trim, $home_path . '/') === 0) return substr($trim, strlen($home_path) + 1);
    return $trim;
  }

  private static function is_system_relative_path(string $path): bool {
    $trim = ltrim($path, '/');
    if ($trim === '') return false;

    $system = ['wp-admin', 'wp-login.php', 'wp-json', 'wp-content', 'wp-includes'];
    foreach ($system as $prefix) {
      if ($trim === $prefix || strpos($trim, $prefix . '/') === 0) return true;
    }
    return false;
  }
}
