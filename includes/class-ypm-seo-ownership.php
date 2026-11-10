<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Cross-plugin SEO ownership boundary.
 *
 * One field has one write authority. YPS may claim SEO ownership through the
 * documented filters without YPM hard-coding a YPS implementation class.
 */
final class SEOOwnership {
  // Persisted ownership token stays `ypm` for backward compatibility; the canonical product name is YMulti.
  public const OWNER_YMULTI = 'ypm';
  public const OWNER_YPM = self::OWNER_YMULTI;
  public const OWNER_YPS = 'yps';
  public const OWNER_NONE = 'none';

  public static function metadata_owner(): string {
    $owner = apply_filters('yablonsky_multilingual_seo_metadata_owner', self::OWNER_YPM);
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Legacy YPM public compatibility hook.
    return sanitize_key((string) apply_filters('ypm_seo_metadata_owner', $owner));
  }

  public static function hreflang_owner(): string {
    $owner = apply_filters('yablonsky_multilingual_hreflang_output_owner', self::OWNER_YPM);
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Legacy YPM public compatibility hook.
    return sanitize_key((string) apply_filters('ypm_hreflang_output_owner', $owner));
  }

  public static function sitemap_owner(): string {
    // YMulti never owns sitemap output. A sitemap/SEO plugin may claim authority
    // explicitly through this compatibility boundary.
    $owner = apply_filters('yablonsky_multilingual_sitemap_output_owner', self::OWNER_NONE);
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Legacy YPM public compatibility hook.
    return sanitize_key((string) apply_filters('ypm_sitemap_output_owner', $owner));
  }

  public static function ymulti_may_write_seo_metadata(): bool {
    return self::metadata_owner() === self::OWNER_YMULTI;
  }

  public static function ymulti_may_render_hreflang(): bool {
    return self::hreflang_owner() === self::OWNER_YMULTI;
  }

  /** @deprecated Use ymulti_may_write_seo_metadata(). */
  public static function ypm_may_write_seo_metadata(): bool {
    return self::ymulti_may_write_seo_metadata();
  }

  /** @deprecated Use ymulti_may_render_hreflang(). */
  public static function ypm_may_render_hreflang(): bool {
    return self::ymulti_may_render_hreflang();
  }

  /**
   * Request SEO translation without assuming which plugin implements it.
   * Consumers may attach to this action and own the actual writes.
   */
  public static function request_translation(
    int $resource_id,
    string $source_locale,
    string $target_locale,
    array $fields = [],
    array $context = []
  ): void {
    do_action(
      'yablonsky_multilingual_request_seo_translation',
      $resource_id,
      $source_locale,
      $target_locale,
      $fields,
      $context
    );
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Legacy YPM public compatibility action.
    do_action('ypm_request_seo_translation', $resource_id, $source_locale, $target_locale, $fields, $context);
  }
}
