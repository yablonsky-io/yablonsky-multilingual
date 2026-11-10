<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Read-only, bounded audit of shared WordPress menu references across active locales. */
final class NavigationAudit {
  private const MAX_ITEMS = 200;
  private const MAX_GAPS = 30;

  /** @return array<string,mixed> */
  public static function scan(): array {
    $active = Languages::active_codes();
    $menus = wp_get_nav_menus();
    if (!is_array($menus)) $menus = [];
    $result = ['menus' => count($menus), 'items' => 0, 'translatable' => 0, 'complete' => 0, 'gaps' => [], 'truncated' => false];
    $records = [];
    $post_ids = [];
    $term_ids = [];
    $seen_item_families = [];
    $seen_object_families = [];

    foreach ($menus as $menu) {
      if ((int) $result['items'] >= self::MAX_ITEMS) { $result['truncated'] = true; break; }
      if (!($menu instanceof \WP_Term)) continue;
      $items = wp_get_nav_menu_items((int) $menu->term_id, ['post_status' => 'any']);
      if (!is_array($items)) continue;
      foreach ($items as $item) {
        if ((int) $result['items'] >= self::MAX_ITEMS) { $result['truncated'] = true; break 2; }
        if (!($item instanceof \WP_Post)) continue;
        $item_family_key = EntityContract::post_family_key((int) $item->ID);
        if (isset($seen_item_families[$item_family_key])) continue;
        $seen_item_families[$item_family_key] = true;
        $result['items']++;
        $object_id = absint($item->object_id ?? 0);
        $object = sanitize_key((string) ($item->object ?? ''));
        $type = sanitize_key((string) ($item->type ?? ''));
        if ($object_id <= 0 || !in_array($type, ['post_type', 'taxonomy'], true)) continue;
        $object_family_key = $type === 'post_type'
          ? 'post|' . EntityContract::post_family_key($object_id)
          : 'term|' . $object . '|' . EntityContract::term_family_key($object_id, $object);
        if (isset($seen_object_families[$object_family_key])) continue;
        $seen_object_families[$object_family_key] = true;
        $result['translatable']++;
        $records[] = [
          'menu' => sanitize_text_field((string) $menu->name),
          'item' => sanitize_text_field((string) ($item->title ?: ('#' . $object_id))),
          'object' => $object,
          'object_id' => $object_id,
          'type' => $type,
        ];
        if ($type === 'post_type') $post_ids[$object_id] = true;
        elseif (TermTranslations::is_supported_taxonomy($object)) $term_ids[$object][$object_id] = true;
      }
    }

    // Prime the bounded audit set once. The locale loop below then reads request-local
    // family maps instead of expanding one family at a time.
    if ($post_ids) Translations::prime_translation_families(array_keys($post_ids));
    foreach ($term_ids as $taxonomy => $ids) {
      TermTranslations::prime_translation_families(array_keys($ids), (string) $taxonomy);
    }

    foreach ($records as $record) {
      $object_id = (int) $record['object_id'];
      $object = (string) $record['object'];
      $type = (string) $record['type'];
      $missing = [];
      if ($type === 'post_type') {
        $family = EffectiveRelations::post_family($object_id, null, $active);
        $translations = is_array($family['translations'] ?? null) ? $family['translations'] : [];
      } elseif (TermTranslations::is_supported_taxonomy($object)) {
        $family = EffectiveRelations::term_family($object_id, $object, null, $active);
        $translations = is_array($family['translations'] ?? null) ? $family['translations'] : [];
      } else {
        $translations = array_fill_keys($active, $object_id);
      }
      foreach ($active as $locale) if (empty($translations[$locale])) $missing[] = $locale;
      if (!$missing) { $result['complete']++; continue; }

      foreach ($missing as $locale) {
        if (count($result['gaps']) >= self::MAX_GAPS) break 2;
        $url = '';
        if ($type === 'post_type') {
          $post = get_post($object_id);
          if ($post instanceof \WP_Post && current_user_can('edit_post', $object_id)) $url = AdminContent::create_translation_url($post, $locale);
        } elseif (TermTranslations::is_supported_taxonomy($object)) {
          $url = AdminTerms::create_translation_url($object_id, $object, $locale);
        }
        $result['gaps'][] = [
          'menu' => $record['menu'],
          'item' => $record['item'],
          'object' => $object,
          'object_id' => $object_id,
          'locale' => $locale,
          'url' => $url,
        ];
      }
    }
    return $result;
  }

  public static function render_panel(): void {
    if (!current_user_can('edit_posts')) return;
    $audit = self::scan();
    $gaps = isset($audit['gaps']) && is_array($audit['gaps']) ? $audit['gaps'] : [];
    echo '<section class="ypm-card ypm-card--standalone"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Navigation', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Shared-menu translation readiness', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('YMulti keeps one native WordPress menu and resolves linked posts/pages/products/taxonomy terms to the current locale at runtime. This bounded audit shows menu-linked object translations ready to complete.', 'yablonsky-multilingual') . '</p></div>' . wp_kses_post(Admin::status_badge($gaps ? 'attention' : 'pass', $gaps ? __('Gaps found', 'yablonsky-multilingual') : __('Ready', 'yablonsky-multilingual'))) . '</div>';
    echo '<div class="ypm-metrics ypm-metrics--4">';
    Admin::render_metric(__('Menus', 'yablonsky-multilingual'), (string) (int) ($audit['menus'] ?? 0), __('Native WordPress menus', 'yablonsky-multilingual'), 'neutral');
    Admin::render_metric(__('Scanned items', 'yablonsky-multilingual'), (string) (int) ($audit['items'] ?? 0), !empty($audit['truncated']) ? __('Bound reached', 'yablonsky-multilingual') : __('Bounded scan', 'yablonsky-multilingual'), !empty($audit['truncated']) ? 'attention' : 'neutral');
    Admin::render_metric(__('Linked objects', 'yablonsky-multilingual'), (string) (int) ($audit['translatable'] ?? 0), __('Post / taxonomy references', 'yablonsky-multilingual'), 'neutral');
    Admin::render_metric(__('Complete objects', 'yablonsky-multilingual'), (string) (int) ($audit['complete'] ?? 0), __('Across active locales', 'yablonsky-multilingual'), $gaps ? 'attention' : 'pass');
    echo '</div>';

    if ($gaps) {
      echo '<details class="ypm-disclosure ypm-navigation-gap-list"><summary><span><strong>' . esc_html__('Menu-linked translations to complete', 'yablonsky-multilingual') . '</strong><small>' . esc_html(sprintf(
        /* translators: %d: number of missing menu-linked translation records shown. */
        __('Review %d bounded gaps', 'yablonsky-multilingual'),
        count($gaps)
      )) . '</small></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary><div class="ypm-disclosure__body"><div class="ypm-family-list">';
      foreach ($gaps as $gap) {
        if (!is_array($gap)) continue;
        $locale = sanitize_text_field((string) ($gap['locale'] ?? ''));
        echo '<div class="ypm-family-row"><div class="ypm-family-row__name"><strong>' . esc_html((string) ($gap['item'] ?? '')) . '</strong><span>' . esc_html((string) ($gap['menu'] ?? '')) . ' · <code>' . esc_html((string) ($gap['object'] ?? '')) . '</code></span></div><span class="ypm-family-chip ypm-family-row__code">+' . esc_html(Languages::display_code($locale)) . '</span><div class="ypm-family-row__action">';
        $url = (string) ($gap['url'] ?? '');
        if ($url !== '') echo '<a class="button button-small button-primary" href="' . esc_url($url) . '">' . esc_html__('Create translation', 'yablonsky-multilingual') . '</a>'; else echo '<span class="ypm-family-status">' . esc_html__('Review object', 'yablonsky-multilingual') . '</span>';
        echo '</div></div>';
      }
      echo '</div><p class="description">' . esc_html__('Each audit shows the first 30 object/locale link gaps. Custom URLs remain under native menu authority because WordPress translation families apply to linked objects.', 'yablonsky-multilingual') . '</p></div></details>';
    } else {
      echo '<div class="ypm-empty-panel ypm-empty-panel--compact"><strong>' . esc_html__('Bounded scan complete · menu translation gaps: 0.', 'yablonsky-multilingual') . '</strong><p>' . esc_html__('Runtime menu resolution can use the existing translation families for every scanned linked object.', 'yablonsky-multilingual') . '</p></div>';
    }
    echo '<div class="ypm-row-actions"><a class="button" href="' . esc_url(admin_url('nav-menus.php')) . '">' . esc_html__('Open native Menus', 'yablonsky-multilingual') . '</a></div></section>';
  }
}
