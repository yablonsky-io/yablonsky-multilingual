<?php
namespace Yablonsky\Multilingual;

if (!defined('ABSPATH')) exit;

final class LegacyApi {
  public static function register(): void {
    $aliases = [
      'Languages', 'SafeMode', 'Updater', 'Urls', 'LocaleRuntime', 'RequestContext',
      'LanguageProvider', 'SEOOwnership', 'AITranslation', 'Router', 'Translations',
      'TermTranslations', 'Hreflang', 'Switcher', 'Widget_Language_Switcher',
      'CommerceStrings', 'Status', 'Admin', 'AdminContent', 'AdminTerms', 'Plugin',
    ];

    foreach ($aliases as $class) {
      $canonical = __NAMESPACE__ . '\\' . $class;
      $legacy = 'YPM\\' . $class;
      if (class_exists($canonical, false) && !class_exists($legacy, false)) {
        class_alias($canonical, $legacy);
      }
    }

    $compat = ['Yoast', 'Woo', 'EDD', 'WidgetsBatch'];
    foreach ($compat as $class) {
      $canonical = __NAMESPACE__ . '\\Compat\\' . $class;
      $legacy = 'YPM\\Compat\\' . $class;
      if (class_exists($canonical, false) && !class_exists($legacy, false)) {
        class_alias($canonical, $legacy);
      }
    }
  }
}
