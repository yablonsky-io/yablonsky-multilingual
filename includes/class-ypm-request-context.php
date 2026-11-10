<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Request-local multilingual context.
 *
 * YPM resolves its route/language identity once and exposes the result to
 * integrations instead of asking each consumer to rediscover the same state.
 */
final class RequestContext {
  /** @var array<string,mixed>|null */
  private static ?array $current = null;

  public static function init(): void {
    add_action('parse_request', [__CLASS__, 'capture'], 2);
  }

  public static function capture(\WP $wp): void {
    self::$current = self::build();
  }

  /**
   * @return array<string,mixed>
   */
  public static function current(): array {
    if (self::$current === null) {
      self::$current = self::build();
    }
    return self::$current;
  }

  public static function reset(): void {
    self::$current = null;
  }

  /**
   * @return array<string,mixed>
   */
  private static function build(): array {
    $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
    $parts = wp_parse_url($uri);
    $path = is_array($parts) && isset($parts['path']) ? (string) $parts['path'] : '/';

    $route_type = 'content';
    if (is_admin()) {
      $route_type = 'admin';
    } elseif (wp_doing_ajax()) {
      $route_type = 'ajax';
    } elseif (wp_doing_cron()) {
      $route_type = 'cron';
    } elseif ((defined('REST_REQUEST') && REST_REQUEST) || (function_exists('wp_is_json_request') && wp_is_json_request()) || filter_input(INPUT_GET, 'rest_route', FILTER_UNSAFE_RAW) !== null) {
      $route_type = 'rest';
    } elseif (Router::is_system_path($path)) {
      $route_type = 'system';
    }

    $identity = class_exists('Yablonsky\Multilingual\RuntimeAuthority') ? RuntimeAuthority::request_identity() : [];
    $language = isset($identity['language']) ? sanitize_text_field((string) $identity['language']) : Router::current_lang();

    return array_merge($identity, [
      'language' => $language,
      'path' => $path,
      'route_type' => $route_type,
      'is_system' => in_array($route_type, ['admin', 'ajax', 'cron', 'rest', 'system'], true),
    ]);
  }
}
