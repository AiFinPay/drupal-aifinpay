<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Policy;

/** "Who reads for free": exempt_humans still picks named or everyone; free_readers proven overrides it, and without its keys everyone pays. */
final class FreeReaders {

  public const NAMED = 'named';
  public const PROVEN = 'proven';
  public const EVERYONE = 'everyone';

  public static function chosen(array $config): string {
    if (($config['free_readers'] ?? NULL) === self::PROVEN) {
      return self::PROVEN;
    }
    return empty($config['exempt_humans']) ? self::EVERYONE : self::NAMED;
  }

  /** @return string[] What `proven` still needs. */
  public static function missing(array $config, array $keys): array {
    $missing = [];
    if (!is_string($config['turnstile_sitekey'] ?? NULL) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $config['turnstile_sitekey'])) {
      $missing[] = 'turnstile_sitekey';
    }
    foreach (['turnstile_secret', 'reader_key'] as $key) {
      if (($keys[$key] ?? NULL) === NULL) {
        $missing[] = $key;
      }
    }
    return $missing;
  }

  public static function effective(array $config, array $keys): string {
    $chosen = self::chosen($config);
    return $chosen === self::PROVEN && self::missing($config, $keys) !== [] ? self::EVERYONE : $chosen;
  }

  /** @return string[] Field machine names held back on a held page. */
  public static function paidFields(array $config): array {
    $fields = $config['paid_fields'] ?? NULL;
    if (!is_array($fields)) {
      return ['body'];
    }
    return array_values(array_filter($fields, static fn(mixed $f): bool => is_string($f) && preg_match('/^[a-z0-9_]{1,32}$/D', $f) === 1));
  }

}
