<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Plugin\QueueWorker;

use Drupal\aifinpay\Connect\SiteBinding;
use Drupal\aifinpay\Protocol\Transport;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Delivers SHADOW observations to AiFinPay's gate-events ingest.
 *
 * POST {api origin}/v1/merchants/{merchant_id}/gate-events with
 * AIFP-Merchant-Secret, body {"events": [...]}: the item being processed plus
 * up to 49 more claimed from the same queue, so a burst costs one call per 50
 * events instead of one each (the ingest allows 120 calls per two minutes per
 * merchant). The ingest deduplicates by event id, so a retry after a timeout
 * cannot double-count.
 *
 * Outcomes: 200 delivered. 429/5xx and network errors give the extra items
 * back and suspend the queue until the next cron run. Any other refusal
 * (bad event, unknown route pattern, wrong secret) rejects the whole batch,
 * so its events are then sent one by one; a refusal of a single event drops
 * it: retrying the same bytes cannot succeed.
 * An event older than 24 hours is dropped, because the ingest refuses it.
 */
#[QueueWorker(id: 'aifinpay_gate_events', title: new TranslatableMarkup('AiFinPay gate events'), cron: ['time' => 15])]
final class GateEvents extends QueueWorkerBase {

  private const KEYS = ['at', 'id', 'kind', 'operator', 'resource', 'ua_class', 'verified'];

  private const BATCH = 50;

  public function processItem($data): void {
    $site = SiteBinding::get();
    $merchant = (string) ($site['merchant_id'] ?? '');
    $secret = $site['secret'] ?? '';
    if (!preg_match('/^mrch_[a-z0-9_]{3,32}$/D', $merchant)
      || !is_string($secret) || strlen($secret) < 32 || preg_match('/[\x00-\x20\x7f]/', $secret)) {
      return;
    }
    if (!self::deliverable($data)) {
      return;
    }
    $queue = \Drupal::queue('aifinpay_gate_events');
    $extra = [];
    while (count($extra) < self::BATCH - 1 && is_object($item = $queue->claimItem(60))) {
      if (self::deliverable($item->data)) {
        $extra[] = $item;
      }
      else {
        $queue->deleteItem($item);
      }
    }
    $status = $this->send($site, $merchant, $secret, [$data, ...array_map(static fn(object $i): array => $i->data, $extra)]);
    if ($status === 200) {
      foreach ($extra as $item) {
        $queue->deleteItem($item);
      }
      return;
    }
    if ($status !== NULL && $status !== 429 && $status < 500 && $extra) {
      // The ingest refuses a whole batch for one bad event: settle these
      // events one by one now, so the bad one is dropped and does not come
      // back to spoil the next batch.
      foreach ($extra as $n => $item) {
        $one = $this->send($site, $merchant, $secret, [$item->data]);
        if ($one === NULL || $one === 429 || $one >= 500) {
          foreach (array_slice($extra, $n) as $rest) {
            $queue->releaseItem($rest);
          }
          $status = $one;
          break;
        }
        if ($one !== 200) {
          $this->dropped($one, $item->data);
        }
        $queue->deleteItem($item);
      }
      $extra = [];
      if ($status !== NULL && $status !== 429 && $status < 500) {
        $status = $this->send($site, $merchant, $secret, [$data]);
        if ($status === 200) {
          return;
        }
      }
    }
    foreach ($extra as $item) {
      $queue->releaseItem($item);
    }
    if ($status === NULL) {
      throw new SuspendQueueException('AiFinPay gate events unreachable; retry next cron.');
    }
    if ($status === 429 || $status >= 500) {
      throw new SuspendQueueException('AiFinPay gate events busy (' . $status . '); retry next cron.');
    }
    $this->dropped($status, $data);
  }

  private function dropped(int $status, array $data): void {
    \Drupal::logger('aifinpay')->warning('Gate event dropped: AiFinPay answered @status for @resource.', [
      '@status' => $status, '@resource' => (string) $data['resource'],
    ]);
  }

  /** Exactly the ingest's identity event, and young enough to be accepted. */
  private static function deliverable(mixed $data): bool {
    if (!is_array($data)) {
      return FALSE;
    }
    $keys = array_keys($data);
    sort($keys);
    $at = strtotime((string) ($data['at'] ?? ''));
    return $keys === self::KEYS && $at !== FALSE && time() - $at <= 86000;
  }

  /** The HTTP status of one ingest call, or NULL when AiFinPay was not reached. */
  private function send(array $site, string $merchant, string $secret, array $events): ?int {
    try {
      $transport = new Transport(\Drupal::httpClient());
      $response = $transport->request($site, 'POST', '/v1/merchants/' . $merchant . '/gate-events', [
        'headers' => ['AIFP-Merchant-Secret' => $secret, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
        'body' => json_encode(['events' => $events], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        'timeout' => 4.0,
      ]);
      $status = $response->getStatusCode();
      $response->getBody()->close();
      return $status;
    }
    catch (\Throwable) {
      return NULL;
    }
  }
}
