<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Optional locale-aware WooCommerce currency presentation.
 * Rates are administrator-owned and local; no remote FX call ever runs on a
 * visitor request. Orders retain the presented currency plus a rate snapshot.
 */
final class MultiCurrency {
  public const OPT = 'ymulti_multicurrency_v1';
  public const COOKIE = 'ymulti_currency';
  public const ORDER_META = '_ymulti_currency_snapshot';
  private static bool $filtering = false;
  private static ?array $settings_cache = null;
  private static ?string $currency_cache = null;

  public static function reset_request_cache(): void {
    self::$settings_cache = null;
    self::$currency_cache = null;
  }

  public static function init(): void {
    if (!(class_exists('WooCommerce') || function_exists('WC'))) return;
    add_filter('woocommerce_currency', [__CLASS__, 'filter_currency'], 90);
    foreach (['woocommerce_product_get_price','woocommerce_product_get_regular_price','woocommerce_product_get_sale_price','woocommerce_product_variation_get_price','woocommerce_product_variation_get_regular_price','woocommerce_product_variation_get_sale_price'] as $hook) {
      add_filter($hook, [__CLASS__, 'filter_price'], 90, 2);
    }
    foreach (['woocommerce_variation_prices_price','woocommerce_variation_prices_regular_price','woocommerce_variation_prices_sale_price'] as $hook) {
      add_filter($hook, [__CLASS__, 'filter_variation_price'], 90, 3);
    }
    add_action('woocommerce_checkout_create_order', [__CLASS__, 'snapshot_order'], 20, 2);
    add_action('template_redirect', [__CLASS__, 'handle_currency_choice'], -30);
    add_shortcode('ymulti_currency_switcher', [__CLASS__, 'shortcode']);
    if (is_admin()) add_action('admin_post_ymulti_save_multicurrency', [__CLASS__, 'handle_save']);
  }

  /** @return array<string,mixed> */
  public static function settings(): array {
    if (self::$settings_cache !== null) return self::$settings_cache;
    $base = sanitize_key((string) get_option('woocommerce_currency', 'USD'));
    $raw = get_option(self::OPT, []);
    $raw = is_array($raw) ? $raw : [];
    self::$settings_cache = wp_parse_args($raw, ['enabled' => 0, 'allow_override' => 0, 'base' => strtoupper($base), 'locale_currency' => [], 'rates' => [], 'rate_source' => 'manual']);
    return self::$settings_cache;
  }

  public static function enabled(): bool { $s = self::settings(); return !empty($s['enabled']); }

  public static function active_currency(): string {
    if (self::$currency_cache !== null) return self::$currency_cache;
    $settings = self::settings();
    $base = strtoupper((string) ($settings['base'] ?? get_option('woocommerce_currency', 'USD')));
    if (!self::enabled()) return self::$currency_cache = $base;
    if (!empty($settings['allow_override']) && isset($_COOKIE[self::COOKIE])) {
      $cookie = strtoupper(sanitize_key(wp_unslash($_COOKIE[self::COOKIE])));
      if (self::currency_is_configured($cookie, $settings)) return self::$currency_cache = $cookie;
    }
    $locale = Router::current_lang();
    $mapped = strtoupper(sanitize_key((string) (($settings['locale_currency'][$locale] ?? ''))));
    return self::$currency_cache = ($mapped !== '' && self::currency_is_configured($mapped, $settings) ? $mapped : $base);
  }

  /** @return string[] */
  public static function configured_currencies(): array {
    $settings = self::settings();
    $base = strtoupper((string) ($settings['base'] ?? get_option('woocommerce_currency', 'USD')));
    $codes = [$base];
    foreach ((array) ($settings['locale_currency'] ?? []) as $code) {
      $code = strtoupper(sanitize_key((string) $code));
      if ($code !== '') $codes[] = $code;
    }
    foreach ((array) ($settings['rates'] ?? []) as $code => $rate) {
      if ((float) $rate > 0) $codes[] = strtoupper(sanitize_key((string) $code));
    }
    return array_values(array_unique(array_filter($codes)));
  }

  /** @return array<string,float> */
  public static function effective_rates(): array {
    $settings = self::settings();
    $manual = [];
    foreach ((array) ($settings['rates'] ?? []) as $code => $rate) {
      $code = strtoupper(sanitize_key((string) $code));
      $number = is_numeric($rate) ? (float) $rate : 0.0;
      if ($code !== '' && $number > 0) $manual[$code] = $number;
    }
    if ((string) ($settings['rate_source'] ?? 'manual') === 'frankfurter_ecb' && class_exists('Yablonsky\Multilingual\FXRates')) {
      $automatic = FXRates::rates();
      // Last-known automatic values win. Manual rates remain a fail-safe for currencies not returned by the provider.
      return array_merge($manual, $automatic);
    }
    return $manual;
  }

  /** @param array<string,mixed>|null $settings */
  private static function currency_is_configured(string $currency, ?array $settings = null): bool {
    $settings = $settings ?: self::settings();
    $base = strtoupper((string) ($settings['base'] ?? ''));
    if ($currency === $base) return true;
    $rates = self::effective_rates();
    return isset($rates[$currency]) && (float) $rates[$currency] > 0.0;
  }

  public static function rate_for(string $currency): float {
    $settings = self::settings();
    $currency = strtoupper($currency);
    $base = strtoupper((string) ($settings['base'] ?? ''));
    if ($currency === $base) return 1.0;
    $rates = self::effective_rates();
    $rate = isset($rates[$currency]) ? (float) $rates[$currency] : 0.0;
    return $rate > 0 ? $rate : 1.0;
  }

  public static function filter_currency(string $currency): string {
    if (!self::should_convert()) return $currency;
    return self::active_currency();
  }

  /** @param mixed $price @param mixed $product @return mixed */
  public static function filter_price($price, $product) {
    unset($product);
    return self::convert_price($price);
  }

  /** @param mixed $price @param mixed $variation @param mixed $product @return mixed */
  public static function filter_variation_price($price, $variation, $product) {
    unset($variation, $product);
    return self::convert_price($price);
  }

  /** @param mixed $price @return mixed */
  private static function convert_price($price) {
    if (!self::should_convert() || self::$filtering) return $price;
    if ($price === '' || $price === null || !is_numeric($price)) return $price;
    $currency = self::active_currency();
    $settings = self::settings();
    $base = strtoupper((string) ($settings['base'] ?? ''));
    if ($currency === $base) return $price;
    $rate = self::rate_for($currency);
    if ($rate <= 0 || abs($rate - 1.0) < 0.0000001) return $price;
    self::$filtering = true;
    $converted = (float) $price * $rate;
    self::$filtering = false;
    return wc_format_decimal($converted, wc_get_price_decimals());
  }

  private static function should_convert(): bool {
    if (!self::enabled()) return false;
    if (is_admin() && !wp_doing_ajax()) return false;
    if (defined('REST_REQUEST') && REST_REQUEST) return false;
    if (wp_doing_cron()) return false;
    return true;
  }

  /** @param \WC_Order $order @param array<string,mixed> $data */
  public static function snapshot_order($order, array $data): void {
    unset($data);
    if (!self::enabled() || !is_object($order) || !method_exists($order, 'update_meta_data')) return;
    $currency = self::active_currency();
    $settings = self::settings();
    $snapshot = [
      'base_currency' => strtoupper((string) ($settings['base'] ?? '')),
      'presented_currency' => $currency,
      'rate' => self::rate_for($currency),
      'language' => Router::current_lang(),
      'rate_source' => (string) ($settings['rate_source'] ?? 'manual'),
      'rate_source_date' => class_exists('Yablonsky\Multilingual\FXRates') ? (string) (FXRates::state()['source_date'] ?? '') : '',
      'captured_at' => gmdate('c'),
    ];
    $order->update_meta_data(self::ORDER_META, $snapshot);
  }

  public static function handle_currency_choice(): void {
    if (!self::enabled() || empty(self::settings()['allow_override'])) return;
    $currency_raw = filter_input(INPUT_GET, 'ymulti_currency', FILTER_UNSAFE_RAW);
    $nonce_raw = filter_input(INPUT_GET, 'ymulti_currency_nonce', FILTER_UNSAFE_RAW);
    if (!is_string($currency_raw) || !is_string($nonce_raw)) return;
    if (!wp_verify_nonce(sanitize_text_field($nonce_raw), 'ymulti_currency_choice')) return;
    $currency = strtoupper(sanitize_key($currency_raw));
    if (!self::currency_is_configured($currency)) return;
    if (!headers_sent()) setcookie(self::COOKIE, $currency, time() + MONTH_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
    $uri_raw = filter_input(INPUT_SERVER, 'REQUEST_URI', FILTER_UNSAFE_RAW);
    $uri = is_string($uri_raw) ? sanitize_text_field($uri_raw) : '/';
    $target = remove_query_arg(['ymulti_currency', 'ymulti_currency_nonce'], home_url($uri));
    wp_safe_redirect($target, 302, 'YMulti currency choice');
    exit;
  }

  public static function shortcode(): string {
    if (!self::enabled() || empty(self::settings()['allow_override'])) return '';
    $settings = self::settings();
    $currencies = function_exists('get_woocommerce_currencies') ? get_woocommerce_currencies() : [];
    $configured = [strtoupper((string) $settings['base'])];
    foreach (self::effective_rates() as $code => $rate) if ((float) $rate > 0) $configured[] = strtoupper((string) $code);
    $configured = array_values(array_unique($configured));
    ob_start();
    echo '<form method="get" class="ymulti-currency-switcher">';
    wp_nonce_field('ymulti_currency_choice', 'ymulti_currency_nonce', false);
    echo '<label><span class="screen-reader-text">' . esc_html__('Currency', 'yablonsky-multilingual') . '</span><select name="ymulti_currency">';
    $active = self::active_currency();
    foreach ($configured as $code) {
      $label = isset($currencies[$code]) ? (string) $currencies[$code] : $code;
      echo '<option value="' . esc_attr($code) . '"' . selected($active, $code, false) . '>' . esc_html($code . ' · ' . $label) . '</option>';
    }
    echo '</select></label><button type="submit">' . esc_html__('Change currency', 'yablonsky-multilingual') . '</button></form>';
    return (string) ob_get_clean();
  }

  public static function handle_save(): void {
    if (!current_user_can('manage_woocommerce')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ymulti_save_multicurrency');
    $base = strtoupper(sanitize_key((string) get_option('woocommerce_currency', 'USD')));
    $locale_currency = [];
    $raw_locale = filter_input(INPUT_POST, 'locale_currency', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
    $raw_locale = is_array($raw_locale) ? $raw_locale : [];
    foreach (Languages::active_codes() as $locale) {
      $code = strtoupper(sanitize_key((string) ($raw_locale[$locale] ?? '')));
      if ($code !== '') $locale_currency[$locale] = $code;
    }
    $rates = [];
    $raw_rates = filter_input(INPUT_POST, 'rates', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
    $raw_rates = is_array($raw_rates) ? $raw_rates : [];
    foreach ($raw_rates as $code => $rate) {
      $code = strtoupper(sanitize_key((string) $code));
      $number = is_scalar($rate) ? (float) str_replace(',', '.', (string) $rate) : 0.0;
      if ($code !== '' && $code !== $base && $number > 0) $rates[$code] = $number;
    }
    $rate_source_raw = filter_input(INPUT_POST, 'rate_source', FILTER_UNSAFE_RAW);
    $rate_source = is_string($rate_source_raw) && $rate_source_raw === 'frankfurter_ecb' ? 'frankfurter_ecb' : 'manual';
    $saved = [
      'enabled' => filter_input(INPUT_POST, 'enabled', FILTER_UNSAFE_RAW) !== null ? 1 : 0,
      'allow_override' => filter_input(INPUT_POST, 'allow_override', FILTER_UNSAFE_RAW) !== null ? 1 : 0,
      'base' => $base,
      'locale_currency' => $locale_currency,
      'rates' => $rates,
      'rate_source' => $rate_source,
    ];
    update_option(self::OPT, $saved, false);
    self::$settings_cache = $saved;
    self::$currency_cache = null;
    if (class_exists('Yablonsky\Multilingual\FXRates')) FXRates::sync_schedule();
    wp_safe_redirect(admin_url('admin.php?page=ypm-woocommerce&currency_saved=1#ymulti-multicurrency'));
    exit;
  }


public static function render_panel(): void {
  if (!current_user_can('manage_woocommerce')) return;
  $settings = self::settings();
  $base = strtoupper((string) ($settings['base'] ?? get_option('woocommerce_currency', 'USD')));
  $currencies = function_exists('get_woocommerce_currencies') ? get_woocommerce_currencies() : [];
  $codes = array_keys(is_array($currencies) ? $currencies : []);
  $used = array_values(array_unique(array_filter(array_merge([$base], array_values((array) ($settings['locale_currency'] ?? []))))));
  $rate_source = (string) ($settings['rate_source'] ?? 'manual');
  $fx_state = class_exists('Yablonsky\Multilingual\FXRates') ? FXRates::state() : [];
  $automatic_fresh = class_exists('Yablonsky\Multilingual\FXRates') && FXRates::is_fresh();
  $effective = self::effective_rates();

  echo '<section class="ypm-card ypm-card--standalone" id="ymulti-multicurrency"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Multicurrency', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Locale-aware currency presentation', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('Choose merchant-owned manual rates or cached automatic reference rates. Remote FX requests run in admin/background jobs, while storefront browsing and checkout use local cached rates.', 'yablonsky-multilingual') . '</p></div>' . wp_kses_post(Admin::status_badge(!empty($settings['enabled']) ? 'pass' : 'neutral', !empty($settings['enabled']) ? __('Enabled', 'yablonsky-multilingual') : __('Optional', 'yablonsky-multilingual'))) . '</div>';
  echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ymulti-currency-form"><input type="hidden" name="action" value="ymulti_save_multicurrency">';
  wp_nonce_field('ymulti_save_multicurrency');
  echo '<div class="ymulti-routing-options"><label><input type="checkbox" name="enabled" value="1"' . checked(!empty($settings['enabled']), true, false) . '><span><strong>' . esc_html__('Enable locale currencies', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Base store prices remain authoritative; display and checkout values use the locally cached effective rate.', 'yablonsky-multilingual') . '</small></span></label><label><input type="checkbox" name="allow_override" value="1"' . checked(!empty($settings['allow_override']), true, false) . '><span><strong>' . esc_html__('Allow shopper override', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Enables the [ymulti_currency_switcher] shortcode. Otherwise currency follows the current content language.', 'yablonsky-multilingual') . '</small></span></label></div>';

  echo '<div class="ymulti-fx-source-grid"><label class="ymulti-fx-source' . ($rate_source === 'manual' ? ' is-selected' : '') . '"><input type="radio" name="rate_source" value="manual"' . checked($rate_source, 'manual', false) . '><span><strong>' . esc_html__('Manual rates', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('You control every rate. Best for contractual pricing or a small currency set.', 'yablonsky-multilingual') . '</small></span></label><label class="ymulti-fx-source' . ($rate_source === 'frankfurter_ecb' ? ' is-selected' : '') . '"><input type="radio" name="rate_source" value="frankfurter_ecb"' . checked($rate_source, 'frankfurter_ecb', false) . '><span><strong>' . esc_html__('Automatic · ECB reference feed', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Fetched through the open Frankfurter API pinned to the ECB provider, cached locally and refreshed twice daily.', 'yablonsky-multilingual') . '</small></span></label></div>';

  echo '<div class="ymulti-currency-summary"><strong>' . esc_html__('Base currency', 'yablonsky-multilingual') . '</strong><code>' . esc_html($base) . '</code><span>' . esc_html__('1 base unit = effective target rate', 'yablonsky-multilingual') . '</span></div><div class="ymulti-domain-grid">';
  foreach (Languages::active_codes() as $locale) {
    $selected = strtoupper((string) (($settings['locale_currency'][$locale] ?? $base)));
    echo '<label><span><strong>' . esc_html(Languages::display_name($locale)) . '</strong><small>' . esc_html(Languages::display_code($locale)) . '</small></span><select name="locale_currency[' . esc_attr($locale) . ']">';
    foreach ($codes as $code) echo '<option value="' . esc_attr($code) . '"' . selected($selected, $code, false) . '>' . esc_html($code) . '</option>';
    echo '</select></label>';
  }
  echo '</div><h3>' . esc_html__('Manual rates / automatic fallback', 'yablonsky-multilingual') . '</h3><p class="ypm-card__copy">' . esc_html__('Manual values are authoritative in Manual mode. In Automatic mode they remain a safe fallback whenever the provider response omits a configured currency.', 'yablonsky-multilingual') . '</p><div class="ymulti-currency-rates">';
  foreach ($used as $code) {
    $code = strtoupper((string) $code); if ($code === $base) continue;
    $rate = isset($settings['rates'][$code]) ? (string) $settings['rates'][$code] : '';
    echo '<label><span>1 ' . esc_html($base) . ' =</span><input type="number" min="0" step="0.000001" name="rates[' . esc_attr($code) . ']" value="' . esc_attr($rate) . '"><strong>' . esc_html($code) . '</strong></label>';
  }
  if (count($used) <= 1) echo '<p class="ypm-empty-state">' . esc_html__('Choose a non-base currency for at least one language to configure its rate.', 'yablonsky-multilingual') . '</p>';
  echo '</div><p><button class="button button-primary" type="submit">' . esc_html__('Save multicurrency settings', 'yablonsky-multilingual') . '</button></p></form>';

  if ($rate_source === 'frankfurter_ecb') {
    $updated = sanitize_text_field((string) ($fx_state['updated_at'] ?? ''));
    $source_date = sanitize_text_field((string) ($fx_state['source_date'] ?? ''));
    $error = sanitize_text_field((string) ($fx_state['last_error'] ?? ''));
    echo '<div class="ymulti-fx-status"><div><span class="ypm-eyebrow">' . esc_html__('Automatic rate cache', 'yablonsky-multilingual') . '</span><strong>' . esc_html($automatic_fresh ? __('Fresh local rates', 'yablonsky-multilingual') : __('Refresh recommended', 'yablonsky-multilingual')) . '</strong><small>' . esc_html(sprintf(
      /* translators: 1: provider source date, 2: local refresh timestamp. */
      __('Provider date: %1$s · cached: %2$s', 'yablonsky-multilingual'),
      $source_date !== '' ? $source_date : '—',
      $updated !== '' ? $updated : '—'
    )) . '</small>';
    if ($error !== '') echo '<small class="ymulti-fx-status__error">' . esc_html($error) . '</small>';
    echo '</div><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ymulti_fx_refresh">';
    wp_nonce_field('ymulti_fx_refresh');
    echo '<button class="button" type="submit">' . esc_html__('Refresh rates now', 'yablonsky-multilingual') . '</button></form></div>';
  }

  if ($effective) {
    echo '<details class="ypm-disclosure ypm-disclosure--wide ymulti-effective-rates"><summary><span><strong>' . esc_html__('Effective cached rates', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Rates actually used by storefront calculations after automatic/manual fallback resolution.', 'yablonsky-multilingual') . '</small></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary><div class="ypm-disclosure__body"><div class="ymulti-chip-grid">';
    foreach ($effective as $code => $rate) echo '<span><strong>1 ' . esc_html($base) . '</strong> = ' . esc_html((string) $rate) . ' ' . esc_html($code) . '</span>';
    echo '</div></div></details>';
  }
  echo '<p class="ymulti-fx-disclaimer">' . esc_html__('Automatic values are reference rates. Card processor or bank settlement rates remain externally authoritative, and the merchant owns final pricing, tax and accounting policy.', 'yablonsky-multilingual') . '</p></section>';
}

}
