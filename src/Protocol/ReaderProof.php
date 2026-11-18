<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Protocol;

/** The reader cookie `__aifp_human=<exp>.<sig>`: base64url HMAC-SHA-256 of "<exp>\n<user-agent>", as in @aifinpay/gate. */
final class ReaderProof {

  public const COOKIE = '__aifp_human';
  public const TTL = 2592000;
  public const MIN_KEY_BYTES = 32;

  public function __construct(private string $key, private int $ttl = self::TTL) {
    if (strlen($key) < self::MIN_KEY_BYTES) {
      throw new \InvalidArgumentException('reader key must be at least 32 bytes');
    }
  }

  public function issue(string $userAgent, int $now): string {
    $exp = (string) ($now + $this->ttl);
    return $exp . '.' . $this->sign($exp, $userAgent);
  }

  /** Only the first reader cookie in the header counts, as everywhere else the proof is checked. */
  public function verify(?string $cookieHeader, string $userAgent, int $now): bool {
    $prefix = self::COOKIE . '=';
    foreach (preg_split('/;[ \t]*/', (string) $cookieHeader) ?: [] as $pair) {
      if (!str_starts_with($pair, $prefix)) {
        continue;
      }
      if (!preg_match('/^(0|[1-9][0-9]{0,15})\.([A-Za-z0-9_-]{43})$/D', substr($pair, strlen($prefix)), $m)) {
        return FALSE;
      }
      if ((float) $m[1] > 9007199254740991 || (int) $m[1] <= $now) {
        return FALSE;
      }
      return hash_equals($this->sign($m[1], $userAgent), $m[2]);
    }
    return FALSE;
  }

  public function setCookie(string $value): string {
    return self::COOKIE . '=' . $value . '; Path=/; Max-Age=' . $this->ttl . '; HttpOnly; Secure; SameSite=Lax';
  }

  private function sign(string $exp, string $userAgent): string {
    return rtrim(strtr(base64_encode(hash_hmac('sha256', $exp . "\n" . $userAgent, $this->key, TRUE)), '+/', '-_'), '=');
  }

}
