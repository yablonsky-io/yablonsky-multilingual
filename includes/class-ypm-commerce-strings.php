<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Runtime-safe local overrides for common WooCommerce PHP strings.
 *
 * Standard WooCommerce language packs remain the primary translation source.
 * Overrides are loaded once per request for the current YPM locale and then
 * served from request memory; there is no SQL lookup per gettext call.
 */
final class CommerceStrings {
  private static bool $initialized = false;
  private const OPTION_PREFIX = 'ypm_woo_strings_';

  /** @var array<string,array<string,string>> */
  private static array $request_cache = [];

  /** Clear per-blog WooCommerce gettext overrides; keep hooks installed. */
  public static function reset_request_cache(): void {
    self::$request_cache = [];
  }

  public static function init(): void {
    if (self::$initialized) return;
    self::$initialized = true;

    add_filter('gettext', [__CLASS__, 'filter_gettext'], 30, 3);
    add_filter('gettext_with_context', [__CLASS__, 'filter_gettext_with_context'], 30, 4);
  }

  /**
   * Curated high-value WooCommerce UI strings for local overrides.
   * Native language packs can translate many more strings automatically.
   *
   * @return array<string,string>
   */
  public static function catalog(): array {
    return [
      'Add to cart' => __('Button: Add to cart', 'yablonsky-multilingual'),
      'Select options' => __('Button: Select options', 'yablonsky-multilingual'),
      'Read more' => __('Button: Read more', 'yablonsky-multilingual'),
      'Buy product' => __('Button: Buy product', 'yablonsky-multilingual'),
      'View cart' => __('Button: View cart', 'yablonsky-multilingual'),
      'Proceed to checkout' => __('Button: Proceed to checkout', 'yablonsky-multilingual'),
      'Place order' => __('Button: Place order', 'yablonsky-multilingual'),
      'Cart' => __('Label: Cart', 'yablonsky-multilingual'),
      'Checkout' => __('Label: Checkout', 'yablonsky-multilingual'),
      'Your order' => __('Label: Order summary', 'yablonsky-multilingual'),
      'Subtotal' => __('Label: Subtotal', 'yablonsky-multilingual'),
      'Shipping' => __('Label: Shipping', 'yablonsky-multilingual'),
      'Total' => __('Label: Total', 'yablonsky-multilingual'),
      'Order notes' => __('Label: Order notes', 'yablonsky-multilingual'),
      'Billing details' => __('Label: Billing details', 'yablonsky-multilingual'),
      'Shipping details' => __('Label: Shipping details', 'yablonsky-multilingual'),
      'Apply coupon' => __('Button: Apply coupon', 'yablonsky-multilingual'),
      'Coupon code' => __('Field: Coupon code', 'yablonsky-multilingual'),
      'Update cart' => __('Button: Update cart', 'yablonsky-multilingual'),
      'Continue shopping' => __('Button: Continue shopping', 'yablonsky-multilingual'),
      'My account' => __('Label: My account', 'yablonsky-multilingual'),
      'Orders' => __('Label: Orders', 'yablonsky-multilingual'),
      'Downloads' => __('Label: Downloads', 'yablonsky-multilingual'),
      'Addresses' => __('Label: Addresses', 'yablonsky-multilingual'),
      'Account details' => __('Label: Account details', 'yablonsky-multilingual'),
      'Logout' => __('Label: Logout', 'yablonsky-multilingual'),
      'Thank you. Your order has been received.' => __('Order received message', 'yablonsky-multilingual'),
    ];
  }

  public static function filter_gettext(string $translation, string $text, string $domain): string {
    if ($domain !== 'woocommerce') return $translation;

    $language = class_exists('Yablonsky\\Multilingual\\LocaleRuntime') ? LocaleRuntime::content_language() : '';
    if ($language === '') return $translation;

    $overrides = self::overrides($language);
    if (!isset($overrides[$text]) || $overrides[$text] === '') return $translation;
    return $overrides[$text];
  }

  public static function filter_gettext_with_context(string $translation, string $text, string $context, string $domain): string {
    return self::filter_gettext($translation, $text, $domain);
  }

  /**
   * @return array<string,string>
   */
  public static function overrides(string $language): array {
    if (isset(self::$request_cache[$language])) return self::$request_cache[$language];

    $raw = get_option(self::option_name($language), []);
    $clean = [];
    if (is_array($raw)) {
      foreach ($raw as $source => $translated) {
        if (!is_string($source) || !is_scalar($translated)) continue;
        $value = sanitize_textarea_field((string) $translated);
        if ($value !== '') $clean[(string) $source] = $value;
      }
    }

    self::$request_cache[$language] = $clean;
    return $clean;
  }

  /**
   * @param array<string,string> $values
   */
  public static function save_overrides(string $language, array $values): void {
    $clean = [];
    foreach (self::catalog() as $source => $description) {
      if (!isset($values[$source]) || !is_scalar($values[$source])) continue;
      $value = sanitize_textarea_field((string) $values[$source]);
      if ($value !== '') $clean[$source] = $value;
    }

    update_option(self::option_name($language), $clean, false);
    self::$request_cache[$language] = $clean;
  }

  private static function option_name(string $language): string {
    $key = sanitize_key(str_replace('-', '_', strtolower($language)));
    if ($key === '') $key = substr(md5($language), 0, 12);
    return self::OPTION_PREFIX . $key;
  }
}
