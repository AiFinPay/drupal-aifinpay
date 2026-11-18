<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Protocol;

use Symfony\Component\HttpFoundation\Cookie;

/** Sent exactly in the shared form; Symfony would otherwise reword the attributes. */
final class ReaderCookie extends Cookie {

  private string $line;

  public function __construct(ReaderProof $proof, string $value) {
    parent::__construct(ReaderProof::COOKIE, $value, time() + ReaderProof::TTL, '/', NULL, TRUE, TRUE, TRUE, self::SAMESITE_LAX);
    $this->line = $proof->setCookie($value);
  }

  public function __toString(): string {
    return $this->line;
  }

}
