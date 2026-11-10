<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Stable read-only multilingual graph for SEO consumers.
 *
 * YMulti owns multilingual truth. SEO plugins remain responsible for canonical,
 * indexability and sitemap rendering. This provider intentionally does not
 * create or route any sitemap endpoint.
 */
final class MultilingualSEOProvider {
  public const CONTRACT = 'yseo-multilingual-provider/v1';

  private static ?self $instance = null;

  public static function instance(): self {
    if (self::$instance === null) self::$instance = new self();
    return self::$instance;
  }

  public static function init(): void {
    add_filter('yablonsky_multilingual_seo_provider_v1', [__CLASS__, 'filter_provider'], 10, 1);
    add_filter('yablonsky_seo_multilingual_provider_v1', [__CLASS__, 'filter_provider'], 10, 1);
    add_filter('yablonsky_multilingual_seo_capabilities_v1', [__CLASS__, 'filter_capabilities'], 10, 1);

    // Semantic invalidation is write-side only; no frontend scan is added.
    add_action('save_post', [__CLASS__, 'on_save_post'], 100, 3);
    add_action('before_delete_post', [__CLASS__, 'on_delete_post'], 10, 1);
    add_action('set_object_terms', [__CLASS__, 'on_set_object_terms'], 100, 6);
    add_action('updated_option', [__CLASS__, 'on_updated_option'], 10, 3);
    add_action('yablonsky_multilingual_term_graph_changed_v1', [__CLASS__, 'on_term_graph_changed'], 10, 1);
    add_action('created_term', [__CLASS__, 'on_term_content_changed'], 100, 3);
    add_action('edited_term', [__CLASS__, 'on_term_content_changed'], 100, 3);
    add_action('delete_term', [__CLASS__, 'on_term_deleted'], 100, 5);
  }

  /** @param mixed $provider @return mixed */
  public static function filter_provider($provider) {
    return $provider ?: self::instance();
  }

  /** @param mixed $capabilities */
  public static function filter_capabilities($capabilities): array {
    $base = is_array($capabilities) ? $capabilities : [];
    return array_merge($base, self::instance()->capabilities());
  }

  public function capabilities(): array {
    return [
      'contract' => self::CONTRACT,
      'resource_language' => true,
      'translation_groups' => true,
      'bulk_alternates' => true,
      'resource_languages_bulk' => true,
      'taxonomy_alternates_bulk' => true,
      'request_context' => true,
      'localized_urls' => true,
      'x_default' => true,
      'domain_per_language' => class_exists('Yablonsky\\Multilingual\\RuntimeAuthority') && RuntimeAuthority::ymulti_owns_public_runtime() && class_exists('Yablonsky\\Multilingual\\RoutingModes') && RoutingModes::domain_mode_enabled(),
      'runtime_authority' => true,
      'resource_types' => ['post', 'term'],
      'sitemap_owner' => false,
    ];
  }

  /** @return array<string,array<string,mixed>> */
  public function get_languages(): array {
    $out = [];
    $default = class_exists('Yablonsky\Multilingual\RuntimeAuthority') ? RuntimeAuthority::default_language() : Languages::default_code();
    $runtime_public = class_exists('Yablonsky\Multilingual\RuntimeAuthority') ? RuntimeAuthority::public_language_codes() : Languages::public_codes();
    // Incumbent public languages can exist before YMulti's admin-only locale
    // bridge has imported their choices. Keep them in the public YSEO graph.
    $codes = array_values(array_unique(array_merge(Languages::active_codes(), $runtime_public, [$default])));
    $catalog = Languages::catalog();
    foreach ($codes as $code) {
      if (!isset($catalog[$code]) || !empty($catalog[$code]['legacy'])) continue;
      $out[$code] = [
        'key' => $code,
        'name' => Languages::display_name($code),
        'hreflang' => Languages::hreflang_code($code),
        'default' => $code === $default,
        'active' => in_array($code, Languages::active_codes(), true) || in_array($code, $runtime_public, true),
        'public' => in_array($code, $runtime_public, true),
      ];
    }
    return $out;
  }

  public function get_default_language(): string {
    return class_exists('Yablonsky\Multilingual\RuntimeAuthority') ? RuntimeAuthority::default_language() : Languages::default_code();
  }

  public function get_resource_language(int $resource_id, string $resource_type = 'post'): ?string {
    if (!$this->supports_post($resource_id, $resource_type)) return null;
    return EntityContract::post_source_locale($resource_id);
  }

  /** @param int[] $resource_ids @return array<int,string> */
  public function get_resource_languages_bulk(array $resource_ids, string $resource_type = 'post'): array {
    if ($resource_type !== 'post') return [];
    $resource_ids = array_values(array_unique(array_filter(array_map('intval', $resource_ids))));
    if (!$resource_ids) return [];
    Translations::prime_languages($resource_ids);
    $out = [];
    foreach ($resource_ids as $resource_id) {
      if ($this->supports_post($resource_id, 'post')) $out[$resource_id] = EntityContract::post_source_locale($resource_id);
    }
    return $out;
  }

  /** @return array<string,mixed> */
  public function get_request_context(): array {
    return RequestContext::current();
  }

  public function get_term_language(int $term_id, string $taxonomy): ?string {
    if (!$this->supports_term($term_id, $taxonomy)) return null;
    return EntityContract::term_source_locale($term_id, $taxonomy);
  }

  public function get_term_translation_group(int $term_id, string $taxonomy): ?string {
    if (!$this->supports_term($term_id, $taxonomy)) return null;
    $family = EntityContract::term_family($term_id, $taxonomy);
    $key = sanitize_text_field((string) ($family['family_key'] ?? ''));
    return $key !== '' ? $key : ('term:' . $taxonomy . ':' . $term_id);
  }

  /** @return array<string,array<string,mixed>> */
  public function get_term_translations(int $term_id, string $taxonomy): array {
    if (!$this->supports_term($term_id, $taxonomy)) return [];
    TermTranslations::prime_translation_families([$term_id], $taxonomy);
    return $this->translation_payload_for_term($term_id, $taxonomy);
  }

  /**
   * Bounded taxonomy graph. Input rows contain term_id + taxonomy and output is
   * grouped by taxonomy, then source term ID, then locale.
   *
   * @param array<int,array<string,mixed>> $resources
   * @return array<string,array<int,array<string,array<string,mixed>>>>
   */
  public function get_term_translations_bulk(array $resources): array {
    if (!$resources) return [];
    $by_taxonomy = [];
    foreach ($resources as $resource) {
      if (!is_array($resource)) continue;
      $term_id = isset($resource['term_id']) ? (int) $resource['term_id'] : 0;
      $taxonomy = isset($resource['taxonomy']) ? sanitize_key((string) $resource['taxonomy']) : '';
      if ($term_id <= 0 || !$this->supports_term($term_id, $taxonomy)) continue;
      $by_taxonomy[$taxonomy][$term_id] = true;
    }
    if (!$by_taxonomy) return [];

    foreach ($by_taxonomy as $taxonomy => $ids) {
      TermTranslations::prime_translation_families(array_keys($ids), (string) $taxonomy);
    }

    $out = [];
    foreach ($by_taxonomy as $taxonomy => $ids) {
      foreach (array_keys($ids) as $term_id) {
        $out[(string) $taxonomy][(int) $term_id] = $this->translation_payload_for_term((int) $term_id, (string) $taxonomy);
      }
    }
    return $out;
  }

  public function get_translation_group(int $resource_id, string $resource_type = 'post'): ?string {
    if (!$this->supports_post($resource_id, $resource_type)) return null;
    $family = EntityContract::post_family($resource_id);
    $key = sanitize_text_field((string) ($family['family_key'] ?? ''));
    return $key !== '' ? $key : ('post:' . $resource_id);
  }

  /** @return array<string,array<string,mixed>> */
  public function get_translations(int $resource_id, string $resource_type = 'post'): array {
    if (!$this->supports_post($resource_id, $resource_type)) return [];
    Translations::prime_translation_families([$resource_id]);
    return $this->translation_payload_for_post($resource_id);
  }

  /**
   * Bounded bulk graph. The caller controls batch size; YMulti primes languages
   * and family membership in batches instead of performing one relation query
   * for every requested resource.
   *
   * @param int[] $resource_ids
   * @return array<int,array<string,array<string,mixed>>>
   */
  public function get_translations_bulk(array $resource_ids, string $resource_type = 'post'): array {
    if ($resource_type !== 'post') return [];

    $resource_ids = array_values(array_unique(array_filter(array_map('intval', $resource_ids))));
    if (!$resource_ids) return [];

    Translations::prime_translation_families($resource_ids);

    $member_ids = [];
    foreach ($resource_ids as $resource_id) {
      if (!$this->supports_post($resource_id, 'post')) continue;
      $family = EntityContract::post_family($resource_id);
      foreach ((array) ($family['translations'] ?? []) as $member_id) $member_ids[absint($member_id)] = true;
    }
    unset($member_ids[0]);
    if ($member_ids && function_exists('_prime_post_caches')) _prime_post_caches(array_keys($member_ids), false, false);

    $out = [];
    foreach ($resource_ids as $resource_id) {
      if (!$this->supports_post($resource_id, 'post')) continue;
      $out[$resource_id] = $this->translation_payload_for_post($resource_id);
    }
    return $out;
  }

  public function get_localized_url(int $resource_id, string $language, string $resource_type = 'post'): ?string {
    if (!$this->supports_post($resource_id, $resource_type)) return null;
    if (class_exists('Yablonsky\Multilingual\RuntimeAuthority') && !RuntimeAuthority::ymulti_owns_public_runtime()) {
      $url = RuntimeAuthority::localized_post_url($resource_id, $language);
      return $url !== '' ? $url : null;
    }
    if (!Languages::is_public($language)) return null;
    // Native multilingual URLs belong to actual translated WordPress objects,
    // not the source object's permalink with another locale prefix.
    $target_id = RuntimeAuthority::translated_post_id($resource_id, $language);
    if ($target_id <= 0) return null;
    $url = Urls::for_post($target_id, $language);
    return $url !== '' ? $url : null;
  }

  public function get_hreflang_code(string $language): string {
    return Languages::hreflang_code($language);
  }

  public function get_x_default_url(int $resource_id, string $resource_type = 'post'): ?string {
    if (!$this->supports_post($resource_id, $resource_type)) return null;
    $translations = $this->get_translations($resource_id, $resource_type);
    $default = $this->get_default_language();
    if (empty($translations[$default]) || empty($translations[$default]['public'])) return null;
    $url = isset($translations[$default]['url']) ? (string) $translations[$default]['url'] : '';
    return $url !== '' ? $url : null;
  }

  public function is_translation_public(int $resource_id, string $language, string $resource_type = 'post'): bool {
    if (!$this->supports_post($resource_id, $resource_type)) return false;
    $translations = $this->get_translations($resource_id, $resource_type);
    return !empty($translations[$language]['public']);
  }

  /** @return array<string,array<string,mixed>> */
  private function translation_payload_for_post(int $resource_id, array $preloaded_groups = []): array {
    unset($preloaded_groups);
    $family = EntityContract::post_family($resource_id);
    $by_language = is_array($family['translations'] ?? null) ? $family['translations'] : [];
    $out = [];
    foreach ($by_language as $language => $post_id) {
      $post_id = (int) $post_id;
      $post = get_post($post_id);
      if (!($post instanceof \WP_Post)) continue;
      // Resolve from the actual translation object: native URLs can have
      // different slugs even within the same family. Incumbent bridges still
      // route through their established translated-post lookup.
      $url = $this->get_localized_url($post_id, (string) $language, 'post');
      // A published record can belong to a non-public WordPress post type.
      // Expose its identity to authorized editorial consumers but never mark
      // it as a publicly queryable SEO alternate or x-default destination.
      $post_type = get_post_type_object((string) $post->post_type);
      $queryable = is_object($post_type) && !empty($post_type->publicly_queryable);
      $out[(string) $language] = [
        'resource_id' => $post_id,
        'url' => $url ?: '',
        'public' => $queryable && $post->post_status === 'publish' && (string) $post->post_password === '' && in_array((string) $language, RuntimeAuthority::public_language_codes(), true),
      ];
    }
    return $out;
  }

  /** @return array<string,array<string,mixed>> */
  private function translation_payload_for_term(int $term_id, string $taxonomy): array {
    $family = EntityContract::term_family($term_id, $taxonomy);
    $by_language = is_array($family['translations'] ?? null) ? $family['translations'] : [];
    $tax_obj = get_taxonomy($taxonomy);
    $taxonomy_public = is_object($tax_obj) && !empty($tax_obj->public) && !empty($tax_obj->publicly_queryable);
    $out = [];
    foreach ($by_language as $language => $translated_id) {
      $translated_id = (int) $translated_id;
      $term = get_term($translated_id, $taxonomy);
      if (!($term instanceof \WP_Term)) continue;
      if (RuntimeAuthority::ymulti_owns_public_runtime()) {
        $url = Urls::for_term($translated_id, $taxonomy, (string) $language);
      } else {
        $resolved_url = get_term_link($translated_id, $taxonomy);
        $url = is_wp_error($resolved_url) ? '' : (string) $resolved_url;
      }
      $out[(string) $language] = [
        'term_id' => $translated_id,
        'taxonomy' => $taxonomy,
        'url' => $url,
        'public' => $taxonomy_public && $url !== '' && in_array((string) $language, RuntimeAuthority::public_language_codes(), true),
      ];
    }
    return $out;
  }

  private function supports_term(int $term_id, string $taxonomy): bool {
    if ($term_id <= 0 || $taxonomy === '' || !TermTranslations::is_supported_taxonomy($taxonomy)) return false;
    $term = get_term($term_id, $taxonomy);
    return $term instanceof \WP_Term;
  }

  private function supports_post(int $resource_id, string $resource_type): bool {
    if ($resource_type !== 'post' || $resource_id <= 0) return false;
    $post = get_post($resource_id);
    return $post instanceof \WP_Post && in_array((string) $post->post_type, Languages::supported_post_types(), true);
  }

  public static function on_save_post(int $post_id, \WP_Post $post, bool $update): void {
    unset($update);
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
    if (!in_array((string) $post->post_type, Languages::supported_post_types(), true)) return;
    self::emit_invalidation($post_id, 'post_saved');
  }

  public static function on_delete_post(int $post_id): void {
    $post = get_post($post_id);
    if (!($post instanceof \WP_Post) || !in_array((string) $post->post_type, Languages::supported_post_types(), true)) return;
    self::emit_invalidation($post_id, 'post_deleted');
  }

  /** @param mixed $terms @param mixed $tt_ids @param mixed $old_tt_ids */
  public static function on_set_object_terms(int $object_id, $terms, $tt_ids, string $taxonomy, bool $append, $old_tt_ids): void {
    unset($terms, $append);
    if (!in_array($taxonomy, [Translations::GROUP_TAX, Languages::TAX], true)) return;
    $post = get_post($object_id);
    if (!($post instanceof \WP_Post) || !in_array((string) $post->post_type, Languages::supported_post_types(), true)) return;

    $reason = $taxonomy === Languages::TAX ? 'language_changed' : 'translation_group_changed';
    $extra = [];
    if ($taxonomy === Translations::GROUP_TAX) {
      $previous_group = self::group_slug_from_tt_ids(is_array($old_tt_ids) ? $old_tt_ids : []);
      $current_group = self::group_slug_from_tt_ids(is_array($tt_ids) ? $tt_ids : []);
      $affected = [$object_id];
      $effective = EntityContract::post_family($object_id);
      foreach ((array) ($effective['translations'] ?? []) as $member_id) $affected[] = (int) $member_id;
      foreach ([$previous_group, $current_group] as $group) {
        if ($group === '') continue;
        foreach (Translations::translations_for_group($group) as $member_id) $affected[] = (int) $member_id;
      }
      $extra = [
        'previous_group' => $previous_group,
        'current_group' => $current_group,
        'affected_resource_ids' => array_values(array_unique(array_filter(array_map('intval', $affected)))),
      ];
    }
    self::emit_invalidation($object_id, $reason, $extra);
  }

  /** @param array<string,mixed> $event */
  public static function on_term_graph_changed(array $event): void {
    $term_id = isset($event['term_id']) ? (int) $event['term_id'] : 0;
    $taxonomy = isset($event['taxonomy']) ? sanitize_key((string) $event['taxonomy']) : '';
    if ($term_id <= 0 || $taxonomy === '' || !TermTranslations::is_supported_taxonomy($taxonomy)) return;

    $previous_group = sanitize_title((string) ($event['previous_group'] ?? ''));
    $current_group = sanitize_title((string) ($event['current_group'] ?? TermTranslations::group_id($term_id)));
    $term_resources = [['term_id' => $term_id, 'taxonomy' => $taxonomy]];
    $effective = EntityContract::term_family($term_id, $taxonomy);
    foreach ((array) ($effective['translations'] ?? []) as $member_id) {
      $term_resources[] = ['term_id' => (int) $member_id, 'taxonomy' => $taxonomy];
    }
    foreach ([$previous_group, $current_group] as $group) {
      if ($group === '') continue;
      foreach (TermTranslations::translations_by_language_for_group($taxonomy, $group) as $member_id) {
        $term_resources[] = ['term_id' => (int) $member_id, 'taxonomy' => $taxonomy];
      }
    }
    $unique = [];
    foreach ($term_resources as $resource) $unique[$resource['taxonomy'] . ':' . $resource['term_id']] = $resource;

    $payload = array_merge($event, [
      'resource_type' => 'term',
      'resource_id' => $term_id,
      'taxonomy' => $taxonomy,
      'previous_group' => $previous_group,
      'current_group' => $current_group,
      'affected_terms' => array_values($unique),
      'contract' => self::CONTRACT,
    ]);
    self::notify_invalidation($payload);
  }

  public static function on_term_content_changed(int $term_id, int $tt_id, string $taxonomy): void {
    unset($tt_id);
    if (!TermTranslations::is_supported_taxonomy($taxonomy)) return;
    $group = TermTranslations::group_id($term_id);
    self::on_term_graph_changed([
      'term_id' => $term_id,
      'taxonomy' => $taxonomy,
      'reason' => 'term_content_changed',
      'previous_group' => $group,
      'current_group' => $group,
    ]);
  }

  /** @param mixed $deleted_term @param mixed $object_ids */
  public static function on_term_deleted(int $term_id, int $tt_id, string $taxonomy, $deleted_term, $object_ids): void {
    unset($tt_id, $object_ids);
    if (!TermTranslations::is_supported_taxonomy($taxonomy)) return;
    $language = '';
    if ($deleted_term instanceof \WP_Term) {
      $language = sanitize_text_field((string) get_term_meta($term_id, TermTranslations::META_LANGUAGE, true));
    }
    self::notify_invalidation([
      'resource_type' => 'term',
      'resource_id' => $term_id,
      'taxonomy' => $taxonomy,
      'reason' => 'term_deleted',
      'previous_language' => $language,
      'affected_terms' => [['term_id' => $term_id, 'taxonomy' => $taxonomy]],
      'contract' => self::CONTRACT,
    ]);
  }

  /** @param mixed $old_value @param mixed $value */
  public static function on_updated_option(string $option, $old_value, $value): void {
    unset($old_value, $value);
    $watched = [Languages::OPT_DEFAULT, Languages::OPT_ACTIVE, Router::OPT_HIDE_DEFAULT_SLUG, 'ypm_custom_languages'];
    if (!in_array($option, $watched, true)) return;
    self::notify_invalidation([
      'resource_type' => 'global',
      'resource_id' => 0,
      'reason' => 'language_configuration_changed',
      'option' => $option,
      'contract' => self::CONTRACT,
    ]);
  }

  /** @param array<string,mixed> $extra */
  private static function emit_invalidation(int $post_id, string $reason, array $extra = []): void {
    $payload = array_merge([
      'resource_type' => 'post',
      'resource_id' => $post_id,
      'reason' => $reason,
      'affected_resource_ids' => [$post_id],
      'contract' => self::CONTRACT,
    ], $extra);
    self::notify_invalidation($payload);
  }

  /**
   * Emit the public YMulti -> SEO invalidation contract without allowing an
   * integration listener failure to break the primary YMulti write path.
   *
   * @param array<string,mixed> $payload
   */
  public static function notify_invalidation(array $payload): bool {
    if (!isset($payload['contract'])) $payload['contract'] = self::CONTRACT;
    try {
      do_action('yablonsky_multilingual_seo_graph_invalidated_v1', $payload);
      return true;
    } catch (\Throwable $error) {
      self::record_integration_error('seo_invalidation_listener', $error);
      return false;
    }
  }

  public static function record_integration_error(string $context, \Throwable $error): void {
    update_option('ypm_last_integration_error', [
      'context' => sanitize_key($context),
      'error_class' => sanitize_text_field(get_class($error)),
      'message' => sanitize_text_field(substr($error->getMessage(), 0, 500)),
      'recorded_at' => gmdate('c'),
      'version' => defined('YPM_VERSION') ? sanitize_text_field((string) YPM_VERSION) : '',
    ], false);
  }

  /** @param mixed[] $tt_ids */
  private static function group_slug_from_tt_ids(array $tt_ids): string {
    foreach ($tt_ids as $tt_id) {
      $term = get_term_by('term_taxonomy_id', (int) $tt_id, Translations::GROUP_TAX);
      if ($term instanceof \WP_Term) return sanitize_title((string) $term->slug);
    }
    return '';
  }
}
