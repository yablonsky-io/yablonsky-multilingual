<?php
namespace Yablonsky\Multilingual\Compat;

use Yablonsky\Multilingual\CommerceStrings;
use Yablonsky\Multilingual\Languages;
use Yablonsky\Multilingual\LocaleRuntime;
use Yablonsky\Multilingual\Router;
use Yablonsky\Multilingual\SiteCoverage;

if (!defined('ABSPATH')) exit;

/**
 * WooCommerce capability and admin integration layer.
 *
 * YPM keeps multilingual identity separate from WooCommerce commerce state.
 * The manifest below is intentionally explicit so future sync code cannot
 * accidentally treat stock/SKU/price as translatable text.
 */
final class Woo {
  private const OPT_ATTRIBUTE_LABELS = 'ypm_woo_attribute_labels_v1';
  public static function init(): void {
    add_filter('yablonsky_multilingual_supported_taxonomies', [__CLASS__, 'filter_supported_taxonomies']);
    add_action('admin_menu', [__CLASS__, 'register_admin_page'], 30);
    add_action('admin_post_ypm_save_woo_strings', [__CLASS__, 'handle_save_strings']);
    add_action('admin_post_ypm_save_woo_attribute_labels', [__CLASS__, 'handle_save_attribute_labels']);
    add_filter('woocommerce_attribute_label', [__CLASS__, 'filter_attribute_label'], 20, 3);
    add_action('woocommerce_attribute_added', [__CLASS__, 'on_attribute_schema_changed'], 100, 2);
    add_action('woocommerce_attribute_updated', [__CLASS__, 'on_attribute_schema_changed'], 100, 3);
    add_action('woocommerce_attribute_deleted', [__CLASS__, 'on_attribute_schema_changed'], 100, 3);
  }

  public static function is_active(): bool {
    return class_exists('WooCommerce') || function_exists('WC');
  }

  /**
   * @param string[] $taxonomies
   * @return string[]
   */
  public static function filter_supported_taxonomies(array $taxonomies): array {
    if (!self::is_active()) return $taxonomies;
    return array_values(array_unique(array_merge($taxonomies, self::translatable_taxonomies())));
  }

  /**
   * WooCommerce taxonomies whose labels/terms are content and therefore need
   * their own locale identity. Operational taxonomies remain shared.
   *
   * @return string[]
   */
  public static function translatable_taxonomies(): array {
    if (!self::is_active()) return [];

    $out = [];
    foreach (['product_cat', 'product_tag', 'product_brand'] as $taxonomy) {
      if (taxonomy_exists($taxonomy)) $out[] = $taxonomy;
    }

    foreach (self::attribute_taxonomies() as $taxonomy) $out[] = $taxonomy;
    return array_values(array_unique($out));
  }

  /**
   * Operational Woo taxonomies that must not be translated into new commerce
   * identities because WooCommerce logic relies on their shared semantics.
   *
   * @return string[]
   */
  public static function shared_taxonomies(): array {
    $out = [];
    foreach (['product_type', 'product_visibility', 'product_shipping_class'] as $taxonomy) {
      if (taxonomy_exists($taxonomy)) $out[] = $taxonomy;
    }
    return $out;
  }

  /**
   * @return string[]
   */
  public static function attribute_taxonomies(): array {
    if (!self::is_active()) return [];

    $out = [];
    if (function_exists('wc_get_attribute_taxonomies') && function_exists('wc_attribute_taxonomy_name')) {
      $attributes = wc_get_attribute_taxonomies();
      if (is_array($attributes)) {
        foreach ($attributes as $attribute) {
          if (!is_object($attribute) || empty($attribute->attribute_name)) continue;
          $taxonomy = wc_attribute_taxonomy_name((string) $attribute->attribute_name);
          if ($taxonomy && taxonomy_exists($taxonomy)) $out[] = $taxonomy;
        }
      }
    }

    return array_values(array_unique($out));
  }

  /**
   * @return array<string,array<int,string>>
   */
  public static function field_policy(): array {
    return [
      'localized_content' => [
        __('Product name / title', 'yablonsky-multilingual'),
        __('Slug / localized URL', 'yablonsky-multilingual'),
        __('Description', 'yablonsky-multilingual'),
        __('Short description', 'yablonsky-multilingual'),
        __('Purchase note', 'yablonsky-multilingual'),
        __('External-product button text', 'yablonsky-multilingual'),
      ],
      'shared_commerce_state' => [
        __('SKU / global unique identifier', 'yablonsky-multilingual'),
        __('Regular / sale price', 'yablonsky-multilingual'),
        __('Stock quantity and stock status', 'yablonsky-multilingual'),
        __('Backorders', 'yablonsky-multilingual'),
        __('Tax status / tax class', 'yablonsky-multilingual'),
        __('Weight and dimensions', 'yablonsky-multilingual'),
        __('Shipping class', 'yablonsky-multilingual'),
        __('Virtual / downloadable state', 'yablonsky-multilingual'),
      ],
      'localized_relationships' => [
        __('Product categories', 'yablonsky-multilingual'),
        __('Product tags', 'yablonsky-multilingual'),
        __('Global attributes and attribute terms', 'yablonsky-multilingual'),
        __('Brands when the product_brand taxonomy exists', 'yablonsky-multilingual'),
        __('Variation presentation relationships', 'yablonsky-multilingual'),
      ],
    ];
  }

  /** @return array<string,array<string,mixed>> */
  public static function completeness_matrix(): array {
    $attributes = self::attribute_taxonomies();
    $translatable = self::translatable_taxonomies();
    return [
      'products' => ['ready' => post_type_exists('product'), 'detail' => __('Separate locale-aware product content with shared commerce state', 'yablonsky-multilingual')],
      'product_categories' => ['ready' => in_array('product_cat', $translatable, true), 'detail' => __('Taxonomy translation families', 'yablonsky-multilingual')],
      'product_tags' => ['ready' => !taxonomy_exists('product_tag') || in_array('product_tag', $translatable, true), 'detail' => __('Taxonomy translation families', 'yablonsky-multilingual')],
      'global_attributes' => ['ready' => true, 'detail' => sprintf(
        /* translators: %d: number of WooCommerce attribute taxonomies discovered. */
        _n('%d attribute taxonomy discovered dynamically', '%d attribute taxonomies discovered dynamically', count($attributes), 'yablonsky-multilingual'),
        count($attributes)
      )],
      'attribute_terms' => ['ready' => !$attributes || !array_diff($attributes, $translatable), 'detail' => __('Attribute values use term translation families', 'yablonsky-multilingual')],
      'variations' => ['ready' => post_type_exists('product_variation'), 'detail' => __('Bounded sibling sync with localized attribute relationships', 'yablonsky-multilingual')],
      'commerce_emails' => ['ready' => class_exists('Yablonsky\\Multilingual\\Compat\\WooOrderLanguage'), 'detail' => __('Order-scoped locale + commerce string overrides', 'yablonsky-multilingual')],
    ];
  }

  /**
   * Actual localization completion for the installed WooCommerce catalog.
   * This is intentionally separate from completeness_matrix(), which reports
   * whether YMulti implements the capability at all.
   *
   * @return array<string,array<string,mixed>>
   */
  public static function localization_coverage_matrix(): array {
    $attributes = self::attribute_taxonomies();
    $products = SiteCoverage::posts(['product']);
    $categories = taxonomy_exists('product_cat') ? SiteCoverage::terms(['product_cat']) : SiteCoverage::metric(0, 0, __('Product categories: 0 in current scope.', 'yablonsky-multilingual'));
    $tags = taxonomy_exists('product_tag') ? SiteCoverage::terms(['product_tag']) : SiteCoverage::metric(0, 0, __('Product tags: 0 in current scope.', 'yablonsky-multilingual'));
    $attribute_labels = SiteCoverage::attribute_labels($attributes);
    $attribute_terms = $attributes ? SiteCoverage::terms($attributes) : SiteCoverage::metric(0, 0, __('Global attribute terms: 0 configured.', 'yablonsky-multilingual'));
    $emails = SiteCoverage::commerce_emails();
    $variations = $products;
    $variations['detail'] = __('Variation locale readiness follows translated product families; sellable state and global-attribute relationships synchronize inside those families.', 'yablonsky-multilingual');

    return [
      'products' => $products + ['title' => __('Products', 'yablonsky-multilingual'), 'url' => admin_url('admin.php?page=ypm-translations&post_type=product')],
      'product_categories' => $categories + ['title' => __('Product Categories', 'yablonsky-multilingual'), 'url' => admin_url('admin.php?page=ypm-taxonomies&taxonomy=product_cat')],
      'product_tags' => $tags + ['title' => __('Product Tags', 'yablonsky-multilingual'), 'url' => admin_url('admin.php?page=ypm-taxonomies&taxonomy=product_tag')],
      'global_attributes' => $attribute_labels + ['title' => __('Global Attributes', 'yablonsky-multilingual'), 'url' => admin_url('admin.php?page=ypm-woocommerce#ypm-woo-attribute-labels')],
      'attribute_terms' => $attribute_terms + ['title' => __('Attribute Terms', 'yablonsky-multilingual'), 'url' => admin_url('admin.php?page=ypm-taxonomies')],
      'variations' => $variations + ['title' => __('Variations', 'yablonsky-multilingual'), 'url' => admin_url('admin.php?page=ypm-translations&post_type=product')],
      'commerce_emails' => $emails + ['title' => __('Commerce Emails', 'yablonsky-multilingual'), 'url' => admin_url('admin.php?page=ypm-woo-emails')],
    ];
  }

  public static function register_admin_page(): void {
    if (!self::is_active()) return;

    add_submenu_page(
      'ypm-multilingual',
      __('WooCommerce', 'yablonsky-multilingual'),
      __('WooCommerce', 'yablonsky-multilingual'),
      'manage_woocommerce',
      'ypm-woocommerce',
      [__CLASS__, 'render_admin_page']
    );
  }

  public static function render_admin_page(): void {
    if (!current_user_can('manage_woocommerce')) return;

    $active = Languages::active_codes();
    $selected_raw = filter_input(INPUT_GET, 'locale', FILTER_UNSAFE_RAW);
    $selected = is_string($selected_raw) ? sanitize_text_field($selected_raw) : '';
    if ($selected === '' || !in_array($selected, $active, true)) $selected = Languages::default_code();

    $saved = filter_input(INPUT_GET, 'saved', FILTER_VALIDATE_BOOLEAN) === true;
    $overrides = CommerceStrings::overrides($selected);
    $locale_meta = Languages::locale_metadata($selected);
    $mapped_wp_locale = isset($locale_meta['wp_locale']) ? (string) $locale_meta['wp_locale'] : '';
    $rows = self::entity_rows();
    $translatable_count = 1 + count(self::translatable_taxonomies());
    $override_count = 0;
    foreach ($overrides as $value) if (is_string($value) && trim($value) !== '') $override_count++;

    echo '<div class="wrap ypm-admin">';
    \Yablonsky\Multilingual\Admin::render_shell_header(
      __('WooCommerce', 'yablonsky-multilingual'),
      __('Full WooCommerce multilingual runtime: localized content, synchronized commerce state, localized relationships and native-first UI strings.', 'yablonsky-multilingual'),
      'pass',
      __('Detected', 'yablonsky-multilingual')
    );

    if ($saved) echo '<div class="ypm-callout ypm-callout--pass"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><div><strong>' . esc_html__('Overrides saved', 'yablonsky-multilingual') . '</strong><p>' . esc_html__('The locale dictionary will be reused from request memory after its first load.', 'yablonsky-multilingual') . '</p></div></div>';

    echo '<div class="ypm-metrics ypm-metrics--4">';
    \Yablonsky\Multilingual\Admin::render_metric(__('WooCommerce', 'yablonsky-multilingual'), defined('WC_VERSION') ? (string) WC_VERSION : __('Active', 'yablonsky-multilingual'), __('First-class workload', 'yablonsky-multilingual'), 'pass');
    \Yablonsky\Multilingual\Admin::render_metric(__('Translatable entities', 'yablonsky-multilingual'), (string) $translatable_count, __('Products + taxonomies', 'yablonsky-multilingual'), 'neutral');
    \Yablonsky\Multilingual\Admin::render_metric(__('UI locale', 'yablonsky-multilingual'), Languages::display_code($selected), $mapped_wp_locale !== '' ? $mapped_wp_locale : __('Mapping pending', 'yablonsky-multilingual'), $mapped_wp_locale !== '' ? 'pass' : 'attention');
    \Yablonsky\Multilingual\Admin::render_metric(__('Overrides', 'yablonsky-multilingual'), (string) $override_count, __('Native translations remain default', 'yablonsky-multilingual'), 'neutral');
    echo '</div>';

    $coverage = self::localization_coverage_matrix();
    $coverage_metrics = [];
    foreach ($coverage as $key => $row) {
      // Variations intentionally mirror product-family completion; do not double
      // weight the same translated product records in the aggregate badge.
      if ($key === 'variations') continue;
      $coverage_metrics[] = $row;
    }
    $overall_coverage = SiteCoverage::combine($coverage_metrics);
    echo '<section class="ypm-card ypm-card--standalone ymulti-commerce-readiness-card"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Localization progress', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('WooCommerce multilingual completion', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('This is live site completion evidence. Each percentage compares localized locale records with the records required by the currently active languages.', 'yablonsky-multilingual') . '</p></div>' . wp_kses_post(\Yablonsky\Multilingual\Admin::status_badge((string) $overall_coverage['state'], (string) $overall_coverage['label'])) . '</div>';
    echo '<div class="ymulti-commerce-overall-progress is-' . esc_attr((string) $overall_coverage['state']) . '"><div class="ymulti-commerce-overall-progress__meta"><strong>' . esc_html((string) $overall_coverage['label']) . '</strong><span>' . esc_html(sprintf(
      /* translators: 1: localized locale records, 2: required locale records. */
      __('%1$d of %2$d required locale records complete', 'yablonsky-multilingual'),
      (int) $overall_coverage['done'],
      (int) $overall_coverage['total']
    )) . '</span></div><span class="ymulti-coverage-rail"><i style="width:' . esc_attr((string) $overall_coverage['percent']) . '%"></i></span></div>';
    echo '<div class="ymulti-commerce-readiness">';
    foreach ($coverage as $row) {
      $state = (string) ($row['state'] ?? 'neutral');
      $percent = max(0, min(100, (int) ($row['percent'] ?? 0)));
      $title = sanitize_text_field((string) ($row['title'] ?? ''));
      $detail = sanitize_text_field((string) ($row['detail'] ?? ''));
      $url = (string) ($row['url'] ?? admin_url('admin.php?page=ypm-woocommerce'));
      $icon = in_array($state, ['pass', 'near'], true) ? 'dashicons-yes-alt' : (in_array($state, ['progress', 'attention'], true) ? 'dashicons-chart-bar' : 'dashicons-minus');
      $class = $state === 'pass' ? 'is-ready' : ($state === 'near' ? 'is-near' : (in_array($state, ['progress', 'attention'], true) ? 'is-progress' : 'is-neutral'));
      echo '<div class="ymulti-commerce-readiness__item ' . esc_attr($class) . '"><span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span><div class="ymulti-commerce-readiness__copy"><strong>' . esc_html($title) . '</strong><span>' . esc_html($detail) . '</span><span class="ymulti-coverage-rail"><i style="width:' . esc_attr((string) $percent) . '%"></i></span></div><div class="ymulti-commerce-readiness__actions">' . wp_kses_post(\Yablonsky\Multilingual\Admin::status_badge($state, (string) ($row['label'] ?? ''))) . '<a class="button button-small" href="' . esc_url($url) . '">' . esc_html__('Open', 'yablonsky-multilingual') . '</a></div></div>';
    }
    echo '</div></section>';

    echo '<section class="ypm-card ypm-card--standalone ymulti-customer-communication"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Customer communication', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Order-aware email language', 'yablonsky-multilingual') . '</h2><p class="ypm-card__copy">' . esc_html__('Transactional emails follow the language captured on each order. Configure localized commerce strings and review email coverage in one dedicated workspace.', 'yablonsky-multilingual') . '</p></div><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=ypm-woo-emails')) . '">' . esc_html__('Configure email language', 'yablonsky-multilingual') . '</a></div><div class="ymulti-customer-communication__flow"><span><strong>' . esc_html__('Checkout', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Capture order language', 'yablonsky-multilingual') . '</small></span><i class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></i><span><strong>' . esc_html__('Order', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Persist locale', 'yablonsky-multilingual') . '</small></span><i class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></i><span><strong>' . esc_html__('Email', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Render localized communication', 'yablonsky-multilingual') . '</small></span></div></section>';

    echo '<section class="ypm-card ypm-card--commerce ypm-card--standalone">';
    echo '<div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Commerce model', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Three clear data responsibilities', 'yablonsky-multilingual') . '</h2></div><span class="ypm-pill">' . esc_html__('Native-first', 'yablonsky-multilingual') . '</span></div>';
    echo '<div class="ypm-policy-grid">';
    foreach (self::field_policy() as $key => $items) {
      if ($key === 'localized_content') { $label = __('Translated per locale', 'yablonsky-multilingual'); $icon = 'edit-page'; }
      elseif ($key === 'shared_commerce_state') { $label = __('Shared commerce state', 'yablonsky-multilingual'); $icon = 'database'; }
      else { $label = __('Localized relationships', 'yablonsky-multilingual'); $icon = 'networking'; }
      echo '<div class="ypm-policy-card"><span class="dashicons dashicons-' . esc_attr($icon) . '" aria-hidden="true"></span><strong>' . esc_html($label) . '</strong><p>' . esc_html(implode(', ', $items)) . '</p></div>';
    }
    echo '</div></section>';

    echo '<div class="ypm-dashboard-grid">';
    echo '<section class="ypm-card"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('Detected', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('WooCommerce entities', 'yablonsky-multilingual') . '</h2></div><button type="button" class="button" data-ypm-open-details="ypm-woo-entities">' . esc_html__('View all', 'yablonsky-multilingual') . '</button></div>';
    echo '<p class="ypm-card__copy">' . esc_html__('YMulti discovers product taxonomies and global attributes dynamically. Operational taxonomies remain shared.', 'yablonsky-multilingual') . '</p><div class="ypm-entity-chips">';
    foreach (array_slice($rows, 0, 6) as $row) echo '<a href="' . esc_url($row['url']) . '"><strong>' . esc_html($row['label']) . '</strong><code>' . esc_html($row['key']) . '</code></a>';
    if (count($rows) > 6) echo '<span class="ypm-more-chip">+' . esc_html((string) (count($rows) - 6)) . '</span>';
    echo '</div></section>';

    echo '<section class="ypm-card"><div class="ypm-card__head"><div><span class="ypm-eyebrow">' . esc_html__('UI language', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Native packs + optional overrides', 'yablonsky-multilingual') . '</h2></div>' . wp_kses_post(\Yablonsky\Multilingual\Admin::status_badge($mapped_wp_locale !== '' ? 'pass' : 'attention', $mapped_wp_locale !== '' ? __('Mapped', 'yablonsky-multilingual') : __('Review', 'yablonsky-multilingual'))) . '</div>';
    echo '<p class="ypm-card__copy">' . esc_html__('Standard WooCommerce, WordPress and theme strings use their native language packs. YMulti overrides are loaded once per locale/request and then served from memory.', 'yablonsky-multilingual') . '</p>';
    echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="ypm-inline-action ypm-locale-selector"><input type="hidden" name="page" value="ypm-woocommerce"><label><span>' . esc_html__('Locale', 'yablonsky-multilingual') . '</span><select name="locale">';
    foreach ($active as $code) echo '<option value="' . esc_attr($code) . '"' . selected($selected, $code, false) . '>' . esc_html(Languages::display_name($code) . ' · ' . Languages::display_code($code)) . '</option>';
    echo '</select></label><button class="button" type="submit">' . esc_html__('View', 'yablonsky-multilingual') . '</button></form>';
    echo '<div class="ypm-runtime-note"><code>content=' . esc_html($selected) . '</code><span>→</span><code>wp=' . esc_html($mapped_wp_locale !== '' ? $mapped_wp_locale : '—') . '</code></div></section></div>';

    echo '<details class="ypm-disclosure ypm-disclosure--wide" id="ypm-woo-entities"><summary><span><strong>' . esc_html__('All detected WooCommerce entities', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Open native WooCommerce management screens while YMulti keeps multilingual context alongside them', 'yablonsky-multilingual') . '</small></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary><div class="ypm-disclosure__body">';
    echo '<div class="ypm-manager-bar"><label for="ypm-woo-entity-search"><span>' . esc_html__('Find entity', 'yablonsky-multilingual') . '</span><input id="ypm-woo-entity-search" type="search" placeholder="' . esc_attr__('Search taxonomy, attribute or policy…', 'yablonsky-multilingual') . '"></label><span class="ypm-manager-count">' . esc_html((string) count($rows)) . ' ' . esc_html__('detected', 'yablonsky-multilingual') . '</span></div>';
    echo '<div class="ypm-entity-list" id="ypm-woo-entity-list">';
    foreach ($rows as $row) {
      $entity_search = strtolower((string) $row['label'] . ' ' . (string) $row['key'] . ' ' . (string) $row['policy']);
      echo '<div data-ypm-entity-search="' . esc_attr($entity_search) . '"><div><strong>' . esc_html($row['label']) . '</strong><code>' . esc_html($row['key']) . '</code><small>' . esc_html($row['policy']) . '</small></div><a class="button button-small" href="' . esc_url($row['url']) . '">' . esc_html__('Open', 'yablonsky-multilingual') . '</a></div>';
    }
    echo '</div>';
    \Yablonsky\Multilingual\Admin::render_client_pager('ypm-woo-entity-pager', 20, [20, 40, 80]);
    echo '<p id="ypm-woo-entity-empty" class="ypm-empty-state" hidden>' . esc_html__('0 detected entities match this search.', 'yablonsky-multilingual') . '</p></div></details>';

    $attribute_catalog = self::attribute_label_catalog();
    $attribute_labels = self::attribute_labels_for_locale($selected);
    echo '<details class="ypm-disclosure ypm-disclosure--wide" id="ypm-woo-attribute-labels"' . ($attribute_catalog ? '' : ' hidden') . '><summary><span><strong>' . esc_html__('Global attribute labels', 'yablonsky-multilingual') . '</strong><small>' . esc_html__('Translate the human label while keeping one shared WooCommerce attribute taxonomy and variation identity.', 'yablonsky-multilingual') . '</small></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary><div class="ypm-disclosure__body">';
    if ($attribute_catalog) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ypm-woo-string-form">';
      wp_nonce_field('ypm_save_woo_attribute_labels', 'ypm_woo_attribute_labels_nonce');
      echo '<input type="hidden" name="action" value="ypm_save_woo_attribute_labels"><input type="hidden" name="locale" value="' . esc_attr($selected) . '"><div class="ypm-string-list">';
      foreach ($attribute_catalog as $taxonomy => $source_label) {
        $value = isset($attribute_labels[$taxonomy]) ? (string) $attribute_labels[$taxonomy] : '';
        echo '<label class="ypm-string-row"><span><strong>' . esc_html($source_label) . '</strong><code>' . esc_html($taxonomy) . '</code><small>' . esc_html__('Attribute values/terms use normal YMulti taxonomy families.', 'yablonsky-multilingual') . '</small></span><input type="text" name="attribute_labels[' . esc_attr($taxonomy) . ']" value="' . esc_attr($value) . '" placeholder="' . esc_attr($source_label) . '"></label>';
      }
      echo '</div><div class="ypm-sticky-actions"><span>' . esc_html__('The original WooCommerce attribute label remains active until you save a localized override.', 'yablonsky-multilingual') . '</span><button class="button button-primary" type="submit">' . esc_html__('Save attribute labels', 'yablonsky-multilingual') . '</button></div></form>';
    } else {
      echo '<p>' . esc_html__('Global WooCommerce attributes: 0 registered.', 'yablonsky-multilingual') . '</p>';
    }
    echo '</div></details>';

    echo '<details class="ypm-disclosure ypm-disclosure--wide" id="ypm-woo-overrides"><summary><span><strong>' . esc_html__('WooCommerce UI string overrides', 'yablonsky-multilingual') . '</strong><small>' . esc_html((string) $override_count) . ' ' . esc_html__('custom overrides for the selected locale · native translation remains the default', 'yablonsky-multilingual') . '</small></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary><div class="ypm-disclosure__body">';
    echo '<div class="ypm-manager-bar"><div><strong>' . esc_html(Languages::display_name($selected)) . '</strong><small>' . esc_html(Languages::display_code($selected) . ' → ' . ($mapped_wp_locale !== '' ? $mapped_wp_locale : '—')) . '</small></div><input id="ypm-woo-string-search" type="search" placeholder="' . esc_attr__('Search WooCommerce UI strings…', 'yablonsky-multilingual') . '"></div>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ypm-woo-string-form">';
    wp_nonce_field('ypm_save_woo_strings', 'ypm_woo_strings_nonce');
    echo '<input type="hidden" name="action" value="ypm_save_woo_strings"><input type="hidden" name="locale" value="' . esc_attr($selected) . '"><div class="ypm-string-list" id="ypm-woo-string-list">';
    foreach (CommerceStrings::catalog() as $source => $description) {
      $value = isset($overrides[$source]) ? (string) $overrides[$source] : '';
      $search = strtolower($source . ' ' . $description);
      echo '<label class="ypm-string-row" data-ypm-string-search="' . esc_attr($search) . '"><span><code>' . esc_html($source) . '</code><small>' . esc_html($description) . '</small></span><textarea rows="2" name="strings[' . esc_attr(sha1($source)) . ']" placeholder="' . esc_attr__('Use native translation', 'yablonsky-multilingual') . '">' . esc_textarea($value) . '</textarea></label>';
    }
    echo '</div>';
    \Yablonsky\Multilingual\Admin::render_client_pager('ypm-woo-string-pager', 20, [20, 40, 80]);
    echo '<p id="ypm-woo-string-empty" class="ypm-empty-state" style="display:none">' . esc_html__('0 WooCommerce UI strings match this search.', 'yablonsky-multilingual') . '</p><div class="ypm-sticky-actions"><span>' . esc_html__('The native WooCommerce translation remains active until you save an override.', 'yablonsky-multilingual') . '</span><button class="button button-primary" type="submit">' . esc_html__('Save overrides', 'yablonsky-multilingual') . '</button></div></form></div></details>';

    echo '</div>';
  }

  /** @return array<string,string> taxonomy => source label */
  private static function attribute_label_catalog(): array {
    $out = [];
    if (!self::is_active() || !function_exists('wc_get_attribute_taxonomies') || !function_exists('wc_attribute_taxonomy_name')) return $out;
    $attributes = wc_get_attribute_taxonomies();
    if (!is_array($attributes)) return $out;
    foreach ($attributes as $attribute) {
      if (!is_object($attribute) || empty($attribute->attribute_name)) continue;
      $taxonomy = sanitize_key((string) wc_attribute_taxonomy_name((string) $attribute->attribute_name));
      if ($taxonomy === '') continue;
      $label = sanitize_text_field((string) ($attribute->attribute_label ?? $attribute->attribute_name));
      $out[$taxonomy] = $label !== '' ? $label : $taxonomy;
    }
    return $out;
  }

  /** @return array<string,string> */
  private static function attribute_labels_for_locale(string $locale): array {
    $all = get_option(self::OPT_ATTRIBUTE_LABELS, []);
    if (!is_array($all) || !isset($all[$locale]) || !is_array($all[$locale])) return [];
    $out = [];
    foreach ($all[$locale] as $taxonomy => $label) {
      if (!is_string($taxonomy) || !is_scalar($label)) continue;
      $taxonomy = sanitize_key($taxonomy);
      $label = sanitize_text_field((string) $label);
      if ($taxonomy !== '' && $label !== '') $out[$taxonomy] = $label;
    }
    return $out;
  }

  public static function filter_attribute_label(string $label, string $name, $product = null): string {
    unset($product);
    if (is_admin() && !wp_doing_ajax()) return $label;
    $taxonomy = sanitize_key($name);
    if ($taxonomy === '' || strpos($taxonomy, 'pa_') !== 0) return $label;
    $locale = Router::current_lang();
    if ($locale === '' || $locale === Languages::default_code()) return $label;
    $labels = self::attribute_labels_for_locale($locale);
    return isset($labels[$taxonomy]) && $labels[$taxonomy] !== '' ? $labels[$taxonomy] : $label;
  }

  public static function handle_save_attribute_labels(): void {
    if (!current_user_can('manage_woocommerce')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_save_woo_attribute_labels', 'ypm_woo_attribute_labels_nonce');
    $locale_raw = filter_input(INPUT_POST, 'locale', FILTER_UNSAFE_RAW);
    $locale = is_string($locale_raw) ? sanitize_text_field($locale_raw) : '';
    if ($locale === '' || !Languages::is_active($locale)) wp_die(esc_html__('Invalid locale.', 'yablonsky-multilingual'));
    $raw = filter_input(INPUT_POST, 'attribute_labels', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
    if (!is_array($raw)) $raw = [];
    $catalog = self::attribute_label_catalog();
    $clean = [];
    foreach ($raw as $taxonomy => $label) {
      if (!is_string($taxonomy) || !is_scalar($label)) continue;
      $taxonomy = sanitize_key($taxonomy);
      if (!isset($catalog[$taxonomy])) continue;
      $label = sanitize_text_field((string) $label);
      if ($label !== '') $clean[$taxonomy] = $label;
    }
    $all = get_option(self::OPT_ATTRIBUTE_LABELS, []);
    if (!is_array($all)) $all = [];
    if ($clean) $all[$locale] = $clean; else unset($all[$locale]);
    update_option(self::OPT_ATTRIBUTE_LABELS, $all, false);
    do_action('yablonsky_multilingual_semantic_invalidated_v1', [
      'contract' => 'ymulti-semantic-invalidation/v1',
      'reason' => 'woocommerce_attribute_labels_changed',
      'resource_type' => 'taxonomy_label',
      'scope' => 'woocommerce_attribute_labels',
      'taxonomies' => array_values(array_keys($catalog)),
      'languages' => [$locale],
      'urls' => [],
      'requires_broad_invalidation' => false,
      'purge_executor' => false,
    ]);
    wp_safe_redirect(admin_url('admin.php?page=ypm-woocommerce&locale=' . rawurlencode($locale) . '&saved=1#ypm-woo-attribute-labels'));
    exit;
  }

  /** @param mixed $data @param mixed $extra */
  public static function on_attribute_schema_changed(int $attribute_id = 0, $data = [], $extra = null): void {
    unset($attribute_id, $extra);
    $taxonomy = '';
    if (is_array($data) && !empty($data['attribute_name']) && function_exists('wc_attribute_taxonomy_name')) {
      $taxonomy = sanitize_key((string) wc_attribute_taxonomy_name((string) $data['attribute_name']));
    } elseif (is_object($data) && !empty($data->attribute_name) && function_exists('wc_attribute_taxonomy_name')) {
      $taxonomy = sanitize_key((string) wc_attribute_taxonomy_name((string) $data->attribute_name));
    } elseif (is_string($data) && strpos($data, 'pa_') === 0) {
      $taxonomy = sanitize_key($data);
    }
    do_action('yablonsky_multilingual_semantic_invalidated_v1', [
      'contract' => 'ymulti-semantic-invalidation/v1',
      'reason' => 'woocommerce_attribute_schema_changed',
      'resource_type' => 'taxonomy_schema',
      'scope' => 'woocommerce_attribute_schema',
      'taxonomies' => $taxonomy !== '' ? [$taxonomy] : self::attribute_taxonomies(),
      'languages' => Languages::active_codes(),
      'urls' => [],
      'requires_broad_invalidation' => false,
      'purge_executor' => false,
    ]);
  }

  public static function handle_save_strings(): void {
    if (!current_user_can('manage_woocommerce')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_save_woo_strings', 'ypm_woo_strings_nonce');

    $locale_raw = filter_input(INPUT_POST, 'locale', FILTER_UNSAFE_RAW);
    $locale = is_string($locale_raw) ? sanitize_text_field($locale_raw) : '';
    if ($locale === '' || !Languages::is_active($locale)) wp_die(esc_html__('Invalid locale.', 'yablonsky-multilingual'));

    $raw = filter_input(INPUT_POST, 'strings', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
    if (!is_array($raw)) $raw = [];

    $catalog = CommerceStrings::catalog();
    $source_by_hash = [];
    foreach (array_keys($catalog) as $source) $source_by_hash[sha1($source)] = $source;

    $values = [];
    foreach ($raw as $hash => $value) {
      if (!is_string($hash) || !is_scalar($value) || !isset($source_by_hash[$hash])) continue;
      $source = $source_by_hash[$hash];
      $values[$source] = sanitize_textarea_field((string) $value);
    }

    CommerceStrings::save_overrides($locale, $values);
    wp_safe_redirect(admin_url('admin.php?page=ypm-woocommerce&locale=' . rawurlencode($locale) . '&saved=1'));
    exit;
  }

  /**
   * Preserve Woo product type on a newly created YPM translation draft.
   * This is structural state, not translated content.
   */
  public static function initialize_translation_draft(int $source_id, int $target_id): void {
    if (!self::is_active()) return;
    if (get_post_type($source_id) !== 'product' || get_post_type($target_id) !== 'product') return;

    if (taxonomy_exists('product_type')) {
      $types = wp_get_object_terms($source_id, 'product_type', ['fields' => 'ids']);
      if (!is_wp_error($types) && $types) wp_set_object_terms($target_id, array_map('intval', $types), 'product_type', false);
    }

    do_action('yablonsky_multilingual_woocommerce_translation_draft_initialized', $source_id, $target_id);
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Legacy YPM public compatibility hook.
    do_action('ypm_woocommerce_translation_draft_initialized', $source_id, $target_id);
  }

  /**
   * @return array<int,array<string,string>>
   */
  private static function entity_rows(): array {
    $rows = [
      [
        'label' => __('Products', 'yablonsky-multilingual'),
        'key' => 'product',
        'policy' => __('Separate product object per locale with shared commerce-state synchronization across the translation family.', 'yablonsky-multilingual'),
        'url' => admin_url('edit.php?post_type=product'),
      ],
    ];

    foreach (self::translatable_taxonomies() as $taxonomy) {
      $obj = get_taxonomy($taxonomy);
      $label = $obj && isset($obj->labels->name) ? (string) $obj->labels->name : $taxonomy;
      $rows[] = [
        'label' => $label,
        'key' => $taxonomy,
        'policy' => __('Translatable taxonomy identity; term translation relationships are handled separately from shared Woo operational state.', 'yablonsky-multilingual'),
        'url' => admin_url('edit-tags.php?taxonomy=' . rawurlencode($taxonomy) . '&post_type=product'),
      ];
    }

    foreach (self::shared_taxonomies() as $taxonomy) {
      $obj = get_taxonomy($taxonomy);
      $label = $obj && isset($obj->labels->name) ? (string) $obj->labels->name : $taxonomy;
      $rows[] = [
        'label' => $label,
        'key' => $taxonomy,
        'policy' => __('Shared operational taxonomy keeps one commerce identity across languages; YMulti preserves that shared authority.', 'yablonsky-multilingual'),
        'url' => admin_url('edit-tags.php?taxonomy=' . rawurlencode($taxonomy) . '&post_type=product'),
      ];
    }

    return $rows;
  }
}
