# Changelog

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

## 1.0.0

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
