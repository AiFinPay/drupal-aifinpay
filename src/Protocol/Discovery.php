<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Protocol;

use Drupal\aifinpay\Policy\Paths;

/**
 * How an agent that lands on any page finds the site's payment terms.
 *
 * A PHP copy of @aifinpay/gate 0.8.0 (gate/src/discovery-link.ts and
 * buildDiscoveryDocument in gate/src/discovery.ts): the same Link header on
 * every HTML page and the same /.well-known/x402.json document.
 * tests/DiscoveryTest.php replays the gate's own parity records.
 */
final class Discovery {

  public const LLMS_TXT_PATH = '/llms.txt';
  public const X402_PATH = '/.well-known/x402.json';

  private const DOCS = 'https://github.com/AiFinPay/sdk/blob/main/AGENT-FLOW.md';
  private const SKILL = 'https://raw.githubusercontent.com/AiFinPay/skill/main/agent/skills/aifinpay/SKILL.md';
  private const MERCHANT_SKILL = 'https://raw.githubusercontent.com/AiFinPay/skill/main/agent/skills/aifinpay-merchant/SKILL.md';

  /** The header value, or NULL when every link is off. */
  public static function linkHeader(string|false $llmsTxt = self::LLMS_TXT_PATH, string|false $x402 = self::X402_PATH): ?string {
    $links = [];
    if ($llmsTxt !== FALSE) {
      $links[] = '<' . $llmsTxt . '>; rel="alternate"; type="text/markdown"; title="AI agents: access and payment"';
    }
    if ($x402 !== FALSE) {
      $links[] = '<' . $x402 . '>; rel="describedby"; type="application/json"';
    }
    return $links ? implode(', ', $links) : NULL;
  }

  public static function isHtml(?string $contentType): bool {
    $type = strtolower(trim(explode(';', (string) $contentType, 2)[0]));
    return $type === 'text/html' || $type === 'application/xhtml+xml';
  }

  /** A merchant id the document may name; anything else publishes no x402.json. */
  public static function merchantId(array $config): ?string {
    $id = (string) ($config['merchant_id'] ?? '');
    return preg_match('/^mrch_[A-Za-z0-9_-]{1,64}$/D', $id) ? $id : NULL;
  }

  /** Protected path patterns as gate resources: "/x/*" is a prefix scope. */
  public static function resources(array $config): array {
    $resources = [];
    foreach ($config['protected_paths'] ?? [] as $pattern) {
      if (is_string($pattern) && Paths::validPattern($pattern)) {
        $resources[] = ['resource' => $pattern, 'scope' => str_ends_with($pattern, '/*') ? 'prefix' : 'exact'];
      }
    }
    return $resources;
  }

  /** @param array<int, array{resource: string, tier?: string, scope?: string, name?: string}> $resources */
  public static function x402Document(string $merchantId, array $resources, string $apiBase = 'https://api.aifinpay.io'): array {
    $api = rtrim($apiBase, '/');
    return [
      'x402_version' => 1,
      'protocol' => 'AIFP-1',
      'merchant_id' => $merchantId,
      'quote_endpoint' => $api . '/v1/quote',
      'pay_endpoint' => $api . '/v1/pay',
      'onboarding' => 'npx @aifinpay/mcp init',
      'documentation_url' => self::DOCS,
      'instructions_url' => self::SKILL,
      'merchant_instructions_url' => self::MERCHANT_SKILL,
      'resources' => array_map(static fn (array $r): array => [
        'resource' => $r['resource'],
        ...(isset($r['name']) ? ['name' => $r['name']] : []),
        'tier' => $r['tier'] ?? 'standard',
        'scope' => $r['scope'] ?? 'exact',
        'unit_price_usd' => self::unitPriceUsd($r['tier'] ?? 'standard'),
      ], $resources),
      'settlement_terms_from' => $api . '/v1/quote',
    ];
  }

  /** The site's agent instructions, from its own configuration. */
  public static function llmsTxt(array $config, string $siteName, string $baseUrl): string {
    $base = rtrim($baseUrl, '/');
    $merchant = self::merchantId($config);
    $resources = self::resources($config);
    $enforce = ($config['mode'] ?? 'SHADOW') === 'ENFORCE';
    $example = $resources ? $base . rtrim($resources[0]['resource'], '*') : $base . '/<protected path>';
    $lines = [
      '# ' . self::line($siteName) . ' — access for AI agents', '',
      '> Paid access for AI agents on this site runs through AiFinPay (HTTP 402, AIFP-1).', '',
      '## Access', '',
      $enforce
        ? 'Mode: enforce. An AI agent requesting a protected path gets HTTP 402 with a quote until it sends a valid AIFP-Receipt.'
        : 'Mode: shadow. Every page is served as usual and nothing is charged; AiFinPay counts AI-agent requests to the protected paths below.',
      '',
      '## Protected paths', '',
    ];
    foreach ($resources as $r) {
      $lines[] = '- ' . $base . $r['resource'] . ($r['scope'] === 'prefix' ? ' (and everything under it)' : '');
    }
    if (!$resources) {
      $lines[] = 'No protected paths are configured.';
    }
    array_push($lines, '',
      '## How to pay', '',
      '1. Install a payer: `npx skills add AiFinPay/skill` (the AiFinPay skill for your agent) or `npx -y @aifinpay/mcp` (MCP server with a local wallet and the payable_fetch tool).',
      "2. Fund that wallet with the asset and network this site's HTTP 402 quote names (accepted_chains, accepted_assets).",
      $enforce
        ? '3. Call the paid URL, for example ' . $example . '. It answers HTTP 402 with the quote; payable_fetch (MCP) or fetchPaid (Node SDK) quotes, pays and retries with the receipt.'
        : '3. Once this site enforces payment, the paid URL (for example ' . $example . ') answers HTTP 402 with the quote; payable_fetch (MCP) or fetchPaid (Node SDK) quotes, pays and retries with the receipt. Until then it is served without payment.',
      '',
      '## Machine-readable', '',
    );
    if ($merchant !== NULL) {
      $lines[] = '- x402 discovery: ' . $base . self::X402_PATH;
      $lines[] = '- Merchant: ' . $merchant;
    }
    $lines[] = '- Agent flow: ' . self::DOCS;
    $lines[] = '- Agent skill: ' . self::SKILL;
    $lines[] = '';
    return implode("\n", $lines);
  }

  private static function unitPriceUsd(string $tier): string {
    return ['standard' => '0.0005', 'complex' => '0.002', 'premium' => '0.005'][$tier] ?? '0.0005';
  }

  private static function line(string $text): string {
    return trim(preg_replace('/[\r\n]+/', ' ', $text) ?? '');
  }

}
