<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Protocol;

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/** Audited AIFP endpoints, pinned origins; no redirects or automatic retries. */
final class Transport {

  public function __construct(private ClientInterface $client) {}

  public static function origin(array $site, string $kind): string {
    $value = $site[$kind . '_origin'] ?? '';
    $official = $kind === 'gateway' ? 'https://gateway.aifinpay.io' : 'https://api.aifinpay.io';
    if ($value === $official) {
      return $value;
    }
    // Explicit test-only loopback network. Never selectable in exported config.
    if (($site['environment'] ?? '') === 'local' && !empty($site['local_contract'])
      && is_string($value) && preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{3,4}$~D', $value)) {
      return $value;
    }
    throw new \RuntimeException('unapproved_origin');
  }

  public function request(array $site, string $method, string $path, array $options = [], string $kind = 'api'): ResponseInterface {
    if (!in_array($method, ['GET', 'HEAD', 'POST'], TRUE) || !str_starts_with($path, '/') || str_contains($path, '?')) {
      throw new \RuntimeException('invalid_request');
    }
    return $this->client->request($method, self::origin($site, $kind) . $path, $options + [
      'connect_timeout' => 0.5, 'timeout' => 8.0, 'allow_redirects' => FALSE,
      'http_errors' => FALSE, 'verify' => TRUE, 'stream' => TRUE,
    ]);
  }

  public static function read(ResponseInterface $response, int $limit = 65536): string {
    $body = $response->getBody();
    try {
      if ((int) $response->getHeaderLine('Content-Length') > $limit) {
        throw new \RuntimeException('response_too_large');
      }
      $raw = '';
      $deadline = hrtime(TRUE) + 8000000000;
      while (!self::finished($body)) {
        if (hrtime(TRUE) > $deadline) {
          throw new \RuntimeException('response_timeout');
        }
        $chunk = $body->read(min(8192, $limit + 1 - strlen($raw)));
        if ($chunk === '' && !self::finished($body)) {
          throw new \RuntimeException('incomplete_response');
        }
        $raw .= $chunk;
        if (strlen($raw) > $limit) {
          throw new \RuntimeException('response_too_large');
        }
      }
      return $raw;
    }
    finally {
      $body->close();
    }
  }

  /**
   * Stream reads mutate EOF state, including reads made by callers.
   *
   * @phpstan-impure
   */
  private static function finished(\Psr\Http\Message\StreamInterface $body): bool {
    return $body->eof();
  }

  public function json(array $site, string $path, bool $authenticated = TRUE): array {
    $headers = ['Accept' => 'application/json'];
    if ($authenticated) {
      $secret = $site['secret'] ?? '';
      if (!is_string($secret) || strlen($secret) < 32 || preg_match('/[\x00-\x20\x7f]/', $secret)) {
        throw new \RuntimeException('credential_missing');
      }
      $headers['AIFP-Merchant-Secret'] = $secret;
    }
    $response = $this->request($site, 'GET', $path, ['headers' => $headers]);
    if ($response->getStatusCode() !== 200) {
      $response->getBody()->close();
      throw new \RuntimeException('api_unavailable');
    }
    $data = json_decode(self::read($response), TRUE, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
      throw new \RuntimeException('invalid_api_response');
    }
    return $data;
  }

}
