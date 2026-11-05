<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Policy;

/** Explicit resource rules. Traffic identity is supplied by trusted Edge context. */
final class Paths {

  public static function validPattern(string $path): bool {
    return (bool) preg_match('~^/[a-zA-Z0-9_./-]+(?:/\*)?$~D', $path)
      && !str_contains($path, '..') && !str_contains($path, '//');
  }

  public static function matches(string $path, array $patterns): ?string {
    foreach ($patterns as $pattern) {
      if (!is_string($pattern) || !self::validPattern($pattern)) {
        continue;
      }
      if ($path === $pattern || (str_ends_with($pattern, '/*') &&
        str_starts_with($path, substr($pattern, 0, -1)))) {
        return $pattern;
      }
    }
    return NULL;
  }

  public static function protected(string $path, array $config): ?string {
    if (preg_match('~^/(admin|user|batch|update.php)(/|$)~', $path)) {
      return NULL;
    }
    if (self::matches($path, $config['excluded_paths'] ?? []) || self::matches($path, $config['allowed_paths'] ?? [])) {
      return NULL;
    }
    return self::matches($path, $config['protected_paths'] ?? []);
  }

}
