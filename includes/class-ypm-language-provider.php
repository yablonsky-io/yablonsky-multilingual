<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Versioned read contract for YPS and future integrations.
 *
 * YMulti remains the authority for locale identity, translation relationships,
 * localized URLs and routing. Consumers should prefer bulk methods.
 */
final class LanguageProvider {
  public const VERSION = '1.3.0';

  public static function init(): void {
    add_filter('yablonsky_multilingual_language_provider_v1', [__CLASS__, 'provide']);
    // Legacy compatibility filter retained for already-integrated consumers.
    add_filter('ypm_language_provider', [__CLASS__, 'provide']);
  }

  public static function provide($provider = null) {
    return __CLASS__;
  }

  public static function version(): string {
    return self::VERSION;
  }

  public static function capabilities(): array {
    return [
      'resource_languages_bulk' => true,
      'translations_bulk' => true,
      'localized_urls_bulk' => true,
      'translated_terms_bulk' => false,
      'taxonomy_bases_bulk' => false,
      'request_context' => true,
      'native_request_locale' => true,
      'woocommerce_entity_manifest' => true,
      'projection_specific_priming' => true,
      'runtime_authority' => true,
      'canonical_public_url' => true,
      'alternate_public_urls' => true,
      'cache_namespace' => true,
      'bridge_mode' => true,
    ];
  }

  /**
   * Return YMulti request identity without forcing consumers to rediscover it.
   *
   * @return array<string,mixed>
   */
  public static function get_request_context(): array {
    return RequestContext::current();
  }


  /** @return array<string,mixed> */
  public static function get_runtime_authority(): array {
    return class_exists('Yablonsky\Multilingual\RuntimeAuthority') ? RuntimeAuthority::status() : [];
  }

  public static function get_canonical_public_url(): string {
    $context = self::get_request_context();
    return isset($context['canonical_url']) ? (string) $context['canonical_url'] : '';
  }

  /** @return array<string,string> */
  public static function get_alternate_public_urls(): array {
    $context = self::get_request_context();
    return isset($context['alternate_urls']) && is_array($context['alternate_urls']) ? $context['alternate_urls'] : [];
  }

  public static function get_cache_namespace(): string {
    $context = self::get_request_context();
    return isset($context['cache_namespace']) ? sanitize_key((string) $context['cache_namespace']) : '';
  }

  public static function get_enabled_languages(): array {
    $catalog = Languages::catalog();
    $out = [];
    foreach (Languages::active_codes() as $code) {
      $row = $catalog[$code] ?? Languages::locale_metadata($code);
      if (!$row) continue;
      $row['code'] = $code;
      $out[$code] = $row;
    }
    return $out;
  }

  public static function get_language_catalog(): array {
    return Languages::catalog();
  }

  public static function get_woocommerce_manifest(): array {
    if (!class_exists('Yablonsky\Multilingual\Compat\Woo') || !Compat\Woo::is_active()) {
      return [
        'active' => false,
        'translatable_taxonomies' => [],
        'shared_taxonomies' => [],
        'field_policy' => [],
      ];
    }

    return [
      'active' => true,
      'translatable_taxonomies' => Compat\Woo::translatable_taxonomies(),
      'shared_taxonomies' => Compat\Woo::shared_taxonomies(),
      'field_policy' => Compat\Woo::field_policy(),
      'completeness' => Compat\Woo::completeness_matrix(),
    ];
  }

  public static function current_language(): string {
    if (class_exists('Yablonsky\Multilingual\Router')) return Router::current_lang();
    return Languages::default_code();
  }

  public static function translation_group(int $post_id): string {
    return EntityContract::post_family_key($post_id);
  }

  public static function get_resource_language(int $post_id): string {
    return EntityContract::post_source_locale($post_id);
  }

  /**
   * @param int[] $post_ids
   * @return array<int,string>
   */
  public static function get_resource_languages_bulk(array $post_ids): array {
    $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
    if (!$post_ids) return [];

    Translations::prime_languages($post_ids);
    $out = [];
    foreach ($post_ids as $post_id) {
      $out[$post_id] = EntityContract::post_source_locale($post_id);
    }
    return $out;
  }

  /**
   * @return array<string,int>
   */
  public static function get_translations(int $post_id): array {
    $family = EntityContract::post_family($post_id);
    return is_array($family['translations'] ?? null) ? $family['translations'] : [];
  }

  /**
   * @param int[] $post_ids
   * @return array<int,array<string,int>>
   */
  public static function get_translations_bulk(array $post_ids): array {
    $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
    if (!$post_ids) return [];

    Translations::prime_translation_families($post_ids);
    $out = [];
    foreach ($post_ids as $post_id) {
      $out[$post_id] = self::get_translations($post_id);
    }
    return $out;
  }

  public static function get_localized_url(int $post_id, string $locale): string {
    if (class_exists('Yablonsky\\Multilingual\\RuntimeAuthority')) {
      $url = RuntimeAuthority::localized_post_url($post_id, $locale);
      if ($url !== '') return $url;
    }
    return RuntimeAuthority::ymulti_owns_public_runtime() ? Urls::for_post($post_id, $locale) : '';
  }

  /**
   * @param int[] $post_ids
   * @param string[] $locales
   * @return array<int,array<string,string>>
   */
  public static function get_localized_urls_bulk(array $post_ids, array $locales): array {
    $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
    $locales = array_values(array_unique(array_filter(array_map('strval', $locales))));
    if (!$post_ids || !$locales) return [];

    _prime_post_caches($post_ids, false, false);

    $out = [];
    foreach ($post_ids as $post_id) {
      $out[$post_id] = [];
      foreach ($locales as $locale) {
        $out[$post_id][$locale] = self::get_localized_url($post_id, $locale);
      }
    }
    return $out;
  }
}
