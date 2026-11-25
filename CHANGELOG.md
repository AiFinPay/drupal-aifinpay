# Changelog

## 1.4.0

- Every request to AiFinPay with the merchant secret (gate events, receipt use,
  traffic summaries, the resource registry) names the module:
  `User-Agent: aifinpay-gate-drupal/1.4.0` and
  `AIFP-Gate: pkg=drupal; ver=1.4.0; platform=drupal11; mode=…; charge=…`, mode
  and charge from the settings at that moment (**Who reads for free**: *AI
  agents only* is `agents`, *Strict* is `unproven`, *Everyone pays* is
  `everyone`), byte for byte the shared vectors `gate-identity-v1.json`.
- Traffic summaries: every request the gate decides is counted once, after the
  response has been sent (`kernel.terminate`, or a shutdown function for the
  responses Drupal does not terminate: the gate's own 402, 403 and 503 and
  page-cache hits), by agent class and outcome
  (`served`, `challenged`, `paid`, `blocked`, `error`); agents also by operator
  and first path segment; country from the header **Country header** names
  (default `CF-IPCountry`), network only from **Network header** (no default)
  and never for readers. A page's images and scripts served free, and a request
  carrying `AIFP-Edge-Counted: 1`, are not counted; the marked request is
  otherwise answered exactly as before. Counts live in the new tables
  `aifinpay_traffic_windows` and `aifinpay_traffic`, in five-minute windows,
  at most 1000 distinct sections and networks per window. Cron sends each
  closed window to `POST /v1/merchants/{id}/gate-traffic` as the shared vectors
  `gate-traffic-v1.json` build it, with its own state: at most three windows
  held, the same id and bytes on retry, a 404 pauses six hours, another refusal
  a day, a window older than an hour is dropped. Gate events and receipt use do
  not depend on it. On by default for a connected site; **Send traffic
  summaries to AiFinPay** turns it off. Update 11008 adds the tables and the
  three settings.

## 1.3.0

- The site tells AiFinPay how much of each receipt it has spent. Every metered
  read keeps the receipt id (never the token) and the units left in the new
  table `aifinpay_receipt_use`, the lowest per receipt; cron sends them to
  `POST /v1/merchants/{id}/receipt-use` with the merchant secret, at most 100
  per call, ordered by id, byte for byte the shared vectors
  `receipt-use-v1.json`. Sent whenever the site has its secret, apart from the
  `report_gate_events` opt-in. A 404 (an API without the route), another
  refusal or a redirect drops what is held and the next read records again;
  a network error, 408, 429 or 5xx keeps it for up to five cron runs. A
  failure never reaches the paid read and gate events are unchanged.
  Update 11007 adds the table.

## 1.2.0

- **Who reads for free** names its three choices as modes: *AI agents only*
  (`named`), *Strict* (`proven`) and *Everyone pays* (`everyone`), each with a
  one-line description. Stored values and `exempt_humans` are unchanged.
- **Tell agents what a page is** (`about`: `off`, `title`, `title_summary`;
  `title_summary` on a new install, `off` on an installed site after update
  11006, so an update publishes nothing new, since **Strict** held the body
  summary back until now): the 402 of a published node, and the terms embedded
  in its held page, carry `about: {title, description}`. The description is
  only the body Summary an editor typed, or the plain-text field named in
  `about_field`; never body text or a generated summary. Plain text cleaned as
  the gate packages do (shared vectors `about-v1.json`), capped at 200 and 300
  code points, placed right after `resource`. The quote, receipt and metering
  are unchanged.
  Update 11006 adds both keys, `about` as `off` unless one is stored.
- **Who reads for free** has a third choice, *proven*: readers who passed a
  silent Cloudflare Turnstile check (a signed `__aifp_human` cookie, the format
  shared with `@aifinpay/gate`) and search engines verified by forward-confirmed
  reverse DNS read free; everyone else gets the 402. A browser gets the page
  itself as a 402 with its paid fields held back before rendering and the price
  embedded; it fills itself in once the check passes. `POST /__aifp/human`
  answers the check. `exempt_humans` keeps its meaning and update 11005 adds
  `free_readers`, `turnstile_sitekey` and `paid_fields` to match it, so an
  installed site behaves as before.
- A page opened with a receipt that names a content licence carries
  `AIFP-License: id=…; rev=…; sha256=…; url="…"` and `Link: <…/text>; rel="license"`,
  as in `@aifinpay/gate`. The URL is built from the API origin and the merchant, never
  read from the token, and `Link` is added beside Drupal's canonical and shortlink
  links instead of replacing them. A malformed `lic` claim adds nothing.
- The 402 no longer carries `protocol_fee_bps`. The fee is set per merchant and
  stated by the quote, matching `@aifinpay/gate` 0.11.0.
- The settings form names the dashboard page the connect code is on: Profile &
  keys → API keys → Connect a CMS site.

## 1.0.2

- A protected page drops `CDN-Cache-Control`, `Surrogate-Control`, `X-Accel-Expires` and
  the like, which outrank `Cache-Control` at the cache they address: a CDN could store a
  paid page.
- Every 503 from the gate carries `Retry-After`.
- A raw `#` and a spelling still decoding after three rounds get an agent a 400 in
  Enforce, and a reader the page. An escaped `?` or `#` stays part of the name, and since
  a proxy that decodes hands `/report%3Fx` on as `/report`, that page's rule protects
  it and a receipt for that page opens it.
- The 402 asks for `AIFP-Agent-Id` alongside the receipt, as in `@aifinpay/gate`
  0.10.0, and a receipt sent without it is refused in words that say what to send.
  Nothing is spent, so the agent retries without paying again.
- A testnet receipt no longer opens a live site, or a live receipt a test site.
- A `unit_quota` that is not a whole number is refused. It used to count as 1.
- A reader on a protected path is served while the merchant record is unreadable or
  charging is not approved yet. The page is free for them either way. Sites that
  require Acquia Edge keep their answer.
- Shadow verifies a receipt and reports what Enforce would have said: a good one as
  a verified read with no `AIFP-Shadow-Status`, a forged one as a would-be 403.
  Nothing is spent.
- A meter outage carries `Retry-After`, and `/.well-known/x402.json` prices each
  resource at the site's tier.
- **Enforce no longer answers 400 to readers.** Any path with a `%`, a `/.` or a `//`
  was refused for everyone, on every path: `/news/caf%C3%A9`, and
  `/.well-known/acme-challenge/x`, which stops a certificate renewing. A path is now
  read as `@aifinpay/gate` 0.10 reads it. Only a dot segment or a control character
  is refused, and only for an agent.
- `/admin/../paid/a` no longer counts as an admin path, and an encoded spelling such
  as `/%70aid/a` is protected and priced as the page it resolves to.
- A receipt with an escape in its resource opens that page. A prefix receipt for
  `/x/*`, as `x402.json` lists a section, covers it. An Aptos address matches with or
  without its padding.

## 1.0.1

- A protected path now matches in any case and with a trailing slash. Drupal's
  router serves `/PAID/a` and `/paid/` as the same page, so those spellings were
  served free in Enforce and went uncounted in Shadow.
- A `/x/*` pattern now covers `/x` itself, as in `@aifinpay/gate` and the WordPress
  plugin. It covered only what is under `/x/`, so the section page was free.
- A receipt covers the page in any spelling the router serves: `/PAID/a` and
  `/paid/a/` answered 403 after the agent had paid. A prefix receipt covers the
  section page, as in `@aifinpay/gate` 0.9.1: the SDKs buy `/paid/` for a `/paid/*`
  402, and `/paid` answered 403.

## 1.0.0 — unreleased

First public release.

- Install with `composer require aifinpay/drupal-connector` and `drush en aifinpay`;
  connect with a one-time code from the AiFinPay dashboard. No `settings.php` edits.
- Without a `settings.php` binding, agents are charged only on the host of the
  site URL set in AiFinPay, and charging is approved by AiFinPay: test sites
  (testnets only) or live sites with a verified domain. Any key set in
  `settings.php` still wins.
- Settings page: status table with the connected merchant, test or live, site
  URL and domain verification, charging hosts, mode and approval; rarely used
  fields moved under Advanced.
- New installs default to the self-hosted gate (verification `receipt`).
- Status report shows whether the site is connected.
- Self-hosted gate: AI agents get an HTTP 402 with the AIFP-1 challenge, pay
  through AiFinPay, and retry with a receipt verified on the site (Ed25519,
  published keys) and metered in the site database.
- Shadow mode: agent requests to protected paths are classified and, on
  opt-in, reported to the AiFinPay dashboard in batches of up to 50.
- `/llms.txt` and `/.well-known/x402.json` built from the protected paths.
