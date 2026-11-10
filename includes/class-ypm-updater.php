<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Update Manager
 *
 * Keeps YPM upgrades safe and predictable. Stores a schema version and runs
 * idempotent migrations in wp-admin only.
 */
final class Updater {
  public const OPT_DB_VERSION = 'ypm_db_version';

  public static function init(): void {
    add_action('admin_init', [__CLASS__, 'maybe_upgrade'], 1);
  }

  public static function ensure_option(): void {
    if (get_option(self::OPT_DB_VERSION) === false) {
      add_option(self::OPT_DB_VERSION, 0, '', false);
    }
  }

  public static function current_version(): int {
    self::ensure_option();
    return (int) get_option(self::OPT_DB_VERSION, 0);
  }

  public static function maybe_upgrade(): void {
    if (!is_admin() || !current_user_can('manage_options')) return;

    self::ensure_option();

    $installed = self::current_version();
    $target = defined('YPM_DB_VERSION') ? (int) YPM_DB_VERSION : 1;

    if ($installed >= $target) return;

    // Run migrations sequentially.
    for ($v = $installed + 1; $v <= $target; $v++) {
      // Never mark a schema migration complete until its checks succeed.
      if (!self::migrate_to($v)) return;
      update_option(self::OPT_DB_VERSION, $v, false);
    }
  }

  /**
   * Migrations must be idempotent and must NOT delete user content.
   */
  private static function migrate_to(int $version): bool {
    // v1: Ensure all core options exist and keep taxonomy terms in sync.
    if ($version === 1) {
      if (class_exists('Yablonsky\\Multilingual\\Languages')) {
        Languages::ensure_options();
        // Ensure custom languages option exists.
        if (get_option('ypm_custom_languages') === false) {
          add_option('ypm_custom_languages', [], '', false);
        }
      }
      if (class_exists('Yablonsky\\Multilingual\\SafeMode')) {
        SafeMode::ensure_option();
      }
      // Do NOT flush rewrites here; it can be costly on large sites.
      // We'll flush only when settings change or activation.
    }

    // v2: Indexed explicit string-translation store.
    if ($version === 2) {
      if (class_exists('Yablonsky\\Multilingual\\StringTranslations')) {
        StringTranslations::install_table();
      }
    }

    // v4: Durable professional translation jobs + workflow roles.
    if ($version === 4) {
      if (class_exists('Yablonsky\Multilingual\TranslationJobs')) {
        TranslationJobs::install_table();
        TranslationJobs::install_roles();
      }
    }

    // v5: Append-only workflow audit table. install_table() is idempotent and
    // also re-checks the jobs table so upgrades remain safe after partial runs.
    if ($version === 5) {
      if (class_exists('Yablonsky\Multilingual\TranslationJobs')) {
        TranslationJobs::install_table();
        TranslationJobs::install_roles();
      }
    }

    // v6: Local exact-match translation memory for repeated authoring content.
    if ($version === 6) {
      if (class_exists('Yablonsky\Multilingual\TranslationMemory')) {
        TranslationMemory::install_table();
      }
    }

    // v7: Durable continuous-translation Autopilot queue.
    if ($version === 7) {
      if (class_exists('Yablonsky\Multilingual\Autopilot')) {
        Autopilot::install_table();
      }
    }


    // v8: Additive, online job-target lookup index. No frontend DDL.
    if ($version === 8) {
      return class_exists('Yablonsky\Multilingual\TranslationJobs')
        && TranslationJobs::ensure_target_lookup_index();
    }
    return true;
  }
}
