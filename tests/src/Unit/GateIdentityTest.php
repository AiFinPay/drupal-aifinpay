<?php

declare(strict_types=1);

namespace Drupal\Tests\aifinpay\Unit;

use Drupal\aifinpay\Protocol\GateIdentity;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The identity headers, from the shared vectors gate-identity-v1.json.
 */
#[Group('aifinpay')]
final class GateIdentityTest extends UnitTestCase {

  private static function vectors(): array {
    return json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/gate-identity-v1.json'), TRUE, 64, JSON_THROW_ON_ERROR);
  }

  public static function formatVectors(): iterable {
    foreach (self::vectors()['format'] as $vector) {
      yield $vector['name'] => [$vector];
    }
  }

  #[DataProvider('formatVectors')]
  public function testFormatVector(array $vector): void {
    $in = $vector['input'];
    $this->assertSame(['User-Agent' => $vector['user_agent'], 'AIFP-Gate' => $vector['header']],
      GateIdentity::format($in['pkg'], $in['ver'], $in['platform'], $in['mode'], $in['charge']));
  }

  public function testDrupalVectorsAreCovered(): void {
    $drupal = array_filter(self::vectors()['format'], static fn(array $v): bool => $v['input']['pkg'] === GateIdentity::PKG);
    $this->assertNotEmpty($drupal);
  }

  public function testVersionIsTheModuleVersion(): void {
    $info = (string) file_get_contents(__DIR__ . '/../../../aifinpay.info.yml');
    $this->assertMatchesRegularExpression('/^version: \'' . preg_quote(GateIdentity::VERSION, '/') . '\'$/m', $info);
    $changelog = (string) file_get_contents(__DIR__ . '/../../../CHANGELOG.md');
    preg_match('/^## (\S+)$/m', $changelog, $m);
    $this->assertSame(GateIdentity::VERSION, $m[1] ?? NULL);
  }

  public function testHeadersFollowTheSettings(): void {
    $platform = 'drupal' . (int) \Drupal::VERSION;
    $this->assertSame([
      'User-Agent' => 'aifinpay-gate-drupal/' . GateIdentity::VERSION,
      'AIFP-Gate' => 'pkg=drupal; ver=' . GateIdentity::VERSION . '; platform=' . $platform . '; mode=enforce; charge=agents',
    ], GateIdentity::headers(['enabled' => TRUE, 'mode' => 'ENFORCE', 'exempt_humans' => TRUE, 'free_readers' => 'named']));
    $this->assertStringEndsWith('; mode=shadow; charge=everyone', GateIdentity::headers(['enabled' => TRUE, 'mode' => 'SHADOW', 'exempt_humans' => FALSE])['AIFP-Gate']);
    $this->assertStringEndsWith('; mode=shadow; charge=unproven', GateIdentity::headers(['enabled' => FALSE, 'mode' => 'ENFORCE', 'free_readers' => 'proven'])['AIFP-Gate']);
    $this->assertMatchesRegularExpression('/^drupal\d+$/', GateIdentity::platform());
  }

}
