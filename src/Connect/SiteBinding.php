<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Connect;

use Drupal\Core\Site\Settings;

/**
 * The server-side site binding: settings.php (environment variables) first,
 * then the connection stored by a redeemed connect code in Drupal State.
 *
 * State is per-site database, outside config export; the secret is never
 * written to config, logs or rendered output.
 */
final class SiteBinding {

  public const STATE_KEY = 'aifinpay.connection';

  /** The binding every reader of the merchant secret uses. */
  public static function get(): array {
    $connection = NULL;
    if (\Drupal::hasContainer()) {
      $stored = \Drupal::state()->get(self::STATE_KEY);
      $connection = is_array($stored) ? $stored : NULL;
    }
    return self::merge(Settings::get('aifinpay_site', []), $connection);
  }

  /**
   * An environment secret wins. The stored secret fills in only when the
   * environment has none, and only for the same merchant (or none named).
   */
  public static function merge(array $identity, ?array $connection): array {
    if ($connection === NULL || !self::validConnection($connection)) {
      return $identity;
    }
    $envSecret = $identity['secret'] ?? '';
    if (is_string($envSecret) && $envSecret !== '') {
      return $identity;
    }
    $envMerchant = (string) ($identity['merchant_id'] ?? '');
    if ($envMerchant !== '' && $envMerchant !== $connection['merchant_id']) {
      return $identity;
    }
    $identity['merchant_id'] = $connection['merchant_id'];
    $identity['secret'] = $connection['secret'];
    $identity['site_id'] = (string) ($identity['site_id'] ?? '') !== '' ? $identity['site_id'] : (string) ($connection['site_id'] ?? '');
    if (!isset($identity['meo_site_name']) && is_string($connection['meo_site_name'] ?? NULL) && $connection['meo_site_name'] !== '') {
      $identity['meo_site_name'] = $connection['meo_site_name'];
    }
    $identity['secret_reference'] ??= '';
    $identity['api_origin'] ??= 'https://api.aifinpay.io';
    $identity['gateway_origin'] ??= 'https://gateway.aifinpay.io';
    $identity['credential_source'] = 'connect_code';
    return $identity;
  }

  public static function validConnection(array $c): bool {
    return is_string($c['merchant_id'] ?? NULL) && preg_match('/^mrch_[a-z0-9_]{3,32}$/D', $c['merchant_id'])
      && is_string($c['secret'] ?? NULL) && strlen($c['secret']) >= 32 && !preg_match('/[\x00-\x20\x7f]/', $c['secret']);
  }

}
