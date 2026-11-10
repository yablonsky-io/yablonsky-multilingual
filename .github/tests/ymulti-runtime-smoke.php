<?php
/**
 * YMulti read-only WordPress CI smoke gate.
 * Runs only in disposable wp-env after official Plugin Check has activated the candidate.
 * Deliberately excluded from the installable package.
 */
if (!defined('ABSPATH')) {
    throw new RuntimeException('WordPress was not bootstrapped');
}
$failures = array();
if (!function_exists('is_plugin_active')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if (!is_plugin_active('yablonsky-multilingual/yablonsky-multilingual.php')) {
    $failures[] = 'Candidate plugin not active';
}
if (!defined('YPM_PATH') || !defined('YPM_ASSET_URL')) {
    $failures[] = 'Plugin asset constants unavailable';
} else {
    if (strpos(YPM_ASSET_URL, '/assets/release-') !== false ||
        substr(YPM_ASSET_URL, -8) !== '/assets/') {
        $failures[] = 'Asset root still tied to a release version';
    }
    foreach (array('admin-shell.js', 'admin-shell.css', 'language-ui.js', 'language-switcher.css') as $asset) {
        if (!is_file(YPM_PATH . 'assets/' . $asset)) {
            $failures[] = 'Missing runtime asset: ' . $asset;
        }
    }
}
$routes = rest_get_server()->get_routes();
foreach (array('/ymulti/v1/languages', '/ymulti/v1/coverage') as $route) {
    if (!array_key_exists($route, $routes)) {
        $failures[] = 'Missing REST route: ' . $route;
    }
}
$translation_route = false;
foreach (array_keys($routes) as $route) {
    if (strpos($route, '/ymulti/v1/translations/') === 0) {
        $translation_route = true;
        break;
    }
}
if (!$translation_route) {
    $failures[] = 'Missing translations REST route';
}
$domain = 'yablonsky-multilingual';
foreach (array('zh_CN', 'zh_TW', 'de_DE', 'ru_RU', 'ar', 'he_IL') as $locale) {
    if (!defined('YPM_PATH')) {
        break;
    }
    $mo = YPM_PATH . 'languages/' . $domain . '-' . $locale . '.mo';
    if (!is_readable($mo)) {
        $failures[] = 'Missing MO: ' . $locale;
        continue;
    }
    unload_textdomain($domain);
    if (!load_textdomain($domain, $mo)) {
        $failures[] = 'WordPress cannot load MO: ' . $locale;
        continue;
    }
    $translated = __('1 row selected', $domain);
    if (!is_string($translated) || trim($translated) === '' || $translated === '1 row selected') {
        $failures[] = 'Translation did not resolve: ' . $locale;
    } else {
        echo 'PASS translation ' . $locale . ': ' . $translated . PHP_EOL;
    }
}
unload_textdomain($domain);
if ($failures) {
    foreach ($failures as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . PHP_EOL);
    }
    throw new RuntimeException('YMulti WordPress runtime smoke failed');
}
echo 'PASS: plugin activation, REST registration, canonical assets and six real MO translations' . PHP_EOL;
