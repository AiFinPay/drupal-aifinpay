<?php

declare(strict_types=1);

namespace Drupal\aifinpay\EventSubscriber;

use Drupal\aifinpay\Protocol\TrafficStore;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Writes the gate's count for a request after its response has been sent.
 *
 * On kernel.terminate, and from a shutdown function for the responses Drupal
 * does not terminate: those the gate or the page cache answer before
 * KernelPreHandle runs. Whichever comes first writes; the other finds nothing.
 */
final class TrafficSubscriber implements EventSubscriberInterface {

  /** Request attribute holding the count until it is written. */
  public const ATTRIBUTE = '_aifinpay_traffic';

  /** @var callable(callable): void */
  private $defer = 'register_shutdown_function';

  public function __construct(private TrafficStore $store) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::TERMINATE => ['onTerminate', 0]];
  }

  /** How the write is put off until after the response; tests replace it. */
  public function setDefer(callable $defer): void {
    $this->defer = $defer;
  }

  /** Holds one request's count until the response has gone out. */
  public function hold(Request $request, array $count): void {
    $request->attributes->set(self::ATTRIBUTE, $count);
    ($this->defer)(fn() => $this->write($request));
  }

  public function onTerminate(TerminateEvent $event): void {
    $this->write($event->getRequest());
  }

  /** Records the held count once; never throws. */
  public function write(Request $request): void {
    $count = $request->attributes->get(self::ATTRIBUTE);
    $request->attributes->remove(self::ATTRIBUTE);
    if (!is_array($count) || !is_array($count['record'] ?? NULL)) {
      return;
    }
    $this->store->record($count['record'], (string) $count['mode'], (string) $count['charge'], (int) $count['at']);
  }

}
