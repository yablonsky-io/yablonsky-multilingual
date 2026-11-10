<?php
namespace Yablonsky\Multilingual\Compat;

if (!defined('ABSPATH')) exit;

/**
 * Compatibility shim for the block Widgets screen batch preflight.
 *
 * Gutenberg's Widgets save path first issues an explicit OPTIONS request to
 * /batch/v1 only to discover the server's max batch size. Some otherwise
 * healthy WordPress environments return an unusable OPTIONS payload, which
 * prevents the subsequent authenticated POST from ever being sent.
 *
 * This shim is intentionally narrow: only widgets.php, only the exact
 * /batch/v1 OPTIONS apiFetch call, and it mirrors WordPress' own
 * rest_get_max_batch_size value. The actual POST still goes through the normal
 * REST API authentication, permissions and validation path.
 */
final class WidgetsBatch {
  public static function init(): void {
    if (!is_admin()) return;
    add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue'], 5);
  }

  public static function enqueue(string $hook): void {
    if ($hook !== 'widgets.php') return;

    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core REST filter.
    $max_items = (int) apply_filters('rest_get_max_batch_size', 25);
    if ($max_items < 1) $max_items = 25;

    wp_enqueue_script(
      'ypm-widgets-batch-compat',
      YPM_ASSET_URL . 'widgets-batch-compat.js',
      ['wp-api-fetch'],
      YPM_VERSION,
      false
    );

    wp_localize_script('ypm-widgets-batch-compat', 'YPMWidgetsBatchCompat', [
      'maxItems' => $max_items,
    ]);
  }
}
