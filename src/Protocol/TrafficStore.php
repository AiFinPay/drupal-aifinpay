<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Protocol;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;

/**
 * The traffic tally in this site's database: windows and their counts.
 *
 * PHP keeps no process between requests, so a window is a row of
 * aifinpay_traffic_windows. It opens at the first counted request after the
 * last window ended: the opener inserts a row naming the window it follows,
 * `after` is unique, so of two workers racing to open, one wins and the other
 * counts into the winner's window. Counts are rows of aifinpay_traffic
 * (window, table, value, rest of the cells, n), raised with n = n + 1, so no
 * increment is lost or doubled. Closing subtracts what it read, so a count
 * that lands while a window closes stays for the next run.
 */
final class TrafficStore {

  public const WINDOWS = 'aifinpay_traffic_windows';

  public const COUNTS = 'aifinpay_traffic';

  /** Distinct sections and networks held per window; the rest are counted as `_other`. */
  public const MAX_DISTINCT = 1000;

  /** Windows older than this are deleted unsent by whoever opens the next window. */
  public const KEEP = 7200;

  public function __construct(private Connection $database) {}

  /**
   * Counts one request in the window open now. Never throws.
   *
   * @param array{target: string, ua_class: string, operator: string, outcome: string, country?: mixed, network?: mixed} $record
   */
  public function record(array $record, string $mode, string $charge, int $now): void {
    try {
      $cells = Traffic::cells($record);
      if ($cells === []) {
        return;
      }
      $window = $this->window($mode, $charge, $now);
      foreach ($cells as [$table, $values]) {
        $value = array_shift($values);
        $this->increment($window, $table, $value, implode("\t", $values));
      }
    }
    catch (\Throwable) {
      // Counting never reaches the visitor.
    }
  }

  /** The start of the window open now, opening one when the last has ended. */
  private function window(string $mode, string $charge, int $now): int {
    $last = $this->latest();
    if ($last !== NULL && $now < $last + Traffic::WINDOW) {
      return $last;
    }
    try {
      $this->database->insert(self::WINDOWS)->fields([
        'opened' => $now,
        'after' => $last ?? 0,
        'mode' => $mode,
        'charge' => $charge,
      ])->execute();
    }
    catch (IntegrityConstraintViolationException) {
      // Another worker opened it first.
      return $this->latest() ?? $now;
    }
    $this->database->delete(self::COUNTS)->condition('opened', $now - self::KEEP, '<')->execute();
    $this->database->delete(self::WINDOWS)->condition('opened', $now - self::KEEP, '<')->execute();
    return $now;
  }

  private function latest(): ?int {
    $opened = $this->database->select(self::WINDOWS, 'w')->fields('w', ['opened'])->orderBy('opened', 'DESC')->range(0, 1)->execute()->fetchField();
    return $opened === FALSE || $opened === NULL ? NULL : (int) $opened;
  }

  private function increment(int $window, string $table, string $value, string $rest): void {
    if (($table === 'sections' || $table === 'networks') && $value !== '_other' && !$this->known($window, $table, $value)
      && $this->distinct($window, $table) >= self::MAX_DISTINCT) {
      $value = '_other';
    }
    $key = ['opened' => $window, 'tbl' => $table, 'v' => $value, 'rest' => $rest];
    for ($attempt = 0; $attempt < 2; $attempt++) {
      $query = $this->database->update(self::COUNTS)->expression('n', 'n + 1');
      foreach ($key as $field => $v) {
        $query->condition($field, $v);
      }
      if ($query->execute() > 0) {
        return;
      }
      try {
        $this->database->insert(self::COUNTS)->fields($key + ['n' => 1])->execute();
        return;
      }
      catch (IntegrityConstraintViolationException) {
        // A concurrent first count created the row; raise it instead.
      }
    }
  }

  private function known(int $window, string $table, string $value): bool {
    return (bool) $this->database->select(self::COUNTS, 'c')->fields('c', ['n'])->condition('opened', $window)
      ->condition('tbl', $table)->condition('v', $value)->range(0, 1)->execute()->fetchField();
  }

  private function distinct(int $window, string $table): int {
    $query = $this->database->select(self::COUNTS, 'c')->condition('opened', $window)->condition('tbl', $table);
    $query->addExpression('COUNT(DISTINCT v)', 'distinct_values');
    return (int) $query->execute()->fetchField();
  }

  /**
   * Takes every window that ended at or before $now out of the tally, oldest first.
   *
   * @return list<array{opened: int, mode: string, charge: string, tables: array<string, list<array{0: list<string>, 1: int}>>}>
   *   An empty `tables` means nothing was counted in it.
   */
  public function closeDue(int $now, string $mode, string $charge): array {
    $due = [];
    foreach ($this->database->select(self::WINDOWS, 'w')->fields('w', ['opened', 'mode', 'charge'])
      ->condition('opened', $now - Traffic::WINDOW, '<=')->orderBy('opened')->execute() as $row) {
      $due[(int) $row->opened] = ['mode' => (string) $row->mode, 'charge' => (string) $row->charge];
    }
    // Counts that arrived after their window was closed and its row removed.
    $query = $this->database->select(self::COUNTS, 'c')->fields('c', ['opened'])->condition('opened', $now - Traffic::WINDOW, '<=')->distinct();
    foreach ($query->execute()->fetchCol() as $opened) {
      $due[(int) $opened] ??= ['mode' => $mode, 'charge' => $charge];
    }
    ksort($due);
    $closed = [];
    foreach ($due as $opened => $meta) {
      $tables = [];
      $read = $this->database->select(self::COUNTS, 'c')->fields('c', ['tbl', 'v', 'rest', 'n'])->condition('opened', $opened)->execute()->fetchAll();
      foreach ($read as $row) {
        $cells = [(string) $row->v, ...((string) $row->rest === '' ? [] : explode("\t", (string) $row->rest))];
        $tables[(string) $row->tbl][] = [$cells, (int) $row->n];
        $this->database->update(self::COUNTS)->expression('n', 'n - :n', [':n' => (int) $row->n])
          ->condition('opened', $opened)->condition('tbl', $row->tbl)->condition('v', $row->v)->condition('rest', $row->rest)->execute();
      }
      $this->database->delete(self::COUNTS)->condition('opened', $opened)->condition('n', 0, '<=')->execute();
      $this->database->delete(self::WINDOWS)->condition('opened', $opened)->execute();
      $closed[] = ['opened' => $opened] + $meta + ['tables' => $tables];
    }
    return $closed;
  }

  /** Forgets everything counted. */
  public function clear(): void {
    $this->database->delete(self::COUNTS)->execute();
    $this->database->delete(self::WINDOWS)->execute();
  }

}
