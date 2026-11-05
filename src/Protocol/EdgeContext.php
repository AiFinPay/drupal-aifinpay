<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Protocol;

use Symfony\Component\HttpFoundation\Request;

/** Verifies request-bound context injected by the trusted pre-cache edge layer. */
final class EdgeContext {

  private const ALLOWED_CLASSIFICATIONS = ['AI_AGENT', 'AUTOMATION', 'HUMAN', 'UNKNOWN'];
  private const ALLOWED_DECISIONS = ['BYPASS', 'OBSERVE', 'MONETIZE'];

  public static function verify(Request $request, array $site): ?array {
    $encoded = $request->headers->get('X-AiFinPay-Edge-Context', '');
    $signature = $request->headers->get('X-AiFinPay-Edge-Signature', '');
    $secret = $site['edge_context_secret'] ?? '';
    if (!is_string($secret) || strlen($secret) < 32 || strlen($secret) > 128
      || !is_string($encoded) || $encoded === '' || strlen($encoded) > 4096
      || !is_string($signature) || $signature === '' || strlen($signature) > 256) {
      return NULL;
    }
    $provided = self::decode($signature);
    if ($provided === NULL) {
      return NULL;
    }
    $expected = hash_hmac('sha256', $encoded, $secret, TRUE);
    if (!hash_equals($expected, $provided)) {
      return NULL;
    }
    $json = self::decode($encoded);
    if ($json === NULL || strlen($json) > 3072) {
      return NULL;
    }
    try {
      $payload = json_decode($json, TRUE, 16, JSON_THROW_ON_ERROR);
    }
    catch (\Throwable) {
      return NULL;
    }
    if (!is_array($payload) || ($payload['v'] ?? NULL) !== 2
      || ($payload['site_id'] ?? NULL) !== ($site['site_id'] ?? NULL)
      || ($payload['method'] ?? NULL) !== strtoupper($request->getMethod())
      || strtolower((string) ($payload['host'] ?? '')) !== strtolower($request->getHttpHost())
      || ($payload['path'] ?? NULL) !== $request->getPathInfo()
      || ($payload['query'] ?? NULL) !== (string) $request->server->get('QUERY_STRING', '')
      || !is_bool($payload['trusted'] ?? NULL)
      || !is_int($payload['revision'] ?? NULL) || $payload['revision'] < 1
      || $payload['revision'] !== ($site['config_revision'] ?? 1)
      || !in_array($payload['classification'] ?? NULL, self::ALLOWED_CLASSIFICATIONS, TRUE)
      || !in_array($payload['decision'] ?? NULL, self::ALLOWED_DECISIONS, TRUE)
      || !is_string($payload['source'] ?? NULL) || !preg_match('/^[a-z0-9._:-]{1,80}$/iD', $payload['source'])
      || !is_string($payload['nonce'] ?? NULL) || !preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $payload['nonce'])
      || !is_int($payload['ts'] ?? NULL)) {
      return NULL;
    }
    $ttl = (int) ($site['edge_context_ttl'] ?? 90);
    if ($ttl < 10 || $ttl > 300 || $payload['ts'] > time() + 5 || time() - $payload['ts'] > $ttl) {
      return NULL;
    }
    return $payload;
  }

  private static function decode(string $value): ?string {
    if (!preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
      return NULL;
    }
    $pad = strlen($value) % 4;
    if ($pad > 0) {
      $value .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode(strtr($value, '-_', '+/'), TRUE);
    return $decoded === FALSE ? NULL : $decoded;
  }

}

