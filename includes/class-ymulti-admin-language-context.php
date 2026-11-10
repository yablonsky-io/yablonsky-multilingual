<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Per-user WordPress admin language context.
 *
 * This is an editing/navigation preference only. It never changes the public
 * request locale, site default locale or stored content identity.
 */
final class AdminLanguageContext {
  private const META_KEY = '_ymulti_admin_language_context_v1';
  private const ACTION = 'ymulti_set_admin_language_context';

  public static function init(): void {
    if (!is_admin()) return;
    add_action('admin_bar_menu', [__CLASS__, 'render_toolbar'], 72);
    add_action('admin_post_' . self::ACTION, [__CLASS__, 'handle_switch']);
  }

  public static function current(): string {
    $user_id = get_current_user_id();
    if ($user_id <= 0) return '';
    $stored = sanitize_text_field((string) get_user_meta($user_id, self::META_KEY, true));
    return Languages::is_active($stored) ? $stored : '';
  }

  public static function preferred_target(): string {
    $current = self::current();
    return $current !== '' && $current !== Languages::default_code() ? $current : '';
  }

  public static function render_toolbar(\WP_Admin_Bar $bar): void {
    if (!is_admin() || !is_user_logged_in() || !current_user_can('edit_posts')) return;
    $active = Languages::active_codes();
    if (!$active) return;

    $current = self::current();
    $title = $current !== '' ? sprintf(
      /* translators: %s: current admin editing locale code. */
      __('Language · %s', 'yablonsky-multilingual'),
      Languages::display_code($current)
    ) : __('Language · All', 'yablonsky-multilingual');

    $bar->add_node([
      'id' => 'ymulti-admin-language',
      'title' => esc_html($title),
      'href' => self::switch_url(''),
      'meta' => [
        'class' => 'ymulti-admin-language-context',
        'title' => __('YMulti admin editing language', 'yablonsky-multilingual'),
      ],
    ]);

    $bar->add_node([
      'id' => 'ymulti-admin-language-all',
      'parent' => 'ymulti-admin-language',
      'title' => ($current === '' ? '✓ ' : '') . esc_html__('All languages', 'yablonsky-multilingual'),
      'href' => self::switch_url(''),
    ]);

    foreach ($active as $code) {
      $bar->add_node([
        'id' => 'ymulti-admin-language-' . sanitize_html_class(strtolower($code)),
        'parent' => 'ymulti-admin-language',
        'title' => ($current === $code ? '✓ ' : '') . esc_html(Languages::display_name($code) . ' · ' . Languages::display_code($code)),
        'href' => self::switch_url($code),
      ]);
    }
  }

  private static function switch_url(string $locale): string {
    $args = [
      'action' => self::ACTION,
      'locale' => $locale,
      'screen' => self::current_screen_key(),
    ];
    $object_id = self::current_object_id();
    if ($object_id > 0) $args['object_id'] = $object_id;
    return wp_nonce_url(add_query_arg($args, admin_url('admin-post.php')), self::ACTION . '|' . $locale);
  }

  private static function current_screen_key(): string {
    $page_raw = filter_input(INPUT_GET, 'page', FILTER_UNSAFE_RAW);
    $page = is_string($page_raw) ? sanitize_key($page_raw) : '';
    if ($page !== '') return 'page:' . $page;

    global $pagenow;
    $screen = is_string($pagenow) ? sanitize_key($pagenow) : 'index.php';
    $post_type_raw = filter_input(INPUT_GET, 'post_type', FILTER_UNSAFE_RAW);
    $post_type = is_string($post_type_raw) ? sanitize_key($post_type_raw) : '';
    return $screen . ($post_type !== '' ? '|post_type:' . $post_type : '');
  }

  private static function current_object_id(): int {
    $post_raw = filter_input(INPUT_GET, 'post', FILTER_VALIDATE_INT);
    if ($post_raw) return (int) $post_raw;
    global $post;
    return $post instanceof \WP_Post ? (int) $post->ID : 0;
  }

  public static function handle_switch(): void {
    if (!is_user_logged_in() || !current_user_can('edit_posts')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    $locale_raw = filter_input(INPUT_GET, 'locale', FILTER_UNSAFE_RAW);
    $screen_raw = filter_input(INPUT_GET, 'screen', FILTER_UNSAFE_RAW);
    $object_raw = filter_input(INPUT_GET, 'object_id', FILTER_VALIDATE_INT);
    $locale = is_string($locale_raw) ? sanitize_text_field($locale_raw) : '';
    $screen = is_string($screen_raw) ? sanitize_text_field($screen_raw) : '';
    $object_id = $object_raw ? (int) $object_raw : 0;
    check_admin_referer(self::ACTION . '|' . $locale);

    if ($locale !== '' && !Languages::is_active($locale)) wp_die(esc_html__('Invalid language context.', 'yablonsky-multilingual'));
    if ($locale === '') delete_user_meta(get_current_user_id(), self::META_KEY);
    else update_user_meta(get_current_user_id(), self::META_KEY, $locale);

    $redirect = self::screen_url($screen, $locale, $object_id);
    wp_safe_redirect($redirect);
    exit;
  }

  private static function screen_url(string $screen, string $locale, int $object_id): string {
    if ($object_id > 0 && get_post($object_id) instanceof \WP_Post && in_array(get_post_type($object_id), Languages::supported_post_types(), true)) {
      if ($locale !== '') {
        $family = EffectiveRelations::post_family($object_id);
        $translations = is_array($family['translations'] ?? null) ? $family['translations'] : [];
        if (isset($translations[$locale])) {
          $edit = get_edit_post_link((int) $translations[$locale], '');
          if (is_string($edit) && $edit !== '') return $edit;
        }
        $source_post = get_post($object_id);
        $source_language = sanitize_text_field((string) ($family['source'] ?? RuntimeAuthority::effective_post_language($object_id)));
        if ($source_post instanceof \WP_Post && $locale !== $source_language) {
          return AdminContent::create_translation_url($source_post, $locale);
        }
      }
      $edit = get_edit_post_link($object_id, '');
      if (is_string($edit) && $edit !== '') return $edit;
    }

    if (strpos($screen, 'page:') === 0) {
      $page = sanitize_key(substr($screen, 5));
      if ($page !== '') return admin_url('admin.php?page=' . rawurlencode($page));
    }

    if (preg_match('/^edit\.php(?:\|post_type:([a-z0-9_-]+))?$/', $screen, $matches)) {
      $url = admin_url('edit.php');
      if (!empty($matches[1])) $url = add_query_arg('post_type', sanitize_key($matches[1]), $url);
      return $url;
    }

    return admin_url();
  }
}
