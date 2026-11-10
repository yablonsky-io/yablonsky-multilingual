<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Product-wide language-owned entity contract.
 *
 * Every admin/workflow surface that operates on translated WordPress objects
 * must resolve Source, family membership, Coverage and target existence through
 * the same provider-neutral truth. Native YMulti identity wins; an incumbent
 * provider is projected read-only during coexistence.
 */
final class EntityContract {
  /** @return string[] */
  public static function active_locales(): array {
    // The incumbent may expose public locales before YMulti's admin-only
    // source-language sync has run (always possible with Polylang). Public
    // SEO families must include real incumbent translations on a fresh request.
    $locales = Languages::active_codes();
    if (class_exists(RuntimeAuthority::class, false) && !RuntimeAuthority::ymulti_owns_public_runtime()) {
      $locales = array_merge($locales, RuntimeAuthority::public_language_codes());
    }
    return array_values(array_unique(array_filter(array_map('strval', $locales))));
  }

  public static function post_source_locale(int $post_id): string {
    return $post_id > 0 ? RuntimeAuthority::effective_post_language($post_id) : '';
  }

  public static function term_source_locale(int $term_id, string $taxonomy): string {
    return $term_id > 0 ? RuntimeAuthority::effective_term_language($term_id, sanitize_key($taxonomy)) : '';
  }

  /** @param string[]|null $active @return array<string,mixed> */
  public static function post_family(int $post_id, ?array $active = null): array {
    return EffectiveRelations::post_family($post_id, null, $active ?? self::active_locales());
  }

  /** @param string[]|null $active @return array<string,mixed> */
  public static function term_family(int $term_id, string $taxonomy, ?array $active = null): array {
    return EffectiveRelations::term_family($term_id, sanitize_key($taxonomy), null, $active ?? self::active_locales());
  }

  /** @param string[]|null $active @return string[] */
  public static function post_coverage_locales(int $post_id, ?array $active = null): array {
    $active = $active ?? self::active_locales();
    $family = self::post_family($post_id, $active);
    return array_values(array_intersect($active, array_keys((array) ($family['translations'] ?? []))));
  }

  /** @param string[]|null $active @return string[] */
  public static function term_coverage_locales(int $term_id, string $taxonomy, ?array $active = null): array {
    $active = $active ?? self::active_locales();
    $family = self::term_family($term_id, $taxonomy, $active);
    return array_values(array_intersect($active, array_keys((array) ($family['translations'] ?? []))));
  }

  public static function existing_post_target(int $source_id, string $target_locale): int {
    if ($source_id <= 0 || !Languages::is_active($target_locale)) return 0;
    return EffectiveRelations::existing_target($source_id, $target_locale);
  }

  public static function existing_term_target(int $source_id, string $taxonomy, string $target_locale): int {
    if ($source_id <= 0 || !Languages::is_active($target_locale)) return 0;
    return EffectiveRelations::existing_term_target($source_id, sanitize_key($taxonomy), $target_locale);
  }

  public static function post_target_allowed(int $source_id, string $target_locale): bool {
    return $source_id > 0 && Languages::is_active($target_locale) && self::post_source_locale($source_id) !== $target_locale;
  }

  public static function term_target_allowed(int $source_id, string $taxonomy, string $target_locale): bool {
    return $source_id > 0 && Languages::is_active($target_locale) && self::term_source_locale($source_id, $taxonomy) !== $target_locale;
  }

  /** @return string[] */
  public static function post_target_locales(int $source_id): array {
    $source = self::post_source_locale($source_id);
    return array_values(array_filter(self::active_locales(), static fn(string $code): bool => $code !== $source));
  }

  /** @return string[] */
  public static function term_target_locales(int $source_id, string $taxonomy): array {
    $source = self::term_source_locale($source_id, $taxonomy);
    return array_values(array_filter(self::active_locales(), static fn(string $code): bool => $code !== $source));
  }



  /** @return int[] */
  public static function post_family_member_ids(int $seed): array {
    if ($seed <= 0) return [];
    $family = self::post_family($seed);
    $ids = array_values(array_unique(array_filter(array_map('absint', array_values((array) ($family['translations'] ?? []))))));
    if (!$ids && get_post($seed) instanceof \WP_Post) $ids[] = $seed;
    sort($ids, SORT_NUMERIC);
    return $ids;
  }

  /**
   * Stable owner for family-scoped YMulti sidecars. The owner is deliberately
   * independent from the currently selected Source row so switching Source does
   * not split labels/media metadata across physical translated objects.
   */
  public static function post_sidecar_owner_id(int $seed): int {
    if ($seed <= 0) return 0;
    $family = self::post_family($seed);
    $translations = (array) ($family['translations'] ?? []);
    $default_id = absint($translations[Languages::default_code()] ?? 0);
    if ($default_id > 0) return $default_id;
    $source = sanitize_text_field((string) ($family['source'] ?? ''));
    $source_id = absint($translations[$source] ?? 0);
    if ($source_id > 0) return $source_id;
    $ids = self::post_family_member_ids($seed);
    return $ids ? (int) $ids[0] : (get_post($seed) instanceof \WP_Post ? $seed : 0);
  }

  /**
   * Read a locale-keyed family sidecar without losing values written by older
   * builds to a non-canonical physical member. Canonical-owner values win.
   *
   * @return array<string,mixed>
   */
  public static function merged_post_family_meta(int $seed, string $meta_key): array {
    if ($seed <= 0 || $meta_key === '') return [];
    $owner = self::post_sidecar_owner_id($seed);
    $ids = self::post_family_member_ids($seed);
    if ($owner > 0) {
      $ids = array_values(array_diff($ids, [$owner]));
      $ids[] = $owner;
    }
    $merged = [];
    foreach ($ids as $member_id) {
      $raw = get_post_meta((int) $member_id, $meta_key, true);
      if (is_array($raw)) $merged = array_replace($merged, $raw);
    }
    return $merged;
  }

  /**
   * Persist a family-scoped YMulti sidecar on the canonical owner and remove
   * legacy duplicate copies from sibling members. This also makes deletes
   * durable: a cleared locale cannot reappear from an older sibling record.
   */
  public static function write_post_family_meta(int $seed, string $meta_key, array $value): bool {
    if ($seed <= 0 || $meta_key === '') return false;
    $owner = self::post_sidecar_owner_id($seed);
    if ($owner <= 0) return false;
    if ($value) update_post_meta($owner, $meta_key, $value); else delete_post_meta($owner, $meta_key);
    foreach (self::post_family_member_ids($seed) as $member_id) {
      if ((int) $member_id !== $owner) delete_post_meta((int) $member_id, $meta_key);
    }
    if (class_exists('Yablonsky\Multilingual\SiteCoverage', false)) SiteCoverage::reset();
    return true;
  }

  /** Stable provider-neutral family key for one post object. */
  public static function post_family_key(int $post_id): string {
    $family = self::post_family($post_id);
    $key = sanitize_text_field((string) ($family['family_key'] ?? ''));
    return $key !== '' ? $key : 'post:' . max(0, $post_id);
  }

  /** Stable provider-neutral family key for one taxonomy term. */
  public static function term_family_key(int $term_id, string $taxonomy): string {
    $family = self::term_family($term_id, $taxonomy);
    $key = sanitize_text_field((string) ($family['family_key'] ?? ''));
    return $key !== '' ? $key : 'term:' . sanitize_key($taxonomy) . ':' . max(0, $term_id);
  }

  /**
   * Resolve the member that must represent a family in a catalog row.
   * A concrete Source filter always wins. Without one, keep the supplied seed
   * when it is a valid family member; this preserves deterministic ordering
   * while the family key guarantees one logical row per family.
   */
  public static function representative_post_id(int $seed, string $source_locale = ''): int {
    if ($seed <= 0) return 0;
    $family = self::post_family($seed);
    $translations = (array) ($family['translations'] ?? []);
    if ($source_locale !== '') {
      $candidate = absint($translations[$source_locale] ?? 0);
      return $candidate > 0 ? $candidate : 0;
    }
    if (in_array($seed, array_map('absint', array_values($translations)), true)) return $seed;
    $source = sanitize_text_field((string) ($family['source'] ?? ''));
    $candidate = absint($translations[$source] ?? 0);
    if ($candidate > 0) return $candidate;
    foreach ($translations as $candidate) {
      $candidate = absint($candidate);
      if ($candidate > 0) return $candidate;
    }
    return get_post($seed) instanceof \WP_Post ? $seed : 0;
  }

  public static function representative_term_id(int $seed, string $taxonomy, string $source_locale = ''): int {
    if ($seed <= 0) return 0;
    $family = self::term_family($seed, $taxonomy);
    $translations = (array) ($family['translations'] ?? []);
    if ($source_locale !== '') {
      $candidate = absint($translations[$source_locale] ?? 0);
      return $candidate > 0 ? $candidate : 0;
    }
    if (in_array($seed, array_map('absint', array_values($translations)), true)) return $seed;
    $source = sanitize_text_field((string) ($family['source'] ?? ''));
    $candidate = absint($translations[$source] ?? 0);
    if ($candidate > 0) return $candidate;
    foreach ($translations as $candidate) {
      $candidate = absint($candidate);
      if ($candidate > 0) return $candidate;
    }
    $term = get_term($seed, sanitize_key($taxonomy));
    return $term instanceof \WP_Term ? $seed : 0;
  }

  /** @param array<int,\WP_Post> $posts @return array<int,\WP_Post> */
  public static function unique_post_families(array $posts, string $source_locale = ''): array {
    $out = [];
    $seen = [];
    foreach ($posts as $post) {
      if (!($post instanceof \WP_Post)) continue;
      $seed = (int) $post->ID;
      if ($source_locale !== '' && self::post_source_locale($seed) !== $source_locale) continue;
      $key = self::post_family_key($seed);
      if (isset($seen[$key])) continue;
      $representative = self::representative_post_id($seed, $source_locale);
      if ($representative <= 0) continue;
      $row = $representative === $seed ? $post : get_post($representative);
      if (!($row instanceof \WP_Post)) continue;
      $seen[$key] = true;
      $out[] = $row;
    }
    return $out;
  }

  /** @param array<int,\WP_Term> $terms @return array<int,\WP_Term> */
  public static function unique_term_families(array $terms, string $taxonomy, string $source_locale = ''): array {
    $out = [];
    $seen = [];
    $taxonomy = sanitize_key($taxonomy);
    foreach ($terms as $term) {
      if (!($term instanceof \WP_Term)) continue;
      $seed = (int) $term->term_id;
      if ($source_locale !== '' && self::term_source_locale($seed, $taxonomy) !== $source_locale) continue;
      $key = self::term_family_key($seed, $taxonomy);
      if (isset($seen[$key])) continue;
      $representative = self::representative_term_id($seed, $taxonomy, $source_locale);
      if ($representative <= 0) continue;
      $row = $representative === $seed ? $term : get_term($representative, $taxonomy);
      if (!($row instanceof \WP_Term)) continue;
      $seen[$key] = true;
      $out[] = $row;
    }
    return $out;
  }

  /**
   * Deterministic family source for features that need one canonical source
   * object (freshness/copy provenance). Prefer explicit persisted provenance,
   * then the family default-locale member, then the seed/effective member.
   */
  public static function canonical_post_source_id(int $seed, int $persisted_source_id = 0): int {
    if ($persisted_source_id > 0 && get_post($persisted_source_id) instanceof \WP_Post) return $persisted_source_id;
    if ($seed <= 0) return 0;
    $family = self::post_family($seed);
    $translations = (array) ($family['translations'] ?? []);
    $default = Languages::default_code();
    $default_id = absint($translations[$default] ?? 0);
    if ($default_id > 0) return $default_id;
    $source_locale = (string) ($family['source'] ?? '');
    $source_id = absint($translations[$source_locale] ?? 0);
    if ($source_id > 0) return $source_id;
    foreach ($translations as $candidate) {
      $candidate = absint($candidate);
      if ($candidate > 0) return $candidate;
    }
    return get_post($seed) instanceof \WP_Post ? $seed : 0;
  }
}
