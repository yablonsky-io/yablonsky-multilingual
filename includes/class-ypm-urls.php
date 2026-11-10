<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Target-language-aware URL generation.
 *
 * All alternate-language URLs should flow through this class so URL prefixes
 * are derived from the requested target language rather than the current
 * request language.
 */
final class Urls {
  private const REQUEST_CACHE_LIMIT = 2048;

  /** @var array<string,string> */
  private static array $post_cache = [];

  /** @var array<int,string> */
  private static array $permalink_cache = [];

  /** @var array<string,string> */
  private static array $term_cache = [];

  /** @var array<string,string> */
  private static array $term_link_cache = [];

  /** @var array<string,string> */
  private static array $path_cache = [];

  /** @var array<string,string> */
  private static array $url_cache = [];

  /** @var array<string,mixed>|null */
  private static ?array $home_cache = null;

  public static function init(): void {
    add_action('clean_post_cache', [__CLASS__, 'clear_request_cache']);
  }

  public static function clear_request_cache(): void {
    self::$post_cache = [];
    self::$permalink_cache = [];
    self::$term_cache = [];
    self::$term_link_cache = [];
    self::$path_cache = [];
    self::$url_cache = [];
    self::$home_cache = null;
  }
  /**
   * Return the language home URL.
   */
  public static function home(string $language): string {
    return self::for_path('/', $language);
  }

  /**
   * Return a permalink localized for the target post language.
   */
  public static function for_post(int $post_id, string $language = ''): string {
    if ($language === '') {
      $language = RuntimeAuthority::effective_post_language($post_id);
    }

    $cache_key = $post_id . '|' . $language;
    if (array_key_exists($cache_key, self::$post_cache)) {
      return self::$post_cache[$cache_key];
    }

    if (array_key_exists($post_id, self::$permalink_cache)) {
      $url = self::$permalink_cache[$post_id];
    } else {
      $permalink = get_permalink($post_id);
      $url = is_string($permalink) ? $permalink : '';
      if (count(self::$permalink_cache) < self::REQUEST_CACHE_LIMIT) {
        self::$permalink_cache[$post_id] = $url;
      }
    }

    if ($url === '') {
      self::remember(self::$post_cache, $cache_key, '');
      return '';
    }

    $localized = self::for_url($url, $language);
    self::remember(self::$post_cache, $cache_key, $localized);
    return $localized;
  }

  /**
   * Return a taxonomy-term URL localized for the target term language.
   */
  public static function for_term(int $term_id, string $taxonomy, string $language = ''): string {
    if ($term_id <= 0 || $taxonomy === '') return '';
    if ($language === '' && class_exists('Yablonsky\\Multilingual\\TermTranslations')) {
      $language = RuntimeAuthority::effective_term_language($term_id, $taxonomy);
    }
    if ($language === '') $language = Languages::default_code();

    $cache_key = $taxonomy . '|' . $term_id . '|' . $language;
    if (array_key_exists($cache_key, self::$term_cache)) return self::$term_cache[$cache_key];

    $raw_key = $taxonomy . '|' . $term_id;
    if (array_key_exists($raw_key, self::$term_link_cache)) {
      $url = self::$term_link_cache[$raw_key];
    } else {
      $term = get_term($term_id, $taxonomy);
      if (!($term instanceof \WP_Term)) {
        self::remember(self::$term_cache, $cache_key, '');
        return '';
      }
      $term_link = get_term_link($term);
      $url = is_wp_error($term_link) ? '' : (string) $term_link;
      self::remember(self::$term_link_cache, $raw_key, $url);
    }

    if ($url === '') {
      self::remember(self::$term_cache, $cache_key, '');
      return '';
    }

    $localized = self::for_url($url, $language);
    self::remember(self::$term_cache, $cache_key, $localized);
    return $localized;
  }

  /**
   * Localize a site-relative path for a target language.
   * Query strings and fragments are preserved.
   */
  public static function for_path(string $path, string $language): string {
    $cache_key = $language . '|' . $path;
    if (array_key_exists($cache_key, self::$path_cache)) return self::$path_cache[$cache_key];

    $parts = wp_parse_url($path);
    if ($parts === false) return home_url('/');

    if (!empty($parts['scheme']) || !empty($parts['host'])) {
      return self::for_url($path, $language);
    }

    $path_part = isset($parts['path']) ? (string) $parts['path'] : '/';
    $query = isset($parts['query']) ? (string) $parts['query'] : '';
    $fragment = isset($parts['fragment']) ? (string) $parts['fragment'] : '';

    $home = self::home_parts();
    if (!$home) return home_url('/');

    $relative = self::relative_to_home_path($path_part, $home['path']);
    if (Router::is_system_path('/' . ltrim($relative, '/'))) {
      $localized = self::compose($home, $relative, $query, $fragment);
      self::remember(self::$path_cache, $cache_key, $localized);
      return $localized;
    }

    $relative = self::strip_language_prefix($relative);
    $relative = self::apply_language_prefix($relative, $language);

    $localized = self::compose($home, $relative, $query, $fragment);
    if (class_exists('Yablonsky\Multilingual\RoutingModes')) $localized = RoutingModes::map_url_to_language_domain($localized, $language);
    self::remember(self::$path_cache, $cache_key, $localized);
    return $localized;
  }

  /**
   * Localize an absolute site URL for a target language.
   * External URLs are returned unchanged.
   */
  public static function for_url(string $url, string $language): string {
    $cache_key = $language . '|' . $url;
    if (array_key_exists($cache_key, self::$url_cache)) return self::$url_cache[$cache_key];

    $parts = wp_parse_url($url);
    if ($parts === false) return $url;

    if (empty($parts['scheme']) && empty($parts['host'])) {
      return self::for_path($url, $language);
    }

    $home = self::home_parts();
    if (!$home) return $url;

    $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
    $home_host = strtolower((string) $home['host']);
    $port = isset($parts['port']) ? (int) $parts['port'] : null;
    $home_port = $home['port'];

    if ($host !== $home_host || $port !== $home_port) return $url;

    $path_part = isset($parts['path']) ? (string) $parts['path'] : '/';
    $query = isset($parts['query']) ? (string) $parts['query'] : '';
    $fragment = isset($parts['fragment']) ? (string) $parts['fragment'] : '';

    $relative = self::relative_to_home_path($path_part, $home['path']);
    if (Router::is_system_path('/' . ltrim($relative, '/'))) return $url;

    $relative = self::strip_language_prefix($relative);
    $relative = self::apply_language_prefix($relative, $language);

    $localized = self::compose($home, $relative, $query, $fragment);
    if (class_exists('Yablonsky\Multilingual\RoutingModes')) $localized = RoutingModes::map_url_to_language_domain($localized, $language);
    self::remember(self::$url_cache, $cache_key, $localized);
    return $localized;
  }

  /**
   * Keep request-local memoization bounded on high-cardinality archives.
   * Once the small hot cache is full, return values normally without growing
   * request memory further.
   *
   * @param array<string,string> $cache
   */
  private static function remember(array &$cache, string $key, string $value): void {
    if (array_key_exists($key, $cache) || count($cache) < self::REQUEST_CACHE_LIMIT) {
      $cache[$key] = $value;
    }
  }

  /**
   * Build parsed home URL components. home_url('/') is intentionally used:
   * Router::filter_home_url() leaves the root URL unchanged, avoiding recursion.
   */
  private static function home_parts(): ?array {
    if (self::$home_cache !== null) return self::$home_cache;

    $home_url = home_url('/');
    $parts = wp_parse_url($home_url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return null;

    self::$home_cache = [
      'scheme' => (string) $parts['scheme'],
      'host' => (string) $parts['host'],
      'port' => isset($parts['port']) ? (int) $parts['port'] : null,
      'path' => isset($parts['path']) ? '/' . trim((string) $parts['path'], '/') : '',
    ];

    return self::$home_cache;
  }

  /**
   * Return a request path relative to the configured WordPress home path.
   * This keeps routing checks correct when WordPress lives in a subdirectory.
   */
  public static function site_relative_path(string $path): string {
    $home = self::home_parts();
    if (!$home) return ltrim($path, '/');

    return self::relative_to_home_path($path, $home['path']);
  }

  /**
   * Remove the WordPress home subdirectory from an absolute path.
   */
  private static function relative_to_home_path(string $path, string $home_path): string {
    $trimmed = ltrim($path, '/');
    $home_trimmed = trim($home_path, '/');

    if ($home_trimmed === '') return $trimmed;
    if ($trimmed === $home_trimmed) return '';

    $prefix = $home_trimmed . '/';
    if (strpos($trimmed, $prefix) === 0) {
      return substr($trimmed, strlen($prefix));
    }

    return $trimmed;
  }

  /**
   * Strip one known language slug from the front of a site-relative path.
   */
  private static function strip_language_prefix(string $path): string {
    $leading = ltrim($path, '/');

    foreach (Languages::active_codes() as $code) {
      $slug = Languages::code_to_slug($code);
      if ($leading === $slug) return '';
      if (strpos($leading, $slug . '/') === 0) {
        return substr($leading, strlen($slug) + 1);
      }
    }

    return $leading;
  }

  /**
   * Apply the requested target-language prefix while respecting the hidden
   * default-language-slug setting.
   */
  private static function apply_language_prefix(string $path, string $language): string {
    $path = ltrim($path, '/');
    $default = Languages::default_code();
    $hide_default = Router::hide_default_slug_enabled();

    if ($hide_default && $language === $default) {
      return $path;
    }

    $slug = Languages::code_to_slug($language);
    if ($path === '') return $slug . '/';

    return $slug . '/' . $path;
  }

  /**
   * Compose an absolute URL from normalized home and relative-path parts.
   */
  private static function compose(array $home, string $relative, string $query, string $fragment): string {
    $url = $home['scheme'] . '://' . $home['host'];
    if ($home['port'] !== null) $url .= ':' . (int) $home['port'];

    $home_path = trim((string) $home['path'], '/');
    if ($home_path !== '') $url .= '/' . $home_path;

    $relative = ltrim($relative, '/');
    $url .= '/';
    if ($relative !== '') $url .= $relative;

    if ($query !== '') $url .= '?' . $query;
    if ($fragment !== '') $url .= '#' . $fragment;

    return $url;
  }
}
