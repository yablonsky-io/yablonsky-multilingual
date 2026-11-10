<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

final class Switcher {
  private static int $instance_counter = 0;

  public static function init(): void {
    // Canonical public shortcode. Keep the old YPM tag as a compatibility alias.
    add_shortcode('ymulti_language_switcher', [__CLASS__, 'shortcode']);
    add_shortcode('ypm_language_switcher', [__CLASS__, 'shortcode']);
    add_action('widgets_init', [__CLASS__, 'register_widget']);
    add_action('init', [__CLASS__, 'register_block'], 20);
    add_filter('widget_types_to_hide_from_legacy_widget_block', [__CLASS__, 'hide_legacy_widget_from_block_editor']);
    add_action('wp_enqueue_scripts', [__CLASS__, 'register_frontend_assets'], 1);
  }

  public static function register_frontend_assets(): void {
    wp_register_style('ymulti-language-switcher', YMULTI_ASSET_URL . 'language-switcher.css', [], YMULTI_VERSION);
    wp_register_script('ymulti-language-switcher', YMULTI_ASSET_URL . 'language-switcher.js', [], YMULTI_VERSION, true);
  }

  /** @return array<string,mixed> */
  public static function accessibility_capabilities(): array {
    return [
      'accessible_name' => 'label',
      'unique_control_id' => true,
      'keyboard_native_select' => true,
      'inline_event_handler' => false,
      'delegated_navigation_handler' => true,
      'conditional_assets' => true,
      'minimum_control_height_px' => 44,
      'rtl_inherits_document_direction' => true,
    ];
  }

  public static function register_widget(): void {
    if (!class_exists('WP_Widget')) return;
    register_widget('Yablonsky\\Multilingual\\Widget_Language_Switcher');
  }

  /**
   * Keep the classic widget registered for existing sidebars/classic-widget
   * installations, but steer block-editor users to the native YMulti block.
   */
  public static function hide_legacy_widget_from_block_editor(array $widget_types): array {
    if (!in_array('ypm_language_switcher', $widget_types, true)) {
      $widget_types[] = 'ypm_language_switcher';
    }
    return $widget_types;
  }

  /**
   * Register the native dynamic block without a build framework.
   * The editor uses a local placeholder instead of ServerSideRender so adding
   * the block does not depend on a REST preview request.
   */
  public static function register_block(): void {
    if (!function_exists('register_block_type')) return;

    $handle = 'ypm-language-switcher-block-editor';
    wp_register_script(
      $handle,
      YPM_URL . 'blocks/language-switcher/index.js',
      ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'],
      YPM_VERSION,
      true
    );

    wp_localize_script($handle, 'YMultiBlockI18n', [
      'title' => __('YMulti Language Switcher', 'yablonsky-multilingual'),
      'description' => __('Displays links for the active YMulti languages.', 'yablonsky-multilingual'),
      'settings' => __('Switcher settings', 'yablonsky-multilingual'),
      'type' => __('Type', 'yablonsky-multilingual'),
      'dropdown' => __('Dropdown', 'yablonsky-multilingual'),
      'inlineLinks' => __('Inline links', 'yablonsky-multilingual'),
      'alignment' => __('Alignment', 'yablonsky-multilingual'),
      'themeDefault' => __('Theme default', 'yablonsky-multilingual'),
      'left' => __('Left', 'yablonsky-multilingual'),
      'center' => __('Center', 'yablonsky-multilingual'),
      'right' => __('Right', 'yablonsky-multilingual'),
      'showFlags' => __('Show emoji flags', 'yablonsky-multilingual'),
      'showName' => __('Show language name', 'yablonsky-multilingual'),
      'showCode' => __('Show language code', 'yablonsky-multilingual'),
      'instructions' => __('The front-end switcher is rendered dynamically from the active YMulti languages.', 'yablonsky-multilingual'),
    ]);

    register_block_type(
      YPM_PATH . 'blocks/language-switcher',
      [
        'render_callback' => [__CLASS__, 'render_block'],
      ]
    );
  }

  public static function render_block(array $attributes = []): string {
    return self::shortcode([
      'type' => isset($attributes['type']) ? (string) $attributes['type'] : 'dropdown',
      'show_flag' => !array_key_exists('showFlag', $attributes) || !empty($attributes['showFlag']) ? '1' : '0',
      'show_name' => !array_key_exists('showName', $attributes) || !empty($attributes['showName']) ? '1' : '0',
      'show_code' => !empty($attributes['showCode']) ? '1' : '0',
      'align' => isset($attributes['textAlign']) ? (string) $attributes['textAlign'] : '',
    ]);
  }

  public static function shortcode(array $atts = []): string {
    if (class_exists('Yablonsky\\Multilingual\\SafeMode') && SafeMode::enabled()) return '';

    try {
      $atts = shortcode_atts([
        'type' => 'dropdown',
        'show_code' => '0',
        'show_name' => '1',
        'show_flag' => '1',
        'class' => 'ymulti-language-switcher',
        'separator' => ' ',
        'align' => '',
      ], $atts, 'ymulti_language_switcher');

      $active = Languages::selection_codes(Languages::public_codes());
      if (!$active) return '';

      $catalog = Languages::catalog();
      $current = Router::current_lang();
      $links = self::build_links_for_current_context();

      $type = in_array((string) $atts['type'], ['dropdown', 'inline'], true) ? (string) $atts['type'] : 'dropdown';
      $wrap_classes = array_values(array_unique([(string) $atts['class'], 'ymulti-language-switcher', 'ypm-language-switcher', 'ymulti-switcher', 'ymulti-switcher--' . $type, 'ypm-switcher', 'ypm-switcher--' . $type]));
      $align = strtolower((string) $atts['align']);
      $style = '';
      if (in_array($align, ['left', 'center', 'right'], true)) {
        $style = ' style="text-align:' . esc_attr($align) . ';"';
      }

      if ($type === 'inline') {
        $items = [];
        foreach ($active as $code) {
          $url = $links[$code] ?? null;
          if (!$url) continue;
          $label = self::build_label($code, $catalog, $atts);
          $is_current = ($code === $current);
          if ($is_current) {
            $items[] = '<span class="ymulti-switcher__current ypm-switcher__current" aria-current="page">' . esc_html($label) . '</span>';
          } else {
            $items[] = '<a href="' . esc_url($url) . '" class="ymulti-switcher__link ypm-switcher__link">' . esc_html($label) . '</a>';
          }
        }
        $separator = (string) $atts['separator'];
        return '<span class="' . esc_attr(implode(' ', $wrap_classes)) . '"' . $style . '>' . implode(esc_html($separator), $items) . '</span>';
      }

      $options = [];
      foreach ($active as $code) {
        $url = $links[$code] ?? null;
        if (!$url) continue;
        $label = self::build_label($code, $catalog, $atts);
        $selected = ($code === $current) ? ' selected' : '';
        $options[] = '<option value="' . esc_url($url) . '" lang="' . esc_attr(Languages::hreflang_code($code)) . '"' . $selected . '>' . esc_html($label) . '</option>';
      }

      if (!$options) return '';

      self::$instance_counter++;
      $select_id = 'ymulti-language-switcher-' . self::$instance_counter;
      $label = __('Choose language', 'yablonsky-multilingual');
      // Assets are registered globally but transferred only when a dropdown is actually rendered.
      if (!wp_style_is('ymulti-language-switcher', 'registered') || !wp_script_is('ymulti-language-switcher', 'registered')) self::register_frontend_assets();
      wp_enqueue_style('ymulti-language-switcher');
      wp_enqueue_script('ymulti-language-switcher');

      $select = '<label class="screen-reader-text ymulti-switcher__label ypm-switcher__label" for="' . esc_attr($select_id) . '">' . esc_html($label) . '</label>'
        . '<select id="' . esc_attr($select_id) . '" class="ymulti-switcher__select ypm-switcher__select" data-ymulti-language-switcher="1">' . implode('', $options) . '</select>';
      return '<div class="' . esc_attr(implode(' ', $wrap_classes)) . '"' . $style . '>' . $select . '</div>';
    } catch (\Throwable $e) {
      return '';
    }
  }

  private static function build_label(string $code, array $catalog, array $atts): string {
    $name = $catalog[$code]['name'] ?? $code;
    $parts = [];

    if (($atts['show_flag'] ?? '0') === '1') {
      $flag = self::emoji_flag_for_code($code);
      if ($flag !== '') $parts[] = $flag;
    }
    if (($atts['show_name'] ?? '1') === '1') $parts[] = $name;
    if (($atts['show_code'] ?? '0') === '1') $parts[] = Languages::display_code($code);

    return trim(implode(' ', $parts));
  }

  private static function emoji_flag_for_code(string $code): string {
    $code = strtolower(str_replace('_', '-', $code));
    $map = [
      'en-gb' => '🇬🇧', 'en-us' => '🇺🇸', 'en-ca' => '🇨🇦', 'en-au' => '🇦🇺',
      'fr-fr' => '🇫🇷', 'fr-ca' => '🇨🇦', 'pt-pt' => '🇵🇹', 'pt-br' => '🇧🇷',
      'es-es' => '🇪🇸', 'zh-cn' => '🇨🇳', 'zh-tw' => '🇹🇼', 'zh-hk' => '🇭🇰',
      'ru' => '🇷🇺', 'lt' => '🇱🇹', 'pl' => '🇵🇱', 'uk' => '🇺🇦', 'de' => '🇩🇪',
      'it' => '🇮🇹', 'nl' => '🇳🇱', 'sv' => '🇸🇪', 'da' => '🇩🇰', 'fi' => '🇫🇮',
      'no' => '🇳🇴', 'cs' => '🇨🇿', 'hu' => '🇭🇺', 'ro' => '🇷🇴', 'el' => '🇬🇷',
      'tr' => '🇹🇷', 'ar' => '🇸🇦', 'hi' => '🇮🇳', 'ja' => '🇯🇵', 'ko' => '🇰🇷',
      'bg' => '🇧🇬', 'hr' => '🇭🇷', 'et' => '🇪🇪', 'ga' => '🇮🇪', 'lv' => '🇱🇻',
      'ms' => '🇲🇾', 'mt' => '🇲🇹', 'sk' => '🇸🇰', 'sl' => '🇸🇮', 'id' => '🇮🇩',
    ];
    return $map[$code] ?? '';
  }

  private static function build_links_for_current_context(): array {
    $active = Languages::public_codes();
    $links = [];

    $is_json = function_exists('wp_is_json_request') ? wp_is_json_request() : false;
    $is_rest = (defined('REST_REQUEST') && REST_REQUEST);
    if (is_admin() || $is_json || $is_rest || wp_doing_ajax() || wp_doing_cron()) {
      return self::language_home_links($active);
    }

    if (is_category() || is_tag() || is_tax()) {
      $term = get_queried_object();
      if ($term instanceof \WP_Term && TermTranslations::is_supported_taxonomy((string) $term->taxonomy)) {
        $taxonomy = (string) $term->taxonomy;
        $family = EntityContract::term_family((int) $term->term_id, $taxonomy, $active);
        foreach ((array) ($family['translations'] ?? []) as $language => $term_id) {
          if (!Languages::is_public((string) $language)) continue;
          $url = Urls::for_term((int) $term_id, $taxonomy, (string) $language);
          if ($url !== '') $links[(string) $language] = $url;
        }

        $language = EntityContract::term_source_locale((int) $term->term_id, $taxonomy);
        if (Languages::is_public($language) && empty($links[$language])) {
          $url = Urls::for_term((int) $term->term_id, $taxonomy, $language);
          if ($url !== '') $links[$language] = $url;
        }

        foreach (self::language_home_links($active) as $code => $home_url) {
          if (empty($links[$code])) $links[$code] = $home_url;
        }
        return $links;
      }
    }

    if (is_singular()) {
      $post = get_queried_object();
      if ($post instanceof \WP_Post) {
        $family = EntityContract::post_family((int) $post->ID, $active);
        foreach ((array) ($family['translations'] ?? []) as $language => $post_id) {
          if (!Languages::is_public((string) $language)) continue;
          $url = Urls::for_post((int) $post_id, (string) $language);
          if ($url !== '') $links[(string) $language] = $url;
        }

        $language = EntityContract::post_source_locale((int) $post->ID);
        if (Languages::is_public($language) && empty($links[$language])) {
          $url = Urls::for_post((int) $post->ID, $language);
          if ($url !== '') $links[$language] = $url;
        }

        foreach (self::language_home_links($active) as $code => $home_url) {
          if (empty($links[$code])) $links[$code] = $home_url;
        }

        return $links;
      }
    }

    $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
    $parts = wp_parse_url($request_uri);
    $path = is_array($parts) && isset($parts['path']) ? (string) $parts['path'] : '/';
    $query = is_array($parts) && isset($parts['query']) ? ('?' . (string) $parts['query']) : '';

    if (Router::is_system_path($path)) {
      return self::language_home_links($active);
    }

    foreach ($active as $code) {
      $links[$code] = Urls::for_path($path . $query, $code);
    }

    return $links;
  }

  private static function language_home_links(array $active): array {
    $links = [];
    foreach ($active as $code) {
      $links[$code] = Urls::home($code);
    }
    return $links;
  }
}

/**
 * Classic widget wrapper retained for backward compatibility.
 * New block-editor placements should use the native YMulti Language Switcher block.
 */
if (class_exists('WP_Widget')) {
  class Widget_Language_Switcher extends \WP_Widget {
    public function __construct() {
      parent::__construct(
        'ypm_language_switcher',
        esc_html__('YMulti Language Switcher', 'yablonsky-multilingual'),
        ['description' => esc_html__('Language switcher for classic widget areas.', 'yablonsky-multilingual')]
      );
    }

    public function widget($args, $instance) {
      try {
        echo wp_kses_post($args['before_widget']);

        $title = isset($instance['title']) ? sanitize_text_field($instance['title']) : '';
        if ($title !== '') {
          echo wp_kses_post($args['before_title']) . esc_html($title) . wp_kses_post($args['after_title']);
        }

        $type = isset($instance['type']) && in_array($instance['type'], ['dropdown', 'inline'], true) ? $instance['type'] : 'dropdown';
        $show_flag = !empty($instance['show_flag']) ? '1' : '0';
        $show_name = !empty($instance['show_name']) ? '1' : '0';
        $show_code = !empty($instance['show_code']) ? '1' : '0';
        $align = isset($instance['align']) ? sanitize_text_field($instance['align']) : '';

        $html = Switcher::shortcode([
          'type' => $type,
          'show_flag' => $show_flag,
          'show_name' => $show_name,
          'show_code' => $show_code,
          'align' => $align,
        ]);

        $allowed = [
          'div' => ['class' => true, 'style' => true],
          'span' => ['class' => true, 'style' => true],
          'a' => ['class' => true, 'href' => true, 'rel' => true, 'title' => true],
          'label' => ['class' => true, 'for' => true],
          'select' => ['id' => true, 'class' => true, 'data-ymulti-language-switcher' => true],
          'option' => ['value' => true, 'selected' => true, 'lang' => true],
        ];
        echo wp_kses($html, $allowed);

        echo wp_kses_post($args['after_widget']);
      } catch (\Throwable $e) {
        echo '';
      }
    }

    public function form($instance) {
      $title = isset($instance['title']) ? sanitize_text_field($instance['title']) : '';
      $type = isset($instance['type']) ? sanitize_text_field($instance['type']) : 'dropdown';
      $align = isset($instance['align']) ? sanitize_text_field($instance['align']) : '';
      $show_flag = !empty($instance['show_flag']);
      $show_name = array_key_exists('show_name', (array) $instance) ? (bool) $instance['show_name'] : true;
      $show_code = !empty($instance['show_code']);
      ?>
      <p>
        <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php echo esc_html__('Title:', 'yablonsky-multilingual'); ?></label>
        <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>" />
      </p>
      <p>
        <label for="<?php echo esc_attr($this->get_field_id('type')); ?>"><?php echo esc_html__('Type:', 'yablonsky-multilingual'); ?></label>
        <select class="widefat" id="<?php echo esc_attr($this->get_field_id('type')); ?>" name="<?php echo esc_attr($this->get_field_name('type')); ?>">
          <option value="dropdown" <?php selected($type, 'dropdown'); ?>><?php echo esc_html__('Dropdown', 'yablonsky-multilingual'); ?></option>
          <option value="inline" <?php selected($type, 'inline'); ?>><?php echo esc_html__('Inline', 'yablonsky-multilingual'); ?></option>
        </select>
      </p>
      <p>
        <label for="<?php echo esc_attr($this->get_field_id('align')); ?>"><?php echo esc_html__('Align:', 'yablonsky-multilingual'); ?></label>
        <select class="widefat" id="<?php echo esc_attr($this->get_field_id('align')); ?>" name="<?php echo esc_attr($this->get_field_name('align')); ?>">
          <option value="" <?php selected($align, ''); ?>><?php echo esc_html__('Theme default', 'yablonsky-multilingual'); ?></option>
          <option value="left" <?php selected($align, 'left'); ?>><?php echo esc_html__('Left', 'yablonsky-multilingual'); ?></option>
          <option value="center" <?php selected($align, 'center'); ?>><?php echo esc_html__('Center', 'yablonsky-multilingual'); ?></option>
          <option value="right" <?php selected($align, 'right'); ?>><?php echo esc_html__('Right', 'yablonsky-multilingual'); ?></option>
        </select>
      </p>
      <p>
        <input class="checkbox" type="checkbox" <?php checked($show_flag); ?> id="<?php echo esc_attr($this->get_field_id('show_flag')); ?>" name="<?php echo esc_attr($this->get_field_name('show_flag')); ?>" />
        <label for="<?php echo esc_attr($this->get_field_id('show_flag')); ?>"><?php echo esc_html__('Show emoji flags (lightweight)', 'yablonsky-multilingual'); ?></label>
      </p>
      <p>
        <input class="checkbox" type="checkbox" <?php checked($show_name); ?> id="<?php echo esc_attr($this->get_field_id('show_name')); ?>" name="<?php echo esc_attr($this->get_field_name('show_name')); ?>" />
        <label for="<?php echo esc_attr($this->get_field_id('show_name')); ?>"><?php echo esc_html__('Show language name', 'yablonsky-multilingual'); ?></label>
      </p>
      <p>
        <input class="checkbox" type="checkbox" <?php checked($show_code); ?> id="<?php echo esc_attr($this->get_field_id('show_code')); ?>" name="<?php echo esc_attr($this->get_field_name('show_code')); ?>" />
        <label for="<?php echo esc_attr($this->get_field_id('show_code')); ?>"><?php echo esc_html__('Show language code', 'yablonsky-multilingual'); ?></label>
      </p>
      <?php
    }

    public function update($new_instance, $old_instance) {
      $instance = [];
      $instance['title'] = isset($new_instance['title']) ? sanitize_text_field($new_instance['title']) : '';
      $instance['type'] = isset($new_instance['type']) && in_array($new_instance['type'], ['dropdown', 'inline'], true) ? $new_instance['type'] : 'dropdown';
      $instance['align'] = isset($new_instance['align']) ? sanitize_text_field($new_instance['align']) : '';
      $instance['show_flag'] = !empty($new_instance['show_flag']) ? 1 : 0;
      $instance['show_name'] = !empty($new_instance['show_name']) ? 1 : 0;
      $instance['show_code'] = !empty($new_instance['show_code']) ? 1 : 0;
      return $instance;
    }
  }
}
