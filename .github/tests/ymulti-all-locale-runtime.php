<?php
/**
 * CI-only WordPress POMO round-trip for every shipped YMulti locale.
 * The test must not be added to the production plugin ZIP.
 */
if (!defined('ABSPATH') || !defined('YPM_PATH')) {
    throw new RuntimeException('WordPress/YMulti not bootstrapped');
}
if (!class_exists('MO', false)) {
    require_once ABSPATH . WPINC . '/pomo/mo.php';
}
$domain = 'yablonsky-multilingual';
$files = glob(YPM_PATH . 'languages/' . $domain . '-*.mo');
if (!is_array($files) || count($files) !== 36) {
    throw new RuntimeException('Expected 36 MO catalogs; found ' . count((array) $files));
}
sort($files, SORT_STRING);
$failures = array();
foreach ($files as $file) {
    $locale = substr(basename($file), strlen($domain) + 1, -3);
    if (!preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/', $locale)) {
        $failures[] = "Invalid locale filename: $locale";
        continue;
    }
    $mo = new MO();
    if (!$mo->import_from_file($file)) {
        $failures[] = "Native WordPress MO parser rejected $locale";
        continue;
    }
    $sample = $mo->translate('1 row selected');
    if (!is_string($sample) || trim($sample) === '' || $sample === '1 row selected') {
        $failures[] = "POMO translation missing for $locale";
        continue;
    }
    unload_textdomain($domain);
    if (!load_textdomain($domain, $file, $locale)) {
        $failures[] = "WordPress textdomain could not load $locale";
        continue;
    }
    $translations = get_translations_for_domain($domain);
    if (!is_object($translations) ||
        !method_exists($translations, 'translate') ||
        $translations->translate('1 row selected') !== $sample) {
        $failures[] = "WordPress domain lookup differs for $locale";
        continue;
    }
    echo "PASS $locale native MO + WordPress textdomain" . PHP_EOL;
}
unload_textdomain($domain);
if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL $failure" . PHP_EOL);
    throw new RuntimeException('YMulti 36-locale WordPress runtime regression failed');
}
echo 'PASS: all 36 locale catalogs load and resolve under native WordPress POMO' . PHP_EOL;
