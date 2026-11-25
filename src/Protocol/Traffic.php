<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Protocol;

/**
 * Five-minute traffic summaries: the counting rules and the request body, no I/O.
 *
 * The rules of conformance/SPEC.md "Traffic summary", byte for byte the
 * shared vectors gate-traffic-v1.json (`sections`, `build`, `edge_counted`).
 * A summary carries counts per class and outcome, operator, first path
 * segment, country and network; never a full path, address, User-Agent or
 * header, and never a network for a reader.
 */
final class Traffic {

  public const WINDOW = 300;

  public const OUTCOMES = ['served', 'challenged', 'paid', 'blocked', 'error'];

  public const TABLES = ['classes', 'operators', 'sections', 'countries', 'networks'];

  public const CAPS = ['sections' => 30, 'countries' => 20, 'networks' => 50];

  public const MAX_ROWS = 600;

  public const MAX_COUNT = 1000000000;

  public const EDGE_COUNTED = 'AIFP-Edge-Counted';

  public const LANGUAGE_PREFIXES = ['ar', 'bg', 'bn', 'ca', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fa', 'fi', 'fr', 'he', 'hi', 'hr', 'hu', 'id', 'it', 'ja', 'ko', 'lt', 'lv', 'ms', 'nl', 'no', 'pl', 'pt', 'ro', 'ru', 'sk', 'sl', 'sr', 'sv', 'th', 'tr', 'uk', 'vi', 'zh'];

  public const ASSET_EXTENSIONS = ['avif', 'bmp', 'css', 'eot', 'gif', 'ico', 'jpeg', 'jpg', 'js', 'map', 'mjs', 'mp3', 'mp4', 'otf', 'png', 'svg', 'ttf', 'wasm', 'webm', 'webp', 'woff', 'woff2'];

  private const MAX_ASN = 4294967295;

  private static function lower(string $s): string {
    return strtr($s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
  }

  private static function pathOf(string $target): string {
    return preg_split('/[?#]/', $target, 2)[0];
  }

  /** The first segment of the path, `/` or `_other`; never more of the path. */
  public static function sectionOf(string $target): string {
    $segments = array_values(array_map(self::lower(...), array_filter(explode('/', self::pathOf($target)), static fn(string $s): bool => $s !== '')));
    $language = '/^(?:' . implode('|', self::LANGUAGE_PREFIXES) . ')(?:[-_][a-z]{2,4})?$/D';
    $segment = $segments[$segments !== [] && preg_match($language, $segments[0]) ? 1 : 0] ?? NULL;
    if ($segment === NULL) {
      return '/';
    }
    return preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/D', $segment) && preg_match_all('/[0-9]/', $segment) <= 4 ? '/' . $segment : '_other';
  }

  /** A page's images and scripts served free are not visitors. */
  public static function counted(string $target, string $outcome): bool {
    if ($outcome !== 'served') {
      return TRUE;
    }
    $path = self::pathOf($target);
    $last = self::lower(substr($path, strrpos($path, '/') === FALSE ? 0 : strrpos($path, '/') + 1));
    foreach (self::ASSET_EXTENSIONS as $ext) {
      if (str_ends_with($last, '.' . $ext)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * An edge gate of the same site already counted this request.
   *
   * @param list<?string> $lines
   *   The field lines the request carried for AIFP-Edge-Counted.
   */
  public static function edgeCounted(array $lines): bool {
    return count($lines) === 1 && is_string($lines[0]) && trim($lines[0], " \t") === '1';
  }

  public static function countryOf(mixed $value): string {
    if (!is_string($value) || !preg_match('/^[A-Za-z]{2}$/D', $value)) {
      return 'ZZ';
    }
    $country = strtoupper($value);
    return $country === 'XX' ? 'ZZ' : $country;
  }

  public static function networkOf(mixed $value): string {
    $n = NULL;
    if (is_int($value)) {
      $n = $value;
    }
    elseif (is_string($value) && preg_match('/^(?:AS|as)?(0|[1-9][0-9]{0,9})$/D', $value, $m)) {
      $n = (int) $m[1];
    }
    return $n !== NULL && $n >= 1 && $n <= self::MAX_ASN ? 'AS' . $n : 'AS0';
  }

  /**
   * The table cells one counted request adds to, or [] when it is not counted.
   *
   * @param array{target: string, ua_class: string, operator: string, outcome: string, country?: mixed, network?: mixed} $r
   *   `country` / `network` absent: the site has no source for it.
   *
   * @return list<array{0: string, 1: list<string>}>
   */
  public static function cells(array $r): array {
    if (!self::counted($r['target'], $r['outcome'])) {
      return [];
    }
    $agent = $r['ua_class'] !== 'human';
    $cells = [['classes', [$r['ua_class'], $r['outcome']]]];
    if ($agent) {
      $cells[] = ['operators', [$r['operator'], $r['ua_class'], $r['outcome']]];
      $cells[] = ['sections', [self::sectionOf($r['target']), $r['ua_class']]];
    }
    if (array_key_exists('country', $r)) {
      $cells[] = ['countries', [self::countryOf($r['country']), $r['ua_class']]];
    }
    if ($agent && array_key_exists('network', $r)) {
      $cells[] = ['networks', [self::networkOf($r['network']), $r['ua_class']]];
    }
    return $cells;
  }

  /**
   * The request body, or NULL when nothing was counted.
   *
   * @param array{id: string, from: string, to: string, mode: string, charge: string, sample: int} $window
   * @param array<string, list<array{0: list<string>, 1: int}>> $tables
   *   Per table name, rows of [cells, n]; the same cells may repeat and are added up.
   */
  public static function body(array $window, array $tables): ?string {
    $rows = [];
    foreach (self::TABLES as $name) {
      $sums = [];
      foreach ($tables[$name] ?? [] as [$cells, $n]) {
        $key = implode("\0", $cells);
        $sums[$key] = ($sums[$key] ?? 0) + $n * $window['sample'];
      }
      if (isset(self::CAPS[$name])) {
        $sums = self::cap($sums, self::CAPS[$name]);
      }
      $list = [];
      foreach ($sums as $key => $n) {
        if ($n > 0) {
          $list[] = [...explode("\0", (string) $key), min($n, self::MAX_COUNT)];
        }
      }
      usort($list, static function (array $a, array $b): int {
        for ($i = 0; $i < count($a) - 1; $i++) {
          if ($a[$i] !== $b[$i]) {
            return strcmp($a[$i], $b[$i]) < 0 ? -1 : 1;
          }
        }
        return 0;
      });
      $rows[$name] = $list;
    }
    if ($rows['classes'] === []) {
      return NULL;
    }
    $total = array_sum(array_map('count', $rows));
    foreach (['networks', 'countries', 'sections', 'operators'] as $name) {
      if ($total <= self::MAX_ROWS) {
        break;
      }
      $total -= count($rows[$name]);
      $rows[$name] = [];
    }
    $body = ['v' => 1, 'id' => $window['id'], 'from' => $window['from'], 'to' => $window['to'], 'mode' => $window['mode'],
      'charge' => $window['charge'], 'sample' => $window['sample'], 'classes' => $rows['classes']];
    foreach (array_slice(self::TABLES, 1) as $name) {
      if ($rows[$name] !== []) {
        $body[$name] = $rows[$name];
      }
    }
    return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }

  /**
   * Folds every value past the cap into `_other`, per class.
   *
   * @param array<string, int> $sums
   * @return array<string, int>
   */
  private static function cap(array $sums, int $limit): array {
    $totals = [];
    foreach ($sums as $key => $n) {
      $value = explode("\0", (string) $key, 2)[0];
      if ($value !== '_other') {
        $totals[$value] = ($totals[$value] ?? 0) + $n;
      }
    }
    uksort($totals, static fn(string|int $a, string|int $b): int => $totals[$b] <=> $totals[$a] ?: strcmp((string) $a, (string) $b));
    $kept = array_flip(array_map('strval', array_slice(array_keys($totals), 0, $limit)));
    $out = [];
    foreach ($sums as $key => $n) {
      $cells = explode("\0", (string) $key);
      if ($cells[0] !== '_other' && !isset($kept[$cells[0]])) {
        $cells[0] = '_other';
      }
      $other = implode("\0", $cells);
      $out[$other] = ($out[$other] ?? 0) + $n;
    }
    return $out;
  }

  /** A UTC timestamp as the summary writes it. */
  public static function utc(int $seconds): string {
    return gmdate('Y-m-d\TH:i:s\Z', $seconds);
  }

  public static function uuid4(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
  }

}
