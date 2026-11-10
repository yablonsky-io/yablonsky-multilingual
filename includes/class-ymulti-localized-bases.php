<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Locale-specific public URL bases without changing WordPress object identity. */
final class LocalizedBases {
  public const OPT = 'ymulti_localized_bases_v1';
  private static ?array $settings_cache = null;

  public static function reset_request_cache(): void {
    self::$settings_cache = null;
  }

  public static function init(): void {
    add_action('init', [__CLASS__, 'register_rewrites'], 30);
    add_filter('post_type_link', [__CLASS__, 'filter_post_type_link'], 30, 4);
    add_filter('post_type_archive_link', [__CLASS__, 'filter_archive_link'], 30, 2);
    add_filter('term_link', [__CLASS__, 'filter_term_link'], 30, 3);
    if (is_admin()) {
      add_action('admin_menu', [__CLASS__, 'menu'], 20);
      add_action('admin_post_ymulti_save_localized_bases', [__CLASS__, 'handle_save']);
    }
  }

  public static function menu(): void {
    add_submenu_page('ypm-multilingual', __('Routing', 'yablonsky-multilingual'), __('Routing', 'yablonsky-multilingual'), 'manage_options', 'ypm-routing', [__CLASS__, 'page']);
  }

  /** @return array<string,mixed> */
  public static function settings(): array {
    if (self::$settings_cache !== null) return self::$settings_cache;
    $raw = get_option(self::OPT, []);
    self::$settings_cache = is_array($raw) ? $raw : [];
    return self::$settings_cache;
  }

  public static function get(string $language, string $kind, string $name, string $fallback = ''): string {
    $all = self::settings();
    $value = $all[$language][$kind][$name] ?? '';
    $value = sanitize_title((string) $value);
    return $value !== '' ? $value : $fallback;
  }

  /** @return array<string,array{label:string,slug:string}> */
  public static function post_types(): array {
    $out = [];
    foreach (get_post_types(['public' => true], 'objects') as $name => $obj) {
      if (!$obj instanceof \WP_Post_Type || in_array($name, ['post','page','attachment'], true)) continue;
      if (empty($obj->rewrite) || !is_array($obj->rewrite)) continue;
      $slug = sanitize_title((string) ($obj->rewrite['slug'] ?? $name));
      if ($slug === '') continue;
      $out[$name] = ['label' => (string) $obj->labels->name, 'slug' => $slug];
    }
    return $out;
  }

  /** @return array<string,array{label:string,slug:string}> */
  public static function taxonomies(): array {
    $out = [];
    foreach (get_taxonomies(['public' => true], 'objects') as $name => $obj) {
      if (!$obj instanceof \WP_Taxonomy || in_array($name, ['post_format'], true)) continue;
      if (empty($obj->rewrite) || !is_array($obj->rewrite)) continue;
      $slug = sanitize_title((string) ($obj->rewrite['slug'] ?? $name));
      if ($slug === '') continue;
      $out[$name] = ['label' => (string) $obj->labels->name, 'slug' => $slug];
    }
    return $out;
  }

  public static function register_rewrites(): void {
    if (class_exists('Yablonsky\\Multilingual\\RuntimeAuthority') && !RuntimeAuthority::ymulti_owns_public_runtime()) return;
    if (class_exists('Yablonsky\\Multilingual\\SafeMode') && SafeMode::enabled()) return;
    $post_types = self::post_types();
    $taxonomies = self::taxonomies();
    $default = Languages::default_code();
    foreach (Languages::active_codes() as $language) {
      $domain_owned = class_exists('Yablonsky\\Multilingual\\RoutingModes') && RoutingModes::has_domain_for_language($language);
      $prefix = ($domain_owned || (Router::hide_default_slug_enabled() && $language === $default)) ? '' : preg_quote(Languages::code_to_slug($language), '#') . '/';
      foreach ($post_types as $name => $row) {
        $base = self::get($language, 'post_type', $name, (string) $row['slug']);
        $quoted = preg_quote($base, '#');
        $obj = get_post_type_object($name);
        $lang_query = $domain_owned ? '' : ('&' . Router::QV_LANG . '=' . rawurlencode($language));
        if ($obj && !empty($obj->has_archive)) {
          add_rewrite_rule('^' . $prefix . $quoted . '/?$', 'index.php?post_type=' . rawurlencode($name) . $lang_query, 'top');
        }
        add_rewrite_rule('^' . $prefix . $quoted . '/(.+?)/?$', 'index.php?post_type=' . rawurlencode($name) . '&name=$matches[1]' . $lang_query, 'top');
      }
      foreach ($taxonomies as $name => $row) {
        $base = self::get($language, 'taxonomy', $name, (string) $row['slug']);
        $quoted = preg_quote($base, '#');
        $lang_query = $domain_owned ? '' : ('&' . Router::QV_LANG . '=' . rawurlencode($language));
        add_rewrite_rule('^' . $prefix . $quoted . '/(.+?)/?$', 'index.php?' . rawurlencode($name) . '=$matches[1]' . $lang_query, 'top');
      }
    }
  }

  public static function filter_post_type_link(string $url, \WP_Post $post, bool $leavename, bool $sample): string {
    $obj = get_post_type_object($post->post_type);
    if (!$obj || empty($obj->rewrite) || !is_array($obj->rewrite)) return $url;
    $source = sanitize_title((string) ($obj->rewrite['slug'] ?? $post->post_type));
    $language = RuntimeAuthority::effective_post_language((int) $post->ID);
    $target = self::get($language, 'post_type', $post->post_type, $source);
    return self::replace_path_base($url, $source, $target);
  }

  public static function filter_archive_link(string $url, string $post_type): string {
    $obj = get_post_type_object($post_type);
    if (!$obj || empty($obj->rewrite) || !is_array($obj->rewrite)) return $url;
    $source = sanitize_title((string) ($obj->rewrite['slug'] ?? $post_type));
    $language = Router::current_lang();
    $target = self::get($language, 'post_type', $post_type, $source);
    return self::replace_path_base($url, $source, $target);
  }

  /** @param string|\WP_Error $url @return string|\WP_Error */
  public static function filter_term_link($url, \WP_Term $term, string $taxonomy) {
    if (is_wp_error($url)) return $url;
    $obj = get_taxonomy($taxonomy);
    if (!$obj || empty($obj->rewrite) || !is_array($obj->rewrite)) return $url;
    $source = sanitize_title((string) ($obj->rewrite['slug'] ?? $taxonomy));
    $language = class_exists('Yablonsky\\Multilingual\\RuntimeAuthority', false) ? RuntimeAuthority::effective_term_language((int) $term->term_id, (string) $term->taxonomy) : Router::current_lang();
    $target = self::get($language, 'taxonomy', $taxonomy, $source);
    return self::replace_path_base((string) $url, $source, $target);
  }

  private static function replace_path_base(string $url, string $source, string $target): string {
    if ($source === '' || $target === '' || $source === $target) return $url;
    $parts = wp_parse_url($url);
    if ($parts === false || empty($parts['path'])) return $url;
    $path = (string) $parts['path'];
    $pattern = '#/' . preg_quote(trim($source, '/'), '#') . '(/|$)#';
    $new_path = preg_replace($pattern, '/' . trim($target, '/') . '$1', $path, 1);
    if (!is_string($new_path) || $new_path === $path) return $url;
    return str_replace($path, $new_path, $url);
  }

  public static function handle_save(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ymulti_save_localized_bases');
    $input = filter_input(INPUT_POST, 'bases', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
    $input = is_array($input) ? $input : [];
    $clean = [];
    $post_types = self::post_types();
    $taxonomies = self::taxonomies();
    foreach (Languages::active_codes() as $language) {
      foreach ($post_types as $name => $row) {
        $value = sanitize_title((string) ($input[$language]['post_type'][$name] ?? ''));
        if ($value !== '' && $value !== $row['slug']) $clean[$language]['post_type'][$name] = $value;
      }
      foreach ($taxonomies as $name => $row) {
        $value = sanitize_title((string) ($input[$language]['taxonomy'][$name] ?? ''));
        if ($value !== '' && $value !== $row['slug']) $clean[$language]['taxonomy'][$name] = $value;
      }
    }
    update_option(self::OPT, $clean, false);
    self::$settings_cache = $clean;
    Router::maybe_flush_rewrites(true);
    wp_safe_redirect(admin_url('admin.php?page=ypm-routing&saved=1'));
    exit;
  }

  public static function page(): void {
    if (!current_user_can('manage_options')) return;
    $post_types = self::post_types();
    $taxonomies = self::taxonomies();
    echo '<div class="wrap ypm-admin">';
    Admin::render_shell_header(__('Routing', 'yablonsky-multilingual'), __('Localized public URL bases and language routing through the native WordPress permalink engine.', 'yablonsky-multilingual'), 'neutral', __('Native-first', 'yablonsky-multilingual'));
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success marker after nonce-protected save.
    if (isset($_GET['saved'])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Localized URL bases saved and rewrite rules refreshed.', 'yablonsky-multilingual') . '</p></div>';
    echo '<section class="ypm-card ypm-card--standalone"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Localized URL bases', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Translate archive and taxonomy bases', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('The native WordPress rewrite base stays active for locales with a blank field. Localization applies to public content routes, while wp-admin, REST and system paths keep their native paths.', 'yablonsky-multilingual') . '</p></div></div>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ymulti_save_localized_bases">';
    wp_nonce_field('ymulti_save_localized_bases');
    echo '<p class="ymulti-routing-bases-help">' . esc_html__('Choose a language tile to edit its public URL bases. Native WordPress bases appear as input placeholders; saved values become the localized public route.', 'yablonsky-multilingual') . '</p>';
    echo '<div class="ymulti-routing-matrix" data-ymulti-routing-matrix>';
    $field_total = count($post_types) + count($taxonomies);
    foreach (Languages::active_codes() as $language) {
      $override_count = 0;
      foreach ($post_types as $name => $row) if (self::get($language, 'post_type', $name, '') !== '') $override_count++;
      foreach ($taxonomies as $name => $row) if (self::get($language, 'taxonomy', $name, '') !== '') $override_count++;
      echo '<details class="ypm-disclosure ymulti-routing-language" data-ymulti-routing-language><summary><span><strong>' . esc_html(Languages::display_name($language)) . '</strong><small>' . esc_html(Languages::display_code($language)) . '</small></span><b>' . esc_html(sprintf(
        /* translators: 1: localized URL-base overrides, 2: available URL-base fields. */
        __('%1$d/%2$d localized', 'yablonsky-multilingual'),
        $override_count,
        $field_total
      )) . '</b><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary><div class="ypm-disclosure__body ymulti-routing-language__body">';
      if ($post_types) {
        echo '<section class="ymulti-routing-fieldset"><div class="ymulti-routing-fieldset__head"><h3>' . esc_html__('Content bases', 'yablonsky-multilingual') . '</h3><small>' . esc_html__('Archive and single-content route bases', 'yablonsky-multilingual') . '</small></div><div class="ymulti-routing-fields">';
        foreach ($post_types as $name => $row) {
          $value = self::get($language, 'post_type', $name, '');
          echo '<label class="ymulti-routing-field"><span>' . esc_html($row['label']) . '</span><input type="text" name="bases[' . esc_attr($language) . '][post_type][' . esc_attr($name) . ']" value="' . esc_attr($value) . '" placeholder="' . esc_attr($row['slug']) . '" title="' . esc_attr(sprintf(
            /* translators: %s: native WordPress URL base. */
            __('Native base: %s', 'yablonsky-multilingual'),
            $row['slug']
          )) . '"></label>';
        }
        echo '</div></section>';
      }
      if ($taxonomies) {
        echo '<section class="ymulti-routing-fieldset"><div class="ymulti-routing-fieldset__head"><h3>' . esc_html__('Taxonomy bases', 'yablonsky-multilingual') . '</h3><small>' . esc_html__('Category, tag and commerce taxonomy route bases', 'yablonsky-multilingual') . '</small></div><div class="ymulti-routing-fields">';
        foreach ($taxonomies as $name => $row) {
          $value = self::get($language, 'taxonomy', $name, '');
          echo '<label class="ymulti-routing-field"><span>' . esc_html($row['label']) . '</span><input type="text" name="bases[' . esc_attr($language) . '][taxonomy][' . esc_attr($name) . ']" value="' . esc_attr($value) . '" placeholder="' . esc_attr($row['slug']) . '" title="' . esc_attr(sprintf(
            /* translators: %s: native WordPress URL base. */
            __('Native base: %s', 'yablonsky-multilingual'),
            $row['slug']
          )) . '"></label>';
        }
        echo '</div></section>';
      }
      echo '</div></details>';
    }
    echo '</div><div class="ymulti-routing-savebar"><span>' . esc_html__('Localized bases update public routes after WordPress rewrite rules refresh.', 'yablonsky-multilingual') . '</span><button class="button button-primary" type="submit">' . esc_html__('Save localized bases', 'yablonsky-multilingual') . '</button></div></form></section>';
    if (class_exists('Yablonsky\Multilingual\RoutingModes')) RoutingModes::render_panel();
    echo '</div>';
  }
}
