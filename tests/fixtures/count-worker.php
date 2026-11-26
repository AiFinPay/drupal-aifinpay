<?php

/**
 * @file
 * A second PHP worker for the race test: counts requests into the test database.
 *
 * Arguments: autoloader, Drupal root, connection info as JSON, count, start second.
 */

declare(strict_types=1);

use Drupal\aifinpay\Protocol\TrafficStore;
use Drupal\Core\Database\Database;

$loader = require $argv[1];
$loader->addPsr4('Drupal\\sqlite\\', $argv[2] . '/core/modules/sqlite/src');
$loader->addPsr4('Drupal\\aifinpay\\', dirname(__DIR__, 2) . '/src');
chdir($argv[2]);
Database::addConnectionInfo('default', 'default', json_decode($argv[3], TRUE));
$store = new TrafficStore(Database::getConnection());
$count = (int) $argv[4];
$start = (int) $argv[5];
for ($i = 0; $i < $count; $i++) {
  $store->record(['target' => '/blog/' . $i, 'ua_class' => 'ai_crawler', 'operator' => 'openai', 'outcome' => 'challenged', 'country' => 'US'],
    'enforce', 'agents', $start + $i);
}
