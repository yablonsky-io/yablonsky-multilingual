<?php
namespace Yablonsky\Multilingual\Compat;

use Yablonsky\Multilingual\Languages;
use Yablonsky\Multilingual\TermTranslations;
use Yablonsky\Multilingual\Translations;

if (!defined('ABSPATH')) exit;

/**
 * Full WooCommerce multilingual commerce synchronization.
 *
 * Localized editorial content remains owned by each translated product.
 * Operational commerce state is synchronized across one translation family.
 * Relationship IDs are mapped into the target locale before assignment.
 */
final class WooCommerceRuntime {
  private const VAR_GROUP_META = '_ypm_variation_group';
  private const VAR_LANG_META = '_ypm_variation_language';
  private const VAR_BATCH = 100;
  private const VAR_CRON_HOOK = 'yablonsky_multilingual_variation_sync';

  private static bool $syncing = false;

  public static function init(): void {
    if (!Woo::is_active()) return;

    add_action('yablonsky_multilingual_woocommerce_translation_draft_initialized', [__CLASS__, 'initialize_product_translation'], 10, 2);
    add_action('woocommerce_update_product', [__CLASS__, 'sync_product_family'], 40, 1);
    add_action('woocommerce_new_product', [__CLASS__, 'sync_product_family'], 40, 1);
    add_action('woocommerce_update_product_variation', [__CLASS__, 'sync_variation_family'], 40, 1);
    add_action('woocommerce_new_product_variation', [__CLASS__, 'sync_variation_family'], 40, 1);

    // Woo <= 8.x uses this result as "SKU found". Returning false allows a
    // duplicate only when it belongs to the same YMulti commerce family.
    add_filter('wc_product_has_unique_sku', [__CLASS__, 'filter_sku_found'], 10, 3);
    // Woo 9+ can short-circuit before the data-store lookup.
    add_filter('wc_product_pre_has_unique_sku', [__CLASS__, 'filter_pre_unique_sku'], 10, 3);
    add_action(self::VAR_CRON_HOOK, [__CLASS__, 'cron_variation_sync'], 10, 2);
  }

  public static function initialize_product_translation(int $source_id, int $target_id): void {
    if (self::$syncing || !function_exists('wc_get_product')) return;
    if (get_post_type($source_id) !== 'product' || get_post_type($target_id) !== 'product') return;

    $source = wc_get_product($source_id);
    $target = wc_get_product($target_id);
    if (!$source || !$target) return;

    $language = Translations::get_post_language($target_id);
    self::$syncing = true;
    try {
      self::apply_product_state($source, $target, $language, true);
      self::ensure_variation_siblings($source_id);
    } finally {
      self::$syncing = false;
    }
  }

  public static function sync_product_family(int $source_id): void {
    if (self::$syncing || !function_exists('wc_get_product')) return;
    if (get_post_type($source_id) !== 'product') return;

    $source = wc_get_product($source_id);
    if (!$source) return;

    $group = Translations::group_id($source_id);
    if ($group === '') return;
    $siblings = Translations::translations_by_language_for_group($group);
    if (count($siblings) < 2) return;

    self::$syncing = true;
    try {
      foreach ($siblings as $language => $target_id) {
        $target_id = (int) $target_id;
        if ($target_id === $source_id || get_post_type($target_id) !== 'product') continue;
        $target = wc_get_product($target_id);
        if (!$target) continue;
        self::apply_product_state($source, $target, (string) $language, false);
      }
      self::ensure_variation_siblings($source_id);
    } finally {
      self::$syncing = false;
    }
  }

  /**
   * Synchronize state which identifies one sellable item across localized UI.
   * Editorial/localized fields are deliberately excluded.
   *
   * @param \WC_Product $source
   * @param \WC_Product $target
   */
  private static function apply_product_state($source, $target, string $language, bool $initial): void {
    $props = [
      'sku' => $source->get_sku('edit'),
      'regular_price' => $source->get_regular_price('edit'),
      'sale_price' => $source->get_sale_price('edit'),
      'date_on_sale_from' => $source->get_date_on_sale_from('edit'),
      'date_on_sale_to' => $source->get_date_on_sale_to('edit'),
      'manage_stock' => $source->get_manage_stock('edit'),
      'stock_quantity' => $source->get_stock_quantity('edit'),
      'stock_status' => $source->get_stock_status('edit'),
      'backorders' => $source->get_backorders('edit'),
      'sold_individually' => $source->get_sold_individually('edit'),
      'tax_status' => $source->get_tax_status('edit'),
      'tax_class' => $source->get_tax_class('edit'),
      'weight' => $source->get_weight('edit'),
      'length' => $source->get_length('edit'),
      'width' => $source->get_width('edit'),
      'height' => $source->get_height('edit'),
      'shipping_class_id' => $source->get_shipping_class_id('edit'),
      'virtual' => $source->get_virtual('edit'),
      'downloadable' => $source->get_downloadable('edit'),
      'downloads' => $source->get_downloads('edit'),
      'download_limit' => $source->get_download_limit('edit'),
      'download_expiry' => $source->get_download_expiry('edit'),
      'image_id' => $source->get_image_id('edit'),
      'gallery_image_ids' => $source->get_gallery_image_ids('edit'),
      'reviews_allowed' => $source->get_reviews_allowed('edit'),
    ];

    if (method_exists($source, 'get_low_stock_amount') && method_exists($target, 'set_low_stock_amount')) {
      $props['low_stock_amount'] = $source->get_low_stock_amount('edit');
    }

    // External URL is commerce behavior; button text remains localized.
    if (method_exists($source, 'get_product_url') && method_exists($target, 'set_product_url')) {
      $props['product_url'] = $source->get_product_url('edit');
    }

    try {
      $target->set_props($props);
    } catch (\Exception $e) {
      self::log('Shared product state rejected', ['source' => $source->get_id(), 'target' => $target->get_id(), 'message' => $e->getMessage()]);
      return;
    }

    $target->set_category_ids(self::map_term_ids($source->get_category_ids('edit'), 'product_cat', $language));
    $target->set_tag_ids(self::map_term_ids($source->get_tag_ids('edit'), 'product_tag', $language));
    $target->set_upsell_ids(self::map_product_ids($source->get_upsell_ids('edit'), $language));
    $target->set_cross_sell_ids(self::map_product_ids($source->get_cross_sell_ids('edit'), $language));

    if (method_exists($source, 'get_children') && method_exists($target, 'set_children') && $source->is_type('grouped')) {
      $target->set_children(self::map_product_ids($source->get_children(), $language));
    }

    self::set_localized_attributes($source, $target, $language, $initial);
    $target->save();

    // Brands are extension-dependent and do not have a WC_Product setter.
    if (taxonomy_exists('product_brand')) {
      $brand_ids = wp_get_object_terms($source->get_id(), 'product_brand', ['fields' => 'ids']);
      if (!is_wp_error($brand_ids)) {
        wp_set_object_terms($target->get_id(), self::map_term_ids(array_map('intval', $brand_ids), 'product_brand', $language), 'product_brand', false);
      }
    }
  }

  /**
   * Map global taxonomy attributes while preserving custom localized values.
   * On initial translation custom values are copied as editable starting text.
   *
   * @param \WC_Product $source
   * @param \WC_Product $target
   */
  private static function set_localized_attributes($source, $target, string $language, bool $initial): void {
    $source_attributes = $source->get_attributes('edit');
    $target_attributes = $target->get_attributes('edit');
    $result = [];

    foreach ($source_attributes as $key => $attribute) {
      if (!is_object($attribute) || !method_exists($attribute, 'is_taxonomy')) continue;

      if ($attribute->is_taxonomy()) {
        $copy = clone $attribute;
        $taxonomy = (string) $attribute->get_name();
        $options = array_map('intval', (array) $attribute->get_options());
        $copy->set_options(self::map_term_ids($options, $taxonomy, $language));
        $result[$key] = $copy;
      } elseif ($initial) {
        $result[$key] = clone $attribute;
      } elseif (isset($target_attributes[$key])) {
        // Custom attribute labels/options are localized editorial content.
        $result[$key] = $target_attributes[$key];
      }
    }

    // Preserve target-only custom attributes rather than silently deleting them.
    if (!$initial) {
      foreach ($target_attributes as $key => $attribute) {
        if (!isset($result[$key]) && is_object($attribute) && method_exists($attribute, 'is_taxonomy') && !$attribute->is_taxonomy()) {
          $result[$key] = $attribute;
        }
      }
    }

    $target->set_attributes($result);
  }

  /** @param int[] $ids @return int[] */
  private static function map_product_ids(array $ids, string $language): array {
    $out = [];
    foreach (array_values(array_unique(array_filter(array_map('intval', $ids)))) as $id) {
      $mapped = Translations::get_translation($id, $language);
      if ($mapped) $out[] = (int) $mapped;
      elseif (Translations::get_post_language($id) === $language) $out[] = $id;
    }
    return array_values(array_unique($out));
  }

  /** @param int[] $term_ids @return int[] */
  private static function map_term_ids(array $term_ids, string $taxonomy, string $language): array {
    $term_ids = array_values(array_unique(array_filter(array_map('intval', $term_ids))));
    if (!$term_ids || !TermTranslations::is_supported_taxonomy($taxonomy)) return $term_ids;

    TermTranslations::prime_translation_families($term_ids, $taxonomy);
    $out = [];
    foreach ($term_ids as $term_id) {
      $group = TermTranslations::group_id($term_id);
      if ($group === '') {
        if (TermTranslations::get_term_language($term_id) === $language) $out[] = $term_id;
        continue;
      }
      $family = TermTranslations::translations_by_language_for_group($taxonomy, $group);
      if (isset($family[$language])) $out[] = (int) $family[$language];
    }
    return array_values(array_unique($out));
  }

  public static function sync_variation_family(int $variation_id): void {
    if (self::$syncing || !function_exists('wc_get_product')) return;
    if (get_post_type($variation_id) !== 'product_variation') return;

    $source = wc_get_product($variation_id);
    if (!$source) return;
    $parent_id = (int) $source->get_parent_id();
    if ($parent_id <= 0) return;

    $language = Translations::get_post_language($parent_id);
    update_post_meta($variation_id, self::VAR_LANG_META, $language);
    $group = sanitize_title((string) get_post_meta($variation_id, self::VAR_GROUP_META, true));
    if ($group === '') {
      $group = sanitize_title(wp_generate_uuid4());
      update_post_meta($variation_id, self::VAR_GROUP_META, $group);
    }

    self::$syncing = true;
    try {
      self::ensure_single_variation_family($variation_id, $group);
      self::sync_existing_variation_siblings($variation_id, $group);
    } finally {
      self::$syncing = false;
    }
  }

  private static function ensure_variation_siblings(int $source_parent_id, int $cursor = 0): void {
    global $wpdb;
    if (!function_exists('wc_get_product')) return;
    $parent = wc_get_product($source_parent_id);
    if (!$parent || !$parent->is_type('variable')) return;

    // Admin/write path only. Use an ID cursor so large variable products never
    // load or synchronize the full variation catalog in one PHP request.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $ids = $wpdb->get_col($wpdb->prepare(
      "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product_variation' AND post_parent = %d AND ID > %d AND post_status <> 'trash' ORDER BY ID ASC LIMIT %d",
      $source_parent_id,
      $cursor,
      self::VAR_BATCH
    ));
    if (!$ids) return;

    $last = $cursor;
    foreach ($ids as $variation_raw) {
      $variation_id = (int) $variation_raw;
      $last = max($last, $variation_id);
      if ($variation_id <= 0) continue;
      $group = sanitize_title((string) get_post_meta($variation_id, self::VAR_GROUP_META, true));
      if ($group === '') {
        $group = sanitize_title(wp_generate_uuid4());
        update_post_meta($variation_id, self::VAR_GROUP_META, $group);
      }
      update_post_meta($variation_id, self::VAR_LANG_META, Translations::get_post_language($source_parent_id));
      self::ensure_single_variation_family($variation_id, $group);
      self::sync_existing_variation_siblings($variation_id, $group);
    }

    if (count($ids) >= self::VAR_BATCH && !wp_next_scheduled(self::VAR_CRON_HOOK, [$source_parent_id, $last])) {
      wp_schedule_single_event(time() + 5, self::VAR_CRON_HOOK, [$source_parent_id, $last]);
    }
  }

  public static function cron_variation_sync(int $source_parent_id, int $cursor = 0): void {
    if (self::$syncing) return;
    self::$syncing = true;
    try {
      self::ensure_variation_siblings($source_parent_id, $cursor);
    } finally {
      self::$syncing = false;
    }
  }

  private static function ensure_single_variation_family(int $source_variation_id, string $group): void {
    if (!function_exists('wc_get_product')) return;
    $source = wc_get_product($source_variation_id);
    if (!$source || !$source->is_type('variation')) return;

    $source_parent = (int) $source->get_parent_id();
    $parent_group = Translations::group_id($source_parent);
    if ($parent_group === '') return;
    $parents = Translations::translations_by_language_for_group($parent_group);

    $existing = self::variation_siblings_by_language($group);
    foreach ($parents as $language => $target_parent_id) {
      $language = (string) $language;
      $target_parent_id = (int) $target_parent_id;
      if (isset($existing[$language]) || $target_parent_id <= 0) continue;

      $new_id = wp_insert_post([
        'post_type' => 'product_variation',
        'post_status' => get_post_status($source_variation_id) ?: 'publish',
        'post_parent' => $target_parent_id,
        'menu_order' => (int) get_post_field('menu_order', $source_variation_id),
        'post_excerpt' => (string) get_post_field('post_excerpt', $source_variation_id),
      ], true);
      if (is_wp_error($new_id) || !$new_id) continue;

      update_post_meta((int) $new_id, self::VAR_GROUP_META, $group);
      update_post_meta((int) $new_id, self::VAR_LANG_META, $language);
      $target = wc_get_product((int) $new_id);
      if ($target) self::apply_variation_state($source, $target, $language, true);
      $existing[$language] = (int) $new_id;
    }
  }

  private static function sync_existing_variation_siblings(int $source_variation_id, string $group): void {
    if (!function_exists('wc_get_product')) return;
    $source = wc_get_product($source_variation_id);
    if (!$source || !$source->is_type('variation')) return;

    foreach (self::variation_siblings_by_language($group) as $language => $target_id) {
      $target_id = (int) $target_id;
      if ($target_id === $source_variation_id) continue;
      $target = wc_get_product($target_id);
      if ($target) self::apply_variation_state($source, $target, (string) $language, false);
    }
  }

  /** @param \WC_Product_Variation $source @param \WC_Product_Variation $target */
  private static function apply_variation_state($source, $target, string $language, bool $initial): void {
    $props = [
      'sku' => $source->get_sku('edit'),
      'regular_price' => $source->get_regular_price('edit'),
      'sale_price' => $source->get_sale_price('edit'),
      'date_on_sale_from' => $source->get_date_on_sale_from('edit'),
      'date_on_sale_to' => $source->get_date_on_sale_to('edit'),
      'manage_stock' => $source->get_manage_stock('edit'),
      'stock_quantity' => $source->get_stock_quantity('edit'),
      'stock_status' => $source->get_stock_status('edit'),
      'backorders' => $source->get_backorders('edit'),
      'weight' => $source->get_weight('edit'),
      'length' => $source->get_length('edit'),
      'width' => $source->get_width('edit'),
      'height' => $source->get_height('edit'),
      'shipping_class_id' => $source->get_shipping_class_id('edit'),
      'virtual' => $source->get_virtual('edit'),
      'downloadable' => $source->get_downloadable('edit'),
      'downloads' => $source->get_downloads('edit'),
      'download_limit' => $source->get_download_limit('edit'),
      'download_expiry' => $source->get_download_expiry('edit'),
      'image_id' => $source->get_image_id('edit'),
      'attributes' => self::map_variation_attributes($source->get_attributes('edit'), $language),
    ];
    if ($initial) $props['description'] = $source->get_description('edit');

    try {
      $target->set_props($props);
      $target->save();
    } catch (\Exception $e) {
      self::log('Variation state rejected', ['source' => $source->get_id(), 'target' => $target->get_id(), 'message' => $e->getMessage()]);
    }
  }

  /** @param array<string,string> $attributes @return array<string,string> */
  private static function map_variation_attributes(array $attributes, string $language): array {
    $out = [];
    foreach ($attributes as $taxonomy => $value) {
      $taxonomy = (string) $taxonomy;
      $value = (string) $value;
      if ($value === '' || !taxonomy_exists($taxonomy) || strpos($taxonomy, 'pa_') !== 0) {
        $out[$taxonomy] = $value;
        continue;
      }
      $term = get_term_by('slug', $value, $taxonomy);
      if (!($term instanceof \WP_Term)) {
        $out[$taxonomy] = $value;
        continue;
      }
      $mapped = self::map_term_ids([(int) $term->term_id], $taxonomy, $language);
      if ($mapped) {
        $target = get_term((int) $mapped[0], $taxonomy);
        $out[$taxonomy] = $target instanceof \WP_Term ? (string) $target->slug : $value;
      } else {
        $out[$taxonomy] = '';
      }
    }
    return $out;
  }

  /** @return array<string,int> */
  private static function variation_siblings_by_language(string $group): array {
    global $wpdb;
    $group = sanitize_title($group);
    if ($group === '') return [];

    // Admin/product-save path only; a variation family is bounded by locale count.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT p.ID, lm.meta_value AS language
       FROM {$wpdb->posts} p
       INNER JOIN {$wpdb->postmeta} gm ON gm.post_id = p.ID AND gm.meta_key = %s AND gm.meta_value = %s
       LEFT JOIN {$wpdb->postmeta} lm ON lm.post_id = p.ID AND lm.meta_key = %s
       WHERE p.post_type = 'product_variation' AND p.post_status <> 'trash'",
      self::VAR_GROUP_META,
      $group,
      self::VAR_LANG_META
    ));

    $out = [];
    if (is_array($rows)) {
      foreach ($rows as $row) {
        if (!isset($row->ID)) continue;
        $language = isset($row->language) && (string) $row->language !== '' ? sanitize_text_field((string) $row->language) : Languages::default_code();
        if (!isset($out[$language])) $out[$language] = (int) $row->ID;
      }
    }
    return $out;
  }

  /** Woo <= 8.x: $sku_found=true means duplicate. */
  public static function filter_sku_found($sku_found, int $product_id, string $sku) {
    if (!$sku_found || $sku === '' || !function_exists('wc_get_product_id_by_sku')) return $sku_found;
    $existing_id = (int) wc_get_product_id_by_sku($sku);
    if ($existing_id > 0 && self::same_commerce_family($product_id, $existing_id)) return false;
    return $sku_found;
  }

  /** Woo 9+: return true only for an intentional same-family duplicate. */
  public static function filter_pre_unique_sku($pre, int $product_id, string $sku) {
    if ($sku === '' || !function_exists('wc_get_product_id_by_sku')) return $pre;
    $existing_id = (int) wc_get_product_id_by_sku($sku);
    if ($existing_id > 0 && self::same_commerce_family($product_id, $existing_id)) return true;
    return $pre;
  }

  private static function same_commerce_family(int $a, int $b): bool {
    if ($a <= 0 || $b <= 0) return false;
    $a_product = function_exists('wc_get_product') ? wc_get_product($a) : false;
    $b_product = function_exists('wc_get_product') ? wc_get_product($b) : false;
    if (!$a_product || !$b_product) return false;

    $a_variation = $a_product->is_type('variation');
    $b_variation = $b_product->is_type('variation');
    if ($a_variation !== $b_variation) return false;

    if ($a_variation) {
      $ga = sanitize_title((string) get_post_meta($a, self::VAR_GROUP_META, true));
      $gb = sanitize_title((string) get_post_meta($b, self::VAR_GROUP_META, true));
      return $ga !== '' && $ga === $gb;
    }

    $ga = Translations::group_id($a);
    $gb = Translations::group_id($b);
    return $ga !== '' && $ga === $gb;
  }

  private static function log(string $message, array $context = []): void {
    if (function_exists('wc_get_logger')) {
      $context['source'] = 'yablonsky-multilingual';
      wc_get_logger()->warning($message, $context);
    }
  }
}
