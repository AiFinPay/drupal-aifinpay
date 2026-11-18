<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Render;

use Drupal\aifinpay\Connect\ReaderKeys;
use Drupal\aifinpay\Policy\FreeReaders;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\EntityInterface;

/** Holds the paid fields of a node back on a held page and marks them on a full one. */
final class PaidFields {

  public const HOLD_MESSAGE = 'Loading the rest of the page…';

  private static function hold(): ?Hold {
    return \Drupal::hasService('request_stack') ? Hold::of(\Drupal::requestStack()->getMainRequest()) : NULL;
  }

  /** A held node is never read from or written to the render cache. */
  public static function buildDefaultsAlter(array &$build, EntityInterface $entity): void {
    if ($entity->getEntityTypeId() === 'node' && self::hold() !== NULL) {
      unset($build['#cache']['keys']);
      $build['#cache']['max-age'] = 0;
    }
  }

  public static function viewAlter(array &$build, EntityInterface $entity, string $viewMode): void {
    if ($entity->getEntityTypeId() !== 'node' || !$entity instanceof FieldableEntityInterface) {
      return;
    }
    $hold = self::hold();
    if ($hold !== NULL) {
      $build['#cache']['max-age'] = 0;
      foreach ($hold->fields as $field) {
        if (!$entity->hasField($field)) {
          continue;
        }
        foreach ($entity->get($field)->getValue() as $item) {
          foreach ((array) $item as $value) {
            if (is_string($value)) {
              $hold->remember($value, (string) $entity->label());
            }
          }
        }
        if (isset($build[$field])) {
          $build[$field] = [
            '#type' => 'html_tag', '#tag' => 'div',
            '#attributes' => ['data-aifp-hold' => self::key($entity, $viewMode, $field)],
            'message' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => self::HOLD_MESSAGE, '#attributes' => ['class' => ['aifp-m']]],
            '#weight' => $build[$field]['#weight'] ?? 0,
          ];
          $hold->held++;
        }
      }
      return;
    }
    $config = \Drupal::config('aifinpay.settings')->getRawData();
    if (FreeReaders::chosen($config) !== FreeReaders::PROVEN || FreeReaders::effective($config, ReaderKeys::get()) !== FreeReaders::PROVEN) {
      return;
    }
    $build['#cache']['tags'][] = 'config:aifinpay.settings';
    foreach (FreeReaders::paidFields($config) as $field) {
      if (isset($build[$field])) {
        $build[$field]['#prefix'] = '<div data-aifp-paid="' . self::key($entity, $viewMode, $field) . '">' . ($build[$field]['#prefix'] ?? '');
        $build[$field]['#suffix'] = ($build[$field]['#suffix'] ?? '') . '</div>';
      }
    }
  }

  /** A held page asks not to be indexed and drops head parts that repeat the article. */
  public static function attachmentsAlter(array &$attachments): void {
    if (self::hold() === NULL) {
      return;
    }
    foreach ($attachments['#attached']['html_head'] ?? [] as $i => $entry) {
      $element = $entry[0] ?? [];
      $attributes = $element['#attributes'] ?? [];
      $name = strtolower((string) ($attributes['name'] ?? $attributes['property'] ?? ''));
      $type = strtolower((string) ($attributes['type'] ?? ''));
      if (in_array($name, ['description', 'abstract', 'og:description', 'twitter:description', 'dcterms.description', 'robots'], TRUE)
        || $type === 'application/ld+json') {
        unset($attachments['#attached']['html_head'][$i]);
      }
    }
    $attachments['#attached']['html_head'][] = [['#tag' => 'meta', '#attributes' => ['name' => 'robots', 'content' => 'noindex']], 'aifinpay_noindex'];
  }

  private static function key(EntityInterface $entity, string $viewMode, string $field): string {
    return preg_replace('/[^a-z0-9_-]/', '', 'node-' . $entity->id() . '-' . $viewMode . '-' . $field);
  }

}
