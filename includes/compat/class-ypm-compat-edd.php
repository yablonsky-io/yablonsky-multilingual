<?php
namespace Yablonsky\Multilingual\Compat;

if (!defined('ABSPATH')) exit;

final class EDD {
  public static function init(): void {
    // No special hooks required for v0.x.
    // Downloads are public post types; our core language filtering applies.
  }
}
