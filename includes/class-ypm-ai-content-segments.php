<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

/**
 * Source-preserving localization scanner for modern WordPress content.
 *
 * HTML is the primary translation surface. JavaScript and CSS are inspected
 * conservatively only for high-confidence human-facing strings. The scanner
 * never serializes the document: it records byte ranges and later replaces
 * only those approved ranges, leaving the executable/structural source intact.
 */
final class AIContentSegments {
  private const MAX_SEGMENTS = 240;
  private const MAX_SEGMENT_CHARS = 12000;

  /** @return array<string,mixed>|\WP_Error */
  public static function extract(string $source) {
    $segments = [];
    $stack = [];
    $length = strlen($source);
    $pos = 0;

    while ($pos < $length) {
      $lt = strpos($source, '<', $pos);
      if ($lt === false) {
        self::extract_text_span($source, $pos, $length, $stack, $segments);
        break;
      }

      if ($lt > $pos) self::extract_text_span($source, $pos, $lt, $stack, $segments);

      if (substr($source, $lt, 4) === '<!--') {
        $comment_end = strpos($source, '-->', $lt + 4);
        $pos = $comment_end === false ? $length : $comment_end + 3;
        continue;
      }
      if (substr($source, $lt, 9) === '<![CDATA[') {
        $cdata_end = strpos($source, ']]>', $lt + 9);
        $pos = $cdata_end === false ? $length : $cdata_end + 3;
        continue;
      }

      $tag_end = self::find_tag_end($source, $lt);
      if ($tag_end < 0) return new \WP_Error('ypm_ai_html_syntax', __('The source HTML contains an unterminated tag or quoted attribute. Complete the markup, then start AI translation.', 'yablonsky-multilingual'));
      $tag_source = substr($source, $lt, $tag_end - $lt + 1);

      if (preg_match('~^<\s*/\s*([A-Za-z][A-Za-z0-9:_-]*)~', $tag_source, $closing)) {
        self::pop_stack($stack, strtolower((string) $closing[1]));
        $pos = $tag_end + 1;
        continue;
      }

      if (!preg_match('~^<\s*([A-Za-z][A-Za-z0-9:_-]*)~', $tag_source, $opening)) {
        $pos = $tag_end + 1;
        continue;
      }

      $tag = strtolower((string) $opening[1]);
      $attrs = self::parse_attributes($tag_source, $lt);
      $parent_skip = !empty($stack) && !empty($stack[count($stack) - 1]['skip']);
      $classes = isset($attrs['class']['value']) ? preg_split('/\s+/', trim((string) $attrs['class']['value'])) : [];
      $classes = is_array($classes) ? array_values(array_filter(array_map('sanitize_html_class', $classes))) : [];
      $translate_no = isset($attrs['translate']['value']) && strtolower(trim((string) $attrs['translate']['value'])) === 'no';
      $notranslate = in_array('notranslate', $classes, true);
      $skip_tag = in_array($tag, ['code', 'pre', 'kbd', 'samp', 'svg', 'math'], true);
      $skip = $parent_skip || $translate_no || $notranslate || $skip_tag;

      if (!$skip) self::extract_attribute_segments($source, $tag, $attrs, $stack, $classes, $segments);

      if ($tag === 'script' || $tag === 'style') {
        $close = self::find_closing_tag($source, $tag, $tag_end + 1);
        if ($close === null) return new \WP_Error('ypm_ai_html_syntax', __('The source HTML contains an unterminated script or style element. Complete the markup, then start AI translation.', 'yablonsky-multilingual'));
        $body_start = $tag_end + 1;
        $body_end = $close['start'];
        if (!$skip && $body_end > $body_start) {
          if ($tag === 'style') {
            self::extract_css_segments(substr($source, $body_start, $body_end - $body_start), $body_start, $stack, $segments);
          } elseif (self::is_javascript_type($attrs)) {
            self::extract_js_segments(substr($source, $body_start, $body_end - $body_start), $body_start, $stack, $segments);
          }
        }
        $pos = $close['end'] + 1;
        continue;
      }

      $self_closing = (bool) preg_match('~/\s*>$~', $tag_source) || self::is_void_tag($tag);
      if (!$self_closing) {
        $stack[] = [
          'tag' => $tag,
          'classes' => $classes,
          'id' => isset($attrs['id']['value']) ? sanitize_html_class((string) $attrs['id']['value']) : '',
          'skip' => $skip,
        ];
      }
      $pos = $tag_end + 1;
    }

    usort($segments, static function (array $a, array $b): int { return ((int) $a['start']) <=> ((int) $b['start']); });
    if (count($segments) > self::MAX_SEGMENTS) {
      return new \WP_Error(
        'ypm_ai_too_many_segments',
        sprintf(
          /* translators: %d: maximum number of translatable segments in one bounded AI request. */
          __('This page contains more than %d translatable segments. Split the translation into smaller content before using AI.', 'yablonsky-multilingual'),
          self::MAX_SEGMENTS
        )
      );
    }

    $occupied_end = -1;
    foreach ($segments as $index => &$segment) {
      $start = (int) $segment['start'];
      $seg_len = (int) $segment['length'];
      if ($start < $occupied_end) return new \WP_Error('ypm_ai_segment_overlap', __('The source contains overlapping localization regions. AI translation was stopped to protect the page structure.', 'yablonsky-multilingual'));
      $occupied_end = $start + $seg_len;
      $segment['id'] = 'S' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
      if (strlen((string) $segment['source']) > self::MAX_SEGMENT_CHARS) {
        return new \WP_Error('ypm_ai_segment_too_large', __('One translatable region is too large for the bounded structured localization request.', 'yablonsky-multilingual'));
      }
    }
    unset($segment);

    $stats = [
      'segments' => count($segments),
      'html' => 0,
      'attributes' => 0,
      'javascript' => 0,
      'css' => 0,
      'high_layout_risk' => 0,
    ];
    foreach ($segments as $segment) {
      $mode = (string) ($segment['mode'] ?? '');
      if ($mode === 'html_text') $stats['html']++;
      elseif ($mode === 'html_attr') $stats['attributes']++;
      elseif ($mode === 'js_string') $stats['javascript']++;
      elseif ($mode === 'css_string') $stats['css']++;
      if (($segment['risk'] ?? '') === 'high') $stats['high_layout_risk']++;
    }

    return [
      'segments' => $segments,
      'stats' => $stats,
      'graph' => self::relationship_graph($source, $segments),
      'canonical_hash' => self::canonical_hash($source, $segments),
    ];
  }

  /**
   * Replace only approved source ranges, then prove that the structural source
   * and cross-layer identity graph still match the original.
   *
   * @param array<int,array<string,mixed>> $segments
   * @param array<string,string> $translations
   * @return string|\WP_Error
   */
  public static function apply(string $source, array $segments, array $translations) {
    $expected = [];
    foreach ($segments as $segment) $expected[(string) ($segment['id'] ?? '')] = true;
    foreach ($translations as $id => $value) {
      if (!isset($expected[(string) $id]) || !is_string($value)) {
        return new \WP_Error('ypm_ai_segment_unknown', __('AI returned an unknown structured localization segment. Source content remains unchanged; verify the selected model and run the draft again.', 'yablonsky-multilingual'));
      }
    }
    if (count($translations) !== count($expected)) return new \WP_Error('ypm_ai_segment_missing', __('AI returned an incomplete structured localization set. WordPress content remains unchanged; retry the staged translation.', 'yablonsky-multilingual'));

    $result = $source;
    $ordered = $segments;
    usort($ordered, static function (array $a, array $b): int { return ((int) $b['start']) <=> ((int) $a['start']); });
    foreach ($ordered as $segment) {
      $id = (string) ($segment['id'] ?? '');
      $translated = trim((string) ($translations[$id] ?? ''));
      if ($translated === '' && trim((string) ($segment['source'] ?? '')) !== '') {
        return new \WP_Error('ypm_ai_segment_empty', __('AI returned an empty translation for a required page segment. Source content remains unchanged; run the draft again or choose another text model.', 'yablonsky-multilingual'));
      }
      $rendered = self::escape_for_segment($translated, $segment);
      $start = (int) $segment['start'];
      $length = (int) $segment['length'];
      $result = substr($result, 0, $start) . $rendered . substr($result, $start + $length);
    }

    $validation = self::validate_structure($source, $result);
    if (is_wp_error($validation)) return $validation;
    return $result;
  }

  /** @return true|\WP_Error */
  public static function validate_structure(string $before, string $after) {
    $before_data = self::extract($before);
    if (is_wp_error($before_data)) return $before_data;
    $after_data = self::extract($after);
    if (is_wp_error($after_data)) return new \WP_Error('ypm_ai_structure_after_invalid', __('The localized page changed the protected HTML/JavaScript/CSS structure. YMulti rejected the draft and preserved the source structure.', 'yablonsky-multilingual'));

    $before_segments = isset($before_data['segments']) && is_array($before_data['segments']) ? $before_data['segments'] : [];
    $after_segments = isset($after_data['segments']) && is_array($after_data['segments']) ? $after_data['segments'] : [];
    if (count($before_segments) !== count($after_segments)) return new \WP_Error('ypm_ai_structure_segment_count', __('Localization changed the number of recognized page text regions. The draft was rejected to protect the page structure.', 'yablonsky-multilingual'));

    foreach ($before_segments as $index => $segment) {
      $other = $after_segments[$index] ?? [];
      foreach (['mode', 'role', 'context'] as $field) {
        if ((string) ($segment[$field] ?? '') !== (string) ($other[$field] ?? '')) {
          return new \WP_Error('ypm_ai_structure_context', __('Localization changed a protected HTML/JavaScript/CSS context. The draft was rejected.', 'yablonsky-multilingual'));
        }
      }
    }

    if (!hash_equals((string) $before_data['canonical_hash'], (string) $after_data['canonical_hash'])) {
      return new \WP_Error('ypm_ai_structure_hash', __('Localization changed protected page source outside the approved human-language regions. The draft was rejected.', 'yablonsky-multilingual'));
    }

    $before_graph = wp_json_encode($before_data['graph'], JSON_UNESCAPED_SLASHES);
    $after_graph = wp_json_encode($after_data['graph'], JSON_UNESCAPED_SLASHES);
    if (!is_string($before_graph) || !is_string($after_graph) || !hash_equals(hash('sha256', $before_graph), hash('sha256', $after_graph))) {
      return new \WP_Error('ypm_ai_reference_graph', __('Localization changed a protected cross-layer identifier or reference used by HTML, CSS or JavaScript. The draft was rejected.', 'yablonsky-multilingual'));
    }
    return true;
  }

  /** @return array{images:int,with_alt:int,attachment_ids:array<int,int>} */
  public static function media_summary(string $source): array {
    $images = 0;
    $with_alt = 0;
    $attachment_ids = [];
    if (preg_match_all('~<img\b[^>]*>~i', $source, $matches)) {
      foreach ($matches[0] as $tag) {
        $images++;
        if (preg_match('~\balt\s*=\s*["\']([^"\']*)["\']~i', (string) $tag, $alt) && trim((string) $alt[1]) !== '') $with_alt++;
        if (preg_match('~\bwp-image-(\d+)\b~', (string) $tag, $id_match)) {
          $id = absint($id_match[1]);
          if ($id > 0) $attachment_ids[] = $id;
        }
      }
    }
    return ['images' => $images, 'with_alt' => $with_alt, 'attachment_ids' => array_values(array_unique($attachment_ids))];
  }

  /** @param array<int,array<string,mixed>> $segments */
  public static function prompt_payload(array $segments): array {
    $payload = [];
    foreach ($segments as $segment) {
      $payload[] = [
        'id' => (string) ($segment['id'] ?? ''),
        'text' => (string) ($segment['source'] ?? ''),
        'role' => (string) ($segment['role'] ?? 'body'),
        'context' => (string) ($segment['context'] ?? ''),
        'layout_risk' => (string) ($segment['risk'] ?? 'low'),
        'layer' => self::layer_name((string) ($segment['mode'] ?? '')),
      ];
    }
    return $payload;
  }

  /** @param array<string,array<string,mixed>> $attrs */
  private static function is_javascript_type(array $attrs): bool {
    $type = isset($attrs['type']['value']) ? strtolower(trim((string) $attrs['type']['value'])) : '';
    if ($type === '') return true;
    return in_array($type, ['text/javascript', 'application/javascript', 'module'], true);
  }

  private static function is_void_tag(string $tag): bool {
    return in_array($tag, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true);
  }

  private static function find_tag_end(string $source, int $start): int {
    $quote = '';
    $length = strlen($source);
    for ($i = $start + 1; $i < $length; $i++) {
      $char = $source[$i];
      if ($quote !== '') {
        if ($char === $quote && ($i === 0 || $source[$i - 1] !== '\\')) $quote = '';
        continue;
      }
      if ($char === '"' || $char === "'") { $quote = $char; continue; }
      if ($char === '>') return $i;
    }
    return -1;
  }

  /** @return array{start:int,end:int}|null */
  private static function find_closing_tag(string $source, string $tag, int $from): ?array {
    if (!preg_match('~</\s*' . preg_quote($tag, '~') . '\s*>~i', $source, $match, PREG_OFFSET_CAPTURE, $from)) return null;
    $raw = (string) $match[0][0];
    $start = (int) $match[0][1];
    return ['start' => $start, 'end' => $start + strlen($raw) - 1];
  }

  /** @return array<string,array<string,mixed>> */
  private static function parse_attributes(string $tag_source, int $absolute_start): array {
    $attrs = [];
    if (!preg_match_all('~([A-Za-z_:][A-Za-z0-9:_.-]*)\s*=\s*(["\'])(.*?)\2~s', $tag_source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return $attrs;
    foreach ($matches as $match) {
      $name = strtolower((string) $match[1][0]);
      $value = (string) $match[3][0];
      $value_start = $absolute_start + (int) $match[3][1];
      $attrs[$name] = ['value' => $value, 'start' => $value_start, 'length' => strlen($value), 'quote' => (string) $match[2][0]];
    }
    return $attrs;
  }

  /** @param array<int,array<string,mixed>> $stack @param array<int,array<string,mixed>> $segments */
  private static function extract_text_span(string $source, int $start, int $end, array $stack, array &$segments): void {
    if ($end <= $start || (!$stack && trim(substr($source, $start, $end - $start)) === '')) return;
    if ($stack && !empty($stack[count($stack) - 1]['skip'])) return;
    $raw = substr($source, $start, $end - $start);
    if (!preg_match('/^(\s*)(.*?)(\s*)$/us', $raw, $parts)) return;
    $text = (string) $parts[2];
    if (!self::looks_human($text)) return;
    $leading = strlen((string) $parts[1]);
    $role = self::role_from_stack($stack);
    self::add_segment($segments, [
      'start' => $start + $leading,
      'length' => strlen($text),
      'source' => html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
      'mode' => 'html_text',
      'role' => $role,
      'context' => self::context_path($stack),
      'risk' => self::layout_risk($role, $stack),
      'quote' => '',
    ]);
  }

  /** @param array<string,array<string,mixed>> $attrs @param array<int,array<string,mixed>> $stack @param array<int,string> $classes @param array<int,array<string,mixed>> $segments */
  private static function extract_attribute_segments(string $source, string $tag, array $attrs, array $stack, array $classes, array &$segments): void {
    $allowed = ['alt', 'title', 'placeholder', 'aria-label', 'aria-description'];
    foreach ($allowed as $name) {
      if (!isset($attrs[$name])) continue;
      $row = $attrs[$name];
      $value = (string) ($row['value'] ?? '');
      if (!self::looks_human($value)) continue;
      $local_stack = $stack;
      $local_stack[] = ['tag' => $tag, 'classes' => $classes, 'id' => isset($attrs['id']['value']) ? (string) $attrs['id']['value'] : '', 'skip' => false];
      $role = $name === 'placeholder' ? 'placeholder' : ($name === 'alt' ? 'alt_text' : ($name === 'aria-label' || $name === 'aria-description' ? 'aria_label' : 'tooltip'));
      self::add_segment($segments, [
        'start' => (int) $row['start'],
        'length' => (int) $row['length'],
        'source' => html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'mode' => 'html_attr',
        'role' => $role,
        'context' => self::context_path($local_stack) . '@' . $name,
        'risk' => in_array($role, ['placeholder', 'aria_label'], true) ? 'medium' : 'low',
        'quote' => (string) ($row['quote'] ?? '"'),
      ]);
    }
  }

  /** @param array<int,array<string,mixed>> $stack @param array<int,array<string,mixed>> $segments */
  private static function extract_js_segments(string $script, int $base, array $stack, array &$segments): void {
    $prefixes = [
      '~(?:\.\s*(?:textContent|innerText|title|placeholder|ariaLabel)\s*=\s*)$~',
      '~(?:\b(?:alert|confirm|prompt)\s*\(\s*)$~',
      '~(?:\b(?:label|message|description|buttonText|button_text|errorMessage|error_message|heading|caption|tooltip)\s*:\s*)$~',
    ];
    $length = strlen($script);
    for ($i = 0; $i < $length; $i++) {
      $quote = $script[$i];
      if ($quote !== '"' && $quote !== "'") continue;
      $before = substr($script, max(0, $i - 100), min(100, $i));
      $accepted = false;
      foreach ($prefixes as $pattern) {
        if (preg_match($pattern, $before)) { $accepted = true; break; }
      }
      if (!$accepted) {
        $set_attr = substr($script, max(0, $i - 160), min(160, $i));
        if (preg_match('~setAttribute\s*\(\s*["\'](?:aria-label|aria-description|title|placeholder)["\']\s*,\s*$~', $set_attr)) $accepted = true;
      }
      if (!$accepted) continue;
      $parsed = self::quoted_content($script, $i, $quote);
      if ($parsed === null) continue;
      $text = $parsed['content'];
      if (self::looks_human($text)) {
        self::add_segment($segments, [
          'start' => $base + $parsed['start'],
          'length' => $parsed['length'],
          'source' => self::decode_simple_js_string($text),
          'mode' => 'js_string',
          'role' => 'ui_message',
          'context' => self::context_path($stack) . '>script',
          'risk' => 'medium',
          'quote' => $quote,
        ]);
      }
      $i = $parsed['end'];
    }
  }

  /** @param array<int,array<string,mixed>> $stack @param array<int,array<string,mixed>> $segments */
  private static function extract_css_segments(string $css, int $base, array $stack, array &$segments): void {
    if (!preg_match_all('~\bcontent\s*:\s*~i', $css, $matches, PREG_OFFSET_CAPTURE)) return;
    foreach ($matches[0] as $match) {
      $after = (int) $match[1] + strlen((string) $match[0]);
      $length = strlen($css);
      while ($after < $length && ctype_space($css[$after])) $after++;
      if ($after >= $length || ($css[$after] !== '"' && $css[$after] !== "'")) continue;
      $quote = $css[$after];
      $parsed = self::quoted_content($css, $after, $quote);
      if ($parsed === null || !self::looks_human($parsed['content'])) continue;
      self::add_segment($segments, [
        'start' => $base + $parsed['start'],
        'length' => $parsed['length'],
        'source' => stripcslashes($parsed['content']),
        'mode' => 'css_string',
        'role' => 'generated_label',
        'context' => self::context_path($stack) . '>style content',
        'risk' => 'high',
        'quote' => $quote,
      ]);
    }
  }

  /** @return array{content:string,start:int,length:int,end:int}|null */
  private static function quoted_content(string $source, int $quote_pos, string $quote): ?array {
    $length = strlen($source);
    $start = $quote_pos + 1;
    for ($i = $start; $i < $length; $i++) {
      if ($source[$i] === '\\') { $i++; continue; }
      if ($source[$i] !== $quote) continue;
      return ['content' => substr($source, $start, $i - $start), 'start' => $start, 'length' => $i - $start, 'end' => $i];
    }
    return null;
  }

  private static function decode_simple_js_string(string $value): string {
    return str_replace(['\\n', '\\r', '\\t', '\\"', "\\'", '\\\\'], ["\n", "\r", "\t", '"', "'", '\\'], $value);
  }

  /** @param array<int,array<string,mixed>> $segments @param array<string,mixed> $segment */
  private static function add_segment(array &$segments, array $segment): void {
    $start = (int) ($segment['start'] ?? 0);
    $end = $start + (int) ($segment['length'] ?? 0);
    foreach ($segments as $existing) {
      $existing_start = (int) ($existing['start'] ?? 0);
      $existing_end = $existing_start + (int) ($existing['length'] ?? 0);
      if ($start < $existing_end && $end > $existing_start) return;
    }
    $segments[] = $segment;
  }

  private static function looks_human(string $text): bool {
    $text = trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($text === '') return false;
    if (!preg_match('/\p{L}/u', $text)) return false;
    if (preg_match('~^(?:https?://|mailto:|tel:|#[A-Za-z0-9_-]+$|\.[A-Za-z0-9_-]+$)~', $text)) return false;
    return true;
  }

  /** @param array<int,array<string,mixed>> $stack */
  private static function role_from_stack(array $stack): string {
    for ($i = count($stack) - 1; $i >= 0; $i--) {
      $tag = (string) ($stack[$i]['tag'] ?? '');
      $classes = isset($stack[$i]['classes']) && is_array($stack[$i]['classes']) ? $stack[$i]['classes'] : [];
      if (preg_match('/^h[1-6]$/', $tag)) return 'heading';
      if ($tag === 'button') return 'button';
      if ($tag === 'label' || $tag === 'legend') return 'label';
      if ($tag === 'option') return 'option';
      if ($tag === 'summary') return 'summary';
      if ($tag === 'nav') return 'nav_item';
      if ($tag === 'a' && self::classes_match($classes, ['button', 'btn', 'cta'])) return 'button';
      if (self::classes_match($classes, ['badge', 'chip', 'pill', 'tag', 'tab'])) return 'badge';
    }
    return 'body';
  }

  /** @param array<int,array<string,mixed>> $stack */
  private static function layout_risk(string $role, array $stack): string {
    if (in_array($role, ['button', 'nav_item', 'badge'], true)) return 'high';
    if (in_array($role, ['heading', 'label', 'option', 'summary'], true)) return 'medium';
    foreach ($stack as $item) {
      $classes = isset($item['classes']) && is_array($item['classes']) ? $item['classes'] : [];
      if (self::classes_match($classes, ['nowrap', 'no-wrap', 'compact', 'toolbar', 'menu', 'tabs'])) return 'high';
    }
    return 'low';
  }

  /** @param array<int,string> $classes @param array<int,string> $needles */
  private static function classes_match(array $classes, array $needles): bool {
    foreach ($classes as $class) {
      $lower = strtolower((string) $class);
      foreach ($needles as $needle) if (strpos($lower, $needle) !== false) return true;
    }
    return false;
  }

  /** @param array<int,array<string,mixed>> $stack */
  private static function context_path(array $stack): string {
    $parts = [];
    foreach (array_slice($stack, -5) as $item) {
      $part = (string) ($item['tag'] ?? '');
      if (!empty($item['id'])) $part .= '#' . (string) $item['id'];
      $classes = isset($item['classes']) && is_array($item['classes']) ? array_slice($item['classes'], 0, 2) : [];
      if ($classes) $part .= '.' . implode('.', $classes);
      if ($part !== '') $parts[] = $part;
    }
    return $parts ? implode(' > ', $parts) : 'document';
  }

  /** @param array<int,array<string,mixed>> $stack */
  private static function pop_stack(array &$stack, string $tag): void {
    for ($i = count($stack) - 1; $i >= 0; $i--) {
      if ((string) ($stack[$i]['tag'] ?? '') !== $tag) continue;
      $stack = array_slice($stack, 0, $i);
      return;
    }
  }

  /** @param array<string,mixed> $segment */
  private static function escape_for_segment(string $value, array $segment): string {
    $mode = (string) ($segment['mode'] ?? 'html_text');
    if ($mode === 'html_attr') return esc_attr($value);
    if ($mode === 'js_string') return self::escape_js_string($value, (string) ($segment['quote'] ?? '"'));
    if ($mode === 'css_string') return self::escape_css_string($value, (string) ($segment['quote'] ?? '"'));
    return esc_html($value);
  }

  private static function escape_js_string(string $value, string $quote): string {
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace(["\r", "\n", "\t"], ['\\r', '\\n', '\\t'], $value);
    $value = str_replace($quote, '\\' . $quote, $value);
    $value = str_replace(["\u{2028}", "\u{2029}"], ['\\u2028', '\\u2029'], $value);
    return str_replace(['<', '>'], ['\\u003C', '\\u003E'], $value);
  }

  private static function escape_css_string(string $value, string $quote): string {
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace(["\r", "\n", "\f"], ['\\D ', '\\A ', '\\C '], $value);
    $value = str_replace($quote, '\\' . $quote, $value);
    return str_replace('<', '\\3C ', $value);
  }

  /** @param array<int,array<string,mixed>> $segments */
  private static function canonical_hash(string $source, array $segments): string {
    $canonical = $source;
    $ordered = $segments;
    usort($ordered, static function (array $a, array $b): int { return ((int) $b['start']) <=> ((int) $a['start']); });
    foreach ($ordered as $segment) {
      $token = '[[YMS:' . (string) ($segment['mode'] ?? '') . ':' . (string) ($segment['role'] ?? '') . ']]';
      $start = (int) $segment['start'];
      $length = (int) $segment['length'];
      $canonical = substr($canonical, 0, $start) . $token . substr($canonical, $start + $length);
    }
    return hash('sha256', $canonical);
  }

  /** @param array<int,array<string,mixed>> $segments @return array<string,array<int,string>> */
  private static function relationship_graph(string $source, array $segments): array {
    $structural = $source;
    $ordered = $segments;
    usort($ordered, static function (array $a, array $b): int { return ((int) $b['start']) <=> ((int) $a['start']); });
    foreach ($ordered as $segment) {
      $start = (int) ($segment['start'] ?? 0);
      $length = (int) ($segment['length'] ?? 0);
      $structural = substr($structural, 0, $start) . str_repeat(' ', $length) . substr($structural, $start + $length);
    }
    $source = $structural;
    $graph = [
      'html_ids' => [],
      'html_classes' => [],
      'html_refs' => [],
      'data_keys' => [],
      'css_ids' => [],
      'css_classes' => [],
      'css_vars' => [],
      'js_selectors' => [],
      'js_ids' => [],
      'js_classes' => [],
    ];

    if (preg_match_all('~\bid\s*=\s*["\']([^"\']+)["\']~i', $source, $matches)) $graph['html_ids'] = $matches[1];
    if (preg_match_all('~\bclass\s*=\s*["\']([^"\']+)["\']~i', $source, $matches)) {
      foreach ($matches[1] as $classes) foreach (preg_split('/\s+/', trim((string) $classes)) ?: [] as $class) if ($class !== '') $graph['html_classes'][] = $class;
    }
    if (preg_match_all('~\b(?:for|aria-controls|aria-labelledby|aria-describedby)\s*=\s*["\']([^"\']+)["\']~i', $source, $matches)) {
      foreach ($matches[1] as $refs) foreach (preg_split('/\s+/', trim((string) $refs)) ?: [] as $ref) if ($ref !== '') $graph['html_refs'][] = $ref;
    }
    if (preg_match_all('~\bhref\s*=\s*["\']#([^"\']+)["\']~i', $source, $matches)) $graph['html_refs'] = array_merge($graph['html_refs'], $matches[1]);
    if (preg_match_all('~\bdata-([A-Za-z0-9_-]+)\s*=~', $source, $matches)) $graph['data_keys'] = $matches[1];

    if (preg_match_all('~(?<![A-Za-z0-9_-])#([A-Za-z_][A-Za-z0-9_-]*)~', $source, $matches)) $graph['css_ids'] = $matches[1];
    if (preg_match_all('~(?<![A-Za-z0-9_-])\.([A-Za-z_][A-Za-z0-9_-]*)~', $source, $matches)) $graph['css_classes'] = $matches[1];
    if (preg_match_all('~--([A-Za-z0-9_-]+)~', $source, $matches)) $graph['css_vars'] = $matches[1];

    if (preg_match_all('~(?:querySelector|querySelectorAll)\s*\(\s*["\']([^"\']+)["\']~', $source, $matches)) $graph['js_selectors'] = $matches[1];
    if (preg_match_all('~getElementById\s*\(\s*["\']([^"\']+)["\']~', $source, $matches)) $graph['js_ids'] = $matches[1];
    if (preg_match_all('~classList\.(?:add|remove|toggle|contains)\s*\(\s*["\']([^"\']+)["\']~', $source, $matches)) $graph['js_classes'] = $matches[1];

    foreach ($graph as &$values) {
      $values = array_values(array_unique(array_map('strval', $values)));
      sort($values, SORT_STRING);
    }
    unset($values);
    return $graph;
  }

  private static function layer_name(string $mode): string {
    if ($mode === 'js_string') return 'javascript';
    if ($mode === 'css_string') return 'css';
    return 'html';
  }
}
