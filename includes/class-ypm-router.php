<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

final class Router {
  public const QV_LANG = 'ypm_lang';
  public const OPT_REWRITE_SIG = 'ypm_rewrite_signature';
  public const OPT_HIDE_DEFAULT_SLUG = 'ypm_hide_default_slug';
  private static ?string $current_lang = null;

  /** @var array<string,bool> */
  private static array $system_path_cache = [];

  /** @var string[]|null */
  private static ?array $active_slug_cache = null;

  /** Reset locale/path projections without changing rewrite rules. */
  public static function reset_request_cache(): void {
    self::$current_lang = null;
    self::$system_path_cache = [];
    self::$active_slug_cache = null;
  }

  public static function ensure_signature_option(): void {
    if (get_option(self::OPT_REWRITE_SIG) === false) {
      add_option(self::OPT_REWRITE_SIG, '', '', false);
    }
  }

  /**
   * Create a deterministic signature for rewrite rules.
   * Used to avoid expensive flush_rewrite_rules() calls when nothing changed.
   */
  public static function compute_rewrite_signature(): string {
    $active = Languages::public_codes();
    $map = [];
    foreach ($active as $code) {
      $map[$code] = Languages::code_to_slug($code);
    }

    $data = [
      'default' => Languages::default_code(),
      'active' => $map,
      'permalinks' => (string) get_option('permalink_structure', ''),
      'hide_default_slug' => self::hide_default_slug_enabled() ? 1 : 0,
    ];

    return md5(wp_json_encode($data));
  }

  /**
   * Flush rewrites only when needed.
   * Returns true when a flush occurred.
   */
  public static function maybe_flush_rewrites(bool $force = false): bool {
    if (class_exists('Yablonsky\\Multilingual\\RuntimeAuthority') && !RuntimeAuthority::ymulti_owns_public_runtime()) return false;
    if (class_exists('Yablonsky\\Multilingual\\SafeMode') && SafeMode::enabled()) return false;

    self::ensure_signature_option();
    $old = (string) get_option(self::OPT_REWRITE_SIG, '');
    $new = self::compute_rewrite_signature();

    if ($force || $old !== $new) {
      flush_rewrite_rules();
      update_option(self::OPT_REWRITE_SIG, $new, false);
      return true;
    }
    return false;
  }

  public static function init(): void {
    if (class_exists('Yablonsky\\Multilingual\\RuntimeAuthority') && !RuntimeAuthority::ymulti_owns_public_runtime()) return;
    add_filter('query_vars', [__CLASS__, 'add_query_var']);
    add_filter('rewrite_rules_array', [__CLASS__, 'prefix_rewrite_rules'], 20);
    add_action('parse_request', [__CLASS__, 'capture_language'], 1);
    add_action('template_redirect', [__CLASS__, 'redirect_to_default_if_needed'], 0);
    add_action('template_redirect', [__CLASS__, 'redirect_default_slug_to_root_if_needed'], 1);
    add_filter('home_url', [__CLASS__, 'filter_home_url'], 20, 4);
  }

  public static function hide_default_slug_enabled(): bool {
    return (bool) get_option(self::OPT_HIDE_DEFAULT_SLUG, false);
  }

  public static function add_query_var(array $vars): array {
    $vars[] = self::QV_LANG;
    return $vars;
  }

  public static function prefix_rewrite_rules(array $rules): array {
    $active = Languages::public_codes();
    if (!$active) return $rules;

    $new = [];
    foreach ($active as $code) {
      if (self::hide_default_slug_enabled() && $code === Languages::default_code()) {
        continue;
      }
      $slug = Languages::code_to_slug($code);
      $new['^' . preg_quote($slug, '#') . '/?$'] = 'index.php?' . self::QV_LANG . '=' . rawurlencode($code);

      foreach ($rules as $regex => $query) {
        if (self::is_system_rewrite_rule((string) $regex, (string) $query)) continue;

        $regex2 = ltrim($regex, '^');
        $prefixed = '^' . preg_quote($slug, '#') . '/' . $regex2;

        if (strpos($query, 'index.php?') === 0) {
          $query = 'index.php?' . self::QV_LANG . '=' . rawurlencode($code) . '&' . substr($query, strlen('index.php?'));
        } else {
          $query = 'index.php?' . self::QV_LANG . '=' . rawurlencode($code);
        }
        $new[$prefixed] = $query;
      }
    }

    return $new + $rules;
  }

  public static function capture_language(\WP $wp): void {
    $code = null;
    if (isset($wp->query_vars[self::QV_LANG]) && is_string($wp->query_vars[self::QV_LANG])) {
      $code = sanitize_text_field($wp->query_vars[self::QV_LANG]);
    }
    if (!$code && class_exists('Yablonsky\Multilingual\RoutingModes')) {
      $host_language = RoutingModes::language_for_current_host();
      if ($host_language !== '') $code = $host_language;
    }
    if (!$code) $code = Languages::default_code();
    if (!Languages::is_public($code)) $code = Languages::default_code();
    self::$current_lang = $code;
  }

  public static function current_lang(): string {
    return self::$current_lang ?: Languages::default_code();
  }

  public static function current_slug(): string {
    return Languages::code_to_slug(self::current_lang());
  }

  public static function is_system_path(string $path): bool {
    if (array_key_exists($path, self::$system_path_cache)) {
      return self::$system_path_cache[$path];
    }

    $trim = ltrim($path, '/');

    // Legacy/third-party stacks can surface system namespaces behind a locale
    // prefix. Strip one known language prefix before classifying the route so
    // /en/wp-json/... is never treated as ordinary multilingual content.
    foreach (self::active_slugs() as $slug) {
      if ($trim === $slug) break;
      if (strpos($trim, $slug . '/') === 0) {
        $trim = substr($trim, strlen($slug) + 1);
        break;
      }
    }

    $is_system = false;
    $blocked = ['wp-admin', 'wp-login.php', 'wp-json', 'wp-content', 'wp-includes'];
    foreach ($blocked as $bp) {
      if ($trim === $bp || strpos($trim, $bp . '/') === 0) {
        $is_system = true;
        break;
      }
    }

    if (count(self::$system_path_cache) < 256) {
      self::$system_path_cache[$path] = $is_system;
    }
    return $is_system;
  }

  /**
   * @return string[]
   */
  private static function active_slugs(): array {
    if (self::$active_slug_cache !== null) return self::$active_slug_cache;

    self::$active_slug_cache = [];
    foreach (Languages::public_codes() as $code) {
      self::$active_slug_cache[] = Languages::code_to_slug($code);
    }
    return self::$active_slug_cache;
  }

  /**
   * REST/system rewrite rules are WordPress infrastructure, not localized
   * content. Prefixing them creates duplicate API namespaces and extra work.
   */
  private static function is_system_rewrite_rule(string $regex, string $query): bool {
    $normalized = ltrim($regex, '^');
    if (strpos($normalized, 'wp-json') === 0) return true;
    if (strpos($query, 'rest_route=') !== false) return true;
    if (strpos($query, self::QV_LANG . '=') !== false) return true;
    return false;
  }

  public static function redirect_to_default_if_needed(): void {
    if (class_exists('Yablonsky\\Multilingual\\SafeMode') && SafeMode::enabled()) return;
    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    if (is_feed()) return;

    if (self::hide_default_slug_enabled()) return;
    if (class_exists('Yablonsky\Multilingual\RoutingModes') && RoutingModes::language_for_current_host() !== '') return;

    $qv = get_query_var(self::QV_LANG);
    if (is_string($qv) && $qv !== '') return;

    $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
    $parts = wp_parse_url($uri);
    $path = $parts['path'] ?? '/';
    $query = isset($parts['query']) ? ('?' . $parts['query']) : '';

    $site_path = Urls::site_relative_path($path);
    if (self::is_system_path('/' . ltrim($site_path, '/'))) return;

    $trim = ltrim($site_path, '/');
    foreach (Languages::public_codes() as $code) {
      $slug = Languages::code_to_slug($code);
      if ($trim === $slug || strpos($trim, $slug . '/') === 0) return;
    }

    $target = Urls::for_path($path . $query, Languages::default_code());
    wp_safe_redirect($target, 301);
    exit;
  }

  /**
   * If the default language slug is hidden, redirect /{default}/... to /...
   * to avoid duplicate content.
   */
  public static function redirect_default_slug_to_root_if_needed(): void {
    if (class_exists('Yablonsky\\Multilingual\\SafeMode') && SafeMode::enabled()) return;
    if (!self::hide_default_slug_enabled()) return;
    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    if (is_feed()) return;

    $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
    $parts = wp_parse_url($uri);
    $path = $parts['path'] ?? '/';
    $query = isset($parts['query']) ? ('?' . $parts['query']) : '';

    $site_path = Urls::site_relative_path($path);
    if (self::is_system_path('/' . ltrim($site_path, '/'))) return;

    $default = Languages::default_code();
    $default_slug = Languages::code_to_slug($default);
    $trim = ltrim($site_path, '/');

    if ($trim === $default_slug || strpos($trim, $default_slug . '/') === 0) {
      $target = Urls::for_path($path . $query, $default);
      wp_safe_redirect($target, 301);
      exit;
    }
  }

  /**
   * Localize ordinary home_url() output for the current request language.
   * Alternate-language code must use Urls directly with an explicit target.
   */
  public static function filter_home_url(string $url, string $path, ?string $orig_scheme, ?int $blog_id): string {
    if (class_exists('Yablonsky\\Multilingual\\SafeMode') && SafeMode::enabled()) return $url;
    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) return $url;
    if (defined('REST_REQUEST') && REST_REQUEST) return $url;

    if ($path === '' || $path === '/') return $url;
    if (self::is_system_path($path)) return $url;

    $trim = ltrim($path, '/');
    foreach (Languages::public_codes() as $code) {
      $slug = Languages::code_to_slug($code);
      if ($trim === $slug || strpos($trim, $slug . '/') === 0) return $url;
    }

    return Urls::for_url($url, self::current_lang());
  }
}
