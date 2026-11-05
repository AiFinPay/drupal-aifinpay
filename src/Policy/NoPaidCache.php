<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Policy;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\PageCache\RequestPolicyInterface;
use Symfony\Component\HttpFoundation\Request;

/** Prevent inner caches from storing or serving a monetized resource. */
final class NoPaidCache implements RequestPolicyInterface {

  public function __construct(private ConfigFactoryInterface $configFactory) {}

  public function check(Request $request): ?string {
    $config = $this->configFactory->get('aifinpay.settings')->getRawData();
    if (!empty($config['enabled']) && ($config['mode'] ?? '') === 'ENFORCE' && Paths::protected($request->getPathInfo(), $config) !== NULL) {
      return self::DENY;
    }
    return NULL;
  }

}
