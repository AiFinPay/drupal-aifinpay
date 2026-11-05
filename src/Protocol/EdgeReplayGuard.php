<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Protocol;

use Drupal\Core\Database\Connection;

/** Atomic per-site nonce consumption shared by all origin workers. */
final class EdgeReplayGuard {

  public function __construct(private Connection $database) {}

  public function consume(array $context, int $ttl): bool {
    try {
      $this->database->insert('aifinpay_edge_nonces')->fields([
        'nonce_hash' => hash('sha256', $context['site_id'] . "\0" . $context['nonce']),
        'expires' => $context['ts'] + $ttl,
      ])->execute();
      return TRUE;
    }
    catch (\Throwable) {
      // Duplicate nonce, storage outage or missing migration all fail closed.
      return FALSE;
    }
  }

}
