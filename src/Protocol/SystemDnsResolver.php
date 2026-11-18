<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Protocol;

/** The platform resolver. */
final class SystemDnsResolver implements DnsResolver {

  public function reverse(string $ip): array {
    $name = @gethostbyaddr($ip);
    if ($name === FALSE) {
      throw new \RuntimeException('reverse lookup failed');
    }
    return $name === $ip ? [] : [$name];
  }

  public function forward(string $name, bool $ipv6): array {
    $records = @dns_get_record($name, $ipv6 ? DNS_AAAA : DNS_A);
    if ($records === FALSE) {
      throw new \RuntimeException('forward lookup failed');
    }
    $key = $ipv6 ? 'ipv6' : 'ip';
    return array_values(array_filter(array_map(static fn(array $r): mixed => $r[$key] ?? NULL, $records), 'is_string'));
  }

}
