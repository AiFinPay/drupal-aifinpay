<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Render;

use Drupal\aifinpay\Policy\FreeReaders;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Symfony\Component\HttpFoundation\Request;

/** The `about` object of the node a protected path shows, read before routing. */
class AboutPage {

  public const FIELD_TYPES = ['string', 'string_long'];

  public function __construct(private EntityTypeManagerInterface $entityTypeManager, private ?InboundPathProcessorInterface $paths = NULL) {}

  /** @return array{title?: string, description?: string}|null Only for a published node anonymous visitors may view. */
  public function forRequest(Request $request, array $config): ?array {
    if (About::setting($config) === About::OFF) {
      return NULL;
    }
    try {
      $path = rtrim($request->getPathInfo(), '/');
      // The router's own inbound processing (aliases, language prefix) on a copy, so the request is untouched.
      $system = $this->paths !== NULL && $path !== '' ? $this->paths->processInbound($path, $request->duplicate()) : $path;
      if (!preg_match('~^/node/([1-9][0-9]{0,9})$~D', $system, $m) || !$this->entityTypeManager->hasDefinition('node')) {
        return NULL;
      }
      $node = $this->entityTypeManager->getStorage('node')->load($m[1]);
      if (!$node instanceof FieldableEntityInterface || !$node instanceof EntityPublishedInterface || !$node->isPublished()
        || !$node->access('view', new AnonymousUserSession())) {
        return NULL;
      }
      return self::fromNode($node, $config);
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /** Title = the label; description = the summary the editor typed in body, or the named plain-text field; never body text. */
  public static function fromNode(FieldableEntityInterface $node, array $config): ?array {
    $paidFields = FreeReaders::paidFields($config);
    $field = is_string($config['about_field'] ?? NULL) ? $config['about_field'] : '';
    $description = '';
    if ($field === '') {
      $description = $node->hasField('body') ? self::string($node->get('body')->getValue()[0]['summary'] ?? '') : '';
    }
    elseif (preg_match('/^[a-z0-9_]{1,32}$/D', $field) && $node->hasField($field) && !in_array($field, $paidFields, TRUE)
      && in_array($node->getFieldDefinition($field)?->getType(), self::FIELD_TYPES, TRUE)) {
      $description = self::string($node->get($field)->getValue()[0]['value'] ?? '');
    }
    $paid = [];
    foreach (array_unique([...$paidFields, 'body']) as $name) {
      if (!$node->hasField($name)) {
        continue;
      }
      foreach ($node->get($name)->getValue() as $item) {
        foreach ((array) $item as $key => $value) {
          if (is_string($value) && $key !== 'format' && !($name === 'body' && $key === 'summary')) {
            $paid[] = $value;
          }
        }
      }
    }
    return About::build(About::setting($config), self::string($node->label()), $description, implode("\n", $paid));
  }

  private static function string(mixed $value): string {
    return is_string($value) ? $value : '';
  }

}
