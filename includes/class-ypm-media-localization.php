<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Human-approved locale-specific media text sidecar. Native attachment fields remain untouched. */
final class MediaLocalization {
  public const META = '_ypm_localized_media_text_v1';
  public const META_GENERATED = '_ypm_ai_generated_media_v1';
  public const META_ASSET = '_ymulti_localized_media_asset_v1';
  public const META_AI_SUGGESTION = '_ymulti_ai_media_text_suggestion_v1';
  public const GENERATED_ATTACHMENT_META = '_ypm_ai_generated_media_provenance_v1';
  private const MAX_GENERATED_IMAGE_BYTES = 12582912;

  public static function init(): void {
    add_filter('wp_get_attachment_image_attributes', [__CLASS__, 'filter_image_attributes'], 20, 3);
    add_filter('wp_get_attachment_caption', [__CLASS__, 'filter_caption'], 20, 2);
    add_filter('the_title', [__CLASS__, 'filter_attachment_title'], 20, 2);
    add_filter('the_content', [__CLASS__, 'filter_attachment_description'], 20);
    add_filter('render_block_core/image', [__CLASS__, 'filter_image_block'], 20, 2);
    if (is_admin()) {
      add_action('admin_post_ypm_media_localization_apply', [__CLASS__, 'handle_apply']);
      add_action('admin_post_ypm_media_localization_clear', [__CLASS__, 'handle_clear']);
      add_action('admin_post_ymulti_media_promote_generated', [__CLASS__, 'handle_promote_generated']);
    }
  }

  /** @return array{title:string,alt:string,caption:string,description:string,source_post_id:int,accepted_at:string} */
  public static function record(int $attachment_id, string $locale): array {
    $empty = ['title'=>'','alt'=>'','caption'=>'','description'=>'','source_post_id'=>0,'accepted_at'=>''];
    $raw = EntityContract::merged_post_family_meta($attachment_id, self::META);
    if (is_array($raw) && isset($raw[$locale]) && is_array($raw[$locale])) {
      $row = $raw[$locale];
      return [
        'title' => sanitize_text_field((string) ($row['title'] ?? '')),
        'alt' => sanitize_text_field((string) ($row['alt'] ?? '')),
        'caption' => sanitize_textarea_field((string) ($row['caption'] ?? '')),
        'description' => sanitize_textarea_field((string) ($row['description'] ?? '')),
        'source_post_id' => absint($row['source_post_id'] ?? 0),
        'accepted_at' => sanitize_text_field((string) ($row['accepted_at'] ?? '')),
      ];
    }

    // Compatibility/migration fallback: incumbent multilingual plugins commonly
    // use a real translated attachment object per locale. Read that object's
    // native media fields when no YMulti sidecar overrides them. Once migration
    // materializes the same family IDs this fallback keeps working without WPML.
    if ($attachment_id > 0 && Languages::is_active($locale) && class_exists('Yablonsky\\Multilingual\\EffectiveRelations', false)) {
      $target_id = EffectiveRelations::existing_target($attachment_id, $locale);
      if ($target_id > 0 && $target_id !== $attachment_id && get_post_type($target_id) === 'attachment') {
        $target = get_post($target_id);
        if ($target instanceof \WP_Post) {
          return [
            'title' => sanitize_text_field((string) $target->post_title),
            'alt' => sanitize_text_field((string) get_post_meta($target_id, '_wp_attachment_image_alt', true)),
            'caption' => sanitize_textarea_field((string) $target->post_excerpt),
            'description' => sanitize_textarea_field((string) $target->post_content),
            'source_post_id' => $target_id,
            'accepted_at' => '',
          ];
        }
      }
    }
    return $empty;
  }


/** @return array{title:string,alt:string,caption:string,description:string,model:string,cost:mixed,suggested_at:string} */
public static function ai_suggestion(int $attachment_id, string $locale): array {
  $raw = EntityContract::merged_post_family_meta($attachment_id, self::META_AI_SUGGESTION);
  if (!is_array($raw) || !isset($raw[$locale]) || !is_array($raw[$locale])) return ['title'=>'','alt'=>'','caption'=>'','description'=>'','model'=>'','cost'=>null,'suggested_at'=>''];
  $row = $raw[$locale];
  return [
    'title' => sanitize_text_field((string)($row['title'] ?? '')),
    'alt' => sanitize_text_field((string)($row['alt'] ?? '')),
    'caption' => sanitize_textarea_field((string)($row['caption'] ?? '')),
    'description' => sanitize_textarea_field((string)($row['description'] ?? '')),
    'model' => sanitize_text_field((string)($row['model'] ?? '')),
    'cost' => is_numeric($row['cost'] ?? null) ? (float)$row['cost'] : null,
    'suggested_at' => sanitize_text_field((string)($row['suggested_at'] ?? '')),
  ];
}

/** @param array<string,mixed> $analysis */
public static function save_ai_suggestion(int $attachment_id, string $locale, array $analysis): bool {
  if ($attachment_id <= 0 || !EntityContract::post_target_allowed($attachment_id, $locale)) return false;
  $raw = EntityContract::merged_post_family_meta($attachment_id, self::META_AI_SUGGESTION);
  if (!is_array($raw)) $raw = [];
  $raw[$locale] = [
    'title' => sanitize_text_field((string)($analysis['suggested_title'] ?? '')),
    'alt' => sanitize_text_field((string)($analysis['suggested_alt'] ?? '')),
    'caption' => sanitize_textarea_field((string)($analysis['suggested_caption'] ?? '')),
    'description' => sanitize_textarea_field((string)($analysis['suggested_description'] ?? '')),
    'model' => sanitize_text_field((string)($analysis['model'] ?? '')),
    'cost' => is_numeric($analysis['cost'] ?? null) ? (float)$analysis['cost'] : null,
    'suggested_at' => gmdate('c'),
  ];
  return EntityContract::write_post_family_meta($attachment_id, self::META_AI_SUGGESTION, $raw);
}

public static function clear_ai_suggestion(int $attachment_id, string $locale): void {
  $raw = EntityContract::merged_post_family_meta($attachment_id, self::META_AI_SUGGESTION);
  if (!is_array($raw) || !isset($raw[$locale])) return;
  unset($raw[$locale]);
  EntityContract::write_post_family_meta($attachment_id, self::META_AI_SUGGESTION, $raw);
}

  public static function filter_image_attributes(array $attr, \WP_Post $attachment, $size): array {
    if (is_admin() && !wp_doing_ajax()) return $attr;
    $locale = Router::current_lang();
    $source_id = (int) $attachment->ID;
    if (!Languages::is_active($locale) || EntityContract::post_source_locale($source_id) === $locale) return $attr;
    $alternate_id = self::alternate_attachment_id($source_id, $locale);
    if ($alternate_id > 0) {
      $image = wp_get_attachment_image_src($alternate_id, $size, false);
      if (is_array($image) && !empty($image[0])) {
        $attr['src'] = (string) $image[0];
        if (!empty($image[1])) $attr['width'] = (string) (int) $image[1];
        if (!empty($image[2])) $attr['height'] = (string) (int) $image[2];
        $srcset = wp_get_attachment_image_srcset($alternate_id, $size);
        if (is_string($srcset) && $srcset !== '') $attr['srcset'] = $srcset; else unset($attr['srcset']);
        $sizes = wp_get_attachment_image_sizes($alternate_id, $size);
        if (is_string($sizes) && $sizes !== '') $attr['sizes'] = $sizes;
      }
    }
    $record = self::record($source_id, $locale);
    if ($record['alt'] !== '') $attr['alt'] = $record['alt'];
    elseif ($alternate_id > 0) {
      $alternate_alt = sanitize_text_field((string) get_post_meta($alternate_id, '_wp_attachment_image_alt', true));
      if ($alternate_alt !== '') $attr['alt'] = $alternate_alt;
    }
    return $attr;
  }

  public static function filter_image_block(string $block_content, array $block): string {
    if (is_admin() || $block_content === '' || empty($block['attrs']['id'])) return $block_content;
    $source_id = absint($block['attrs']['id']);
    if ($source_id <= 0) return $block_content;
    $locale = Router::current_lang();
    if (!Languages::is_active($locale) || EntityContract::post_source_locale($source_id) === $locale) return $block_content;
    $alternate_id = self::alternate_attachment_id($source_id, $locale);
    if ($alternate_id > 0) {
      $source_url = wp_get_attachment_url($source_id);
      $alternate_url = wp_get_attachment_url($alternate_id);
      if (is_string($source_url) && $source_url !== '' && is_string($alternate_url) && $alternate_url !== '') $block_content = str_replace($source_url, $alternate_url, $block_content);
      $block_content = preg_replace('/\\bwp-image-' . preg_quote((string) $source_id, '/') . '\\b/', 'wp-image-' . $alternate_id, $block_content) ?: $block_content;
    }
    $record = self::record($source_id, $locale);
    if ($record['alt'] !== '') {
      $localized_alt = esc_attr($record['alt']);
      $replaced = preg_replace_callback('/(<img\\b[^>]*\\balt=["\\\'])[^"\\\']*(["\\\'])/i', static function(array $matches) use ($localized_alt): string { return (string) $matches[1] . $localized_alt . (string) $matches[2]; }, $block_content, 1);
      if (is_string($replaced) && $replaced !== '') $block_content = $replaced;
    }
    return $block_content;
  }

  /** @return array<int,string> */
  public static function localized_locales(int $attachment_id): array {
    if ($attachment_id <= 0) return [];
    $active = array_fill_keys(Languages::active_codes(), true);
    $source = RuntimeAuthority::effective_post_language($attachment_id);
    if ($source === '') $source = Languages::default_code();
    unset($active[$source]);
    $found = [];

    if (class_exists('Yablonsky\\Multilingual\\EffectiveRelations', false)) {
      $family = EffectiveRelations::post_family($attachment_id, null, Languages::active_codes());
      foreach (array_keys((array) ($family['translations'] ?? [])) as $code) {
        if (isset($active[$code])) $found[$code] = true;
      }
    }

    $text = EntityContract::merged_post_family_meta($attachment_id, self::META);
    if (is_array($text)) foreach ($text as $code => $row) {
      if (!isset($active[$code]) || !is_array($row)) continue;
      if (sanitize_text_field((string)($row['title'] ?? '')) !== '' || sanitize_text_field((string)($row['alt'] ?? '')) !== '' || sanitize_textarea_field((string)($row['caption'] ?? '')) !== '' || sanitize_textarea_field((string)($row['description'] ?? '')) !== '') $found[$code] = true;
    }
    $assets = EntityContract::merged_post_family_meta($attachment_id, self::META_ASSET);
    if (is_array($assets)) foreach ($assets as $code => $candidate) if (isset($active[$code]) && absint($candidate) > 0) $found[$code] = true;
    return array_values(array_keys($found));
  }

  /** @return string[] */
  public static function coverage_locales(int $attachment_id): array {
    if ($attachment_id <= 0) return [];
    $active = Languages::active_codes();
    $found = [];
    $source = RuntimeAuthority::effective_post_language($attachment_id);
    if ($source === '') $source = Languages::default_code();
    if (in_array($source, $active, true)) $found[$source] = true;
    foreach (self::localized_locales($attachment_id) as $code) if (in_array($code, $active, true)) $found[$code] = true;
    return array_values(array_keys($found));
  }

  public static function alternate_attachment_id(int $attachment_id, string $locale): int {
    if ($attachment_id <= 0 || !Languages::is_active($locale)) return 0;
    $all = EntityContract::merged_post_family_meta($attachment_id, self::META_ASSET);
    if (is_array($all)) {
      $candidate = absint($all[$locale] ?? 0);
      if ($candidate > 0 && get_post_type($candidate) === 'attachment' && strpos((string) get_post_mime_type($candidate), 'image/') === 0) return $candidate;
    }

    if (class_exists('Yablonsky\\Multilingual\\EffectiveRelations', false)) {
      $candidate = EffectiveRelations::existing_target($attachment_id, $locale);
      if ($candidate > 0 && $candidate !== $attachment_id && get_post_type($candidate) === 'attachment' && strpos((string) get_post_mime_type($candidate), 'image/') === 0) return $candidate;
    }
    return 0;
  }

  public static function save_manual(int $attachment_id, string $locale, string $alt, string $caption, int $alternate_id = 0): bool {
    $existing = self::record($attachment_id, $locale);
    return self::save_full($attachment_id, $locale, $existing['title'], $alt, $caption, $existing['description'], $alternate_id);
  }

  public static function save_full(int $attachment_id, string $locale, string $title, string $alt, string $caption, string $description, int $alternate_id = 0): bool {
    if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment' || !EntityContract::post_target_allowed($attachment_id, $locale)) return false;
    $owner = EntityContract::post_sidecar_owner_id($attachment_id);
    if ($owner <= 0) return false;
    $title = sanitize_text_field($title);
    $alt = sanitize_text_field($alt);
    $caption = sanitize_textarea_field($caption);
    $description = sanitize_textarea_field($description);
    $all = EntityContract::merged_post_family_meta($attachment_id, self::META);
    if (!is_array($all)) $all = [];
    if ($title === '' && $alt === '' && $caption === '' && $description === '') {
      unset($all[$locale]);
    } else {
      $all[$locale] = [
        'title'=>$title,
        'alt'=>$alt,
        'caption'=>$caption,
        'description'=>$description,
        'source_post_id'=>0,
        'accepted_at'=>gmdate('c'),
      ];
    }
    EntityContract::write_post_family_meta($attachment_id, self::META, $all);
    $assets = EntityContract::merged_post_family_meta($attachment_id, self::META_ASSET);
    if (!is_array($assets)) $assets = [];
    if ($alternate_id > 0 && get_post_type($alternate_id) === 'attachment' && strpos((string) get_post_mime_type($alternate_id), 'image/') === 0 && $alternate_id !== $attachment_id) $assets[$locale] = $alternate_id; else unset($assets[$locale]);
    EntityContract::write_post_family_meta($attachment_id, self::META_ASSET, $assets);
    if (class_exists('Yablonsky\Multilingual\SiteCoverage', false)) SiteCoverage::reset();
    return true;
  }

  public static function filter_caption(string $caption, int $post_id): string {
    if (is_admin() && !wp_doing_ajax()) return $caption;
    $locale = Router::current_lang();
    if (!Languages::is_active($locale) || EntityContract::post_source_locale($post_id) === $locale) return $caption;
    $record = self::record($post_id, $locale);
    return $record['caption'] !== '' ? $record['caption'] : $caption;
  }


  public static function filter_attachment_title(string $title, int $post_id = 0): string {
    if ($post_id <= 0 || get_post_type($post_id) !== 'attachment' || (is_admin() && !wp_doing_ajax())) return $title;
    $locale = Router::current_lang();
    if (!Languages::is_active($locale) || EntityContract::post_source_locale($post_id) === $locale) return $title;
    $record = self::record($post_id, $locale);
    return $record['title'] !== '' ? $record['title'] : $title;
  }

  public static function filter_attachment_description(string $content): string {
    if (is_admin() || !is_attachment()) return $content;
    $post_id = get_queried_object_id();
    if ($post_id <= 0 || get_post_type($post_id) !== 'attachment') return $content;
    $locale = Router::current_lang();
    if (!Languages::is_active($locale) || EntityContract::post_source_locale($post_id) === $locale) return $content;
    $record = self::record($post_id, $locale);
    return $record['description'] !== '' ? wpautop(esc_html($record['description'])) : $content;
  }

  /** @param array<string,mixed> $review */
  public static function render_review_action(int $source_post_id, int $attachment_id, string $target_locale, array $review): void {
    if ($source_post_id <= 0 || $attachment_id <= 0 || !Languages::is_active($target_locale) || !current_user_can('edit_post', $source_post_id)) return;
    $title = sanitize_text_field((string) ($review['suggested_title'] ?? ''));
    $alt = sanitize_text_field((string) ($review['suggested_alt'] ?? ''));
    $caption = sanitize_textarea_field((string) ($review['suggested_caption'] ?? ''));
    $description = sanitize_textarea_field((string) ($review['suggested_description'] ?? ''));
    if ($title === '' && $alt === '' && $caption === '' && $description === '') return;
    $record = self::record($attachment_id, $target_locale);
    $applied = ($title === '' || hash_equals($record['title'], $title)) && ($alt === '' || hash_equals($record['alt'], $alt)) && ($caption === '' || hash_equals($record['caption'], $caption)) && ($description === '' || hash_equals($record['description'], $description)) && $record['accepted_at'] !== '';
    if ($applied) {
      echo wp_kses_post(Admin::status_badge('pass', __('Localized media text applied', 'yablonsky-multilingual')));
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('ypm_media_localization_clear_' . $source_post_id . '_' . $attachment_id . '_' . $target_locale, 'ypm_media_localization_nonce');
      echo '<input type="hidden" name="action" value="ypm_media_localization_clear"><input type="hidden" name="post_id" value="' . esc_attr((string) $source_post_id) . '"><input type="hidden" name="attachment_id" value="' . esc_attr((string) $attachment_id) . '"><input type="hidden" name="target_locale" value="' . esc_attr($target_locale) . '"><button class="button">' . esc_html__('Use native media text', 'yablonsky-multilingual') . '</button></form>';
      return;
    }
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('ypm_media_localization_apply_' . $source_post_id . '_' . $attachment_id . '_' . $target_locale, 'ypm_media_localization_nonce');
    echo '<input type="hidden" name="action" value="ypm_media_localization_apply"><input type="hidden" name="post_id" value="' . esc_attr((string) $source_post_id) . '"><input type="hidden" name="attachment_id" value="' . esc_attr((string) $attachment_id) . '"><input type="hidden" name="target_locale" value="' . esc_attr($target_locale) . '"><button class="button button-primary">' . esc_html__('Apply suggested media text', 'yablonsky-multilingual') . '</button></form>';
  }

  /** @return array<string,mixed> */
  public static function generated_record(int $source_post_id, int $attachment_id, string $locale): array {
    $all = get_post_meta($source_post_id, self::META_GENERATED, true);
    if (!is_array($all)) return [];
    $key = $attachment_id . '|' . $locale;
    $row = isset($all[$key]) && is_array($all[$key]) ? $all[$key] : [];
    if (!$row) return [];
    $generated_id = absint($row['generated_attachment_id'] ?? 0);
    if ($generated_id <= 0 || get_post_type($generated_id) !== 'attachment') return [];
    $row['generated_attachment_id'] = $generated_id;
    return $row;
  }

  /** @return array<string,mixed>|\WP_Error */
  public static function generate_asset_draft(int $source_post_id, int $attachment_id, string $locale) {
    if ($source_post_id <= 0 || $attachment_id <= 0 || !Languages::is_active($locale)) return new \WP_Error('ypm_media_generate_context', __('Invalid localized image-generation context.', 'yablonsky-multilingual'));
    if (!current_user_can('edit_post', $source_post_id) || !current_user_can('edit_post', $attachment_id) || !AITranslation::current_user_can_compute()) return new \WP_Error('ypm_media_generate_forbidden', __('Localized media draft generation requires AI media permission.', 'yablonsky-multilingual'));

    $provenance = get_post_meta($source_post_id, AITranslation::META_PROVENANCE, true);
    if (!is_array($provenance) || sanitize_text_field((string) ($provenance['target_locale'] ?? '')) !== $locale) return new \WP_Error('ypm_media_generate_provenance', __('Translation-draft target locale changed. Reopen the media workflow from the current draft.', 'yablonsky-multilingual'));
    $ids = isset($provenance['structure']['media']['attachment_ids']) && is_array($provenance['structure']['media']['attachment_ids']) ? array_values(array_unique(array_filter(array_map('absint', $provenance['structure']['media']['attachment_ids'])))) : [];
    if (!in_array($attachment_id, $ids, true)) return new \WP_Error('ypm_media_generate_inventory', __('Choose an image from the protected translation-draft media inventory.', 'yablonsky-multilingual'));

    $reviews = get_post_meta($source_post_id, AITranslation::META_MEDIA_REVIEW, true);
    $review = is_array($reviews) && isset($reviews[(string) $attachment_id]) && is_array($reviews[(string) $attachment_id]) ? $reviews[(string) $attachment_id] : [];
    if (!$review) return new \WP_Error('ypm_media_generate_review', __('Run Vision review before generating a localized image draft.', 'yablonsky-multilingual'));
    $stored_modified = sanitize_text_field((string) ($review['attachment_modified_gmt'] ?? ''));
    $current_modified = sanitize_text_field((string) get_post_field('post_modified_gmt', $attachment_id));
    if ($stored_modified !== '' && $current_modified !== '' && !hash_equals($stored_modified, $current_modified)) return new \WP_Error('ypm_media_generate_stale', __('The source image changed after Vision review. Run the review again before generating a localized candidate.', 'yablonsky-multilingual'));

    $target_name = Languages::display_name($locale) . ' (' . Languages::display_code($locale) . ')';
    $draft_title = trim(wp_strip_all_tags((string) get_the_title($source_post_id), true));
    $visible = isset($review['visible_text']) && is_array($review['visible_text']) ? array_slice(array_values(array_filter(array_map('sanitize_text_field', $review['visible_text']))), 0, 8) : [];
    $alt = sanitize_text_field((string) ($review['suggested_alt'] ?? ''));
    $caption = sanitize_textarea_field((string) ($review['suggested_caption'] ?? ''));
    $notes = sanitize_textarea_field((string) ($review['notes'] ?? ''));
    $prompt = "Create a high-quality localized editorial image candidate for a WordPress translation draft. Target locale: {$target_name}. ";
    $prompt .= "Preserve the subject and meaning implied by the reviewed source image, but do not copy logos, watermarks, UI chrome or copyrighted branding. ";
    $prompt .= "If text must appear inside the image, use only natural {$target_name} text and keep it minimal. The result is a review candidate and must not claim facts not present in the supplied context. ";
    if ($draft_title !== '') $prompt .= 'Page title: ' . $draft_title . '. ';
    if ($visible) $prompt .= 'Source image visible text detected by Vision: ' . implode(' | ', $visible) . '. ';
    if ($alt !== '') $prompt .= 'Localized alt intent: ' . $alt . '. ';
    if ($caption !== '') $prompt .= 'Localized caption intent: ' . $caption . '. ';
    if ($notes !== '') $prompt .= 'Vision review context: ' . $notes . '. ';
    $prompt .= 'Prefer a clean 16:9 composition with a clear focal subject. Do not include a watermark.';

    $result = AITranslation::generate_image($prompt, $locale, '16:9');
    if (is_wp_error($result)) return $result;
    $generated_id = self::copy_generated_image_to_media_library($result, $source_post_id, $attachment_id, $locale, $alt);
    if (is_wp_error($generated_id)) return $generated_id;

    $record = [
      'source_attachment_id' => $attachment_id,
      'generated_attachment_id' => (int) $generated_id,
      'locale' => $locale,
      'model' => sanitize_text_field((string) ($result['model'] ?? '')),
      'cost' => is_numeric($result['cost'] ?? null) ? (float) $result['cost'] : null,
      'remote_resource_id' => sanitize_text_field((string) ($result['id'] ?? '')),
      'generated_at' => gmdate('c'),
      'source_modified_gmt' => $current_modified,
      'status' => 'review',
    ];
    $all = get_post_meta($source_post_id, self::META_GENERATED, true);
    if (!is_array($all)) $all = [];
    $all[$attachment_id . '|' . $locale] = $record;
    update_post_meta($source_post_id, self::META_GENERATED, $all);
    update_post_meta((int) $generated_id, self::GENERATED_ATTACHMENT_META, [
      'source_post_id' => $source_post_id,
      'source_attachment_id' => $attachment_id,
      'locale' => $locale,
      'model' => $record['model'],
      'remote_resource_id' => $record['remote_resource_id'],
      'generated_at' => $record['generated_at'],
      'status' => 'review',
    ]);
    return $record;
  }

  /** @param array<string,mixed> $result @return int|\WP_Error */
  private static function copy_generated_image_to_media_library(array $result, int $source_post_id, int $source_attachment_id, string $locale, string $alt) {
    $source_url = esc_url_raw((string) ($result['url'] ?? ''), ['https']);
    if ($source_url === '' || strpos(strtolower($source_url), 'https://') !== 0) return new \WP_Error('ypm_media_generate_url', __('The generated image URL requires a safe HTTPS URL.', 'yablonsky-multilingual'));
    $tmp = wp_tempnam('ymulti-ai-image-' . $source_post_id);
    if (!is_string($tmp) || $tmp === '') return new \WP_Error('ypm_media_generate_temp', __('Generated-image promotion requires available WordPress temporary storage.', 'yablonsky-multilingual'));
    $response = wp_safe_remote_get($source_url, [
      'timeout' => 60,
      'redirection' => 2,
      'sslverify' => true,
      'stream' => true,
      'filename' => $tmp,
      'limit_response_size' => self::MAX_GENERATED_IMAGE_BYTES,
    ]);
    if (is_wp_error($response)) {
      wp_delete_file($tmp);
      return new \WP_Error('ypm_media_generate_download', __('Generated-image promotion requires a successful copy from Yablonsky AI.', 'yablonsky-multilingual'));
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $content_type = strtolower(trim(explode(';', (string) wp_remote_retrieve_header($response, 'content-type'))[0]));
    $size = file_exists($tmp) ? (int) filesize($tmp) : 0;
    if ($code < 200 || $code >= 300 || $size <= 0 || $size > self::MAX_GENERATED_IMAGE_BYTES || strpos($content_type, 'image/') !== 0) {
      wp_delete_file($tmp);
      return new \WP_Error('ypm_media_generate_validation', __('The generated image did not pass Media Library validation. Choose another image model or revise the generation request, then try again.', 'yablonsky-multilingual'));
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $path = (string) wp_parse_url($source_url, PHP_URL_PATH);
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime_extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif'];
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'], true)) $extension = (string) ($mime_extensions[$content_type] ?? 'webp');
    $title = sanitize_title((string) get_the_title($source_post_id));
    if ($title === '') $title = 'localized-image-' . $source_post_id;
    $file = ['name' => $title . '-' . sanitize_key($locale) . '-review.' . $extension, 'tmp_name' => $tmp];
    $generated_id = media_handle_sideload($file, $source_post_id, wp_strip_all_tags((string) get_the_title($source_post_id), true));
    if (is_wp_error($generated_id)) {
      wp_delete_file($tmp);
      return new \WP_Error('ypm_media_generate_sideload', __('WordPress rejected the generated image while adding it to the Media Library.', 'yablonsky-multilingual'));
    }
    $generated_id = (int) $generated_id;
    if ($alt !== '') update_post_meta($generated_id, '_wp_attachment_image_alt', $alt);
    return $generated_id;
  }

  public static function handle_apply(): void {
    [$post_id, $attachment_id, $locale] = self::request_context('apply');
    $provenance = get_post_meta($post_id, AITranslation::META_PROVENANCE, true);
    if (!is_array($provenance) || sanitize_text_field((string) ($provenance['target_locale'] ?? '')) !== $locale) wp_die(esc_html__('Media localization context changed. Reopen the current translation draft.', 'yablonsky-multilingual'));
    $ids = isset($provenance['structure']['media']['attachment_ids']) && is_array($provenance['structure']['media']['attachment_ids']) ? array_values(array_unique(array_filter(array_map('absint', $provenance['structure']['media']['attachment_ids'])))) : [];
    if (!in_array($attachment_id, $ids, true)) wp_die(esc_html__('Choose an attachment from the translation-draft media inventory.', 'yablonsky-multilingual'));
    $reviews = get_post_meta($post_id, AITranslation::META_MEDIA_REVIEW, true);
    $review = is_array($reviews) && isset($reviews[(string) $attachment_id]) && is_array($reviews[(string) $attachment_id]) ? $reviews[(string) $attachment_id] : [];
    if (!$review) wp_die(esc_html__('Run Vision review before applying localized media text.', 'yablonsky-multilingual'));
    $stored_modified = sanitize_text_field((string) ($review['attachment_modified_gmt'] ?? ''));
    $current_modified = sanitize_text_field((string) get_post_field('post_modified_gmt', $attachment_id));
    if ($stored_modified !== '' && $current_modified !== '' && !hash_equals($stored_modified, $current_modified)) wp_die(esc_html__('The attachment changed after Vision review. Run the review again.', 'yablonsky-multilingual'));
    $title = sanitize_text_field((string) ($review['suggested_title'] ?? ''));
    $alt = sanitize_text_field((string) ($review['suggested_alt'] ?? ''));
    $caption = sanitize_textarea_field((string) ($review['suggested_caption'] ?? ''));
    $description = sanitize_textarea_field((string) ($review['suggested_description'] ?? ''));
    if ($title === '' && $alt === '' && $caption === '' && $description === '') wp_die(esc_html__('Vision review media text: 0 fields ready to apply.', 'yablonsky-multilingual'));

    $all = EntityContract::merged_post_family_meta($attachment_id, self::META);
    if (!is_array($all)) $all = [];
    $all[$locale] = ['title' => $title, 'alt' => $alt, 'caption' => $caption, 'description' => $description, 'source_post_id' => $post_id, 'accepted_at' => gmdate('c')];
    $owner = EntityContract::post_sidecar_owner_id($attachment_id);
    if ($owner <= 0) wp_die(esc_html__('Invalid media localization request.', 'yablonsky-multilingual'));
    EntityContract::write_post_family_meta($attachment_id, self::META, $all);
    if (class_exists('Yablonsky\Multilingual\SiteCoverage', false)) SiteCoverage::reset();
    self::redirect($post_id);
  }

  public static function handle_clear(): void {
    [$post_id, $attachment_id, $locale] = self::request_context('clear');
    $all = EntityContract::merged_post_family_meta($attachment_id, self::META);
    if (is_array($all) && isset($all[$locale])) {
      unset($all[$locale]);
      EntityContract::write_post_family_meta($attachment_id, self::META, $all);
    }
    if (class_exists('Yablonsky\Multilingual\SiteCoverage', false)) SiteCoverage::reset();
    self::redirect($post_id);
  }

  public static function handle_promote_generated(): void {
    if (!current_user_can('upload_files')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    $post_raw = filter_input(INPUT_POST, 'post_id', FILTER_VALIDATE_INT);
    $attachment_raw = filter_input(INPUT_POST, 'attachment_id', FILTER_VALIDATE_INT);
    $locale_raw = filter_input(INPUT_POST, 'target_locale', FILTER_UNSAFE_RAW);
    $post_id = $post_raw ? (int) $post_raw : 0; $attachment_id = $attachment_raw ? (int) $attachment_raw : 0; $locale = is_string($locale_raw) ? sanitize_text_field($locale_raw) : '';
    if ($post_id <= 0 || $attachment_id <= 0 || !current_user_can('edit_post', $post_id) || !current_user_can('edit_post', $attachment_id) || !EntityContract::post_target_allowed($attachment_id, $locale)) wp_die(esc_html__('Invalid generated-media promotion request.', 'yablonsky-multilingual'));
    check_admin_referer('ymulti_media_promote_generated_' . $post_id . '_' . $attachment_id . '_' . $locale, 'ymulti_media_nonce');
    $generated = self::generated_record($post_id, $attachment_id, $locale); $generated_id = absint($generated['generated_attachment_id'] ?? 0);
    if ($generated_id <= 0) wp_die(esc_html__('Generate or choose a valid image candidate for this locale.', 'yablonsky-multilingual'));
    $record = self::record($attachment_id, $locale);
    if (!self::save_full($attachment_id, $locale, $record['title'], $record['alt'], $record['caption'], $record['description'], $generated_id)) wp_die(esc_html__('Generated candidate promotion needs attention. Verify the candidate and local storage, then retry.', 'yablonsky-multilingual'));
    $all = get_post_meta($post_id, self::META_GENERATED, true); if (is_array($all)) { $key=$attachment_id.'|'.$locale; if(isset($all[$key])&&is_array($all[$key])){$all[$key]['status']='accepted';$all[$key]['accepted_at']=gmdate('c');update_post_meta($post_id,self::META_GENERATED,$all);} }
    $prov=get_post_meta($generated_id,self::GENERATED_ATTACHMENT_META,true);if(is_array($prov)){$prov['status']='accepted';$prov['accepted_at']=gmdate('c');update_post_meta($generated_id,self::GENERATED_ATTACHMENT_META,$prov);}
    MultilingualSEOProvider::notify_invalidation(['resource_type'=>'post','reason'=>'localized_media_asset_promoted','resource_id'=>$post_id,'affected_resource_ids'=>[$post_id]]);
    self::redirect($post_id);
  }

  /** @return array{0:int,1:int,2:string} */
  private static function request_context(string $operation): array {
    $post_raw = filter_input(INPUT_POST, 'post_id', FILTER_VALIDATE_INT);
    $attachment_raw = filter_input(INPUT_POST, 'attachment_id', FILTER_VALIDATE_INT);
    $locale_raw = filter_input(INPUT_POST, 'target_locale', FILTER_UNSAFE_RAW);
    $post_id = $post_raw ? (int) $post_raw : 0;
    $attachment_id = $attachment_raw ? (int) $attachment_raw : 0;
    $locale = is_string($locale_raw) ? sanitize_text_field($locale_raw) : '';
    if ($post_id <= 0 || $attachment_id <= 0 || !Languages::is_active($locale) || !current_user_can('edit_post', $post_id) || !current_user_can('edit_post', $attachment_id)) wp_die(esc_html__('Invalid media localization request.', 'yablonsky-multilingual'));
    check_admin_referer('ypm_media_localization_' . $operation . '_' . $post_id . '_' . $attachment_id . '_' . $locale, 'ypm_media_localization_nonce');
    return [$post_id, $attachment_id, $locale];
  }

  private static function redirect(int $post_id): void {
    $url = get_edit_post_link($post_id, '');
    wp_safe_redirect($url ? $url . '#ypm-ai-media-review' : admin_url('admin.php?page=ypm-ai')); exit;
  }
}
