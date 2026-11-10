<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Professional PO interchange for explicit string overrides. */
final class StringInterchange {
  public static function init(): void {
    if (!is_admin()) return;
    add_action('admin_post_ymulti_strings_po_export', [__CLASS__, 'export_po']);
    add_action('admin_post_ymulti_strings_po_import', [__CLASS__, 'import_po']);
  }

  /** @param string[] $domains */
  public static function render_panel(string $locale, array $domains): void {
    if (!current_user_can('manage_options')) return;
    $domains = array_values(array_unique(array_filter(array_map('sanitize_key', $domains))));
    sort($domains, SORT_NATURAL | SORT_FLAG_CASE);
    echo '<details class="ypm-disclosure ypm-disclosure--wide"><summary><span><strong>' . esc_html__('PO import & export', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Exchange one text domain at a time with translators or gettext-compatible tools. Imports update local YMulti overrides only.', 'yablonsky-multilingual') . '</small></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary><div class="ypm-disclosure__body">';
    if (!$domains) {
      echo '<div class="ypm-empty-panel ypm-empty-panel--compact"><strong>' . esc_html__('Tracked text domains appear after discovery or import', 'yablonsky-multilingual') . '</strong><p>' . esc_html__('Discover or add a string override first; its domain will then be available for interchange.', 'yablonsky-multilingual') . '</p></div></div></details>';
      return;
    }
    echo '<div class="ypm-dashboard-grid">';
    echo '<section class="ypm-card"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Export', 'yablonsky-multilingual') . '</span><h3>' . esc_html__('Download PO', 'yablonsky-multilingual') . '</h3></div></div><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('ymulti_strings_po_export', 'ymulti_strings_po_nonce');
    echo '<input type="hidden" name="action" value="ymulti_strings_po_export"><input type="hidden" name="locale" value="' . esc_attr($locale) . '"><label><span>' . esc_html__('Text domain', 'yablonsky-multilingual') . '</span><select name="text_domain">';
    foreach ($domains as $domain) echo '<option value="' . esc_attr($domain) . '">' . esc_html($domain) . '</option>';
    echo '</select></label><p class="submit"><button class="button button-primary">' . esc_html__('Export PO', 'yablonsky-multilingual') . '</button></p></form></section>';
    echo '<section class="ypm-card"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Import', 'yablonsky-multilingual') . '</span><h3>' . esc_html__('Import reviewed PO', 'yablonsky-multilingual') . '</h3></div></div><form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('ymulti_strings_po_import', 'ymulti_strings_po_nonce');
    echo '<input type="hidden" name="action" value="ymulti_strings_po_import"><input type="hidden" name="locale" value="' . esc_attr($locale) . '"><label><span>' . esc_html__('Text domain', 'yablonsky-multilingual') . '</span><select name="text_domain">';
    foreach ($domains as $domain) echo '<option value="' . esc_attr($domain) . '">' . esc_html($domain) . '</option>';
    echo '</select></label><label><span>' . esc_html__('PO file', 'yablonsky-multilingual') . '</span><input type="file" name="po_file" accept=".po,text/x-gettext-translation" required></label><p class="ypm-card__copy">' . esc_html__('Maximum 5 MB. Source strings stay authoritative; only matching PO entries are written as local overrides.', 'yablonsky-multilingual') . '</p><p class="submit"><button class="button button-primary">' . esc_html__('Import PO', 'yablonsky-multilingual') . '</button></p></form></section>';
    echo '</div></div></details>';
  }

  public static function export_po(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ymulti_strings_po_export', 'ymulti_strings_po_nonce');
    $locale_raw = filter_input(INPUT_POST, 'locale', FILTER_UNSAFE_RAW);
    $domain_raw = filter_input(INPUT_POST, 'text_domain', FILTER_UNSAFE_RAW);
    $locale = is_string($locale_raw) ? sanitize_text_field($locale_raw) : '';
    $domain = is_string($domain_raw) ? sanitize_key($domain_raw) : '';
    if (!Languages::is_active($locale) || $domain === '') wp_die(esc_html__('Invalid string interchange scope.', 'yablonsky-multilingual'));
    require_once ABSPATH . WPINC . '/pomo/po.php';
    global $wpdb;
    $table = StringTranslations::table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit bounded admin export of YMulti-owned rows.
    $rows = $wpdb->get_results($wpdb->prepare('SELECT context_name, source_text, translated_text FROM ' . esc_sql($table) . ' WHERE locale=%s AND text_domain=%s ORDER BY id ASC', $locale, $domain));
    $po = new \PO();
    $po->set_header('Project-Id-Version', 'YMulti Strings');
    $po->set_header('Language', $locale);
    $po->set_header('X-YMulti-Text-Domain', $domain);
    if (is_array($rows)) foreach ($rows as $row) {
      $entry = new \Translation_Entry([
        'singular' => (string) $row->source_text,
        'translations' => [(string) $row->translated_text],
        'context' => (string) $row->context_name,
      ]);
      $po->add_entry($entry);
    }
    $payload = $po->export();
    nocache_headers();
    header('Content-Type: text/x-gettext-translation; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ymulti-' . rawurlencode($domain) . '-' . rawurlencode($locale) . '.po"');
    header('X-Content-Type-Options: nosniff');
    echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated PO download payload, not HTML.
    exit;
  }

  /** Prevent accidental cross-locale / cross-domain PO imports. */
  private static function po_scope_matches(\PO $po, string $locale, string $domain): bool {
    $declared_locale = trim((string) $po->get_header('Language'));
    $declared_domain = trim((string) $po->get_header('X-YMulti-Text-Domain'));
    $metadata = Languages::locale_metadata($locale);
    $wp_locale = is_array($metadata) ? (string) ($metadata['wp_locale'] ?? '') : '';
    // Some third-party PO tools do not emit either header. When a header is
    // provided, it must agree with the explicitly selected import scope.
    return ($declared_locale === '' || $declared_locale === $locale || ($wp_locale !== '' && $declared_locale === $wp_locale))
      && ($declared_domain === '' || $declared_domain === $domain);
  }

  public static function import_po(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ymulti_strings_po_import', 'ymulti_strings_po_nonce');
    $locale_raw = filter_input(INPUT_POST, 'locale', FILTER_UNSAFE_RAW);
    $domain_raw = filter_input(INPUT_POST, 'text_domain', FILTER_UNSAFE_RAW);
    $locale = is_string($locale_raw) ? sanitize_text_field($locale_raw) : '';
    $domain = is_string($domain_raw) ? sanitize_key($domain_raw) : '';
    if (!Languages::is_active($locale) || $domain === '') wp_die(esc_html__('Invalid string interchange scope.', 'yablonsky-multilingual'));
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File upload metadata is validated by size, extension and is_uploaded_file() before parsing.
    if (empty($_FILES['po_file']) || !is_array($_FILES['po_file'])) wp_die(esc_html__('PO file is required.', 'yablonsky-multilingual'));
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File upload metadata is validated immediately below; file content is parsed by WordPress POMO.
    $file = $_FILES['po_file'];
    $tmp = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
    $size = isset($file['size']) ? (int) $file['size'] : 0;
    $name = isset($file['name']) ? sanitize_file_name((string) $file['name']) : '';
    $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
    // Never trust a client-supplied size, and never parse partial uploads.
    $actual_size = $tmp !== '' && is_uploaded_file($tmp) ? @filesize($tmp) : false;
    if ($error !== UPLOAD_ERR_OK || $actual_size === false || $actual_size <= 0 || $actual_size > 5 * MB_IN_BYTES || $size <= 0 || $size > 5 * MB_IN_BYTES || strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'po') wp_die(esc_html__('Invalid PO upload.', 'yablonsky-multilingual'));
    require_once ABSPATH . WPINC . '/pomo/po.php';
    $po = new \PO();
    if (!$po->import_from_file($tmp)) wp_die(esc_html__('PO parsing needs attention. Choose a valid PO file and retry.', 'yablonsky-multilingual'));
    if (!self::po_scope_matches($po, $locale, $domain)) wp_die(esc_html__('Invalid string interchange scope.', 'yablonsky-multilingual'));
    global $wpdb;
    $table = StringTranslations::table();
    $written = 0;
    foreach ($po->entries as $entry) {
      if (!($entry instanceof \Translation_Entry)) continue;
      $source = sanitize_textarea_field((string) $entry->singular);
      $context = sanitize_text_field((string) $entry->context);
      $translated = isset($entry->translations[0]) ? sanitize_textarea_field((string) $entry->translations[0]) : '';
      if ($source === '' || $translated === '') continue;
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit reviewed import to YMulti-owned dictionary.
      $wpdb->replace($table, [
        'locale' => $locale, 'text_domain' => $domain, 'context_name' => $context,
        'source_hash' => sha1($source), 'source_text' => $source, 'translated_text' => $translated,
        'updated_at' => current_time('mysql', true),
      ], ['%s','%s','%s','%s','%s','%s','%s']);
      $written++;
    }
    $domains = get_option(StringTranslations::OPT_DOMAINS, []); if (!is_array($domains)) $domains = [];
    if (!in_array($domain, $domains, true)) { $domains[] = $domain; update_option(StringTranslations::OPT_DOMAINS, array_values(array_unique($domains)), false); }
    wp_safe_redirect(add_query_arg(['page'=>'ypm-strings','locale'=>$locale,'ymulti_po_imported'=>$written], admin_url('admin.php')));
    exit;
  }
}
