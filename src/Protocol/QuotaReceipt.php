<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Protocol;

/**
 * Preflight validation only. The existing backend alone consumes shared quota.
 *
 * Claim shape follows aifinpay-web `app/aifp/receipts.js` (signReceipt) and the
 * hosted gateway's own checks (`routes/gateway.js`, `app/aifp/receipt-verify.js`):
 * a quota receipt has no `typ_aifp` (or "quota") and a `receipt_id`; since the
 * credit model it also carries `payer` (= `sub`), which binds the batch to the
 * caller presenting the same AIFP-Agent-Id. Receipts issued before that lack
 * `payer` and may meter by legacy `quota` instead of `unit_quota`; the backend
 * accepts them until its configured sunset, so this preflight does not refuse
 * them either. Solana receipts are never metered by the hosted gateway.
 */
final class QuotaReceipt {

  public static function verify(string $token, array $jwks, array $site, string $path, int $now): array {
    $p = ActionReceipt::signedClaims($token, $jwks);
    if (($p['chain'] ?? NULL) === 'solana') {
      throw new \RuntimeException('solana_hosted_gateway_unavailable');
    }
    $mode = $p['network_mode'] ?? 'live';
    $scope = $p['scope'] ?? 'exact';
    $resource = $p['resource'] ?? '';
    $covers = is_string($resource) && ($scope === 'merchant' ||
      ($scope === 'prefix' && ($path === $resource || str_starts_with($path, rtrim($resource, '/') . '/'))) ||
      ($scope === 'exact' && ($path === $resource || (str_ends_with($resource, '/*') &&
        ($path === substr($resource, 0, -2) || str_starts_with($path, substr($resource, 0, -1)))))));
    if (($p['iss'] ?? '') !== ActionReceipt::issuer($site) || ($p['aud'] ?? '') !== ($site['merchant_id'] ?? '')
      || !in_array($p['typ_aifp'] ?? 'quota', ['quota'], TRUE)
      || !in_array($scope, ['exact', 'prefix', 'merchant'], TRUE) || !$covers
      || !in_array($mode, ['test', 'live'], TRUE) || $mode !== ($site['network_mode'] ?? 'test')
      || !is_int($p['iat'] ?? NULL) || !is_int($p['exp'] ?? NULL) || $p['iat'] > $now + 5
      || $p['exp'] <= $now || $p['exp'] <= $p['iat'] || $p['exp'] - $p['iat'] > 2592060
      || (isset($p['nbf']) && (!is_int($p['nbf']) || $p['nbf'] > $now))
      || !is_string($p['receipt_id'] ?? NULL) || !preg_match('/^rcpt_[a-f0-9]{16}$/D', $p['receipt_id'])
      || !is_string($p['nonce'] ?? NULL) || $p['nonce'] === '' || strlen($p['nonce']) > 256
      || (isset($p['payer']) && (!is_string($p['payer']) || $p['payer'] === '' || strlen($p['payer']) > 256))
      || (isset($p['unit_quota']) && (!is_int($p['unit_quota']) || $p['unit_quota'] < 1 || $p['unit_quota'] > 100000000))
      || (isset($p['quota']) && (!is_int($p['quota']) || $p['quota'] < 1 || $p['quota'] > 100000000))
      || !is_string($p['amount'] ?? NULL) || !preg_match('/^(0|[1-9][0-9]{0,12})(\.[0-9]{1,6})?$/D', $p['amount'])
      || !preg_match('/[1-9]/', $p['amount']) || ($p['currency'] ?? '') !== 'USD'
      || !in_array($p['asset'] ?? NULL, $site['allowed_assets'] ?? [], TRUE)
      || !in_array($p['chain'] ?? NULL, $site['allowed_chains'] ?? [], TRUE)) {
      throw new \RuntimeException('invalid_quota_receipt');
    }
    return $p;
  }

}
