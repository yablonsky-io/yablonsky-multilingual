<?php
namespace Yablonsky\Multilingual\Compat;

if (!defined('ABSPATH')) exit;

final class Yoast {
  public static function init(): void {
    // SEO-native by design: each language is a separate post ID + URL.
    // No Yoast internals are modified.
  }
}
