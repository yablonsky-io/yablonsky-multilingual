<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

final class Plugin {
  private static ?self $instance = null;
  private bool $autopilot_runtime = false;
  private bool $custom_fields_runtime = false;

  public static function instance(): self {
    if (self::$instance === null) self::$instance = new self();
    return self::$instance;
  }

  public static function activate(): void {
    require_once YPM_PATH . 'includes/class-ypm-languages.php';
    require_once YPM_PATH . 'includes/class-ymulti-language-ui.php';
    require_once YPM_PATH . 'includes/class-ypm-safemode.php';
    require_once YPM_PATH . 'includes/class-ymulti-runtime-authority.php';
    require_once YPM_PATH . 'includes/class-ypm-updater.php';
    require_once YPM_PATH . 'includes/class-ypm-urls.php';
    require_once YPM_PATH . 'includes/class-ypm-locale-runtime.php';
    require_once YPM_PATH . 'includes/class-ypm-request-context.php';
    require_once YPM_PATH . 'includes/class-ypm-language-provider.php';
    require_once YPM_PATH . 'includes/class-ypm-seo-ownership.php';
    require_once YPM_PATH . 'includes/class-ypm-multilingual-seo-provider.php';
    require_once YPM_PATH . 'includes/class-ymulti-semantic-invalidation.php';
    require_once YPM_PATH . 'includes/class-ypm-ai-content-segments.php';
    require_once YPM_PATH . 'includes/class-ypm-translation-glossary.php';
    require_once YPM_PATH . 'includes/class-ymulti-translation-memory.php';
    require_once YPM_PATH . 'includes/class-ymulti-autopilot.php';
    require_once YPM_PATH . 'includes/class-ypm-ai-translation.php';
    require_once YPM_PATH . 'includes/class-ypm-ai-layout-qa.php';
    require_once YPM_PATH . 'includes/class-ypm-media-localization.php';
    require_once YPM_PATH . 'includes/class-ypm-ai-jobs.php';
    require_once YPM_PATH . 'includes/class-ypm-ai-term-jobs.php';
    require_once YPM_PATH . 'includes/class-ypm-ai-site-plan.php';
    require_once YPM_PATH . 'includes/class-ypm-router.php';
    require_once YPM_PATH . 'includes/class-ypm-translations.php';
    require_once YPM_PATH . 'includes/class-ymulti-effective-relations.php';
    require_once YPM_PATH . 'includes/class-ymulti-entity-contract.php';
    require_once YPM_PATH . 'includes/class-ypm-term-translations.php';
    require_once YPM_PATH . 'includes/class-ypm-hreflang.php';
    require_once YPM_PATH . 'includes/class-ypm-switcher.php';
    require_once YPM_PATH . 'includes/class-ypm-commerce-strings.php';
    require_once YPM_PATH . 'includes/class-ypm-string-translations.php';
    require_once YPM_PATH . 'includes/class-ymulti-string-discovery.php';
    require_once YPM_PATH . 'includes/class-ymulti-string-interchange.php';
    require_once YPM_PATH . 'includes/class-ymulti-translation-jobs.php';
    require_once YPM_PATH . 'includes/class-ymulti-drafts-workspace.php';
    require_once YPM_PATH . 'includes/class-ymulti-xliff-exchange.php';
    require_once YPM_PATH . 'includes/class-ymulti-menu-translations.php';
    require_once YPM_PATH . 'includes/class-ymulti-menu-ai-jobs.php';

    Languages::ensure_options();
    SafeMode::ensure_option();
    Updater::ensure_option();
    StringTranslations::install_table();
    StringDiscovery::install_table();
    TranslationJobs::install_table();
    TranslationJobs::install_roles();
    TranslationMemory::install_table();
    Autopilot::install_table();

    // On fresh install, initialize schema version to current.
    if (Updater::current_version() === 0) {
      // A fresh installation may record v8 only after the lookup index exists.
      update_option(Updater::OPT_DB_VERSION, TranslationJobs::target_lookup_index_exists() ? (int) YPM_DB_VERSION : 7, false);
    }

    // Only flush rewrites in full mode.
    if (RuntimeAuthority::ymulti_owns_public_runtime()) {
      Router::maybe_flush_rewrites(true);
    }
  }

  public static function deactivate(): void {
    // Remove YMulti-owned background continuations before disabling runtime.
    wp_clear_scheduled_hook('yablonsky_multilingual_variation_sync');
    wp_clear_scheduled_hook('yablonsky_multilingual_migration_tick');
    wp_clear_scheduled_hook('yablonsky_multilingual_ai_translation_tick');
    wp_clear_scheduled_hook('yablonsky_multilingual_ai_site_plan_tick');
    wp_clear_scheduled_hook('yablonsky_multilingual_ai_term_job_tick');
    wp_clear_scheduled_hook('yablonsky_multilingual_ai_media_job_tick');
    wp_clear_scheduled_hook('yablonsky_multilingual_ai_menu_job_tick');
    wp_clear_scheduled_hook('yablonsky_multilingual_autopilot_tick');
    if (function_exists('as_unschedule_all_actions')) { as_unschedule_all_actions('yablonsky_multilingual_autopilot_tick', [], 'ymulti'); as_unschedule_all_actions('yablonsky_multilingual_ai_menu_job_tick', [], 'ymulti'); }

    // Deactivation is rare; flushing here cleans up prefixed rules.
    flush_rewrite_rules();
  }

  private function __construct() {
    $this->includes();
    $this->init();
  }

  private function includes(): void {
    $worker = is_admin() || wp_doing_cron();
    $woo = class_exists('WooCommerce') || function_exists('WC');
    $autopilot_settings = get_option('ymulti_autopilot_settings_v1', []);
    $custom_field_policies = get_option('ymulti_custom_field_policies_v1', []);
    $this->autopilot_runtime = $worker || (is_array($autopilot_settings) && !empty($autopilot_settings['enabled']));
    $this->custom_fields_runtime = $worker || (is_array($custom_field_policies) && !empty($custom_field_policies));

    // Frontend/runtime core. Keep this list intentionally small: ordinary
    // visitor requests should not parse admin workspaces, AI job UIs or export
    // tooling that cannot participate in the request.
    require_once YPM_PATH . 'includes/class-ypm-languages.php';
    require_once YPM_PATH . 'includes/class-ypm-safemode.php';
    require_once YPM_PATH . 'includes/class-ymulti-runtime-authority.php';
    require_once YPM_PATH . 'includes/class-ypm-urls.php';
    require_once YPM_PATH . 'includes/class-ypm-locale-runtime.php';
    require_once YPM_PATH . 'includes/class-ypm-request-context.php';
    require_once YPM_PATH . 'includes/class-ypm-language-provider.php';
    require_once YPM_PATH . 'includes/class-ypm-seo-ownership.php';
    require_once YPM_PATH . 'includes/class-ypm-multilingual-seo-provider.php';
    require_once YPM_PATH . 'includes/class-ymulti-semantic-invalidation.php';
    require_once YPM_PATH . 'includes/class-ypm-media-localization.php';
    require_once YPM_PATH . 'includes/class-ypm-router.php';
    require_once YPM_PATH . 'includes/class-ypm-translations.php';
    require_once YPM_PATH . 'includes/class-ymulti-effective-relations.php';
    require_once YPM_PATH . 'includes/class-ymulti-entity-contract.php';
    require_once YPM_PATH . 'includes/class-ypm-term-translations.php';
    require_once YPM_PATH . 'includes/class-ypm-hreflang.php';
    require_once YPM_PATH . 'includes/class-ypm-switcher.php';
    require_once YPM_PATH . 'includes/class-ypm-string-translations.php';
    require_once YPM_PATH . 'includes/class-ypm-navigation.php';
    require_once YPM_PATH . 'includes/class-ymulti-menu-translations.php';
    require_once YPM_PATH . 'includes/class-ymulti-editor-compatibility.php';
    require_once YPM_PATH . 'includes/class-ymulti-localized-bases.php';
    require_once YPM_PATH . 'includes/class-ymulti-routing-modes.php';
    require_once YPM_PATH . 'includes/class-ymulti-translation-freshness.php';


    if ($this->custom_fields_runtime) require_once YPM_PATH . 'includes/class-ymulti-custom-fields.php';
    if ($this->autopilot_runtime) require_once YPM_PATH . 'includes/class-ymulti-autopilot.php';

    if (!$worker) {
      $layout_qa_requested = filter_input(INPUT_GET, 'ypm_layout_qa', FILTER_VALIDATE_BOOLEAN) === true;
      if ($layout_qa_requested) {
        require_once YPM_PATH . 'includes/class-ypm-ai-translation.php';
        require_once YPM_PATH . 'includes/class-ypm-ai-layout-qa.php';
      }
    }

    if ($woo) {
      require_once YPM_PATH . 'includes/class-ypm-commerce-strings.php';
      require_once YPM_PATH . 'includes/class-ymulti-multicurrency.php';
      require_once YPM_PATH . 'includes/class-ymulti-fx-rates.php';
      require_once YPM_PATH . 'includes/compat/class-ypm-compat-woo.php';
      require_once YPM_PATH . 'includes/compat/class-ypm-woo-commerce.php';
      require_once YPM_PATH . 'includes/compat/class-ypm-woo-order-language.php';
    }

    // Admin/worker surface. admin-post.php and admin-ajax.php both satisfy
    // is_admin(); cron receives the background executors without loading the
    // visual admin shell.
    if ($worker) {
      require_once YPM_PATH . 'includes/class-ypm-updater.php';
      require_once YPM_PATH . 'includes/class-ymulti-runtime-contract.php';
      require_once YPM_PATH . 'includes/class-ypm-ai-translation.php';
      require_once YPM_PATH . 'includes/class-ypm-ai-layout-qa.php';
      require_once YPM_PATH . 'includes/class-ymulti-translation-jobs.php';
      require_once YPM_PATH . 'includes/class-ymulti-drafts-workspace.php';
      require_once YPM_PATH . 'includes/class-ypm-ai-content-segments.php';
      require_once YPM_PATH . 'includes/class-ypm-translation-glossary.php';
      require_once YPM_PATH . 'includes/class-ymulti-translation-memory.php';
      require_once YPM_PATH . 'includes/class-ypm-ai-jobs.php';
      require_once YPM_PATH . 'includes/class-ypm-ai-term-jobs.php';
      require_once YPM_PATH . 'includes/class-ypm-ai-site-plan.php';
      require_once YPM_PATH . 'includes/class-ymulti-media-ai-jobs.php';
      require_once YPM_PATH . 'includes/class-ymulti-menu-ai-jobs.php';
      require_once YPM_PATH . 'includes/class-ymulti-source-language-bridge.php';
      require_once YPM_PATH . 'includes/class-ypm-migration-tools.php';
    }

    if (is_admin()) {
      require_once YPM_PATH . 'includes/class-ymulti-site-coverage.php';
      require_once YPM_PATH . 'includes/class-ymulti-language-ui.php';
      require_once YPM_PATH . 'includes/class-ypm-ai-admin.php';
      require_once YPM_PATH . 'includes/class-ypm-translation-workspace.php';
      require_once YPM_PATH . 'includes/class-ymulti-media-workspace.php';
      require_once YPM_PATH . 'includes/class-ymulti-string-discovery.php';
      require_once YPM_PATH . 'includes/class-ymulti-string-interchange.php';
      require_once YPM_PATH . 'includes/class-ymulti-xliff-exchange.php';
      require_once YPM_PATH . 'includes/class-ypm-navigation-audit.php';
      require_once YPM_PATH . 'includes/class-ypm-status.php';
      require_once YPM_PATH . 'includes/class-ymulti-visual-review.php';
      require_once YPM_PATH . 'includes/class-ymulti-launch-center.php';
      require_once YPM_PATH . 'includes/class-ypm-integrity-audit.php';
      require_once YPM_PATH . 'includes/class-ymulti-admin-language-context.php';
      require_once YPM_PATH . 'includes/class-ypm-admin.php';
      require_once YPM_PATH . 'includes/class-ypm-admin-content.php';
      require_once YPM_PATH . 'includes/class-ypm-admin-terms.php';
      require_once YPM_PATH . 'includes/compat/class-ypm-compat-widgets-batch.php';
    }

    require_once YPM_PATH . 'includes/compat/class-ypm-compat-yoast.php';
    require_once YPM_PATH . 'includes/compat/class-ypm-compat-edd.php';
    require_once YPM_PATH . 'includes/class-yablonsky-multilingual-legacy-api.php';
    LegacyApi::register();
  }

  private function init(): void {
    $worker = is_admin() || wp_doing_cron();
    $woo = class_exists('WooCommerce') || function_exists('WC');
    $public_runtime = RuntimeAuthority::ymulti_owns_public_runtime();

    // Activation/upgrade initializes Safe Mode. Runtime status checks are
    // read-only, including the first public hit after a partial install.
    if ($worker) Updater::init();

    Languages::init();
    Urls::init();
    if ($public_runtime) LocaleRuntime::init();
    RequestContext::init();
    LanguageProvider::init();
    MultilingualSEOProvider::init();
    SemanticInvalidation::init();
    Translations::init();
    TermTranslations::init();
    if ($public_runtime || $worker) Switcher::init();
    if ($public_runtime) Navigation::init();
    if ($public_runtime || $worker) MenuTranslations::init();
    EditorCompatibility::init();
    if ($public_runtime || $worker) LocalizedBases::init();
    if ($public_runtime || $worker) RoutingModes::init();
    if ($worker) RuntimeContract::init();
    TranslationFreshness::init();
    if ($this->custom_fields_runtime) CustomFields::init();
    if ($this->autopilot_runtime) Autopilot::init();
    if ($public_runtime || $worker) MediaLocalization::init();
    if ($public_runtime || $worker) StringTranslations::init();

    if (!$worker && class_exists(AILayoutQA::class, false)) AILayoutQA::init();

    if ($public_runtime) {
      Router::init();
      Hreflang::init();
    }

    if ($woo) {
      if ($public_runtime) CommerceStrings::init();
      if ($public_runtime || $worker) MultiCurrency::init();
      FXRates::init();
      if ($public_runtime || $worker) Compat\Woo::init();
      if ($public_runtime || $worker) Compat\WooCommerceRuntime::init();
      if ($public_runtime || $worker) Compat\WooOrderLanguage::init();
    }

    if ($worker) {
      TranslationJobs::init();
      DraftsWorkspace::init();
      AILayoutQA::init();
      AIJobs::init();
      AITermJobs::init();
      AISitePlan::init();
      MediaAIJobs::init();
      MenuAIJobs::init();
      MigrationTools::init();
      if (is_admin()) SourceLanguageBridge::init();
    }

    if (is_admin()) {
      AdminLanguageContext::init();
      Admin::init();
      AdminContent::init();
      AdminTerms::init();
      TranslationWorkspace::init();
      AIAdmin::init();
      TranslationGlossary::init();
      MediaWorkspace::init();
      StringDiscovery::init();
      StringInterchange::init();
      XLIFFExchange::init();
      VisualReview::init();
      LaunchCenter::init();
      IntegrityAudit::init();
      Compat\WidgetsBatch::init();
    }


    if (!$worker) {
      add_action('rest_api_init', static function (): void {
        // REST requests bypass the admin/cron bootstrap. Job-scoped meta-cap
        // checks reference Updater::OPT_DB_VERSION; load that dependency before
        // registering TranslationJobs to avoid a fatal on assigned draft edits.
        if (!class_exists(Updater::class, false)) require_once YPM_PATH . 'includes/class-ypm-updater.php';
        if (!class_exists(RestAPI::class, false)) require_once YPM_PATH . 'includes/class-ymulti-rest-api.php';
        if (!class_exists(TranslationJobs::class, false)) require_once YPM_PATH . 'includes/class-ymulti-translation-jobs.php';
        if (!class_exists(DraftsWorkspace::class, false)) require_once YPM_PATH . 'includes/class-ymulti-drafts-workspace.php';
        RestAPI::register_routes();
        TranslationJobs::init();
        DraftsWorkspace::init();
      }, 1);
    } else {
      require_once YPM_PATH . 'includes/class-ymulti-rest-api.php';
      RestAPI::init();
    }

    Compat\Yoast::init();
    Compat\EDD::init();
  }

}
