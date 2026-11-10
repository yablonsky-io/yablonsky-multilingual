<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Browser-language and separate-domain routing. Disabled by default. */
final class RoutingModes {
  private static ?array $settings_cache = null;
  public const OPT = 'ymulti_routing_modes_v1';
  public const COOKIE = 'ymulti_language_choice';

  public static function reset_request_cache(): void {
    self::$settings_cache = null;
  }

  public static function init(): void {
    add_action('template_redirect', [__CLASS__, 'remember_explicit_language'], -20);
    add_action('template_redirect', [__CLASS__, 'maybe_redirect_browser_language'], -15);
    add_filter('allowed_redirect_hosts', [__CLASS__, 'allow_mapped_hosts']);
    if (is_admin()) add_action('admin_post_ymulti_save_routing_modes', [__CLASS__, 'handle_save']);
  }

  /** @return array<string,mixed> */
  public static function settings(): array {
    if (self::$settings_cache !== null) return self::$settings_cache;
    $raw = get_option(self::OPT, []);
    $raw = is_array($raw) ? $raw : [];
    self::$settings_cache = wp_parse_args($raw, [
      'browser_detection' => 0,
      'domain_mode' => 0,
      'domains' => [],
    ]);
    return self::$settings_cache;
  }

  public static function browser_detection_enabled(): bool {
    $settings = self::settings();
    return !empty($settings['browser_detection']);
  }

  public static function domain_mode_enabled(): bool {
    $settings = self::settings();
    return !empty($settings['domain_mode']);
  }

  public static function has_domain_for_language(string $language): bool {
    if (!self::domain_mode_enabled() || !Languages::is_public($language)) return false;
    $settings = self::settings();
    return self::normalize_domain((string) (($settings['domains'][$language] ?? ''))) !== '';
  }

  public static function language_for_current_host(): string {
    if (!self::domain_mode_enabled()) return '';
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower(preg_replace('/:\d+$/', '', sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])))) : '';
    if ($host === '') return '';
    $settings = self::settings();
    $domains = isset($settings['domains']) && is_array($settings['domains']) ? $settings['domains'] : [];
    foreach ($domains as $language => $domain) {
      $mapped = self::normalize_domain((string) $domain);
      if ($mapped !== '' && hash_equals($mapped, $host) && Languages::is_public((string) $language)) return (string) $language;
    }
    return '';
  }

  public static function map_url_to_language_domain(string $url, string $language): string {
    if (!self::domain_mode_enabled() || !Languages::is_public($language)) return $url;
    $settings = self::settings();
    $domain = self::normalize_domain((string) (($settings['domains'][$language] ?? '')));
    if ($domain === '') return $url;
    $parts = wp_parse_url($url);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) return $url;
    $old_host = (string) $parts['host'];
    $authority = $parts['scheme'] . '://' . $old_host;
    if (isset($parts['port'])) $authority .= ':' . (int) $parts['port'];
    $replacement = $parts['scheme'] . '://' . $domain;
    $mapped = preg_replace('#^' . preg_quote($authority, '#') . '#', $replacement, $url, 1) ?: $url;
    // A dedicated language domain is itself the locale discriminator. Remove
    // the redundant /{language}/ prefix while preserving the WordPress home
    // subdirectory, query and fragment.
    $slug = preg_quote(Languages::code_to_slug($language), '#');
    $home_path = trim((string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?: ''), '/');
    $prefix = $home_path !== '' ? '/' . preg_quote($home_path, '#') : '';
    $mapped = preg_replace('#^(' . preg_quote($replacement, '#') . ')' . $prefix . '/' . $slug . '(/|$)#', '$1' . ($home_path !== '' ? '/' . $home_path : '') . '$2', $mapped, 1) ?: $mapped;
    return $mapped;
  }

  /** @param string[] $hosts @return string[] */
  public static function allow_mapped_hosts(array $hosts): array {
    $settings = self::settings();
    $domains = isset($settings['domains']) && is_array($settings['domains']) ? $settings['domains'] : [];
    foreach ($domains as $domain) {
      $host = self::normalize_domain((string) $domain);
      if ($host !== '') $hosts[] = $host;
    }
    return array_values(array_unique($hosts));
  }

  public static function remember_explicit_language(): void {
    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) return;
    if (headers_sent()) return;
    $language = self::language_for_current_host();
    if ($language === '') {
      $uri_raw = filter_input(INPUT_SERVER, 'REQUEST_URI', FILTER_UNSAFE_RAW);
      $uri = is_string($uri_raw) ? sanitize_text_field($uri_raw) : '';
      $path = (string) (wp_parse_url($uri, PHP_URL_PATH) ?: '');
      $relative = Urls::site_relative_path($path);
      $first = strtok(ltrim($relative, '/'), '/');
      foreach (Languages::public_codes() as $code) {
        if ($first === Languages::code_to_slug($code)) { $language = $code; break; }
      }
    }
    if ($language !== '' && Languages::is_public($language)) {
      setcookie(self::COOKIE, $language, time() + YEAR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
    }
  }

  public static function maybe_redirect_browser_language(): void {
    if (!self::browser_detection_enabled()) return;
    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    if (isset($_COOKIE[self::COOKIE]) && Languages::is_public(sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE])))) return;
    $method_raw = filter_input(INPUT_SERVER, 'REQUEST_METHOD', FILTER_UNSAFE_RAW);
    $method = is_string($method_raw) ? strtoupper(sanitize_key($method_raw)) : '';
    if ($method !== '' && $method !== 'GET') return;

    $uri_raw = filter_input(INPUT_SERVER, 'REQUEST_URI', FILTER_UNSAFE_RAW);
    $uri = is_string($uri_raw) ? sanitize_text_field($uri_raw) : '/';
    $path = (string) (wp_parse_url($uri, PHP_URL_PATH) ?: '/');
    $relative = trim(Urls::site_relative_path($path), '/');
    // Detection is intentionally first-visit home only. Deep links never jump unexpectedly.
    if ($relative !== '') return;

    $preferred = self::preferred_browser_language();
    if ($preferred === '' || $preferred === Router::current_lang()) return;
    $target = Urls::home($preferred);
    wp_safe_redirect($target, 302, 'YMulti browser language');
    exit;
  }

  public static function preferred_browser_language(): string {
    $header = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE'])) : '';
    if ($header === '') return '';
    $active = Languages::public_codes();
    $weighted = [];
    foreach (explode(',', $header) as $part) {
      $bits = array_map('trim', explode(';', $part));
      $tag = strtolower(str_replace('_', '-', (string) ($bits[0] ?? '')));
      if ($tag === '') continue;
      $q = 1.0;
      if (!empty($bits[1]) && preg_match('/q=([0-9.]+)/i', $bits[1], $m)) $q = max(0.0, min(1.0, (float) $m[1]));
      $weighted[$tag] = $q;
    }
    arsort($weighted, SORT_NUMERIC);
    foreach (array_keys($weighted) as $tag) {
      foreach ($active as $code) {
        $candidate = strtolower(str_replace('_', '-', $code));
        if ($tag === $candidate || strtok($tag, '-') === strtolower(Languages::family_code($code))) return $code;
      }
    }
    return '';
  }

  public static function handle_save(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ymulti_save_routing_modes');
    $domains = [];
    $input_domains = filter_input(INPUT_POST, 'domains', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
    $input_domains = is_array($input_domains) ? $input_domains : [];
    foreach (Languages::active_codes() as $language) {
      $domain = self::normalize_domain((string) ($input_domains[$language] ?? ''));
      if ($domain !== '') $domains[$language] = $domain;
    }
    $settings = [
      'browser_detection' => filter_input(INPUT_POST, 'browser_detection', FILTER_UNSAFE_RAW) !== null ? 1 : 0,
      'domain_mode' => filter_input(INPUT_POST, 'domain_mode', FILTER_UNSAFE_RAW) !== null ? 1 : 0,
      'domains' => $domains,
    ];
    update_option(self::OPT, $settings, false);
    self::$settings_cache = $settings;
    Router::maybe_flush_rewrites(true);
    wp_safe_redirect(admin_url('admin.php?page=ypm-routing&routing_saved=1'));
    exit;
  }

  private static function normalize_domain(string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') return '';
    if (strpos($value, '://') === false) $value = 'https://' . $value;
    $host = wp_parse_url($value, PHP_URL_HOST);
    if (!is_string($host) || $host === '') return '';
    $host = strtolower(trim($host, '.'));
    if (!preg_match('/^[a-z0-9.-]+$/', $host)) return '';
    return $host;
  }

  public static function render_panel(): void {
    if (!current_user_can('manage_options')) return;
    $settings = self::settings();
    echo '<section class="ypm-card ypm-card--standalone"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Routing modes', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Browser detection & separate domains', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('Both modes are optional. Browser detection uses the browser language on the first home visit. Separate-domain routing uses administrator-approved hostnames and the same native WordPress content identities.', 'yablonsky-multilingual') . '</p></div></div>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ymulti_save_routing_modes">';
    wp_nonce_field('ymulti_save_routing_modes');
    echo '<div class="ymulti-routing-options"><label><input type="checkbox" name="browser_detection" value="1"' . checked(!empty($settings['browser_detection']), true, false) . '> <span><strong>' . esc_html__('Browser language suggestion', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Redirect only a first-time visitor landing on the site home when an active language matches Accept-Language.', 'yablonsky-multilingual') . '</small></span></label>';
    echo '<label><input type="checkbox" name="domain_mode" value="1"' . checked(!empty($settings['domain_mode']), true, false) . '> <span><strong>' . esc_html__('Separate domain per language', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Map selected locales to verified DNS hosts. Other locales continue using the normal path-prefix route.', 'yablonsky-multilingual') . '</small></span></label></div>';
    echo '<div class="ymulti-domain-grid">';
    foreach (Languages::active_codes() as $language) {
      $value = (string) (($settings['domains'][$language] ?? ''));
      echo '<label><span><strong>' . esc_html(Languages::display_name($language)) . '</strong><small>' . esc_html(Languages::display_code($language)) . '</small></span><input type="text" name="domains[' . esc_attr($language) . ']" value="' . esc_attr($value) . '" placeholder="' . esc_attr__('example.com', 'yablonsky-multilingual') . '"></label>';
    }
    echo '</div><p><button class="button button-primary" type="submit">' . esc_html__('Save routing modes', 'yablonsky-multilingual') . '</button></p></form></section>';
  }
}
