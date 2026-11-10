<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

final class Hreflang {
  public static function init(): void {
    if (class_exists('Yablonsky\\Multilingual\\RuntimeAuthority') && !RuntimeAuthority::ymulti_owns_public_runtime()) return;
    add_action('wp_head', [__CLASS__, 'render_hreflang'], 2);
  }

  public static function render_hreflang(): void {
    if (is_admin()) return;
    // A 404, feed, or editorial preview has no public canonical language
    // equivalents. Generic path expansion must not manufacture alternates.
    if (is_404() || is_feed() || is_preview()) return;
    if (class_exists('Yablonsky\\Multilingual\\SEOOwnership') && !SEOOwnership::ymulti_may_render_hreflang()) return;

    $active = Languages::public_codes();
    if (!$active) return;

    $default = Languages::default_code();
    $links = [];

    if (is_singular()) {
      $post = get_queried_object();
      // SEO alternate links must never expose unpublished or password-protected resources.
      $post_type = $post instanceof \WP_Post ? get_post_type_object((string) $post->post_type) : null;
      if ($post instanceof \WP_Post && is_object($post_type) && !empty($post_type->publicly_queryable) && $post->post_status === 'publish' && (string) $post->post_password === '') {
        $family = EntityContract::post_family((int) $post->ID, $active);
        foreach ((array) ($family['translations'] ?? []) as $language => $post_id) {
          if (!Languages::is_public((string) $language)) continue;
          $member = get_post((int) $post_id);
          if (!($member instanceof \WP_Post) || $member->post_status !== 'publish' || (string) $member->post_password !== '') continue;
          $member_type = get_post_type_object((string) $member->post_type);
          if (!is_object($member_type) || empty($member_type->publicly_queryable)) continue;
          $url = Urls::for_post((int) $post_id, (string) $language);
          if ($url !== '') $links[(string) $language] = $url;
        }
      }
    } elseif (is_category() || is_tag() || is_tax()) {
      $term = get_queried_object();
      $tax_obj = $term instanceof \WP_Term ? get_taxonomy((string) $term->taxonomy) : null;
      if ($term instanceof \WP_Term && is_object($tax_obj) && !empty($tax_obj->publicly_queryable) && TermTranslations::is_supported_taxonomy((string) $term->taxonomy)) {
        $taxonomy = (string) $term->taxonomy;
        $family = EntityContract::term_family((int) $term->term_id, $taxonomy, $active);
        foreach ((array) ($family['translations'] ?? []) as $language => $term_id) {
          if (!Languages::is_public((string) $language)) continue;
          $url = Urls::for_term((int) $term_id, $taxonomy, (string) $language);
          if ($url !== '') $links[(string) $language] = $url;
        }
      }
    } else {
      $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
      $parts = wp_parse_url($request_uri);
      $path = is_array($parts) && isset($parts['path']) ? (string) $parts['path'] : '/';
      $query = is_array($parts) && isset($parts['query']) ? ('?' . (string) $parts['query']) : '';

      if (!Router::is_system_path($path)) {
        foreach ($active as $code) {
          $links[$code] = Urls::for_path($path . $query, $code);
        }
      }
    }

    foreach ($active as $code) {
      if (empty($links[$code])) continue;
      printf(
        "\n<link rel=\"alternate\" hreflang=\"%s\" href=\"%s\" />",
        esc_attr(Languages::hreflang_code($code)),
        esc_url($links[$code])
      );
    }

    if (!empty($links[$default])) {
      printf(
        "\n<link rel=\"alternate\" hreflang=\"x-default\" href=\"%s\" />",
        esc_url($links[$default])
      );
    }
    echo "\n";
  }
}
