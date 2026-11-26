<?php

declare(strict_types=1);

namespace Drupal\Tests\aifinpay\Unit;

use Drupal\aifinpay\Protocol\TrafficSender;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The sender table, from the `sender` scenarios of gate-traffic-v1.json.
 */
#[Group('aifinpay')]
final class TrafficSenderTest extends UnitTestCase {

  public function testConstantsMatchTheVectors(): void {
    $sender = TrafficTest::vectors()['sender'];
    $this->assertSame($sender['hold'], TrafficSender::HOLD);
    $this->assertSame($sender['stale_seconds'], TrafficSender::STALE);
    $this->assertSame($sender['pause_seconds']['404'], TrafficSender::PAUSE_404);
    $this->assertSame($sender['pause_seconds']['other'], TrafficSender::PAUSE_OTHER);
    $this->assertLessThanOrEqual(5.0, TrafficSender::TIMEOUT);
  }

  public static function scenarios(): iterable {
    foreach (TrafficTest::vectors()['sender']['scenarios'] as $scenario) {
      yield $scenario['name'] => [$scenario];
    }
  }

  #[DataProvider('scenarios')]
  public function testScenario(array $scenario): void {
    $state = TrafficSender::fresh();
    $bodies = [];
    foreach ($scenario['steps'] as $i => $step) {
      $answers = $step['answers'];
      $posted = [];
      $post = static function (string $body) use (&$answers, &$posted): ?int {
        $posted[] = $body;
        $answer = array_shift($answers) ?? 'none';
        return is_int($answer) ? $answer : NULL;
      };
      $closed = $step['closed'] === NULL ? NULL : ['id' => $step['closed'], 'to' => $step['at'], 'body' => '{"id":"' . $step['closed'] . '"}'];
      [$state, $sent] = TrafficSender::tick($state, $closed, $step['at'], $post);
      $expect = $scenario['expect'][$i];
      $this->assertSame($expect['sent'], $sent, "step $i sent");
      $this->assertSame($expect['held'], array_column($state['held'], 'id'), "step $i held");
      $this->assertSame($expect['paused_until'], $state['paused_until'], "step $i paused_until");
      foreach ($sent as $n => $id) {
        // A window is always sent with the bytes it was first sent with.
        $bodies[$id] ??= $posted[$n];
        $this->assertSame($bodies[$id], $posted[$n]);
      }
    }
  }

  public function testNoSendKeepsWindowsHeld(): void {
    $post = static fn(string $body): ?int => throw new \LogicException('must not send');
    [$state, $sent] = TrafficSender::tick(TrafficSender::fresh(), ['id' => 'w1', 'to' => 300, 'body' => '{}'], 300, $post, FALSE);
    $this->assertSame([], $sent);
    $this->assertSame(['w1'], array_column($state['held'], 'id'));
  }

}
