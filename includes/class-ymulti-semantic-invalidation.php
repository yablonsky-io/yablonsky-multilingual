<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Stable semantic invalidation contract for performance consumers such as YPerf.
 *
 * YMulti identifies changed multilingual resources and exact local URLs. It never
 * executes page/object cache purges and never configures a cache backend.
 */
final class SemanticInvalidation {
  public const CONTRACT = 'ymulti-semantic-invalidation/v1';

  public static function init(): void {
    add_filter('yablonsky_multilingual_invalidation_provider_v1', [__CLASS__, 'provide'], 10, 1);
    add_action('yablonsky_multilingual_seo_graph_invalidated_v1', [__CLASS__, 'forward_graph_event'], 20, 1);
  }

  /** @param mixed $provider @return mixed */
  public static function provide($provider) {
    return $provider ?: __CLASS__;
  }

  /** @return array<string,mixed> */
  public static function capabilities(): array {
    return [
      'contract' => self::CONTRACT,
      'exact_post_targets' => true,
      'exact_term_targets' => true,
      'language_configuration_scope' => true,
      'purge_executor' => false,
      'remote_calls' => false,
    ];
  }

  /** @param array<string,mixed> $event */
  public static function forward_graph_event(array $event): void {
    $resource_type = sanitize_key((string) ($event['resource_type'] ?? ''));
    $reason = sanitize_key((string) ($event['reason'] ?? 'unknown'));
    $payload = [
      'contract' => self::CONTRACT,
      'reason' => $reason,
      'resource_type' => $resource_type,
      'scope' => 'exact',
      'resource_ids' => [],
      'term_resources' => [],
      'languages' => [],
      'urls' => [],
      'requires_broad_invalidation' => false,
      'purge_executor' => false,
    ];

    if ($resource_type === 'post') {
      $ids = isset($event['affected_resource_ids']) && is_array($event['affected_resource_ids'])
        ? $event['affected_resource_ids']
        : [(int) ($event['resource_id'] ?? 0)];
      $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
      $payload['resource_ids'] = $ids;
      foreach ($ids as $post_id) {
        $post = get_post($post_id);
        if (!($post instanceof \WP_Post) || !in_array((string) $post->post_type, Languages::supported_post_types(), true)) continue;
        $language = RuntimeAuthority::effective_post_language($post_id);
        if ($language !== '') $payload['languages'][] = $language;
        $url = Urls::for_post($post_id, $language);
        if ($url !== '') $payload['urls'][] = $url;
      }
      $payload['previous_group'] = sanitize_title((string) ($event['previous_group'] ?? ''));
      $payload['current_group'] = sanitize_title((string) ($event['current_group'] ?? ''));
    } elseif ($resource_type === 'term') {
      $resources = isset($event['affected_terms']) && is_array($event['affected_terms']) ? $event['affected_terms'] : [];
      if (!$resources && !empty($event['resource_id']) && !empty($event['taxonomy'])) {
        $resources[] = ['term_id' => (int) $event['resource_id'], 'taxonomy' => sanitize_key((string) $event['taxonomy'])];
      }
      $unique = [];
      foreach ($resources as $resource) {
        if (!is_array($resource)) continue;
        $term_id = isset($resource['term_id']) ? (int) $resource['term_id'] : 0;
        $taxonomy = isset($resource['taxonomy']) ? sanitize_key((string) $resource['taxonomy']) : '';
        if ($term_id <= 0 || $taxonomy === '' || !TermTranslations::is_supported_taxonomy($taxonomy)) continue;
        $term = get_term($term_id, $taxonomy);
        if (!($term instanceof \WP_Term)) continue;
        $unique[$taxonomy . ':' . $term_id] = ['term_id' => $term_id, 'taxonomy' => $taxonomy];
        $language = RuntimeAuthority::effective_term_language($term_id, $taxonomy);
        if ($language !== '') $payload['languages'][] = $language;
        $url = Urls::for_term($term_id, $taxonomy, $language);
        if ($url !== '') $payload['urls'][] = $url;
      }
      $payload['term_resources'] = array_values($unique);
      $payload['previous_group'] = sanitize_title((string) ($event['previous_group'] ?? ''));
      $payload['current_group'] = sanitize_title((string) ($event['current_group'] ?? ''));
    } elseif ($resource_type === 'global') {
      $payload['scope'] = 'language_configuration';
      $payload['requires_broad_invalidation'] = true;
      $payload['option'] = sanitize_key((string) ($event['option'] ?? ''));
    } else {
      return;
    }

    $payload['languages'] = array_values(array_unique(array_filter(array_map('strval', $payload['languages']))));
    $payload['urls'] = array_values(array_unique(array_filter(array_map('strval', $payload['urls']))));
    do_action('yablonsky_multilingual_semantic_invalidated_v1', $payload);
  }
}
