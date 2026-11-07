<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Connect;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\aifinpay\Protocol\Transport;

/** The public merchant record (GET /v1/merchants/{id}): charging host and approval when settings.php sets neither. */
final class MerchantRecord {

  public const STATE_KEY = 'aifinpay.merchant_record';

  public const TTL = 300;

  public const STALE_LIMIT = 86400;

  public const RETRY = 60;

  public function __construct(private Transport $transport, private CacheBackendInterface $cache, private StateInterface $state) {}

  /** Cached five minutes; while the API is unreachable the last known record serves for a day, retried every minute. */
  public function get(array $site, bool $refresh = FALSE, ?int $now = NULL): ?array {
    $now ??= time();
    $merchant = (string) ($site['merchant_id'] ?? '');
    if (!preg_match('/^mrch_[a-z0-9_]{3,32}$/D', $merchant)) {
      return NULL;
    }
    try {
      $key = 'aifinpay.merchant_record.' . hash('sha256', Transport::origin($site, 'api') . "\0" . $merchant);
    }
    catch (\Throwable) {
      return NULL;
    }
    if (!$refresh && ($cached = $this->cache->get($key)) && is_array($cached->data) && array_key_exists('record', $cached->data)) {
      return $cached->data['record'];
    }
    try {
      $record = self::fromResponse($this->transport->json($site, '/v1/merchants/' . $merchant, FALSE), $merchant, $now);
    }
    catch (\Throwable) {
      $record = NULL;
    }
    if ($record === NULL) {
      $last = $this->state->get(self::STATE_KEY);
      $record = is_array($last) && ($last['merchant_id'] ?? NULL) === $merchant && $now - (int) ($last['fetched_at'] ?? 0) <= self::STALE_LIMIT
        ? $last : NULL;
      $this->cache->set($key, ['record' => $record], $now + self::RETRY);
      return $record;
    }
    $this->cache->set($key, ['record' => $record], $now + self::TTL);
    $this->state->set(self::STATE_KEY, $record);
    return $record;
  }

  /** The validated subset this module uses; NULL for anything else. */
  public static function fromResponse(array $body, string $merchant, int $now): ?array {
    if (($body['merchant_id'] ?? NULL) !== $merchant) {
      return NULL;
    }
    $mode = $body['network_mode'] ?? 'live';
    if (!in_array($mode, ['live', 'test'], TRUE)) {
      return NULL;
    }
    $url = $body['site_url'] ?? NULL;
    $host = is_string($url) ? self::host($url) : NULL;
    $name = $body['name'] ?? NULL;
    return [
      'merchant_id' => $merchant,
      'name' => is_string($name) ? mb_substr($name, 0, 120) : NULL,
      'network_mode' => $mode,
      'site_url' => $host !== NULL ? $url : NULL,
      'site_host' => $host,
      'site_verified' => ($body['site_verified'] ?? NULL) === TRUE,
      'fetched_at' => $now,
    ];
  }

  /** Lowercase host of an http(s) URL without credentials; NULL otherwise. */
  public static function host(string $url): ?string {
    if (strlen($url) > 2048 || !preg_match('~^https?://~i', $url)) {
      return NULL;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || !isset($parts['host'])) {
      return NULL;
    }
    $host = rtrim(strtolower($parts['host']), '.');
    return preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/D', $host) && !str_contains($host, '..') ? $host : NULL;
  }

  /** Host = site_url; approved = test merchant or live with a verified domain; settings.php keys win. */
  public static function resolve(array $site, ?array $record): array {
    $own = $record !== NULL && ($record['merchant_id'] ?? NULL) === ($site['merchant_id'] ?? NULL);
    if (!array_key_exists('trusted_hosts', $site)) {
      $site['trusted_hosts'] = $own && is_string($record['site_host'] ?? NULL) ? [$record['site_host']] : [];
      $site['trusted_hosts_source'] = 'merchant_record';
    }
    if (!array_key_exists('receipt_gate_approved', $site)) {
      $live = $own && $record['network_mode'] === 'live';
      $approved = $own && ($record['network_mode'] === 'test' || $record['site_verified'] === TRUE);
      $site['receipt_gate_approved'] = $approved;
      $site['environment'] ??= $live ? 'production' : 'sandbox';
      $site['production_approved'] ??= $approved && $live;
      $site['approval_source'] = 'merchant_record';
    }
    if ($own && !array_key_exists('network_mode', $site)) {
      $site['network_mode'] = $record['network_mode'];
    }
    return $site;
  }

  /** Why charging is or is not approved, for the admin page. */
  public static function approvalReason(array $resolved, ?array $record): string {
    if (($resolved['approval_source'] ?? '') !== 'merchant_record') {
      return 'settings_php';
    }
    if ((string) ($resolved['merchant_id'] ?? '') === '') {
      return 'not_connected';
    }
    if ($record === NULL || ($record['merchant_id'] ?? NULL) !== ($resolved['merchant_id'] ?? NULL)) {
      return 'record_unavailable';
    }
    if ($record['network_mode'] === 'test') {
      return 'test_merchant';
    }
    return $record['site_verified'] ? 'live_domain_verified' : 'live_domain_unverified';
  }

}
