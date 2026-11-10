<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Side-by-side visual QA for native translation objects. */
final class VisualReview {
  public static function init(): void {
    if (is_admin()) {
      add_action('admin_menu', [__CLASS__, 'menu'], 20);
      add_action('admin_head', [__CLASS__, 'hide_submenu_entry']);
      add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }
  }

  public static function menu(): void {
    add_submenu_page('ypm-multilingual', __('Visual Review', 'yablonsky-multilingual'), __('Visual Review', 'yablonsky-multilingual'), 'edit_posts', 'ypm-visual-review', [__CLASS__, 'page']);
    // Keep the registered submenu entry for WordPress' permission/route resolution.
    // Removing it here makes get_admin_page_parent() lose the parent and
    // user_can_access_admin_page() reject the canonical Visual Review URL.
  }

  /** Hide only the sidebar link; preserve its WordPress registration and capability. */
  public static function hide_submenu_entry(): void {
    echo '<style id="ymulti-visual-review-submenu">#adminmenu .wp-submenu li:has(> a[href*="page=ypm-visual-review"]) { display: none !important; }</style>';
  }

  /** Load isolated review instrumentation only on this admin screen. */
  public static function enqueue_assets(string $hook): void {
    if (strpos($hook, '_page_ypm-visual-review') === false) return;
    wp_enqueue_script('ymulti-visual-review', YPM_ASSET_URL . 'admin-visual-review.js', [], YPM_VERSION, true);
  }

  public static function page(): void {
    if (!current_user_can('edit_posts')) return;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filters; no state change.
    $source_id = isset($_GET['source']) ? absint(wp_unslash($_GET['source'])) : 0;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filters; no state change.
    $target = isset($_GET['lang']) ? sanitize_text_field(wp_unslash($_GET['lang'])) : '';
    if ($target !== '' && !Languages::is_active($target)) $target = '';
    $source = $source_id > 0 ? get_post($source_id) : null;
    if ($source instanceof \WP_Post && !current_user_can('edit_post', $source_id)) $source = null;

    echo '<div class="wrap ypm-admin">';
    Admin::render_shell_header(__('Visual Review', 'yablonsky-multilingual'), __('Compare the source and a translated WordPress object in their real frontend context, then jump back to the native editor for changes.', 'yablonsky-multilingual'), 'neutral', __('Review-only', 'yablonsky-multilingual'));

    if (class_exists('Yablonsky\Multilingual\Autopilot')) {
      $review_rows = Autopilot::review_rows(8);
      if ($review_rows) {
        echo '<section class="ypm-card ypm-card--standalone"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Autopilot review', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Recent review-ready work', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('Continuous translation prepares review work, with publication remaining a human approval step. These are the newest drafts and source-drift reviews.', 'yablonsky-multilingual') . '</p></div></div><div class="ypm-family-list">';
        foreach ($review_rows as $row) {
          $kind = sanitize_key((string) ($row['kind'] ?? ''));
          if (!in_array($kind, ['content_missing','content_stale'], true)) continue;
          $review_source_id = absint($row['source_id'] ?? 0);
          $review_source = get_post($review_source_id);
          if (!$review_source instanceof \WP_Post || !current_user_can('edit_post', $review_source_id)) continue;
          $review_target = sanitize_text_field((string) ($row['target_locale'] ?? ''));
          $link = Autopilot::review_link($row);
          echo '<div class="ypm-family-row"><div class="ypm-family-row__name"><strong>' . esc_html(get_the_title($review_source_id) ?: ('#' . $review_source_id)) . '</strong><span>' . esc_html($kind === 'content_stale' ? __('Source changed after translation', 'yablonsky-multilingual') : __('New translation draft prepared', 'yablonsky-multilingual')) . '</span></div><span class="ypm-family-chip ypm-family-row__code">' . esc_html(Languages::display_code($review_target)) . '</span><div class="ypm-family-row__action">';
          if ((string) ($link['url'] ?? '') !== '') echo '<a class="button button-small button-primary" href="' . esc_url((string) $link['url']) . '">' . esc_html((string) ($link['label'] ?? __('Review', 'yablonsky-multilingual'))) . '</a>';
          echo '</div></div>';
        }
        echo '</div></section>';
      }
    }

    echo '<section class="ypm-card ypm-card--standalone"><form method="get" class="ymulti-visual-toolbar"><input type="hidden" name="page" value="ypm-visual-review">';
    echo '<label><span>' . esc_html__('Source post ID', 'yablonsky-multilingual') . '</span><input type="number" min="1" name="source" value="' . esc_attr($source_id > 0 ? (string) $source_id : '') . '" placeholder="123"></label>';
    echo '<label><span>' . esc_html__('Target language', 'yablonsky-multilingual') . '</span><select name="lang"><option value="">' . esc_html__('Choose target…', 'yablonsky-multilingual') . '</option>';
    foreach (Languages::active_codes() as $code) {
      if ($source instanceof \WP_Post && RuntimeAuthority::effective_post_language((int) $source->ID) === $code) continue;
      echo '<option value="' . esc_attr($code) . '"' . selected($target, $code, false) . '>' . esc_html(Languages::display_name($code) . ' · ' . Languages::display_code($code)) . '</option>';
    }
    echo '</select></label><button class="button button-primary" type="submit">' . esc_html__('Open comparison', 'yablonsky-multilingual') . '</button></form></section>';

    if (!$source instanceof \WP_Post || $target === '') {
      echo '<section class="ypm-card ypm-card--standalone"><div class="ypm-empty-state"><strong>' . esc_html__('Choose a source and target language.', 'yablonsky-multilingual') . '</strong><span>' . esc_html__('Visual Review previews the native source and target while WordPress remains the editing authority.', 'yablonsky-multilingual') . '</span></div></section></div>';
      return;
    }

    $effective = EntityContract::post_family((int) $source->ID, Languages::active_codes());
    $source_lang = EntityContract::post_source_locale((int) $source->ID);
    if ($source_lang === $target) {
      echo '<section class="ypm-card ypm-card--standalone"><div class="ypm-empty-state"><strong>' . esc_html__('Choose a source and target language.', 'yablonsky-multilingual') . '</strong></div></section></div>';
      return;
    }
    $target_id = absint($effective['translations'][$target] ?? 0);
    $target_post = $target_id ? get_post($target_id) : null;
    $target_exists = $target_post instanceof \WP_Post;
    // Never embed a private/draft member without object-level edit permission.
    $target_permitted = $target_exists && (
      $target_post->post_status === 'publish' || current_user_can('edit_post', $target_id)
    );
    $source_url = self::review_url((int) $source->ID, $source_lang);
    $target_url = $target_permitted ? self::review_url((int) $target_id, $target) : '';
    if ($target_permitted && class_exists('Yablonsky\Multilingual\TranslationFreshness')) {
      $freshness = TranslationFreshness::status((int) $target_id);
      echo '<div class="ypm-callout ypm-callout--' . esc_attr((string) $freshness['state']) . '"><span class="dashicons dashicons-update" aria-hidden="true"></span><div><strong>' . esc_html((string) $freshness['label']) . '</strong><p>' . esc_html((string) $freshness['detail']) . '</p></div></div>';
    }
    echo '<section class="ypm-card ypm-card--standalone"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Visual comparison', 'yablonsky-multilingual') . '</span><h2>' . esc_html(get_the_title((int) $source->ID) ?: ('#' . (int) $source->ID)) . '</h2><p class="ypm-card__copy">' . esc_html__('This surface is deliberately review-first: editing stays in WordPress admin and the public site keeps its existing frontend runtime.', 'yablonsky-multilingual') . '</p></div></div>';
    echo '<div class="ymulti-visual-actions">';
    if ($source_url !== '') echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url($source_url) . '">' . esc_html__('Open source', 'yablonsky-multilingual') . '</a>';
    if ($target_permitted) {
      $edit = current_user_can('edit_post', $target_id) ? get_edit_post_link((int) $target_id, '') : '';
      if ($target_url !== '') echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url($target_url) . '">' . esc_html__('Open target', 'yablonsky-multilingual') . '</a>';
      if (is_string($edit) && $edit !== '') echo '<a class="button button-primary" href="' . esc_url($edit) . '">' . esc_html__('Edit target in WordPress', 'yablonsky-multilingual') . '</a>';
    } elseif (!$target_exists) {
      $create = wp_nonce_url(admin_url('admin.php?page=ypm-translations&ypm_action=create_translation&source=' . (int) $source->ID . '&lang=' . rawurlencode($target)), 'ypm_create_translation');
      echo '<a class="button button-primary" href="' . esc_url($create) . '">' . esc_html__('Create target draft', 'yablonsky-multilingual') . '</a>';
    }
    echo '</div><div class="ymulti-visual-grid">';
    self::frame(__('Source', 'yablonsky-multilingual'), $source_lang, $source_url, $source_url !== '' ? 'preview' : 'missing');
    self::frame(__('Target', 'yablonsky-multilingual'), $target, $target_url, !$target_exists ? 'missing' : ($target_permitted && $target_url !== '' ? 'preview' : 'restricted'));
    echo '</div></section></div>';
  }

  /**
   * Use the owner of public routing. WPML is authoritative during coexistence;
   * YMulti's own URL prefixer must not remap WPML preview links afterwards.
   * No filters are installed on the WordPress preview pipeline.
   */
  private static function review_url(int $post_id, string $language): string {
    $post = get_post($post_id);
    if (!$post instanceof \WP_Post || $language === '') return '';
    $owner = RuntimeAuthority::public_owner();
    $provider_code = $owner === 'wpml' ? RuntimeAuthority::provider_code_for_language($language) : '';
    if ($owner === 'wpml' && $provider_code === '') return '';

    // WPML's locale is request-scoped: source and target can differ from the
    // current admin toolbar language. Close exactly the scope we opened.
    $scope = $owner === 'wpml' && has_action('wpml_switch_language') !== false;
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML API: fixed hook name required for language scope.
    if ($scope) do_action('wpml_switch_language', $provider_code);
    try {
      $url = $post->post_status === 'publish'
        ? get_permalink($post_id)
        : get_preview_post_link($post_id);
      if (!is_string($url) || $url === '') return '';
      if ($owner === 'wpml') {
        if ($post->post_status === 'publish') {
          // WPML decides directories, domain routing and translated slugs.
          // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML API for published permalink routing.
          $localized = apply_filters('wpml_permalink', $url, $provider_code, true);
          return is_string($localized) && $localized !== '' ? $localized : $url;
        }

        // Draft permalinks can be non-resolvable under a different WPML locale.
        // Keep WordPress's native preview query (including preview nonce), but
        // address the physical draft by ID on the WPML-language home route.
        $parts = wp_parse_url($url);
        if (!is_array($parts)) return '';
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        if (!is_array($query)) $query = [];
        // The draft ID and post type must win over any filtered preview link.
        unset($query['p'], $query['page_id'], $query['post_type'], $query['name'], $query['pagename'], $query['attachment_id']);
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML API for draft language home URL.
        $home = apply_filters('wpml_permalink', home_url('/'), $provider_code, false);
        if (!is_string($home) || $home === '') return '';
        $identity = $post->post_type === 'page'
          ? ['page_id' => $post_id]
          : ['p' => $post_id, 'post_type' => $post->post_type];
        return add_query_arg(array_merge($query, $identity, ['preview' => 'true', 'lang' => $provider_code]), $home);
      }
      if ($owner === 'polylang') {
        // The incumbent already filters WordPress's permalink/preview URL.
        // Do not introduce a second, competing language prefix.
        return $url;
      }
      return Urls::for_url($url, $language);
    } finally {
      // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML API: restore prior language context.
      if ($scope) do_action('wpml_switch_language', null);
    }
  }

  /** An iframe's presence is not proof that its HTTP response is successful. */
  private static function frame(string $label, string $language, string $url, string $state): void {
    $preview = $state === 'preview' && $url !== '';
    echo '<div class="ymulti-visual-pane"><div class="ymulti-visual-pane__head"><div><strong>' . esc_html($label) . '</strong><span>' . esc_html(Languages::display_name($language) . ' · ' . Languages::display_code($language)) . '</span></div>';
    if ($preview) {
      echo '<span class="ymulti-visual-frame-state" role="status" data-ymulti-review-status data-state="pending" data-ready="' . esc_attr__('Ready', 'yablonsky-multilingual') . '" data-missing="' . esc_attr__('Missing', 'yablonsky-multilingual') . '" data-review="' . esc_attr__('Needs review', 'yablonsky-multilingual') . '">' . esc_html__('Needs review', 'yablonsky-multilingual') . '</span>';
    } else {
      echo wp_kses_post(Admin::status_badge('attention', $state === 'restricted' ? __('Needs review', 'yablonsky-multilingual') : __('Missing', 'yablonsky-multilingual')));
    }
    echo '</div>';
    if ($preview) {
      echo '<iframe loading="lazy" referrerpolicy="same-origin" data-ymulti-review-frame data-expected-lang="' . esc_attr($language) . '" src="' . esc_url($url) . '" title="' . esc_attr($label) . '"></iframe>';
      echo '<small>' . esc_html__('If a security header blocks embedding, use the Open button above; the review workflow remains available.', 'yablonsky-multilingual') . '</small>';
    } else {
      echo '<div class="ymulti-visual-placeholder"><span class="dashicons dashicons-translation" aria-hidden="true"></span><strong>' . esc_html($state === 'restricted' ? __('Needs review', 'yablonsky-multilingual') : __('Target translation ready to create', 'yablonsky-multilingual')) . '</strong>';
      if ($state !== 'restricted') echo '<span>' . esc_html__('Create a native target draft, then return here for side-by-side visual QA.', 'yablonsky-multilingual') . '</span>';
      echo '</div>';
    }
    echo '</div>';
  }
}
