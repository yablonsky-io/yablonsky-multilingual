<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Safe Mode
 *
 * When enabled, YPM will NOT touch front-end routing, redirects, URL generation,
 * hreflang output, or query filtering.
 */
final class SafeMode {
  public const OPT = 'ypm_safe_mode';

  public static function ensure_option(): void {
    if (get_option(self::OPT) === false) {
      add_option(self::OPT, 0, '', false);
    }
  }

  /**
   * A status read must never initialize an option on a visitor request.
   * Activation/upgrade/admin configuration own option persistence; missing
   * data means the safe, historical default of OFF.
   */
  public static function enabled(): bool {
    return (bool) get_option(self::OPT, 0);
  }

  public static function set_enabled(bool $enabled): void {
    update_option(self::OPT, $enabled ? 1 : 0, false);
  }

  /**
   * Detect conflicting multilingual plugins.
   * Returns a list of human-readable plugin names.
   */
  public static function detect_conflicts(): array {
    $conflicts = [];

    // WPML
    if (defined('ICL_SITEPRESS_VERSION') || class_exists('SitePress')) {
      $conflicts[] = 'WPML';
    }

    // Polylang
    if (defined('POLYLANG_VERSION') || function_exists('pll_current_language') || class_exists('PLL_Model')) {
      $conflicts[] = 'Polylang';
    }

    return array_values(array_unique($conflicts));
  }
}
