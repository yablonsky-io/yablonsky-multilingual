<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * One durable workspace for multilingual content drafts awaiting human action.
 *
 * The bounded index is intentionally non-authoritative: every row is validated
 * against the current WordPress post status and YMulti translation identity
 * before it is shown or counted.
 */
final class DraftsWorkspace {
  private const OPT_INDEX = 'ymulti_drafts_index_v1';
  private const OPT_INDEX_VERSION = 'ymulti_drafts_index_version_v1';
  private const META_SOURCE = '_ymulti_draft_source_id';
  private const META_ORIGIN = '_ymulti_draft_origin';
  private const INDEX_LIMIT = 3000;
  private const BACKFILL_LIMIT = 3000;

  public static function init(): void {
    add_action('yablonsky_multilingual_translation_draft_initialized', [__CLASS__, 'register_draft'], 1, 3);
    add_action('transition_post_status', [__CLASS__, 'on_status_transition'], 10, 3);
    add_action('before_delete_post', [__CLASS__, 'on_delete_post']);

    if (is_admin()) {
      add_action('admin_menu', [__CLASS__, 'register_page'], 30);
      add_action('admin_post_ymulti_drafts_batch', [__CLASS__, 'handle_batch']);
    }
  }

  public static function register_page(): void {
    $count = self::pending_count();
    $label = esc_html__('Drafts', 'yablonsky-multilingual');
    if ($count > 0) {
      $label .= ' <span class="awaiting-mod count-' . esc_attr((string) $count) . '"><span class="pending-count" aria-hidden="true">' . esc_html((string) $count) . '</span><span class="screen-reader-text">' . esc_html(sprintf(
        /* translators: %d: multilingual drafts awaiting checking. */
        _n('%d multilingual draft awaiting checking', '%d multilingual drafts awaiting checking', $count, 'yablonsky-multilingual'),
        $count
      )) . '</span></span>';
    }

    add_submenu_page(
      'ypm-multilingual',
      __('Drafts', 'yablonsky-multilingual'),
      $label,
      'edit_posts',
      'ypm-drafts',
      [__CLASS__, 'render_page']
    );
  }

  public static function register_draft(int $source_id, int $draft_id, string $origin = 'manual'): void {
    if ($draft_id <= 0) return;
    $origin = sanitize_key($origin);
    if (!in_array($origin, ['manual', 'ai', 'job', 'xliff'], true)) $origin = 'manual';

    if ($source_id > 0) update_post_meta($draft_id, self::META_SOURCE, $source_id);
    update_post_meta($draft_id, self::META_ORIGIN, $origin);
    self::add_to_index($draft_id);
  }

  public static function on_status_transition(string $new_status, string $old_status, \WP_Post $post): void {
    if (!in_array($old_status, ['draft', 'pending'], true)) return;
    if (in_array($new_status, ['draft', 'pending'], true)) return;
    self::remove_from_index((int) $post->ID);
  }

  public static function pending_count(): int {
    self::maybe_backfill_index();
    $ids = get_option(self::OPT_INDEX, []);
    if (!is_array($ids)) return 0;
    return count(array_values(array_unique(array_filter(array_map('absint', $ids)))));
  }

  public static function on_delete_post(int $post_id): void {
    self::remove_from_index($post_id);
  }

  /** @return int[] */
  public static function draft_ids(): array {
    self::maybe_backfill_index();
    $ids = get_option(self::OPT_INDEX, []);
    if (!is_array($ids)) return [];
    $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));

    $clean = [];
    foreach ($ids as $post_id) {
      $post = get_post($post_id);
      if (!$post instanceof \WP_Post || !in_array($post->post_status, ['draft', 'pending'], true)) continue;
      if (!self::is_multilingual_translation($post_id)) continue;
      $clean[] = $post_id;
    }

    if ($clean !== $ids) update_option(self::OPT_INDEX, $clean, false);
    return $clean;
  }

  private static function add_to_index(int $post_id): void {
    $ids = get_option(self::OPT_INDEX, []);
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_filter(array_map('absint', $ids)));
    array_unshift($ids, $post_id);
    $ids = array_values(array_unique($ids));
    if (count($ids) > self::INDEX_LIMIT) $ids = array_slice($ids, 0, self::INDEX_LIMIT);
    update_option(self::OPT_INDEX, $ids, false);
  }

  private static function remove_from_index(int $post_id): void {
    if ($post_id <= 0) return;
    $ids = get_option(self::OPT_INDEX, []);
    if (!is_array($ids)) return;
    $clean = array_values(array_filter(array_map('absint', $ids), static function (int $id) use ($post_id): bool { return $id !== $post_id; }));
    if ($clean !== $ids) update_option(self::OPT_INDEX, $clean, false);
  }

  private static function maybe_backfill_index(): void {
    if ((int) get_option(self::OPT_INDEX_VERSION, 0) >= 1) return;

    $found = [];
    if (class_exists('Yablonsky\\Multilingual\\AITranslation')) {
      $legacy_ai_ids = get_option(AITranslation::OPT_REVIEW_INDEX, []);
      if (is_array($legacy_ai_ids)) foreach ($legacy_ai_ids as $post_id) $found[] = absint($post_id);
    }

    $post_types = Languages::supported_post_types();
    if (!$post_types) $post_types = ['post', 'page'];
    $query = new \WP_Query([
      'post_type' => $post_types,
      'post_status' => ['draft', 'pending'],
      'posts_per_page' => self::BACKFILL_LIMIT,
      'orderby' => 'ID',
      'order' => 'DESC',
      'fields' => 'ids',
      'no_found_rows' => true,
      'update_post_meta_cache' => true,
      'update_post_term_cache' => true,
    ]);

    foreach ((array) $query->posts as $value) {
      $post_id = absint($value);
      if ($post_id <= 0 || !self::is_multilingual_translation($post_id)) continue;
      $found[] = $post_id;
      if ((int) get_post_meta($post_id, self::META_SOURCE, true) <= 0) {
        $source_id = self::resolve_source_id($post_id);
        if ($source_id > 0) update_post_meta($post_id, self::META_SOURCE, $source_id);
      }
      if ((string) get_post_meta($post_id, self::META_ORIGIN, true) === '') {
        $provenance = class_exists('Yablonsky\\Multilingual\\AITranslation') ? get_post_meta($post_id, AITranslation::META_PROVENANCE, true) : [];
        update_post_meta($post_id, self::META_ORIGIN, is_array($provenance) && ($provenance['provider'] ?? '') === 'yablonsky_ai' ? 'ai' : 'manual');
      }
    }
    wp_reset_postdata();

    $existing = get_option(self::OPT_INDEX, []);
    if (!is_array($existing)) $existing = [];
    $ids = array_values(array_unique(array_filter(array_map('absint', array_merge($found, $existing)))));
    rsort($ids, SORT_NUMERIC);
    if (count($ids) > self::INDEX_LIMIT) $ids = array_slice($ids, 0, self::INDEX_LIMIT);
    update_option(self::OPT_INDEX, $ids, false);
    update_option(self::OPT_INDEX_VERSION, 1, false);
  }

  private static function is_multilingual_translation(int $post_id): bool {
    if ($post_id <= 0) return false;
    if (absint(get_post_meta($post_id, self::META_SOURCE, true)) > 0) return true;
    if (class_exists('Yablonsky\\Multilingual\\AITranslation')) {
      $provenance = get_post_meta($post_id, AITranslation::META_PROVENANCE, true);
      if (is_array($provenance) && absint($provenance['source_id'] ?? 0) > 0) return true;
    }
    $group = Translations::group_id($post_id);
    if ($group === '') return false;
    $anchor = self::group_anchor_id($group);
    if ($anchor > 0) return $anchor !== $post_id;
    $members = Translations::translations_for_group($group);
    return count($members) > 1;
  }

  private static function group_anchor_id(string $group): int {
    $group = sanitize_title($group);
    if ($group === '') return 0;
    $term = get_term_by('slug', $group, Translations::GROUP_TAX);
    if (!$term instanceof \WP_Term) return 0;
    return absint(get_term_meta((int) $term->term_id, Translations::GROUP_ORDER_ANCHOR_META, true));
  }

  private static function resolve_source_id(int $post_id): int {
    $stored = absint(get_post_meta($post_id, self::META_SOURCE, true));
    if ($stored > 0 && get_post($stored) instanceof \WP_Post) return $stored;

    if (class_exists('Yablonsky\Multilingual\AITranslation')) {
      $provenance = get_post_meta($post_id, AITranslation::META_PROVENANCE, true);
      if (is_array($provenance)) {
        $source_id = absint($provenance['source_id'] ?? 0);
        if ($source_id > 0 && get_post($source_id) instanceof \WP_Post) return $source_id;
      }
    }

    $source_id = EntityContract::canonical_post_source_id($post_id);
    return $source_id > 0 && $source_id !== $post_id ? $source_id : 0;
  }

  private static function origin_for(int $post_id): string {
    $origin = sanitize_key((string) get_post_meta($post_id, self::META_ORIGIN, true));
    if (in_array($origin, ['manual', 'ai', 'job', 'xliff'], true)) return $origin;
    if (class_exists('Yablonsky\\Multilingual\\AITranslation')) {
      $provenance = get_post_meta($post_id, AITranslation::META_PROVENANCE, true);
      if (is_array($provenance) && ($provenance['provider'] ?? '') === 'yablonsky_ai') return 'ai';
    }
    return 'manual';
  }

  private static function origin_label(string $origin): string {
    if ($origin === 'ai') return __('AI', 'yablonsky-multilingual');
    if ($origin === 'job') return __('Translation job', 'yablonsky-multilingual');
    if ($origin === 'xliff') return __('XLIFF', 'yablonsky-multilingual');
    return __('Manual', 'yablonsky-multilingual');
  }

  /** @return array<int,array<string,mixed>> */
  private static function filtered_rows(): array {
    $search_raw = filter_input(INPUT_GET, 's', FILTER_UNSAFE_RAW);
    $search = is_string($search_raw) ? trim(sanitize_text_field(wp_unslash($search_raw))) : '';
    $post_type_raw = filter_input(INPUT_GET, 'post_type_filter', FILTER_UNSAFE_RAW);
    $post_type = is_string($post_type_raw) ? sanitize_key($post_type_raw) : '';
    $source_raw = filter_input(INPUT_GET, 'source_locale', FILTER_UNSAFE_RAW);
    $source_locale = is_string($source_raw) ? sanitize_text_field(wp_unslash($source_raw)) : '';
    $target_raw = filter_input(INPUT_GET, 'target_locale', FILTER_UNSAFE_RAW);
    $target_locale = is_string($target_raw) ? sanitize_text_field(wp_unslash($target_raw)) : '';
    $origin_raw = filter_input(INPUT_GET, 'origin', FILTER_UNSAFE_RAW);
    $origin_filter = is_string($origin_raw) ? sanitize_key($origin_raw) : '';
    $status_raw = filter_input(INPUT_GET, 'draft_status', FILTER_UNSAFE_RAW);
    $status_filter = is_string($status_raw) ? sanitize_key($status_raw) : '';

    $rows = [];
    $ids = self::draft_ids();
    if ($ids) Translations::prime_languages($ids);
    foreach ($ids as $post_id) {
      if (!current_user_can('edit_post', $post_id)) continue;
      $post = get_post($post_id);
      if (!$post instanceof \WP_Post) continue;
      if ($post_type !== '' && $post->post_type !== $post_type) continue;
      if ($status_filter !== '' && $post->post_status !== $status_filter) continue;

      $target = RuntimeAuthority::effective_post_language($post_id);
      if ($target_locale !== '' && $target !== $target_locale) continue;
      $source_id = self::resolve_source_id($post_id);
      $source_lang = $source_id > 0 ? RuntimeAuthority::effective_post_language($source_id) : '';
      if ($source_locale !== '' && $source_lang !== $source_locale) continue;
      $origin = self::origin_for($post_id);
      if ($origin_filter !== '' && $origin !== $origin_filter) continue;

      if ($search !== '') {
        $haystack = strtolower(implode(' ', [
          (string) $post_id,
          (string) $post->post_title,
          (string) $post->post_name,
          $source_id > 0 ? (string) get_the_title($source_id) : '',
          (string) $source_id,
        ]));
        if (strpos($haystack, strtolower($search)) === false) continue;
      }

      $rows[] = [
        'id' => $post_id,
        'post' => $post,
        'source_id' => $source_id,
        'source_locale' => $source_lang,
        'target_locale' => $target,
        'origin' => $origin,
      ];
    }
    return $rows;
  }

  public static function render_page(): void {
    if (!current_user_can('edit_posts')) return;
    $rows = self::filtered_rows();
    $total = count($rows);
    $page_size = Admin::requested_page_size(25, [10, 25, 50, 100]);
    $paged_raw = filter_input(INPUT_GET, 'paged', FILTER_VALIDATE_INT);
    $paged = max(1, $paged_raw ? (int) $paged_raw : 1);
    $pages = max(1, (int) ceil($total / $page_size));
    if ($paged > $pages && $total > 0) $paged = $pages;
    $offset = ($paged - 1) * $page_size;
    $visible = array_slice($rows, $offset, $page_size);

    echo '<div class="wrap ypm-admin ypm-admin--workspace ypm-admin--drafts">';
    Admin::render_shell_header(
      __('Drafts', 'yablonsky-multilingual'),
      __('Translation drafts waiting for checking, editing or publication. Search and filter the queue, then handle one item or a bounded batch.', 'yablonsky-multilingual'),
      $total > 0 ? 'attention' : 'pass',
      $total > 0 ? sprintf(
        /* translators: %d: multilingual drafts awaiting checking. */
        _n('%d draft waiting', '%d drafts waiting', $total, 'yablonsky-multilingual'),
        $total
      ) : __('Queue clear', 'yablonsky-multilingual')
    );
    self::render_notice();

    echo '<div class="ypm-metrics ypm-metrics--4">';
    Admin::render_metric(__('Drafts', 'yablonsky-multilingual'), (string) $total, __('After current filters', 'yablonsky-multilingual'), $total > 0 ? 'attention' : 'pass');
    Admin::render_metric(__('Draft', 'yablonsky-multilingual'), (string) self::count_status($rows, 'draft'), __('Editable translations', 'yablonsky-multilingual'), 'neutral');
    Admin::render_metric(__('Pending review', 'yablonsky-multilingual'), (string) self::count_status($rows, 'pending'), __('Waiting for editorial approval', 'yablonsky-multilingual'), 'neutral');
    Admin::render_metric(__('Active locales', 'yablonsky-multilingual'), (string) count(Languages::active_codes()), __('Configured languages', 'yablonsky-multilingual'), 'neutral');
    echo '</div>';

    echo '<section class="ypm-card ypm-card--standalone ypm-workspace-card">';
    echo '<div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Draft checking', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Translation drafts', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('This queue is shared by manual translations, AI-created content and professional translation jobs. Published items leave the queue automatically.', 'yablonsky-multilingual') . '</p></div></div>';

    self::render_filters();

    if (!$visible) {
      echo '<div class="ypm-empty-panel"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><strong>' . esc_html__('No multilingual drafts match this view', 'yablonsky-multilingual') . '</strong><p>' . esc_html__('Adjust the filters or create a translation draft from the Translations workspace.', 'yablonsky-multilingual') . '</p></div></section></div>';
      return;
    }

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ypm-drafts-batch-form">';
    wp_nonce_field('ymulti_drafts_batch', 'ymulti_drafts_nonce');
    echo '<input type="hidden" name="action" value="ymulti_drafts_batch">';
    echo '<input type="hidden" name="return_url" value="' . esc_attr(self::current_url()) . '">';
    echo '<div class="ymulti-bulk-actions ypm-drafts-bulk-actions"><label><span>' . esc_html__('Batch action', 'yablonsky-multilingual') . '</span><select name="draft_action"><option value="">' . esc_html__('Choose action…', 'yablonsky-multilingual') . '</option><option value="publish">' . esc_html__('Publish selected', 'yablonsky-multilingual') . '</option><option value="pending">' . esc_html__('Move selected to pending review', 'yablonsky-multilingual') . '</option></select></label><button class="button button-primary">' . esc_html__('Apply', 'yablonsky-multilingual') . '</button></div>';

    echo '<div class="ypm-table-wrap"><table class="widefat ymulti-entity-table ypm-workspace-table ypm-drafts-table"><thead><tr><th class="ymulti-bulk-check"><input type="checkbox" data-ymulti-bulk-all="drafts" aria-label="' . esc_attr__('Select this page', 'yablonsky-multilingual') . '"></th><th>' . esc_html__('Draft', 'yablonsky-multilingual') . '</th><th>' . esc_html__('Source → Target', 'yablonsky-multilingual') . '</th><th>' . esc_html__('Created by', 'yablonsky-multilingual') . '</th><th>' . esc_html__('Review gates', 'yablonsky-multilingual') . '</th><th class="ypm-col-action">' . esc_html__('Action', 'yablonsky-multilingual') . '</th></tr></thead><tbody>';
    foreach ($visible as $row) self::render_row($row);
    echo '</tbody></table></div>';
    echo '</form>';

    $start = $total > 0 ? $offset + 1 : 0;
    $end = min($total, $offset + count($visible));
    echo '<div class="ypm-pagination"><span>' . esc_html(sprintf(
      /* translators: 1: first visible row, 2: last visible row, 3: total rows. */
      __('Showing %1$d–%2$d of %3$d', 'yablonsky-multilingual'),
      $start,
      $end,
      $total
    )) . '</span>';
    Admin::render_server_page_size(self::filter_query(['page' => 'ypm-drafts']), $page_size, [10, 25, 50, 100]);
    if ($pages > 1) {
      $links = paginate_links([
        'base' => add_query_arg(array_merge(self::filter_query(['page' => 'ypm-drafts', 'paged' => '%#%']), ['per_page' => $page_size]), admin_url('admin.php')),
        'format' => '',
        'current' => $paged,
        'total' => $pages,
        'prev_text' => '‹',
        'next_text' => '›',
        'type' => 'array',
      ]);
      if (is_array($links) && $links) echo '<span class="ypm-pagination__links">' . wp_kses_post(implode('', $links)) . '</span>';
    }
    echo '</div></section></div>';
  }

  /** @param array<string,mixed> $row */
  private static function render_row(array $row): void {
    $post_id = (int) $row['id'];
    $post = $row['post'];
    if (!$post instanceof \WP_Post) return;
    $source_id = (int) $row['source_id'];
    $source_locale = (string) $row['source_locale'];
    $target_locale = (string) $row['target_locale'];
    $origin = (string) $row['origin'];
    $edit = get_edit_post_link($post_id, '');
    $source_edit = $source_id > 0 && current_user_can('edit_post', $source_id) ? get_edit_post_link($source_id, '') : '';
    $source_title = $source_id > 0 ? get_the_title($source_id) : '';
    $visual_review = ($source_id > 0 && $target_locale !== '') ? add_query_arg([
      'page' => 'ypm-visual-review',
      'source' => $source_id,
      'lang' => $target_locale,
    ], admin_url('admin.php')) : '';

    $provenance = class_exists('Yablonsky\\Multilingual\\AITranslation') ? get_post_meta($post_id, AITranslation::META_PROVENANCE, true) : [];
    $is_ai = $origin === 'ai' || (is_array($provenance) && ($provenance['provider'] ?? '') === 'yablonsky_ai');
    $qa = $is_ai && class_exists('Yablonsky\\Multilingual\\AILayoutQA') ? AILayoutQA::preview_url($post_id) : '';
    $layout_report = $is_ai && class_exists('Yablonsky\\Multilingual\\AILayoutQA') ? AILayoutQA::report($post_id) : [];
    $media_required = $is_ai && is_array($provenance) && !empty($provenance['media_review_required']);
    $media_ids = $media_required && isset($provenance['structure']['media']['attachment_ids']) && is_array($provenance['structure']['media']['attachment_ids'])
      ? array_values(array_unique(array_filter(array_map('absint', $provenance['structure']['media']['attachment_ids']))))
      : [];
    $media_reviews = $is_ai && class_exists('Yablonsky\\Multilingual\\AITranslation') ? get_post_meta($post_id, AITranslation::META_MEDIA_REVIEW, true) : [];
    if (!is_array($media_reviews)) $media_reviews = [];
    $media_reviewed = 0;
    foreach ($media_ids as $media_id) if (isset($media_reviews[(string) $media_id]) && is_array($media_reviews[(string) $media_id])) $media_reviewed++;

    echo '<tr><td class="ymulti-bulk-check"><input type="checkbox" name="draft_ids[]" value="' . esc_attr((string) $post_id) . '" data-ymulti-bulk-row="drafts" aria-label="' . esc_attr(sprintf(
      /* translators: %s: draft title. */
      __('Select %s', 'yablonsky-multilingual'),
      $post->post_title !== '' ? $post->post_title : '#' . $post_id
    )) . '"></td>';
    echo '<td><div class="ypm-content-cell"><strong>' . esc_html($post->post_title !== '' ? $post->post_title : '#' . $post_id) . '</strong><span>' . esc_html($post->post_type . ' · ' . $post->post_status . ' · #' . $post_id) . '</span>';
    if ($source_title !== '') echo '<small>' . esc_html__('Source', 'yablonsky-multilingual') . ': ' . esc_html($source_title) . '</small>';
    echo '</div></td>';

    echo '<td><div class="ypm-domain-cell"><strong class="ymulti-locale-pair">' . wp_kses_post(Admin::language_badge($source_locale)) . '<span aria-hidden="true">→</span>' . wp_kses_post(Admin::language_badge($target_locale)) . '</strong><span>' . esc_html($source_id > 0 ? '#' . $source_id . ' → #' . $post_id : '#' . $post_id) . '</span></div></td>';
    echo '<td>' . wp_kses_post(Admin::status_badge($origin === 'ai' ? 'attention' : 'neutral', self::origin_label($origin))) . '</td>';

    echo '<td><div class="ypm-review-gates">';
    echo wp_kses_post(Admin::status_badge($post->post_status === 'pending' ? 'attention' : 'neutral', $post->post_status === 'pending' ? __('Pending review', 'yablonsky-multilingual') : __('Draft', 'yablonsky-multilingual')));
    if ($is_ai) {
      echo wp_kses_post(Admin::status_badge('pass', __('Structure PASS', 'yablonsky-multilingual')));
      if ($layout_report) {
        $layout_status = ($layout_report['status'] ?? '') === 'pass' ? 'pass' : 'attention';
        echo wp_kses_post(Admin::status_badge($layout_status, $layout_status === 'pass' ? __('Layout PASS', 'yablonsky-multilingual') : __('Layout review', 'yablonsky-multilingual')));
      } else {
        echo wp_kses_post(Admin::status_badge('neutral', __('Layout QA ready', 'yablonsky-multilingual')));
      }
      if ($media_required && $media_ids) {
        $media_label = sprintf(
          /* translators: 1: reviewed images, 2: total images in the translation draft. */
          __('Vision %1$d/%2$d', 'yablonsky-multilingual'),
          $media_reviewed,
          count($media_ids)
        );
        echo wp_kses_post(Admin::status_badge($media_reviewed >= count($media_ids) ? 'pass' : 'attention', $media_label));
      } elseif ($media_required) {
        echo wp_kses_post(Admin::status_badge('attention', __('Media review', 'yablonsky-multilingual')));
      }
    }
    echo '</div></td>';

    echo '<td class="ypm-col-action"><div class="ypm-row-actions">';
    if ($edit) echo '<a class="button button-primary" href="' . esc_url($edit) . '">' . esc_html__('Edit draft', 'yablonsky-multilingual') . '</a>';
    if ($edit && $media_required) echo '<a class="button" href="' . esc_url($edit . '#ypm-ai-media-review') . '">' . esc_html__('Media review', 'yablonsky-multilingual') . '</a>';
    if ($qa !== '') echo '<a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url($qa) . '">' . esc_html__('Layout QA', 'yablonsky-multilingual') . '</a>';
    if ($visual_review !== '') echo '<a class="button" href="' . esc_url($visual_review) . '">' . esc_html__('Visual Review', 'yablonsky-multilingual') . '</a>';
    if ($source_edit && $source_edit !== $edit) echo '<a class="button" href="' . esc_url($source_edit) . '">' . esc_html__('Edit source', 'yablonsky-multilingual') . '</a>';
    echo '</div></td></tr>';
  }

  private static function render_filters(): void {
    $s = self::get_param('s');
    $post_type = self::get_param('post_type_filter');
    $source = self::get_param('source_locale');
    $target = self::get_param('target_locale');
    $origin = self::get_param('origin');
    $status = self::get_param('draft_status');

    echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="ypm-workspace-filter-form"><input type="hidden" name="page" value="ypm-drafts">';
    echo '<label class="ypm-workspace-filter ypm-workspace-filter--search"><span>' . esc_html__('Search', 'yablonsky-multilingual') . '</span><input type="search" name="s" value="' . esc_attr($s) . '" placeholder="' . esc_attr__('Title, slug or ID', 'yablonsky-multilingual') . '"></label>';
    echo '<label class="ypm-workspace-filter"><span>' . esc_html__('Content type', 'yablonsky-multilingual') . '</span><select name="post_type_filter"><option value="">' . esc_html__('All content types', 'yablonsky-multilingual') . '</option>';
    foreach (Languages::supported_post_types() as $type) {
      $obj = get_post_type_object($type);
      $label = $obj && isset($obj->labels->name) ? (string) $obj->labels->name : $type;
      echo '<option value="' . esc_attr($type) . '"' . selected($post_type, $type, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label>';
    self::render_language_filter(__('Source language', 'yablonsky-multilingual'), 'source_locale', $source, __('Any source language', 'yablonsky-multilingual'));
    self::render_language_filter(__('Target language', 'yablonsky-multilingual'), 'target_locale', $target, __('Any target language', 'yablonsky-multilingual'));
    echo '<label class="ypm-workspace-filter"><span>' . esc_html__('Created by', 'yablonsky-multilingual') . '</span><select name="origin"><option value="">' . esc_html__('Any workflow', 'yablonsky-multilingual') . '</option><option value="manual"' . selected($origin, 'manual', false) . '>' . esc_html__('Manual', 'yablonsky-multilingual') . '</option><option value="ai"' . selected($origin, 'ai', false) . '>' . esc_html__('AI', 'yablonsky-multilingual') . '</option><option value="job"' . selected($origin, 'job', false) . '>' . esc_html__('Translation job', 'yablonsky-multilingual') . '</option></select></label>';
    echo '<label class="ypm-workspace-filter"><span>' . esc_html__('Status', 'yablonsky-multilingual') . '</span><select name="draft_status"><option value="">' . esc_html__('Draft + pending', 'yablonsky-multilingual') . '</option><option value="draft"' . selected($status, 'draft', false) . '>' . esc_html__('Draft', 'yablonsky-multilingual') . '</option><option value="pending"' . selected($status, 'pending', false) . '>' . esc_html__('Pending review', 'yablonsky-multilingual') . '</option></select></label>';
    echo '<div class="ypm-workspace-filter-actions"><button class="button button-primary">' . esc_html__('Apply filters', 'yablonsky-multilingual') . '</button><a class="button" href="' . esc_url(admin_url('admin.php?page=ypm-drafts')) . '">' . esc_html__('Reset', 'yablonsky-multilingual') . '</a></div></form>';
  }

  private static function render_language_filter(string $label, string $name, string $value, string $empty_label): void {
    echo '<label class="ypm-workspace-filter"><span>' . esc_html($label) . '</span><select name="' . esc_attr($name) . '"><option value="">' . esc_html($empty_label) . '</option>';
    foreach (Languages::active_codes() as $code) {
      echo '<option value="' . esc_attr($code) . '"' . selected($value, $code, false) . '>' . esc_html(Languages::display_name($code) . ' · ' . Languages::display_code($code)) . '</option>';
    }
    echo '</select></label>';
  }

  /** @param array<int,array<string,mixed>> $rows */
  private static function count_status(array $rows, string $status): int {
    $count = 0;
    foreach ($rows as $row) if (($row['post'] ?? null) instanceof \WP_Post && $row['post']->post_status === $status) $count++;
    return $count;
  }

  public static function handle_batch(): void {
    if (!current_user_can('edit_posts')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ymulti_drafts_batch', 'ymulti_drafts_nonce');
    $action_raw = filter_input(INPUT_POST, 'draft_action', FILTER_UNSAFE_RAW);
    $action = is_string($action_raw) ? sanitize_key(wp_unslash($action_raw)) : '';
    $ids_raw = isset($_POST['draft_ids']) && is_array($_POST['draft_ids']) ? array_map('absint', wp_unslash($_POST['draft_ids'])) : [];
    $ids = array_values(array_unique(array_filter($ids_raw)));
    $changed = 0;
    $skipped = 0;
    $indexed = array_fill_keys(self::draft_ids(), true);

    if (!in_array($action, ['publish', 'pending'], true) || !$ids) {
      self::set_notice('attention', __('Choose one or more drafts and a batch action.', 'yablonsky-multilingual'));
      self::redirect_back();
    }

    foreach ($ids as $post_id) {
      $post = get_post($post_id);
      if (!isset($indexed[$post_id]) || !$post instanceof \WP_Post || !in_array($post->post_status, ['draft', 'pending'], true) || !self::is_multilingual_translation($post_id) || !current_user_can('edit_post', $post_id)) {
        $skipped++;
        continue;
      }
      if ($action === 'publish' && !self::can_publish($post)) {
        $skipped++;
        continue;
      }
      $new_status = $action === 'publish' ? 'publish' : 'pending';
      if ($post->post_status === $new_status) {
        $skipped++;
        continue;
      }
      $result = wp_update_post(['ID' => $post_id, 'post_status' => $new_status], true);
      if (is_wp_error($result)) $skipped++; else $changed++;
    }

    self::set_notice($changed > 0 ? 'success' : 'attention', sprintf(
      /* translators: 1: changed drafts, 2: skipped drafts. */
      __('Updated %1$d drafts; skipped %2$d.', 'yablonsky-multilingual'),
      $changed,
      $skipped
    ));
    self::redirect_back();
  }

  private static function can_publish(\WP_Post $post): bool {
    $type = get_post_type_object($post->post_type);
    $cap = $type && isset($type->cap->publish_posts) ? (string) $type->cap->publish_posts : 'publish_posts';
    return current_user_can($cap);
  }

  private static function current_url(): string {
    $query = self::filter_query(['page' => 'ypm-drafts']);
    $per_page = filter_input(INPUT_GET, 'per_page', FILTER_VALIDATE_INT);
    $paged = filter_input(INPUT_GET, 'paged', FILTER_VALIDATE_INT);
    if ($per_page) $query['per_page'] = (int) $per_page;
    if ($paged) $query['paged'] = (int) $paged;
    return add_query_arg($query, admin_url('admin.php'));
  }

  /** @param array<string,mixed> $base @return array<string,mixed> */
  private static function filter_query(array $base): array {
    foreach (['s', 'post_type_filter', 'source_locale', 'target_locale', 'origin', 'draft_status'] as $key) {
      $value = self::get_param($key);
      if ($value !== '') $base[$key] = $value;
    }
    return $base;
  }

  private static function get_param(string $key): string {
    $raw = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW);
    return is_string($raw) ? sanitize_text_field(wp_unslash($raw)) : '';
  }

  private static function set_notice(string $type, string $message): void {
    set_transient('ymulti_drafts_notice_' . get_current_user_id(), ['type' => sanitize_key($type), 'message' => sanitize_text_field($message)], 60);
  }

  private static function render_notice(): void {
    $key = 'ymulti_drafts_notice_' . get_current_user_id();
    $notice = get_transient($key);
    if (!is_array($notice)) return;
    delete_transient($key);
    $type = ($notice['type'] ?? '') === 'success' ? 'success' : 'warning';
    echo '<div class="notice notice-' . esc_attr($type) . ' inline"><p>' . esc_html((string) ($notice['message'] ?? '')) . '</p></div>';
  }

  private static function redirect_back(): void {
    $return_raw = filter_input(INPUT_POST, 'return_url', FILTER_UNSAFE_RAW);
    $return = is_string($return_raw) ? esc_url_raw(wp_unslash($return_raw)) : '';
    wp_safe_redirect($return !== '' ? $return : admin_url('admin.php?page=ypm-drafts'));
    exit;
  }
}
