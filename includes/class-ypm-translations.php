<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

final class Translations {
  public const META_GROUP = '_ypm_tr_group';
  public const GROUP_TAX = 'ypm_translation_group';
  public const GROUP_ORDER_ANCHOR_META = '_ypm_family_order_anchor';
  private const GROUP_MATERIALIZED_META = '_ypm_legacy_materialized';
  private const GROUP_MATERIALIZATION_PAGE_META = '_ypm_legacy_materialization_page';
  private const PRIME_OBJECT_BATCH = 500;
  private const PRIME_GROUP_BATCH = 250;
  private const LEGACY_READ_LIMIT = 250;

  /** @var array<int,string> */
  private static array $language_cache = [];

  /** @var array<int,bool> */
  private static array $language_assignment_cache = [];

  /** @var array<int,string> */
  private static array $group_cache = [];

  /** @var array<string,array<int,int>> */
  private static array $group_members_cache = [];

  /** @var array<string,array<string,int>> */
  private static array $group_language_cache = [];

  /** @var array<string,bool> Legacy relation projections exceeding a bounded read. */
  private static array $legacy_projection_overflow = [];

  /** Clear per-blog identity projections after WordPress switches sites. */
  public static function reset_request_cache(): void {
    self::$language_cache = [];
    self::$language_assignment_cache = [];
    self::$group_cache = [];
    self::$group_members_cache = [];
    self::$group_language_cache = [];
    self::$legacy_projection_overflow = [];
  }

  public static function init(): void {
    add_action('init', [__CLASS__, 'register_group_taxonomy'], 0);
    add_action('add_meta_boxes', [__CLASS__, 'register_metabox']);
    add_action('save_post', [__CLASS__, 'save_post'], 10, 2);

    if (!class_exists('Yablonsky\\Multilingual\\RuntimeAuthority') || RuntimeAuthority::ymulti_owns_public_runtime()) {
      add_action('pre_get_posts', [__CLASS__, 'filter_main_query'], 5);
      add_action('template_redirect', [__CLASS__, 'ensure_correct_language_on_singular'], 1);
    }
  }

  public static function register_group_taxonomy(): void {
    register_taxonomy(self::GROUP_TAX, Languages::supported_post_types(), [
      'labels' => [
        'name' => __('YMulti Translation Groups', 'yablonsky-multilingual'),
        'singular_name' => __('YMulti Translation Group', 'yablonsky-multilingual'),
      ],
      'public' => false,
      'show_ui' => false,
      'show_in_nav_menus' => false,
      'show_admin_column' => false,
      'rewrite' => false,
      'hierarchical' => false,
      'query_var' => false,
    ]);
  }

  public static function group_id(int $post_id): string {
    if (isset(self::$group_cache[$post_id])) return self::$group_cache[$post_id];

    $groups = wp_get_object_terms($post_id, self::GROUP_TAX, ['fields' => 'slugs']);
    if (!is_wp_error($groups) && !empty($groups)) {
      $group = (string) $groups[0];
      self::$group_cache[$post_id] = $group;
      return $group;
    }

    // Read-only legacy pre-0.5.4 projection. Creating the taxonomy term or
    // assigning a post is an authorized migration write, never a lookup effect.
    $legacy = sanitize_title((string) get_post_meta($post_id, self::META_GROUP, true));
    if ($legacy !== '') {
      self::$group_cache[$post_id] = $legacy;
      return $legacy;
    }

    self::$group_cache[$post_id] = '';
    return '';
  }

  public static function ensure_group_id(int $post_id): string {
    $group = self::group_id($post_id);
    if ($group !== '') return $group;

    $group = wp_generate_uuid4();
    self::set_group_id($post_id, $group);
    return $group;
  }

  public static function set_group_id(int $post_id, string $group_id): void {
    $group_id = sanitize_title($group_id);
    if ($post_id <= 0 || $group_id === '') return;

    // Do not mark a pre-0.5.4 family fully migrated merely because an
    // editor adds one new member. Detect remaining legacy meta before the
    // explicit write; expensive discovery is confined to approved edits.
    $existing_term = get_term_by('slug', $group_id, self::GROUP_TAX);
    $is_unmigrated = $existing_term instanceof \WP_Term
      ? (int) get_term_meta((int) $existing_term->term_id, self::GROUP_MATERIALIZED_META, true) !== 1
      : !empty(self::query_legacy_members($group_id, 1)->posts);
    self::assign_group_term($post_id, $group_id, !$is_unmigrated);

    // Preserve the family's workspace position when the first translation
    // materializes a former singleton into a stored translation group.
    // Subsequent translations must never replace this anchor.
    $group_term = get_term_by('slug', $group_id, self::GROUP_TAX);
    if ($group_term instanceof \WP_Term && (int) get_term_meta((int) $group_term->term_id, self::GROUP_ORDER_ANCHOR_META, true) <= 0) {
      update_term_meta((int) $group_term->term_id, self::GROUP_ORDER_ANCHOR_META, $post_id);
    }

    update_post_meta($post_id, self::META_GROUP, $group_id);
    self::$group_cache[$post_id] = $group_id;
    unset(self::$group_members_cache[$group_id], self::$group_language_cache[$group_id]);
    if (class_exists('Yablonsky\Multilingual\RuntimeAuthority', false)) RuntimeAuthority::reset();
    if (class_exists('Yablonsky\Multilingual\EffectiveRelations', false)) EffectiveRelations::reset();
    if (class_exists('Yablonsky\Multilingual\SiteCoverage', false)) SiteCoverage::reset();
  }

  /**
   * Return the explicitly assigned language or null when the object has no YPM
   * language term yet. Frontend fallback behavior remains in get_post_language().
   */
  public static function assigned_post_language(int $post_id): ?string {
    if (array_key_exists($post_id, self::$language_assignment_cache)) {
      return self::$language_assignment_cache[$post_id]
        ? (self::$language_cache[$post_id] ?? Languages::default_code())
        : null;
    }

    $terms = wp_get_object_terms($post_id, Languages::TAX, ['fields' => 'slugs']);
    if (is_wp_error($terms) || empty($terms)) {
      self::$language_assignment_cache[$post_id] = false;
      return null;
    }

    $code = self::language_code_from_slug((string) $terms[0]);
    self::$language_cache[$post_id] = $code;
    self::$language_assignment_cache[$post_id] = true;
    return $code;
  }

  /**
   * Prime only post-language assignments.
   *
   * This is intentionally projection-specific: callers that only need a
   * language must not pay the cost of expanding complete translation groups.
   *
   * @param int[] $post_ids
   */
  public static function prime_languages(array $post_ids): void {
    $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
    if (!$post_ids) return;

    $missing = [];
    foreach ($post_ids as $post_id) {
      if (!array_key_exists($post_id, self::$language_assignment_cache)) {
        $missing[] = $post_id;
      }
    }
    if (!$missing) return;

    foreach (array_chunk($missing, self::PRIME_OBJECT_BATCH) as $batch) {
      $terms = wp_get_object_terms($batch, Languages::TAX, ['fields' => 'all_with_object_id']);
      if (is_wp_error($terms)) continue;

      $assigned = [];
      foreach ($terms as $term) {
        if (!isset($term->object_id, $term->slug)) continue;
        $post_id = (int) $term->object_id;
        $code = self::language_code_from_slug((string) $term->slug);
        self::$language_cache[$post_id] = $code;
        self::$language_assignment_cache[$post_id] = true;
        $assigned[$post_id] = true;
      }

      foreach ($batch as $post_id) {
        if (!isset($assigned[$post_id])) {
          self::$language_assignment_cache[$post_id] = false;
        }
      }
    }
  }

  /**
   * Prime translation-family state for a bounded set of posts.
   *
   * Group expansion is reserved for callers that explicitly need translation
   * families. This keeps language-only hot paths from over-hydrating relations.
   *
   * @param int[] $post_ids
   */
  public static function prime_translation_families(array $post_ids): void {
    $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
    if (!$post_ids) return;

    self::prime_languages($post_ids);

    // SEO/bulk callers may ask for the same page of families more than once
    // inside a request (e.g. language graph, then sitemap alternates). Avoid
    // repeating taxonomy relation queries when every requested projection is
    // already fully primed. A cached group slug alone is not enough: legacy
    // meta-only groups and post-write invalidations must still be resolved.
    $fully_primed = true;
    foreach ($post_ids as $post_id) {
      if (!array_key_exists($post_id, self::$group_cache)) {
        $fully_primed = false;
        break;
      }
      $group = self::$group_cache[$post_id];
      if ($group !== '' && (!isset(self::$group_members_cache[$group]) || !isset(self::$group_language_cache[$group]))) {
        $fully_primed = false;
        break;
      }
    }
    if ($fully_primed) return;

    $group_ids = [];
    foreach (array_chunk($post_ids, self::PRIME_OBJECT_BATCH) as $batch) {
      $group_terms = wp_get_object_terms($batch, self::GROUP_TAX, ['fields' => 'all_with_object_id']);
      if (is_wp_error($group_terms)) continue;

      $assigned = [];
      foreach ($group_terms as $term) {
        if (!isset($term->object_id, $term->slug, $term->term_id)) continue;
        $post_id = (int) $term->object_id;
        $slug = (string) $term->slug;
        $term_id = (int) $term->term_id;
        self::$group_cache[$post_id] = $slug;
        $assigned[$post_id] = true;
        $group_ids[$term_id] = true;
      }

      // Mark ordinary ungrouped posts in the request cache as well. Without
      // this, a bulk consumer would fall back to one wp_get_object_terms()
      // call per singleton after the bounded prime. Preserve the legacy meta
      // bridge so old translation groups can still materialize on demand.
      if (function_exists('update_meta_cache')) update_meta_cache('post', $batch);
      foreach ($batch as $post_id) {
        $post_id = (int) $post_id;
        if (isset($assigned[$post_id])) continue;
        $legacy = sanitize_title((string) get_post_meta($post_id, self::META_GROUP, true));
        self::$group_cache[$post_id] = $legacy;
      }
    }
    if (!$group_ids) return;

    $member_ids = [];
    foreach (array_chunk(array_keys($group_ids), self::PRIME_GROUP_BATCH) as $group_batch) {
      $ids = get_objects_in_term($group_batch, self::GROUP_TAX);
      if (is_wp_error($ids) || !$ids) continue;
      foreach ($ids as $post_id) $member_ids[(int) $post_id] = true;
    }
    if (!$member_ids) return;
    $member_ids = array_keys($member_ids);

    $members_by_group = [];
    foreach (array_chunk($member_ids, self::PRIME_OBJECT_BATCH) as $batch) {
      $member_group_terms = wp_get_object_terms($batch, self::GROUP_TAX, ['fields' => 'all_with_object_id']);
      if (is_wp_error($member_group_terms)) continue;

      foreach ($member_group_terms as $term) {
        if (!isset($term->object_id, $term->slug)) continue;
        $post_id = (int) $term->object_id;
        $slug = (string) $term->slug;
        self::$group_cache[$post_id] = $slug;
        $members_by_group[$slug][] = $post_id;
      }
    }

    self::prime_languages($member_ids);
    $default = Languages::default_code();
    foreach ($members_by_group as $slug => $ids) {
      $ids = array_values(array_unique(array_map('intval', $ids)));
      self::$group_members_cache[$slug] = $ids;
      $by_language = [];
      foreach ($ids as $post_id) {
        $code = self::$language_cache[$post_id] ?? $default;
        if (!isset($by_language[$code])) $by_language[$code] = $post_id;
      }
      self::$group_language_cache[$slug] = $by_language;
    }
  }

  /**
   * Backward-compatible full priming used by existing admin callers.
   *
   * @param int[] $post_ids
   */
  public static function prime_posts(array $post_ids): void {
    self::prime_translation_families($post_ids);
  }

  public static function set_post_language(int $post_id, string $code): void {
    $slug = Languages::code_to_slug($code);
    $term = get_term_by('slug', $slug, Languages::TAX);
    if (!$term) {
      $name = Languages::catalog()[$code]['name'] ?? $code;
      $result = wp_insert_term($name, Languages::TAX, ['slug' => $slug]);
      if (is_wp_error($result)) return;
      $term_id = (int) $result['term_id'];
    } else {
      $term_id = (int) $term->term_id;
    }

    wp_set_object_terms($post_id, [$term_id], Languages::TAX, false);
    self::$language_cache[$post_id] = $code;
    self::$language_assignment_cache[$post_id] = true;

    $group_id = self::group_id($post_id);
    if ($group_id !== '') unset(self::$group_language_cache[$group_id]);
    if (class_exists('Yablonsky\Multilingual\RuntimeAuthority', false)) RuntimeAuthority::reset();
    if (class_exists('Yablonsky\Multilingual\EffectiveRelations', false)) EffectiveRelations::reset();
    if (class_exists('Yablonsky\Multilingual\SiteCoverage', false)) SiteCoverage::reset();
  }

  public static function get_post_language(int $post_id): string {
    if (isset(self::$language_cache[$post_id])) return self::$language_cache[$post_id];

    $terms = wp_get_object_terms($post_id, Languages::TAX, ['fields' => 'slugs']);
    if (is_wp_error($terms) || empty($terms)) {
      $default = Languages::default_code();
      self::$language_cache[$post_id] = $default;
      self::$language_assignment_cache[$post_id] = false;
      return $default;
    }

    $code = self::language_code_from_slug((string) $terms[0]);
    self::$language_cache[$post_id] = $code;
    self::$language_assignment_cache[$post_id] = true;
    return $code;
  }

  public static function translations_for_group(string $group_id): array {
    $group_id = sanitize_title($group_id);
    if ($group_id === '') return [];
    if (isset(self::$group_members_cache[$group_id])) return self::$group_members_cache[$group_id];

    // A frontend/read-only consumer must not create group terms, assign
    // relations, or advance the old pre-0.5.4 migration cursor. The native
    // taxonomy members and a bounded legacy meta projection are combined in
    // memory until an administrator explicitly materializes the legacy group.
    $term = get_term_by('slug', $group_id, self::GROUP_TAX);
    $term_id = $term instanceof \WP_Term ? (int) $term->term_id : 0;
    $ids = [];
    if ($term_id > 0) {
      $native_ids = get_objects_in_term($term_id, self::GROUP_TAX);
      if (!is_wp_error($native_ids)) $ids = (array) $native_ids;
    }

    // New groups are already materialized by explicit set_group_id() writes.
    // An old group may exist as metadata only, or be partly materialized.
    if ($term_id <= 0 || (int) get_term_meta($term_id, self::GROUP_MATERIALIZED_META, true) !== 1) {
      $query = self::query_legacy_members($group_id, self::LEGACY_READ_LIMIT + 1);
      $legacy_ids = array_values(array_filter(array_map('intval', (array) ($query->posts ?? []))));
      self::$legacy_projection_overflow[$group_id] = count($legacy_ids) > self::LEGACY_READ_LIMIT;
      $ids = array_merge($ids, array_slice($legacy_ids, 0, self::LEGACY_READ_LIMIT));
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    self::$group_members_cache[$group_id] = $ids;
    return $ids;
  }

  /** An oversized legacy-only family requires an approved migration for complete output. */
  public static function legacy_projection_overflow(string $group_id): bool {
    $group_id = sanitize_title($group_id);
    if (!array_key_exists($group_id, self::$legacy_projection_overflow)) {
      self::translations_for_group($group_id);
    }
    return (bool) (self::$legacy_projection_overflow[$group_id] ?? false);
  }

  /** @return \WP_Query Read-only legacy member scan, bounded by the caller. */
  private static function query_legacy_members(string $group_id, int $limit, int $page = 1): \WP_Query {
    return new \WP_Query([
      'post_type' => Languages::supported_post_types(),
      'post_status' => ['publish', 'draft', 'pending', 'private'],
      'posts_per_page' => max(1, min(251, $limit)),
      'paged' => max(1, $page),
      'orderby' => 'ID',
      'order' => 'ASC',
      'no_found_rows' => true,
      'fields' => 'ids',
      // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- Legacy identity scan must include every locale, even with WPML's content-query filters active.
      'suppress_filters' => true,
      // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded read of legacy migration data only.
      'meta_key' => self::META_GROUP,
      // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded read of legacy migration data only.
      'meta_value' => $group_id,
    ]);
  }

  /**
   * Resolve the complete group with one language-term query instead of one
   * wp_get_object_terms() call per object.
   *
   * @return array<string,int> language code => post ID
   */
  public static function translations_by_language_for_group(string $group_id): array {
    $group_id = sanitize_title($group_id);
    if ($group_id === '') return [];
    if (isset(self::$group_language_cache[$group_id])) return self::$group_language_cache[$group_id];

    $ids = self::translations_for_group($group_id);
    if (!$ids) {
      self::$group_language_cache[$group_id] = [];
      return [];
    }

    $by_object = [];
    $terms = wp_get_object_terms($ids, Languages::TAX, ['fields' => 'all_with_object_id']);
    if (!is_wp_error($terms)) {
      foreach ($terms as $term) {
        if (!isset($term->object_id, $term->slug)) continue;
        $by_object[(int) $term->object_id] = self::language_code_from_slug((string) $term->slug);
      }
    }

    $result = [];
    $default = Languages::default_code();
    foreach ($ids as $post_id) {
      $language = $by_object[$post_id] ?? $default;
      self::$language_cache[$post_id] = $language;
      if (!isset($result[$language])) $result[$language] = $post_id;
    }

    self::$group_language_cache[$group_id] = $result;
    return $result;
  }

  public static function get_translation(int $post_id, string $lang_code): ?int {
    $group = self::group_id($post_id);
    if ($group === '') return null;

    $by_language = self::translations_by_language_for_group($group);
    if (!isset($by_language[$lang_code])) return null;

    $target_id = (int) $by_language[$lang_code];
    return $target_id === $post_id ? null : $target_id;
  }

  public static function register_metabox(): void {
    foreach (Languages::supported_post_types() as $post_type) {
      add_meta_box(
        'ypm_translations',
        __('YMulti: Language & Translations', 'yablonsky-multilingual'),
        [__CLASS__, 'render_metabox'],
        $post_type,
        'side',
        'default'
      );
    }
  }

  public static function render_metabox(\WP_Post $post): void {
    wp_nonce_field('ypm_save_post', 'ypm_nonce');

    $assigned_lang = self::assigned_post_language((int) $post->ID);
    $current_lang = $assigned_lang ?: Languages::default_code();
    $group = self::group_id((int) $post->ID);
    $active = Languages::active_codes();
    $catalog = Languages::catalog();

    echo '<p style="margin:0 0 6px"><strong>' . esc_html__('Content language', 'yablonsky-multilingual') . '</strong></p>';
    echo '<select name="ypm_lang_code" style="width:100%">';
    foreach ($active as $code) {
      $label = ($catalog[$code]['name'] ?? $code) . ' (' . Languages::display_code($code) . ')';
      echo '<option value="' . esc_attr($code) . '"' . selected($current_lang, $code, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';

    if ($assigned_lang === null) {
      echo '<p style="margin:6px 0 0;color:#8c8f94;font-size:14px">' . esc_html__('Choose a locale; saving assigns that language identity to this content.', 'yablonsky-multilingual') . '</p>';
    }

    echo '<p style="margin:14px 0 6px"><strong>' . esc_html__('Translation family', 'yablonsky-multilingual') . '</strong></p>';

    $native_by_lang = $group !== ''
      ? self::translations_by_language_for_group($group)
      : ($assigned_lang !== null ? [$assigned_lang => (int) $post->ID] : []);
    $effective_family = class_exists('Yablonsky\Multilingual\EffectiveRelations', false)
      ? EffectiveRelations::post_family((int) $post->ID, $native_by_lang, $active)
      : ['translations' => ($native_by_lang ?: [$current_lang => (int) $post->ID])];
    $by_lang = is_array($effective_family['translations'] ?? null) ? $effective_family['translations'] : [];

    $visible_codes = $active;
    if (count($active) > 5) {
      $visible_codes = [];
      $append = static function(string $code) use (&$visible_codes): void {
        if ($code !== '' && !in_array($code, $visible_codes, true)) $visible_codes[] = $code;
      };
      $append($current_lang);
      foreach ($active as $code) {
        if (isset($by_lang[$code])) $append($code);
        if (count($visible_codes) >= 4) break;
      }
      foreach ($active as $code) {
        $append($code);
        if (count($visible_codes) >= 4) break;
      }
      $visible_codes = array_slice($visible_codes, 0, 4);
    }

    $complete = 0;
    foreach ($active as $code) if (isset($by_lang[$code])) $complete++;

    echo '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;align-items:center">';
    if (class_exists('Yablonsky\\Multilingual\\AdminContent')) {
      echo '<a class="ypm-family-more" data-post-id="' . esc_attr((string) $post->ID) . '" href="' . esc_url(AdminContent::family_detail_url((int) $post->ID)) . '" style="display:inline-flex;padding:4px 8px;border-radius:999px;background:#f0f0f1;color:#50575e;text-decoration:none;font-weight:700;font-size:14px" title="' . esc_attr__('Open full translation family', 'yablonsky-multilingual') . '">' . esc_html((string) $complete) . '/' . esc_html((string) count($active)) . '</a>';
    }

    foreach ($visible_codes as $code) {
      $name = $catalog[$code]['name'] ?? $code;
      $label = Languages::display_code($code);

      if (isset($by_lang[$code])) {
        $post_id = (int) $by_lang[$code];
        $is_current = $post_id === (int) $post->ID;
        $edit = get_edit_post_link($post_id, '');
        $style = $is_current
          ? 'display:inline-flex;padding:4px 8px;border-radius:999px;background:#2271b1;color:#fff;text-decoration:none;font-weight:700;font-size:14px'
          : 'display:inline-flex;padding:4px 8px;border:1px solid #c3c4c7;border-radius:999px;background:#fff;color:#1d2327;text-decoration:none;font-weight:700;font-size:14px';
        if ($edit && !$is_current) {
          /* translators: %s: locale or language display name. */
          $edit_title = sprintf(__('Edit %s translation', 'yablonsky-multilingual'), $name);
          echo '<a href="' . esc_url($edit) . '" style="' . esc_attr($style) . '" title="' . esc_attr($edit_title) . '">' . esc_html($label) . '</a>';
        } else {
          echo '<span style="' . esc_attr($style) . '" title="' . esc_attr($name) . '">' . esc_html($label) . '</span>';
        }
        continue;
      }

      if (!Languages::is_active($code)) continue;
      $create_url = wp_nonce_url(
        add_query_arg([
          'post_type' => $post->post_type,
          'ypm_action' => 'create_translation',
          'source' => $post->ID,
          'lang' => $code,
        ], admin_url('post-new.php')),
        'ypm_create_translation'
      );
      /* translators: %s: locale or language display name. */
      $create_title = sprintf(__('Create %s translation', 'yablonsky-multilingual'), $name);
      echo '<a href="' . esc_url($create_url) . '" style="display:inline-flex;padding:4px 8px;border:1px dashed #a7aaad;border-radius:999px;background:#f6f7f7;color:#50575e;text-decoration:none;font-weight:700;font-size:14px" title="' . esc_attr($create_title) . '">+ ' . esc_html($label) . '</a>';
    }

    $hidden = max(0, count($active) - count(array_intersect($visible_codes, $active)));
    if ($hidden > 0 && class_exists('Yablonsky\\Multilingual\\AdminContent')) {
      echo '<a class="ypm-family-more" data-post-id="' . esc_attr((string) $post->ID) . '" href="' . esc_url(AdminContent::family_detail_url((int) $post->ID)) . '" style="display:inline-flex;padding:4px 8px;border:1px solid #72aee6;border-radius:999px;background:#f0f6fc;color:#135e96;text-decoration:none;font-weight:700;font-size:14px">+' . esc_html((string) $hidden) . '</a>';
    }
    echo '</div>';

    echo '<p style="margin:8px 0 0;color:#646970;font-size:14px">' . wp_kses_post(__('Use the translated title exactly as readers should see it. YMulti stores language identity separately from the title.', 'yablonsky-multilingual')) . '</p>';
  }

  public static function save_post(int $post_id, \WP_Post $post): void {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    $nonce = isset($_POST['ypm_nonce']) ? sanitize_text_field(wp_unslash($_POST['ypm_nonce'])) : '';
    if ($nonce === '' || !wp_verify_nonce($nonce, 'ypm_save_post')) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $group = self::ensure_group_id($post_id);

    $code = isset($_POST['ypm_lang_code']) ? sanitize_text_field(wp_unslash($_POST['ypm_lang_code'])) : '';
    if ($code && Languages::is_active($code)) {
      self::set_post_language($post_id, $code);
    } else {
      self::set_post_language($post_id, Languages::default_code());
    }

    // Keep legacy meta as a rollback/export mirror during the pre-1.0 transition.
    if ($group) update_post_meta($post_id, self::META_GROUP, $group);
  }

  public static function filter_main_query(\WP_Query $query): void {
    if (is_admin()) return;
    if (!$query->is_main_query()) return;

    if (!($query->is_home() || $query->is_archive() || $query->is_search() || $query->is_post_type_archive() || $query->is_tax() || $query->is_category() || $query->is_tag())) {
      return;
    }

    $lang = Router::current_lang();
    $slug = Languages::code_to_slug($lang);

    $tax_query = (array) $query->get('tax_query');
    $tax_query[] = [
      'taxonomy' => Languages::TAX,
      'field' => 'slug',
      'terms' => [$slug],
      'operator' => 'IN',
      'include_children' => false,
    ];
    $query->set('tax_query', $tax_query);
  }

  public static function ensure_correct_language_on_singular(): void {
    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) return;
    if (!is_singular()) return;

    $post = get_queried_object();
    if (!($post instanceof \WP_Post)) return;

    $requested_lang = Router::current_lang();
    $actual_lang = self::get_post_language((int) $post->ID);

    if ($requested_lang === $actual_lang) return;

    $target_id = self::get_translation((int) $post->ID, $requested_lang);
    if (!$target_id) {
      $target_id = (int) $post->ID;
      $requested_lang = $actual_lang;
    }

    $target = Urls::for_post($target_id, $requested_lang);
    if ($target !== '') {
      wp_safe_redirect($target, 302, 'YMulti');
      exit;
    }
  }

  private static function language_code_from_slug(string $slug): string {
    foreach (Languages::catalog() as $code => $row) {
      if (($row['slug'] ?? '') === $slug) return (string) $code;
    }
    return Languages::default_code();
  }

  private static function ensure_group_term(string $group_id): int {
    $term = get_term_by('slug', $group_id, self::GROUP_TAX);
    if ($term instanceof \WP_Term) return (int) $term->term_id;

    $result = wp_insert_term($group_id, self::GROUP_TAX, ['slug' => $group_id]);
    if (is_wp_error($result)) {
      $existing = term_exists($group_id, self::GROUP_TAX);
      if (is_array($existing) && isset($existing['term_id'])) return (int) $existing['term_id'];
      if (is_int($existing)) return $existing;
      return 0;
    }

    return (int) $result['term_id'];
  }

  private static function assign_group_term(int $post_id, string $group_id, bool $mark_materialized): void {
    $term_id = self::ensure_group_term($group_id);
    if ($term_id <= 0) return;

    wp_set_object_terms($post_id, [$term_id], self::GROUP_TAX, false);
    if ($mark_materialized) update_term_meta($term_id, self::GROUP_MATERIALIZED_META, 1);
  }

  /**
   * Read-only administrator preview for a single legacy family batch. The
   * reported size is the bounded page, not a misleading whole-site count.
   * @return array{valid:bool,complete:bool,page:int,ready:int,conflicts?:int}
   */
  public static function legacy_group_migration_preview(string $group_id): array {
    $group_id = sanitize_title($group_id);
    if ($group_id === '') return ['valid' => false, 'complete' => false, 'page' => 1, 'ready' => 0];
    $term = get_term_by('slug', $group_id, self::GROUP_TAX);
    $term_id = $term instanceof \WP_Term ? (int) $term->term_id : 0;
    $page = $term_id > 0 ? max(1, (int) get_term_meta($term_id, self::GROUP_MATERIALIZATION_PAGE_META, true)) : 1;
    if ($term_id > 0 && (int) get_term_meta($term_id, self::GROUP_MATERIALIZED_META, true) === 1) {
      return ['valid' => true, 'complete' => true, 'page' => $page, 'ready' => 0];
    }
    $query = self::query_legacy_members($group_id, self::LEGACY_READ_LIMIT, $page);
    $legacy_ids = array_values(array_filter(array_map('intval', (array) ($query->posts ?? []))));
    $ready = count($legacy_ids);
    // A review should surface regrouped posts before the administrator presses
    // Import. The write path repeats this guard because the source can change
    // between preview and submit.
    $conflicts = 0;
    if ($legacy_ids) {
      $terms = wp_get_object_terms($legacy_ids, self::GROUP_TAX, ['fields' => 'all_with_object_id']);
      if (is_wp_error($terms)) return ['valid' => false, 'complete' => false, 'page' => $page, 'ready' => $ready, 'conflicts' => 0];
      foreach ($terms as $term) {
        if (isset($term->slug) && (string) $term->slug !== $group_id) $conflicts++;
      }
    }
    return ['valid' => $term_id > 0 || $ready > 0, 'complete' => false, 'page' => $page, 'ready' => $ready, 'conflicts' => $conflicts];
  }

  /**
   * Explicit bounded repair of one pre-0.5.4 family, never called by reads.
   * Invoked only from the capability- and nonce-guarded administrator action.
   * @return array{assigned:int,complete:bool,valid:bool}
   */
  public static function materialize_legacy_group_batch(string $group_id): array {
    if (!is_admin() || !current_user_can('manage_options')) return ['assigned' => 0, 'complete' => false, 'valid' => false];
    $group_id = sanitize_title($group_id);
    if ($group_id === '') return ['assigned' => 0, 'complete' => false, 'valid' => false];
    // Reject arbitrary non-existent slugs before creating any taxonomy term.
    // The caller must first preview an actual legacy family.
    $preview = self::legacy_group_migration_preview($group_id);
    if (!$preview['valid'] || !empty($preview['conflicts'])) return ['assigned' => 0, 'complete' => false, 'valid' => false];
    $term_id = self::ensure_group_term($group_id);
    if ($term_id <= 0) return ['assigned' => 0, 'complete' => false, 'valid' => false];
    return self::materialize_legacy_group_once($group_id, $term_id);
  }

  /** @return array{assigned:int,complete:bool,valid:bool} */
  private static function materialize_legacy_group_once(string $group_id, int $term_id): array {
    if ((int) get_term_meta($term_id, self::GROUP_MATERIALIZED_META, true) === 1) return ['assigned' => 0, 'complete' => true, 'valid' => true];

    // Admin-approved, bounded writes only. Preserve the existing page cursor
    // and the legacy meta mirror for rollback/export compatibility.
    $page = max(1, (int) get_term_meta($term_id, self::GROUP_MATERIALIZATION_PAGE_META, true));
    $query = self::query_legacy_members($group_id, self::LEGACY_READ_LIMIT, $page);
    $legacy_ids = array_values(array_filter(array_map('intval', (array) ($query->posts ?? []))));
    // A legacy meta mirror is never permission to replace a different native
    // family. Read all native assignments in one bounded taxonomy operation,
    // and reject the entire batch before its first write if any conflict exists.
    // This protects an editor's newer regrouping from an old metadata value.
    $native_by_post = [];
    if ($legacy_ids) {
      $native_terms = wp_get_object_terms($legacy_ids, self::GROUP_TAX, ['fields' => 'all_with_object_id']);
      if (is_wp_error($native_terms)) return ['assigned' => 0, 'complete' => false, 'valid' => false];
      foreach ($native_terms as $native_term) {
        if (!isset($native_term->object_id, $native_term->slug)) continue;
        $native_by_post[(int) $native_term->object_id][] = (string) $native_term->slug;
      }
      foreach ($legacy_ids as $legacy_post_id) {
        foreach ($native_by_post[$legacy_post_id] ?? [] as $native_slug) {
          if ($native_slug !== $group_id) {
            return ['assigned' => 0, 'complete' => false, 'valid' => false];
          }
        }
      }
    }
    $assigned = 0;
    foreach ($legacy_ids as $legacy_post_id) {
      // Already migrated in an earlier retry or by an explicit edit.
      if (in_array($group_id, $native_by_post[$legacy_post_id] ?? [], true)) {
        self::$group_cache[$legacy_post_id] = $group_id;
        continue;
      }
      $result = wp_set_object_terms($legacy_post_id, [$term_id], self::GROUP_TAX, false);
      if (is_wp_error($result)) {
        // Retain the cursor on partial failure. Previously applied assignments
        // are idempotent and will be retried; never claim a partial family is
        // complete or silently skip a post on the next admin-approved batch.
        unset(self::$group_members_cache[$group_id], self::$group_language_cache[$group_id], self::$legacy_projection_overflow[$group_id]);
        return ['assigned' => $assigned, 'complete' => false, 'valid' => false];
      }
      self::$group_cache[$legacy_post_id] = $group_id;
      $assigned++;
    }
    $complete = count($legacy_ids) < self::LEGACY_READ_LIMIT;
    if ($complete) {
      update_term_meta($term_id, self::GROUP_MATERIALIZED_META, 1);
      delete_term_meta($term_id, self::GROUP_MATERIALIZATION_PAGE_META);
    } else {
      update_term_meta($term_id, self::GROUP_MATERIALIZATION_PAGE_META, $page + 1);
    }
    unset(self::$group_members_cache[$group_id], self::$group_language_cache[$group_id], self::$legacy_projection_overflow[$group_id]);
    if (class_exists(EffectiveRelations::class, false)) EffectiveRelations::reset();
    return ['assigned' => $assigned, 'complete' => $complete, 'valid' => true];
  }
}
