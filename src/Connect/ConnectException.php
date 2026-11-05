<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Connect;

/** A refused or failed connect; the message is a short reason code, never a secret. */
final class ConnectException extends \RuntimeException {}
