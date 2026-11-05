<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Middleware;

use Drupal\aifinpay\Connect\MerchantRecord;
use Drupal\aifinpay\Connect\SiteBinding;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\aifinpay\Policy\Paths;
use Drupal\aifinpay\Protocol\AgentIdentity;
use Drupal\aifinpay\Protocol\Discovery;
use Drupal\aifinpay\Protocol\EdgeContext;
use Drupal\aifinpay\Protocol\EdgeReplayGuard;
use Drupal\aifinpay\Protocol\HostedGateway;
use Drupal\aifinpay\Protocol\ReceiptGate;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/** Origin enforcement. AI traffic classification is performed at Acquia Edge. */
final class PaymentMiddleware implements HttpKernelInterface {

  public function __construct(
    private HttpKernelInterface $httpKernel,
    private ConfigFactoryInterface $configFactory,
    private ClientInterface $client,
    private QueueFactory $queue,
    private LoggerChannelFactoryInterface $loggerFactory,
    private ?HostedGateway $gateway = NULL,
    private ?EdgeReplayGuard $edgeReplay = NULL,
    private ?ReceiptGate $receiptGate = NULL,
    private ?MerchantRecord $merchantRecord = NULL,
  ) {}

  public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
    $config = $this->configFactory->get('aifinpay.settings')->getRawData();
    $response = $this->gate($request, $type, $catch, $config);
    if ($type === self::MAIN_REQUEST && !empty($config['enabled']) && ($config['mode'] ?? '') !== 'OFF') {
      $this->announce($response, $config);
    }
    return $response;
  }

  private function gate(Request $request, int $type, bool $catch, array $config): Response {
    $identity = SiteBinding::get();
    $proxyAuthPresent = $request->headers->has('AIFP-Proxy-Auth');
    $gatewayOrigin = $proxyAuthPresent && HostedGateway::originAuthorized($request, $identity);
    if ($type === self::MAIN_REQUEST && $proxyAuthPresent && !$gatewayOrigin) {
      return $this->deny(403, 'origin_auth_failed');
    }
    if ($type !== self::MAIN_REQUEST || empty($config['enabled']) || ($config['mode'] ?? '') === 'OFF') {
      return $this->httpKernel->handle($request, $type, $catch);
    }
    $document = $this->discoveryDocument($request, $config);
    if ($document !== NULL) {
      return $document;
    }
    $mode = $config['mode'] ?? 'SHADOW';
    $path = $request->getPathInfo();
    $rule = Paths::protected($path, $config);
    if ($mode === 'ENFORCE' && (preg_match('/%|\\\\|\/\.|\/\//', $path))) {
      return $this->deny(400, 'noncanonical_path');
    }
    if ($rule === NULL) {
      return $this->httpKernel->handle($request, $type, $catch);
    }

    $start = hrtime(TRUE);
    $trace = bin2hex(random_bytes(16));
    $bound = ($identity['site_id'] ?? NULL) === ($config['site_id'] ?? '')
      && ($identity['merchant_id'] ?? NULL) === ($config['merchant_id'] ?? '')
      && ($identity['secret_reference'] ?? NULL) === ($config['secret_reference'] ?? '');
    $meoSite = $_ENV['AH_DRUPAL_SITE_NAME'] ?? NULL;
    if ($meoSite !== NULL && $meoSite !== ($identity['meo_site_name'] ?? NULL)) {
      $bound = FALSE;
    }

    $edge = EdgeContext::verify($request, $identity);
    $classification = is_array($edge) ? (string) ($edge['classification'] ?? 'UNKNOWN') : 'UNKNOWN';
    $edgeDecision = is_array($edge) ? (string) ($edge['decision'] ?? '') : '';

    if ($mode === 'SHADOW') {
      $response = $this->httpKernel->handle($request, $type, $catch);
      // Visible from outside without telemetry: the rule matched and the
      // middleware ran on this host. Nothing about the rule or site leaks.
      $response->headers->set('X-AiFinPay-Mode', 'shadow');
      $response->headers->set('X-AiFinPay-Trace', $trace);
      // Shadow measures who asks for a protected path. A shared cache in
      // front of Drupal (Acquia Varnish kept these pages for 900 s, keyed by
      // URL, not by agent) would answer the next agent itself, so the request
      // would never reach this middleware. Protected paths are never stored by
      // a shared cache, exactly as in ENFORCE; other paths are untouched.
      $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
      // Without Edge, who asked comes from the request's own declaration (the
      // same table as @aifinpay/gate). The requester learns only what its own
      // User-Agent already says.
      $who = AgentIdentity::classify($request);
      $response->headers->set('X-AiFinPay-Agent', $who['operator'] . '; ' . $who['ua_class']);
      if ($who['ua_class'] !== 'human') {
        // What ENFORCE would have answered, as the gate SDK says it in shadow.
        $response->headers->set('AIFP-Shadow-Status', '402');
      }
      $this->record($config, $rule, 'shadow', $classification, $trace, $start, $edge);
      // An agent on a protected path is what ENFORCE would have answered with a
      // 402; report it exactly as the gate SDK does in shadow. Humans are not
      // reported: the report is about agent traffic.
      if ($who['ua_class'] !== 'human') {
        $this->reportGateEvent($config, $identity, $bound, $rule, $who);
      }
      return $response;
    }

    // Bindings and approval apply before any HUMAN bypass, including gateway callbacks.
    $mock = ($config['verification'] ?? '') === 'mock' && ($identity['local_mock'] ?? FALSE) === TRUE
      && ($identity['environment'] ?? '') === 'local';
    $receipt = ($config['verification'] ?? '') === 'receipt';
    if ($receipt && $this->merchantRecord !== NULL
      && (!array_key_exists('trusted_hosts', $identity) || !array_key_exists('receipt_gate_approved', $identity))) {
      $identity = MerchantRecord::resolve($identity, $this->merchantRecord->get($identity));
    }
    if (!$bound || !in_array($request->getHost(), $identity['trusted_hosts'] ?? [], TRUE)
      || ($receipt ? !ReceiptGate::approved($identity)
        : ($mock ? ($identity['enforce_approved'] ?? FALSE) !== TRUE : !HostedGateway::approved($identity)))) {
      return $this->deny(503, 'enforcement_not_approved');
    }
    if (($identity['edge_required'] ?? FALSE) === TRUE && !$gatewayOrigin) {
      if (!HostedGateway::edgeReady($identity)) {
        return $this->deny(503, 'edge_not_validated');
      }
      if ($edge === NULL) {
        return $this->deny(403, 'trusted_edge_context_required');
      }
      if (($edge['rule'] ?? NULL) !== $rule || ($edge['trusted'] ?? FALSE) !== TRUE || $classification === 'UNKNOWN'
        || $edgeDecision === 'OBSERVE'
        || ($edgeDecision === 'BYPASS' && $classification !== 'HUMAN')
        || ($edgeDecision === 'MONETIZE' && !in_array($classification, ['AI_AGENT', 'AUTOMATION'], TRUE))) {
        return $this->deny(403, 'invalid_edge_decision');
      }
      if ($this->edgeReplay === NULL || !$this->edgeReplay->consume($edge, (int) ($identity['edge_context_ttl'] ?? 90))) {
        return $this->deny(403, 'edge_context_replay');
      }
      // Do not leave private trusted context visible to downstream controllers.
      $request->headers->remove('X-AiFinPay-Edge-Context');
      $request->headers->remove('X-AiFinPay-Edge-Signature');
      if ($edgeDecision === 'BYPASS') {
        $this->record($config, $rule, 'edge_bypass', $classification, $trace, $start, $edge);
        return $this->httpKernel->handle($request, $type, $catch);
      }
    }

    if ($receipt) {
      return $this->receiptGate($request, $type, $catch, $config, $identity, $rule, $bound, $trace);
    }

    if (($config['verification'] ?? '') === 'hosted_gateway') {
      if ($mode !== 'ENFORCE' || !HostedGateway::approved($identity)
        || ($config['api_endpoint'] ?? '') !== ($identity['api_origin'] ?? NULL)
        || !in_array($request->getHost(), $identity['trusted_hosts'] ?? [], TRUE)) {
        return $this->deny(503, 'gateway_not_approved');
      }
      if (!in_array($request->getMethod(), ['GET', 'HEAD'], TRUE) || $request->getQueryString() !== NULL) {
        return $this->deny(400, 'unsupported_gateway_resource');
      }
      if ($gatewayOrigin) {
        $request->headers->remove('AIFP-Proxy-Auth');
        $response = $this->httpKernel->handle($request, $type, $catch);
      }
      else {
        try {
          $response = $this->gateway->forward($request, $identity);
        }
        catch (\Throwable) {
          $this->loggerFactory->get('aifinpay')->warning('Hosted gateway unavailable. Trace: @trace', ['@trace' => $trace]);
          $response = $this->deny(503, 'gateway_unavailable');
        }
      }
      $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
      $response->headers->set('X-AiFinPay-Trace', $trace);
      $this->record($config, $rule, 'hosted', $classification, $trace, $start, $edge);
      return $response;
    }

    if ($mode !== 'ENFORCE' || empty($identity['enforce_approved'])
      || ($identity['environment'] ?? 'production') === 'production'
      || ($config['verification'] ?? '') !== 'mock' || empty($identity['local_mock'])) {
      return $this->deny(503, 'enforcement_not_approved');
    }
    if (!in_array($request->getMethod(), ['GET', 'HEAD'], TRUE)) {
      return $this->deny(405, 'read_only_resource');
    }
    $endpoint = $config['api_endpoint'] ?? '';
    if ($endpoint !== ($identity['api_endpoint'] ?? NULL) || !preg_match('~^https?://[^/?#@]+$~D', $endpoint)) {
      return $this->deny(503, 'invalid_api_origin');
    }
    if (!in_array($request->getHost(), $identity['trusted_hosts'] ?? [], TRUE)) {
      return $this->deny(400, 'untrusted_host');
    }
    if ($request->getQueryString() !== NULL) {
      return $this->deny(400, 'query_resources_not_supported');
    }
    $proof = $request->headers->get('PAYMENT-SIGNATURE', '');
    if (strlen($proof) > 8192) {
      return $this->deny(400, 'proof_too_large');
    }
    $secret = $identity['secret'] ?? '';
    if (strlen($secret) < 32) {
      return $this->deny(503, 'credential_missing');
    }
    try {
      $api = $this->client->request('POST', $endpoint . '/integration/v1/decision', [
        'headers' => ['Authorization' => 'Bearer ' . $secret],
        'json' => ['site_id' => $identity['site_id'], 'merchant_id' => $identity['merchant_id'],
          'resource' => $path, 'proof' => $proof, 'trace_id' => $trace],
        'connect_timeout' => 0.5, 'timeout' => 2.0, 'allow_redirects' => FALSE,
        'http_errors' => FALSE,
      ]);
      if ($api->getStatusCode() !== 200 || (int) $api->getHeaderLine('Content-Length') > 32768) {
        throw new \RuntimeException('adapter_unavailable');
      }
      $raw = $api->getBody()->read(32769);
      if (strlen($raw) > 32768) {
        throw new \RuntimeException('adapter_response_too_large');
      }
      $decision = json_decode($raw, TRUE, 32, JSON_THROW_ON_ERROR);
      $status = $decision['status'] ?? 0;
      if (!in_array($status, [200, 402, 403, 429, 503], TRUE)) {
        throw new \RuntimeException('invalid_decision');
      }
      if ($status === 200) {
        $response = $this->httpKernel->handle($request, $type, $catch);
      }
      else {
        $response = new JsonResponse($decision['body'] ?? ['error' => 'denied'], $status);
      }
      foreach (['PAYMENT-REQUIRED', 'PAYMENT-RESPONSE'] as $header) {
        $value = $decision['headers'][$header] ?? NULL;
        if (is_string($value) && strlen($value) <= 8192 && preg_match('~^[A-Za-z0-9+/=]*$~D', $value)) {
          $response->headers->set($header, $value);
        }
      }
      $this->record($config, $rule, (string) $status, $classification, $trace, $start, $edge);
    }
    catch (\Throwable) {
      $this->loggerFactory->get('aifinpay')->warning('Adapter unavailable. Trace: @trace', ['@trace' => $trace]);
      $response = $this->deny(503, 'payment_service_unavailable');
    }
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    $response->headers->set('X-AiFinPay-Trace', $trace);
    $response->headers->set('Server-Timing', 'aifinpay;dur=' . round((hrtime(TRUE) - $start) / 1e6, 3));
    return $response;
  }

  /** ENFORCE with the self-hosted gate: 402, local receipt check, metering here. */
  private function receiptGate(Request $request, int $type, bool $catch, array $config, array $identity, string $rule, bool $bound, string $trace): Response {
    $tier = (string) ($config['tier'] ?? 'standard');
    if ($this->receiptGate === NULL || !isset(ReceiptGate::TIER_WEIGHTS[$tier])
      || !preg_match('/^mrch_[a-z0-9_]{3,32}$/D', (string) ($identity['merchant_id'] ?? ''))) {
      return $this->deny(503, 'gate_not_configured');
    }
    try {
      $decision = $this->receiptGate->decide($request, $identity, $rule, $tier, !empty($config['exempt_humans']), time());
    }
    catch (\Throwable) {
      $this->loggerFactory->get('aifinpay')->warning('Receipt gate unavailable. Trace: @trace', ['@trace' => $trace]);
      return $this->deny(503, 'payment_service_unavailable');
    }
    $response = $decision['status'] === 200
      ? $this->httpKernel->handle($request, $type, $catch)
      : new JsonResponse($decision['body'], $decision['status']);
    foreach ($decision['headers'] as $name => $value) {
      $response->headers->set($name, $value);
    }
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    $response->headers->set('X-AiFinPay-Trace', $trace);
    $this->logDecision($config, $decision, $rule, $trace);
    if ($decision['event'] !== NULL) {
      $event = $decision['event'];
      $this->reportGateEvent($config, $identity, $bound, $rule, $event, $event['kind'], (bool) ($event['verified'] ?? FALSE));
    }
    return $response;
  }

  /**
   * One log line per gate decision, keyed by the trace the response carries:
   * a paid read names its receipt, quote and transaction, so Drupal's log
   * joins the payment chain. No IP, User-Agent or receipt token. Readers
   * served free are not logged.
   */
  private function logDecision(array $config, array $decision, string $rule, string $trace): void {
    $status = $decision['status'];
    $logger = $this->loggerFactory->get('aifinpay');
    $notice = ($config['logging_level'] ?? 'notice') !== 'warning';
    if (isset($decision['receipt'])) {
      $r = $decision['receipt'];
      if ($notice) {
        $logger->notice('Paid read of @resource: receipt @receipt, quote @quote, @chain tx @tx (@mode), @remaining units left. Trace @trace.', [
          '@resource' => $rule, '@receipt' => $r['receipt_id'] ?? '?', '@quote' => $r['quote_id'] ?? '?', '@chain' => $r['chain'] ?? '?',
          '@tx' => $r['tx_ref'] ?? '?', '@mode' => $r['network_mode'] ?? '?', '@remaining' => $r['remaining'], '@trace' => $trace,
        ]);
      }
    }
    elseif ($status === 402 && $notice) {
      $logger->notice('402 for @resource (@operator; @class): @detail. Trace @trace.', [
        '@resource' => $rule, '@operator' => $decision['event']['operator'] ?? '?', '@class' => $decision['event']['ua_class'] ?? '?',
        '@detail' => $decision['body']['detail'] ?? '', '@trace' => $trace,
      ]);
    }
    elseif ($status !== 200) {
      $logger->warning('Refused @status for @resource: @detail. Trace @trace.', [
        '@status' => $status, '@resource' => $rule, '@detail' => $decision['body']['detail'] ?? $decision['body']['error'] ?? '', '@trace' => $trace,
      ]);
    }
  }

  /** GET /llms.txt and /.well-known/x402.json, built from this site's configuration. */
  private function discoveryDocument(Request $request, array $config): ?Response {
    $path = $request->getPathInfo();
    if (!in_array($request->getMethod(), ['GET', 'HEAD'], TRUE) || !($config['discovery_documents'] ?? TRUE)
      || ($path !== Discovery::LLMS_TXT_PATH && $path !== Discovery::X402_PATH)) {
      return NULL;
    }
    $headers = ['Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff'];
    if ($path === Discovery::LLMS_TXT_PATH) {
      $site = (string) $this->configFactory->get('system.site')->get('name');
      $body = Discovery::llmsTxt($config, $site, $request->getSchemeAndHttpHost());
      $response = new Response($body, 200, ['Content-Type' => 'text/markdown; charset=utf-8'] + $headers);
    }
    else {
      $merchant = Discovery::merchantId($config);
      if ($merchant === NULL) {
        return NULL;
      }
      $document = Discovery::x402Document($merchant, Discovery::resources($config), (string) ($config['api_endpoint'] ?? 'https://api.aifinpay.io'));
      $response = new JsonResponse($document, 200, $headers);
    }
    return $response->prepare($request);
  }

  /** Every response says the gate is here; every HTML page points at the agent documents. */
  private function announce(Response $response, array $config): void {
    if (($config['mode'] ?? 'SHADOW') === 'SHADOW') {
      $response->headers->set('AIFP-Mode', 'shadow');
    }
    if (!($config['discovery_link'] ?? TRUE) || !Discovery::isHtml($response->headers->get('Content-Type'))) {
      return;
    }
    $x402 = ($config['discovery_documents'] ?? TRUE) && Discovery::merchantId($config) !== NULL ? Discovery::X402_PATH : FALSE;
    $link = Discovery::linkHeader(Discovery::LLMS_TXT_PATH, $x402);
    if ($link !== NULL && !str_contains(implode(', ', $response->headers->all('link')), $link)) {
      $response->headers->set('Link', $link, FALSE);
    }
  }

  private function deny(int $status, string $error): Response {
    return new JsonResponse(['error' => $error], $status, ['Cache-Control' => 'private, no-store, max-age=0']);
  }

  private function record(array $config, string $rule, string $kind, string $classification, string $trace, int $start, ?array $edge = NULL): void {
    if (empty($config['telemetry_enabled']) || random_int(1, 100) > (int) ($config['telemetry_sample'] ?? 0)) {
      return;
    }
    try {
      $queue = $this->queue->get('aifinpay_telemetry');
      if ($queue->numberOfItems() < 1000) {
        $queue->createItem(['site_id' => $config['site_id'], 'resource' => $rule, 'kind' => $kind,
          'classification' => $classification, 'edge_source' => $edge['source'] ?? NULL,
          'edge_decision' => $edge['decision'] ?? NULL, 'trace_id' => $trace, 'at' => time(),
          'simulated' => TRUE, 'latency_ms' => round((hrtime(TRUE) - $start) / 1e6, 3)]);
      }
    }
    catch (\Throwable) {
      // Bounded, best-effort measurements cannot break content delivery.
    }
  }

  /**
   * Queue one AiFinPay gate event (POST /v1/merchants/{id}/gate-events).
   *
   * Only when the site opted in (report_gate_events) and its server-side
   * identity is bound to the configured merchant, so a config edit alone can
   * never send events under someone else's credential. The wire shape is the
   * ingest's identity event: id, kind, resource, at, operator, ua_class,
   * verified, nothing else. The raw User-Agent, IP and headers stay here.
   */
  private function reportGateEvent(array $config, array $identity, bool $bound, string $rule, array $who, string $kind = '402', bool $verified = FALSE): void {
    if (empty($config['report_gate_events']) || !$bound || empty($identity['merchant_id'])) {
      return;
    }
    try {
      $queue = $this->queue->get('aifinpay_gate_events');
      if ($queue->numberOfItems() < 5000) {
        $queue->createItem([
          'id' => self::uuid4(),
          'kind' => $kind,
          'resource' => $rule,
          'at' => gmdate('Y-m-d\TH:i:s\Z'),
          'operator' => $who['operator'],
          'ua_class' => $who['ua_class'],
          'verified' => $verified,
        ]);
      }
    }
    catch (\Throwable) {
      // Reporting is best-effort; content delivery never waits on it.
    }
  }

  private static function uuid4(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
  }

}

