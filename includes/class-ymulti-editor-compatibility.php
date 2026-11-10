<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Native editor / Site Editor / page-builder compatibility bridge.
 *
 * The multilingual identity remains owned by YMulti and translated content
 * remains a normal WordPress object. This bridge only preserves editor
 * structure/settings that would otherwise disappear when a target draft is
 * created. It never performs frontend remote calls.
 */
final class EditorCompatibility {
  public const META_REVIEW = '_ymulti_editor_review_required';
  public const META_SOURCE = '_ymulti_editor_source';

  public static function init(): void {
    add_filter('yablonsky_multilingual_supported_post_types', [__CLASS__, 'extend_post_types'], 20);
    add_action('yablonsky_multilingual_translation_draft_initialized', [__CLASS__, 'initialize_translation_draft'], 10, 3);
  }

  /** @param string[] $types @return string[] */
  public static function extend_post_types(array $types): array {
    foreach (['wp_block', 'wp_navigation', 'wp_template', 'wp_template_part'] as $type) {
      if (post_type_exists($type) && !in_array($type, $types, true)) $types[] = $type;
    }
    return array_values(array_unique($types));
  }

  /** @return array<string,array<string,mixed>> */
  public static function detected_editors(): array {
    return [
      'site_editor' => [
        'label' => __('WordPress Site Editor', 'yablonsky-multilingual'),
        'active' => function_exists('wp_is_block_theme') ? (bool) wp_is_block_theme() : false,
        'detail' => __('Templates, template parts, synced patterns and Navigation remain native WordPress objects.', 'yablonsky-multilingual'),
      ],
      'elementor' => [
        'label' => 'Elementor',
        'active' => defined('ELEMENTOR_VERSION') || did_action('elementor/loaded') > 0,
        'detail' => __('Builder document structure and page settings are preserved for the target draft.', 'yablonsky-multilingual'),
      ],
      'divi' => [
        'label' => 'Divi',
        'active' => defined('ET_CORE_VERSION') || defined('ET_BUILDER_VERSION'),
        'detail' => __('Builder mode and page settings are preserved; translated content stays in the target WordPress object.', 'yablonsky-multilingual'),
      ],
      'beaver' => [
        'label' => 'Beaver Builder',
        'active' => class_exists('FLBuilder'),
        'detail' => __('Builder state is copied only to the explicit translation draft.', 'yablonsky-multilingual'),
      ],
      'bricks' => [
        'label' => 'Bricks',
        'active' => defined('BRICKS_VERSION') || class_exists('Bricks\\Database'),
        'detail' => __('Builder document metadata is preserved with a review marker on the translated draft.', 'yablonsky-multilingual'),
      ],
    ];
  }

  /**
   * @param int $source_id
   * @param int $target_id
   * @param string $origin manual|ai|job
   */
  public static function initialize_translation_draft(int $source_id, int $target_id, string $origin = 'manual'): void {
    if ($source_id <= 0 || $target_id <= 0 || $source_id === $target_id) return;
    $source = get_post($source_id);
    $target = get_post($target_id);
    if (!$source instanceof \WP_Post || !$target instanceof \WP_Post) return;

    $meta_keys = self::structure_meta_keys($source_id);
    $copied = [];
    foreach ($meta_keys as $meta_key) {
      $values = get_post_meta($source_id, $meta_key, false);
      if (!$values) continue;
      delete_post_meta($target_id, $meta_key);
      foreach ($values as $value) add_post_meta($target_id, $meta_key, $value);
      $copied[] = $meta_key;
    }

    if ($copied) {
      update_post_meta($target_id, self::META_REVIEW, 1);
      update_post_meta($target_id, self::META_SOURCE, [
        'source_id' => $source_id,
        'origin' => sanitize_key($origin),
        'keys' => array_values($copied),
        'created_at' => gmdate('c'),
      ]);
    }
  }

  /** @return string[] */
  private static function structure_meta_keys(int $post_id): array {
    $keys = [];
    // Elementor document + page settings.
    foreach (['_elementor_edit_mode','_elementor_template_type','_elementor_version','_elementor_page_settings','_elementor_data'] as $key) {
      if (metadata_exists('post', $post_id, $key)) $keys[] = $key;
    }
    // Divi / Elegant Themes page-level builder state. Main layout normally lives in post_content.
    foreach (['_et_pb_use_builder','_et_pb_page_layout','_et_pb_side_nav','_et_pb_post_hide_nav','_et_pb_old_content'] as $key) {
      if (metadata_exists('post', $post_id, $key)) $keys[] = $key;
    }
    // Beaver Builder stores the layout graph in post meta.
    foreach (['_fl_builder_enabled','_fl_builder_data','_fl_builder_draft'] as $key) {
      if (metadata_exists('post', $post_id, $key)) $keys[] = $key;
    }
    // Bricks document + page settings.
    foreach (['_bricks_page_content_2','_bricks_page_settings','_bricks_template_type'] as $key) {
      if (metadata_exists('post', $post_id, $key)) $keys[] = $key;
    }
    return array_values(array_unique($keys));
  }

  public static function render_status_panel(): void {
    if (!current_user_can('manage_options')) return;
    $editors = self::detected_editors();
    $active = array_filter($editors, static function(array $row): bool { return !empty($row['active']); });
    echo '<section class="ypm-card ypm-card--standalone"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Editor compatibility', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Native editor & builder handoff', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('YMulti keeps translated content as native WordPress objects. When an editor stores layout structure outside post_content, the target draft receives the structure and a review marker instead of losing the builder document.', 'yablonsky-multilingual') . '</p></div>' . wp_kses_post(Admin::status_badge('pass', sprintf(
      /* translators: %d: number of detected editor systems. */
      __('%d detected', 'yablonsky-multilingual'),
      count($active)
    ))) . '</div><div class="ypm-coverage-grid">';
    foreach ($editors as $row) {
      $state = !empty($row['active']) ? 'pass' : 'neutral';
      echo '<div class="ypm-coverage-row"><div><strong>' . esc_html((string) $row['label']) . '</strong><small>' . esc_html((string) $row['detail']) . '</small></div>' . wp_kses_post(Admin::status_badge($state, !empty($row['active']) ? __('Detected', 'yablonsky-multilingual') : __('Detection pending', 'yablonsky-multilingual'))) . '</div>';
    }
    echo '</div></section>';
  }
}
