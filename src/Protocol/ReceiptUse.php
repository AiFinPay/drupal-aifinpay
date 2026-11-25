<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Protocol;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;

/**
 * Tells AiFinPay how much of each receipt this site has spent.
 *
 * After every spend the receipt id (never the token) and the units left are
 * kept in aifinpay_receipt_use, the lowest per receipt. Cron sends them to
 * POST {api origin}/v1/merchants/{merchant_id}/receipt-use with
 * AIFP-Merchant-Secret, body {"uses": [{"receipt_id", "remaining"}]}, at most
 * 100 per call, ordered by receipt id: the bytes of the shared vectors
 * conformance/vectors/receipt-use-v1.json. Sent whenever the site has its
 * secret: accounting, not the report_gate_events opt-in.
 *
 * Outcomes: 200 with matching counts removes what was sent. 404 (an API
 * without the route), another 4xx or a redirect drops what is held and the
 * next spend records again. Network failure, 408, 429 or 5xx keep it for the
 * next cron run, at most five runs. Gate events are not touched.
 */
final class ReceiptUse {

  public const TABLE = 'aifinpay_receipt_use';

  public const BATCH = 100;

  public const MAX_HELD = 10000;

  public const MAX_ATTEMPTS = 5;

  /** A receipt id as the backend issues it and a whole number of units left. */
  public static function valid(mixed $receiptId, mixed $remaining): bool {
    return is_string($receiptId) && preg_match('/^rcpt_[0-9a-f]{16}$/D', $receiptId) === 1
      && is_int($remaining) && $remaining >= 0;
  }

  /** Keep the lowest remaining for this receipt. Never throws into the paid read. */
  public static function record(Connection $database, mixed $receiptId, mixed $remaining): void {
    if (!self::valid($receiptId, $remaining)) {
      return;
    }
    try {
      for ($attempt = 0; $attempt < 2; $attempt++) {
        $lowered = $database->update(self::TABLE)
          ->fields(['remaining' => $remaining, 'attempts' => 0])
          ->condition('receipt_id', $receiptId)
          ->condition('remaining', $remaining, '>')
          ->execute();
        if ($lowered > 0 || $database->select(self::TABLE, 'u')->condition('receipt_id', $receiptId)->countQuery()->execute()->fetchField() > 0) {
          return;
        }
        if ($database->select(self::TABLE, 'u')->countQuery()->execute()->fetchField() >= self::MAX_HELD) {
          return;
        }
        try {
          $database->insert(self::TABLE)->fields(['receipt_id' => $receiptId, 'remaining' => $remaining, 'attempts' => 0])->execute();
          return;
        }
        catch (IntegrityConstraintViolationException) {
          // A concurrent first record created the row; lower it instead.
        }
      }
    }
    catch (\Throwable) {
      // Accounting is best-effort; the paid read never waits on it.
    }
  }

  /**
   * The request bodies for what is held, receipt id ascending, BATCH per body.
   *
   * @param array<string, int> $held
   * @return list<string>
   */
  public static function bodies(array $held): array {
    ksort($held, SORT_STRING);
    $uses = [];
    foreach ($held as $id => $remaining) {
      $uses[] = ['receipt_id' => (string) $id, 'remaining' => (int) $remaining];
    }
    return array_map(static fn(array $chunk): string => json_encode(['uses' => $chunk], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
      array_chunk($uses, self::BATCH));
  }

  /**
   * One pass over what is held.
   *
   * @param callable(string): array{0: ?int, 1: string} $post
   *   Sends one body; returns the HTTP status (NULL when AiFinPay was not reached) and the start of the answer.
   */
  public static function flush(Connection $database, callable $post): void {
    $held = [];
    foreach ($database->select(self::TABLE, 'u')->fields('u', ['receipt_id', 'remaining'])->execute() as $row) {
      $held[(string) $row->receipt_id] = (int) $row->remaining;
    }
    foreach (self::bodies($held) as $body) {
      $items = json_decode($body, TRUE, 8, JSON_THROW_ON_ERROR)['uses'];
      try {
        [$status, $answer] = $post($body);
      }
      catch (\Throwable) {
        [$status, $answer] = [NULL, ''];
      }
      if ($status !== NULL && $status >= 200 && $status < 300 && self::acknowledged($answer, count($items))) {
        foreach ($items as $item) {
          $database->delete(self::TABLE)->condition('receipt_id', $item['receipt_id'])->condition('remaining', $item['remaining'])->execute();
        }
        continue;
      }
      if ($status === NULL || $status === 408 || $status === 429 || $status >= 500 || ($status >= 200 && $status < 300)) {
        $database->update(self::TABLE)->expression('attempts', 'attempts + 1')->execute();
        $database->delete(self::TABLE)->condition('attempts', self::MAX_ATTEMPTS, '>=')->execute();
        return;
      }
      // 404 from an older backend, any refusal or a redirect: drop what is held, try again after the next spend.
      $database->delete(self::TABLE)->execute();
      return;
    }
  }

  /** {"applied": n, "ignored": m} with n + m equal to what was sent. */
  private static function acknowledged(string $answer, int $count): bool {
    $ack = json_decode($answer, TRUE);
    return is_array($ack) && is_int($ack['applied'] ?? NULL) && is_int($ack['ignored'] ?? NULL)
      && $ack['applied'] >= 0 && $ack['ignored'] >= 0 && $ack['applied'] + $ack['ignored'] === $count;
  }

  /** The site's merchant and secret, or NULL when it has none: then nothing is sent. */
  public static function credentials(array $site): ?array {
    $merchant = (string) ($site['merchant_id'] ?? '');
    $secret = $site['secret'] ?? '';
    if (!preg_match('/^mrch_[a-z0-9_]{3,32}$/D', $merchant)
      || !is_string($secret) || strlen($secret) < 32 || preg_match('/[\x00-\x20\x7f]/', $secret)) {
      return NULL;
    }
    return [$merchant, $secret];
  }

  /** The cron sender: one POST through the pinned transport. */
  public static function poster(array $site, Transport $transport): callable {
    [$merchant, $secret] = self::credentials($site);
    return static function (string $body) use ($site, $transport, $merchant, $secret): array {
      $response = $transport->request($site, 'POST', '/v1/merchants/' . $merchant . '/receipt-use', [
        'headers' => ['AIFP-Merchant-Secret' => $secret, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
        'body' => $body,
        'timeout' => 4.0,
      ]);
      $status = $response->getStatusCode();
      try {
        $answer = $status >= 200 && $status < 300 ? Transport::read($response, 4096) : '';
      }
      catch (\Throwable) {
        $answer = '';
      }
      $response->getBody()->close();
      return [$status, $answer];
    };
  }

}
