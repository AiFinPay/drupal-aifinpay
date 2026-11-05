<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Connect;

use Drupal\aifinpay\Protocol\Transport;

/**
 * Redeems a one-time dashboard connect code for merchant_id + a freshly rotated secret.
 *
 * POST {api origin}/v1/merchants/connect {"code": …} → {merchant_id, secret}.
 * The secret is returned to the caller only; nothing here logs or renders it.
 */
final class ConnectClient {

  public const RE_CODE = '/^(AIFP)?[0-9A-HJKMNP-TV-Z]{16}$/D';

  public function __construct(private Transport $transport) {}

  /** Uppercase, strip spaces and dashes; NULL when it cannot be a code. */
  public static function normalise(string $input): ?string {
    $raw = strtoupper((string) preg_replace('/[\s-]+/', '', $input));
    return preg_match(self::RE_CODE, $raw) ? $raw : NULL;
  }

  /**
   * @return array{merchant_id: string, secret: string}
   * @throws ConnectException with code invalid_code|rate_limited|unavailable|bad_response
   */
  public function redeem(array $site, string $code): array {
    $normal = self::normalise($code);
    if ($normal === NULL) {
      throw new ConnectException('invalid_code');
    }
    try {
      $response = $this->transport->request($site, 'POST', '/v1/merchants/connect', [
        'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        'body' => json_encode(['code' => $normal], JSON_THROW_ON_ERROR),
        'timeout' => 8.0,
      ]);
      $status = $response->getStatusCode();
      $raw = Transport::read($response, 4096);
    }
    catch (\Throwable) {
      throw new ConnectException('unavailable');
    }
    if ($status === 400) {
      throw new ConnectException('invalid_code');
    }
    if ($status === 429) {
      throw new ConnectException('rate_limited');
    }
    if ($status !== 200) {
      throw new ConnectException('unavailable');
    }
    $body = json_decode($raw, TRUE);
    $merchant = is_array($body) ? ($body['merchant_id'] ?? NULL) : NULL;
    $secret = is_array($body) ? ($body['secret'] ?? NULL) : NULL;
    if (!is_string($merchant) || !preg_match('/^mrch_[a-z0-9_]{3,32}$/D', $merchant)
      || !is_string($secret) || strlen($secret) < 32 || strlen($secret) > 200 || preg_match('/[\x00-\x20\x7f]/', $secret)) {
      throw new ConnectException('bad_response');
    }
    return ['merchant_id' => $merchant, 'secret' => $secret];
  }

}
