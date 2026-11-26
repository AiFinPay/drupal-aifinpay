<?php

declare(strict_types=1);

namespace Drupal\Tests\aifinpay\Kernel;

use Drupal\aifinpay\Middleware\PaymentMiddleware;
use Drupal\aifinpay\Plugin\QueueWorker\GateEvents;
use Drupal\aifinpay\Protocol\GateIdentity;
use Drupal\aifinpay\Protocol\ReceiptUse;
use Drupal\aifinpay\Protocol\Traffic;
use Drupal\aifinpay\Protocol\TrafficSender;
use Drupal\aifinpay\Protocol\TrafficStore;
use Drupal\aifinpay\Protocol\Transport;
use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\aifinpay\Unit\TrafficTest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The tally in the database, the gate's counting, the identity on the wire and the sender on cron.
 */
#[Group('aifinpay')]
#[RunTestsInSeparateProcesses]
final class TrafficKernelTest extends KernelTestBase {

  protected static $modules = ['system', 'aifinpay'];

  private const SECRET = 'sec_0123456789abcdef0123456789abcdef';

  /** @var list<RequestInterface> */
  private array $sent = [];

  /** @var array<string, int> Status per API path suffix; anything else gets 200. */
  private array $answers = [];

  /** @var list<callable> Writes put off until the end of the request. */
  private array $deferred = [];

  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('aifinpay', ['aifinpay_traffic', 'aifinpay_traffic_windows', 'aifinpay_receipt_use', 'aifinpay_receipt_units', 'aifinpay_actions', 'aifinpay_edge_nonces']);
    $this->installConfig(['aifinpay']);
    $this->setSetting('aifinpay_site', [
      'merchant_id' => 'mrch_news_1', 'secret' => self::SECRET, 'site_id' => 'news', 'secret_reference' => 'AIFP_NEWS_SECRET',
      'api_origin' => 'https://api.aifinpay.io', 'environment' => 'sandbox', 'trusted_hosts' => ['localhost'],
      'receipt_gate_approved' => TRUE, 'network_mode' => 'test',
    ]);
    $this->config('aifinpay.settings')->setData([
      'enabled' => TRUE, 'mode' => 'SHADOW', 'verification' => 'receipt', 'merchant_id' => 'mrch_news_1', 'site_id' => 'news',
      'secret_reference' => 'AIFP_NEWS_SECRET', 'protected_paths' => ['/paid/*'], 'exempt_humans' => TRUE, 'free_readers' => 'named',
      'report_gate_events' => TRUE, 'tier' => 'standard', 'traffic_network_header' => 'X-Client-ASN',
    ] + $this->config('aifinpay.settings')->getRawData())->save();
    $handler = function (RequestInterface $request, array $options) {
      $this->sent[] = $request;
      $path = $request->getUri()->getPath();
      foreach ($this->answers as $suffix => $status) {
        if (str_ends_with($path, $suffix)) {
          return Create::promiseFor(new Psr7Response($status));
        }
      }
      $body = str_ends_with($path, '/receipt-use') ? '{"applied":1,"ignored":0}' : '{}';
      return Create::promiseFor(new Psr7Response(200, ['Content-Type' => 'application/json'], $body));
    };
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create($handler)]));
    $this->container->get('aifinpay.traffic_subscriber')->setDefer(function (callable $write): void {
      $this->deferred[] = $write;
    });
  }

  private function store(): TrafficStore {
    return $this->container->get('aifinpay.traffic_store');
  }

  private function middleware(): PaymentMiddleware {
    $inner = new class implements HttpKernelInterface {

      public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
        return new Response('<html><body>page ' . htmlspecialchars($request->getPathInfo()) . '</body></html>', 200, ['Content-Type' => 'text/html; charset=utf-8']);
      }

    };
    return new PaymentMiddleware($inner, $this->container->get('config.factory'), $this->container->get('http_client'), $this->container->get('queue'),
      $this->container->get('logger.factory'), NULL, NULL, $this->container->get('aifinpay.receipt_gate'), $this->container->get('aifinpay.merchant_record'),
      NULL, NULL, NULL, $this->container->get('aifinpay.traffic_subscriber'));
  }

  /** One request through the gate, kernel.terminate when Drupal sends it, then the shutdown functions. */
  private function visit(string $path, array $headers, bool $terminate = TRUE): Response {
    $request = Request::create('http://localhost' . $path);
    foreach ($headers as $name => $value) {
      $request->headers->set($name, $value);
    }
    $response = $this->middleware()->handle($request);
    if ($terminate) {
      $kernel = $this->createMock(HttpKernelInterface::class);
      $this->container->get('event_dispatcher')->dispatch(new TerminateEvent($kernel, $request, $response), KernelEvents::TERMINATE);
    }
    foreach ($this->deferred as $write) {
      $write();
    }
    $this->deferred = [];
    return $response;
  }

  /** What the gate counted, closed now as one window. */
  private function counted(): ?array {
    $closed = $this->store()->closeDue(time() + 3600, 'shadow', 'agents');
    $tables = [];
    foreach ($closed as $w) {
      foreach ($w['tables'] as $name => $rows) {
        $tables[$name] = array_merge($tables[$name] ?? [], $rows);
      }
    }
    $body = Traffic::body(['id' => 'x', 'from' => 'f', 'to' => 't', 'mode' => 'shadow', 'charge' => 'agents', 'sample' => 1], $tables);
    return $body === NULL ? NULL : json_decode($body, TRUE);
  }

  private static function comparable(Response $response): array {
    $headers = $response->headers->all();
    unset($headers['x-aifinpay-trace'], $headers['date']);
    return [$response->getStatusCode(), $headers, $response->getContent()];
  }

  public function testBuildVectorsThroughTheDatabase(): void {
    $vectors = TrafficTest::vectors();
    foreach ([...$vectors['build'], ...$vectors['edge_counted']['build']] as $vector) {
      if ($vector['window']['sample'] !== 1 || $vector['name'] === 'clamp') {
        continue;
      }
      $from = strtotime($vector['window']['from']);
      foreach ($vector['requests'] as $r) {
        if (Traffic::edgeCounted($r['edge_counted'] ?? [])) {
          continue;
        }
        $record = ['target' => $r['target'], 'ua_class' => $r['ua_class'], 'operator' => $r['operator'], 'outcome' => $r['outcome']];
        if ($vector['platform']['country']) {
          $record['country'] = $r['country'];
        }
        if ($vector['platform']['network']) {
          $record['network'] = $r['network'];
        }
        for ($i = 0; $i < ($r['n'] ?? 1); $i++) {
          $this->store()->record($record, $vector['window']['mode'], $vector['window']['charge'], $from + intdiv($i, 100));
        }
      }
      $this->assertSame([], $this->store()->closeDue($from + 299, 'shadow', 'everyone'), $vector['name'] . ': closed early');
      $closed = $this->store()->closeDue($from + 300, 'shadow', 'everyone');
      $tables = $closed[0]['tables'] ?? [];
      $this->assertSame($vector['body'], Traffic::body($vector['window'], $tables), $vector['name']);
      if ($closed !== []) {
        $this->assertSame([$from, $vector['window']['mode'], $vector['window']['charge']], [$closed[0]['opened'], $closed[0]['mode'], $closed[0]['charge']]);
      }
      $this->store()->clear();
    }
  }

  public function testDistinctSectionsAndNetworksAreBounded(): void {
    $now = 1_800_000_000;
    for ($i = 0; $i < TrafficStore::MAX_DISTINCT + 5; $i++) {
      $this->store()->record(['target' => '/s' . $i . 'x/page', 'ua_class' => 'ai_crawler', 'operator' => 'openai', 'outcome' => 'challenged', 'network' => 'AS' . ($i + 1)], 'shadow', 'agents', $now);
    }
    $db = $this->container->get('database');
    foreach (['sections', 'networks'] as $table) {
      $values = $db->select(TrafficStore::COUNTS, 'c')->fields('c', ['v'])->condition('tbl', $table)->execute()->fetchCol();
      $this->assertCount(TrafficStore::MAX_DISTINCT + 1, $values, $table);
      $this->assertContains('_other', $values);
      $other = $db->select(TrafficStore::COUNTS, 'c')->fields('c', ['n'])->condition('tbl', $table)->condition('v', '_other')->execute()->fetchField();
      $this->assertSame(5, (int) $other);
    }
  }

  public function testTwoWorkersRacingLoseAndDoubleNothing(): void {
    $start = 1_800_000_000;
    $info = Database::getConnectionInfo('default')['default'];
    $autoload = dirname((new \ReflectionClass(Client::class))->getFileName(), 4) . '/autoload.php';
    $worker = dirname(__DIR__, 2) . '/fixtures/count-worker.php';
    $processes = [];
    foreach ([1, 2] as $n) {
      $processes[$n] = proc_open([PHP_BINARY, $worker, $autoload, DRUPAL_ROOT, json_encode($info), '400', (string) $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$n]);
    }
    foreach ($processes as $n => $process) {
      $out = stream_get_contents($pipes[$n][1]) . stream_get_contents($pipes[$n][2]);
      $this->assertSame(0, proc_close($process), $out);
    }
    $closed = $this->store()->closeDue($start + 3000, 'shadow', 'agents');
    $this->assertCount(2, $closed, 'one window per five minutes, whoever opened it');
    $total = 0;
    foreach ($closed as $w) {
      foreach ($w['tables']['classes'] as [, $n]) {
        $total += $n;
      }
    }
    $this->assertSame(800, $total);
  }

  public function testCountsLandingWhileClosingAreKeptForTheNextRun(): void {
    $store = $this->store();
    $record = ['target' => '/blog/a', 'ua_class' => 'ai_crawler', 'operator' => 'openai', 'outcome' => 'challenged'];
    $store->record($record, 'shadow', 'agents', 1_800_000_000);
    $first = $store->closeDue(1_800_000_300, 'shadow', 'agents');
    // A worker that read the window before it closed writes after.
    $this->container->get('database')->insert(TrafficStore::COUNTS)->fields(['opened' => 1_800_000_000, 'tbl' => 'classes', 'v' => 'ai_crawler', 'rest' => 'challenged', 'n' => 1])->execute();
    $late = $store->closeDue(1_800_000_400, 'shadow', 'agents');
    $this->assertSame([[['ai_crawler', 'challenged'], 1]], $first[0]['tables']['classes']);
    $this->assertSame([[['ai_crawler', 'challenged'], 1]], $late[0]['tables']['classes']);
    $this->assertSame([], $store->closeDue(1_800_000_500, 'shadow', 'agents'));
  }

  public function testTheGateCountsAgentsReadersAndNotAssets(): void {
    $this->visit('/paid/a', ['User-Agent' => 'GPTBot/1.1', 'CF-IPCountry' => 'de', 'X-Client-ASN' => '8075']);
    $this->visit('/blog/2026/draft-title', ['User-Agent' => 'Mozilla/5.0', 'CF-IPCountry' => 'US', 'X-Client-ASN' => '7922']);
    $this->visit('/files/logo.png', ['User-Agent' => 'Mozilla/5.0']);
    $body = $this->counted();
    $this->assertSame([['ai_crawler', 'challenged', 1], ['human', 'served', 1]], $body['classes']);
    $this->assertSame([['openai', 'ai_crawler', 'challenged', 1]], $body['operators']);
    $this->assertSame([['/paid', 'ai_crawler', 1]], $body['sections']);
    $this->assertSame([['DE', 'ai_crawler', 1], ['US', 'human', 1]], $body['countries']);
    $this->assertSame([['AS8075', 'ai_crawler', 1]], $body['networks']);
  }

  public function testCountedOnceWithOrWithoutKernelTerminate(): void {
    $this->config('aifinpay.settings')->set('mode', 'ENFORCE')->save();
    // A 402 the gate answers itself: Drupal does not run kernel.terminate for it.
    $this->visit('/paid/a', ['User-Agent' => 'GPTBot/1.1'], FALSE);
    $this->visit('/paid/a', ['User-Agent' => 'GPTBot/1.1']);
    $this->assertSame([['ai_crawler', 'challenged', 2]], $this->counted()['classes']);
  }

  public function testEnforceOutcomes(): void {
    $this->config('aifinpay.settings')->set('mode', 'ENFORCE')->save();
    $this->assertSame(402, $this->visit('/paid/a', ['User-Agent' => 'ClaudeBot/1.0'])->getStatusCode());
    $this->assertSame(200, $this->visit('/paid/a', ['User-Agent' => 'Mozilla/5.0'])->getStatusCode());
    // The receipt keys cannot be read here, so the gate answers its own 503.
    $this->assertSame(503, $this->visit('/paid/a', ['User-Agent' => 'GPTBot', 'AIFP-Receipt' => 'not-a-token'])->getStatusCode());
    $body = $this->counted();
    $this->assertSame([['ai_crawler', 'challenged', 1], ['aifp_agent', 'error', 1], ['human', 'served', 1]], $body['classes']);
  }

  public function testAMarkedRequestIsAnsweredTheSameAndNotCounted(): void {
    foreach (['SHADOW', 'ENFORCE'] as $mode) {
      $this->config('aifinpay.settings')->set('mode', $mode)->save();
      foreach (['/paid/a' => 'GPTBot/1.1', '/blog/a' => 'Mozilla/5.0', '/paid/b' => 'Mozilla/5.0'] as $path => $agent) {
        $plain = self::comparable($this->visit($path, ['User-Agent' => $agent]));
        $marked = self::comparable($this->visit($path, ['User-Agent' => $agent, 'AIFP-Edge-Counted' => ' 1 ']));
        $this->assertSame($plain, $marked, "$mode $path");
      }
      $body = $this->counted();
      $this->assertSame(3, array_sum(array_column($body['classes'], 2)), $mode);
    }
    $this->visit('/paid/a', ['User-Agent' => 'GPTBot', 'AIFP-Edge-Counted' => '0']);
    $this->assertNotNull($this->counted(), 'any other value is counted');
  }

  public function testSettingOffCountsAndSendsNothing(): void {
    $this->config('aifinpay.settings')->set('traffic_enabled', FALSE)->save();
    $this->visit('/paid/a', ['User-Agent' => 'GPTBot/1.1']);
    $this->assertSame(0, (int) $this->container->get('database')->select(TrafficStore::COUNTS)->countQuery()->execute()->fetchField());
    $this->store()->record(['target' => '/a', 'ua_class' => 'human', 'operator' => 'unknown', 'outcome' => 'served'], 'shadow', 'agents', time() - 900);
    aifinpay_cron();
    $this->assertSame([], array_filter($this->sent, static fn(RequestInterface $r): bool => str_ends_with($r->getUri()->getPath(), '/gate-traffic')));
    $this->assertSame(0, (int) $this->container->get('database')->select(TrafficStore::COUNTS)->countQuery()->execute()->fetchField());
  }

  public function testNotConnectedCountsNothing(): void {
    $this->setSetting('aifinpay_site', []);
    $this->visit('/paid/a', ['User-Agent' => 'GPTBot/1.1']);
    $this->assertNull($this->counted());
  }

  /** @return array<string, array{0: ?string, 1: ?string}> AIFP-Gate and User-Agent per API path. */
  private function identities(): array {
    $seen = [];
    foreach ($this->sent as $request) {
      $seen[$request->getUri()->getPath()] = [$request->getHeaderLine('AIFP-Gate') ?: NULL, $request->getHeaderLine('User-Agent') ?: NULL];
    }
    return $seen;
  }

  public function testEveryMerchantSecretRequestCarriesTheIdentity(): void {
    $this->config('aifinpay.settings')->set('mode', 'ENFORCE')->set('exempt_humans', FALSE)->set('free_readers', 'everyone')->save();
    $expect = ['pkg=drupal; ver=' . GateIdentity::VERSION . '; platform=' . GateIdentity::platform() . '; mode=enforce; charge=everyone', 'aifinpay-gate-drupal/' . GateIdentity::VERSION];
    $site = \Drupal\aifinpay\Connect\SiteBinding::get();
    $transport = $this->container->get('aifinpay.transport');
    $this->assertInstanceOf(Transport::class, $transport);
    // Gate events.
    $worker = new GateEvents([], 'aifinpay_gate_events', []);
    $worker->processItem(['id' => '0b0c6f0e-3d0a-4c58-9a51-0f2d6c1b9e77', 'kind' => '402', 'resource' => '/paid/*', 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'operator' => 'openai', 'ua_class' => 'ai_crawler', 'verified' => FALSE]);
    // Receipt use.
    ReceiptUse::poster($site, $transport)('{"uses":[]}');
    // Traffic.
    TrafficSender::poster($site, $transport)('{}');
    // The resource registry, read with the secret.
    $transport->json($site, '/v1/merchants/mrch_news_1/resources');
    // The public site record and keys, without the secret.
    $transport->json($site, '/v1/merchants/mrch_news_1', FALSE);
    $seen = $this->identities();
    foreach (['/gate-events', '/receipt-use', '/gate-traffic', '/resources'] as $suffix) {
      $this->assertSame($expect, $seen['/v1/merchants/mrch_news_1' . $suffix] ?? NULL, $suffix);
    }
    $this->assertNull($seen['/v1/merchants/mrch_news_1'][0], 'no identity without the secret');
    foreach ($this->sent as $request) {
      if ($request->hasHeader('AIFP-Gate')) {
        $this->assertSame(self::SECRET, $request->getHeaderLine('AIFP-Merchant-Secret'));
      }
    }
  }

  public function testCronSendsClosedWindowsWithTheIdentity(): void {
    $this->store()->record(['target' => '/paid/a', 'ua_class' => 'ai_crawler', 'operator' => 'openai', 'outcome' => 'challenged'], 'shadow', 'agents', time() - 400);
    aifinpay_cron();
    $traffic = array_values(array_filter($this->sent, static fn(RequestInterface $r): bool => str_ends_with($r->getUri()->getPath(), '/gate-traffic')));
    $this->assertCount(1, $traffic);
    $this->assertSame('https://api.aifinpay.io/v1/merchants/mrch_news_1/gate-traffic', (string) $traffic[0]->getUri());
    $this->assertSame(self::SECRET, $traffic[0]->getHeaderLine('AIFP-Merchant-Secret'));
    $this->assertStringStartsWith('pkg=drupal; ', $traffic[0]->getHeaderLine('AIFP-Gate'));
    $body = json_decode((string) $traffic[0]->getBody(), TRUE);
    $this->assertSame(['v', 'id', 'from', 'to', 'mode', 'charge', 'sample', 'classes', 'operators', 'sections'], array_keys($body));
    $this->assertSame(300, strtotime($body['to']) - strtotime($body['from']));
    $this->assertSame(['held' => [], 'paused_until' => NULL], $this->container->get('state')->get(TrafficSender::STATE_KEY));
  }

  public function testRetryResendsTheSameWindowAndBytes(): void {
    $this->answers = ['/gate-traffic' => 503];
    $this->store()->record(['target' => '/paid/a', 'ua_class' => 'ai_crawler', 'operator' => 'openai', 'outcome' => 'challenged'], 'shadow', 'agents', time() - 400);
    aifinpay_cron();
    $this->answers = [];
    aifinpay_cron();
    $bodies = array_map(static fn(RequestInterface $r): string => (string) $r->getBody(),
      array_values(array_filter($this->sent, static fn(RequestInterface $r): bool => str_ends_with($r->getUri()->getPath(), '/gate-traffic'))));
    $this->assertCount(2, $bodies);
    $this->assertSame($bodies[0], $bodies[1]);
  }

  public function testATraffic404LeavesEventsAndReceiptUseDelivering(): void {
    $this->answers = ['/gate-traffic' => 404];
    $queue = $this->container->get('queue')->get('aifinpay_gate_events');
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('aifinpay_gate_events');
    foreach ([1, 2] as $round) {
      $this->store()->record(['target' => '/paid/a', 'ua_class' => 'ai_crawler', 'operator' => 'openai', 'outcome' => 'challenged'], 'shadow', 'agents', time() - 400 * $round);
      ReceiptUse::record($this->container->get('database'), 'rcpt_00000000000000a' . $round, 3);
      $queue->createItem(['id' => sprintf('0b0c6f0e-3d0a-4c58-9a51-0f2d6c1b9e7%d', $round), 'kind' => '402', 'resource' => '/paid/*', 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'operator' => 'openai', 'ua_class' => 'ai_crawler', 'verified' => FALSE]);
      $this->sent = [];
      aifinpay_cron();
      while ($item = $queue->claimItem()) {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
      }
      $paths = array_map(static fn(RequestInterface $r): string => $r->getUri()->getPath(), $this->sent);
      $this->assertContains('/v1/merchants/mrch_news_1/gate-events', $paths, "round $round");
      $this->assertContains('/v1/merchants/mrch_news_1/receipt-use', $paths, "round $round");
      $this->assertSame(0, (int) $this->container->get('database')->select(ReceiptUse::TABLE)->countQuery()->execute()->fetchField());
    }
    $state = $this->container->get('state')->get(TrafficSender::STATE_KEY);
    $this->assertNotNull($state['paused_until']);
    $this->assertSame([], array_filter($paths, static fn(string $p): bool => str_ends_with($p, '/gate-traffic')), 'paused after the 404');
  }

}
