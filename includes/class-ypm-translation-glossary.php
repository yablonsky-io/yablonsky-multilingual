<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/** Human-approved terminology rules applied only to explicit AI localization prompts. */
final class TranslationGlossary {
  public const OPT = 'ypm_translation_glossary_v1';
  private const MAX_ENTRIES = 100;
  private const PROMPT_LIMIT = 25;

  public static function init(): void {
    if (!is_admin()) return;
    add_action('admin_post_ypm_glossary_add', [__CLASS__, 'handle_add']);
    add_action('admin_post_ypm_glossary_delete', [__CLASS__, 'handle_delete']);
  }

  /** @return array<int,array{id:string,source:string,target:string,locale:string}> */
  public static function entries(): array {
    $raw = get_option(self::OPT, []);
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $entry) {
      if (!is_array($entry)) continue;
      $source = sanitize_text_field((string) ($entry['source'] ?? ''));
      $target = sanitize_text_field((string) ($entry['target'] ?? ''));
      $locale = sanitize_text_field((string) ($entry['locale'] ?? ''));
      if ($source === '' || $target === '' || !Languages::is_active($locale)) continue;
      $id = sanitize_key((string) ($entry['id'] ?? ''));
      if ($id === '') $id = substr(sha1($locale . '|' . $source . '|' . $target), 0, 16);
      $out[] = ['id' => $id, 'source' => $source, 'target' => $target, 'locale' => $locale];
      if (count($out) >= self::MAX_ENTRIES) break;
    }
    return $out;
  }

  public static function prompt_rules(string $target_locale): string {
    if (!Languages::is_active($target_locale)) return '';
    $rules = [];
    foreach (self::entries() as $entry) {
      if ($entry['locale'] !== $target_locale) continue;
      $rules[] = '- ' . wp_json_encode($entry['source'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' => ' . wp_json_encode($entry['target'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if (count($rules) >= self::PROMPT_LIMIT) break;
    }
    if (!$rules) return '';
    return "\nHuman-approved terminology rules for this target locale. When the exact source phrase appears with the same meaning, use the prescribed target phrase. Do not invent a glossary match and do not alter protected tokens:\n" . implode("\n", $rules) . "\n";
  }

  public static function render_panel(): void {
    if (!current_user_can('manage_options')) return;
    $entries = self::entries();
    echo '<section id="ypm-translation-glossary" class="ypm-card ypm-card--standalone"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Terminology', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Human-approved translation glossary', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('Lock important product names, technical terms and preferred wording per target locale. Glossary rules are local, bounded and added only to explicit AI translation prompts.', 'yablonsky-multilingual') . '</p></div>' . wp_kses_post(Admin::status_badge($entries ? 'pass' : 'neutral', sprintf(
      /* translators: %d: glossary entry count. */
      __('%d terms', 'yablonsky-multilingual'), count($entries)
    ))) . '</div>';

    if ($entries) {
      echo '<div class="ypm-family-list">';
      foreach ($entries as $entry) {
        echo '<div class="ypm-family-row"><div class="ypm-family-row__name"><strong>' . esc_html($entry['source']) . '</strong><span>→ ' . esc_html($entry['target']) . '</span></div><span class="ypm-family-chip ypm-family-row__code">' . esc_html(Languages::display_code($entry['locale'])) . '</span><div class="ypm-family-row__action"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ypm_glossary_delete_' . $entry['id'], 'ypm_glossary_nonce');
        echo '<input type="hidden" name="action" value="ypm_glossary_delete"><input type="hidden" name="entry_id" value="' . esc_attr($entry['id']) . '"><button class="button button-small">' . esc_html__('Delete', 'yablonsky-multilingual') . '</button></form></div></div>';
      }
      echo '</div>';
    }

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ypm-inline-action ypm-glossary-add">';
    wp_nonce_field('ypm_glossary_add', 'ypm_glossary_nonce');
    echo '<input type="hidden" name="action" value="ypm_glossary_add"><label><span>' . esc_html__('Source phrase', 'yablonsky-multilingual') . '</span><input type="text" name="source" maxlength="190" required></label><label><span>' . esc_html__('Preferred translation', 'yablonsky-multilingual') . '</span><input type="text" name="target" maxlength="190" required></label><label><span>' . esc_html__('Target locale', 'yablonsky-multilingual') . '</span><select name="locale" required>';
    foreach (Languages::active_codes() as $code) echo '<option value="' . esc_attr($code) . '">' . esc_html(Languages::display_name($code) . ' (' . Languages::display_code($code) . ')') . '</option>';
    echo '</select></label><button class="button button-primary">' . esc_html__('Add terminology rule', 'yablonsky-multilingual') . '</button></form><p class="description">' . esc_html__('The glossary guides future AI output while existing human translations remain authoritative.', 'yablonsky-multilingual') . '</p></section>';
  }

  public static function handle_add(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_glossary_add', 'ypm_glossary_nonce');
    $source_raw = filter_input(INPUT_POST, 'source', FILTER_UNSAFE_RAW);
    $target_raw = filter_input(INPUT_POST, 'target', FILTER_UNSAFE_RAW);
    $locale_raw = filter_input(INPUT_POST, 'locale', FILTER_UNSAFE_RAW);
    $source = is_string($source_raw) ? sanitize_text_field($source_raw) : '';
    $target = is_string($target_raw) ? sanitize_text_field($target_raw) : '';
    $locale = is_string($locale_raw) ? sanitize_text_field($locale_raw) : '';
    if ($source === '' || $target === '' || !Languages::is_active($locale)) wp_die(esc_html__('Invalid glossary rule.', 'yablonsky-multilingual'));
    $entries = self::entries();
    $id = substr(sha1($locale . '|' . $source . '|' . $target . '|' . microtime(true)), 0, 16);
    $entries[] = ['id' => $id, 'source' => $source, 'target' => $target, 'locale' => $locale];
    update_option(self::OPT, array_slice($entries, -self::MAX_ENTRIES), false);
    self::redirect();
  }

  public static function handle_delete(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    $id_raw = filter_input(INPUT_POST, 'entry_id', FILTER_UNSAFE_RAW);
    $id = is_string($id_raw) ? sanitize_key($id_raw) : '';
    if ($id === '') wp_die(esc_html__('Invalid glossary rule.', 'yablonsky-multilingual'));
    check_admin_referer('ypm_glossary_delete_' . $id, 'ypm_glossary_nonce');
    $entries = array_values(array_filter(self::entries(), static function (array $entry) use ($id): bool { return !hash_equals($id, (string) $entry['id']); }));
    update_option(self::OPT, $entries, false);
    self::redirect();
  }

  private static function redirect(): void { wp_safe_redirect(admin_url('admin.php?page=ypm-translations#ypm-translation-glossary')); exit; }
}
