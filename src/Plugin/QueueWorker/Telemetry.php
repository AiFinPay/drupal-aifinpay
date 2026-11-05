<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Plugin\QueueWorker;

use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[QueueWorker(id: 'aifinpay_telemetry', title: new TranslatableMarkup('AiFinPay anonymous mock telemetry'), cron: ['time' => 10])]
final class Telemetry extends QueueWorkerBase {

  public function processItem($data): void {
    $site = Settings::get('aifinpay_site', []);
    if (empty($site['local_mock']) || ($site['environment'] ?? 'production') === 'production' || time() - ($data['at'] ?? 0) > 86400) {
      return;
    }
    if (($data['site_id'] ?? '') !== ($site['site_id'] ?? '')) {
      return;
    }
    try {
      $response = \Drupal::httpClient()->post($site['api_endpoint'] . '/integration/v1/events', [
        'headers' => ['Authorization' => 'Bearer ' . $site['secret']], 'json' => $data,
        'timeout' => 2, 'connect_timeout' => 0.5, 'allow_redirects' => FALSE,
      ]);
      if ($response->getStatusCode() !== 202) {
        throw new \RuntimeException();
      }
    }
    catch (\Throwable) {
      throw new SuspendQueueException('Mock telemetry unavailable; retry next cron.');
    }
  }

}
