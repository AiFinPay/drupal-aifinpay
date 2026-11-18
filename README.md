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

## Unproven pays

**Who reads for free** decides who opens a protected page without a receipt.
An agent that sends an AiFinPay receipt or agent ID always pays.

| Mode | What it does | Config | Who reads free |
|---|---|---|---|
| **AI agents only** | Clients that say they are AI agents pay. Everyone else reads. | `exempt_humans: true` | Any request without a known AI User-Agent (a scraper with a browser User-Agent too) |
| **Strict** | Only readers who passed a silent check and verified search engines read. Everyone else pays. | `free_readers: proven` | A browser holding a valid `__aifp_human` cookie; Googlebot, Bingbot, Applebot, YandexBot and Baiduspider confirmed by reverse and forward DNS |
| **Everyone pays** | Every request without a receipt pays. For APIs, catalogs and data feeds. | `exempt_humans: false` | Nobody |

Installed sites keep what they had: `exempt_humans` still chooses between the
first and the last row, and the update sets `free_readers` to match.

With **Strict** (`proven`), a browser that has not passed the check gets the page itself
with status 402: the node's paid fields (`paid_fields`, default `body`) are
replaced before rendering by a holder, the price is embedded as
`<script type="application/json" id="aifp-402">`, and Cloudflare Turnstile
(managed, `interaction-only`) runs. When it passes, `POST /__aifp/human` sets
the cookie and the page fills itself in without a reload. A client that does
not accept HTML, or a POST, gets the 402 JSON. A page that has no paid field,
or that repeats its text anywhere else, gets a bare 402 page. Free pages run
the check unseen, so the next paid page opens whole. Held pages are never
stored by Drupal's page caches.

Proven needs a Turnstile site key and secret key in the form; the module makes
the reader key itself. The secret and the reader key are kept in Drupal State
(or `turnstile_secret` and `reader_key` in the settings.php binding), never in
exported config. Until all three exist everyone pays on protected paths, and
the Status table says what is missing.

Search engines are verified from the client IP. Behind a proxy or CDN, set
`reverse_proxy` and `reverse_proxy_addresses` in settings.php, or no search
engine is verified and they all pay.

## Tell agents what a page is

The 402 of a protected node can say what the page is, so an agent knows what
it is paying for:

```json
"about": { "title": "Harbour report", "description": "Weekly harbour notes: tides, berths and pilot hours." }
```

| Setting | `about` | The 402 carries |
|---|---|---|
| Title and summary (default on a new install) | `title_summary` | The node title and, when an editor typed one, the body **Summary** |
| Title | `title` | The node title only |
| Off (what an updated site has) | `off` | No `about` |

The description is only what an editor wrote in the body's Summary field, or
in the plain-text field named in `about_field` (a `string` or `string_long`
field that is not paid). It is never cut from the body and never Drupal's
trimmed summary: a node with an empty Summary has no description. A summary
that repeats a run of the paid text is left out. Both parts are plain text,
cleaned exactly as `@aifinpay/gate` and `aifinpay-gate` do (the shared vectors
`about-v1.json`): tags removed, entities decoded once, control characters
removed, whitespace collapsed, the title cut at 200 code points and the
description at 300, with no ellipsis. `about` sits right after `resource`.

Only a published node that anonymous visitors may view is described, found
from the request path (alias or `/node/{id}`); any other protected path has no
`about`. The held page of **Strict** embeds the same object. It is information
only: the price, the quote, the receipt and the metering do not depend on it.
A new install starts with `about: title_summary`. An installed site that
updates gets `about: off` from update 11006 (and `about_field: ''`), so it
publishes nothing it did not before: under **Strict** the body summary was held
back as paid text until now. Turn it on in the form when the summaries are
meant to be public. A site without the key at all is treated as `off`.

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
