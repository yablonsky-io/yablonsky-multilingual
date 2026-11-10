<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Resolve shared WordPress navigation into real translated objects in one pass. */
final class Navigation {
  public static function init(): void {
    if (SafeMode::enabled()) return;
    add_filter('wp_nav_menu_objects', [__CLASS__, 'translate_menu_objects'], 20, 2);
    add_filter('option_page_on_front', [__CLASS__, 'translate_system_page'], 20, 2);
    add_filter('option_page_for_posts', [__CLASS__, 'translate_system_page'], 20, 2);
  }

  /** @param array<int,\WP_Post> $items @return array<int,\WP_Post> */
  public static function translate_menu_objects(array $items, $args): array {
    if (is_admin() || !$items) return $items;
    $language = Router::current_lang();
    $post_ids = [];
    $term_ids_by_tax = [];

    foreach ($items as $item) {
      if (!($item instanceof \WP_Post)) continue;
      if ($item->type === 'post_type' && (int) $item->object_id > 0) $post_ids[] = (int) $item->object_id;
      if ($item->type === 'taxonomy' && (int) $item->object_id > 0 && is_string($item->object)) $term_ids_by_tax[$item->object][] = (int) $item->object_id;
    }
    if ($post_ids) Translations::prime_translation_families($post_ids);
    foreach ($term_ids_by_tax as $taxonomy => $ids) TermTranslations::prime_translation_families($ids, (string) $taxonomy);

    $out = [];
    foreach ($items as $item) {
      if (!($item instanceof \WP_Post)) continue;
      if ($item->type === 'post_type' && (int) $item->object_id > 0) {
        $source_id = (int) $item->object_id;
        $has_custom_label = trim((string) $item->post_title) !== '';
        $target_id = EntityContract::post_source_locale($source_id) === $language ? $source_id : EntityContract::existing_post_target($source_id, $language);
        if ($target_id <= 0) continue;
        $item->object_id = (string) $target_id;
        if (!$has_custom_label) {
          $translated_title = get_the_title($target_id);
          if (is_string($translated_title) && $translated_title !== '') $item->title = $translated_title;
        }
        $url = get_permalink($target_id);
        if (is_string($url) && $url !== '') $item->url = Urls::for_post($target_id, $language) ?: $url;
      } elseif ($item->type === 'taxonomy' && (int) $item->object_id > 0 && is_string($item->object)) {
        $source_id = (int) $item->object_id;
        $taxonomy = (string) $item->object;
        $target_id = EntityContract::term_source_locale($source_id, $taxonomy) === $language
          ? $source_id
          : EntityContract::existing_term_target($source_id, $taxonomy, $language);
        if ($target_id <= 0) continue;
        $item->object_id = (string) $target_id;
        $term = get_term($target_id, $taxonomy);
        if ($term instanceof \WP_Term) {
          if (trim((string) $item->post_title) === '' && $term->name !== '') $item->title = (string) $term->name;
          $url = get_term_link($term);
          if (!is_wp_error($url)) $item->url = (string) $url;
        }
      }
      $out[] = $item;
    }
    return $out;
  }

  public static function translate_system_page($value, string $option) {
    if (is_admin()) return $value;
    $id = (int) $value;
    if ($id <= 0) return $value;
    $language = Router::current_lang();
    if (EntityContract::post_source_locale($id) === $language) return $id;
    $target = EntityContract::existing_post_target($id, $language);
    return $target > 0 ? $target : $id;
  }
}
