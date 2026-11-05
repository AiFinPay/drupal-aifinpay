<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Protocol;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Reuses hosted metering; Drupal does not issue quotes or submit payments. */
final class HostedGateway {

  public function __construct(private Transport $transport, private CacheBackendInterface $cache, private Connection $database) {}

  public static function approved(array $site): bool {
    // Only an explicitly local contract fixture may test the gateway without Edge.
    $edgeReady = (($site['environment'] ?? '') === 'local' && ($site['local_contract'] ?? FALSE) === TRUE && empty($site['edge_required']))
      || self::edgeReady($site);
    return ($site['enforce_approved'] ?? FALSE) === TRUE && ($site['gateway_validated'] ?? FALSE) === TRUE
      && ($site['cache_bypass_validated'] ?? FALSE) === TRUE
      && ($site['backend_security_validated'] ?? FALSE) === TRUE
      && $edgeReady
      && in_array($site['environment'] ?? '', ['local', 'sandbox', 'development', 'staging', 'production'], TRUE)
      && ($site['environment'] !== 'production' || ($site['production_approved'] ?? FALSE) === TRUE);
  }

  public static function edgeReady(array $site): bool {
    return ($site['edge_required'] ?? FALSE) === TRUE
      && ($site['bot_management_validated'] ?? FALSE) === TRUE
      && ($site['worker_pre_cache_validated'] ?? FALSE) === TRUE
      && ($site['cache_bypass_validated'] ?? FALSE) === TRUE
      && is_string($site['edge_context_secret'] ?? NULL)
      && strlen($site['edge_context_secret']) >= 32 && strlen($site['edge_context_secret']) <= 128;
  }

  public static function secretValid(array $site): bool {
    return is_string($site['origin_secret'] ?? NULL)
      && strlen($site['origin_secret']) >= 32 && strlen($site['origin_secret']) <= 128
      && !preg_match('/[\x00-\x20\x7f]/', $site['origin_secret']);
  }

  public static function originAuthorized(Request $request, array $site): bool {
    $presented = $request->headers->get('AIFP-Proxy-Auth', '');
    return self::secretValid($site) && is_string($presented)
      && hash_equals($site['origin_secret'], $presented);
  }

  public function snapshot(array $site): array {
    $merchant = $site['merchant_id'] ?? '';
    if (!is_string($merchant) || !preg_match('/^mrch_[a-z0-9_]{3,32}$/D', $merchant)) {
      throw new \RuntimeException('invalid_merchant');
    }
    $origin = Transport::origin($site, 'api');
    $key = 'aifinpay.registry.' . hash('sha256', $origin . "\0" . $merchant . "\0" . ($site['site_id'] ?? '') . "\0" . ($site['secret'] ?? ''));
    $cached = $this->cache->get($key);
    if ($cached && is_array($cached->data)) {
      return $cached->data;
    }
    $view = $this->transport->json($site, '/v1/merchants/' . $merchant);
    $registry = $this->transport->json($site, '/v1/merchants/' . $merchant . '/resources');
    if (($view['merchant_id'] ?? '') !== $merchant || !is_array($registry['resources'] ?? NULL)
      || count($registry['resources']) > 1000
      || (($view['network_mode'] ?? 'live') !== ($site['network_mode'] ?? 'test'))) {
      throw new \RuntimeException('invalid_merchant_contract');
    }
    $patterns = [];
    foreach ($registry['resources'] as $r) {
      $pattern = $r['route_pattern'] ?? NULL;
      // Registry records (aifinpay-web app/aifp/resources.js): `tier` and
      // `unit_weight` may be null — the gateway then prices by its defaults.
      if (!is_string($pattern) || !\Drupal\aifinpay\Policy\Paths::validPattern($pattern)
        || isset($patterns[$pattern]) || (isset($r['paywall_enabled']) && !is_bool($r['paywall_enabled']))
        || (isset($r['tier']) && !in_array($r['tier'], ['standard', 'complex', 'premium'], TRUE))
        || (isset($r['unit_weight']) && (!is_int($r['unit_weight']) || $r['unit_weight'] < 1 || $r['unit_weight'] > 100000000))) {
        throw new \RuntimeException('invalid_registry_contract');
      }
      $patterns[$pattern] = TRUE;
    }
    $data = ['merchant' => $view, 'resources' => $registry['resources']];
    $this->cache->set($key, $data, time() + 30);
    return $data;
  }

  public function forward(Request $request, array $site): Response {
    if (!self::approved($site) || !self::secretValid($site)) {
      throw new \RuntimeException('gateway_not_approved');
    }
    $slug = $site['gateway_slug'] ?? '';
    if (!is_string($slug) || !preg_match('/^[a-z0-9][a-z0-9-]{2,39}$/D', $slug)) {
      throw new \RuntimeException('invalid_gateway_slug');
    }
    $snapshot = $this->snapshot($site);
    $path = $request->getPathInfo();
    $matched = NULL;
    foreach ($snapshot['resources'] as $resource) {
      if (\Drupal\aifinpay\Policy\Paths::matches($path, [$resource['route_pattern']])
        && ($matched === NULL || strlen($resource['route_pattern']) > strlen($matched['route_pattern']))) {
        $matched = $resource;
      }
    }
    if ($matched === NULL) {
      throw new \RuntimeException('resource_not_registered');
    }
    $headers = ['Accept' => $request->headers->get('Accept', '*/*'), 'Cache-Control' => 'no-store'];
    foreach (['AIFP-Receipt' => 8192, 'AIFP-Agent-Id' => 128] as $name => $limit) {
      $value = $request->headers->get($name);
      if ($value !== NULL) {
        if (strlen($value) > $limit || preg_match('/[\x00-\x20\x7f]/', $value)) {
          throw new \RuntimeException('invalid_protocol_header');
        }
        $headers[$name] = $value;
      }
    }
    $jwks = NULL;
    if (isset($headers['AIFP-Receipt'])) {
      $jwks = $this->transport->json($site, '/.well-known/jwks.json', FALSE);
      try {
        $quota = QuotaReceipt::verify($headers['AIFP-Receipt'], $jwks, $site, $path, time());
      }
      catch (\RuntimeException $e) {
        if ($e->getMessage() === 'solana_hosted_gateway_unavailable') {
          // Same answer the hosted gateway gives: Solana receipts are spent at
          // the merchant's own gate, never metered here.
          return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'AIFP-SOLANA-QUOTA', 'reason' => 'solana_hosted_gateway_unavailable'], 503);
        }
        return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'invalid_quota_receipt'], 403);
      }
      catch (\Throwable) {
        return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'invalid_quota_receipt'], 403);
      }
      // Credit model: a receipt with a payer claim is spendable only by that
      // payer, so the gateway needs the caller's AIFP-Agent-Id to match it.
      if (isset($quota['payer']) && !isset($headers['AIFP-Agent-Id'])) {
        return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'AIFP-403-PAYER', 'detail' => 'AIFP-Agent-Id is required with this receipt'], 403);
      }
    }
    $up = $this->transport->request($site, 'GET', '/' . $slug . $path, ['headers' => $headers], 'gateway');
    $status = $up->getStatusCode();
    $raw = Transport::read($up, 1048576);
    if ($status === 402) {
      $body = json_decode($raw, TRUE, 32, JSON_THROW_ON_ERROR);
      if (($body['error'] ?? '') !== 'AIFP-402' || ($body['protocol'] ?? '') !== 'AIFP-1'
        || ($body['merchant_id'] ?? '') !== $site['merchant_id'] || ($body['resource'] ?? '') !== $path) {
        throw new \RuntimeException('gateway_binding_mismatch');
      }
      return new Response($raw, 402, ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store']);
    }
    if ($status === 410) {
      // A slug a site once published is held forever (gateway_retired).
      return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'gateway_retired'], 410);
    }
    if ($status < 200 || $status >= 300) {
      return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'gateway_access_denied'], in_array($status, [403, 429], TRUE) ? $status : 503);
    }
    $jwks ??= $this->transport->json($site, '/.well-known/jwks.json', FALSE);
    if (!is_array($jwks['keys'] ?? NULL) || count($jwks['keys']) > 16) {
      throw new \RuntimeException('invalid_key_set');
    }
    $token = $up->getHeaderLine('AIFP-Action-Receipt');
    $claims = ActionReceipt::verify($token, $jwks, $site['merchant_id'], $path, $status, time(), ActionReceipt::issuer($site));
    $this->database->insert('aifinpay_actions')->fields([
      'action_hash' => hash('sha256', $site['merchant_id'] . "\0" . $claims['arcpt_id']),
      'expires' => min($claims['exp'], $claims['iat'] + 120),
    ])->execute();
    $type = $up->getHeaderLine('Content-Type');
    $response = new Response($raw, $status, ['Content-Type' => $type ?: 'application/octet-stream', 'Cache-Control' => 'private, no-store']);
    foreach (['AIFP-Action-Receipt', 'AIFP-Action-Receipt-Id', 'AIFP-Request-Id', 'AIFP-Units-Charged', 'AIFP-Quota-Remaining', 'AIFP-Billing-Mode'] as $name) {
      $value = $up->getHeaderLine($name);
      if ($value !== '' && strlen($value) <= 8192 && !preg_match('/[\x00-\x1f\x7f]/', $value)) {
        $response->headers->set($name, $value);
      }
    }
    return $response;
  }

}

