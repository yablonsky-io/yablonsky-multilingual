<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

final class Status {
  /**
   * Return a structured status report for rendering.
   */
  public static function report(): array {
    global $wpdb;

    $wp_version = get_bloginfo('version');
    $php_version = PHP_VERSION;

    $woo_version = null;
    if (class_exists('WooCommerce')) {
      if (defined('WC_VERSION')) {
        $woo_version = WC_VERSION;
      } elseif (function_exists('WC')) {
        $wc = WC();
        if (is_object($wc) && method_exists($wc, 'version')) {
          $woo_version = (string) $wc->version;
        }
      }
    }

    $edd_version = null;
    if (defined('EDD_VERSION')) {
      $edd_version = EDD_VERSION;
    } elseif (class_exists('Easy_Digital_Downloads')) {
      // best-effort fallback
      $edd_version = null;
    }

    $memory_limit = ini_get('memory_limit');
    $upload_max   = ini_get('upload_max_filesize');
    $post_max     = ini_get('post_max_size');
    $time_limit   = ini_get('max_execution_time');

    $permalink_structure = (string) get_option('permalink_structure');
    $is_multisite = is_multisite();
    $site_lang = (string) get_locale();

    $db_server = method_exists($wpdb, 'db_server_info') ? (string) $wpdb->db_server_info() : '';
    $db_name   = (string) $wpdb->dbname;
    $db_prefix = (string) $wpdb->prefix;

    $active_langs = Languages::active_codes();
    $default_lang = Languages::default_code();

    $rewrite_sig = '';
    $rewrite_needs_flush = '';
    if (class_exists('Yablonsky\\Multilingual\\Router')) {
      Router::ensure_signature_option();
      $rewrite_sig = (string) get_option(Router::OPT_REWRITE_SIG, '');
      $current_sig = Router::compute_rewrite_signature();
      $rewrite_needs_flush = ($rewrite_sig !== '' && $rewrite_sig !== $current_sig) ? 'yes' : 'no';
    }

    $checks = [];

    // PHP
    $checks[] = self::check(__('PHP version', 'yablonsky-multilingual'), $php_version, '>= ' . YPM_MIN_PHP, version_compare($php_version, YPM_MIN_PHP, '>='));
    // WordPress
    $checks[] = self::check(__('WordPress version', 'yablonsky-multilingual'), $wp_version, '>= ' . YPM_MIN_WP, version_compare($wp_version, YPM_MIN_WP, '>='));
    // Permalinks
    $checks[] = self::check(__('Pretty permalinks enabled', 'yablonsky-multilingual'), $permalink_structure !== '' ? __('Enabled', 'yablonsky-multilingual') : __('Setup required', 'yablonsky-multilingual'), __('Recommended', 'yablonsky-multilingual'), $permalink_structure !== '');
    // Memory
    $checks[] = self::check(__('WP/PHP memory limit', 'yablonsky-multilingual'), $memory_limit ?: __('Unknown', 'yablonsky-multilingual'), __('128M+ recommended', 'yablonsky-multilingual'), self::memory_ok($memory_limit, 128));
    // Active languages
    $checks[] = self::check(__('Active languages configured', 'yablonsky-multilingual'), (string) count($active_langs), '>= 1', count($active_langs) >= 1);

    // Professional workflow readiness (admin-only; no frontend work).
    if (class_exists('Yablonsky\Multilingual\TranslationJobs')) {
      $jobs_table = TranslationJobs::table();
      $events_table = TranslationJobs::events_table();
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- System-status existence check only.
      $jobs_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $jobs_table)) === $jobs_table;
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- System-status existence check only.
      $events_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $events_table)) === $events_table;
      $admin_role = get_role('administrator');
      $caps_ready = $admin_role && $admin_role->has_cap(TranslationJobs::CAP_MANAGE) && $admin_role->has_cap(TranslationJobs::CAP_REVIEW) && $admin_role->has_cap(TranslationJobs::CAP_TRANSLATE);
      $checks[] = self::check(__('Professional workflow tables', 'yablonsky-multilingual'), ($jobs_exists && $events_exists) ? __('Ready', 'yablonsky-multilingual') : __('Setup required', 'yablonsky-multilingual'), __('Ready', 'yablonsky-multilingual'), $jobs_exists && $events_exists);
      $checks[] = self::check(__('Professional workflow authority', 'yablonsky-multilingual'), $caps_ready ? __('Ready', 'yablonsky-multilingual') : __('Review', 'yablonsky-multilingual'), __('Ready', 'yablonsky-multilingual'), (bool) $caps_ready);
      $index_ready = TranslationJobs::target_lookup_index_exists();
      $checks[] = self::check(__('Translation job lookup index', 'yablonsky-multilingual'), $index_ready ? __('Ready', 'yablonsky-multilingual') : __('Review', 'yablonsky-multilingual'), __('Ready', 'yablonsky-multilingual'), $index_ready);
    }
    if (class_exists('Yablonsky\Multilingual\XLIFFExchange')) {
      $checks[] = self::check(__('XLIFF interchange', 'yablonsky-multilingual'), XLIFFExchange::available() ? __('Ready', 'yablonsky-multilingual') : __('Setup required · PHP DOM', 'yablonsky-multilingual'), __('Ready', 'yablonsky-multilingual'), XLIFFExchange::available());
    }

    // Rewrite signature drift (only relevant in full mode)
    if ((!class_exists('Yablonsky\\Multilingual\\RuntimeAuthority') || RuntimeAuthority::ymulti_owns_public_runtime()) && $rewrite_needs_flush !== '') {
      $checks[] = self::check(__('Rewrite rules up to date', 'yablonsky-multilingual'), $rewrite_needs_flush === 'yes' ? __('Refresh required', 'yablonsky-multilingual') : __('Current', 'yablonsky-multilingual'), __('Yes', 'yablonsky-multilingual'), $rewrite_needs_flush !== 'yes');
    }

    return [
      'checks' => $checks,
      'wordpress' => [
        __('WordPress version', 'yablonsky-multilingual') => $wp_version,
        __('Multisite', 'yablonsky-multilingual') => $is_multisite ? __('Multisite', 'yablonsky-multilingual') : __('Single site', 'yablonsky-multilingual'),
        __('Site locale', 'yablonsky-multilingual') => $site_lang,
        __('Memory limit', 'yablonsky-multilingual') => $memory_limit ?: __('Unknown', 'yablonsky-multilingual'),
        __('Permalink structure', 'yablonsky-multilingual') => $permalink_structure !== '' ? $permalink_structure : __('(default)', 'yablonsky-multilingual'),
      ],
      'plugins' => [
        __('WooCommerce version', 'yablonsky-multilingual') => $woo_version ?: __('Detection pending', 'yablonsky-multilingual'),
        __('Easy Digital Downloads version', 'yablonsky-multilingual') => $edd_version ?: __('Detection pending', 'yablonsky-multilingual'),
      ],
      'server' => [
        __('PHP version', 'yablonsky-multilingual') => $php_version,
        __('Max upload size', 'yablonsky-multilingual') => $upload_max ?: __('Unknown', 'yablonsky-multilingual'),
        __('PHP post max size', 'yablonsky-multilingual') => $post_max ?: __('Unknown', 'yablonsky-multilingual'),
        __('PHP time limit', 'yablonsky-multilingual') => $time_limit ?: __('Unknown', 'yablonsky-multilingual'),
        __('Database server', 'yablonsky-multilingual') => $db_server ?: __('Unknown', 'yablonsky-multilingual'),
        __('Database name', 'yablonsky-multilingual') => $db_name ?: __('Unknown', 'yablonsky-multilingual'),
        __('DB prefix', 'yablonsky-multilingual') => $db_prefix ?: __('Unknown', 'yablonsky-multilingual'),
      ],
      'ypm' => [
        __('Plugin version', 'yablonsky-multilingual') => YPM_VERSION,
        __('DB schema version', 'yablonsky-multilingual') => (string) Updater::current_version() . ' / ' . (defined('YPM_DB_VERSION') ? (string) YPM_DB_VERSION : '1'),
        __('Translation job lookup index', 'yablonsky-multilingual') => (class_exists('Yablonsky\\Multilingual\\TranslationJobs') && TranslationJobs::target_lookup_index_exists()) ? __('Ready', 'yablonsky-multilingual') : (class_exists('Yablonsky\\Multilingual\\TranslationJobs') ? (string) get_option(TranslationJobs::TARGET_INDEX_STATUS_OPTION, __('Review', 'yablonsky-multilingual')) : __('Review', 'yablonsky-multilingual')),
        __('Safe Mode', 'yablonsky-multilingual') => (class_exists('Yablonsky\Multilingual\SafeMode') && SafeMode::enabled() ? __('ON', 'yablonsky-multilingual') : __('OFF', 'yablonsky-multilingual')),
        __('Runtime mode', 'yablonsky-multilingual') => (class_exists('Yablonsky\Multilingual\RuntimeAuthority') ? RuntimeAuthority::mode() : 'standalone'),
        __('Public routing owner', 'yablonsky-multilingual') => (class_exists('Yablonsky\Multilingual\RuntimeAuthority') ? RuntimeAuthority::public_owner() : 'ymulti'),
        __('Incumbent multilingual provider', 'yablonsky-multilingual') => (class_exists('Yablonsky\Multilingual\RuntimeAuthority') ? implode(', ', RuntimeAuthority::incumbent_providers()) : ''),
        __('Rewrite signature up to date', 'yablonsky-multilingual') => ($rewrite_needs_flush === '' ? __('Unknown', 'yablonsky-multilingual') : ($rewrite_needs_flush === 'yes' ? __('Refresh required', 'yablonsky-multilingual') : __('Current', 'yablonsky-multilingual'))),
        __('Default locale', 'yablonsky-multilingual') => $default_lang,
        __('Active locales', 'yablonsky-multilingual') => implode(', ', $active_langs),
        __('Canonical language families', 'yablonsky-multilingual') => (string) count(Languages::canonical_family_codes()),
        __('Language Provider version', 'yablonsky-multilingual') => class_exists('Yablonsky\\Multilingual\\LanguageProvider') ? LanguageProvider::version() : __('Review required', 'yablonsky-multilingual'),
        __('SEO metadata owner', 'yablonsky-multilingual') => class_exists('Yablonsky\\Multilingual\\SEOOwnership') ? SEOOwnership::metadata_owner() : 'ypm',
        __('Hreflang output owner', 'yablonsky-multilingual') => class_exists('Yablonsky\\Multilingual\\SEOOwnership') ? SEOOwnership::hreflang_owner() : 'ypm',
      ],
    ];
  }

  private static function check(string $label, string $value, string $expected, bool $ok): array {
    return [
      'label' => $label,
      'value' => $value,
      'expected' => $expected,
      'status' => $ok ? __('OK', 'yablonsky-multilingual') : __('Warning', 'yablonsky-multilingual'),
      'ok' => $ok,
    ];
  }

  private static function memory_ok(?string $ini_value, int $min_mb): bool {
    if (!$ini_value) return true; // unknown; don't alarm
    $bytes = self::ini_to_bytes($ini_value);
    if ($bytes <= 0) return true;
    return $bytes >= ($min_mb * 1024 * 1024);
  }

  private static function ini_to_bytes(string $val): int {
    $val = trim($val);
    if ($val === '') return 0;
    if ($val === '-1') return PHP_INT_MAX;

    $last = strtolower(substr($val, -1));
    $num = (int) $val;

    switch ($last) {
      case 'g':
        return $num * 1024 * 1024 * 1024;
      case 'm':
        return $num * 1024 * 1024;
      case 'k':
        return $num * 1024;
      default:
        return (int) $val;
    }
  }
}
