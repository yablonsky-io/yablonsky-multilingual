<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Code-level performance contract for the public multilingual hot path. */
final class RuntimeContract {
  public static function init(): void {}

  /** @return array<string,mixed> */
  public static function capabilities(): array {
    return [
      'visitor_ai_calls' => false,
      'visitor_string_discovery_writes' => false,
      'visitor_translation_scans' => false,
      'browser_ip_lookup' => false,
      'remote_fx_on_request' => false,
      'domain_mapping_local_option' => true,
      'currency_rates_local_option' => true,
      'request_local_url_cache' => true,
      'bounded_translation_family_prime' => true,
      'admin_coverage_only' => true,
    ];
  }

  public static function render_status_panel(): void {
    if (!current_user_can('manage_options')) return;
    $caps = self::capabilities();
    echo '<section class="ypm-card ypm-card--standalone"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Performance contract', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Frontend hot-path guardrails', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('Feature parity keeps visitor requests local and bounded. Admin scanners, AI, coverage audits and launch checks run in administrator or background contexts.', 'yablonsky-multilingual') . '</p></div>' . wp_kses_post(Admin::status_badge('pass', __('Guarded', 'yablonsky-multilingual'))) . '</div><div class="ymulti-runtime-contract">';
    $rows = [
      __('AI on visitor requests', 'yablonsky-multilingual') => empty($caps['visitor_ai_calls']) ? __('None', 'yablonsky-multilingual') : __('Detected', 'yablonsky-multilingual'),
      __('Remote FX on visitor requests', 'yablonsky-multilingual') => empty($caps['remote_fx_on_request']) ? __('None', 'yablonsky-multilingual') : __('Detected', 'yablonsky-multilingual'),
      __('String discovery writes', 'yablonsky-multilingual') => empty($caps['visitor_string_discovery_writes']) ? __('Admin only', 'yablonsky-multilingual') : __('Visitor path', 'yablonsky-multilingual'),
      __('Coverage scans', 'yablonsky-multilingual') => !empty($caps['admin_coverage_only']) ? __('Admin only', 'yablonsky-multilingual') : __('Visitor path', 'yablonsky-multilingual'),
      __('Browser detection', 'yablonsky-multilingual') => empty($caps['browser_ip_lookup']) ? __('Accept-Language only', 'yablonsky-multilingual') : __('IP lookup', 'yablonsky-multilingual'),
      __('URL resolution', 'yablonsky-multilingual') => !empty($caps['request_local_url_cache']) ? __('Request cached', 'yablonsky-multilingual') : __('Uncached', 'yablonsky-multilingual'),
    ];
    foreach ($rows as $label => $value) echo '<div><span>' . esc_html($label) . '</span><strong>' . esc_html($value) . '</strong></div>';
    echo '</div></section>';
  }
}
