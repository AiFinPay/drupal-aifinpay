<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Policy;

/** Explicit resource rules. Traffic identity is supplied by trusted Edge context. */
final class Paths {

  public static function validPattern(string $path): bool {
    return (bool) preg_match('~^/[a-zA-Z0-9_./-]+(?:/\*)?$~D', $path)
      && !str_contains($path, '..') && !str_contains($path, '//');
  }

  /** Drupal's router answers /PAID and /paid/ as /paid, and a /x/* section includes /x itself. */
  public static function matches(string $path, array $patterns): ?string {
    $path = rtrim(strtolower($path), '/');
    foreach ($patterns as $pattern) {
      if (!is_string($pattern) || !self::validPattern($pattern)) {
        continue;
      }
      $rule = strtolower($pattern);
      if ($path === rtrim($rule, '/')
        || (str_ends_with($rule, '/*') && ($path === substr($rule, 0, -2) || str_starts_with($path, substr($rule, 0, -1))))) {
        return $pattern;
      }
    }
    return NULL;
  }

  public static function protected(string $path, array $config): ?string {
    if (preg_match('~^/(admin|user|batch|update.php)(/|$)~i', $path)) {
      return NULL;
    }
    if (self::matches($path, $config['excluded_paths'] ?? []) || self::matches($path, $config['allowed_paths'] ?? [])) {
      return NULL;
    }
    return self::matches($path, $config['protected_paths'] ?? []);
  }

}
