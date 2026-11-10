<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Local exact-match translation memory.
 *
 * Memory is admin/authoring infrastructure only. It never runs a remote call
 * and never participates in frontend routing. Exact source+locale+role hashes
 * are reused only after they were produced by a successful reviewed translation
 * pipeline, reducing repeated AI work while preserving source identity.
 */
final class TranslationMemory {
  public const TABLE_SUFFIX = 'ymulti_translation_memory';
  public const OPT_STATS = 'ymulti_translation_memory_stats_v1';

  public static function table(): string {
    global $wpdb;
    return $wpdb->prefix . self::TABLE_SUFFIX;
  }

  public static function install_table(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = self::table();
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      source_locale varchar(32) NOT NULL,
      target_locale varchar(32) NOT NULL,
      role varchar(96) NOT NULL,
      source_hash char(64) NOT NULL,
      source_text longtext NOT NULL,
      translated_text longtext NOT NULL,
      hit_count bigint(20) unsigned NOT NULL DEFAULT 0,
      updated_at datetime NOT NULL,
      PRIMARY KEY  (id),
      UNIQUE KEY source_target_role_hash (source_locale,target_locale,role,source_hash),
      KEY target_locale (target_locale),
      KEY updated_at (updated_at)
    ) {$charset};";
    dbDelta($sql);
  }

  public static function role_for_segment(array $segment): string {
    $role = sanitize_key((string) ($segment['role'] ?? 'text'));
    $mode = sanitize_key((string) ($segment['mode'] ?? 'text'));
    $context = (string) ($segment['context'] ?? '');
    return substr('segment:' . $role . ':' . $mode . ':' . substr(hash('sha256', $context), 0, 12), 0, 96);
  }

  /**
   * @param array<string,array{role:string,source:string}> $items
   * @return array<string,string>
   */
  public static function lookup_many(string $source_locale, string $target_locale, array $items): array {
    global $wpdb;
    if (!$items || $source_locale === '' || $target_locale === '') return [];
    $table = self::table();
    $hash_to_keys = [];
    foreach ($items as $key => $item) {
      $source = (string) ($item['source'] ?? '');
      $role = substr(sanitize_text_field((string) ($item['role'] ?? 'text')), 0, 96);
      if (trim($source) === '' || $role === '') continue;
      $hash = hash('sha256', $source);
      $hash_to_keys[$hash][] = ['key' => (string) $key, 'role' => $role, 'source' => $source];
    }
    if (!$hash_to_keys) return [];

    $out = [];
    foreach (array_chunk(array_keys($hash_to_keys), 80) as $batch) {
      $placeholders = implode(',', array_fill(0, count($batch), '%s'));
      $params = array_merge([$source_locale, $target_locale], $batch);
      $sql = "SELECT role, source_hash, source_text, translated_text FROM {$table} WHERE source_locale=%s AND target_locale=%s AND source_hash IN ({$placeholders})";
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own indexed memory table; table identifier is internal, bounded IN placeholders and all values are prepared.
      $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
      if (!is_array($rows)) continue;
      foreach ($rows as $row) {
        $hash = (string) ($row['source_hash'] ?? '');
        foreach ($hash_to_keys[$hash] ?? [] as $candidate) {
          if (!hash_equals((string) $candidate['source'], (string) ($row['source_text'] ?? ''))) continue;
          if ((string) $candidate['role'] !== (string) ($row['role'] ?? '')) continue;
          $translated = (string) ($row['translated_text'] ?? '');
          if (trim($translated) !== '') $out[(string) $candidate['key']] = $translated;
        }
      }
    }
    if ($out) self::record_hits(count($out));
    return $out;
  }

  private static function record_hits(int $count): void {
    if ($count <= 0) return;
    $stats = get_option(self::OPT_STATS, []);
    if (!is_array($stats)) $stats = [];
    $stats['hits'] = max(0, (int) ($stats['hits'] ?? 0)) + $count;
    $stats['hit_events'] = max(0, (int) ($stats['hit_events'] ?? 0)) + 1;
    $stats['last_hit_at'] = gmdate('c');
    update_option(self::OPT_STATS, $stats, false);
  }

  /** @return array{entries:int,hits:int,hit_events:int,last_hit_at:string} */
  public static function stats(): array {
    $stats = get_option(self::OPT_STATS, []);
    if (!is_array($stats)) $stats = [];
    return [
      'entries' => self::count_entries(),
      'hits' => max(0, (int) ($stats['hits'] ?? 0)),
      'hit_events' => max(0, (int) ($stats['hit_events'] ?? 0)),
      'last_hit_at' => sanitize_text_field((string) ($stats['last_hit_at'] ?? '')),
    ];
  }

  public static function render_panel(): void {
    if (!current_user_can('manage_options')) return;
    $memory_stats = self::stats();
    $memory_entries = (int) ($memory_stats['entries'] ?? 0);
    $memory_hits = (int) ($memory_stats['hits'] ?? 0);
    $memory_last = (string) ($memory_stats['last_hit_at'] ?? '');
    $memory_last_label = $memory_last !== '' ? $memory_last : __('Reuse evidence ready', 'yablonsky-multilingual');
    echo '<section id="ypm-translation-memory" class="ypm-card ypm-card--standalone"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Local reuse', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Translation memory', 'yablonsky-multilingual') . '</h2></div>' . wp_kses_post(Admin::status_badge('neutral', sprintf(
      /* translators: %d: exact-match translation-memory entries. */
      _n('%d reusable entry', '%d reusable entries', $memory_entries, 'yablonsky-multilingual'),
      $memory_entries
    ))) . '</div><p class="ypm-card__copy">' . esc_html__('Exact source text already translated successfully can be reused locally for the same source language, target language and semantic role. Exact matches avoid a repeated remote AI request while structure checks remain unchanged.', 'yablonsky-multilingual') . '</p>';
    echo '<div class="ypm-metrics ypm-metrics--3">';
    Admin::render_metric(__('Reusable entries', 'yablonsky-multilingual'), (string) $memory_entries, __('Exact-match segments stored locally', 'yablonsky-multilingual'), 'neutral');
    Admin::render_metric(__('Segments reused', 'yablonsky-multilingual'), (string) $memory_hits, __('Remote AI calls avoided by exact reuse', 'yablonsky-multilingual'), $memory_hits > 0 ? 'pass' : 'neutral');
    Admin::render_metric(__('Last reuse', 'yablonsky-multilingual'), $memory_last_label, __('Most recent exact-match reuse event', 'yablonsky-multilingual'), 'neutral');
    echo '</div></section>';
  }

  /** @param array<string,array{role:string,source:string,translated:string}> $items */
  public static function remember_many(string $source_locale, string $target_locale, array $items): void {
    global $wpdb;
    if (!$items || $source_locale === '' || $target_locale === '') return;
    $table = self::table();
    $now = current_time('mysql', true);
    foreach ($items as $item) {
      $source = (string) ($item['source'] ?? '');
      $translated = (string) ($item['translated'] ?? '');
      $role = substr(sanitize_text_field((string) ($item['role'] ?? 'text')), 0, 96);
      if (trim($source) === '' || trim($translated) === '' || $role === '') continue;
      $hash = hash('sha256', $source);
      $sql = "INSERT INTO {$table} (source_locale,target_locale,role,source_hash,source_text,translated_text,hit_count,updated_at)
        VALUES (%s,%s,%s,%s,%s,%s,0,%s)
        ON DUPLICATE KEY UPDATE source_text=VALUES(source_text), translated_text=VALUES(translated_text), updated_at=VALUES(updated_at)";
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own indexed memory table; table identifier is internal and all values are placeholders.
      $wpdb->query($wpdb->prepare($sql, $source_locale, $target_locale, $role, $hash, $source, $translated, $now));
    }
  }

  public static function count_entries(): int {
    global $wpdb;
    $table = self::table();
    $table_sql = esc_sql($table);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Internal YMulti table identifier is escaped; query has no request-derived values.
    return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table_sql);
  }
}
