<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Stable developer-facing REST read API.
 *
 * Public language discovery exposes only non-sensitive launch metadata. Resource
 * graphs require edit authority for the requested object; site coverage remains
 * administrator-only. No endpoint publishes, translates or mutates content.
 */
final class RestAPI {
  public const NS = 'ymulti/v1';

  public static function init(): void {
    add_action('rest_api_init', [__CLASS__, 'register_routes']);
  }

  public static function register_routes(): void {
    register_rest_route(self::NS, '/languages', [
      'methods' => \WP_REST_Server::READABLE,
      'callback' => [__CLASS__, 'languages'],
      'permission_callback' => '__return_true',
    ]);
    register_rest_route(self::NS, '/translations/(?P<id>\d+)', [
      'methods' => \WP_REST_Server::READABLE,
      'callback' => [__CLASS__, 'translations'],
      'permission_callback' => [__CLASS__, 'can_read_translations'],
      'args' => ['id' => ['validate_callback' => static fn($value): bool => absint($value) > 0]],
    ]);
    register_rest_route(self::NS, '/coverage', [
      'methods' => \WP_REST_Server::READABLE,
      'callback' => [__CLASS__, 'coverage'],
      'permission_callback' => static fn(): bool => current_user_can('manage_options'),
    ]);
  }

  public static function languages(): \WP_REST_Response {
    // Share the effective language authority with YSEO. During WPML bridge
    // mode the public/default languages are owned by WPML, not the YMulti
    // staging options. Keep the existing REST payload shape unchanged.
    $provider = MultilingualSEOProvider::instance();
    $default = $provider->get_default_language();
    $items = [];
    foreach ($provider->get_languages() as $code => $language) {
      $items[] = [
        'code' => (string) $code,
        'name' => (string) ($language['name'] ?? ''),
        'hreflang' => (string) ($language['hreflang'] ?? ''),
        'default' => (string) $code === $default,
        'public' => !empty($language['public']),
      ];
    }
    return new \WP_REST_Response([
      'contract' => self::NS,
      'default' => $default,
      'languages' => $items,
    ], 200);
  }

  public static function can_read_translations(\WP_REST_Request $request): bool {
    $id = absint($request['id']);
    return $id > 0 && current_user_can('edit_post', $id);
  }

  /** @return \WP_REST_Response|\WP_Error */
  public static function translations(\WP_REST_Request $request) {
    $id = absint($request['id']);
    $post = get_post($id);
    if (!($post instanceof \WP_Post) || !in_array((string) $post->post_type, Languages::supported_post_types(), true)) {
      return new \WP_Error('ymulti_rest_resource', __('Unsupported multilingual resource.', 'yablonsky-multilingual'), ['status' => 404]);
    }
    $provider = MultilingualSEOProvider::instance();
    return new \WP_REST_Response([
      'contract' => self::NS,
      'resource_id' => $id,
      'post_type' => (string) $post->post_type,
      'language' => $provider->get_resource_language($id),
      'group' => $provider->get_translation_group($id),
      // The shared SEO graph may include unpublished drafts. REST disclosure
      // must be restricted to the current actor for EACH linked resource.
      // Preserve the locale-keyed /ymulti/v1 contract for authorized members.
      'translations' => self::authorized_translation_members($provider->get_translations($id), (string) $post->post_type),
    ], 200);
  }

  /**
   * Scope REST-only linked metadata to the active WordPress actor.
   * No mutation of translation families or the YSEO graph provider.
   *
   * @param array<string,array<string,mixed>> $members
   * @return array<string,array<string,mixed>>
   */
  private static function authorized_translation_members(array $members, string $source_type): array {
    $allowed = [];
    foreach ($members as $locale => $member) {
      if (!is_array($member)) continue;
      $related_id = absint($member['resource_id'] ?? 0);
      if ($related_id <= 0) continue;
      $related = get_post($related_id);
      if (!($related instanceof \WP_Post) || (string) $related->post_type !== $source_type) continue;
      // YMulti's existing job-derived meta-cap mapping grants assigned
      // translators and reviewers their legitimate read/edit access.
      if (!current_user_can('read_post', $related_id) && !current_user_can('edit_post', $related_id)) continue;
      $allowed[$locale] = $member;
    }
    return $allowed;
  }

  public static function coverage(): \WP_REST_Response {
    $summary = class_exists('Yablonsky\\Multilingual\\LaunchCenter') && method_exists(LaunchCenter::class, 'report')
      ? LaunchCenter::report()
      : [];
    return new \WP_REST_Response([
      'contract' => self::NS,
      'generated_at' => gmdate('c'),
      'coverage' => $summary,
    ], 200);
  }
}
