<?php
/**
 * Disposable WordPress admin-locale regression for YMulti's already accepted
 * per-user localization fallback. This test is never bundled in the plugin.
 */
if (!defined('ABSPATH') || !is_admin() || !function_exists('ymulti_bundled_ui_mo')) {
    throw new RuntimeException('Admin context or YMulti locale fallback unavailable');
}
$users = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
if (!$users) throw new RuntimeException('Disposable WordPress admin user missing');
$id = (int) $users[0];
$original = get_user_meta($id, 'locale', true);
$original_user = get_current_user_id();
wp_set_current_user($id);
$domain = 'yablonsky-multilingual';
$files = glob(YPM_PATH . 'languages/' . $domain . '-*.mo');
if (count((array) $files) !== 36) throw new RuntimeException('Missing 36 bundled locales');
sort($files);
$failures = array();
try {
    foreach ($files as $file) {
        $locale = substr(basename($file), strlen($domain) + 1, -3);
        update_user_meta($id, 'locale', $locale);
        clean_user_cache($id);
        if (get_user_locale($id) !== $locale) {
            $failures[] = "$locale: WordPress user locale did not change";
            continue;
        }
        $mo = ymulti_bundled_ui_mo();
        if (!$mo || $mo->translate('1 row selected') === '1 row selected') {
            $failures[] = "$locale: YMulti admin fallback did not load translated MO";
            continue;
        }
        unload_textdomain($domain);
        $translated = __('1 row selected', $domain);
        $expected = $mo->translate('1 row selected');
        if (!is_string($translated) || trim($translated) === '' || $translated !== $expected) {
            $failures[] = "$locale: actual gettext differs from the selected user-locale catalog";
            continue;
        }
        echo "PASS user-admin locale $locale: gettext and bundled MO" . PHP_EOL;
    }
} finally {
    if ($original === '') delete_user_meta($id, 'locale');
    else update_user_meta($id, 'locale', $original);
    clean_user_cache($id);
    wp_set_current_user($original_user);
    unload_textdomain($domain);
}
if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL $failure" . PHP_EOL);
    throw new RuntimeException('Admin user locale switch regression failed');
}
echo 'PASS: 36 WordPress user-admin locale changes and YMulti gettext fallback' . PHP_EOL;
