<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Runtime-bounded multilingual identity for WordPress taxonomy terms.
 *
 * Term language/family state is stored as compact term meta. Read paths use
 * targeted batched projections and request-local maps so callers never need a
 * scalar get_term_meta() loop across large WooCommerce taxonomy sets.
 */
final class TermTranslations {
  public const META_LANGUAGE = '_ypm_term_language';
  public const META_GROUP = '_ypm_term_group';
  private const PRIME_TERM_BATCH = 400;
  private const PRIME_GROUP_BATCH = 100;

  /** @var array<int,string> */
  private static array $language_cache = [];

  /** @var array<int,bool> */
  private static array $language_assignment_cache = [];

  /** @var array<int,string> */
  private static array $group_cache = [];

  /** @var array<string,array<string,int>> */
  private static array $group_language_cache = [];

  /** Clear per-blog taxonomy projections after WordPress switches sites. */
  public static function reset_request_cache(): void {
    self::$language_cache = [];
    self::$language_assignment_cache = [];
    self::$group_cache = [];
    self::$group_language_cache = [];
  }

  public static function init(): void {
    if (!class_exists('Yablonsky\\Multilingual\\RuntimeAuthority') || RuntimeAuthority::ymulti_owns_public_runtime()) {
      add_action('template_redirect', [__CLASS__, 'ensure_correct_language_on_term_archive'], 2);
    }
  }

  public static function is_supported_taxonomy(string $taxonomy): bool {
    return in_array($taxonomy, Languages::supported_taxonomies(), true);
  }

  /**
   * Prime language and group projections for a bounded term set.
   *
   * @param int[] $term_ids
   */
  public static function prime_terms(array $term_ids): void {
    global $wpdb;

    $term_ids = array_values(array_unique(array_filter(array_map('intval', $term_ids))));
    if (!$term_ids) return;

    $missing = [];
    foreach ($term_ids as $term_id) {
      if (!array_key_exists($term_id, self::$language_assignment_cache) || !array_key_exists($term_id, self::$group_cache)) {
        $missing[] = $term_id;
      }
    }
    if (!$missing) return;

    foreach (array_chunk($missing, self::PRIME_TERM_BATCH) as $batch) {
      $placeholders = implode(',', array_fill(0, count($batch), '%d'));
      $sql = "SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id IN ({$placeholders}) AND meta_key IN (%s, %s)";
      $params = array_merge($batch, [self::META_LANGUAGE, self::META_GROUP]);
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholders are generated only from the bounded integer batch size; all values are passed directly through prepare().
      $rows = $wpdb->get_results($wpdb->prepare($sql, $params));

      $seen_language = [];
      $seen_group = [];
      if (is_array($rows)) {
        foreach ($rows as $row) {
          if (!isset($row->term_id, $row->meta_key)) continue;
          $term_id = (int) $row->term_id;
          if ((string) $row->meta_key === self::META_LANGUAGE) {
            $code = sanitize_text_field((string) $row->meta_value);
            if ($code !== '') {
              self::$language_cache[$term_id] = $code;
              self::$language_assignment_cache[$term_id] = true;
              $seen_language[$term_id] = true;
            }
          } elseif ((string) $row->meta_key === self::META_GROUP) {
            $group = sanitize_title((string) $row->meta_value);
            self::$group_cache[$term_id] = $group;
            $seen_group[$term_id] = true;
          }
        }
      }

      foreach ($batch as $term_id) {
        if (!isset($seen_language[$term_id])) self::$language_assignment_cache[$term_id] = false;
        if (!isset($seen_group[$term_id])) self::$group_cache[$term_id] = '';
      }
    }
  }

  public static function assigned_term_language(int $term_id): ?string {
    self::prime_terms([$term_id]);
    if (!empty(self::$language_assignment_cache[$term_id])) {
      return self::$language_cache[$term_id] ?? Languages::default_code();
    }
    return null;
  }

  public static function get_term_language(int $term_id): string {
    $assigned = self::assigned_term_language($term_id);
    return $assigned ?: Languages::default_code();
  }

  public static function set_term_language(int $term_id, string $code): void {
    if ($term_id <= 0 || !Languages::is_active($code)) return;
    $previous_language = self::assigned_term_language($term_id);
    update_term_meta($term_id, self::META_LANGUAGE, $code);
    self::$language_cache[$term_id] = $code;
    self::$language_assignment_cache[$term_id] = true;

    $group = self::$group_cache[$term_id] ?? '';
    if ($group !== '') {
      foreach (array_keys(self::$group_language_cache) as $cache_key) {
        if (substr($cache_key, -strlen('|' . $group)) === '|' . $group) unset(self::$group_language_cache[$cache_key]);
      }
    }
    if (class_exists('Yablonsky\Multilingual\RuntimeAuthority', false)) RuntimeAuthority::reset();
    if (class_exists('Yablonsky\Multilingual\EffectiveRelations', false)) EffectiveRelations::reset();
    if (class_exists('Yablonsky\Multilingual\SiteCoverage', false)) SiteCoverage::reset();
    self::emit_term_graph_change($term_id, 'term_language_changed', [
      'previous_language' => $previous_language ?: '',
      'current_language' => $code,
      'previous_group' => $group,
      'current_group' => $group,
    ]);
  }

  public static function group_id(int $term_id): string {
    self::prime_terms([$term_id]);
    return self::$group_cache[$term_id] ?? '';
  }

  public static function ensure_group_id(int $term_id): string {
    $group = self::group_id($term_id);
    if ($group !== '') return $group;

    $group = sanitize_title(wp_generate_uuid4());
    self::set_group_id($term_id, $group);
    return $group;
  }

  public static function set_group_id(int $term_id, string $group_id): void {
    $group_id = sanitize_title($group_id);
    if ($term_id <= 0 || $group_id === '') return;

    $previous_group = self::group_id($term_id);
    update_term_meta($term_id, self::META_GROUP, $group_id);
    self::$group_cache[$term_id] = $group_id;
    foreach (array_keys(self::$group_language_cache) as $cache_key) {
      if (substr($cache_key, -strlen('|' . $group_id)) === '|' . $group_id || ($previous_group !== '' && substr($cache_key, -strlen('|' . $previous_group)) === '|' . $previous_group)) unset(self::$group_language_cache[$cache_key]);
    }
    if (class_exists('Yablonsky\Multilingual\RuntimeAuthority', false)) RuntimeAuthority::reset();
    if (class_exists('Yablonsky\Multilingual\EffectiveRelations', false)) EffectiveRelations::reset();
    if (class_exists('Yablonsky\Multilingual\SiteCoverage', false)) SiteCoverage::reset();
    self::emit_term_graph_change($term_id, 'term_translation_group_changed', [
      'previous_group' => $previous_group,
      'current_group' => $group_id,
      'current_language' => self::get_term_language($term_id),
    ]);
  }

  /**
   * Prime complete translation families for visible terms in bounded groups.
   *
   * @param int[] $term_ids
   */
  public static function prime_translation_families(array $term_ids, string $taxonomy): void {
    global $wpdb;

    if (!self::is_supported_taxonomy($taxonomy)) return;
    $term_ids = array_values(array_unique(array_filter(array_map('intval', $term_ids))));
    if (!$term_ids) return;

    self::prime_terms($term_ids);
    $groups = [];
    foreach ($term_ids as $term_id) {
      $group = self::$group_cache[$term_id] ?? '';
      if ($group !== '') $groups[$group] = true;
    }
    if (!$groups) return;

    foreach (array_chunk(array_keys($groups), self::PRIME_GROUP_BATCH) as $group_batch) {
      $pending = [];
      foreach ($group_batch as $group) {
        $cache_key = $taxonomy . '|' . $group;
        if (!array_key_exists($cache_key, self::$group_language_cache)) $pending[] = $group;
      }
      if (!$pending) continue;

      $placeholders = implode(',', array_fill(0, count($pending), '%s'));
      $sql = "SELECT DISTINCT tt.term_id, gm.meta_value AS group_id, lm.meta_value AS language
        FROM {$wpdb->term_taxonomy} tt
        INNER JOIN {$wpdb->termmeta} gm ON gm.term_id = tt.term_id AND gm.meta_key = %s
        LEFT JOIN {$wpdb->termmeta} lm ON lm.term_id = tt.term_id AND lm.meta_key = %s
        WHERE tt.taxonomy = %s AND gm.meta_value IN ({$placeholders})";
      $params = array_merge([self::META_GROUP, self::META_LANGUAGE, $taxonomy], $pending);
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholders are generated from a bounded internal group batch; all values are passed directly through prepare().
      $rows = $wpdb->get_results($wpdb->prepare($sql, $params));

      foreach ($pending as $group) self::$group_language_cache[$taxonomy . '|' . $group] = [];
      if (!is_array($rows)) continue;

      foreach ($rows as $row) {
        if (!isset($row->term_id, $row->group_id)) continue;
        $term_id = (int) $row->term_id;
        $group = sanitize_title((string) $row->group_id);
        $language = isset($row->language) && (string) $row->language !== ''
          ? sanitize_text_field((string) $row->language)
          : Languages::default_code();
        $cache_key = $taxonomy . '|' . $group;
        if (!isset(self::$group_language_cache[$cache_key][$language])) {
          self::$group_language_cache[$cache_key][$language] = $term_id;
        }
        self::$group_cache[$term_id] = $group;
        self::$language_cache[$term_id] = $language;
        self::$language_assignment_cache[$term_id] = isset($row->language) && (string) $row->language !== '';
      }
    }
  }

  /**
   * @return array<string,int>
   */
  public static function translations_by_language_for_group(string $taxonomy, string $group_id): array {
    $group_id = sanitize_title($group_id);
    if ($group_id === '' || !self::is_supported_taxonomy($taxonomy)) return [];

    $cache_key = $taxonomy . '|' . $group_id;
    if (!array_key_exists($cache_key, self::$group_language_cache)) {
      global $wpdb;
      $sql = "SELECT DISTINCT tt.term_id, lm.meta_value AS language
        FROM {$wpdb->term_taxonomy} tt
        INNER JOIN {$wpdb->termmeta} gm ON gm.term_id = tt.term_id AND gm.meta_key = %s AND gm.meta_value = %s
        LEFT JOIN {$wpdb->termmeta} lm ON lm.term_id = tt.term_id AND lm.meta_key = %s
        WHERE tt.taxonomy = %s";
      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- One translation family is bounded by locale count and all values are passed directly through prepare().
      $rows = $wpdb->get_results($wpdb->prepare($sql, self::META_GROUP, $group_id, self::META_LANGUAGE, $taxonomy));
      self::$group_language_cache[$cache_key] = [];
      if (is_array($rows)) {
        foreach ($rows as $row) {
          if (!isset($row->term_id)) continue;
          $term_id = (int) $row->term_id;
          $language = isset($row->language) && (string) $row->language !== ''
            ? sanitize_text_field((string) $row->language)
            : Languages::default_code();
          if (!isset(self::$group_language_cache[$cache_key][$language])) {
            self::$group_language_cache[$cache_key][$language] = $term_id;
          }
          self::$group_cache[$term_id] = $group_id;
          self::$language_cache[$term_id] = $language;
          self::$language_assignment_cache[$term_id] = isset($row->language) && (string) $row->language !== '';
        }
      }
    }

    return self::$group_language_cache[$cache_key];
  }

  public static function get_translation(int $term_id, string $taxonomy, string $language): ?int {
    $group = self::group_id($term_id);
    if ($group === '') return null;
    $map = self::translations_by_language_for_group($taxonomy, $group);
    return isset($map[$language]) ? (int) $map[$language] : null;
  }

  /** @param array<string,mixed> $extra */
  private static function emit_term_graph_change(int $term_id, string $reason, array $extra = []): void {
    $term = get_term($term_id);
    $taxonomy = $term instanceof \WP_Term ? sanitize_key((string) $term->taxonomy) : '';
    if ($taxonomy === '' || !self::is_supported_taxonomy($taxonomy)) return;
    do_action('yablonsky_multilingual_term_graph_changed_v1', array_merge([
      'term_id' => $term_id,
      'taxonomy' => $taxonomy,
      'reason' => $reason,
    ], $extra));
  }

  public static function ensure_correct_language_on_term_archive(): void {
    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) return;
    if (!(is_category() || is_tag() || is_tax())) return;

    $term = get_queried_object();
    if (!($term instanceof \WP_Term)) return;
    $taxonomy = (string) $term->taxonomy;
    if (!self::is_supported_taxonomy($taxonomy)) return;

    $requested = Router::current_lang();
    $actual = self::get_term_language((int) $term->term_id);
    if ($requested === $actual) return;

    $target_id = self::get_translation((int) $term->term_id, $taxonomy, $requested);
    $target_language = $requested;
    if (!$target_id) {
      $target_id = (int) $term->term_id;
      $target_language = $actual;
    }

    $target = Urls::for_term($target_id, $taxonomy, $target_language);
    if ($target !== '') {
      wp_safe_redirect($target, 302, 'YMulti');
      exit;
    }
  }
}
