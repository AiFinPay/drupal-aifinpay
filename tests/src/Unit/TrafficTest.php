<?php

declare(strict_types=1);

namespace Drupal\Tests\aifinpay\Unit;

use Drupal\aifinpay\Protocol\Traffic;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Counting rules and the summary body, from the shared vectors gate-traffic-v1.json.
 */
#[Group('aifinpay')]
final class TrafficTest extends UnitTestCase {

  public static function vectors(): array {
    return json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/gate-traffic-v1.json'), TRUE, 64, JSON_THROW_ON_ERROR);
  }

  /** The body a list of vector requests makes, counted as the store counts them. */
  public static function build(array $vector): ?string {
    $tables = [];
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
      foreach (Traffic::cells($record) as [$table, $cells]) {
        $tables[$table][] = [$cells, $r['n'] ?? 1];
      }
    }
    return Traffic::body($vector['window'], $tables);
  }

  public function testConstantsMatchTheVectors(): void {
    $v = self::vectors();
    $this->assertSame($v['outcomes'], Traffic::OUTCOMES);
    $this->assertSame($v['language_prefixes'], Traffic::LANGUAGE_PREFIXES);
    $this->assertSame($v['asset_extensions'], Traffic::ASSET_EXTENSIONS);
    $this->assertSame($v['caps'], Traffic::CAPS);
    $this->assertSame($v['max_rows'], Traffic::MAX_ROWS);
    $this->assertSame($v['window_seconds'], Traffic::WINDOW);
    $this->assertSame($v['edge_counted']['header'], Traffic::EDGE_COUNTED);
  }

  public function testSections(): void {
    foreach (self::vectors()['sections'] as $vector) {
      $this->assertSame($vector['section'], Traffic::sectionOf($vector['target']), $vector['target']);
    }
  }

  public function testCounted(): void {
    foreach (self::vectors()['counted'] as $vector) {
      $this->assertSame($vector['counted'], Traffic::counted($vector['target'], $vector['outcome']), $vector['target'] . ' ' . $vector['outcome']);
    }
  }

  public function testCountriesAndNetworks(): void {
    foreach (self::vectors()['countries'] as $vector) {
      $this->assertSame($vector['country'], Traffic::countryOf($vector['value']), json_encode($vector['value']));
    }
    foreach (self::vectors()['networks'] as $vector) {
      $this->assertSame($vector['network'], Traffic::networkOf($vector['value']), json_encode($vector['value']));
    }
  }

  public static function buildVectors(): iterable {
    foreach (self::vectors()['build'] as $vector) {
      yield $vector['name'] => [$vector];
    }
    foreach (self::vectors()['edge_counted']['build'] as $vector) {
      yield 'edge_counted ' . $vector['name'] => [$vector];
    }
  }

  #[DataProvider('buildVectors')]
  public function testBuild(array $vector): void {
    $this->assertSame($vector['body'], self::build($vector));
  }

  public function testEdgeCountedValues(): void {
    foreach (self::vectors()['edge_counted']['values'] as $vector) {
      $this->assertSame($vector['edge_counted'], Traffic::edgeCounted($vector['lines']), $vector['name']);
    }
  }

  public function testReadersHaveNoNetworkOperatorOrSection(): void {
    $cells = Traffic::cells(['target' => '/blog/a', 'ua_class' => 'human', 'operator' => 'unknown', 'outcome' => 'served', 'country' => 'US', 'network' => '7922']);
    $this->assertSame([['classes', ['human', 'served']], ['countries', ['US', 'human']]], $cells);
  }

  public function testOnlyTheFirstSegmentLeaves(): void {
    $cells = Traffic::cells(['target' => '/blog/2026/my-secret-draft?token=x', 'ua_class' => 'ai_crawler', 'operator' => 'openai', 'outcome' => 'challenged']);
    $this->assertSame(['/blog', 'ai_crawler'], $cells[2][1]);
    $this->assertStringNotContainsString('secret', json_encode($cells));
  }

}
