<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Installed-site launch readiness: real data completion + runtime health. */
final class LaunchCenter {
  public static function init(): void {
    if (is_admin()) add_action('admin_menu', [__CLASS__, 'menu'], 20);
  }

  public static function menu(): void {
    add_submenu_page('ypm-multilingual', __('Launch Center', 'yablonsky-multilingual'), __('Launch Center', 'yablonsky-multilingual'), 'manage_options', 'ypm-launch-center', [__CLASS__, 'page']);
  }

  /** @return array<string,mixed> */
  public static function report(): array {
    $status = Status::report();
    $system_ok = true;
    foreach ((array) ($status['checks'] ?? []) as $check) if (empty($check['ok'])) { $system_ok = false; break; }
    $active = Languages::active_codes();
    $taxonomies = class_exists('Yablonsky\\Multilingual\\AdminTerms') ? AdminTerms::manageable_taxonomies() : [];
    $posts = SiteCoverage::posts();
    $terms = $taxonomies ? SiteCoverage::terms($taxonomies) : SiteCoverage::metric(0, 0, __('Taxonomy automation becomes available when managed taxonomies are registered.', 'yablonsky-multilingual'));
    $menus = SiteCoverage::menus();
    $media = SiteCoverage::media();
    $woo_active = class_exists('WooCommerce') || function_exists('WC');
    $woo = null;
    if ($woo_active && class_exists('Yablonsky\\Multilingual\\Compat\\Woo')) {
      $rows = Compat\Woo::localization_coverage_matrix();
      $metrics = [];
      foreach ($rows as $key => $metric) if ($key !== 'variations') $metrics[] = $metric;
      $woo = SiteCoverage::combine($metrics);
    }
    $ai_connected = class_exists('Yablonsky\\Multilingual\\AITranslation') && AITranslation::has_key() && AITranslation::workspace_id() !== '';
    $ai_operational = !$ai_connected || (AITranslation::current_user_can_compute() && (AITranslation::ready() || AITranslation::vision_ready()));
    $xliff = class_exists('DOMDocument');

    $critical = [
      'system' => ['label' => __('Runtime health', 'yablonsky-multilingual'), 'ok' => $system_ok, 'detail' => $system_ok ? __('Core compatibility checks pass.', 'yablonsky-multilingual') : __('System Status still has findings.', 'yablonsky-multilingual'), 'url' => admin_url('admin.php?page=ypm-system-status')],
      'languages' => ['label' => __('Language configuration', 'yablonsky-multilingual'), 'ok' => count($active) >= 2, 'detail' => sprintf(
        /* translators: %d: number of active languages. */
        __('%d active languages.', 'yablonsky-multilingual'), count($active)
      ), 'url' => admin_url('admin.php?page=ypm-languages')],
      'content' => ['label' => __('Content translations', 'yablonsky-multilingual'), 'ok' => (int) $posts['total'] === 0 || (int) $posts['percent'] === 100, 'detail' => (string) $posts['detail'], 'url' => admin_url('admin.php?page=ypm-translations')],
      'taxonomies' => ['label' => __('Taxonomy translations', 'yablonsky-multilingual'), 'ok' => (int) $terms['total'] === 0 || (int) $terms['percent'] === 100, 'detail' => (string) $terms['detail'], 'url' => admin_url('admin.php?page=ypm-taxonomies')],
      'menus' => ['label' => __('Menu localization', 'yablonsky-multilingual'), 'ok' => (int) $menus['total'] === 0 || (int) $menus['percent'] === 100, 'detail' => (string) $menus['detail'], 'url' => admin_url('admin.php?page=ypm-menu-translation')],
      'ai' => ['label' => __('Yablonsky AI', 'yablonsky-multilingual'), 'ok' => $ai_operational, 'detail' => !$ai_connected ? __('Optional connection setup available.', 'yablonsky-multilingual') : ($ai_operational ? __('Connected route is operational.', 'yablonsky-multilingual') : __('Connected account still needs a usable route.', 'yablonsky-multilingual')), 'url' => admin_url('admin.php?page=ypm-ai')],
    ];
    if ($woo_active && is_array($woo)) {
      $critical['woocommerce'] = ['label' => __('WooCommerce localization', 'yablonsky-multilingual'), 'ok' => (int) $woo['total'] === 0 || (int) $woo['percent'] === 100, 'detail' => sprintf(
        /* translators: 1: completed records, 2: required records. */
        __('%1$d of %2$d required commerce locale records complete.', 'yablonsky-multilingual'), (int) $woo['done'], (int) $woo['total']
      ), 'url' => admin_url('admin.php?page=ypm-woocommerce')];
    }

    $route_health = class_exists('Yablonsky\Multilingual\Admin') ? Admin::feature_route_health() : [];
    $admin_routes_ok = $route_health !== [];
    foreach ($route_health as $route) if (empty($route['ok'])) { $admin_routes_ok = false; break; }
    $fx_state = class_exists('Yablonsky\Multilingual\FXRates') ? FXRates::state() : [];
    $mc_settings = class_exists('Yablonsky\Multilingual\MultiCurrency') ? MultiCurrency::settings() : [];
    $fx_auto = (string) ($mc_settings['rate_source'] ?? 'manual') === 'frankfurter_ecb';
    $fx_fresh = !$fx_auto || (class_exists('Yablonsky\Multilingual\FXRates') && FXRates::is_fresh());
    $memory_stats = class_exists('Yablonsky\Multilingual\TranslationMemory') ? TranslationMemory::stats() : ['entries'=>0,'hits'=>0,'hit_events'=>0,'last_hit_at'=>''];
    $autopilot_settings = class_exists('Yablonsky\Multilingual\Autopilot') ? Autopilot::settings() : [];
    $autopilot_stats = class_exists('Yablonsky\Multilingual\Autopilot') ? Autopilot::stats() : ['queued'=>0,'processing'=>0,'review_ready'=>0,'completed'=>0,'skipped'=>0,'failed'=>0];
    $autopilot_daily = class_exists('Yablonsky\Multilingual\Autopilot') ? Autopilot::daily_state() : ['date'=>gmdate('Y-m-d'),'count'=>0,'cap'=>0];
    $autopilot_runner = class_exists('Yablonsky\Multilingual\Autopilot') ? Autopilot::runner_state() : ['engine'=>'wp_cron','next'=>0,'healthy'=>true,'pending'=>0,'recovered'=>0];
    $autopilot_enabled = !empty($autopilot_settings['enabled']);
    $autopilot_paused = !empty($autopilot_settings['paused']);
    $autopilot_failed = (int) ($autopilot_stats['failed'] ?? 0);
    $autopilot_state = !$autopilot_enabled ? 'neutral' : (($autopilot_paused || $autopilot_failed > 0 || empty($autopilot_runner['healthy'])) ? 'attention' : 'pass');
    $autopilot_detail = !$autopilot_enabled
      ? __('Optional continuous translation is off.', 'yablonsky-multilingual')
      : sprintf(
          /* translators: 1: queued/processing tasks, 2: review-ready tasks, 3: AI actions used today, 4: daily cap or infinity symbol. */
          __('%1$d pending · %2$d review ready · AI today %3$d/%4$s.', 'yablonsky-multilingual'),
          (int) ($autopilot_stats['queued'] ?? 0) + (int) ($autopilot_stats['processing'] ?? 0),
          (int) ($autopilot_stats['review_ready'] ?? 0),
          (int) ($autopilot_daily['count'] ?? 0),
          (int) ($autopilot_daily['cap'] ?? 0) === 0 ? '∞' : (string) (int) $autopilot_daily['cap']
        );
    if ($autopilot_enabled) {
      $autopilot_detail .= ' ' . sprintf(
        /* translators: %s: background runner name. */
        __('Runner: %s.', 'yablonsky-multilingual'),
        (string) ($autopilot_runner['engine'] ?? '') === 'action_scheduler' ? __('Action Scheduler', 'yablonsky-multilingual') : __('WP-Cron', 'yablonsky-multilingual')
      );
    }

    $optional = [
      'media' => ['label' => __('Media localization', 'yablonsky-multilingual'), 'state' => (int) $media['total'] === 0 ? 'neutral' : ((int) $media['percent'] === 100 ? 'pass' : 'attention'), 'detail' => (string) $media['detail'], 'url' => admin_url('admin.php?page=ypm-media')],
      'xliff' => ['label' => __('XLIFF interchange', 'yablonsky-multilingual'), 'state' => $xliff ? 'pass' : 'attention', 'detail' => $xliff ? __('PHP DOM is available for XLIFF 1.2/2.0.', 'yablonsky-multilingual') : __('Setup required — install PHP DOM to activate XLIFF 1.2/2.0.', 'yablonsky-multilingual'), 'url' => admin_url('admin.php?page=ypm-translation-jobs')],
      'routing' => ['label' => __('Advanced routing', 'yablonsky-multilingual'), 'state' => 'neutral', 'detail' => __('Localized bases, browser detection and separate domains are optional deployment choices.', 'yablonsky-multilingual'), 'url' => admin_url('admin.php?page=ypm-routing')],
      'visual' => ['label' => __('Visual Review', 'yablonsky-multilingual'), 'state' => 'neutral', 'detail' => __('Side-by-side frontend QA runs from the administrator workflow while the public frontend keeps its existing runtime.', 'yablonsky-multilingual'), 'url' => admin_url('admin.php?page=ypm-visual-review')],
      'admin_routes' => ['label' => __('YMulti admin routes', 'yablonsky-multilingual'), 'state' => $admin_routes_ok ? 'pass' : 'attention', 'detail' => $admin_routes_ok ? __('Routing, Visual Review and Launch Center are registered under canonical admin.php?page=… URLs.', 'yablonsky-multilingual') : __('Feature-page registration needs attention under the YMulti parent menu.', 'yablonsky-multilingual'), 'url' => admin_url('admin.php?page=ypm-system-status')],
      'developer_api' => ['label' => __('Developer REST API', 'yablonsky-multilingual'), 'state' => 'pass', 'detail' => __('Read-only ymulti/v1 language, translation-graph and administrator coverage endpoints are registered.', 'yablonsky-multilingual'), 'url' => rest_url('ymulti/v1/languages')],
      'translation_memory' => ['label' => __('Translation memory', 'yablonsky-multilingual'), 'state' => (int) ($memory_stats['entries'] ?? 0) > 0 ? 'pass' : 'neutral', 'detail' => sprintf(
        /* translators: 1: reusable entries, 2: exact-match segments reused. */
        __('%1$d reusable exact-match entries · %2$d segments reused locally.', 'yablonsky-multilingual'),
        (int) ($memory_stats['entries'] ?? 0),
        (int) ($memory_stats['hits'] ?? 0)
      ), 'url' => admin_url('admin.php?page=ypm-ai')],
      'autopilot' => ['label' => __('Continuous translation Autopilot', 'yablonsky-multilingual'), 'state' => $autopilot_state, 'detail' => $autopilot_detail, 'url' => admin_url('admin.php?page=ypm-autopilot')],
      'string_interchange' => ['label' => __('String PO interchange', 'yablonsky-multilingual'), 'state' => 'pass', 'detail' => __('PO export/import is available per locale and text domain; XLIFF remains available for content jobs.', 'yablonsky-multilingual'), 'url' => admin_url('admin.php?page=ypm-strings')],
      'custom_fields' => ['label' => __('Custom-field policies', 'yablonsky-multilingual'), 'state' => 'neutral', 'detail' => __('Public custom fields can be synchronized, seeded once or managed independently per language.', 'yablonsky-multilingual'), 'url' => admin_url('admin.php?page=ypm-custom-fields')],
      'fx' => ['label' => __('Multicurrency rate cache', 'yablonsky-multilingual'), 'state' => $fx_fresh ? 'pass' : 'attention', 'detail' => !$fx_auto ? __('Manual-rate mode uses merchant-owned local rates.', 'yablonsky-multilingual') : ($fx_fresh ? sprintf(
        /* translators: %s: provider source date. */
        __('Automatic ECB reference cache is fresh (source %s).', 'yablonsky-multilingual'), (string) ($fx_state['source_date'] ?? '—')
      ) : __('Refresh required — Automatic FX is active and the local rate cache needs current rates.', 'yablonsky-multilingual')), 'url' => admin_url('admin.php?page=ypm-woocommerce#ymulti-multicurrency')],
    ];
    $passed = 0;
    foreach ($critical as $row) if (!empty($row['ok'])) $passed++;
    return [
      'critical' => $critical,
      'optional' => $optional,
      'passed' => $passed,
      'total' => count($critical),
      'ready' => $passed === count($critical),
      'generated_at' => gmdate('c'),
    ];
  }

  public static function page(): void {
    if (!current_user_can('manage_options')) return;
    $report = self::report();
    $launch_total = max(0, (int) ($report['total'] ?? 0));
    $launch_passed = max(0, (int) ($report['passed'] ?? 0));
    $launch_percent = $launch_total > 0 ? (int) round(($launch_passed / $launch_total) * 100) : 0;
    echo '<div class="wrap ypm-admin">';
    Admin::render_shell_header(__('Launch Center', 'yablonsky-multilingual'), __('One place to measure whether this installed site is ready to expose all configured languages publicly, using live workload evidence alongside plugin capability.', 'yablonsky-multilingual'), !empty($report['ready']) ? 'pass' : 'attention', !empty($report['ready']) ? __('Launch ready', 'yablonsky-multilingual') : __('Work remains', 'yablonsky-multilingual'));
    echo '<section class="ypm-card ypm-card--standalone"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Critical launch gates', 'yablonsky-multilingual') . '</span><h2>' . esc_html((string) $launch_passed . ' / ' . (string) $launch_total . ' ' . __('complete', 'yablonsky-multilingual')) . '</h2><p class="ypm-card__copy">' . esc_html__('Green represents completed measured work or a passing runtime check for this installed site.', 'yablonsky-multilingual') . '</p></div></div>';
    echo '<div class="ypm-progress ymulti-launch-progress"><div class="ypm-progress__meta"><strong>' . esc_html(sprintf(
      /* translators: %d: installed-site launch-gate completion percentage. */
      __('%d%% installed-site launch readiness', 'yablonsky-multilingual'), $launch_percent
    )) . '</strong><span>' . esc_html(sprintf(
      /* translators: 1: completed critical launch gates, 2: total critical launch gates. */
      __('%1$d of %2$d critical gates complete', 'yablonsky-multilingual'), $launch_passed, $launch_total
    )) . '</span></div><div class="ypm-progress__rail"><div class="ypm-progress__fill" style="width:' . esc_attr((string) $launch_percent) . '%"></div></div><small>' . esc_html__('This bar measures the installed site. Official Plugin Check and the live Autopilot/security acceptance sequence remain separate release evidence.', 'yablonsky-multilingual') . '</small></div><div class="ymulti-launch-gates">';
    foreach ($report['critical'] as $row) {
      $state = !empty($row['ok']) ? 'pass' : 'attention';
      echo '<a class="ymulti-launch-gate" href="' . esc_url((string) $row['url']) . '"><span class="dashicons ' . esc_attr(!empty($row['ok']) ? 'dashicons-yes-alt' : 'dashicons-warning') . '" aria-hidden="true"></span><div><strong>' . esc_html((string) $row['label']) . '</strong><small>' . esc_html((string) $row['detail']) . '</small></div>' . wp_kses_post(Admin::status_badge($state, !empty($row['ok']) ? __('Complete', 'yablonsky-multilingual') : __('Open', 'yablonsky-multilingual'))) . '</a>';
    }
    echo '</div></section>';
    $safe_preset_url = add_query_arg(['page' => 'ypm-autopilot', 'preset' => 'safe_smoke'], admin_url('admin.php'));
    $all_public_url = add_query_arg(['page' => 'ypm-autopilot', 'preset' => 'all_public'], admin_url('admin.php'));
    echo '<section class="ypm-card ypm-card--standalone ymulti-translation-campaign"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Translation campaign', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Smoke one language, then expand to all public targets', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('These shortcuts prepare the Autopilot form. Save plan and Launch Autopilot remain explicit workflow steps.', 'yablonsky-multilingual') . '</p></div></div><div class="ymulti-campaign-actions"><a class="button" href="' . esc_url($safe_preset_url) . '">' . esc_html__('Prepare safe first test', 'yablonsky-multilingual') . '</a><a class="button button-primary" href="' . esc_url($all_public_url) . '">' . esc_html__('Prepare all public languages', 'yablonsky-multilingual') . '</a></div></section>';
    echo '<section class="ypm-card ypm-card--standalone"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Optional deployment capabilities', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Available as an optional launch companion', 'yablonsky-multilingual') . '</h2></div></div><div class="ymulti-launch-gates">';
    foreach ($report['optional'] as $row) {
      echo '<a class="ymulti-launch-gate" href="' . esc_url((string) $row['url']) . '"><span class="dashicons dashicons-admin-tools" aria-hidden="true"></span><div><strong>' . esc_html((string) $row['label']) . '</strong><small>' . esc_html((string) $row['detail']) . '</small></div>' . wp_kses_post(Admin::status_badge((string) $row['state'], (string) ((string) $row['state'] === 'pass' ? __('Ready', 'yablonsky-multilingual') : __('Optional', 'yablonsky-multilingual')))) . '</a>';
    }
    echo '</div></section>';
    echo '<details class="ypm-disclosure ypm-disclosure--wide"><summary><span><strong>' . esc_html__('Machine-readable launch evidence', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Read-only snapshot for support or release acceptance', 'yablonsky-multilingual') . '</small></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary><div class="ypm-disclosure__body"><textarea class="large-text code" rows="12" readonly>' . esc_textarea((string) wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</textarea></div></details></div>';
  }
}
