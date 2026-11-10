<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Shared admin language navigation without remote lookups or per-keystroke AJAX. */
final class LanguageUI {
  /** Localized labels shared by every admin surface using language-ui.js. */
  public static function localize_script(): void {
    wp_localize_script('ymulti-language-ui', 'YMultiLanguageUII18n', [
      'searchPlaceholder' => __('Search languages…', 'yablonsky-multilingual'),
      'searchLabel' => __('Search languages', 'yablonsky-multilingual'),
      'chooseLanguage' => __('Choose language', 'yablonsky-multilingual'),
      'allLanguages' => __('All languages', 'yablonsky-multilingual'),
      'matchesZero' => __('0 languages match this search', 'yablonsky-multilingual'),
      'drawerTitle' => __('YMulti · Languages', 'yablonsky-multilingual'),
      'close' => __('Close', 'yablonsky-multilingual'),
    ]);
  }

  /**
   * Compact row-level language family navigation. Global workspace selectors remain
   * the bulk context switch; this control lets an editor jump one object to a locale.
   *
   * @param array<int,string> $target_codes
   * @param array<int,string> $localized_codes
   * @param array<string,string> $links
   */
  public static function family_nav(array $target_codes, string $current, array $localized_codes, array $links, int $visible_target_count = 3, string $source = ''): string {
    $source = Languages::is_active($source) ? $source : '';
    $target_codes = array_values(array_filter(array_unique(array_map('strval', $target_codes)), static function(string $code) use ($source): bool { return Languages::is_active($code) && $code !== $source; }));
    $localized = array_fill_keys(array_values(array_filter(array_map('strval', $localized_codes))), true);
    $visible_target_count = max(1, min(4, $visible_target_count));
    $visible = array_slice($target_codes, 0, $visible_target_count);
    if ($current !== '' && in_array($current, $target_codes, true) && !in_array($current, $visible, true)) {
      if ($visible) $visible[count($visible) - 1] = $current; else $visible[] = $current;
      $visible = array_values(array_unique($visible));
    }
    $hidden = max(0, count($target_codes) - count($visible));
    ob_start();
    echo '<div class="ymulti-locale-family">';
    if ($source !== '') echo '<span class="ypm-family-chip is-base" title="' . esc_attr__('Source language', 'yablonsky-multilingual') . '">' . esc_html(Languages::display_code($source)) . '</span>';
    foreach ($visible as $code) self::render_chip($code, $current, isset($localized[$code]), (string) ($links[$code] ?? ''));
    if ($hidden > 0) {
      $drawer_rows = [];
      foreach ($target_codes as $code) {
        $meta = Languages::locale_metadata($code);
        $english = (string) ($meta['english_name'] ?? $meta['name'] ?? $code);
        $native = (string) ($meta['native_name'] ?? '');
        $drawer_rows[] = [
          'code' => Languages::display_code($code),
          'name' => $english,
          'native' => ($native !== '' && $native !== $english) ? $native : '',
          'url' => (string) ($links[$code] ?? ''),
          'state' => $code === $current ? __('Current', 'yablonsky-multilingual') : (isset($localized[$code]) ? __('Localized', 'yablonsky-multilingual') : __('Source value', 'yablonsky-multilingual')),
          'current' => $code === $current,
          'search' => strtolower($english . ' ' . $native . ' ' . $code . ' ' . Languages::display_code($code)),
        ];
      }
      echo '<button type="button" class="ypm-family-chip is-overflow" data-ymulti-language-overflow data-ymulti-language-options="' . esc_attr(wp_json_encode($drawer_rows)) . '" aria-label="' . esc_attr__('Open all languages', 'yablonsky-multilingual') . '">+' . esc_html((string) $hidden) . '</button>';
    }
    echo '</div>';
    return (string) ob_get_clean();
  }

  /** @return array<string,array<string,bool>> */
  public static function allowed_html(): array {
    return [
      'div' => ['class'=>true],
      'span' => ['class'=>true,'title'=>true],
      'a' => ['class'=>true,'href'=>true,'title'=>true,'data-post-id'=>true,'data-term-id'=>true,'data-taxonomy'=>true,'data-menu-item-id'=>true,'data-media-id'=>true],
      'button' => ['type'=>true,'class'=>true,'aria-label'=>true,'data-ymulti-language-overflow'=>true,'data-ymulti-language-options'=>true],
    ];
  }

  private static function render_chip(string $code, string $current, bool $localized, string $url): void {
    $classes = ['ypm-family-chip'];
    if ($code === $current) $classes[] = 'is-current'; elseif (!$localized) $classes[] = 'is-missing';
    $label = ($localized && $code !== $current ? '' : ($localized ? '' : '+ ')) . Languages::display_code($code);
    if ($url !== '') echo '<a class="' . esc_attr(implode(' ', $classes)) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    else echo '<span class="' . esc_attr(implode(' ', $classes)) . '">' . esc_html($label) . '</span>';
  }
}
