<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Bounded, read-only multilingual integrity report with explicit JSON export. */
final class IntegrityAudit {
  private const MAX_CONTENT_GROUPS = 100;
  private const MAX_TERMS = 250;
  private const MAX_ISSUES = 100;

  public static function init(): void {
    if (is_admin()) add_action('admin_post_ypm_integrity_export', [__CLASS__, 'handle_export']);
  }

  /** @return array<string,mixed> */
  public static function report(): array {
    $active = Languages::active_codes();
    $issues = [];
    $content = self::content_report($active, $issues);
    $terms = self::term_report($active, $issues);
    $navigation = class_exists('Yablonsky\\Multilingual\\NavigationAudit') ? NavigationAudit::scan() : [];
    if (!empty($navigation['gaps'])) self::issue($issues, 'navigation_gaps', 'attention', sprintf(
      /* translators: %d: navigation translation gaps. */
      __('Navigation scan found %d object/locale links ready for translation mapping.', 'yablonsky-multilingual'),
      count((array) $navigation['gaps'])
    ));
    $ai = self::ai_report($issues);
    return [
      'schema' => 'yablonsky.multilingual.integrity.v1',
      'generated_at' => gmdate('c'),
      'plugin_version' => YPM_VERSION,
      'default_locale' => Languages::default_code(),
      'active_locales' => $active,
      'content' => $content,
      'taxonomies' => $terms,
      'navigation' => $navigation,
      'ai' => $ai,
      'issues' => array_slice($issues, 0, self::MAX_ISSUES),
      'bounded' => true,
    ];
  }

  public static function render_panel(): void {
    if (!current_user_can('manage_options')) return;
    $report = self::report();
    $issues = isset($report['issues']) && is_array($report['issues']) ? $report['issues'] : [];
    $attention = 0; $fail = 0;
    foreach ($issues as $issue) {
      if (!is_array($issue)) continue;
      if (($issue['severity'] ?? '') === 'fail') $fail++; elseif (($issue['severity'] ?? '') === 'attention') $attention++;
    }
    $state = $fail > 0 ? 'fail' : ($attention > 0 ? 'attention' : 'pass');
    echo '<section class="ypm-card ypm-card--standalone"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Integrity', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Multilingual integrity audit', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('One bounded read-only pass checks content families, taxonomy families, shared-menu readiness and the connected AI model projection. Repair and rewrite actions remain explicit administrator choices.', 'yablonsky-multilingual') . '</p></div>' . wp_kses_post(Admin::status_badge($state, $fail > 0 ? __('Review failures', 'yablonsky-multilingual') : ($attention > 0 ? __('Review findings', 'yablonsky-multilingual') : __('PASS', 'yablonsky-multilingual')))) . '</div>';

    $content = is_array($report['content'] ?? null) ? $report['content'] : [];
    $terms = is_array($report['taxonomies'] ?? null) ? $report['taxonomies'] : [];
    $navigation = is_array($report['navigation'] ?? null) ? $report['navigation'] : [];
    $ai = is_array($report['ai'] ?? null) ? $report['ai'] : [];
    echo '<div class="ypm-metrics ypm-metrics--4">';
    Admin::render_metric(__('Content families', 'yablonsky-multilingual'), (string) (int) ($content['families_scanned'] ?? 0), sprintf(
      /* translators: %d: effective native/provider conflicts. */
      __('%d duplicate-locale findings', 'yablonsky-multilingual'), (int) ($content['relationship_conflicts'] ?? 0)
    ), !empty($content['relationship_conflicts']) ? 'attention' : 'pass');
    Admin::render_metric(__('Taxonomy terms', 'yablonsky-multilingual'), (string) (int) ($terms['families_scanned'] ?? 0), sprintf(
      /* translators: %d: term families with unresolved source identity. */
      __('%d assumed-default terms', 'yablonsky-multilingual'), (int) ($terms['source_gaps'] ?? 0)
    ), !empty($terms['source_gaps']) ? 'attention' : 'pass');
    Admin::render_metric(__('Navigation gaps', 'yablonsky-multilingual'), (string) count((array) ($navigation['gaps'] ?? [])), __('First 30 shown by navigation audit', 'yablonsky-multilingual'), !empty($navigation['gaps']) ? 'attention' : 'pass');
    Admin::render_metric(__('AI models', 'yablonsky-multilingual'), (string) (int) ($ai['all_models'] ?? 0), (int) ($ai['all_models'] ?? 0) > 0 ? __('Account projection', 'yablonsky-multilingual') : __('Account projection ready after connection', 'yablonsky-multilingual'), !empty($ai['connected']) && (int) ($ai['all_models'] ?? 0) <= 1 ? 'attention' : 'neutral');
    echo '</div>';

    if ($issues) {
      echo '<div class="ypm-check-list">';
      foreach (array_slice($issues, 0, 20) as $issue) {
        if (!is_array($issue)) continue;
        $severity = sanitize_key((string) ($issue['severity'] ?? 'attention'));
        $state_name = $severity === 'fail' ? 'fail' : ($severity === 'pass' ? 'pass' : 'attention');
        echo '<div class="ypm-check-row"><span class="ypm-status-dot ypm-status-dot--' . esc_attr($state_name) . '"></span><div><strong>' . esc_html((string) ($issue['code'] ?? __('Finding', 'yablonsky-multilingual'))) . '</strong><small>' . esc_html((string) ($issue['message'] ?? '')) . '</small></div>' . wp_kses_post(Admin::status_badge($state_name, strtoupper($state_name))) . '</div>';
      }
      echo '</div>';
    } else {
      echo '<div class="ypm-empty-panel ypm-empty-panel--compact"><strong>' . esc_html__('Bounded scan complete · integrity findings: 0.', 'yablonsky-multilingual') . '</strong><p>' . esc_html__('This local consistency check covers stored multilingual relationships. Search indexing and linguistic approval remain separate evidence layers.', 'yablonsky-multilingual') . '</p></div>';
    }

    echo '<div class="ypm-row-actions"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('ypm_integrity_export', 'ypm_integrity_nonce');
    echo '<input type="hidden" name="action" value="ypm_integrity_export"><button class="button">' . esc_html__('Download JSON report', 'yablonsky-multilingual') . '</button></form></div><p class="description">' . esc_html__('The export contains IDs, taxonomy/post-type names and consistency states. API keys and AI credentials stay in protected settings.', 'yablonsky-multilingual') . '</p></section>';
  }

  public static function handle_export(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_integrity_export', 'ypm_integrity_nonce');
    $report = self::report();
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="yablonsky-multilingual-integrity-' . gmdate('Ymd-His') . '.json"');
    echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
  }

  /** @param string[] $active @param array<int,array<string,string>> $issues @return array<string,int|bool> */
  private static function content_report(array $active, array &$issues): array {
    $post_types = Languages::supported_post_types();
    if (!$post_types) $post_types = ['post', 'page'];
    $args = [
      'post_type' => $post_types,
      'post_status' => ['publish', 'private', 'draft', 'pending'],
      'posts_per_page' => self::MAX_CONTENT_GROUPS,
      'orderby' => 'ID',
      'order' => 'DESC',
      'fields' => 'ids',
      'no_found_rows' => true,
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false,
    ];
    $args = AdminContent::with_family_representative($args);
    $query = new \WP_Query($args);
    $ids = array_values(array_filter(array_map('absint', (array) $query->posts)));
    wp_reset_postdata();

    $stats = [
      'families_scanned' => 0,
      'members_scanned' => 0,
      'source_gaps' => 0,
      'relationship_conflicts' => 0,
      'incomplete_families' => 0,
      'bridged_families' => 0,
      'truncated' => count($ids) >= self::MAX_CONTENT_GROUPS,
    ];
    $seen = [];
    foreach ($ids as $id) {
      $family = EntityContract::post_family($id, $active);
      $key = sanitize_text_field((string) ($family['family_key'] ?? ''));
      if ($key === '') $key = 'post:' . $id;
      if (isset($seen[$key])) continue;
      $seen[$key] = true;
      $translations = array_filter(array_map('absint', (array) ($family['translations'] ?? [])));
      $stats['families_scanned']++;
      $stats['members_scanned'] += count($translations);
      if ((string) ($family['source'] ?? '') === '') {
        $stats['source_gaps']++;

      }
      $conflicts = (array) ($family['conflicts'] ?? []);
      if ($conflicts) {
        $stats['relationship_conflicts'] += count($conflicts);
        self::issue($issues, 'content_relation_conflict', 'attention', sprintf(
          /* translators: 1: effective family key, 2: locale list. */
          __('Content family %1$s contains more than one object for locale(s): %2$s.', 'yablonsky-multilingual'),
          $key,
          implode(', ', array_keys($conflicts))
        ));
      }
      if (!empty($family['bridged'])) $stats['bridged_families']++;
      if (count(array_intersect(array_keys($translations), $active)) < count($active)) $stats['incomplete_families']++;
    }
    if ($stats['source_gaps'] > 0) self::issue($issues, 'content_assumed_default', 'attention', sprintf(
      /* translators: %d: number of posts without explicit language. */
      __('%d scanned content objects rely on assumed default language instead of an explicit language assignment.', 'yablonsky-multilingual'),
      $stats['source_gaps']
    ));
    return $stats;
  }

  /** @param string[] $active @param array<int,array<string,string>> $issues @return array<string,int|bool> */
  private static function term_report(array $active, array &$issues): array {
    $stats = [
      'families_scanned' => 0,
      'members_scanned' => 0,
      'source_gaps' => 0,
      'relationship_conflicts' => 0,
      'incomplete_families' => 0,
      'bridged_families' => 0,
      'taxonomies_scanned' => 0,
      'truncated' => false,
    ];
    foreach (Languages::supported_taxonomies() as $taxonomy) {
      if ($stats['families_scanned'] >= self::MAX_TERMS) { $stats['truncated'] = true; break; }
      if (!taxonomy_exists($taxonomy)) continue;
      $remaining = self::MAX_TERMS - $stats['families_scanned'];
      $ids = class_exists(AdminTerms::class, false) ? AdminTerms::workspace_term_ids($taxonomy, '', '', min(100, $remaining)) : [];
      if (!$ids) continue;
      $stats['taxonomies_scanned']++;
      foreach ($ids as $id) {
        $id = absint($id);
        if ($id <= 0) continue;
        $family = EntityContract::term_family($id, $taxonomy, $active);
        $translations = array_filter(array_map('absint', (array) ($family['translations'] ?? [])));
        $stats['families_scanned']++;
        $stats['members_scanned'] += count($translations);
        if ((string) ($family['source'] ?? '') === '') {
          $stats['source_gaps']++;

        }
        $conflicts = (array) ($family['conflicts'] ?? []);
        if ($conflicts) {
          $stats['relationship_conflicts'] += count($conflicts);
          self::issue($issues, 'term_relation_conflict', 'attention', sprintf(
            /* translators: 1: taxonomy, 2: term ID, 3: locale list. */
            __('Taxonomy family %1$s contains more than one term for locale(s): %2$s.', 'yablonsky-multilingual'),
            $taxonomy . ':' . $id,
            implode(', ', array_keys($conflicts))
          ));
        }
        if (!empty($family['bridged'])) $stats['bridged_families']++;
        if (count(array_intersect(array_keys($translations), $active)) < count($active)) $stats['incomplete_families']++;
        if ($stats['families_scanned'] >= self::MAX_TERMS) { $stats['truncated'] = true; break 2; }
      }
    }
    if ($stats['source_gaps'] > 0) self::issue($issues, 'term_assumed_default', 'attention', sprintf(
      /* translators: %d: number of terms without explicit language. */
      __('%d scanned taxonomy terms rely on assumed default language.', 'yablonsky-multilingual'),
      $stats['source_gaps']
    ));
    return $stats;
  }

  /** @param array<int,array<string,string>> $issues @return array<string,int|bool|string> */
  private static function ai_report(array &$issues): array {
    $connected = class_exists('Yablonsky\\Multilingual\\AITranslation') && AITranslation::has_key();
    $models = $connected ? AITranslation::available_models() : [];
    $text = $connected ? AITranslation::text_models() : [];
    $image = $connected ? AITranslation::image_models() : [];
    if ($connected && count($models) <= 1) self::issue($issues, 'ai_model_catalog_narrow', 'attention', __('The connected workspace currently projects one or fewer models. Refresh the account and verify workspace plan entitlements if a wider catalog is expected.', 'yablonsky-multilingual'));
    return [
      'connected' => $connected, 'ready' => $connected && AITranslation::ready(), 'workspace_id' => $connected ? AITranslation::workspace_id() : '',
      'all_models' => count($models), 'text_models' => count($text), 'image_models' => count($image),
    ];
  }

  /** @param array<int,array<string,string>> $issues */
  private static function issue(array &$issues, string $code, string $severity, string $message): void {
    if (count($issues) >= self::MAX_ISSUES) return;
    $issues[] = ['code' => sanitize_key($code), 'severity' => sanitize_key($severity), 'message' => sanitize_text_field($message)];
  }
}
