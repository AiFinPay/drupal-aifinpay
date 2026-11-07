<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Policy;

/** Explicit resource rules. Traffic identity is supplied by trusted Edge context. */
final class Paths {

  public static function validPattern(string $path): bool {
    return (bool) preg_match('~^/[a-zA-Z0-9_./-]+(?:/\*)?$~D', $path)
      && !str_contains($path, '..') && !str_contains($path, '//');
  }

  /**
   * The page a spelling resolves to, as inspectPath in @aifinpay/gate reads it.
   * Origins disagree on dot segments, so `ambiguous` marks those, a raw `#`, and a spelling
   * still decoding after the last round. Anything else reads the same when read again.
   *
   * @return array{path: string, ambiguous: bool}
   */
  public static function inspect(string $raw): array {
    $target = explode('?', $raw, 2)[0];
    $path = explode('#', $target, 2)[0] ?: '/';
    for ($round = 0; $round < 3; $round++) {
      $next = self::decodeRound($path);
      if ($next === $path) {
        break;
      }
      $path = $next;
    }
    $ambiguous = str_contains($target, '#') || preg_match('/[\x00-\x1f\x7f]/', $path) === 1 || self::decodeRound($path) !== $path;
    $out = [];
    foreach (explode('/', $path) as $piece) {
      $segment = explode(';', $piece, 2)[0];
      // Dropped with the parameter, an escaped mark would hide where a proxy that decodes ends the path.
      if (preg_match('/%(?:3f|23)/i', substr($piece, strlen($segment))) === 1) {
        $ambiguous = TRUE;
      }
      if ($segment === '') {
        continue;
      }
      if ($segment === '.' || $segment === '..') {
        $ambiguous = TRUE;
        if ($segment === '..') {
          array_pop($out);
        }
        continue;
      }
      $out[] = $segment;
    }
    $canonical = preg_replace_callback('/[\x80-\xff]/', static fn(array $m): string => sprintf('%%%02x', ord($m[0])), '/' . implode('/', $out));
    return ['path' => strtolower((string) $canonical), 'ambiguous' => $ambiguous];
  }

  /** `?` and `#` stay escaped: decoded, they would end the path the next time it is read. */
  private static function decodeRound(string $text): string {
    $decoded = preg_replace_callback('/%([0-9a-fA-F]{2})/', static function (array $m): string {
      $code = (int) hexdec($m[1]);
      return $code < 0x80 && $code !== 0x3f && $code !== 0x23 ? chr($code) : $m[0];
    }, $text);
    return str_replace('\\', '/', (string) $decoded);
  }

  /** A receipt names a resource as the merchant spelled it: read it as a path is read, keeping a wildcard. */
  public static function spelled(string $resource): string {
    if (!str_starts_with($resource, '/')) {
      return $resource;
    }
    $wild = str_ends_with($resource, '/*');
    $path = self::inspect($wild ? substr($resource, 0, -2) : $resource)['path'];
    return !$wild ? $path : ($path === '/' ? '/*' : $path . '/*');
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

  /** A proxy that decodes hands `/report%3Fx` on as `/report`, so a canonical path with an escaped mark may be either page. */
  public static function beforeMark(string $path): ?string {
    return preg_match('/%(?:3f|23)/i', $path, $found, PREG_OFFSET_CAPTURE) === 1 ? self::inspect(substr($path, 0, $found[0][1]))['path'] : NULL;
  }

  /** The page a request is bought as: the one before an escaped mark when only that one is under the rule. */
  public static function boughtAs(string $path, string $rule): string {
    $path = self::inspect($path)['path'];
    $before = self::matches($path, [$rule]) === NULL ? self::beforeMark($path) : NULL;
    return $before ?? $path;
  }

  public static function protected(string $path, array $config): ?string {
    $path = self::inspect($path)['path'];
    return self::rule($path, $config) ?? (($before = self::beforeMark($path)) === NULL ? NULL : self::rule($before, $config));
  }

  private static function rule(string $path, array $config): ?string {
    if (preg_match('~^/(admin|user|batch|update.php)(/|$)~i', $path)) {
      return NULL;
    }
    if (self::matches($path, $config['excluded_paths'] ?? []) || self::matches($path, $config['allowed_paths'] ?? [])) {
      return NULL;
    }
    return self::matches($path, $config['protected_paths'] ?? []);
  }

}
