<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Protocol;

use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** POST /__aifp/human {token}: Turnstile siteverify for this host, then the reader cookie. */
final class HumanCheck {

  public const PATH = '/__aifp/human';
  public const SITEVERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

  public function __construct(private ClientInterface $client) {}

  /** The official endpoint, or an explicit test-only loopback one. */
  public static function siteverifyUrl(array $site): string {
    $origin = $site['turnstile_origin'] ?? NULL;
    if (is_string($origin) && ($site['environment'] ?? '') === 'local' && !empty($site['local_contract'])
      && preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{3,4}$~D', $origin)) {
      return $origin . '/turnstile/v0/siteverify';
    }
    return self::SITEVERIFY;
  }

  public function respond(Request $request, string $secret, string $readerKey, array $site, int $now): Response {
    $plain = static fn(int $status, array $headers = []): Response => new Response('', $status, ['Cache-Control' => 'private, no-store, max-age=0'] + $headers);
    if ($request->getMethod() !== 'POST') {
      return $plain(405, ['Allow' => 'POST']);
    }
    try {
      $body = json_decode((string) $request->getContent(), TRUE, 4, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      $body = NULL;
    }
    $token = is_array($body) ? ($body['token'] ?? NULL) : NULL;
    if (!is_string($token) || $token === '' || strlen($token) > 2048) {
      return $plain(400);
    }
    $form = ['secret' => $secret, 'response' => $token];
    $ip = $request->getClientIp();
    if ($ip !== NULL) {
      $form['remoteip'] = $ip;
    }
    try {
      $answer = $this->client->request('POST', self::siteverifyUrl($site), [
        'form_params' => $form, 'connect_timeout' => 2.0, 'timeout' => 5.0, 'allow_redirects' => FALSE, 'http_errors' => FALSE,
      ]);
      $verdict = $answer->getStatusCode() === 200 ? json_decode(substr((string) $answer->getBody(), 0, 65536), TRUE, 8) : NULL;
    }
    catch (\Throwable) {
      $verdict = NULL;
    }
    if (!is_array($verdict) || ($verdict['success'] ?? NULL) !== TRUE || !is_string($verdict['hostname'] ?? NULL)
      || strtolower($verdict['hostname']) !== strtolower($request->getHost())) {
      return $plain(403);
    }
    $proof = new ReaderProof($readerKey);
    $response = $plain(204);
    $response->headers->setCookie(new ReaderCookie($proof, $proof->issue((string) $request->headers->get('User-Agent', ''), $now)));
    return $response;
  }

}
