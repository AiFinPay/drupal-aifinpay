<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Protocol;

use Drupal\Core\State\StateInterface;

/**
 * Sends closed traffic windows to POST {api origin}/v1/merchants/{id}/gate-traffic on cron.
 *
 * Its own state in Drupal State (STATE_KEY): at most three closed windows held,
 * each with its id and exact bytes, and a pause. Nothing is shared with gate
 * events or receipt use, and no answer here changes them. Per window that
 * closed, the SPEC's tick: hold it (or drop it during a pause), keep the newest
 * three, drop any whose end is more than an hour old, then send oldest first
 * until one is not accepted. 2xx: done. 404: drop all, pause 6 h. Another 3xx
 * or 4xx: drop all, pause 24 h. 408, 429, 5xx or no answer: keep it and send
 * the same bytes on the next cron run; nothing more is sent in this run.
 */
final class TrafficSender {

  public const STATE_KEY = 'aifinpay.traffic';

  public const HOLD = 3;

  public const STALE = 3600;

  public const PAUSE_404 = 21600;

  public const PAUSE_OTHER = 86400;

  public const TIMEOUT = 5.0;

  /** An empty sender state. */
  public static function fresh(): array {
    return ['held' => [], 'paused_until' => NULL];
  }

  /**
   * One tick of the sender.
   *
   * @param array{held: list<array{id: string, to: int, body: string}>, paused_until: ?int} $state
   * @param ?array{id: string, to: int, body: string} $closed
   *   The window that just closed, if it counted anything.
   * @param callable(string): ?int $post
   *   Sends one body; the HTTP status, or NULL when AiFinPay was not reached.
   *
   * @return array{0: array, 1: list<string>, 2: bool}
   *   The new state, the ids sent, and whether a send failed and is held for the next run.
   */
  public static function tick(array $state, ?array $closed, int $at, callable $post, bool $send = TRUE): array {
    $held = $state['held'];
    $paused = $state['paused_until'];
    if ($paused !== NULL && $at >= $paused) {
      $paused = NULL;
    }
    if ($closed !== NULL && $paused === NULL) {
      $held[] = $closed;
    }
    while (count($held) > self::HOLD) {
      array_shift($held);
    }
    $held = array_values(array_filter($held, static fn(array $w): bool => !($w['to'] < $at - self::STALE)));
    $sent = [];
    $retry = FALSE;
    while ($send && $paused === NULL && $held !== []) {
      $sent[] = $held[0]['id'];
      try {
        $status = $post($held[0]['body']);
      }
      catch (\Throwable) {
        $status = NULL;
      }
      if ($status !== NULL && $status >= 200 && $status < 300) {
        array_shift($held);
        continue;
      }
      if ($status === NULL || $status === 408 || $status === 429 || $status >= 500 || $status < 200) {
        $retry = TRUE;
        break;
      }
      $held = [];
      $paused = $at + ($status === 404 ? self::PAUSE_404 : self::PAUSE_OTHER);
    }
    return [['held' => $held, 'paused_until' => $paused], $sent, $retry];
  }

  /**
   * One cron run: closes the windows that ended and gives each its tick.
   *
   * @param callable(string): ?int $post
   */
  public static function run(TrafficStore $store, StateInterface $state, callable $post, int $now, string $mode, string $charge): void {
    $current = $state->get(self::STATE_KEY);
    $current = is_array($current) && is_array($current['held'] ?? NULL) && array_key_exists('paused_until', $current) ? $current : self::fresh();
    $windows = [];
    foreach ($store->closeDue($now, $mode, $charge) as $w) {
      $to = $w['opened'] + Traffic::WINDOW;
      $body = Traffic::body(['id' => Traffic::uuid4(), 'from' => Traffic::utc($w['opened']), 'to' => Traffic::utc($to),
        'mode' => $w['mode'], 'charge' => $w['charge'], 'sample' => 1], $w['tables']);
      if ($body !== NULL) {
        $windows[] = ['id' => json_decode($body, TRUE)['id'], 'to' => $to, 'body' => $body];
      }
    }
    $send = TRUE;
    foreach ($windows === [] ? [NULL] : $windows as $closed) {
      [$current, , $retry] = self::tick($current, $closed, $now, $post, $send);
      $send = $send && !$retry;
      $state->set(self::STATE_KEY, $current);
    }
  }

  /** The cron entry: closes, holds and sends under a lock; never throws. */
  public static function cron(array $site, array $config, int $now): void {
    $lock = \Drupal::lock();
    if (!$lock->acquire('aifinpay_traffic', 30.0)) {
      return;
    }
    try {
      $store = \Drupal::service('aifinpay.traffic_store');
      if (($config['traffic_enabled'] ?? TRUE) === FALSE || ReceiptUse::credentials($site) === NULL) {
        $store->clear();
        \Drupal::state()->delete(self::STATE_KEY);
        return;
      }
      self::run($store, \Drupal::state(), self::poster($site, \Drupal::service('aifinpay.transport')), $now,
        GateIdentity::mode($config), GateIdentity::charge($config));
    }
    catch (\Throwable) {
      // Traffic summaries never stop cron, gate events or receipt use.
    }
    finally {
      $lock->release('aifinpay_traffic');
    }
  }

  /**
   * The cron sender: one POST through the pinned transport, at most five seconds.
   *
   * @return callable(string): ?int
   */
  public static function poster(array $site, Transport $transport): callable {
    [$merchant, $secret] = ReceiptUse::credentials($site);
    return static function (string $body) use ($site, $transport, $merchant, $secret): ?int {
      try {
        $response = $transport->request($site, 'POST', '/v1/merchants/' . $merchant . '/gate-traffic', [
          'headers' => ['AIFP-Merchant-Secret' => $secret, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
          'body' => $body,
          'timeout' => self::TIMEOUT,
        ]);
        $status = $response->getStatusCode();
        $response->getBody()->close();
        return $status;
      }
      catch (\Throwable) {
        return NULL;
      }
    };
  }

}
