<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Background-only foreign-exchange rate provider. No provider request ever runs on a visitor request. */
final class FXRates {
  public const OPT = 'ymulti_fx_rates_v1';
  public const CRON_HOOK = 'ymulti_fx_refresh';
  public const BOOTSTRAP_HOOK = 'ymulti_fx_refresh_bootstrap';
  public const PROVIDER = 'frankfurter_ecb';
  private static ?array $cache = null;

  public static function reset_request_cache(): void {
    self::$cache = null;
  }

  public static function init(): void {
    add_action(self::CRON_HOOK, [__CLASS__, 'cron_refresh']);
    add_action(self::BOOTSTRAP_HOOK, [__CLASS__, 'cron_refresh']);
    if (is_admin()) add_action('admin_post_ymulti_fx_refresh', [__CLASS__, 'handle_refresh']);
  }

  /** @return array<string,mixed> */
  public static function state(): array {
    if (self::$cache !== null) return self::$cache;
    $raw = get_option(self::OPT, []);
    $raw = is_array($raw) ? $raw : [];
    return self::$cache = wp_parse_args($raw, [
      'provider' => self::PROVIDER,
      'base' => '',
      'rates' => [],
      'source_date' => '',
      'updated_at' => '',
      'last_attempt' => '',
      'last_error' => '',
    ]);
  }

  public static function sync_schedule(): void {
    if (!class_exists('Yablonsky\Multilingual\MultiCurrency')) return;
    $settings = MultiCurrency::settings();
    $automatic = (string) ($settings['rate_source'] ?? 'manual') === self::PROVIDER;
    $next = wp_next_scheduled(self::CRON_HOOK);
    if (!$automatic) {
      if ($next) wp_clear_scheduled_hook(self::CRON_HOOK);
      wp_clear_scheduled_hook(self::BOOTSTRAP_HOOK);
      return;
    }
    if (!$next) wp_schedule_event(time() + 60, 'twicedaily', self::CRON_HOOK);
    $state = self::state();
    if (empty($state['updated_at']) && !wp_next_scheduled(self::BOOTSTRAP_HOOK)) {
      wp_schedule_single_event(time() + 10, self::BOOTSTRAP_HOOK);
    }
  }

  public static function cron_refresh(): void {
    if (!class_exists('Yablonsky\Multilingual\MultiCurrency')) return;
    $settings = MultiCurrency::settings();
    if ((string) ($settings['rate_source'] ?? 'manual') !== self::PROVIDER) return;
    self::refresh();
  }

  /** @return array{ok:bool,message:string,count:int} */
  public static function refresh(): array {
    if (!class_exists('Yablonsky\Multilingual\MultiCurrency')) return ['ok' => false, 'message' => __('Multicurrency unavailable.', 'yablonsky-multilingual'), 'count' => 0];
    $settings = MultiCurrency::settings();
    $base = strtoupper(sanitize_key((string) ($settings['base'] ?? get_option('woocommerce_currency', 'USD'))));
    $quotes = array_values(array_filter(MultiCurrency::configured_currencies(), static function (string $code) use ($base): bool { return $code !== $base; }));
    $attempt = gmdate('c');
    if (!$quotes) {
      self::save_state(['provider' => self::PROVIDER, 'base' => $base, 'rates' => [], 'source_date' => '', 'updated_at' => $attempt, 'last_attempt' => $attempt, 'last_error' => '']);
      return ['ok' => true, 'message' => __('No non-base currencies require rates.', 'yablonsky-multilingual'), 'count' => 0];
    }
    $url = add_query_arg([
      'base' => strtolower($base),
      'quotes' => strtolower(implode(',', $quotes)),
    ], 'https://api.frankfurter.dev/v2/providers/ecb/rates');
    $response = wp_safe_remote_get($url, [
      'timeout' => 10,
      'redirection' => 0,
      'reject_unsafe_urls' => true,
      'limit_response_size' => 262144,
      'headers' => ['Accept' => 'application/json'],
      'user-agent' => 'Yablonsky-Multilingual/' . (defined('YPM_VERSION') ? YPM_VERSION : 'unknown'),
    ]);
    if (is_wp_error($response)) return self::record_error($attempt, $response->get_error_message());
    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status !== 200) return self::record_error($attempt, sprintf(
      /* translators: %d: upstream FX provider HTTP status code. */
      __('FX provider HTTP %d.', 'yablonsky-multilingual'),
      $status
    ));
    $body = wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) return self::record_error($attempt, __('FX provider returned invalid JSON.', 'yablonsky-multilingual'));
    $allow = array_fill_keys($quotes, true);
    $rates = [];
    $source_date = '';
    foreach ($decoded as $row) {
      if (!is_array($row)) continue;
      $quote = strtoupper(sanitize_key((string) ($row['quote'] ?? '')));
      $row_base = strtoupper(sanitize_key((string) ($row['base'] ?? '')));
      $rate = isset($row['rate']) && is_numeric($row['rate']) ? (float) $row['rate'] : 0.0;
      if ($row_base !== $base || !isset($allow[$quote]) || $rate <= 0 || !is_finite($rate)) continue;
      $rates[$quote] = $rate;
      $date = sanitize_text_field((string) ($row['date'] ?? ''));
      if ($date !== '' && ($source_date === '' || strcmp($date, $source_date) > 0)) $source_date = $date;
    }
    if (!$rates) return self::record_error($attempt, __('FX provider returned no usable configured rates.', 'yablonsky-multilingual'));
    ksort($rates);
    self::save_state(['provider' => self::PROVIDER, 'base' => $base, 'rates' => $rates, 'source_date' => $source_date, 'updated_at' => $attempt, 'last_attempt' => $attempt, 'last_error' => '']);
    return ['ok' => true, 'message' => __('Automatic exchange rates refreshed.', 'yablonsky-multilingual'), 'count' => count($rates)];
  }

  /** @return array{ok:bool,message:string,count:int} */
  private static function record_error(string $attempt, string $message): array {
    $state = self::state();
    $state['last_attempt'] = $attempt;
    $state['last_error'] = sanitize_text_field($message);
    self::save_state($state);
    return ['ok' => false, 'message' => (string) $state['last_error'], 'count' => 0];
  }

  /** @param array<string,mixed> $state */
  private static function save_state(array $state): void {
    update_option(self::OPT, $state, false);
    self::$cache = $state;
  }

  /** @return array<string,float> */
  public static function rates(): array {
    $state = self::state();
    $out = [];
    foreach ((array) ($state['rates'] ?? []) as $code => $rate) {
      $code = strtoupper(sanitize_key((string) $code));
      $number = is_numeric($rate) ? (float) $rate : 0.0;
      if ($code !== '' && $number > 0 && is_finite($number)) $out[$code] = $number;
    }
    return $out;
  }

  public static function is_fresh(int $max_age = 172800): bool {
    $state = self::state();
    $stamp = strtotime((string) ($state['updated_at'] ?? ''));
    return $stamp !== false && $stamp > 0 && (time() - $stamp) <= $max_age && self::rates() !== [];
  }

  public static function handle_refresh(): void {
    if (!current_user_can('manage_woocommerce')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ymulti_fx_refresh');
    $result = self::refresh();
    $args = ['page' => 'ypm-woocommerce', 'fx_refresh' => !empty($result['ok']) ? 'ok' : 'error'];
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')) . '#ymulti-multicurrency');
    exit;
  }
}
