<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Render;

/** "Tell agents what a page is": the plain-text `about` object of a 402, the same bytes as about.ts and about.py of the gate packages. */
final class About {

  public const OFF = 'off';
  public const TITLE = 'title';
  public const TITLE_SUMMARY = 'title_summary';
  public const TITLE_MAX = 200;
  public const DESCRIPTION_MAX = 300;
  // The shortest run of paid text the module looks for, as Hold does.
  public const RUN = 16;

  private const TAG = '/<[A-Za-z\/!?][^<>]*>/u';
  private const ENTITY = '/&(#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6}|[A-Za-z][A-Za-z0-9]{1,31});/u';
  // Cc minus the whitespace below; lone surrogates are the invalid UTF-8 dropped before.
  private const CONTROL = '/[\x{0000}-\x{0008}\x{000E}-\x{001F}\x{007F}-\x{0084}\x{0086}-\x{009F}]/u';
  private const SPACE = '/[\t\n\x{000B}\f\r \x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u';
  private const UTF8 = '/[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}/';

  private const NAMED = [
    'amp' => '&', 'lt' => '<', 'gt' => '>', 'quot' => '"', 'apos' => "'", 'nbsp' => "\u{00A0}",
    'ndash' => "\u{2013}", 'mdash' => "\u{2014}", 'hellip' => "\u{2026}", 'lsquo' => "\u{2018}", 'rsquo' => "\u{2019}",
    'sbquo' => "\u{201A}", 'ldquo' => "\u{201C}", 'rdquo' => "\u{201D}", 'bdquo' => "\u{201E}", 'laquo' => "\u{00AB}",
    'raquo' => "\u{00BB}", 'middot' => "\u{00B7}", 'bull' => "\u{2022}", 'copy' => "\u{00A9}", 'reg' => "\u{00AE}",
    'trade' => "\u{2122}", 'deg' => "\u{00B0}", 'euro' => "\u{20AC}", 'pound' => "\u{00A3}", 'yen' => "\u{00A5}",
    'cent' => "\u{00A2}", 'sect' => "\u{00A7}", 'para' => "\u{00B6}", 'times' => "\u{00D7}", 'divide' => "\u{00F7}",
    'plusmn' => "\u{00B1}",
  ];

  /** A site that has not chosen tells agents nothing; a new install chooses title_summary in config/install. */
  public static function setting(array $config): string {
    $value = $config['about'] ?? NULL;
    return in_array($value, [self::OFF, self::TITLE, self::TITLE_SUMMARY], TRUE) ? $value : self::OFF;
  }

  /** Tags stripped, entities decoded once, control characters removed, whitespace collapsed, cut at $max code points (0: no cut). */
  public static function plain(string $value, int $max = 0): string {
    preg_match_all(self::UTF8, $value, $m);
    $text = (string) preg_replace(self::TAG, '', implode('', $m[0]));
    $text = (string) preg_replace_callback(self::ENTITY, static function (array $e): string {
      $ref = $e[1];
      if ($ref[0] !== '#') {
        return self::NAMED[$ref] ?? $e[0];
      }
      $code = $ref[1] === 'x' || $ref[1] === 'X' ? (int) hexdec(substr($ref, 2)) : (int) substr($ref, 1);
      return $code === 0 || $code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF) ? $e[0] : (string) mb_chr($code, 'UTF-8');
    }, $text);
    $text = (string) preg_replace(self::SPACE, ' ', (string) preg_replace(self::CONTROL, '', $text));
    $text = (string) preg_replace('/^ | $/', '', $text);
    if ($max > 0) {
      $text = mb_substr($text, 0, $max, 'UTF-8');
    }
    return str_ends_with($text, ' ') ? substr($text, 0, -1) : $text;
  }

  /** The cleaned object, or NULL when there is nothing to say or the value has the wrong shape. */
  public static function page(mixed $value): ?array {
    if (!is_array($value) || array_is_list($value) && $value !== []) {
      return NULL;
    }
    $title = $value['title'] ?? NULL;
    $description = $value['description'] ?? NULL;
    if (($title !== NULL && !is_string($title)) || ($description !== NULL && !is_string($description))) {
      return NULL;
    }
    $about = array_filter([
      'title' => is_string($title) ? self::plain($title, self::TITLE_MAX) : '',
      'description' => is_string($description) ? self::plain($description, self::DESCRIPTION_MAX) : '',
    ], static fn(string $v): bool => $v !== '');
    return $about === [] ? NULL : $about;
  }

  /** TRUE when $description repeats a run of the paid text. */
  public static function repeatsPaidText(string $description, string $paid): bool {
    $d = mb_strtolower(self::plain($description));
    $p = mb_strtolower(self::plain($paid));
    if ($d === '' || $p === '') {
      return FALSE;
    }
    $length = mb_strlen($d);
    if ($length < self::RUN) {
      return str_contains($p, $d);
    }
    for ($i = 0; $i + self::RUN <= $length; $i++) {
      if (str_contains($p, mb_substr($d, $i, self::RUN))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /** @return array{title?: string, description?: string}|null */
  public static function build(string $setting, string $title, string $description, string $paid): ?array {
    if ($setting === self::OFF) {
      return NULL;
    }
    if ($setting !== self::TITLE_SUMMARY || self::repeatsPaidText($description, $paid)) {
      $description = '';
    }
    return self::page(['title' => $title, 'description' => $description]);
  }

  /** The 402 body with `about` right after `resource`; anything but a well-formed object leaves the body as it was. */
  public static function into(array $body, mixed $about): array {
    $clean = is_array($about) ? self::page($about) : NULL;
    if ($clean === NULL || $clean !== $about || !array_key_exists('resource', $body) || array_key_exists('about', $body)) {
      return $body;
    }
    $out = [];
    foreach ($body as $key => $value) {
      $out[$key] = $value;
      if ($key === 'resource') {
        $out['about'] = $clean;
      }
    }
    return $out;
  }

}
