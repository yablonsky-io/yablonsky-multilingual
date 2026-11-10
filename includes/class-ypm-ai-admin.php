<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Admin UX and human approval boundaries for Yablonsky AI translation. */
final class AIAdmin {
  public static function init(): void {
    if (!is_admin()) return;
    add_action('admin_menu', [__CLASS__, 'register_page'], 30);
    add_action('admin_post_ypm_ai_connect', [__CLASS__, 'handle_connect']);
    add_action('admin_post_ypm_ai_disconnect', [__CLASS__, 'handle_disconnect']);
    add_action('admin_post_ypm_ai_refresh', [__CLASS__, 'handle_refresh']);
    add_action('admin_post_ypm_ai_workspace', [__CLASS__, 'handle_workspace']);
    add_action('admin_post_ypm_ai_models', [__CLASS__, 'handle_models']);
    add_action('admin_post_ypm_ai_canary', [__CLASS__, 'handle_canary']);
    add_action('admin_post_ypm_ai_content_draft', [__CLASS__, 'handle_content_draft']);
    add_action('admin_post_ypm_ai_media_review', [__CLASS__, 'handle_media_review']);
    add_action('admin_post_ypm_ai_media_generate', [__CLASS__, 'handle_media_generate']);
    add_action('wp_ajax_ypm_ai_suggest', [__CLASS__, 'ajax_suggest']);
    add_action('wp_ajax_ypm_ai_model_catalog_refresh', [__CLASS__, 'ajax_model_catalog_refresh']);
    add_action('admin_notices', [__CLASS__, 'render_provenance_notice']);
  }

  public static function register_page(): void {
    add_submenu_page(
      'ypm-multilingual',
      __('Yablonsky AI', 'yablonsky-multilingual'),
      __('Yablonsky AI', 'yablonsky-multilingual'),
      'manage_options',
      'ypm-ai',
      [__CLASS__, 'render_page']
    );
  }

  public static function render_page(): void {
    if (!current_user_can('manage_options')) return;
    $profile = AITranslation::profile();
    $workspaces = AITranslation::profile_workspaces();
    $workspace = AITranslation::selected_workspace();
    $cap = AITranslation::capability();
    $connected = AITranslation::has_key() && !empty($profile);
    $profile_needs_refresh = AITranslation::profile_needs_refresh();
    $ready = AITranslation::ready();
    $text_models = AITranslation::text_models();
    $vision_models = AITranslation::vision_models();
    $image_models = AITranslation::image_models();
    $text_model_count = count($text_models);
    $image_model_count = count($image_models);
    $text_selected = AITranslation::selected_text_model();
    $vision_selected = AITranslation::selected_vision_model();
    $image_selected = AITranslation::selected_image_model();
    $manual_models = AITranslation::manual_model_overrides();
    $catalog = AITranslation::model_catalog_summary();
    $resolved_text = AITranslation::resolved_text_model();
    $route = AITranslation::text_route();
    $route_ready = $route === 'writer' ? (!empty($cap['enabled']) && $resolved_text !== '') : (!empty($cap['chat_enabled']) && $resolved_text !== '');

    echo '<div class="wrap ypm-admin ypm-admin--ai">';
    Admin::render_shell_header(
      __('Yablonsky AI', 'yablonsky-multilingual'),
      __('Connect one user-owned Yablonsky AI account, choose workspace model routing, and run controlled multilingual AI workflows while page code stays under local YMulti authority.', 'yablonsky-multilingual'),
      $ready ? 'pass' : ($connected ? 'attention' : 'neutral'),
      $ready ? __('Verified', 'yablonsky-multilingual') : ($connected ? __('Text canary required', 'yablonsky-multilingual') : __('Connection setup available', 'yablonsky-multilingual'))
    );

    self::render_notice();
    if ($connected && $profile_needs_refresh) {
      echo '<div class="notice notice-warning inline ypm-ai-profile-refresh"><p><strong>' . esc_html__('AI capability cache needs one refresh', 'yablonsky-multilingual') . '</strong> ' . esc_html__('This YMulti version understands a broader account model projection than the previously cached profile. Use Refresh account once below; remote AI starts with that explicit action.', 'yablonsky-multilingual') . '</p></div>';
    }

    echo '<div class="ypm-metrics ypm-metrics--4">';
    Admin::render_metric(__('Connection', 'yablonsky-multilingual'), $connected ? __('Connected', 'yablonsky-multilingual') : __('Connection setup available', 'yablonsky-multilingual'), $connected ? AITranslation::key_mask() : __('User API key', 'yablonsky-multilingual'), $connected ? 'pass' : 'neutral');
    Admin::render_metric(__('Workspace', 'yablonsky-multilingual'), $workspace ? (string) ($workspace['name'] ?? '—') : '—', __('Membership checked by API', 'yablonsky-multilingual'), $workspace ? 'pass' : 'neutral');
    Admin::render_metric(__('Text model', 'yablonsky-multilingual'), $resolved_text !== '' ? $resolved_text : '—', $route === 'writer' ? __('Auto · workspace Writer', 'yablonsky-multilingual') : __('Manual · conversation model', 'yablonsky-multilingual'), $route_ready ? 'pass' : ($connected ? 'attention' : 'neutral'));
    $credits = $cap['credits'];
    Admin::render_metric(__('Credits', 'yablonsky-multilingual'), $credits !== null ? (string) $credits : '—', $cap['total_credits'] !== null ? sprintf(
      /* translators: %s: total workspace credit allocation. */
      __('of %s total', 'yablonsky-multilingual'),
      (string) $cap['total_credits']
    ) : __('Workspace balance', 'yablonsky-multilingual'), 'neutral');
    echo '</div>';

    echo '<div class="ypm-dashboard-grid ypm-ai-grid">';
    echo '<section class="ypm-card ypm-card--primary"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Connection', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('User API key', 'yablonsky-multilingual') . '</h2></div>' . wp_kses_post(Admin::status_badge($connected ? 'pass' : 'neutral', $connected ? __('Connected', 'yablonsky-multilingual') : __('Required for AI only', 'yablonsky-multilingual'))) . '</div>';
    echo '<p class="ypm-card__copy">' . esc_html__('The key travels only to ai.yablonsky.io, is stored encrypted with this WordPress installation\'s keys, and appears as a masked value after saving. Local multilingual features remain independent of AI.', 'yablonsky-multilingual') . '</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ypm-ai-connect-form">';
    wp_nonce_field('ypm_ai_connect', 'ypm_ai_nonce');
    echo '<input type="hidden" name="action" value="ypm_ai_connect"><label for="ypm-ai-key"><span>' . esc_html($connected ? __('Replace API key', 'yablonsky-multilingual') : __('Yablonsky AI API key', 'yablonsky-multilingual')) . '</span><input id="ypm-ai-key" type="password" name="api_key" class="regular-text" autocomplete="new-password" spellcheck="false" required placeholder="••••••••••••••••"></label><p class="submit"><button class="button button-primary">' . esc_html($connected ? __('Validate & replace key', 'yablonsky-multilingual') : __('Connect account', 'yablonsky-multilingual')) . '</button></p></form>';
    if ($connected) {
      echo '<div class="ypm-inline-actions"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('ypm_ai_refresh', 'ypm_ai_nonce');
      echo '<input type="hidden" name="action" value="ypm_ai_refresh"><button class="button">' . esc_html__('Refresh account', 'yablonsky-multilingual') . '</button></form><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('ypm_ai_disconnect', 'ypm_ai_nonce');
      echo '<input type="hidden" name="action" value="ypm_ai_disconnect"><button class="button button-link-delete" data-ypm-ai-disconnect>' . esc_html__('Disconnect', 'yablonsky-multilingual') . '</button></form></div>';
    }
    echo '</section>';

    echo '<section class="ypm-card"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Authority', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Workspace', 'yablonsky-multilingual') . '</h2></div></div>';
    if (!$connected) {
      echo '<div class="ypm-empty-panel ypm-empty-panel--compact"><strong>' . esc_html__('Connect first', 'yablonsky-multilingual') . '</strong><p>' . esc_html__('Workspace, credits and model entitlement are discovered from the authenticated User API account.', 'yablonsky-multilingual') . '</p></div>';
    } else {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('ypm_ai_workspace', 'ypm_ai_nonce');
      echo '<input type="hidden" name="action" value="ypm_ai_workspace"><label for="ypm-ai-workspace"><span>' . esc_html__('Workspace', 'yablonsky-multilingual') . '</span><select id="ypm-ai-workspace" name="workspace_id">';
      foreach ($workspaces as $row) {
        $id = (string) ($row['id'] ?? '');
        $name = (string) ($row['name'] ?? $id);
        echo '<option value="' . esc_attr($id) . '"' . selected(AITranslation::workspace_id(), $id, false) . '>' . esc_html($name) . '</option>';
      }
      echo '</select></label><p class="submit"><button class="button">' . esc_html__('Use workspace', 'yablonsky-multilingual') . '</button></p></form>';
      if ($workspace) {
        echo '<div class="ypm-kv-list"><div><span>' . esc_html__('Plan', 'yablonsky-multilingual') . '</span><strong>' . esc_html($cap['plan_title'] !== '' ? $cap['plan_title'] : __('Plan name ready to set', 'yablonsky-multilingual')) . '</strong></div><div><span>' . esc_html__('Writer default', 'yablonsky-multilingual') . '</span><strong><code>' . esc_html($cap['model'] !== '' ? $cap['model'] : '—') . '</code></strong></div><div><span>' . esc_html__('Chat route', 'yablonsky-multilingual') . '</span><strong>' . esc_html(!empty($cap['chat_enabled']) ? __('Available', 'yablonsky-multilingual') : __('Setup required', 'yablonsky-multilingual')) . '</strong></div></div>';
      }
    }
    echo '</section></div>';

    echo '<section class="ypm-card ypm-card--standalone ypm-ai-model-routing"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Model routing', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Text, Vision & Image', 'yablonsky-multilingual') . '</h2></div>';
    if ($connected) {
      echo '<div class="ypm-ai-model-head-actions"><span class="ypm-status-badge ypm-status-badge--neutral" data-ypm-model-counts>' . esc_html(sprintf(
        /* translators: 1: number of text-capable models, 2: number of vision-capable models, 3: number of image-generation models. */
        __('%1$d text · %2$d vision · %3$d image', 'yablonsky-multilingual'),
        $text_model_count,
        count($vision_models),
        $image_model_count
      )) . '</span><button type="button" class="button" data-ypm-model-refresh>' . esc_html__('Refresh model catalog', 'yablonsky-multilingual') . '</button></div>';
    }
    echo '</div>';
    echo '<p class="ypm-card__copy">' . esc_html__('Choose from the models exposed by the connected Yablonsky AI account. Search is instant inside the loaded account catalog; Refresh model catalog performs the explicit authenticated AJAX refresh. Text, Vision and Image keep separate, explicit routes.', 'yablonsky-multilingual') . '</p>';
    if (!$connected) {
      echo '<div class="ypm-empty-panel ypm-empty-panel--compact"><strong>' . esc_html__('Connect an account to discover plan models.', 'yablonsky-multilingual') . '</strong></div>';
    } else {
      if ($text_model_count <= 1 || $image_model_count === 0) {
        echo '<div class="ypm-inline-hint" data-ypm-model-narrow><strong>' . esc_html__('Model catalog looks narrower than the connected account', 'yablonsky-multilingual') . '</strong><p>' . esc_html__('Use Refresh model catalog. YMulti now scans account-, plan-, workspace- and route-level capability shapes before falling back to exact manual IDs. A saved model still needs its real canary or generation request before it is treated as usable compute.', 'yablonsky-multilingual') . '</p></div>';
      }
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ypm-ai-model-form">';
      wp_nonce_field('ypm_ai_models', 'ypm_ai_nonce');
      echo '<input type="hidden" name="action" value="ypm_ai_models"><div class="ypm-ai-model-grid">';
      self::render_model_picker('text_model', 'ypm-ai-text-model', __('Text translation model', 'yablonsky-multilingual'), $text_selected, $text_models, 'auto', self::auto_text_model_label($cap), __('Search text models…', 'yablonsky-multilingual'), __('Manual text models are verified through a real conversation canary.', 'yablonsky-multilingual'));
      self::render_model_picker('vision_model', 'ypm-ai-vision-model', __('Vision model', 'yablonsky-multilingual'), $vision_selected, $vision_models, 'auto', __('Auto · best account candidate', 'yablonsky-multilingual'), __('Search Vision models…', 'yablonsky-multilingual'), __('Vision is used only for explicit media understanding. The first review is the live compatibility proof.', 'yablonsky-multilingual'));
      self::render_model_picker('image_model', 'ypm-ai-image-model', __('Image generation model', 'yablonsky-multilingual'), $image_selected, $image_models, 'auto', __('Auto · first available image model', 'yablonsky-multilingual'), __('Search image models…', 'yablonsky-multilingual'), __('Image generation stays separate from Vision, and generated assets enter the review workflow before replacing media.', 'yablonsky-multilingual'));
      echo '</div>';
      echo '<details class="ypm-ai-model-advanced"><summary>' . esc_html__('Advanced fallback · exact model IDs', 'yablonsky-multilingual') . '</summary><p>' . esc_html__('Use this when the authenticated account exposes a model ahead of the cached capability projection. Exact IDs merge locally while remote entitlement checks remain authoritative.', 'yablonsky-multilingual') . '</p><div class="ypm-ai-model-grid ypm-ai-model-grid--extras">';
      echo '<label for="ypm-ai-text-model-extra"><span>' . esc_html__('Extra text model IDs', 'yablonsky-multilingual') . '</span><input id="ypm-ai-text-model-extra" type="text" name="text_model_extra" value="' . esc_attr(implode(', ', $manual_models['text'] ?? [])) . '" placeholder="gpt-5.4-mini, claude-sonnet-4-6"><small>' . esc_html__('Comma or line separated.', 'yablonsky-multilingual') . '</small></label>';
      echo '<label for="ypm-ai-vision-model-extra"><span>' . esc_html__('Extra Vision model IDs', 'yablonsky-multilingual') . '</span><input id="ypm-ai-vision-model-extra" type="text" name="vision_model_extra" value="' . esc_attr(implode(', ', $manual_models['vision'] ?? [])) . '" placeholder="gpt-4o, gemini-2.5-pro"><small>' . esc_html__('Optional explicit Vision candidates.', 'yablonsky-multilingual') . '</small></label>';
      echo '<label for="ypm-ai-image-model-extra"><span>' . esc_html__('Extra image model IDs', 'yablonsky-multilingual') . '</span><input id="ypm-ai-image-model-extra" type="text" name="image_model_extra" value="' . esc_attr(implode(', ', $manual_models['image'] ?? [])) . '" placeholder="gpt-image-1, flux-pro, nano-banana"><small>' . esc_html__('Optional explicit image-generation candidates.', 'yablonsky-multilingual') . '</small></label>';
      echo '</div></details><p class="submit"><button class="button button-primary">' . esc_html__('Save model routing', 'yablonsky-multilingual') . '</button></p></form>';
    }
    echo '</section>';

    if ($connected) self::render_model_catalog_panel($catalog);

    echo '</div>';
  }


private static function auto_text_model_label(array $cap): string {
  $writer = sanitize_text_field((string) ($cap['model'] ?? ''));
  if ($writer === '') return __('Auto · workspace Writer', 'yablonsky-multilingual');
  /* translators: %s: workspace Writer model human-readable label. */
  return sprintf(__('Auto · %s (Workspace Writer)', 'yablonsky-multilingual'), self::model_label($writer));
}

/** @param array<int,string> $models */
private static function render_model_picker(string $name, string $id, string $title, string $selected, array $models, string $auto_value, string $auto_label, string $search_placeholder, string $help): void {
  $selected = $selected !== '' ? $selected : $auto_value;
  $selected_label = $selected === $auto_value ? $auto_label : self::model_label($selected);
  echo '<div class="ypm-model-field"><span class="ypm-model-field__label">' . esc_html($title) . '</span><div class="ypm-model-picker" data-ypm-model-picker>';
  echo '<input type="hidden" id="' . esc_attr($id . '-value') . '" name="' . esc_attr($name) . '" value="' . esc_attr($selected) . '" data-ypm-model-value>';
  echo '<button type="button" class="ypm-model-picker__button" id="' . esc_attr($id) . '" data-ypm-model-toggle aria-haspopup="listbox" aria-expanded="false" aria-controls="' . esc_attr($id . '-popover') . '"><span data-ypm-model-current>' . esc_html($selected_label) . '</span><span class="ypm-model-picker__caret" aria-hidden="true">⌄</span></button>';
  echo '<div class="ypm-model-picker__popover" id="' . esc_attr($id . '-popover') . '" data-ypm-model-popover hidden><label class="ypm-model-picker__search"><span class="screen-reader-text">' . esc_html($search_placeholder) . '</span><input type="search" placeholder="' . esc_attr($search_placeholder) . '" autocomplete="off" data-ypm-model-search></label><div class="ypm-model-picker__list" role="listbox" data-ypm-model-list>';
  self::render_model_picker_option($auto_value, $auto_label, $selected === $auto_value, $auto_value === 'auto' ? __('Auto', 'yablonsky-multilingual') : $auto_value);
  foreach ($models as $model) {
    $model = sanitize_text_field((string) $model);
    if ($model === '' || $model === $auto_value) continue;
    self::render_model_picker_option($model, self::model_label($model), $selected === $model, $model);
  }
  echo '<div class="ypm-model-picker__empty" data-ypm-model-empty hidden>' . esc_html__('0 models match this search.', 'yablonsky-multilingual') . '</div></div></div></div><small>' . esc_html($help) . '</small></div>';
}

private static function render_model_picker_option(string $value, string $label, bool $selected, string $secondary): void {
  $search = strtolower(trim($label . ' ' . $secondary . ' ' . $value));
  echo '<button type="button" role="option" class="ypm-model-picker__option' . ($selected ? ' is-selected' : '') . '" data-ypm-model-option data-model-id="' . esc_attr($value) . '" data-model-label="' . esc_attr($label) . '" data-model-search="' . esc_attr($search) . '" aria-selected="' . ($selected ? 'true' : 'false') . '"><strong>' . esc_html($label) . '</strong><small>' . esc_html($secondary) . '</small></button>';
}

private static function model_label(string $model): string {
  $model = trim($model);
  if ($model === '') return '';
  $label = str_replace(['/', '_', '-'], ' ', $model);
  $label = preg_replace('/\\s+/', ' ', $label) ?: $label;
  $parts = explode(' ', trim($label));
  $parts = array_map(static function (string $part): string {
    $lower = strtolower($part);
    if (in_array($lower, ['gpt', 'ai', 'llm', 'vl', 'sd', 'sdxl', 'api'], true)) return strtoupper($part);
    if ($lower === 'c4ai') return 'C4AI';
    if ($lower === 'googleai') return 'Google AI';
    if ($lower === 'xai') return 'xAI';
    return preg_match('/^[0-9.]+$/', $part) ? $part : ucfirst($part);
  }, $parts);
  return implode(' ', $parts);
}

/** @param array<string,mixed> $catalog */
private static function render_model_catalog_panel(array $catalog): void {
  $discovered = isset($catalog['discovered']) && is_array($catalog['discovered']) ? $catalog['discovered'] : [];
  $manual = isset($catalog['manual']) && is_array($catalog['manual']) ? $catalog['manual'] : [];
  $combined = isset($catalog['combined']) && is_array($catalog['combined']) ? $catalog['combined'] : [];
  $refreshed = sanitize_text_field((string) ($catalog['refreshed_at'] ?? ''));
  echo '<section class="ypm-card ypm-card--standalone ypm-model-catalog" data-ymulti-model-catalog><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Catalog diagnostics', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('AI model catalog evidence', 'yablonsky-multilingual') . '</h2></div><button type="button" class="button" data-ymulti-model-catalog-toggle aria-expanded="false" data-expand-label="' . esc_attr__('Show full catalog', 'yablonsky-multilingual') . '" data-collapse-label="' . esc_attr__('Collapse catalog', 'yablonsky-multilingual') . '">' . esc_html__('Show full catalog', 'yablonsky-multilingual') . '</button></div>';
  echo '<p class="ypm-card__copy">' . esc_html__('This panel shows what YMulti can currently see for the selected workspace: discovered capability projection from the authenticated account, local manual extras, and the combined selector result used by the routing UI.', 'yablonsky-multilingual') . '</p>';
  $manual_total = count(isset($manual['text']) && is_array($manual['text']) ? $manual['text'] : []) + count(isset($manual['vision']) && is_array($manual['vision']) ? $manual['vision'] : []) + count(isset($manual['image']) && is_array($manual['image']) ? $manual['image'] : []);
  echo '<div class="ypm-model-catalog-grid">';
  echo '<div><span>' . esc_html__('Discovered text', 'yablonsky-multilingual') . '</span><strong>' . esc_html((string) count(isset($combined['text']) && is_array($combined['text']) ? $combined['text'] : [])) . '</strong><small>' . esc_html__('Conversation and translation routes.', 'yablonsky-multilingual') . '</small></div>';
  echo '<div><span>' . esc_html__('Discovered vision', 'yablonsky-multilingual') . '</span><strong>' . esc_html((string) count(isset($combined['vision']) && is_array($combined['vision']) ? $combined['vision'] : [])) . '</strong><small>' . esc_html__('Explicit image-understanding routes.', 'yablonsky-multilingual') . '</small></div>';
  echo '<div><span>' . esc_html__('Discovered image', 'yablonsky-multilingual') . '</span><strong>' . esc_html((string) count(isset($combined['image']) && is_array($combined['image']) ? $combined['image'] : [])) . '</strong><small>' . esc_html__('Image-generation and editing routes.', 'yablonsky-multilingual') . '</small></div>';
  echo '<div><span>' . esc_html__('Last refresh', 'yablonsky-multilingual') . '</span><strong>' . esc_html($refreshed !== '' ? gmdate('Y-m-d H:i', strtotime($refreshed)) : '—') . '</strong><small>' . esc_html(sprintf(
    /* translators: %d: number of locally supplied model IDs merged into the account catalog. */
    _n('%d manual extra', '%d manual extras', $manual_total, 'yablonsky-multilingual'),
    $manual_total
  )) . '</small></div>';
  echo '</div>';
  echo '<div class="ypm-model-catalog-sections">';
  self::render_model_catalog_route_section(__('Text', 'yablonsky-multilingual'), __('Conversation and translation-capable model IDs.', 'yablonsky-multilingual'), isset($combined['text']) && is_array($combined['text']) ? $combined['text'] : [], isset($manual['text']) && is_array($manual['text']) ? $manual['text'] : []);
  self::render_model_catalog_route_section(__('Vision', 'yablonsky-multilingual'), __('Models available for explicit image understanding review.', 'yablonsky-multilingual'), isset($combined['vision']) && is_array($combined['vision']) ? $combined['vision'] : [], isset($manual['vision']) && is_array($manual['vision']) ? $manual['vision'] : []);
  self::render_model_catalog_route_section(__('Image', 'yablonsky-multilingual'), __('Models reserved for image generation/editing routing.', 'yablonsky-multilingual'), isset($combined['image']) && is_array($combined['image']) ? $combined['image'] : [], isset($manual['image']) && is_array($manual['image']) ? $manual['image'] : []);
  echo '</div></section>';
}

/** @param array<int,string> $models @param array<int,string> $manual */
private static function render_model_catalog_route_section(string $title, string $copy, array $models, array $manual): void {
  echo '<section class="ypm-model-route"><h3>' . esc_html($title) . '</h3><p>' . esc_html($copy) . '</p><div class="ypm-model-route__chips-wrap">';
  if ($models) {
    echo '<div class="ypm-chip-list ypm-model-catalog-chips">';
    foreach ($models as $model) {
      $is_manual = in_array($model, $manual, true);
      echo '<span class="ypm-chip">' . esc_html($model) . ($is_manual ? ' <code>+' . esc_html__('manual', 'yablonsky-multilingual') . '</code>' : '') . '</span>';
    }
    echo '</div>';
  } else {
    echo '<div class="ypm-empty-panel ypm-empty-panel--compact"><strong>' . esc_html__('Model routes appear here after discovery', 'yablonsky-multilingual') . '</strong><p>' . esc_html__('Refresh the account or add exact model IDs above to expose additional tools for this workspace.', 'yablonsky-multilingual') . '</p></div>';
  }
  echo '</div></section>';
}


  public static function render_canary_panel(): void {
    if (!current_user_can('manage_options')) return;
    self::render_notice();
    $canary = AITranslation::canary();
    $connected = AITranslation::has_key() && !empty(AITranslation::profile());
    $ready = AITranslation::ready();
    $cap = AITranslation::capability();
    $resolved_text = AITranslation::resolved_text_model();
    $route = AITranslation::text_route();
    $route_ready = $route === 'writer' ? (!empty($cap['enabled']) && $resolved_text !== '') : (!empty($cap['chat_enabled']) && $resolved_text !== '');

    echo '<section id="ypm-ai-canary" class="ypm-card ypm-card--standalone ypm-ai-canary"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Live acceptance gate', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Selected text-model canary', 'yablonsky-multilingual') . '</h2></div>';
    echo wp_kses_post(Admin::status_badge($ready ? 'pass' : 'attention', $ready ? __('PASS', 'yablonsky-multilingual') : __('Required', 'yablonsky-multilingual')));
    echo '</div><p class="ypm-card__copy">' . esc_html__('A live canary proves compute access with one small request through the selected Writer or conversation route. A workspace or text-model change refreshes the canary requirement.', 'yablonsky-multilingual') . '</p>';
    if (!empty($canary['tested_at'])) {
      $canary_meta = (string) $canary['tested_at'];
      if (!empty($canary['model'])) $canary_meta .= ' · ' . sanitize_text_field((string) $canary['model']);
      if (!empty($canary['route'])) $canary_meta .= ' · ' . sanitize_text_field((string) $canary['route']);
      echo '<div class="ypm-ai-canary-result"><strong>' . esc_html($ready ? __('Latest canary matches the current workspace and text route.', 'yablonsky-multilingual') : __('Refresh the live canary for the selected workspace/model.', 'yablonsky-multilingual')) . '</strong><span>' . esc_html($canary_meta) . '</span></div>';
    }
    if ($connected && $route_ready) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('ypm_ai_canary', 'ypm_ai_nonce');
      echo '<input type="hidden" name="action" value="ypm_ai_canary"><input type="hidden" name="return_to" value="' . esc_attr(admin_url('admin.php?page=ypm-system-status#ypm-ai-canary')) . '"><button class="button button-primary">' . esc_html($ready ? __('Run canary again', 'yablonsky-multilingual') : __('Run live canary', 'yablonsky-multilingual')) . '</button></form>';
    } else {
      echo '<p><em>' . esc_html__('The selected route must be enabled by the workspace plan before the canary can run.', 'yablonsky-multilingual') . '</em></p>';
    }
    echo '</section>';
  }

  /**
   * Render one canonical text-compute gate across every bulk translation surface.
   * The canary state can be repaired in-place with one explicit live request.
   * Other setup states link to the single Yablonsky AI authority page.
   *
   * @param array<string,mixed> $setup
   */
  public static function render_text_compute_gate(array $setup, string $return_url = ''): void {
    self::render_compute_gate($setup, $return_url, true);
  }

  /** @param array<string,mixed> $setup */
  public static function render_vision_compute_gate(array $setup, string $return_url = ''): void {
    self::render_compute_gate($setup, $return_url, false);
  }

  /**
   * Render the shared Yablonsky AI setup contract for any remote-compute surface.
   * Text routes can repair a stale canary in place; Vision uses the same layout
   * while keeping its request-as-proof compatibility model.
   *
   * @param array<string,mixed> $setup
   */
  private static function render_compute_gate(array $setup, string $return_url, bool $allow_inline_canary): void {
    if (!empty($setup['ready'])) return;
    $message = sanitize_text_field((string) ($setup['message'] ?? ''));
    $label = sanitize_text_field((string) ($setup['label'] ?? __('Configure Yablonsky AI', 'yablonsky-multilingual')));
    $state = sanitize_key((string) ($setup['state'] ?? ''));
    $settings_url = esc_url((string) ($setup['url'] ?? admin_url('admin.php?page=ypm-ai')));
    echo '<div class="ymulti-ai-setup-cta ymulti-ai-setup-cta--canonical"><span>' . esc_html($message) . '</span>';
    if ($allow_inline_canary && $state === 'canary' && current_user_can('manage_options')) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ymulti-ai-inline-canary">';
      wp_nonce_field('ypm_ai_canary', 'ypm_ai_nonce');
      echo '<input type="hidden" name="action" value="ypm_ai_canary">';
      if ($return_url !== '') echo '<input type="hidden" name="return_to" value="' . esc_attr($return_url) . '">';
      echo '<button class="button button-primary">' . esc_html__('Verify text route now', 'yablonsky-multilingual') . '</button></form>';
    } else {
      $target_url = $state === 'canary' ? admin_url('admin.php?page=ypm-system-status#ypm-ai-canary') : $settings_url;
      echo '<a class="button button-primary" href="' . esc_url($target_url) . '">' . esc_html($label) . '</a>';
    }
    echo '</div>';
  }

  public static function render_content_draft_button(int $source_id, string $target_locale, string $label = '', string $classes = 'button button-primary'): void {
    if ($source_id <= 0 || !Languages::is_active($target_locale) || !AITranslation::current_user_can_compute()) return;
    if ($label === '') $label = __('AI draft', 'yablonsky-multilingual');
    $classes = implode(' ', array_filter(array_map('sanitize_html_class', preg_split('/\s+/', trim($classes)) ?: [])));
    if ($classes === '') $classes = 'button button-primary';
    $nonce = wp_create_nonce('ypm_ai_content_' . $source_id . '_' . $target_locale);
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ypm-ai-inline-form">';
    echo '<input type="hidden" name="action" value="ypm_ai_content_draft">';
    echo '<input type="hidden" name="source" value="' . esc_attr((string) $source_id) . '">';
    echo '<input type="hidden" name="target_locale" value="' . esc_attr($target_locale) . '">';
    echo '<input type="hidden" name="ypm_ai_nonce" value="' . esc_attr($nonce) . '">';
    echo '<button type="submit" class="' . esc_attr($classes) . '">' . esc_html($label) . '</button></form>';
  }

  public static function handle_connect(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_ai_connect', 'ypm_ai_nonce');
    $raw = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
    $raw = trim((string) preg_replace('/[\r\n]+/', '', $raw));
    $result = AITranslation::connect($raw);
    self::redirect_result($result, __('Account connected. Choose model routing and run the live text-model canary before using AI translation.', 'yablonsky-multilingual'));
  }

  public static function handle_disconnect(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_ai_disconnect', 'ypm_ai_nonce');
    AITranslation::disconnect();
    self::set_notice('success', __('Yablonsky AI disconnected. Local multilingual features are unchanged.', 'yablonsky-multilingual'));
    wp_safe_redirect(admin_url('admin.php?page=ypm-ai'));
    exit;
  }

  public static function handle_refresh(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_ai_refresh', 'ypm_ai_nonce');
    $result = AITranslation::refresh_profile();
    self::redirect_result($result, __('Account and workspace capabilities refreshed.', 'yablonsky-multilingual'));
  }

  public static function handle_workspace(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_ai_workspace', 'ypm_ai_nonce');
    $raw = filter_input(INPUT_POST, 'workspace_id', FILTER_UNSAFE_RAW);
    $id = is_string($raw) ? sanitize_text_field($raw) : '';
    if (!AITranslation::set_workspace($id)) {
      self::set_notice('error', __('Choose a workspace available to the connected account.', 'yablonsky-multilingual'));
    } else {
      self::set_notice('success', __('Workspace selected. Review model routing and run the live text-model canary for this workspace.', 'yablonsky-multilingual'));
    }
    wp_safe_redirect(admin_url('admin.php?page=ypm-ai'));
    exit;
  }

  public static function handle_models(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_ai_models', 'ypm_ai_nonce');
    $text_raw = filter_input(INPUT_POST, 'text_model', FILTER_UNSAFE_RAW);
    $vision_raw = filter_input(INPUT_POST, 'vision_model', FILTER_UNSAFE_RAW);
    $image_raw = filter_input(INPUT_POST, 'image_model', FILTER_UNSAFE_RAW);
    $text_extra_raw = filter_input(INPUT_POST, 'text_model_extra', FILTER_UNSAFE_RAW);
    $vision_extra_raw = filter_input(INPUT_POST, 'vision_model_extra', FILTER_UNSAFE_RAW);
    $image_extra_raw = filter_input(INPUT_POST, 'image_model_extra', FILTER_UNSAFE_RAW);
    $text = is_string($text_raw) ? sanitize_text_field($text_raw) : 'auto';
    $vision = is_string($vision_raw) ? sanitize_text_field($vision_raw) : 'auto';
    $image = is_string($image_raw) ? sanitize_text_field($image_raw) : 'auto';
    AITranslation::set_manual_model_overrides(
      is_string($text_extra_raw) ? $text_extra_raw : '',
      is_string($vision_extra_raw) ? $vision_extra_raw : '',
      is_string($image_extra_raw) ? $image_extra_raw : ''
    );
    if (!AITranslation::set_model_routing($text, $vision, $image)) {
      self::set_notice('error', __('Selected model availability changed for the current workspace plan. Refresh the account or correct the manual IDs, then choose again.', 'yablonsky-multilingual'));
    } else {
      self::set_notice('success', __('AI model routing saved. Manual route extras were merged locally. Run the live text-model canary whenever the text route changes.', 'yablonsky-multilingual'));
    }
    wp_safe_redirect(admin_url('admin.php?page=ypm-ai'));
    exit;
  }

  public static function ajax_model_catalog_refresh(): void {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => __('Forbidden', 'yablonsky-multilingual')], 403);
    check_ajax_referer('ypm_ai_model_catalog_refresh', 'nonce');
    $result = AITranslation::refresh_profile();
    if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 400);
    wp_send_json_success([
      'message' => __('Model catalog refreshed. Reloading the selectors…', 'yablonsky-multilingual'),
      'text' => AITranslation::text_models(),
      'vision' => AITranslation::vision_models(),
      'image' => AITranslation::image_models(),
      'writer' => sanitize_text_field((string) (AITranslation::capability()['model'] ?? '')),
    ]);
  }

  public static function handle_canary(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_ai_canary', 'ypm_ai_nonce');
    $result = AITranslation::run_canary();
    if (is_wp_error($result)) {
      self::set_notice('error', $result->get_error_message());
      wp_safe_redirect(admin_url('admin.php?page=ypm-system-status#ypm-ai-canary'));
      exit;
    }
    self::set_notice('success', __('Selected text-model canary passed. AI translation actions are now available for this workspace and model route.', 'yablonsky-multilingual'));
    $return_raw = filter_input(INPUT_POST, 'return_to', FILTER_UNSAFE_RAW);
    $return_to = is_string($return_raw) ? wp_validate_redirect(wp_unslash($return_raw), '') : '';
    if ($return_to !== '') {
      wp_safe_redirect(add_query_arg('ymulti_ai_canary', 'passed', $return_to));
      exit;
    }
    wp_safe_redirect(admin_url('admin.php?page=ypm-system-status#ypm-ai-canary'));
    exit;
  }

  public static function handle_content_draft(): void {
    $source_raw = filter_input(INPUT_POST, 'source', FILTER_VALIDATE_INT);
    $target_raw = filter_input(INPUT_POST, 'target_locale', FILTER_UNSAFE_RAW);
    $source_id = $source_raw ? (int) $source_raw : 0;
    $target = is_string($target_raw) ? sanitize_text_field($target_raw) : '';
    if ($source_id <= 0 || !Languages::is_active($target)) {
      wp_die(esc_html__('Invalid or expired AI translation request.', 'yablonsky-multilingual'));
    }
    check_admin_referer('ypm_ai_content_' . $source_id . '_' . $target, 'ypm_ai_nonce');
    if (!current_user_can('edit_post', $source_id) || !AITranslation::current_user_can_compute()) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));

    $plan = AITranslation::content_stage_plan($source_id, $target);
    if (is_wp_error($plan)) {
      self::set_notice('error', $plan->get_error_message());
      wp_safe_redirect(AdminContent::family_detail_url($source_id));
      exit;
    }
    $stage_count = max(1, (int) ($plan['stages'] ?? 1));
    if ($stage_count > 1 && class_exists('Yablonsky\Multilingual\AIJobs')) {
      $queued = AIJobs::start_job([$source_id], $target, get_current_user_id());
      if (is_wp_error($queued)) {
        self::set_notice('error', $queued->get_error_message());
        wp_safe_redirect(AdminContent::family_detail_url($source_id));
        exit;
      }
      self::set_notice('success', sprintf(
        /* translators: %d: number of bounded AI translation stages queued for one content item. */
        __('Large content was queued as %d safe AI stages. The draft will appear only after every stage validates.', 'yablonsky-multilingual'),
        $stage_count
      ));
      wp_safe_redirect(admin_url('admin.php?page=ypm-translations'));
      exit;
    }

    $result = AITranslation::create_content_draft($source_id, $target, get_current_user_id());
    if (is_wp_error($result)) {
      self::set_notice('error', $result->get_error_message());
      wp_safe_redirect(AdminContent::family_detail_url($source_id));
      exit;
    }
    $edit = get_edit_post_link((int) $result, '');
    if ($edit) {
      wp_safe_redirect(add_query_arg('ypm_ai_draft_created', '1', $edit));
      exit;
    }
    wp_safe_redirect(AdminContent::family_detail_url($source_id));
    exit;
  }

  public static function handle_media_review(): void {
    $post_raw = filter_input(INPUT_POST, 'post_id', FILTER_VALIDATE_INT);
    $attachment_raw = filter_input(INPUT_POST, 'attachment_id', FILTER_VALIDATE_INT);
    $post_id = $post_raw ? (int) $post_raw : 0;
    $attachment_id = $attachment_raw ? (int) $attachment_raw : 0;
    if ($post_id <= 0 || $attachment_id <= 0) wp_die(esc_html__('Invalid media review request.', 'yablonsky-multilingual'));
    check_admin_referer('ypm_ai_media_review_' . $post_id . '_' . $attachment_id, 'ypm_ai_nonce');
    if (!current_user_can('edit_post', $post_id) || !AITranslation::current_user_can_compute()) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));

    $provenance = get_post_meta($post_id, AITranslation::META_PROVENANCE, true);
    if (!is_array($provenance) || ($provenance['provider'] ?? '') !== 'yablonsky_ai') wp_die(esc_html__('Choose a YMulti AI translation draft for this action.', 'yablonsky-multilingual'));
    $target = sanitize_text_field((string) ($provenance['target_locale'] ?? ''));
    $attachment_ids = isset($provenance['structure']['media']['attachment_ids']) && is_array($provenance['structure']['media']['attachment_ids']) ? array_values(array_unique(array_filter(array_map('absint', $provenance['structure']['media']['attachment_ids'])))) : [];
    if (!in_array($attachment_id, $attachment_ids, true)) wp_die(esc_html__('Choose an image from the protected media inventory for this AI draft.', 'yablonsky-multilingual'));

    $result = AITranslation::analyze_image_attachment($attachment_id, $target);
    if (is_wp_error($result)) {
      self::set_notice('error', $result->get_error_message());
    } else {
      $reviews = self::media_review_records($post_id);
      $reviews[(string) $attachment_id] = $result;
      update_post_meta($post_id, AITranslation::META_MEDIA_REVIEW, $reviews);
      self::set_notice('success', __('Vision media review completed. The analysis is advisory; image pixels, alt text and captions remain under human approval.', 'yablonsky-multilingual'));
    }
    $edit = get_edit_post_link($post_id, '');
    wp_safe_redirect($edit ? $edit . '#ypm-ai-media-review' : admin_url('admin.php?page=ypm-ai'));
    exit;
  }

  public static function handle_media_generate(): void {
    $post_raw = filter_input(INPUT_POST, 'post_id', FILTER_VALIDATE_INT);
    $attachment_raw = filter_input(INPUT_POST, 'attachment_id', FILTER_VALIDATE_INT);
    $locale_raw = filter_input(INPUT_POST, 'target_locale', FILTER_UNSAFE_RAW);
    $post_id = $post_raw ? (int) $post_raw : 0;
    $attachment_id = $attachment_raw ? (int) $attachment_raw : 0;
    $locale = is_string($locale_raw) ? sanitize_text_field($locale_raw) : '';
    if ($post_id <= 0 || $attachment_id <= 0 || !Languages::is_active($locale)) wp_die(esc_html__('Invalid localized image-generation request.', 'yablonsky-multilingual'));
    check_admin_referer('ypm_ai_media_generate_' . $post_id . '_' . $attachment_id . '_' . $locale, 'ypm_ai_nonce');
    if (!current_user_can('edit_post', $post_id) || !current_user_can('edit_post', $attachment_id) || !AITranslation::current_user_can_compute()) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    if (!class_exists('Yablonsky\\Multilingual\\MediaLocalization')) wp_die(esc_html__('Media localization setup requires the YMulti media workspace. Reload this page and review System if this state persists.', 'yablonsky-multilingual'));

    $result = MediaLocalization::generate_asset_draft($post_id, $attachment_id, $locale);
    if (is_wp_error($result)) self::set_notice('error', $result->get_error_message());
    else self::set_notice('success', __('Localized image draft generated and copied into the WordPress Media Library for human review. The source image and page remain authoritative.', 'yablonsky-multilingual'));
    $edit = get_edit_post_link($post_id, '');
    wp_safe_redirect($edit ? $edit . '#ypm-ai-media-review' : admin_url('admin.php?page=ypm-ai'));
    exit;
  }

  public static function ajax_suggest(): void {
    check_ajax_referer('ypm_ai_suggest', 'nonce');
    if (!AITranslation::current_user_can_compute()) wp_send_json_error(['message' => __('Forbidden', 'yablonsky-multilingual')], 403);
    if (!AITranslation::ready()) wp_send_json_error(['message' => __('Verify Yablonsky AI for the selected workspace to continue.', 'yablonsky-multilingual')], 409);
    $kind_raw = filter_input(INPUT_POST, 'kind', FILTER_UNSAFE_RAW);
    $kind = is_string($kind_raw) ? sanitize_key($kind_raw) : '';

    if ($kind === 'term') {
      $source_id = isset($_POST['source_id']) ? absint(wp_unslash($_POST['source_id'])) : 0;
      $taxonomy = isset($_POST['taxonomy']) ? sanitize_key(wp_unslash($_POST['taxonomy'])) : '';
      $target = isset($_POST['target_locale']) ? sanitize_text_field(wp_unslash($_POST['target_locale'])) : '';
      $object = get_taxonomy($taxonomy);
      if (!$object || empty($object->cap->manage_terms) || !current_user_can((string) $object->cap->manage_terms)) wp_send_json_error(['message' => __('Forbidden', 'yablonsky-multilingual')], 403);
      $result = AITranslation::suggest_term($source_id, $taxonomy, $target);
      if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 422);
      $doc = isset($result['document']) && is_array($result['document']) ? $result['document'] : [];
      wp_send_json_success([
        'name' => (string) $result['name'],
        'description' => (string) $result['description'],
        'slug' => (string) $result['slug'],
        'model' => sanitize_text_field((string) ($doc['model'] ?? '')),
        'cost' => is_numeric($doc['cost'] ?? null) ? (float) $doc['cost'] : null,
      ]);
    }

    if ($kind === 'string') {
      if (!current_user_can('manage_options')) wp_send_json_error(['message' => __('Forbidden', 'yablonsky-multilingual')], 403);
      $source = isset($_POST['source']) ? sanitize_textarea_field(wp_unslash($_POST['source'])) : '';
      $target = isset($_POST['target_locale']) ? sanitize_text_field(wp_unslash($_POST['target_locale'])) : '';
      $domain = isset($_POST['domain']) ? sanitize_key(wp_unslash($_POST['domain'])) : '';
      $context = isset($_POST['context']) ? sanitize_text_field(wp_unslash($_POST['context'])) : '';
      $result = AITranslation::suggest_string($source, $target, $domain, $context);
      if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 422);
      $doc = isset($result['document']) && is_array($result['document']) ? $result['document'] : [];
      wp_send_json_success([
        'translation' => (string) $result['translation'],
        'model' => sanitize_text_field((string) ($doc['model'] ?? '')),
        'cost' => is_numeric($doc['cost'] ?? null) ? (float) $doc['cost'] : null,
      ]);
    }

    wp_send_json_error(['message' => __('Unsupported AI suggestion type.', 'yablonsky-multilingual')], 400);
  }

  public static function render_provenance_notice(): void {
    $screen = get_current_screen();
    if (!$screen || ($screen->base ?? '') !== 'post') return;
    $post_raw = filter_input(INPUT_GET, 'post', FILTER_VALIDATE_INT);
    $post_id = $post_raw ? (int) $post_raw : 0;
    if ($post_id <= 0) return;
    $provenance = get_post_meta($post_id, AITranslation::META_PROVENANCE, true);
    if (!is_array($provenance) || ($provenance['provider'] ?? '') !== 'yablonsky_ai') return;
    self::render_notice();
    $stats = isset($provenance['structure']['stats']) && is_array($provenance['structure']['stats']) ? $provenance['structure']['stats'] : [];
    $media = isset($provenance['structure']['media']) && is_array($provenance['structure']['media']) ? $provenance['structure']['media'] : [];
    echo '<div class="notice notice-info"><p><strong>' . esc_html__('YMulti AI translation draft', 'yablonsky-multilingual') . '</strong> — ' . esc_html__('review this draft before publishing. SEO metadata remains under the configured SEO authority.', 'yablonsky-multilingual') . '</p>';
    if (!empty($stats['segments'])) {
      echo '<p>' . esc_html((string) $stats['segments']) . ' ' . esc_html__('structured language regions were localized while protected HTML/JavaScript/CSS source remained under YMulti control.', 'yablonsky-multilingual');
      if (!empty($stats['high_layout_risk'])) echo ' ' . esc_html__('Compact UI text was detected; preview buttons, navigation and badges in the target language.', 'yablonsky-multilingual');
      echo '</p>';
    }
    if (!empty($media['images'])) echo '<p>' . esc_html__('Images are present in this draft. Text alternatives and captions can be localized, while visible text inside image pixels stays under the media review workflow.', 'yablonsky-multilingual') . '</p>';
    $qa_url = class_exists('Yablonsky\Multilingual\AILayoutQA') ? AILayoutQA::preview_url($post_id) : '';
    $source_id = absint($provenance['source_id'] ?? 0);
    $layout_report = class_exists('Yablonsky\Multilingual\AILayoutQA') ? AILayoutQA::report($post_id) : [];
    echo '<p class="ypm-ai-review-actions"><span class="ypm-status-badge ypm-status-badge--pass">' . esc_html__('Structure PASS', 'yablonsky-multilingual') . '</span>';
    if ($layout_report) {
      if (($layout_report['status'] ?? '') === 'pass') {
        $viewport = max(0, (int) ($layout_report['viewport_width'] ?? 0));
        $layout_label = $viewport > 0 ? sprintf(
          /* translators: %d: viewport width in pixels used for the most recent Layout QA pass. */
          __('Layout PASS · %dpx', 'yablonsky-multilingual'),
          $viewport
        ) : __('Layout PASS', 'yablonsky-multilingual');
        echo ' ' . wp_kses_post(Admin::status_badge('pass', $layout_label));
      } else {
        echo ' ' . wp_kses_post(Admin::status_badge('attention', sprintf(
          /* translators: %d: number of issues found in the most recent Layout QA scan. */
          __('Layout REVIEW · %d', 'yablonsky-multilingual'),
          max(0, (int) ($layout_report['issue_count'] ?? 0))
        )));
      }
    } else {
      echo ' ' . wp_kses_post(Admin::status_badge('neutral', __('Layout QA ready', 'yablonsky-multilingual')));
    }
    if ($qa_url !== '') echo ' <a class="button button-primary" target="_blank" rel="noopener noreferrer" href="' . esc_url($qa_url) . '">' . esc_html__('Open Layout QA', 'yablonsky-multilingual') . '</a>';
    if ($source_id > 0 && current_user_can('edit_post', $source_id)) {
      $source_edit = get_edit_post_link($source_id, '');
      if ($source_edit) echo ' <a class="button" href="' . esc_url($source_edit) . '">' . esc_html__('Open source', 'yablonsky-multilingual') . '</a>';
    }
    echo '</p><p><small>' . esc_html__('Layout QA runs locally in a signed frontend preview. CSS stays unchanged and the layout report remains local.', 'yablonsky-multilingual') . '</small></p>';
    self::render_media_review_panel($post_id, $provenance, $media);
    echo '</div>';
  }

  /** @return array<string,array<string,mixed>> */
  private static function media_review_records(int $post_id): array {
    $reviews = get_post_meta($post_id, AITranslation::META_MEDIA_REVIEW, true);
    if (!is_array($reviews)) return [];
    $clean = [];
    foreach ($reviews as $attachment_id => $review) {
      if (!is_array($review)) continue;
      $id = absint($attachment_id);
      if ($id <= 0) $id = absint($review['attachment_id'] ?? 0);
      if ($id <= 0) continue;
      $stored_modified = sanitize_text_field((string) ($review['attachment_modified_gmt'] ?? ''));
      $current_modified = sanitize_text_field((string) get_post_field('post_modified_gmt', $id));
      if ($stored_modified !== '' && $current_modified !== '' && $stored_modified !== $current_modified) continue;
      $clean[(string) $id] = $review;
    }
    return $clean;
  }

  /** @param array<string,mixed> $provenance @param array<string,mixed> $media */
  private static function render_media_review_panel(int $post_id, array $provenance, array $media): void {
    $attachment_ids = isset($media['attachment_ids']) && is_array($media['attachment_ids']) ? array_values(array_unique(array_filter(array_map('absint', $media['attachment_ids'])))) : [];
    if (!$attachment_ids) return;
    $target = sanitize_text_field((string) ($provenance['target_locale'] ?? ''));
    $reviews = self::media_review_records($post_id);
    $vision_model = AITranslation::resolved_vision_model();
    $image_model = AITranslation::resolved_image_model();
    $can_analyze = AITranslation::current_user_can_compute() && AITranslation::ready() && $vision_model !== '';
    $can_generate = AITranslation::current_user_can_compute() && $image_model !== '';

    echo '<div id="ypm-ai-media-review" class="ypm-ai-media-review"><div class="ypm-ai-media-review__head"><div><strong>' . esc_html__('Media localization review', 'yablonsky-multilingual') . '</strong><p>' . esc_html__('Vision analysis is optional and runs when you request it for an individual image. It may consume workspace credits. Image pixels stay under media authority, and Vision suggestions remain review-only until you apply locale-specific media text.', 'yablonsky-multilingual') . '</p></div>';
    if ($vision_model !== '') echo '<span class="ypm-status-badge ypm-status-badge--neutral">' . esc_html($vision_model) . '</span>';
    echo '</div><div class="ypm-ai-media-review__list">';
    $shown = 0;
    foreach ($attachment_ids as $attachment_id) {
      if ($shown >= 5) break;
      if (get_post_type($attachment_id) !== 'attachment' || strpos((string) get_post_mime_type($attachment_id), 'image/') !== 0) continue;
      $shown++;
      $review = isset($reviews[(string) $attachment_id]) && is_array($reviews[(string) $attachment_id]) ? $reviews[(string) $attachment_id] : [];
      $thumb = wp_get_attachment_image($attachment_id, [96, 96], false, ['class' => 'ypm-ai-media-review__thumb', 'loading' => 'lazy']);
      $title = get_the_title($attachment_id);
      if ($title === '') $title = wp_basename((string) get_attached_file($attachment_id));
      echo '<div class="ypm-ai-media-review__item"><div class="ypm-ai-media-review__visual">' . wp_kses_post($thumb ?: '<span class="dashicons dashicons-format-image" aria-hidden="true"></span>') . '</div><div class="ypm-ai-media-review__body"><div class="ypm-ai-media-review__title"><strong>' . esc_html($title !== '' ? $title : '#' . $attachment_id) . '</strong><code>#' . esc_html((string) $attachment_id) . '</code></div>';
      if ($review) {
        $embedded = !empty($review['embedded_text_detected']);
        $needed = !empty($review['localization_needed']);
        echo '<div class="ypm-review-gates">' . wp_kses_post(Admin::status_badge('pass', __('Vision analyzed', 'yablonsky-multilingual')));
        echo wp_kses_post(Admin::status_badge($embedded ? 'attention' : 'pass', $embedded ? __('Text in pixels', 'yablonsky-multilingual') : __('Pixel text findings: 0', 'yablonsky-multilingual')));
        if ($needed) echo wp_kses_post(Admin::status_badge('attention', __('Localized asset recommended', 'yablonsky-multilingual')));
        echo '</div>';
        $visible = isset($review['visible_text']) && is_array($review['visible_text']) ? array_values(array_filter(array_map('sanitize_text_field', $review['visible_text']))) : [];
        if ($visible) echo '<p><strong>' . esc_html__('Visible text:', 'yablonsky-multilingual') . '</strong> ' . esc_html(implode(' · ', array_slice($visible, 0, 6))) . '</p>';
        $alt = sanitize_text_field((string) ($review['suggested_alt'] ?? ''));
        $caption = sanitize_textarea_field((string) ($review['suggested_caption'] ?? ''));
        $notes = sanitize_textarea_field((string) ($review['notes'] ?? ''));
        if ($alt !== '') echo '<p><strong>' . esc_html__('Suggested alt:', 'yablonsky-multilingual') . '</strong> ' . esc_html($alt) . '</p>';
        if ($caption !== '') echo '<p><strong>' . esc_html__('Suggested caption:', 'yablonsky-multilingual') . '</strong> ' . esc_html($caption) . '</p>';
        if ($notes !== '') echo '<p class="description"><strong>' . esc_html__('Review note:', 'yablonsky-multilingual') . '</strong> ' . esc_html($notes) . '</p>';
        $cost = is_numeric($review['cost'] ?? null) ? (float) $review['cost'] : null;
        $model = sanitize_text_field((string) ($review['model'] ?? ''));
        if ($model !== '' || $cost !== null) {
          $meta = $model;
          if ($cost !== null) $meta .= ($meta !== '' ? ' · ' : '') . rtrim(rtrim(number_format($cost, 4, '.', ''), '0'), '.') . ' ' . __('credits', 'yablonsky-multilingual');
          echo '<p class="description">' . esc_html($meta) . '</p>';
        }
        if (class_exists('Yablonsky\Multilingual\MediaLocalization')) {
          $generated = MediaLocalization::generated_record($post_id, $attachment_id, $target);
          if ($generated) {
            $generated_id = absint($generated['generated_attachment_id'] ?? 0);
            $generated_thumb = $generated_id > 0 ? wp_get_attachment_image($generated_id, [120, 68], false, ['class' => 'ypm-ai-generated-media__thumb', 'loading' => 'lazy']) : '';
            echo '<div class="ypm-ai-generated-media"><div>' . wp_kses_post($generated_thumb) . '</div><div><strong>' . esc_html__('Generated localized image draft', 'yablonsky-multilingual') . '</strong><p>' . esc_html__('A separate Media Library review asset is ready. The source image and published page remain authoritative.', 'yablonsky-multilingual') . '</p>';
            $generated_edit = $generated_id > 0 ? get_edit_post_link($generated_id, '') : '';
            if ($generated_edit) echo '<a class="button button-small" href="' . esc_url($generated_edit) . '">' . esc_html__('Open generated media', 'yablonsky-multilingual') . '</a>';
            if ($generated_id > 0 && sanitize_key((string) ($generated['status'] ?? 'review')) !== 'accepted') {
              echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ypm-ai-generated-media__promote"><input type="hidden" name="action" value="ymulti_media_promote_generated"><input type="hidden" name="post_id" value="' . esc_attr((string) $post_id) . '"><input type="hidden" name="attachment_id" value="' . esc_attr((string) $attachment_id) . '"><input type="hidden" name="target_locale" value="' . esc_attr($target) . '">';
              wp_nonce_field('ymulti_media_promote_generated_' . $post_id . '_' . $attachment_id . '_' . $target, 'ymulti_media_nonce');
              echo '<button class="button button-small button-primary">' . esc_html__('Use as localized asset', 'yablonsky-multilingual') . '</button></form>';
            } elseif ($generated_id > 0) {
              echo wp_kses_post(Admin::status_badge('pass', __('Localized asset active', 'yablonsky-multilingual')));
            }
            echo '</div></div>';
          }
        }
      } else {
        echo '<p class="description">' . esc_html__('Vision analysis is ready. Existing WordPress media remains authoritative.', 'yablonsky-multilingual') . '</p>';
      }
      echo '<div class="ypm-row-actions">';
      if ($review && class_exists('Yablonsky\\Multilingual\\MediaLocalization')) MediaLocalization::render_review_action($post_id, $attachment_id, $target, $review);
      if ($review && $can_generate && $target !== '') {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ypm_ai_media_generate"><input type="hidden" name="post_id" value="' . esc_attr((string) $post_id) . '"><input type="hidden" name="attachment_id" value="' . esc_attr((string) $attachment_id) . '"><input type="hidden" name="target_locale" value="' . esc_attr($target) . '">';
        wp_nonce_field('ypm_ai_media_generate_' . $post_id . '_' . $attachment_id . '_' . $target, 'ypm_ai_nonce');
        echo '<button class="button">' . esc_html__('Generate localized image draft', 'yablonsky-multilingual') . '</button></form>';
      }
      if ($can_analyze) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ypm_ai_media_review"><input type="hidden" name="post_id" value="' . esc_attr((string) $post_id) . '"><input type="hidden" name="attachment_id" value="' . esc_attr((string) $attachment_id) . '">';
        wp_nonce_field('ypm_ai_media_review_' . $post_id . '_' . $attachment_id, 'ypm_ai_nonce');
        echo '<button class="button' . ($review ? '' : ' button-primary') . '">' . esc_html($review ? __('Re-run Vision review', 'yablonsky-multilingual') : __('Analyze with Vision', 'yablonsky-multilingual')) . '</button></form>';
      }
      $attachment_edit = get_edit_post_link($attachment_id, '');
      if ($attachment_edit) echo '<a class="button" href="' . esc_url($attachment_edit) . '">' . esc_html__('Open media', 'yablonsky-multilingual') . '</a>';
      echo '</div></div></div>';
    }
    echo '</div>';
    if (count($attachment_ids) > $shown) {
      /* translators: %d: number of additional image attachments not shown in the compact media review panel. */
      echo '<p class="description">' . esc_html(sprintf(__('%d additional images are present. The compact editor review shows the first five attachments only.', 'yablonsky-multilingual'), count($attachment_ids) - $shown)) . '</p>';
    }
    if (!$can_analyze) echo '<p class="description">' . esc_html__('Vision setup required. Verify the connected workspace and select a Vision-capable model to activate analysis.', 'yablonsky-multilingual') . '</p>';
    if ($image_model === '') echo '<p class="description">' . esc_html__('Image-generation setup required. Select a visible image-generation model or add an exact manual route ID to activate localized image drafts.', 'yablonsky-multilingual') . '</p>';
    echo '</div>';
  }

  private static function policy_item(string $title, string $copy): void {
    echo '<div class="ypm-ai-policy-item"><strong>' . esc_html($title) . '</strong><p>' . esc_html($copy) . '</p></div>';
  }

  /** @param mixed $result */
  private static function redirect_result($result, string $success): void {
    if (is_wp_error($result)) self::set_notice('error', $result->get_error_message()); else self::set_notice('success', $success);
    wp_safe_redirect(admin_url('admin.php?page=ypm-ai'));
    exit;
  }

  private static function set_notice(string $type, string $message): void {
    set_transient('ypm_ai_admin_notice_' . get_current_user_id(), ['type' => $type, 'message' => sanitize_text_field($message)], 60);
  }

  private static function render_notice(): void {
    $key = 'ypm_ai_admin_notice_' . get_current_user_id();
    $notice = get_transient($key);
    if (!is_array($notice) || empty($notice['message'])) return;
    delete_transient($key);
    $type = ($notice['type'] ?? '') === 'error' ? 'error' : 'success';
    echo '<div class="notice notice-' . esc_attr($type) . ' inline"><p>' . esc_html((string) $notice['message']) . '</p></div>';
  }
}
