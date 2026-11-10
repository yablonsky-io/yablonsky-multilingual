<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Provider-neutral read-only family projection used during coexistence.
 *
 * Native YMulti identity always remains authoritative. When another runtime
 * temporarily owns public multilingual routing, incumbent object relations are
 * projected into an effective family view without writing to that provider.
 */
final class EffectiveRelations {
  /** @var array<string,array<string,mixed>> */
  private static array $post_family_cache = [];
  /** @var array<string,array<string,mixed>> */
  private static array $term_family_cache = [];

  public static function reset(): void {
    self::$post_family_cache = [];
    self::$term_family_cache = [];
  }

  /**
   * Return the effective post family for one WordPress object.
   *
   * @param array<string,int>|null $native Optional already-hydrated native family.
   * @param string[]|null $active Optional active locale scope.
   * @return array{
   *   translations:array<string,int>,
   *   provenance:array<string,string>,
   *   source:string,
   *   provider:string,
   *   bridged:bool,
   *   external_count:int,
   *   conflicts:array<string,array{native:int,external:int}>,
   *   family_key:string
   * }
   */
  public static function post_family(int $seed, ?array $native = null, ?array $active = null): array {
    if ($seed <= 0 || !(get_post($seed) instanceof \WP_Post)) return self::empty_family($seed);

    $active = is_array($active) ? array_values(array_unique(array_filter(array_map('strval', $active)))) : Languages::active_codes();
    $native_supplied = is_array($native);
    $cache_key = $seed . '|' . implode(',', $active) . '|' . ($native_supplied ? md5(wp_json_encode($native)) : 'auto');
    if (isset(self::$post_family_cache[$cache_key])) return self::$post_family_cache[$cache_key];

    if (!$native_supplied) {
      $group = Translations::group_id($seed);
      if ($group !== '') {
        $native = Translations::translations_by_language_for_group($group);
      } else {
        $assigned = Translations::assigned_post_language($seed);
        $native = $assigned !== null ? [$assigned => $seed] : [];
      }
    }

    $translations = [];
    $provenance = [];
    foreach ((array) $native as $code => $post_id) {
      $code = (string) $code;
      $post_id = absint($post_id);
      if ($code === '' || $post_id <= 0 || !(get_post($post_id) instanceof \WP_Post)) continue;
      $translations[$code] = $post_id;
      $provenance[$code] = 'native';
    }

    $provider = class_exists(RuntimeAuthority::class, false) ? RuntimeAuthority::public_owner() : 'ymulti';
    $bridged = false;
    $external_count = 0;
    $conflicts = [];
    $seed_external_language = '';
    $external_ids = [];

    if (in_array($provider, ['wpml', 'polylang'], true) && class_exists(RuntimeAuthority::class, false)) {
      foreach ($active as $code) {
        $target_id = RuntimeAuthority::translated_post_id($seed, $code);
        if ($target_id <= 0) continue;
        $external_ids[$target_id] = true;
        if ($target_id === $seed) $seed_external_language = $code;

        if (isset($translations[$code])) {
          if ((int) $translations[$code] !== $target_id) {
            $conflicts[$code] = ['native' => (int) $translations[$code], 'external' => $target_id];
          }
          continue;
        }

        $translations[$code] = $target_id;
        $provenance[$code] = $provider;
        $external_count++;
        $bridged = true;
      }
    }

    if (!$translations) {
      $fallback = Translations::get_post_language($seed);
      if ($fallback !== '') {
        $translations[$fallback] = $seed;
        $provenance[$fallback] = 'native-fallback';
      }
    }

    $source = '';
    $assigned = Translations::assigned_post_language($seed);
    if ($assigned !== null && isset($translations[$assigned]) && (int) $translations[$assigned] === $seed) {
      $source = $assigned;
    } elseif ($seed_external_language !== '') {
      $source = $seed_external_language;
    } else {
      foreach ($translations as $code => $post_id) {
        if ((int) $post_id === $seed) {
          $source = (string) $code;
          break;
        }
      }
    }
    if ($source === '') $source = Translations::get_post_language($seed);

    $group = Translations::group_id($seed);
    if ($group !== '') {
      $family_key = 'ymulti:' . $group;
    } elseif ($external_ids) {
      $ids = array_keys($external_ids);
      sort($ids, SORT_NUMERIC);
      $family_key = $provider . ':' . implode(',', $ids);
    } else {
      $family_key = 'post:' . $seed;
    }

    $result = [
      'translations' => $translations,
      'provenance' => $provenance,
      'source' => $source,
      'provider' => $provider,
      'bridged' => $bridged,
      'external_count' => $external_count,
      'conflicts' => $conflicts,
      'family_key' => $family_key,
    ];
    self::$post_family_cache[$cache_key] = $result;
    return $result;
  }


  /**
   * Return the effective translation family for one taxonomy term.
   *
   * @param array<string,int>|null $native Optional already-hydrated native family.
   * @param string[]|null $active Optional active locale scope.
   * @return array<string,mixed>
   */
  public static function term_family(int $seed, string $taxonomy, ?array $native = null, ?array $active = null): array {
    $taxonomy = sanitize_key($taxonomy);
    $term = $seed > 0 && $taxonomy !== '' ? get_term($seed, $taxonomy) : null;
    if (!($term instanceof \WP_Term)) return self::empty_term_family($seed, $taxonomy);

    $active = is_array($active) ? array_values(array_unique(array_filter(array_map('strval', $active)))) : Languages::active_codes();
    $native_supplied = is_array($native);
    $cache_key = $taxonomy . ':' . $seed . '|' . implode(',', $active) . '|' . ($native_supplied ? md5(wp_json_encode($native)) : 'auto');
    if (isset(self::$term_family_cache[$cache_key])) return self::$term_family_cache[$cache_key];

    if (!$native_supplied) {
      $group = TermTranslations::group_id($seed);
      if ($group !== '') {
        $native = TermTranslations::translations_by_language_for_group($taxonomy, $group);
      } else {
        $assigned = TermTranslations::assigned_term_language($seed);
        $native = $assigned !== null ? [$assigned => $seed] : [];
      }
    }

    $translations = [];
    $provenance = [];
    foreach ((array) $native as $code => $term_id) {
      $code = (string) $code;
      $term_id = absint($term_id);
      if ($code === '' || $term_id <= 0 || !(get_term($term_id, $taxonomy) instanceof \WP_Term)) continue;
      $translations[$code] = $term_id;
      $provenance[$code] = 'native';
    }

    $provider = class_exists(RuntimeAuthority::class, false) ? RuntimeAuthority::public_owner() : 'ymulti';
    $bridged = false;
    $external_count = 0;
    $conflicts = [];
    $seed_external_language = '';
    $external_ids = [];

    if (in_array($provider, ['wpml', 'polylang'], true) && class_exists(RuntimeAuthority::class, false)) {
      foreach ($active as $code) {
        $target_id = RuntimeAuthority::translated_term_id($seed, $taxonomy, $code);
        if ($target_id <= 0) continue;
        $external_ids[$target_id] = true;
        if ($target_id === $seed) $seed_external_language = $code;

        if (isset($translations[$code])) {
          if ((int) $translations[$code] !== $target_id) $conflicts[$code] = ['native' => (int) $translations[$code], 'external' => $target_id];
          continue;
        }
        $translations[$code] = $target_id;
        $provenance[$code] = $provider;
        $external_count++;
        $bridged = true;
      }
    }

    if (!$translations) {
      $fallback = TermTranslations::get_term_language($seed);
      if ($fallback !== '') {
        $translations[$fallback] = $seed;
        $provenance[$fallback] = 'native-fallback';
      }
    }

    $source = '';
    $assigned = TermTranslations::assigned_term_language($seed);
    if ($assigned !== null && isset($translations[$assigned]) && (int) $translations[$assigned] === $seed) {
      $source = $assigned;
    } elseif ($seed_external_language !== '') {
      $source = $seed_external_language;
    } else {
      foreach ($translations as $code => $term_id) {
        if ((int) $term_id === $seed) { $source = (string) $code; break; }
      }
    }
    if ($source === '') $source = TermTranslations::get_term_language($seed);

    $group = TermTranslations::group_id($seed);
    if ($group !== '') {
      $family_key = 'ymulti-term:' . $taxonomy . ':' . $group;
    } elseif ($external_ids) {
      $ids = array_keys($external_ids);
      sort($ids, SORT_NUMERIC);
      $family_key = $provider . '-term:' . $taxonomy . ':' . implode(',', $ids);
    } else {
      $family_key = 'term:' . $taxonomy . ':' . $seed;
    }

    $result = [
      'translations' => $translations,
      'provenance' => $provenance,
      'source' => $source,
      'provider' => $provider,
      'bridged' => $bridged,
      'external_count' => $external_count,
      'conflicts' => $conflicts,
      'family_key' => $family_key,
    ];
    self::$term_family_cache[$cache_key] = $result;
    return $result;
  }

  public static function existing_term_target(int $source_id, string $taxonomy, string $target_locale): int {
    if ($source_id <= 0 || $target_locale === '') return 0;
    $family = self::term_family($source_id, $taxonomy);
    return absint($family['translations'][$target_locale] ?? 0);
  }

  public static function existing_target(int $source_id, string $target_locale): int {
    if ($source_id <= 0 || $target_locale === '') return 0;
    $family = self::post_family($source_id);
    return absint($family['translations'][$target_locale] ?? 0);
  }


  /** @return array<string,mixed> */
  private static function empty_term_family(int $seed, string $taxonomy): array {
    return [
      'translations' => [],
      'provenance' => [],
      'source' => '',
      'provider' => 'none',
      'bridged' => false,
      'external_count' => 0,
      'conflicts' => [],
      'family_key' => 'term:' . sanitize_key($taxonomy) . ':' . max(0, $seed),
    ];
  }

  /** @return array<string,mixed> */
  private static function empty_family(int $seed): array {
    return [
      'translations' => [],
      'provenance' => [],
      'source' => '',
      'provider' => 'none',
      'bridged' => false,
      'external_count' => 0,
      'conflicts' => [],
      'family_key' => 'post:' . max(0, $seed),
    ];
  }
}
