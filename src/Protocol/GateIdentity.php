<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Protocol;

use Drupal\aifinpay\Policy\FreeReaders;

/**
 * What this module tells AiFinPay about itself on every request with the merchant secret.
 *
 * `User-Agent: aifinpay-gate-drupal/<ver>` and
 * `AIFP-Gate: pkg=drupal; ver=<ver>; platform=drupal<major>; mode=<mode>; charge=<charge>`,
 * exactly as the shared vectors gate-identity-v1.json (`format`).
 */
final class GateIdentity {

  /** This module's version; a test keeps it equal to aifinpay.info.yml and the CHANGELOG. */
  public const VERSION = '1.4.0';

  public const PKG = 'drupal';

  private const VALUE = '/^[a-z0-9._-]{1,32}$/D';

  /**
   * The two headers for one gate.
   *
   * @return array{User-Agent: string, AIFP-Gate: string}
   */
  public static function format(string $pkg, mixed $ver, mixed $platform, string $mode, string $charge): array {
    $v = is_string($ver) ? explode('+', strtr($ver, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 2)[0] : '';
    $version = preg_match(self::VALUE, $v) ? $v : 'unknown';
    $where = is_string($platform) && preg_match(self::VALUE, $platform) ? $platform : 'unknown';
    return [
      'User-Agent' => 'aifinpay-gate-' . $pkg . '/' . $version,
      'AIFP-Gate' => 'pkg=' . $pkg . '; ver=' . $version . '; platform=' . $where . '; mode=' . $mode . '; charge=' . $charge,
    ];
  }

  /** `enforce` when the gate charges, `shadow` otherwise. */
  public static function mode(array $config): string {
    return !empty($config['enabled']) && ($config['mode'] ?? '') === 'ENFORCE' ? 'enforce' : 'shadow';
  }

  /** "Who reads for free" as the protocol names it. */
  public static function charge(array $config): string {
    return match (FreeReaders::chosen($config)) {
      FreeReaders::NAMED => 'agents',
      FreeReaders::PROVEN => 'unproven',
      default => 'everyone',
    };
  }

  /** `drupal` and the major version of Drupal core, for example drupal11. */
  public static function platform(): string {
    $major = class_exists(\Drupal::class) ? (int) \Drupal::VERSION : 0;
    return $major > 0 ? 'drupal' . $major : 'drupal';
  }

  /**
   * The headers for this site as configured now.
   *
   * @return array{User-Agent: string, AIFP-Gate: string}
   */
  public static function headers(array $config): array {
    return self::format(self::PKG, self::VERSION, self::platform(), self::mode($config), self::charge($config));
  }

}
