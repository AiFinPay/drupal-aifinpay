<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Protocol;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * The self-hosted AIFP-1 gate of @aifinpay/gate (gate/src/core.ts), in PHP.
 *
 * No receipt: the same 402 body the gate SDK sends. A receipt: verified here
 * against the published JWKS (Ed25519, no control-plane round trip) and its
 * prepaid units are metered in this site's database. Solana receipts are
 * spendable here, unlike on the hosted gateway: a merchant's own gate is
 * where they are spent.
 */
final class ReceiptGate {

  public const TIER_WEIGHTS = ['standard' => 1, 'complex' => 4, 'premium' => 10];
  public const UNIT_PRICE_USD = ['standard' => '0.0005', 'complex' => '0.002', 'premium' => '0.005'];
  // The $0.02 minimum batch (20000 stablecoin minor units) at each tier's price.
  public const MIN_BATCH_UNITS = 20000;
  public const MIN_REQUESTS = ['standard' => 40, 'complex' => 10, 'premium' => 4];
  public const CLOCK_TOLERANCE = 30;

  public const DETAIL_PAYMENT_REQUIRED = 'Payment Required — prepay a batch of requests and retry with the AIFP-Receipt header';
  public const DETAIL_QUOTA_EXHAUSTED = 'quota exhausted — prepay the next batch';
  public const DETAIL_RECEIPT_EXPIRED = 'receipt expired — prepay a new batch';
  public const DETAIL_VERIFY_FAILED = 'receipt verification failed (signature/issuer/audience)';

  public function __construct(private Transport $transport, private CacheBackendInterface $cache, private Connection $database) {}

  /** Server-owned approval in settings.php; production needs its own. */
  public static function approved(array $site): bool {
    return ($site['receipt_gate_approved'] ?? FALSE) === TRUE
      && in_array($site['environment'] ?? '', ['local', 'sandbox', 'development', 'staging', 'production'], TRUE)
      && ($site['environment'] !== 'production' || ($site['production_approved'] ?? FALSE) === TRUE);
  }

  /** The 402 body, field for field what buildChallenge() in the gate SDK emits. */
  public static function challenge(string $merchant, string $resource, string $tier, string $api, string $detail = ''): array {
    $api = rtrim($api, '/');
    $min = self::MIN_REQUESTS[$tier];
    return [
      'error' => 'AIFP-402',
      'detail' => $detail !== '' ? $detail : self::DETAIL_PAYMENT_REQUIRED,
      'protocol' => 'AIFP-1',
      'documentation_url' => 'https://github.com/AiFinPay/sdk/blob/main/AGENT-FLOW.md',
      'instructions_url' => 'https://raw.githubusercontent.com/AiFinPay/skill/main/agent/skills/aifinpay/SKILL.md',
      'merchant_instructions_url' => 'https://raw.githubusercontent.com/AiFinPay/skill/main/agent/skills/aifinpay-merchant/SKILL.md',
      'merchant_id' => $merchant,
      'resource' => $resource,
      'tier' => $tier,
      'unit_weight' => self::TIER_WEIGHTS[$tier],
      'unit_price_usd' => self::UNIT_PRICE_USD[$tier],
      'min_requests' => $min,
      'scope' => 'exact',
      'protocol_fee_bps' => 100,
      'no_minimum_fee' => TRUE,
      'settlement_terms_from' => "POST $api/v1/quote — returns accepted_chains, accepted_assets, amount, order_id and expiry",
      'how_to_pay' => [
        "POST $api/v1/quote {\"merchant_id\":\"$merchant\",\"resource\":\"$resource\",\"tier\":\"$tier\",\"scope\":\"exact\",\"requests\":$min,\"payer\":\"<your wallet address>\"} — payer is required; raise requests to buy more than the minimum batch; check amount, scope, expiry and payer before paying",
        'settle the quoted batch on-chain from your own wallet (order_id = quote_id)',
        "POST $api/v1/pay {quote_id, chain, asset, tx_ref, payment_authorization} + Idempotency-Key header -> quota receipt (wallet-signature-v1). Keep the quote until you hold the receipt; on a timeout retry with the same key and tx_ref — never pay again",
        'retry this request with header: AIFP-Receipt: <receipt JWT>',
      ],
      'no_wallet' => 'npx @aifinpay/mcp init — creates or selects a local wallet; read instructions_url for supported clients and payment availability. Wallet setup alone does not enable settlement.',
    ];
  }

  /**
   * Port of scopeCovers() (gate/src/scope.ts): where one batch may be spent.
   */
  public static function scopeCovers(mixed $scope, mixed $resource, string $path): bool {
    $resource = is_string($resource) ? $resource : '';
    if ($scope === 'merchant') {
      return TRUE;
    }
    if ($scope === 'prefix') {
      if ($resource === '/' || $path === $resource) {
        return TRUE;
      }
      return str_starts_with($path, str_ends_with($resource, '/') ? $resource : $resource . '/');
    }
    if (!str_ends_with($resource, '/*')) {
      return $path === $resource;
    }
    return $path === substr($resource, 0, -2) || str_starts_with($path, substr($resource, 0, -1));
  }

  /**
   * Signature, issuer, audience and time, as jose.jwtVerify in the gate SDK.
   *
   * @throws \RuntimeException 'expired' or 'invalid'.
   */
  public static function verify(string $token, array $jwks, string $merchant, string $issuer, int $now): array {
    try {
      $p = ActionReceipt::signedClaims($token, $jwks);
    }
    catch (\Throwable) {
      throw new \RuntimeException('invalid');
    }
    $aud = $p['aud'] ?? NULL;
    $audOk = $aud === $merchant || (is_array($aud) && in_array($merchant, $aud, TRUE));
    if (($p['iss'] ?? NULL) !== $issuer || !$audOk
      || (isset($p['iat']) && (!is_int($p['iat']) || $p['iat'] > $now + self::CLOCK_TOLERANCE))
      || (isset($p['nbf']) && (!is_int($p['nbf']) || $p['nbf'] > $now + self::CLOCK_TOLERANCE))
      || !is_int($p['exp'] ?? NULL)) {
      throw new \RuntimeException('invalid');
    }
    if ($p['exp'] + self::CLOCK_TOLERANCE <= $now) {
      throw new \RuntimeException('expired');
    }
    return $p;
  }

  /** EVM addresses compare without checksum case; Solana addresses exactly. */
  public static function samePayer(string $payer, ?string $agent): bool {
    if ($agent === NULL || $agent === '') {
      return FALSE;
    }
    $evm = '/^0x[0-9a-fA-F]{40}$/D';
    return preg_match($evm, $payer) && preg_match($evm, $agent) ? strtolower($payer) === strtolower($agent) : $payer === $agent;
  }

  /** The published key set, cached like createRemoteJWKSet (10 minutes). */
  public function jwks(array $site): array {
    $key = 'aifinpay.jwks.' . hash('sha256', Transport::origin($site, 'api'));
    $cached = $this->cache->get($key);
    if ($cached && is_array($cached->data)) {
      return $cached->data;
    }
    $jwks = $this->transport->json($site, '/.well-known/jwks.json', FALSE);
    if (!is_array($jwks['keys'] ?? NULL) || count($jwks['keys']) > 16) {
      throw new \RuntimeException('invalid_key_set');
    }
    $this->cache->set($key, $jwks, time() + 600);
    return $jwks;
  }

  /**
   * Meter $weight units of one receipt. Returns the units used after this
   * call, or NULL when the batch cannot cover it (nothing is taken then).
   */
  public function spend(string $merchant, string $receiptId, int $weight, int $limit, int $expires): ?int {
    $hash = hash('sha256', $merchant . "\0" . $receiptId);
    for ($attempt = 0; $attempt < 2; $attempt++) {
      // One conditional UPDATE: of two concurrent calls only the one that
      // still fits under the limit changes the row, so a batch is never
      // overspent.
      $updated = $this->database->update('aifinpay_receipt_units')
        ->expression('used', 'used + :w', [':w' => $weight])
        ->condition('receipt_hash', $hash)
        ->where('used + :w2 <= :limit', [':w2' => $weight, ':limit' => $limit])
        ->execute();
      if ($updated > 0) {
        return (int) $this->database->select('aifinpay_receipt_units', 'u')
          ->fields('u', ['used'])->condition('receipt_hash', $hash)->execute()->fetchField();
      }
      if ($this->database->select('aifinpay_receipt_units', 'u')->condition('receipt_hash', $hash)->countQuery()->execute()->fetchField() > 0) {
        return NULL;
      }
      if ($weight > $limit) {
        return NULL;
      }
      try {
        $this->database->insert('aifinpay_receipt_units')
          ->fields(['receipt_hash' => $hash, 'used' => $weight, 'expires' => $expires])->execute();
        return $weight;
      }
      catch (IntegrityConstraintViolationException) {
        // A concurrent first spend created the row; meter against it.
      }
    }
    return NULL;
  }

  /**
   * Decide one request to a protected path.
   *
   * @return array{status: int, body: ?array, headers: array<string, string>, event: ?array, receipt?: array<string, string>}
   *   status 200 means serve; `event` is the gate event to report, if any;
   *   `receipt` the spent receipt's ids on a paid read.
   */
  public function decide(Request $request, array $site, string $rule, string $tier, bool $exemptHumans, int $now): array {
    $merchant = (string) ($site['merchant_id'] ?? '');
    $api = Transport::origin($site, 'api');
    $weight = self::TIER_WEIGHTS[$tier];
    $who = AgentIdentity::classify($request);
    $challenge = fn(string $detail = ''): array => ['status' => 402, 'body' => self::challenge($merchant, $rule, $tier, $api, $detail),
      'headers' => [], 'event' => ['kind' => '402'] + $who];
    $forbid = fn(string $detail): array => ['status' => 403, 'body' => ['error' => 'AIFP-403', 'detail' => $detail], 'headers' => [], 'event' => NULL];

    // A paying agent is never exempted: AIFP-Receipt / AIFP-Agent-Id make the
    // request an aifp_agent whatever its User-Agent says.
    if ($exemptHumans && $who['ua_class'] === 'human') {
      return ['status' => 200, 'body' => NULL, 'headers' => ['AIFP-Paywall' => 'exempt'], 'event' => NULL];
    }
    $token = $request->headers->get('AIFP-Receipt');
    if ($token === NULL || $token === '') {
      return $challenge();
    }
    try {
      $jwks = $this->jwks($site);
    }
    catch (\Throwable) {
      // Closed: an outage must not turn a paid page into a free one.
      return ['status' => 503, 'body' => ['error' => 'AIFP-503-METER', 'detail' => 'receipt verification is temporarily unavailable — retry shortly'],
        'headers' => ['Retry-After' => '30'], 'event' => NULL];
    }
    try {
      $p = self::verify($token, $jwks, $merchant, ActionReceipt::issuer($site), $now);
    }
    catch (\RuntimeException $e) {
      return $e->getMessage() === 'expired' ? $challenge(self::DETAIL_RECEIPT_EXPIRED) : $forbid(self::DETAIL_VERIFY_FAILED);
    }
    if (isset($p['typ_aifp']) && $p['typ_aifp'] !== 'quota') {
      return $forbid('receipt type "' . (is_string($p['typ_aifp']) ? $p['typ_aifp'] : '?') . '" is not spendable — this endpoint needs a quota receipt');
    }
    if (!is_string($p['receipt_id'] ?? NULL) || $p['receipt_id'] === '') {
      return $forbid('receipt carries no receipt_id and cannot be metered');
    }
    $path = $request->getPathInfo();
    if (!self::scopeCovers($p['scope'] ?? NULL, $p['resource'] ?? NULL, $path)) {
      return $forbid('receipt is scoped to ' . (is_string($p['resource'] ?? NULL) ? $p['resource'] : '?') . ' (' . (is_string($p['scope'] ?? NULL) && $p['scope'] !== '' ? $p['scope'] : 'exact') . '), not ' . $path);
    }
    // A payer-bound batch is spent only by the agent that paid for it.
    $payer = $p['payer'] ?? $p['sub'] ?? NULL;
    $agent = $request->headers->get('AIFP-Agent-Id');
    if (is_string($payer) && $payer !== '' && !self::samePayer($payer, $agent)) {
      return $forbid('AIFP-Agent-Id does not match the receipt payer');
    }
    $limit = isset($p['unit_quota']) && is_int($p['unit_quota'])
      ? $p['unit_quota']
      : (is_int($p['quota'] ?? NULL) && $p['quota'] > 0 ? $p['quota'] : 1) * (self::TIER_WEIGHTS[$p['tier'] ?? ''] ?? 1);
    if ($limit < 1) {
      return $forbid('receipt carries no spendable units');
    }
    $used = $this->spend($merchant, $p['receipt_id'], $weight, $limit, $p['exp'] + self::CLOCK_TOLERANCE);
    if ($used === NULL) {
      return $challenge(self::DETAIL_QUOTA_EXHAUSTED);
    }
    return ['status' => 200, 'body' => NULL, 'headers' => [
      'AIFP-Quota-Remaining' => (string) ($limit - $used),
      'AIFP-Receipt-Id' => $p['receipt_id'],
    ], 'event' => ['kind' => 'serve', 'operator' => 'aifinpay', 'ua_class' => 'aifp_agent', 'verified' => TRUE],
      // Verified claims that tie this read to its quote and payment.
      'receipt' => array_map(static fn($v): string => is_scalar($v) ? substr((string) $v, 0, 100) : '',
        array_intersect_key($p, array_flip(['receipt_id', 'quote_id', 'tx_ref', 'chain', 'network_mode']))) + ['remaining' => (string) ($limit - $used)]];
  }

}
