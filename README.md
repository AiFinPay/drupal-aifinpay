# AiFinPay for Drupal

Get paid when AI agents read your Drupal pages. An agent that requests a
protected page gets an HTTP 402 with a price; it prepays a small batch of
requests through AiFinPay, the payment goes to the payout wallet of your
AiFinPay account, and the agent retries with a signed receipt. This module
verifies the receipt on your server and counts the requests in your database.
Readers can keep reading for free.

## Requirements

- Drupal 11, PHP 8.3 or later with the `sodium` extension
- An AiFinPay account with a site: https://dash.aifinpay.io

## Install

```sh
composer require aifinpay/drupal-connector
drush en aifinpay
```

## Connect

1. In the AiFinPay dashboard open your site, then **Profile & keys → API keys →
   Connect a CMS site**, and copy the connect code. It works once and expires
   after ten minutes.
2. In Drupal open **Configuration → Web services → AiFinPay**
   (`/admin/config/services/aifinpay`), paste the code and save.

That is all. You do not edit `settings.php`. The module exchanges the code for
an API secret and keeps it in Drupal State on this server: never in
configuration, exports or logs. Redeeming a code rotates the secret, so a
secret used elsewhere for the same site stops working.

## Observe first (Shadow)

Tick **Enable AiFinPay**, keep **Mode: Shadow**, and list the paths you want to
charge for under **Protected paths** (one exact path or `/prefix/*` per line).
In Shadow nothing is charged: agent requests to those paths are classified and,
if you opt in to **Report agent requests**, counted in your AiFinPay dashboard.

## Charge (Enforce)

Choose **Mode: Enforce** and **Self-hosted gate**. The form accepts this only
when AiFinPay approves charging for the connected site:

| Your AiFinPay site | Charging approved |
|---|---|
| Test mode | Yes, testnet payments only |
| Live, domain of the site URL verified in the dashboard | Yes |
| Live, domain not verified | No |

Agents are charged only on the host of the **site URL** set for the site in the
AiFinPay dashboard. Requests to protected paths on any other host are refused
with a 503, never served free. Both the host and the approval come from
AiFinPay, so a change in the Drupal admin form cannot turn charging on.

The **Status** table at the top of the settings page shows the connected
merchant, test or live, the site URL and whether its domain is verified, the
hosts where agents are charged, the mode, and whether charging is approved.

**Serve readers free** lets requests that do not identify as an AI agent
through without payment. An agent that sends an AiFinPay receipt or agent ID
always pays.

## What the module sends to AiFinPay

- The connect code, once, to `POST https://api.aifinpay.io/v1/merchants/connect`.
- In Enforce: a read of your public site record (`GET /v1/merchants/{id}`) at
  most every five minutes, and the public receipt-signing keys
  (`/.well-known/jwks.json`) at most every ten minutes. If AiFinPay cannot be
  reached, the last site record is used for up to a day; after that protected
  paths answer 503 until it is reachable again.
- Only if you opt in: one event per agent request to a protected path
  (operator, agent class, path pattern, time). Never the User-Agent, IP
  address or page content.

## Overriding from settings.php (optional)

Hosting platforms that manage secrets in environment variables can set the
binding in `settings.php`; every key set there wins over the connect code and
the AiFinPay site record:

```php
$settings['aifinpay_site'] = [
  'merchant_id' => getenv('AIFINPAY_MERCHANT_ID'),
  'secret' => getenv('AIFINPAY_MERCHANT_SECRET'),
  'secret_reference' => 'AIFINPAY_MERCHANT_SECRET',
  'trusted_hosts' => ['www.example.com'],
  'environment' => 'production',
  'receipt_gate_approved' => TRUE,
  'production_approved' => TRUE,
];
```

## Uninstall

```sh
drush pmu aifinpay
```

Uninstalling deletes the stored secret and the module's tables.

## Security

Report vulnerabilities privately to contact@aifinpay.io.

## License

GPL-2.0-or-later. See LICENSE.txt.
