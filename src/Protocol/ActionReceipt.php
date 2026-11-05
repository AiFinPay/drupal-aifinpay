<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Protocol;

/** Verifies gateway RESPONSE evidence; never treats an inbound action as access. */
final class ActionReceipt {

  private static function decode(string $value): string {
    if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
      throw new \RuntimeException('invalid_receipt');
    }
    $decoded = base64_decode(strtr($value, '-_', '+/'), TRUE);
    if ($decoded === FALSE || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $value) {
      throw new \RuntimeException('invalid_receipt');
    }
    return $decoded;
  }

  public static function signedClaims(string $token, array $jwks): array {
    if (!function_exists('sodium_crypto_sign_verify_detached') || strlen($token) > 8192) {
      throw new \RuntimeException('verification_unavailable');
    }
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
      throw new \RuntimeException('invalid_receipt');
    }
    [$head, $payload, $signature] = $parts;
    $h = json_decode(self::decode($head), TRUE, 8, JSON_THROW_ON_ERROR);
    $p = json_decode(self::decode($payload), TRUE, 16, JSON_THROW_ON_ERROR);
    if (!is_array($h) || !is_array($p) || ($h['alg'] ?? '') !== 'EdDSA'
      || ($h['typ'] ?? '') !== 'JWT' || !is_string($h['kid'] ?? NULL)
      || isset($h['crit']) || isset($h['jku']) || isset($h['jwk']) || isset($h['x5u'])) {
      throw new \RuntimeException('invalid_receipt');
    }
    $key = NULL;
    foreach ($jwks['keys'] ?? [] as $jwk) {
      if (($jwk['kid'] ?? NULL) === $h['kid']) {
        if ($key !== NULL || ($jwk['kty'] ?? '') !== 'OKP' || ($jwk['crv'] ?? '') !== 'Ed25519'
          || (isset($jwk['alg']) && $jwk['alg'] !== 'EdDSA')
          || (isset($jwk['use']) && $jwk['use'] !== 'sig') || isset($jwk['d'])
          || (isset($jwk['key_ops']) && !in_array('verify', $jwk['key_ops'], TRUE))) {
          throw new \RuntimeException('invalid_key');
        }
        $key = self::decode($jwk['x'] ?? '');
      }
    }
    $sig = self::decode($signature);
    if ($key === NULL || strlen($key) !== 32 || strlen($sig) !== 64
      || !sodium_crypto_sign_verify_detached($sig, "$head.$payload", $key)) {
      throw new \RuntimeException('invalid_signature');
    }
    return $p;
  }

  /**
   * Receipt issuer (`iss`, backend AIFP_ISSUER_URL). Production signs with
   * https://api.aifinpay.io; a non-production backend under test may be pinned
   * through the server-owned binding `receipt_issuer`. Anything that is not an
   * https origin falls back to production, so a typo fails closed.
   */
  public static function issuer(array $site): string {
    $value = $site['receipt_issuer'] ?? NULL;
    return is_string($value) && preg_match('~^https://[a-z0-9.-]+$~D', $value) ? $value : 'https://api.aifinpay.io';
  }

  public static function verify(string $token, array $jwks, string $merchant, string $path, int $status, int $now, string $issuer = 'https://api.aifinpay.io'): array {
    $p = self::signedClaims($token, $jwks);
    if (($p['iss'] ?? '') !== $issuer || ($p['aud'] ?? '') !== $merchant
      || ($p['merchant_id'] ?? '') !== $merchant || ($p['typ_aifp'] ?? '') !== 'action'
      || ($p['resource'] ?? '') !== $path || ($p['upstream_status'] ?? NULL) !== $status
      || !is_int($p['iat'] ?? NULL) || !is_int($p['exp'] ?? NULL)
      || $p['exp'] <= $now || $p['iat'] > $now + 5 || $p['iat'] < $now - 60
      || (isset($p['nbf']) && (!is_int($p['nbf']) || $p['nbf'] > $now))
      || !is_string($p['arcpt_id'] ?? NULL) || !preg_match('/^arcpt_[a-f0-9]{16}$/D', $p['arcpt_id'])
      || !is_int($p['cost_units'] ?? NULL) || $p['cost_units'] < 0 || $p['cost_units'] > 100000000
      || ($p['currency'] ?? '') !== 'USD' || !is_string($p['amount'] ?? NULL)
      || !preg_match('/^(0|[1-9][0-9]{0,12})(\.[0-9]{1,6})?$/D', $p['amount'])) {
      throw new \RuntimeException('invalid_claims');
    }
    return $p;
  }

}
