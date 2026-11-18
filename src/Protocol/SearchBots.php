<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Protocol;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;

/** Forward-confirmed reverse DNS for a crawler naming one search operator, as verifySearchBot() in @aifinpay/gate. */
final class SearchBots {

  /** Operator => lowercase User-Agent product tokens and PTR suffixes, leading dot included. */
  public const TABLE = [
    'google' => ['tokens' => ['googlebot', 'googlebot-image', 'googlebot-news', 'googlebot-video'], 'suffixes' => ['.googlebot.com']],
    'microsoft' => ['tokens' => ['bingbot'], 'suffixes' => ['.search.msn.com']],
    'apple' => ['tokens' => ['applebot'], 'suffixes' => ['.applebot.apple.com']],
    'yandex' => ['tokens' => ['yandexbot', 'yandeximages', 'yandexmobilebot'], 'suffixes' => ['.yandex.ru', '.yandex.net', '.yandex.com']],
    'baidu' => ['tokens' => ['baiduspider', 'baiduspider-image', 'baiduspider-render'], 'suffixes' => ['.baidu.com', '.baidu.jp']],
  ];

  public const TTL_VERIFIED = 86400;
  public const TTL_REFUSED = 3600;
  public const TTL_ERROR = 60;

  public function __construct(private DnsResolver $resolver, private CacheBackendInterface $cache, private TimeInterface $time) {}

  /** The operator a User-Agent claims, or NULL. */
  public static function claimedOperator(string $userAgent): ?string {
    $ua = strtolower($userAgent);
    foreach (array_keys(AgentIdentity::UA_TABLE) as $marker) {
      if (str_contains($ua, $marker)) {
        return NULL;
      }
    }
    $tokens = array_flip(preg_split('/[^a-z0-9-]+/', $ua) ?: []);
    $claimed = array_keys(array_filter(self::TABLE, static fn(array $e): bool => array_intersect_key(array_flip($e['tokens']), $tokens) !== []));
    return count($claimed) === 1 ? $claimed[0] : NULL;
  }

  /** Canonical text of an address, IPv4-mapped IPv6 as IPv4; NULL when not an address. */
  public static function normalizeIp(string $value): ?string {
    $packed = @inet_pton($value);
    if ($packed === FALSE) {
      return NULL;
    }
    if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
      $packed = substr($packed, 12);
    }
    return inet_ntop($packed) ?: NULL;
  }

  /** "search" when the request comes from a verified search engine, else NULL. */
  public function verify(string $userAgent, ?string $ip): ?string {
    $address = $ip === NULL ? NULL : self::normalizeIp($ip);
    $operator = self::claimedOperator($userAgent);
    if ($address === NULL || $operator === NULL) {
      return NULL;
    }
    $now = $this->time->getCurrentTime();
    $cid = 'aifinpay.search_bot:' . $operator . '|' . $address;
    $hit = $this->cache->get($cid);
    if ($hit !== FALSE && is_array($hit->data) && ($hit->data['until'] ?? 0) > $now) {
      return $hit->data['verified'] ? 'search' : NULL;
    }
    try {
      $verified = $this->forwardConfirmed($address, $operator);
      $ttl = $verified ? self::TTL_VERIFIED : self::TTL_REFUSED;
    }
    catch (\Throwable) {
      $verified = FALSE;
      $ttl = self::TTL_ERROR;
    }
    $this->cache->set($cid, ['verified' => $verified, 'until' => $now + $ttl], $now + $ttl);
    return $verified ? 'search' : NULL;
  }

  private function forwardConfirmed(string $ip, string $operator): bool {
    $ipv6 = str_contains($ip, ':');
    foreach (array_slice($this->resolver->reverse($ip), 0, 8) as $raw) {
      if (!is_string($raw) || strlen($raw) > 254) {
        continue;
      }
      $name = rtrim(strtolower($raw), '.');
      if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/D', $name)) {
        continue;
      }
      $owned = FALSE;
      foreach (self::TABLE[$operator]['suffixes'] as $suffix) {
        $owned = $owned || str_ends_with($name, $suffix);
      }
      if (!$owned) {
        continue;
      }
      foreach (array_slice($this->resolver->forward($name, $ipv6), 0, 32) as $address) {
        if (is_string($address) && self::normalizeIp($address) === $ip) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

}
