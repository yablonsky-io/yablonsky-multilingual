<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Source-drift evidence for translated WordPress objects. */
final class TranslationFreshness {
  public const META_SOURCE_HASH = '_ymulti_source_hash_at_translation';
  public const META_SOURCE_ID = '_ymulti_source_id_at_translation';
  private static bool $saving = false;

  public static function init(): void {
    add_action('save_post', [__CLASS__, 'on_save_post'], 120, 3);
    add_action('yablonsky_multilingual_translation_draft_initialized', [__CLASS__, 'record_source'], 5, 3);
  }

  public static function record_source(int $source_id, int $target_id, string $origin = ''): void {
    unset($origin);
    if ($source_id <= 0 || $target_id <= 0 || $source_id === $target_id) return;
    if (!(get_post($source_id) instanceof \WP_Post) || !(get_post($target_id) instanceof \WP_Post)) return;
    update_post_meta($target_id, self::META_SOURCE_ID, $source_id);
    $hash = self::source_hash($source_id);
    if ($hash !== '') update_post_meta($target_id, self::META_SOURCE_HASH, $hash);
  }

  public static function on_save_post(int $post_id, \WP_Post $post, bool $update): void {
    unset($update);
    if (self::$saving || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
    if (!in_array((string) $post->post_type, Languages::supported_post_types(), true)) return;
    $source_id = self::source_id($post_id);
    if ($source_id <= 0 || $source_id === $post_id) return;
    $hash = self::source_hash($source_id);
    if ($hash === '') return;
    self::$saving = true;
    update_post_meta($post_id, self::META_SOURCE_ID, $source_id);
    update_post_meta($post_id, self::META_SOURCE_HASH, $hash);
    self::$saving = false;
  }

  public static function source_id(int $post_id): int {
    if ($post_id <= 0) return 0;
    $persisted = absint(get_post_meta($post_id, self::META_SOURCE_ID, true));
    return EntityContract::canonical_post_source_id($post_id, $persisted);
  }

  public static function source_hash(int $source_id): string {
    $post = get_post($source_id);
    if (!($post instanceof \WP_Post)) return '';
    return hash('sha256', wp_json_encode([
      'title' => (string) $post->post_title,
      'excerpt' => (string) $post->post_excerpt,
      'content' => (string) $post->post_content,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }

  /** @return array{state:string,label:string,detail:string} */
  public static function status(int $target_id): array {
    $source_id = self::source_id($target_id);
    if ($source_id <= 0 || $source_id === $target_id) return ['state'=>'neutral','label'=>__('Source', 'yablonsky-multilingual'),'detail'=>''];
    $current = self::source_hash($source_id);
    $stored = (string) get_post_meta($target_id, self::META_SOURCE_HASH, true);
    if ($stored === '') return ['state'=>'neutral','label'=>__('Tracking ready', 'yablonsky-multilingual'),'detail'=>__('Save the translation once to establish its source baseline.', 'yablonsky-multilingual')];
    if ($current !== '' && hash_equals($stored, $current)) return ['state'=>'pass','label'=>__('Up to date', 'yablonsky-multilingual'),'detail'=>__('The source content still matches the baseline used by this translation.', 'yablonsky-multilingual')];
    return ['state'=>'attention','label'=>__('Source changed', 'yablonsky-multilingual'),'detail'=>__('The source title, excerpt or content changed after this translation was last saved. Review before treating it as current.', 'yablonsky-multilingual')];
  }
}
