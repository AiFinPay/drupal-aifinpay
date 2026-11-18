<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Connect;

use Drupal\Core\Site\Settings;

/** The reader key and the Turnstile secret: settings.php first, then Drupal State; never config, logs or output. */
final class ReaderKeys {

  public const STATE_KEY = 'aifinpay.reader_keys';

  /** @return array{reader_key: ?string, turnstile_secret: ?string} */
  public static function get(): array {
    $stored = \Drupal::hasContainer() ? \Drupal::state()->get(self::STATE_KEY) : NULL;
    return self::merge(Settings::get('aifinpay_site', []), is_array($stored) ? $stored : []);
  }

  /** @return array{reader_key: ?string, turnstile_secret: ?string} */
  public static function merge(array $site, array $stored): array {
    $reader = is_string($site['reader_key'] ?? NULL) && $site['reader_key'] !== '' ? $site['reader_key']
      : (is_string($stored['reader_key'] ?? NULL) ? base64_decode($stored['reader_key'], TRUE) : FALSE);
    $secret = is_string($site['turnstile_secret'] ?? NULL) && $site['turnstile_secret'] !== '' ? $site['turnstile_secret']
      : ($stored['turnstile_secret'] ?? NULL);
    return [
      'reader_key' => is_string($reader) && strlen($reader) >= 32 ? $reader : NULL,
      'turnstile_secret' => is_string($secret) && $secret !== '' && strlen($secret) <= 512 ? $secret : NULL,
    ];
  }

  /** Keeps a stored reader key; makes one of 32 random bytes when there is none. */
  public static function ensureReaderKey(): void {
    $stored = \Drupal::state()->get(self::STATE_KEY);
    $stored = is_array($stored) ? $stored : [];
    if (!is_string($stored['reader_key'] ?? NULL) || strlen((string) base64_decode($stored['reader_key'], TRUE)) < 32) {
      $stored['reader_key'] = base64_encode(random_bytes(32));
      \Drupal::state()->set(self::STATE_KEY, $stored);
    }
  }

  public static function storeTurnstileSecret(string $secret): void {
    $stored = \Drupal::state()->get(self::STATE_KEY);
    $stored = is_array($stored) ? $stored : [];
    $stored['turnstile_secret'] = $secret;
    \Drupal::state()->set(self::STATE_KEY, $stored);
  }

}
