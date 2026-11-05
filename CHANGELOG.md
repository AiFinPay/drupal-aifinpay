# Changelog

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
