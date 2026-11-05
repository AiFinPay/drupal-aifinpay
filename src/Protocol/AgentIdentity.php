<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Protocol;

use Symfony\Component\HttpFoundation\Request;

/**
 * Who a request says it is, without Acquia Edge.
 *
 * A PHP copy of the classifier in @aifinpay/gate (gate/src/agents.ts:
 * GATE_OPERATORS, UA_CLASSES, AI_AGENT_UA_TABLE, classifyAgent). The AiFinPay
 * gate-events ingest accepts only these operator and ua_class values, so the
 * enums here are a contract, not a local choice: change them in all three
 * places together. tests/AgentIdentityTest.php pins them.
 *
 * It reads only what the client declares. A scraper with a browser
 * User-Agent is classified `human`; that boundary is the same for Cloudflare's
 * list and for this one. Never returns verified = TRUE: a header is a claim.
 */
final class AgentIdentity {

  public const OPERATORS = [
    'openai', 'anthropic', 'perplexity', 'google', 'microsoft', 'meta',
    'bytedance', 'apple', 'amazon', 'commoncrawl', 'aifinpay', 'unknown',
  ];

  public const UA_CLASSES = ['ai_crawler', 'ai_agent_browser', 'aifp_agent', 'human', 'unknown'];

  /** Lowercase User-Agent substring => [operator, ua_class]. First match wins. */
  public const UA_TABLE = [
    'gptbot' => ['openai', 'ai_crawler'],
    'oai-searchbot' => ['openai', 'ai_crawler'],
    'chatgpt-user' => ['openai', 'ai_agent_browser'],
    'claudebot' => ['anthropic', 'ai_crawler'],
    'claude-searchbot' => ['anthropic', 'ai_crawler'],
    'claude-user' => ['anthropic', 'ai_agent_browser'],
    'claude-web' => ['anthropic', 'ai_agent_browser'],
    'anthropic-ai' => ['anthropic', 'ai_crawler'],
    'perplexitybot' => ['perplexity', 'ai_crawler'],
    'perplexity-user' => ['perplexity', 'ai_agent_browser'],
    'ccbot' => ['commoncrawl', 'ai_crawler'],
    'bytespider' => ['bytedance', 'ai_crawler'],
    'google-extended' => ['google', 'ai_crawler'],
    'applebot-extended' => ['apple', 'ai_crawler'],
    'meta-externalagent' => ['meta', 'ai_crawler'],
    'facebookbot' => ['meta', 'ai_crawler'],
    'amazonbot' => ['amazon', 'ai_crawler'],
    'youbot' => ['unknown', 'ai_crawler'],
    'diffbot' => ['unknown', 'ai_crawler'],
    'timpibot' => ['unknown', 'ai_crawler'],
    'omgilibot' => ['unknown', 'ai_crawler'],
    'cohere-ai' => ['unknown', 'ai_agent_browser'],
    'ai2bot' => ['unknown', 'ai_crawler'],
    'mistralai' => ['unknown', 'ai_agent_browser'],
    'aifinpay-agent' => ['aifinpay', 'aifp_agent'],
    'headlesschrome' => ['unknown', 'unknown'],
    'phantomjs' => ['unknown', 'unknown'],
  ];

  /** @return array{operator: string, ua_class: string, verified: bool} */
  public static function classify(Request $request): array {
    // Speaking the protocol is the declaration, whatever the User-Agent says.
    if ($request->headers->has('AIFP-Receipt') || $request->headers->has('AIFP-Agent-Id')) {
      return ['operator' => 'aifinpay', 'ua_class' => 'aifp_agent', 'verified' => FALSE];
    }
    $ua = strtolower((string) $request->headers->get('User-Agent', ''));
    if ($ua === '') {
      return ['operator' => 'unknown', 'ua_class' => 'unknown', 'verified' => FALSE];
    }
    foreach (self::UA_TABLE as $marker => [$operator, $class]) {
      if (str_contains($ua, $marker)) {
        return ['operator' => $operator, 'ua_class' => $class, 'verified' => FALSE];
      }
    }
    return ['operator' => 'unknown', 'ua_class' => 'human', 'verified' => FALSE];
  }

}
