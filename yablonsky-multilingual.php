<?php
/**
 * Plugin Name: Yablonsky Multilingual
 * Description: Set up your languages, translate pages and products, and manage your multilingual website in one place.
 * Version: 2.53.9
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Yablonsky.io
 * Author URI: https://yablonsky.io/
 * License: GPLv2 or later
 * Text Domain: yablonsky-multilingual
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;


/**
 * Superseded development package basename.
 *
 * The old development package used the same persisted YPM data model. The
 * canonical package therefore preserves all ypm_* options, taxonomies and
 * relationships, but it must not run concurrently with the legacy runtime.
 */
if (!defined('YABLONSKY_MULTILINGUAL_LEGACY_PLUGIN_BASENAME')) {
  define('YABLONSKY_MULTILINGUAL_LEGACY_PLUGIN_BASENAME', 'yablonsky-performance-multilingual/yablonsky-performance-multilingual.php');
}

/**
 * Return whether the superseded package is still marked active.
 */
function yablonsky_multilingual_legacy_package_active(): bool {
  $active = (array) get_option('active_plugins', []);
  if (in_array(YABLONSKY_MULTILINGUAL_LEGACY_PLUGIN_BASENAME, $active, true)) return true;

  if (is_multisite()) {
    $network = (array) get_site_option('active_sitewide_plugins', []);
    if (isset($network[YABLONSKY_MULTILINGUAL_LEGACY_PLUGIN_BASENAME])) return true;
  }

  return false;
}

/**
 * Explain the one-time package handoff without deleting legacy files.
 */
function yablonsky_multilingual_legacy_handoff_notice(): void {
  if (!current_user_can('activate_plugins')) return;

  $url = wp_nonce_url(
    admin_url('plugins.php?action=deactivate&plugin=' . rawurlencode(YABLONSKY_MULTILINGUAL_LEGACY_PLUGIN_BASENAME)),
    'deactivate-plugin_' . YABLONSKY_MULTILINGUAL_LEGACY_PLUGIN_BASENAME
  );

  echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Yablonsky Multilingual is installed and ready to take over the existing multilingual data.', 'yablonsky-multilingual') . '</strong> ';
  echo esc_html__('Deactivate the superseded development package to complete the handoff. Existing multilingual settings, language relationships and translation data are preserved.', 'yablonsky-multilingual');
  echo '</p><p><a class="button button-primary" href="' . esc_url($url) . '">' . esc_html__('Deactivate legacy package and continue', 'yablonsky-multilingual') . '</a></p></div>';
}

// A different plugin directory cannot be replaced atomically by WordPress.
// During the one-time handoff, keep the canonical runtime dormant until the
// superseded package is explicitly deactivated. This prevents duplicate hooks,
// routes and multilingual state writers without deleting any plugin files.
if (yablonsky_multilingual_legacy_package_active()) {
  add_action('admin_notices', 'yablonsky_multilingual_legacy_handoff_notice');
  return;
}

define('YPM_PLUGIN_FILE', __FILE__);
define('YPM_VERSION', '2.53.9');
define('YPM_DB_VERSION', 8);
define('YPM_MIN_PHP', '7.4');
define('YPM_MIN_WP', '6.0');
define('YPM_PATH', plugin_dir_path(__FILE__));
define('YPM_URL', plugin_dir_url(__FILE__));
// Immutable per-release asset path: survives query-string stripping by cache/performance layers.
define('YPM_ASSET_URL', YPM_URL . 'assets/release-' . YPM_VERSION . '/');

// Canonical YMulti aliases. Legacy YPM constants remain for data/API compatibility.
define('YMULTI_VERSION', YPM_VERSION);
define('YMULTI_DB_VERSION', YPM_DB_VERSION);
define('YMULTI_PATH', YPM_PATH);
define('YMULTI_URL', YPM_URL);
define('YMULTI_ASSET_URL', YPM_ASSET_URL);

/**
 * A domain-scoped fallback for environments in which WPML/WordPress returns
 * the untranslated source although this plugin bundles a valid MO catalog.
 *
 * Never overrides a real translation, other domains, or the site's language
 * policy. The MO catalog is read once per request/locale only upon fallback.
 * We intentionally use the native WordPress MO parser for contexts/plurals.
 */
function ymulti_bundled_ui_mo() {
  static $catalogs = [];

  // The WP admin chrome belongs to the logged-in user's locale. WPML and
  // other integrations may temporarily switch the current content locale;
  // that must not silently turn YMulti's administrative UI back to English.
  if (function_exists('is_admin') && is_admin() && function_exists('get_user_locale')) {
    $locale = get_user_locale();
  } elseif (function_exists('is_locale_switched') && is_locale_switched()) {
    $locale = get_locale();
  } else {
    $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
  }

  if (!is_string($locale) || !preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]+)*$/D', $locale)) {
    return null;
  }
  if (array_key_exists($locale, $catalogs)) {
    return $catalogs[$locale];
  }
  $catalogs[$locale] = null;
  if ('en_US' === $locale) {
    return null;
  }
  $file = YPM_PATH . 'languages/yablonsky-multilingual-' . $locale . '.mo';
  if (!is_readable($file)) {
    return null;
  }
  if (!class_exists('MO', false) && defined('ABSPATH') && defined('WPINC')) {
    require_once ABSPATH . WPINC . '/pomo/mo.php';
  }
  if (!class_exists('MO', false)) {
    return null;
  }
  $mo = new \MO();
  if (!$mo->import_from_file($file)) {
    return null;
  }
  $catalogs[$locale] = $mo;
  return $mo;
}

/** Preserve any localized response supplied by the native translation stack. */
function ymulti_bundled_ui_gettext($translated, $text, $domain) {
  if ('yablonsky-multilingual' !== $domain || $translated !== $text) return $translated;
  $mo = ymulti_bundled_ui_mo();
  return $mo ? $mo->translate($text) : $translated;
}
add_filter('gettext_yablonsky-multilingual', 'ymulti_bundled_ui_gettext', 999, 3);

/** Preserve native context-aware translations as the first authority. */
function ymulti_bundled_ui_gettext_context($translated, $text, $context, $domain) {
  if ('yablonsky-multilingual' !== $domain || $translated !== $text) return $translated;
  $mo = ymulti_bundled_ui_mo();
  return $mo ? $mo->translate($text, $context) : $translated;
}
add_filter('gettext_with_context_yablonsky-multilingual', 'ymulti_bundled_ui_gettext_context', 999, 4);

/** Use the catalog's language-specific plural rules when native WP has no translation. */
function ymulti_bundled_ui_ngettext($translated, $single, $plural, $number, $domain) {
  if ('yablonsky-multilingual' !== $domain || $translated !== (1 === (int) $number ? $single : $plural)) return $translated;
  $mo = ymulti_bundled_ui_mo();
  return $mo ? $mo->translate_plural($single, $plural, (int) $number) : $translated;
}
add_filter('ngettext_yablonsky-multilingual', 'ymulti_bundled_ui_ngettext', 999, 5);

/** Same fallback for strings having both context and plural forms. */
function ymulti_bundled_ui_ngettext_context($translated, $single, $plural, $number, $context, $domain) {
  if ('yablonsky-multilingual' !== $domain || $translated !== (1 === (int) $number ? $single : $plural)) return $translated;
  $mo = ymulti_bundled_ui_mo();
  return $mo ? $mo->translate_plural($single, $plural, (int) $number, $context) : $translated;
}
add_filter('ngettext_with_context_yablonsky-multilingual', 'ymulti_bundled_ui_ngettext_context', 999, 6);

/**
 * Show the minimum-PHP compatibility notice.
 */
function yablonsky_multilingual_admin_notice_min_php(): void {
  $message = sprintf(
    /* translators: 1: required PHP version, 2: current PHP version. */
    __('Yablonsky Multilingual requires PHP %1$s+. Your server is running PHP %2$s.', 'yablonsky-multilingual'),
    YPM_MIN_PHP,
    PHP_VERSION
  );

  echo '<div class="notice notice-error"><p><strong>' . esc_html($message) . '</strong></p></div>';
}

/**
 * Show the minimum-WordPress compatibility notice.
 */
function yablonsky_multilingual_admin_notice_min_wp(): void {
  global $wp_version;

  $message = sprintf(
    /* translators: 1: required WordPress version, 2: current WordPress version. */
    __('Yablonsky Multilingual requires WordPress %1$s+. This site is running WordPress %2$s.', 'yablonsky-multilingual'),
    YPM_MIN_WP,
    (string) $wp_version
  );

  echo '<div class="notice notice-error"><p><strong>' . esc_html($message) . '</strong></p></div>';
}

/**
 * Add the plugin settings shortcut.
 *
 * @param array $links Existing plugin action links.
 * @return array
 */
function yablonsky_multilingual_plugin_action_links(array $links): array {
  $settings_url = admin_url('admin.php?page=ypm-multilingual');
  array_unshift(
    $links,
    '<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings', 'yablonsky-multilingual') . '</a>'
  );
  return $links;
}

/**
 * Bootstrap YMulti after all active plugins are loaded.
 */
function yablonsky_multilingual_boot_plugin(): void {
  Yablonsky\Multilingual\Plugin::instance();
}

// Run compatibility guards before loading classes that use the minimum syntax/API.
if (version_compare(PHP_VERSION, YPM_MIN_PHP, '<')) {
  add_action('admin_notices', 'yablonsky_multilingual_admin_notice_min_php');
  return;
}

global $wp_version;
if (isset($wp_version) && version_compare((string) $wp_version, YPM_MIN_WP, '<')) {
  add_action('admin_notices', 'yablonsky_multilingual_admin_notice_min_wp');
  return;
}

// Register request-locale selection before plugins_loaded so native WordPress,
// WooCommerce and theme translations can resolve against the public content
// locale as early as possible. The class guards against duplicate init calls.
require_once YPM_PATH . 'includes/class-ypm-languages.php';
require_once YPM_PATH . 'includes/class-ypm-safemode.php';
require_once YPM_PATH . 'includes/class-ymulti-runtime-authority.php';
require_once YPM_PATH . 'includes/class-ypm-locale-runtime.php';
// Keep request-local YMulti identity isolated across Multisite site switches.
add_action('switch_blog', ['Yablonsky\Multilingual\RuntimeAuthority', 'on_switch_blog'], 10, 3);
if (Yablonsky\Multilingual\RuntimeAuthority::ymulti_owns_public_runtime()) {
  Yablonsky\Multilingual\LocaleRuntime::init();
}

require_once YPM_PATH . 'includes/class-ypm-plugin.php';

register_activation_hook(__FILE__, ['Yablonsky\Multilingual\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['Yablonsky\Multilingual\Plugin', 'deactivate']);

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'yablonsky_multilingual_plugin_action_links');

add_action('plugins_loaded', 'yablonsky_multilingual_boot_plugin');



/**
 * Tell administrators when the inactive superseded package can be removed.
 */
function yablonsky_multilingual_legacy_package_cleanup_notice(): void {
  if (!current_user_can('delete_plugins')) return;
  $legacy_file = WP_PLUGIN_DIR . '/' . YABLONSKY_MULTILINGUAL_LEGACY_PLUGIN_BASENAME;
  if (!file_exists($legacy_file)) return;

  if (!function_exists('is_plugin_active')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
  }
  if (is_plugin_active(YABLONSKY_MULTILINGUAL_LEGACY_PLUGIN_BASENAME)) return;

  echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__('The superseded development package is inactive. It can be removed from Installed Plugins; Yablonsky Multilingual keeps the existing multilingual settings and translation data.', 'yablonsky-multilingual') . '</p></div>';
}
add_action('admin_notices', 'yablonsky_multilingual_legacy_package_cleanup_notice');

