<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Authorized browser-side layout QA for YMulti AI translation drafts.
 *
 * The scanner runs only in a signed frontend preview opened by an editor. It
 * never changes site CSS/content and never sends layout data off-site.
 */
final class AILayoutQA {
  public const META_LAYOUT_QA = '_ypm_ai_layout_qa';
  private const QUERY_FLAG = 'ypm_layout_qa';
  private const QUERY_NONCE = 'ypm_qa_nonce';

  public static function init(): void {
    if (is_admin()) {
      add_action('wp_ajax_ypm_ai_layout_qa_save', [__CLASS__, 'save_report']);
      return;
    }

    $requested = filter_input(INPUT_GET, self::QUERY_FLAG, FILTER_VALIDATE_BOOLEAN) === true;
    if (!$requested) return;

    add_action('template_redirect', [__CLASS__, 'prepare_request'], 1);
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets'], 99);
    add_filter('body_class', [__CLASS__, 'body_class']);
  }

  public static function preview_url(int $post_id): string {
    if ($post_id <= 0 || !current_user_can('edit_post', $post_id)) return '';
    $provenance = get_post_meta($post_id, AITranslation::META_PROVENANCE, true);
    if (!is_array($provenance) || ($provenance['provider'] ?? '') !== 'yablonsky_ai') return '';
    $url = get_preview_post_link($post_id);
    if (!is_string($url) || $url === '') $url = get_permalink($post_id);
    if (!is_string($url) || $url === '') return '';
    return add_query_arg([
      self::QUERY_FLAG => '1',
      self::QUERY_NONCE => wp_create_nonce('ypm_ai_layout_qa_' . $post_id),
    ], $url);
  }

  public static function prepare_request(): void {
    if (!self::authorized_post_id()) return;
    nocache_headers();
  }

  /** @param string[] $classes @return string[] */
  public static function body_class(array $classes): array {
    if (self::authorized_post_id()) $classes[] = 'ypm-layout-qa-active';
    return $classes;
  }

  public static function enqueue_assets(): void {
    $post_id = self::authorized_post_id();
    if ($post_id <= 0) return;
    wp_enqueue_style('ypm-ai-layout-qa', YPM_ASSET_URL . 'ai-layout-qa.css', [], YPM_VERSION);
    wp_enqueue_script('ypm-ai-layout-qa', YPM_ASSET_URL . 'ai-layout-qa.js', [], YPM_VERSION, true);
    wp_localize_script('ypm-ai-layout-qa', 'ypmLayoutQA', [
      'postId' => $post_id,
      'title' => get_the_title($post_id),
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'saveNonce' => wp_create_nonce('ypm_ai_layout_qa_save_' . $post_id),
      'labels' => [
        'title' => __('YMulti Layout QA', 'yablonsky-multilingual'),
        'pass' => __('PASS', 'yablonsky-multilingual'),
        'review' => __('REVIEW', 'yablonsky-multilingual'),
        'issues' => __('layout issues', 'yablonsky-multilingual'),
        'checked' => __('text-bearing elements checked', 'yablonsky-multilingual'),
        'rescan' => __('Rescan', 'yablonsky-multilingual'),
        'copy' => __('Copy JSON', 'yablonsky-multilingual'),
        'copied' => __('Copied', 'yablonsky-multilingual'),
        'close' => __('Close panel', 'yablonsky-multilingual'),
        'next' => __('Next issue', 'yablonsky-multilingual'),
        'none' => __('Layout QA passed: clipping, fixed-width overflow and viewport escape findings are 0 at this viewport size.', 'yablonsky-multilingual'),
        'resize' => __('Resize the browser and Rescan to test another viewport.', 'yablonsky-multilingual'),
        'horizontal' => __('Horizontal text overflow', 'yablonsky-multilingual'),
        'vertical' => __('Vertical text clipping', 'yablonsky-multilingual'),
        'viewport' => __('Element escapes viewport', 'yablonsky-multilingual'),
        'nowrap' => __('Fixed-width text exceeds the available width', 'yablonsky-multilingual'),
      ],
    ]);
  }

  /** @return array<string,mixed> */
  public static function report(int $post_id): array {
    if ($post_id <= 0) return [];
    $report = get_post_meta($post_id, self::META_LAYOUT_QA, true);
    if (!is_array($report)) return [];
    $post = get_post($post_id);
    if (!($post instanceof \WP_Post)) return [];
    $stored_hash = sanitize_text_field((string) ($report['content_hash'] ?? ''));
    $current_hash = hash('sha256', (string) $post->post_content);
    if ($stored_hash === '' || !hash_equals($stored_hash, $current_hash)) return [];
    return $report;
  }

  public static function save_report(): void {
    $post_raw = filter_input(INPUT_POST, 'post_id', FILTER_VALIDATE_INT);
    $post_id = $post_raw ? (int) $post_raw : 0;
    $nonce_raw = filter_input(INPUT_POST, 'nonce', FILTER_UNSAFE_RAW);
    $nonce = is_string($nonce_raw) ? sanitize_text_field($nonce_raw) : '';
    if ($post_id <= 0 || $nonce === '' || !wp_verify_nonce($nonce, 'ypm_ai_layout_qa_save_' . $post_id)) {
      wp_send_json_error(['message' => __('Invalid Layout QA report.', 'yablonsky-multilingual')], 403);
    }
    if (!current_user_can('edit_post', $post_id)) wp_send_json_error(['message' => __('Forbidden', 'yablonsky-multilingual')], 403);
    $provenance = get_post_meta($post_id, AITranslation::META_PROVENANCE, true);
    if (!is_array($provenance) || ($provenance['provider'] ?? '') !== 'yablonsky_ai') {
      wp_send_json_error(['message' => __('Choose a YMulti AI translation draft for this action.', 'yablonsky-multilingual')], 409);
    }
    $status_raw = filter_input(INPUT_POST, 'status', FILTER_UNSAFE_RAW);
    $status = is_string($status_raw) && $status_raw === 'pass' ? 'pass' : 'review';
    $post = get_post($post_id);
    if (!($post instanceof \WP_Post)) wp_send_json_error(['message' => __('Layout QA requires a readable translation draft.', 'yablonsky-multilingual')], 404);
    $report = [
      'content_hash' => hash('sha256', (string) $post->post_content),
      'status' => $status,
      'issue_count' => max(0, (int) filter_input(INPUT_POST, 'issue_count', FILTER_VALIDATE_INT)),
      'high_count' => max(0, (int) filter_input(INPUT_POST, 'high_count', FILTER_VALIDATE_INT)),
      'checked' => max(0, (int) filter_input(INPUT_POST, 'checked', FILTER_VALIDATE_INT)),
      'viewport_width' => max(0, (int) filter_input(INPUT_POST, 'viewport_width', FILTER_VALIDATE_INT)),
      'viewport_height' => max(0, (int) filter_input(INPUT_POST, 'viewport_height', FILTER_VALIDATE_INT)),
      'scanned_at' => gmdate('c'),
      'reviewed_by' => get_current_user_id(),
    ];
    update_post_meta($post_id, self::META_LAYOUT_QA, $report);
    wp_send_json_success(['status' => $status]);
  }

  private static function authorized_post_id(): int {
    if (is_admin()) return 0;
    $flag = filter_input(INPUT_GET, self::QUERY_FLAG, FILTER_VALIDATE_BOOLEAN);
    if ($flag !== true) return 0;
    $post_id = (int) get_queried_object_id();
    if ($post_id <= 0 || !current_user_can('edit_post', $post_id)) return 0;
    $nonce_raw = filter_input(INPUT_GET, self::QUERY_NONCE, FILTER_UNSAFE_RAW);
    $nonce = is_string($nonce_raw) ? sanitize_text_field($nonce_raw) : '';
    if ($nonce === '' || !wp_verify_nonce($nonce, 'ypm_ai_layout_qa_' . $post_id)) return 0;
    $provenance = get_post_meta($post_id, AITranslation::META_PROVENANCE, true);
    if (!is_array($provenance) || ($provenance['provider'] ?? '') !== 'yablonsky_ai') return 0;
    return $post_id;
  }
}
