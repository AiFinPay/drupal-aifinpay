<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Protocol;

/** The DNS lookups search-bot verification needs; a failure throws. */
interface DnsResolver {

  /** @return array<mixed> PTR names of the address; anything not a name is skipped. */
  public function reverse(string $ip): array;

  /** @return array<mixed> A (or AAAA when $ipv6) addresses of the name. */
  public function forward(string $name, bool $ipv6): array;

}
