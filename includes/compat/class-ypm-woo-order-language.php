<?php
namespace Yablonsky\Multilingual\Compat;

use Yablonsky\Multilingual\Languages;
use Yablonsky\Multilingual\Router;

if (!defined('ABSPATH')) exit;

/** Persist and reuse the customer's content locale through checkout and email. */
final class WooOrderLanguage {
  public const META = '_ypm_order_language';
  private const SESSION_KEY = 'ypm_content_language';
  private const EMAIL_OPT = 'ypm_woo_email_overrides';
  private const ORDER_NONCE_ACTION = 'ypm_save_order_language';
  private const ORDER_NONCE_NAME = 'ypm_order_language_nonce';

  private static int $email_locale_depth = 0;
  private static bool $email_filters_registered = false;

  public static function init(): void {
    if (!Woo::is_active()) return;

    add_action('wp', [__CLASS__, 'remember_frontend_language'], 5);
    add_action('woocommerce_checkout_create_order', [__CLASS__, 'set_classic_order_language'], 10, 2);
    add_action('woocommerce_store_api_checkout_order_processed', [__CLASS__, 'set_store_api_order_language'], 10, 1);

    // Generic option filter is efficient on Woo versions that expose it.
    add_filter('woocommerce_email_get_option', [__CLASS__, 'filter_email_option'], 10, 5);
    // Dynamic public email filters are long-standing Woo contracts and provide
    // a compatibility fallback for subject/heading/additional content.
    add_action('woocommerce_email', [__CLASS__, 'register_dynamic_email_filters'], 5, 1);

    if (is_admin()) {
      add_action('admin_menu', [__CLASS__, 'register_email_page'], 32);
      add_action('admin_post_ypm_save_woo_email_overrides', [__CLASS__, 'handle_save_email_overrides']);
      add_action('woocommerce_admin_order_data_after_order_details', [__CLASS__, 'render_order_language_field'], 20, 1);
      add_action('woocommerce_process_shop_order_meta', [__CLASS__, 'save_order_language_field'], 20, 2);
    }

    foreach (self::email_notification_actions() as $action) {
      add_action($action, [__CLASS__, 'begin_email_locale'], 1, 2);
      add_action($action, [__CLASS__, 'end_email_locale'], 999, 2);
    }
    add_action('woocommerce_before_resend_order_emails', [__CLASS__, 'begin_resend_email_locale'], 1, 2);
    add_action('woocommerce_after_resend_order_email', [__CLASS__, 'end_resend_email_locale'], 999, 2);
  }

  public static function remember_frontend_language(): void {
    if (is_admin() || wp_doing_ajax() || wp_doing_cron() || !function_exists('WC')) return;
    $wc = WC();
    if (!$wc || !$wc->session) return;
    $language = class_exists(Router::class) ? Router::current_lang() : Languages::default_code();
    if (Languages::is_active($language)) $wc->session->set(self::SESSION_KEY, $language);
  }

  /** @param \WC_Order $order */
  public static function set_classic_order_language($order, array $data): void {
    if (!is_object($order) || !method_exists($order, 'update_meta_data')) return;
    $order->update_meta_data(self::META, self::checkout_language());
  }

  /** @param \WC_Order $order */
  public static function set_store_api_order_language($order): void {
    if (!is_object($order) || !method_exists($order, 'update_meta_data')) return;
    $order->update_meta_data(self::META, self::checkout_language());
    if (method_exists($order, 'save_meta_data')) $order->save_meta_data();
    else $order->save();
  }

  public static function order_language($order): string {
    if (is_numeric($order) && function_exists('wc_get_order')) $order = wc_get_order((int) $order);
    if (is_object($order) && method_exists($order, 'get_meta')) {
      $value = sanitize_text_field((string) $order->get_meta(self::META, true));
      if ($value !== '' && Languages::is_active($value)) return $value;
    }
    return Languages::default_code();
  }

  private static function checkout_language(): string {
    if (function_exists('WC')) {
      $wc = WC();
      if ($wc && $wc->session) {
        $value = sanitize_text_field((string) $wc->session->get(self::SESSION_KEY, ''));
        if ($value !== '' && Languages::is_active($value)) return $value;
      }
    }
    if (class_exists(Router::class)) {
      $value = Router::current_lang();
      if (Languages::is_active($value)) return $value;
    }
    return Languages::default_code();
  }

  /**
   * Notification hooks run before WC_Email::trigger()->setup_locale(). We set
   * the order locale around the complete notification action and tell WC_Email
   * not to replace it with the global site locale.
   */
  public static function begin_email_locale($order_id = 0, $order = false): void {
    if (!function_exists('switch_to_locale')) return;
    if (!$order && $order_id && function_exists('wc_get_order')) $order = wc_get_order((int) $order_id);
    $language = self::order_language($order ?: $order_id);
    $meta = Languages::locale_metadata($language);
    $locale = isset($meta['wp_locale']) ? (string) $meta['wp_locale'] : '';
    if ($locale === '') return;

    if (switch_to_locale($locale)) {
      self::$email_locale_depth++;
      add_filter('woocommerce_email_setup_locale', '__return_false', 999);
      add_filter('woocommerce_email_restore_locale', '__return_false', 999);
    }
  }

  public static function begin_resend_email_locale($order, string $email_type = ''): void {
    self::begin_email_locale(is_object($order) && method_exists($order, 'get_id') ? (int) $order->get_id() : 0, $order);
  }

  public static function end_resend_email_locale($order, string $email_type = ''): void {
    self::end_email_locale(is_object($order) && method_exists($order, 'get_id') ? (int) $order->get_id() : 0, $order);
  }

  public static function end_email_locale($order_id = 0, $order = false): void {
    if (self::$email_locale_depth <= 0) return;
    self::$email_locale_depth--;
    if (function_exists('restore_previous_locale')) restore_previous_locale();
    if (self::$email_locale_depth === 0) {
      remove_filter('woocommerce_email_setup_locale', '__return_false', 999);
      remove_filter('woocommerce_email_restore_locale', '__return_false', 999);
    }
  }

  /**
   * Locale-specific merchant email fields. Empty override means native Woo setting.
   */
  public static function filter_email_option($value, $email, $raw_value, $key, $empty_value) {
    if (!in_array((string) $key, ['subject', 'heading', 'additional_content'], true)) return $value;
    if (!is_object($email) || empty($email->id) || empty($email->object)) return $value;
    $language = self::order_language($email->object);
    $all = get_option(self::EMAIL_OPT, []);
    if (!is_array($all)) return $value;
    $override = $all[(string) $email->id][$language][(string) $key] ?? '';
    return is_string($override) && trim($override) !== '' ? $override : $value;
  }


  /** @param mixed $emails */
  public static function register_dynamic_email_filters($emails = null): void {
    if (self::$email_filters_registered) return;
    if (is_object($emails) && method_exists($emails, 'get_emails')) {
      $emails = $emails->get_emails();
    } elseif (!is_array($emails)) {
      return;
    }
    if (!is_array($emails)) return;

    foreach ($emails as $email) {
      if (!is_object($email) || empty($email->id)) continue;
      $id = sanitize_key((string) $email->id);
      if ($id === '') continue;
      add_filter('woocommerce_email_subject_' . $id, [__CLASS__, 'filter_dynamic_subject'], 20, 3);
      add_filter('woocommerce_email_heading_' . $id, [__CLASS__, 'filter_dynamic_heading'], 20, 3);
      add_filter('woocommerce_email_additional_content_' . $id, [__CLASS__, 'filter_dynamic_additional_content'], 20, 3);
    }
    self::$email_filters_registered = true;
  }

  public static function filter_dynamic_subject($value, $object, $email) {
    return self::dynamic_email_override($value, $object, $email, 'subject');
  }

  public static function filter_dynamic_heading($value, $object, $email) {
    return self::dynamic_email_override($value, $object, $email, 'heading');
  }

  public static function filter_dynamic_additional_content($value, $object, $email) {
    return self::dynamic_email_override($value, $object, $email, 'additional_content');
  }

  private static function dynamic_email_override($value, $object, $email, string $key) {
    if (!is_object($email) || empty($email->id) || !$object) return $value;
    $language = self::order_language($object);
    $all = get_option(self::EMAIL_OPT, []);
    if (!is_array($all)) return $value;
    $override = $all[(string) $email->id][$language][$key] ?? '';
    return is_string($override) && trim($override) !== '' ? $override : $value;
  }

  /** @param \WC_Order $order */
  public static function render_order_language_field($order): void {
    if (!is_object($order) || !method_exists($order, 'get_id') || !current_user_can('edit_shop_orders')) return;
    $current = self::order_language($order);
    $active = Languages::active_codes();

    wp_nonce_field(self::ORDER_NONCE_ACTION, self::ORDER_NONCE_NAME);
    echo '<p class="form-field form-field-wide ypm-order-language-field">';
    echo '<label for="ypm_order_language">' . esc_html__('YMulti order language', 'yablonsky-multilingual') . '</label>';
    echo '<select id="ypm_order_language" name="ypm_order_language" style="width:100%">';
    foreach ($active as $code) {
      echo '<option value="' . esc_attr($code) . '"' . selected($current, $code, false) . '>' . esc_html(Languages::display_name($code) . ' (' . Languages::display_code($code) . ')') . '</option>';
    }
    echo '</select>';
    echo '<span class="description">' . esc_html__('Controls the locale used for customer-facing transactional emails for this order.', 'yablonsky-multilingual') . '</span>';
    echo '</p>';
  }

  public static function save_order_language_field(int $order_id, $post = null): void {
    if (!current_user_can('edit_shop_orders') || !function_exists('wc_get_order')) return;
    if (!isset($_POST[self::ORDER_NONCE_NAME]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::ORDER_NONCE_NAME])), self::ORDER_NONCE_ACTION)) return;
    $raw = isset($_POST['ypm_order_language']) ? sanitize_text_field(wp_unslash($_POST['ypm_order_language'])) : '';
    if ($raw === '' || !Languages::is_active($raw)) return;
    $order = wc_get_order($order_id);
    if (!$order || !method_exists($order, 'update_meta_data')) return;
    $order->update_meta_data(self::META, $raw);
    $order->save();
  }


  public static function register_email_page(): void {
    add_submenu_page(
      'ypm-multilingual',
      __('Commerce Emails', 'yablonsky-multilingual'),
      __('Commerce Emails', 'yablonsky-multilingual'),
      'manage_woocommerce',
      'ypm-woo-emails',
      [__CLASS__, 'render_email_page']
    );
  }

  /**
   * Identify the owner of an email class without assuming every WC_Email
   * registered in the mailer belongs to WooCommerce core.
   *
   * @param object $email WooCommerce email instance.
   * @return array{key:string,label:string,kind:string,kind_label:string,search:string}
   */
  private static function email_source($email): array {
    $class = is_object($email) ? get_class($email) : '';
    $file = '';
    if (is_object($email)) {
      try {
        $reflection = new \ReflectionClass($email);
        $ref_file = $reflection->getFileName();
        if (is_string($ref_file)) $file = wp_normalize_path($ref_file);
      } catch (\ReflectionException $e) {
        $file = '';
      }
    }

    $wc_root = defined('WC_ABSPATH') ? trailingslashit(wp_normalize_path((string) WC_ABSPATH)) : '';
    if ($wc_root !== '' && $file !== '' && strpos($file, $wc_root) === 0) {
      return [
        'key' => 'woocommerce-core',
        'label' => __('WooCommerce Core', 'yablonsky-multilingual'),
        'kind' => 'core',
        'kind_label' => __('Core', 'yablonsky-multilingual'),
        'search' => 'woocommerce core ' . strtolower($class),
      ];
    }

    $plugin_dir = '';
    $plugin_name = '';
    $plugin_author = '';
    $plugins_root = trailingslashit(wp_normalize_path(WP_PLUGIN_DIR));
    if ($file !== '' && strpos($file, $plugins_root) === 0) {
      $relative = ltrim(substr($file, strlen($plugins_root)), '/');
      $parts = explode('/', $relative);
      $plugin_dir = sanitize_key((string) ($parts[0] ?? ''));

      static $plugins = null;
      if ($plugins === null) {
        if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $plugins = function_exists('get_plugins') ? get_plugins() : [];
        if (!is_array($plugins)) $plugins = [];
      }
      foreach ($plugins as $plugin_file => $data) {
        $plugin_file = wp_normalize_path((string) $plugin_file);
        if ($plugin_dir !== '' && strpos($plugin_file, $plugin_dir . '/') !== 0) continue;
        $plugin_name = isset($data['Name']) ? wp_strip_all_tags((string) $data['Name']) : '';
        $plugin_author = isset($data['AuthorName']) && $data['AuthorName'] !== ''
          ? wp_strip_all_tags((string) $data['AuthorName'])
          : (isset($data['Author']) ? wp_strip_all_tags((string) $data['Author']) : '');
        break;
      }
    }

    // Stable fallbacks for common Woo ecosystem providers whose email IDs are
    // frequently mistaken for WooCommerce core emails.
    if ($plugin_dir === '' && strpos($class, 'WC_Stripe_Email_') === 0) $plugin_dir = 'woocommerce-gateway-stripe';
    if ($plugin_dir === '' && strpos($class, 'AFWC_') === 0) $plugin_dir = 'affiliate-for-woocommerce';

    $known = [
      'woocommerce-gateway-stripe' => [
        'name' => __('WooCommerce Stripe Gateway', 'yablonsky-multilingual'),
        'kind' => 'woo-extension',
        'kind_label' => __('Woo extension', 'yablonsky-multilingual'),
      ],
      'woocommerce-subscriptions' => [
        'name' => __('WooCommerce Subscriptions', 'yablonsky-multilingual'),
        'kind' => 'woo-extension',
        'kind_label' => __('Woo extension', 'yablonsky-multilingual'),
      ],
      'woocommerce-pre-orders' => [
        'name' => __('WooCommerce Pre-Orders', 'yablonsky-multilingual'),
        'kind' => 'woo-extension',
        'kind_label' => __('Woo extension', 'yablonsky-multilingual'),
      ],
      'affiliate-for-woocommerce' => [
        'name' => __('Affiliate for WooCommerce', 'yablonsky-multilingual'),
        'kind' => 'marketplace',
        'kind_label' => __('Woo Marketplace', 'yablonsky-multilingual'),
      ],
    ];

    if ($plugin_dir !== '' && isset($known[$plugin_dir])) {
      $name = $plugin_name !== '' ? $plugin_name : (string) $known[$plugin_dir]['name'];
      if ($plugin_author !== '' && stripos($name, $plugin_author) === false) $name .= ' · ' . $plugin_author;
      return [
        'key' => $plugin_dir,
        'label' => $name,
        'kind' => (string) $known[$plugin_dir]['kind'],
        'kind_label' => (string) $known[$plugin_dir]['kind_label'],
        'search' => strtolower($name . ' ' . $plugin_dir . ' ' . $class),
      ];
    }

    if ($plugin_dir !== '' || $plugin_name !== '') {
      $name = $plugin_name !== '' ? $plugin_name : $plugin_dir;
      if ($plugin_author !== '' && stripos($name, $plugin_author) === false) $name .= ' · ' . $plugin_author;
      return [
        'key' => $plugin_dir !== '' ? $plugin_dir : sanitize_key($name),
        'label' => $name,
        'kind' => 'extension',
        'kind_label' => __('Extension', 'yablonsky-multilingual'),
        'search' => strtolower($name . ' ' . $plugin_dir . ' ' . $class),
      ];
    }

    return [
      'key' => 'registered-extension',
      'label' => __('Other registered email provider', 'yablonsky-multilingual'),
      'kind' => 'extension',
      'kind_label' => __('Extension', 'yablonsky-multilingual'),
      'search' => strtolower($class),
    ];
  }

  /**
   * @param array<string,array{source:array,emails:array}> $groups
   * @return array<string,array{source:array,emails:array}>
   */
  private static function sort_email_groups(array $groups): array {
    $rank = ['core' => 10, 'marketplace' => 20, 'woo-extension' => 30, 'extension' => 40];
    uasort($groups, static function (array $a, array $b) use ($rank): int {
      $a_kind = (string) ($a['source']['kind'] ?? 'extension');
      $b_kind = (string) ($b['source']['kind'] ?? 'extension');
      $a_rank = $rank[$a_kind] ?? 99;
      $b_rank = $rank[$b_kind] ?? 99;
      if ($a_rank !== $b_rank) return $a_rank <=> $b_rank;
      return strcasecmp((string) ($a['source']['label'] ?? ''), (string) ($b['source']['label'] ?? ''));
    });
    return $groups;
  }

  public static function render_email_page(): void {
    if (!current_user_can('manage_woocommerce') || !function_exists('WC')) return;
    $active = Languages::active_codes();
    $locale_raw = filter_input(INPUT_GET, 'locale', FILTER_UNSAFE_RAW);
    $locale = is_string($locale_raw) ? sanitize_text_field($locale_raw) : '';
    if (!in_array($locale, $active, true)) $locale = Languages::default_code();
    $all = get_option(self::EMAIL_OPT, []);
    if (!is_array($all)) $all = [];
    $mailer = WC()->mailer();
    $emails = $mailer ? $mailer->get_emails() : [];
    if (!is_array($emails)) $emails = [];

    $groups = [];
    $email_count = 0;
    $override_count = 0;
    foreach ($emails as $email) {
      if (!is_object($email) || empty($email->id)) continue;
      $source = self::email_source($email);
      $key = (string) $source['key'];
      if (!isset($groups[$key])) $groups[$key] = ['source' => $source, 'emails' => []];
      $groups[$key]['emails'][] = $email;
      $email_count++;

      $id = (string) $email->id;
      $values = isset($all[$id][$locale]) && is_array($all[$id][$locale]) ? $all[$id][$locale] : [];
      foreach (['subject', 'heading', 'additional_content'] as $field) {
        if (isset($values[$field]) && is_string($values[$field]) && trim($values[$field]) !== '') $override_count++;
      }
    }
    $groups = self::sort_email_groups($groups);

    echo '<div class="wrap ypm-admin ypm-admin--emails">';
    \Yablonsky\Multilingual\Admin::render_shell_header(
      __('Commerce Emails', 'yablonsky-multilingual'),
      __('Localize WooCommerce core and registered extension emails by the language stored on each order. Every template is labelled by its real source.', 'yablonsky-multilingual'),
      'neutral',
      __('Order scoped', 'yablonsky-multilingual')
    );

    if (filter_input(INPUT_GET, 'saved', FILTER_VALIDATE_BOOLEAN) === true) {
      echo '<div class="ypm-callout ypm-callout--pass"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><div><strong>' . esc_html__('Email overrides saved.', 'yablonsky-multilingual') . '</strong></div></div>';
    }

    echo '<div class="ypm-metrics ypm-metrics--4">';
    \Yablonsky\Multilingual\Admin::render_metric(__('Locale', 'yablonsky-multilingual'), Languages::display_code($locale), Languages::display_name($locale), 'neutral');
    \Yablonsky\Multilingual\Admin::render_metric(__('Registered emails', 'yablonsky-multilingual'), (string) $email_count, __('Core + extensions', 'yablonsky-multilingual'), 'neutral');
    \Yablonsky\Multilingual\Admin::render_metric(__('Sources', 'yablonsky-multilingual'), (string) count($groups), __('Ownership detected', 'yablonsky-multilingual'), 'neutral');
    \Yablonsky\Multilingual\Admin::render_metric(__('Overrides', 'yablonsky-multilingual'), (string) $override_count, __('Saved override fields', 'yablonsky-multilingual'), 'neutral');
    echo '</div>';

    echo '<div class="ypm-callout"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><div><strong>' . esc_html__('WooCommerce mailer uses the full ecosystem registry across core and extension emails.', 'yablonsky-multilingual') . '</strong><p>' . esc_html__('Extensions such as affiliate, subscription, pre-order and payment plugins can register their own WC_Email classes. YMulti keeps them available but shows the owning source instead of presenting them as WooCommerce Core.', 'yablonsky-multilingual') . '</p></div></div>';

    echo '<section class="ypm-card ypm-card--standalone">';
    echo '<div class="ypm-card__head ypm-card__head--toolbar"><div><span class="ypm-eyebrow">' . esc_html__('Locale overrides', 'yablonsky-multilingual') . '</span><h2>' . esc_html__('Registered commerce emails', 'yablonsky-multilingual') . '</h2></div><div class="ypm-toolbar-group">';
    echo '<label class="screen-reader-text" for="ypm-email-source-filter">' . esc_html__('Email source', 'yablonsky-multilingual') . '</label><select id="ypm-email-source-filter"><option value="all">' . esc_html__('All sources', 'yablonsky-multilingual') . '</option>'; foreach ($groups as $group_key => $filter_group) echo '<option value="' . esc_attr((string) $group_key) . '">' . esc_html((string) ($filter_group['source']['label'] ?? $group_key)) . '</option>'; echo '</select><label class="ypm-check-control ypm-check-control--compact ypm-email-edited-filter"><input type="checkbox" id="ypm-email-edited-only"><span>' . esc_html__('Overrides only', 'yablonsky-multilingual') . '</span></label><label class="ypm-table-search"><span class="screen-reader-text">' . esc_html__('Search registered commerce emails', 'yablonsky-multilingual') . '</span><input id="ypm-email-search" type="search" placeholder="' . esc_attr__('Search email, ID or source…', 'yablonsky-multilingual') . '"></label>';
    echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '"><input type="hidden" name="page" value="ypm-woo-emails"><select name="locale" onchange="this.form.submit()">';
    foreach ($active as $code) echo '<option value="' . esc_attr($code) . '"' . selected($locale, $code, false) . '>' . esc_html(Languages::display_name($code) . ' (' . Languages::display_code($code) . ')') . '</option>';
    echo '</select></form></div></div>';
    echo '<p class="ypm-card__copy">' . esc_html__('The source plugin setting remains active until you save an override. Overrides apply when the email carries an order with this YMulti locale.', 'yablonsky-multilingual') . '</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('ypm_save_woo_email_overrides', 'ypm_woo_email_nonce');
    echo '<input type="hidden" name="action" value="ypm_save_woo_email_overrides"><input type="hidden" name="locale" value="' . esc_attr($locale) . '">';

    foreach ($groups as $group_key => $group) {
      $source = $group['source'];
      $source_label = (string) ($source['label'] ?? '');
      $source_kind = (string) ($source['kind'] ?? 'extension');
      $source_kind_label = (string) ($source['kind_label'] ?? __('Extension', 'yablonsky-multilingual'));
      echo '<section class="ypm-email-source" data-ypm-email-source-group data-ypm-email-source="' . esc_attr((string) $group_key) . '">';
      echo '<div class="ypm-email-source__head"><div><span class="ypm-provider-badge ypm-provider-badge--' . esc_attr($source_kind) . '">' . esc_html($source_kind_label) . '</span><h3>' . esc_html($source_label) . '</h3></div><span class="ypm-email-source__count">' . esc_html(sprintf(
        /* translators: %d: number of registered email templates from this source. */
        _n('%d email', '%d emails', count($group['emails']), 'yablonsky-multilingual'),
        count($group['emails'])
      )) . '</span></div>';

      foreach ($group['emails'] as $email) {
        if (!is_object($email) || empty($email->id)) continue;
        $id = (string) $email->id;
        $title = method_exists($email, 'get_title') ? (string) $email->get_title() : $id;
        $values = isset($all[$id][$locale]) && is_array($all[$id][$locale]) ? $all[$id][$locale] : [];
        $has_override = false;
        foreach (['subject', 'heading', 'additional_content'] as $override_field) if (isset($values[$override_field]) && is_string($values[$override_field]) && trim($values[$override_field]) !== '') $has_override = true;
        $search = strtolower($title . ' ' . $id . ' ' . (string) ($source['search'] ?? $source_label));
        echo '<details class="ypm-disclosure ypm-disclosure--wide ypm-email-item" data-ypm-email-search="' . esc_attr($search) . '" data-ypm-email-edited="' . ($has_override ? '1' : '0') . '"><summary><span><strong>' . esc_html($title) . '</strong><small><code>' . esc_html($id) . '</code><span class="ypm-email-owner">' . esc_html($source_label) . '</span></small></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary><div class="ypm-disclosure__body">';
        foreach (['subject' => __('Subject', 'yablonsky-multilingual'), 'heading' => __('Heading', 'yablonsky-multilingual'), 'additional_content' => __('Additional content', 'yablonsky-multilingual')] as $field => $label) {
          $value = isset($values[$field]) ? (string) $values[$field] : '';
          echo '<p><label><strong>' . esc_html($label) . '</strong><br><textarea class="large-text" rows="2" name="emails[' . esc_attr($id) . '][' . esc_attr($field) . ']">' . esc_textarea($value) . '</textarea></label></p>';
        }
        echo '</div></details>';
      }
      echo '</section>';
    }

    \Yablonsky\Multilingual\Admin::render_client_pager('ypm-email-pager', 20, [20, 40, 80]);
    echo '<p id="ypm-email-search-empty" class="ypm-empty-state" hidden>' . esc_html__('0 registered emails match this search.', 'yablonsky-multilingual') . '</p>';
    echo '<p class="submit"><button class="button button-primary">' . esc_html__('Save email overrides', 'yablonsky-multilingual') . '</button></p></form></section></div>';
  }

  public static function handle_save_email_overrides(): void {
    if (!current_user_can('manage_woocommerce')) wp_die(esc_html__('Forbidden', 'yablonsky-multilingual'));
    check_admin_referer('ypm_save_woo_email_overrides', 'ypm_woo_email_nonce');
    $locale = isset($_POST['locale']) ? sanitize_text_field(wp_unslash($_POST['locale'])) : '';
    if (!Languages::is_active($locale)) wp_die(esc_html__('Invalid locale.', 'yablonsky-multilingual'));
    $raw = filter_input(INPUT_POST, 'emails', FILTER_UNSAFE_RAW, FILTER_REQUIRE_ARRAY);
    if (!is_array($raw)) $raw = [];
    $all = get_option(self::EMAIL_OPT, []);
    if (!is_array($all)) $all = [];
    foreach ($raw as $email_id => $fields) {
      $email_id = sanitize_key((string) $email_id);
      if ($email_id === '' || !is_array($fields)) continue;
      foreach (['subject', 'heading', 'additional_content'] as $key) {
        $all[$email_id][$locale][$key] = isset($fields[$key]) && is_scalar($fields[$key]) ? sanitize_textarea_field((string) $fields[$key]) : '';
      }
    }
    update_option(self::EMAIL_OPT, $all, false);
    wp_safe_redirect(admin_url('admin.php?page=ypm-woo-emails&locale=' . rawurlencode($locale) . '&saved=1'));
    exit;
  }

  /** @return string[] */
  public static function email_notification_actions(): array {
    return [
      'woocommerce_order_status_pending_to_processing_notification',
      'woocommerce_order_status_pending_to_completed_notification',
      'woocommerce_order_status_pending_to_on-hold_notification',
      'woocommerce_order_status_failed_to_processing_notification',
      'woocommerce_order_status_failed_to_completed_notification',
      'woocommerce_order_status_failed_to_on-hold_notification',
      'woocommerce_order_status_cancelled_to_processing_notification',
      'woocommerce_order_status_cancelled_to_completed_notification',
      'woocommerce_order_status_cancelled_to_on-hold_notification',
      'woocommerce_order_status_on-hold_to_processing_notification',
      'woocommerce_order_status_processing_to_completed_notification',
      'woocommerce_order_status_pending_to_failed_notification',
      'woocommerce_order_status_pending_to_cancelled_notification',
      'woocommerce_order_status_on-hold_to_cancelled_notification',
      'woocommerce_order_status_processing_to_cancelled_notification',
      'woocommerce_order_status_processing_to_refunded_notification',
      'woocommerce_order_status_completed_to_refunded_notification',
      'woocommerce_order_refunded_notification',
    ];
  }
}
