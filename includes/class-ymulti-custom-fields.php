<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Native custom-field translation policies.
 *
 * `copy` stays synchronized from the default-language source, `copy_once` seeds
 * a new translation draft once, and `independent` leaves the target field fully
 * language-specific for the translator/editor. Protected `_` keys are never
 * auto-discovered, avoiding accidental ownership of WordPress/plugin internals.
 */
final class CustomFields {
  public const OPT_POLICIES = 'ymulti_custom_field_policies_v1';
  private static bool $syncing = false;

  public static function init(): void {
    add_action('yablonsky_multilingual_translation_draft_initialized', [__CLASS__, 'initialize_draft'], 15, 3);
    add_action('save_post', [__CLASS__, 'sync_source'], 130, 3);
    if (is_admin()) {
      add_action('admin_menu', [__CLASS__, 'menu'], 32);
      add_action('admin_post_ymulti_custom_fields_save', [__CLASS__, 'save']);
    }
  }

  public static function menu(): void {
    add_submenu_page('ypm-multilingual', __('Custom Fields', 'yablonsky-multilingual'), __('Custom Fields', 'yablonsky-multilingual'), 'manage_options', 'ypm-custom-fields', [__CLASS__, 'page']);
  }

  /** Only ordinary public custom fields may be synchronized. */
  public static function is_public_meta_key(string $key): bool {
    $key = sanitize_key($key);
    return $key !== '' && $key[0] !== '_' && strpos($key, 'wp_') !== 0 && strpos($key, 'ypm_') !== 0 && strpos($key, 'ymulti_') !== 0;
  }

  /** @return array<string,string> */
  public static function policies(): array {
    $raw = get_option(self::OPT_POLICIES, []);
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $key => $policy) {
      $key = sanitize_key((string) $key); $policy = sanitize_key((string) $policy);
      if (self::is_public_meta_key($key) && in_array($policy, ['copy','copy_once','independent','ignore'], true)) $out[$key] = $policy;
    }
    return $out;
  }

  /** @return string[] */
  public static function discovered_keys(): array {
    global $wpdb;
    $types = Languages::supported_post_types();
    if (!$types) return [];
    $type_ph = implode(',', array_fill(0, count($types), '%s'));
    $sql = 'SELECT DISTINCT pm.meta_key FROM ' . $wpdb->postmeta . ' pm INNER JOIN ' . $wpdb->posts . ' p ON p.ID=pm.post_id WHERE p.post_type IN (' . $type_ph . ") AND pm.meta_key NOT LIKE '\\_%' ORDER BY pm.meta_key ASC LIMIT 300";
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Admin-only bounded discovery; post types are prepared values and table names are WordPress-owned.
    $keys = $wpdb->get_col($wpdb->prepare($sql, ...$types));
    $keys = is_array($keys) ? array_values(array_unique(array_filter(array_map('sanitize_key', $keys)))) : [];
    return array_values(array_filter($keys, static fn(string $key): bool => self::is_public_meta_key($key)));
  }

  public static function initialize_draft(int $source_id, int $target_id, string $origin = 'manual'): void {
    unset($origin);
    if ($source_id <= 0 || $target_id <= 0 || self::$syncing) return;
    foreach (self::policies() as $key => $policy) {
      if (!in_array($policy, ['copy','copy_once'], true)) continue;
      $values = get_post_meta($source_id, $key, false);
      if (!$values) continue;
      self::$syncing = true;
      delete_post_meta($target_id, $key);
      foreach ($values as $value) add_post_meta($target_id, $key, maybe_unserialize($value));
      self::$syncing = false;
    }
  }

  public static function sync_source(int $post_id, \WP_Post $post, bool $update): void {
    unset($update);
    if (self::$syncing || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
    if (!in_array((string) $post->post_type, Languages::supported_post_types(), true)) return;
    if (Translations::get_post_language($post_id) !== Languages::default_code()) return;
    $copy_keys = array_keys(array_filter(self::policies(), static fn(string $p): bool => $p === 'copy'));
    if (!$copy_keys) return;
    $group = Translations::group_id($post_id); if ($group === '') return;
    $members = Translations::translations_by_language_for_group($group);
    foreach ($members as $member_id) {
      $member_id = (int) $member_id; if ($member_id <= 0 || $member_id === $post_id) continue;
      foreach ($copy_keys as $key) {
        $values = get_post_meta($post_id, $key, false);
        self::$syncing = true;
        delete_post_meta($member_id, $key);
        foreach ($values as $value) add_post_meta($member_id, $key, maybe_unserialize($value));
        self::$syncing = false;
      }
    }
  }

  public static function page(): void {
    if (!current_user_can('manage_options')) return;
    $keys = array_values(array_unique(array_merge(self::discovered_keys(), array_keys(self::policies())))); sort($keys, SORT_NATURAL | SORT_FLAG_CASE);
    $policies = self::policies();
    echo '<div class="wrap ypm-admin">';
    Admin::render_shell_header(__('Custom Fields', 'yablonsky-multilingual'), __('Choose explicit multilingual behavior for public custom fields while protected WordPress and plugin-internal metadata keep their native authority.', 'yablonsky-multilingual'), 'neutral', __('Native metadata', 'yablonsky-multilingual'));
    echo '<section class="ypm-card ypm-card--standalone"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Field policies', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Copy, seed or translate independently', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('Copy keeps a field synchronized from the default-language source. Copy once seeds new drafts but stops syncing. Independent leaves each language free to store its own value. This works with ordinary post meta and common ACF-style public field keys.', 'yablonsky-multilingual') . '</p></div></div>';
    if (!$keys) { echo '<div class="ypm-empty-panel"><strong>' . esc_html__('Public custom fields: 0 discovered', 'yablonsky-multilingual') . '</strong><p>' . esc_html__('Protected underscore-prefixed metadata is intentionally excluded from automatic discovery.', 'yablonsky-multilingual') . '</p></div></section></div>'; return; }
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('ymulti_custom_fields_save', 'ymulti_custom_fields_nonce');
    echo '<input type="hidden" name="action" value="ymulti_custom_fields_save"><div class="ypm-table-wrap"><table class="widefat"><thead><tr><th>' . esc_html__('Meta key', 'yablonsky-multilingual') . '</th><th>' . esc_html__('Multilingual policy', 'yablonsky-multilingual') . '</th><th>' . esc_html__('Behavior', 'yablonsky-multilingual') . '</th></tr></thead><tbody>';
    $labels=['ignore'=>__('Ignore', 'yablonsky-multilingual'),'copy'=>__('Copy & keep synchronized', 'yablonsky-multilingual'),'copy_once'=>__('Copy once to new translations', 'yablonsky-multilingual'),'independent'=>__('Independent per language', 'yablonsky-multilingual')];
    foreach ($keys as $key) { $current=$policies[$key]??'ignore'; echo '<tr><td><code>' . esc_html($key) . '</code></td><td><select name="policies[' . esc_attr($key) . ']">'; foreach($labels as $value=>$label) echo '<option value="' . esc_attr($value) . '"' . selected($current,$value,false) . '>' . esc_html($label) . '</option>'; echo '</select></td><td>' . esc_html($current==='copy'?__('Source changes propagate to existing translations.', 'yablonsky-multilingual'):($current==='copy_once'?__('Only the initial draft receives the source value.', 'yablonsky-multilingual'):($current==='independent'?__('Editors manage a separate native value in each translated post.', 'yablonsky-multilingual'):__('YMulti leaves this field untouched.', 'yablonsky-multilingual')))) . '</td></tr>'; }
    echo '</tbody></table></div><p class="submit"><button class="button button-primary">' . esc_html__('Save field policies', 'yablonsky-multilingual') . '</button></p></form></section></div>';
  }

  public static function save(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ymulti_custom_fields_save', 'ymulti_custom_fields_nonce');
    $posted_raw = filter_input(INPUT_POST, 'policies', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
    $posted = is_array($posted_raw) ? map_deep(wp_unslash($posted_raw), 'sanitize_text_field') : [];
    $clean=[]; foreach($posted as $key=>$policy){$key=sanitize_key((string)$key);$policy=sanitize_key((string)$policy);if(self::is_public_meta_key($key)&&in_array($policy,['copy','copy_once','independent','ignore'],true))$clean[$key]=$policy;}
    update_option(self::OPT_POLICIES,$clean,false);
    wp_safe_redirect(admin_url('admin.php?page=ypm-custom-fields&saved=1')); exit;
  }
}
