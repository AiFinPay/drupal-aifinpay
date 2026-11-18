<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Render;

use Symfony\Component\HttpFoundation\Request;

/** One held request: which fields are paid, how many were held back and what their text looks like. */
final class Hold {

  public const ATTRIBUTE = '_aifinpay_hold';

  public int $held = 0;

  /** @var string[] */
  public array $probes = [];

  /** @param string[] $fields */
  public function __construct(public readonly array $fields) {}

  public static function of(?Request $request): ?self {
    $hold = $request?->attributes->get(self::ATTRIBUTE);
    return $hold instanceof self ? $hold : NULL;
  }

  /** Text runs of a paid value, to make sure none of it reached the page by another way. */
  public function remember(string $value, string $label): void {
    $text = html_entity_decode((string) preg_replace('/<[^>]*>/', "\n", $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    foreach (preg_split('/\s*\n\s*/u', $text) ?: [] as $segment) {
      $segment = trim((string) preg_replace('/[\s\x{a0}]+/u', ' ', $segment));
      if (mb_strlen($segment) >= 16 && count($this->probes) < 64 && !str_contains($label, $segment)) {
        $this->probes[] = mb_substr($segment, 0, 48);
      }
    }
  }

  /** TRUE when any remembered run is in the HTML, raw or escaped. */
  public function leaksInto(string $html): bool {
    foreach ($this->probes as $probe) {
      $json = substr((string) json_encode($probe), 1, -1);
      $forms = [$probe, htmlspecialchars($probe, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), htmlspecialchars($probe, ENT_NOQUOTES, 'UTF-8'),
        htmlspecialchars($probe, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $json, substr((string) json_encode($probe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1)];
      foreach ($forms as $form) {
        if ($form !== '' && str_contains($html, $form)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

}
