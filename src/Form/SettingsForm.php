<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Drupal\aifinpay\Connect\ConnectClient;
use Drupal\aifinpay\Connect\ConnectException;
use Drupal\aifinpay\Connect\MerchantRecord;
use Drupal\aifinpay\Connect\ReaderKeys;
use Drupal\aifinpay\Connect\SiteBinding;
use Drupal\aifinpay\Policy\FreeReaders;
use Drupal\aifinpay\Policy\Paths;
use Drupal\aifinpay\Protocol\Transport;
use Drupal\aifinpay\Protocol\HostedGateway;
use Drupal\aifinpay\Protocol\ReceiptGate;
use Drupal\aifinpay\Render\About;

final class SettingsForm extends ConfigFormBase {

  public function getFormId(): string { return 'aifinpay_settings'; }

  protected function getEditableConfigNames(): array { return ['aifinpay.settings']; }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('aifinpay.settings');
    $identity = SiteBinding::get();
    $connection = \Drupal::state()->get(SiteBinding::STATE_KEY);
    $form['status'] = ['#type' => 'details', '#open' => TRUE, '#title' => $this->t('Status'), 'table' => $this->statusTable($config->getRawData(), $identity)];
    $form['connect'] = ['#type' => 'details', '#open' => TRUE, '#title' => $this->t('Connect to AiFinPay')];
    $form['connect']['status'] = ['#markup' => is_array($connection) && SiteBinding::validConnection($connection)
      ? $this->t('Connected as @merchant on @date. The secret is stored on this server only.', ['@merchant' => $connection['merchant_id'], '@date' => date('Y-m-d H:i', (int) ($connection['connected_at'] ?? 0)) . ' UTC'])
      : $this->t('Not connected with a connect code.')];
    $form['connect']['connect_code'] = ['#type' => 'textfield', '#title' => $this->t('Connect code'), '#maxlength' => 40, '#attributes' => ['autocomplete' => 'off', 'spellcheck' => 'false'], '#description' => $this->t('In the AiFinPay dashboard (https://dash.aifinpay.io) open your site, then Profile & keys → API keys → Connect a CMS site, and paste the code here. On save this site fetches a new API secret and keeps it server-side; the previous secret stops working. A secret set in settings.php still takes precedence.')];
    $form['enabled'] = ['#type' => 'checkbox', '#title' => $this->t('Enable AiFinPay'), '#default_value' => $config->get('enabled')];
    foreach (['mode' => ['OFF' => 'Off', 'SHADOW' => 'Shadow: observe agents, charge nothing', 'ENFORCE' => 'Enforce: agents pay (needs AiFinPay approval)'], 'verification' => ['receipt' => 'Self-hosted gate: verify AiFinPay receipts on this site (AIFP-1)', 'hosted_gateway' => 'Existing AiFinPay hosted gateway (AIFP-1)', 'mock' => 'Local mock'], 'tier' => ['standard' => 'Standard', 'complex' => 'Complex', 'premium' => 'Premium']] as $key => $options) {
      $form[$key] = ['#type' => 'select', '#title' => ucfirst($key), '#options' => $options, '#default_value' => $config->get($key)];
    }
    foreach (['protected_paths', 'allowed_paths', 'excluded_paths'] as $key) {
      $form[$key] = ['#type' => 'textarea', '#title' => ucfirst(str_replace('_', ' ', $key)), '#default_value' => implode("\n", $config->get($key) ?? []), '#description' => $this->t('One exact path or /prefix/* per line. User-Agent never authorizes access.')];
    }
    // Keyed exempt_humans so a submission of the former checkbox (1 or 0) keeps its meaning.
    $form['exempt_humans'] = ['#type' => 'radios', '#title' => $this->t('Who reads for free'),
      '#options' => [
        1 => $this->t('AI agents only'),
        'proven' => $this->t('Strict'),
        0 => $this->t('Everyone pays'),
      ],
      1 => ['#description' => $this->t('Clients that say they are AI agents pay. Everyone else reads.')],
      'proven' => ['#description' => $this->t('Only readers who passed a silent check and verified search engines read. Everyone else pays.')],
      0 => ['#description' => $this->t('Every request without a receipt pays. For APIs, catalogs and data feeds.')],
      '#default_value' => match (FreeReaders::chosen($config->getRawData())) { FreeReaders::PROVEN => 'proven', FreeReaders::NAMED => 1, default => 0 },
      '#description' => $this->t('Self-hosted gate, protected paths only. An agent that sends an AiFinPay receipt or agent ID always pays. The browser check is Cloudflare Turnstile: enter its site key and secret below. Search engines are verified by reverse DNS of the client IP; behind a proxy or CDN set reverse_proxy in settings.php, or no search engine is verified and they all pay.'),
    ];
    $form['turnstile_sitekey'] = ['#type' => 'textfield', '#title' => $this->t('Turnstile site key'), '#default_value' => (string) $config->get('turnstile_sitekey'), '#maxlength' => 128, '#states' => ['visible' => [':input[name="exempt_humans"]' => ['value' => 'proven']]]];
    $form['turnstile_secret'] = ['#type' => 'password', '#title' => $this->t('Turnstile secret key'), '#maxlength' => 512, '#attributes' => ['autocomplete' => 'off'], '#description' => $this->t('Kept on this server only, never in exported configuration. Leave empty to keep the stored one.'), '#states' => ['visible' => [':input[name="exempt_humans"]' => ['value' => 'proven']]]];
    $form['paid_fields'] = ['#type' => 'textfield', '#title' => $this->t('Paid fields'), '#default_value' => implode(', ', FreeReaders::paidFields($config->getRawData())), '#description' => $this->t('Node fields held back until the check passes, separated by commas.'), '#states' => ['visible' => [':input[name="exempt_humans"]' => ['value' => 'proven']]]];
    $form['about'] = ['#type' => 'radios', '#title' => $this->t('Tell agents what a page is'),
      '#options' => [About::OFF => $this->t('Off'), About::TITLE => $this->t('Title'), About::TITLE_SUMMARY => $this->t('Title and summary')],
      '#default_value' => About::setting($config->getRawData()),
      '#description' => $this->t('Adds the page title and, if you wrote one, its summary to the price an agent gets, so it knows what it is paying for. The summary is only what an editor typed in the body Summary field (or the field named below), never text taken from the page. It does not change the price.'),
    ];
    $form['about_field'] = ['#type' => 'textfield', '#title' => $this->t('Summary field'), '#default_value' => (string) $config->get('about_field'), '#maxlength' => 32,
      '#description' => $this->t('Machine name of a plain-text node field to use instead of the body Summary. Leave empty to use the body Summary. A paid field is never used.'),
      '#states' => ['visible' => [':input[name="about"]' => ['value' => About::TITLE_SUMMARY]]],
    ];
    $form['report_gate_events'] = ['#type' => 'checkbox', '#title' => $this->t('Report agent requests to AiFinPay'), '#default_value' => (bool) $config->get('report_gate_events'), '#description' => $this->t('Sends one event per AI-agent request on a protected path (operator, agent class, path pattern, time; never the User-Agent, IP or content) to the AiFinPay dashboard. Needs a connected site and the path patterns registered as resources in AiFinPay.')];
    $form['traffic_enabled'] = ['#type' => 'checkbox', '#title' => $this->t('Send traffic summaries to AiFinPay'), '#default_value' => ($config->get('traffic_enabled') ?? TRUE) !== FALSE, '#description' => $this->t('Every five minutes, how many visits this site answered: AI agents by company and site section, readers only as a number, and the country if your CDN tells this site. Never addresses, full page addresses or browser details. Shown in your AiFinPay dashboard.')];
    $form['discovery_documents'] = ['#type' => 'checkbox', '#title' => $this->t('Serve /llms.txt and /.well-known/x402.json'), '#default_value' => (bool) ($config->get('discovery_documents') ?? TRUE), '#description' => $this->t('Agent instructions and the x402 discovery document, built from the protected paths above. x402.json is published once a merchant ID is set. Turn off if the site publishes its own files.')];
    $form['discovery_link'] = ['#type' => 'checkbox', '#title' => $this->t('Point agents to /llms.txt from every HTML page'), '#default_value' => (bool) ($config->get('discovery_link') ?? TRUE), '#description' => $this->t('Adds the same Link response header as the AiFinPay gate SDKs.')];
    $form['advanced'] = ['#type' => 'details', '#open' => FALSE, '#title' => $this->t('Advanced'), '#description' => $this->t('A connect code fills Merchant ID, Site ID and Secret reference; changing them by hand unbinds the site.')];
    foreach (['environment', 'merchant_id', 'site_id', 'api_endpoint', 'secret_reference', 'pricing_policy'] as $key) {
      $form['advanced'][$key] = ['#type' => 'textfield', '#title' => ucfirst(str_replace('_', ' ', $key)), '#default_value' => $config->get($key), '#maxlength' => 255];
    }
    $form['advanced']['traffic_country_header'] = ['#type' => 'textfield', '#title' => $this->t('Country header'), '#default_value' => (string) ($config->get('traffic_country_header') ?? 'CF-IPCountry'), '#maxlength' => 64, '#description' => $this->t('The request header your CDN fills with the visitor country, for traffic summaries. CF-IPCountry on Cloudflare. Leave empty to send no countries.')];
    $form['advanced']['traffic_network_header'] = ['#type' => 'textfield', '#title' => $this->t('Network header'), '#default_value' => (string) $config->get('traffic_network_header'), '#maxlength' => 64, '#description' => $this->t('The request header your CDN fills with the network number (AS number) of the client, for traffic summaries. Leave empty unless your CDN sets one.')];
    $form['advanced']['logging_level'] = ['#type' => 'select', '#title' => $this->t('Logging level'), '#options' => ['notice' => 'Notice', 'warning' => 'Warning'], '#default_value' => $config->get('logging_level')];
    $form['advanced']['telemetry_enabled'] = ['#type' => 'checkbox', '#title' => $this->t('Opt in to anonymous mock measurements'), '#default_value' => $config->get('telemetry_enabled')];
    $form['advanced']['telemetry_sample'] = ['#type' => 'number', '#min' => 0, '#max' => 100, '#title' => $this->t('Telemetry sample percent'), '#default_value' => $config->get('telemetry_sample')];
    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    foreach (['protected_paths', 'allowed_paths', 'excluded_paths'] as $key) {
      foreach ($this->lines((string) $form_state->getValue($key)) as $path) {
        if (!Paths::validPattern($path)) {
          $form_state->setErrorByName($key, $this->t('Invalid path pattern.'));
        }
      }
    }
    $identity = SiteBinding::get();
    $hosted = $form_state->getValue('verification') === 'hosted_gateway';
    $receipt = $form_state->getValue('verification') === 'receipt';
    if ($form_state->getValue('mode') === 'ENFORCE' && $receipt) {
      $record = !array_key_exists('trusted_hosts', $identity) || !array_key_exists('receipt_gate_approved', $identity) ? $this->merchantRecord()->get($identity, TRUE) : NULL;
      $resolved = MerchantRecord::resolve($identity, $record);
      if (!ReceiptGate::approved($resolved)) {
        $form_state->setErrorByName('mode', $this->approvalText(MerchantRecord::approvalReason($resolved, $record), FALSE));
      }
      elseif (($resolved['trusted_hosts'] ?? []) === []) {
        $form_state->setErrorByName('mode', $this->t('No host to charge on: set the site URL of this merchant in the AiFinPay dashboard.'));
      }
    }
    elseif ($form_state->getValue('mode') === 'ENFORCE') {
      $approved = $hosted ? HostedGateway::approved($identity) : (!empty($identity['enforce_approved']) && !empty($identity['local_mock']) && ($identity['environment'] ?? '') === 'local' && (empty($identity['edge_required']) || HostedGateway::edgeReady($identity)));
      if (!$approved) {
        $form_state->setErrorByName('mode', $this->t('Enforcement needs server-controlled gateway/cache validation and environment approval.'));
      }
    }
    $sitekey = trim((string) $form_state->getValue('turnstile_sitekey'));
    if ($sitekey !== '' && !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $sitekey)) {
      $form_state->setErrorByName('turnstile_sitekey', $this->t('This is not a Turnstile site key.'));
    }
    foreach ($this->fieldNames((string) $form_state->getValue('paid_fields')) as $field) {
      if (!preg_match('/^[a-z0-9_]{1,32}$/D', $field)) {
        $form_state->setErrorByName('paid_fields', $this->t('Use field machine names, such as body.'));
      }
    }
    $aboutField = trim((string) $form_state->getValue('about_field'));
    if ($aboutField !== '' && !preg_match('/^[a-z0-9_]{1,32}$/D', $aboutField)) {
      $form_state->setErrorByName('about_field', $this->t('Use a field machine name, such as field_teaser.'));
    }
    elseif ($aboutField !== '' && in_array($aboutField, $this->fieldNames((string) $form_state->getValue('paid_fields')) ?: ['body'], TRUE)) {
      $form_state->setErrorByName('about_field', $this->t('This field is paid, so it cannot describe the page.'));
    }
    foreach (['traffic_country_header', 'traffic_network_header'] as $key) {
      $header = trim((string) $form_state->getValue($key));
      if ($header !== '' && !preg_match('/^[A-Za-z0-9-]{1,64}$/D', $header)) {
        $form_state->setErrorByName($key, $this->t('Use a header name, such as CF-IPCountry.'));
      }
    }
    $ref = (string) $form_state->getValue('secret_reference');
    if ($ref !== '' && !preg_match('/^[A-Z][A-Z0-9_]{2,100}$/D', $ref)) {
      $form_state->setErrorByName('secret_reference', $this->t('Use an uppercase environment variable reference, never a secret.'));
    }
    $code = trim((string) $form_state->getValue('connect_code'));
    // Redeem last: it rotates the secret, so nothing else may fail after it.
    if ($code !== '' && !$form_state->getErrors()) {
      try {
        $site = $identity + ['api_origin' => 'https://api.aifinpay.io'];
        $form_state->set('aifinpay_connection', (new ConnectClient(new Transport(\Drupal::httpClient())))->redeem($site, $code));
      }
      catch (ConnectException $e) {
        $form_state->setErrorByName('connect_code', match ($e->getMessage()) {
          'invalid_code' => $this->t('This connect code is unknown, expired or already used. Create a new one in the AiFinPay dashboard.'),
          'rate_limited' => $this->t('Too many connect attempts. Wait a few minutes and try again.'),
          default => $this->t('AiFinPay could not be reached to redeem the code. Try again; the code stays valid until it expires.'),
        });
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('aifinpay.settings');
    foreach (['enabled', 'mode', 'environment', 'merchant_id', 'site_id', 'api_endpoint', 'secret_reference', 'pricing_policy', 'verification', 'tier', 'logging_level', 'telemetry_enabled', 'telemetry_sample', 'report_gate_events', 'discovery_documents', 'discovery_link'] as $key) {
      $config->set($key, $form_state->getValue($key));
    }
    foreach (['protected_paths', 'allowed_paths', 'excluded_paths'] as $key) {
      $config->set($key, $this->lines((string) $form_state->getValue($key)));
    }
    $config->set('traffic_enabled', (bool) $form_state->getValue('traffic_enabled'));
    foreach (['traffic_country_header', 'traffic_network_header'] as $key) {
      $config->set($key, trim((string) $form_state->getValue($key)));
    }
    $choice = (string) $form_state->getValue('exempt_humans');
    $free = $choice === 'proven' ? FreeReaders::PROVEN : ($choice === '1' ? FreeReaders::NAMED : FreeReaders::EVERYONE);
    if ($free !== FreeReaders::chosen($config->getRawData())) {
      Cache::invalidateTags(['rendered']);
    }
    $config->set('exempt_humans', $free === FreeReaders::NAMED)->set('free_readers', $free);
    $config->set('turnstile_sitekey', trim((string) $form_state->getValue('turnstile_sitekey')));
    $config->set('paid_fields', $this->fieldNames((string) $form_state->getValue('paid_fields')) ?: ['body']);
    $config->set('about', About::setting(['about' => $form_state->getValue('about') ?? $config->get('about')]))->set('about_field', trim((string) $form_state->getValue('about_field')));
    $secret = trim((string) $form_state->getValue('turnstile_secret'));
    if ($secret !== '') {
      ReaderKeys::storeTurnstileSecret($secret);
    }
    if ($free === FreeReaders::PROVEN) {
      ReaderKeys::ensureReaderKey();
    }
    $connection = $form_state->get('aifinpay_connection');
    if (is_array($connection)) {
      $env = Settings::get('aifinpay_site', []);
      $siteId = (string) $form_state->getValue('site_id') ?: (string) ($env['site_id'] ?? '') ?: (string) ($_ENV['AH_DRUPAL_SITE_NAME'] ?? '');
      \Drupal::state()->set(SiteBinding::STATE_KEY, $connection + ['site_id' => $siteId, 'meo_site_name' => (string) ($_ENV['AH_DRUPAL_SITE_NAME'] ?? ''), 'connected_at' => time()]);
      $config->set('merchant_id', $connection['merchant_id']);
      $config->set('site_id', $siteId);
      $config->set('secret_reference', (string) ($env['secret_reference'] ?? ''));
      $form_state->setValue('connect_code', '');
      $this->messenger()->addStatus($this->t('Connected to AiFinPay as @merchant. The new secret is stored on this server only.', ['@merchant' => $connection['merchant_id']]));
      if (($env['merchant_id'] ?? '') !== '' && $env['merchant_id'] !== $connection['merchant_id']) {
        $this->messenger()->addWarning($this->t('The environment names merchant @env, not @merchant, so the connected secret is not used. Remove the merchant ID environment variable or connect with a code for @env.', ['@env' => (string) $env['merchant_id'], '@merchant' => $connection['merchant_id']]));
      }
      if (($env['secret'] ?? '') !== '') {
        $this->messenger()->addWarning($this->t('The environment variable @var is set and takes precedence, and the code just made its value stale. Remove that variable so the connected secret is used.', ['@var' => (string) ($env['secret_reference'] ?? '')]));
      }
      $bound = SiteBinding::get();
      $record = $this->merchantRecord()->get($bound, TRUE);
      if ($record !== NULL && $record['site_host'] === NULL && !array_key_exists('trusted_hosts', $bound)) {
        $this->messenger()->addWarning($this->t('This merchant has no site URL in AiFinPay. Set it in the dashboard: agents are charged only on that host.'));
      }
      elseif ($record !== NULL && $record['site_host'] !== NULL && $record['site_host'] !== $this->getRequest()->getHost() && !array_key_exists('trusted_hosts', $bound)) {
        $this->messenger()->addWarning($this->t('Agents are charged only on @host, the site URL of this merchant in AiFinPay; this page was opened on @current.', ['@host' => $record['site_host'], '@current' => $this->getRequest()->getHost()]));
      }
    }
    $config->save();
    parent::submitForm($form, $form_state);
  }

  private function merchantRecord(): MerchantRecord {
    return \Drupal::service('aifinpay.merchant_record');
  }

  /** Connected merchant, network, site, hosts, mode and approval; never the secret. */
  private function statusTable(array $config, array $identity): array {
    $merchant = (string) ($identity['merchant_id'] ?? '');
    $record = $merchant !== '' ? $this->merchantRecord()->get($identity) : NULL;
    $resolved = MerchantRecord::resolve($identity, $record);
    $source = ($identity['credential_source'] ?? '') === 'connect_code' ? $this->t('connect code') : $this->t('settings.php');
    $hosts = $resolved['trusted_hosts'] ?? [];
    $mode = !empty($config['enabled']) ? (string) ($config['mode'] ?? 'SHADOW') : 'OFF';
    $rows = [
      [$this->t('Merchant'), $merchant === '' ? $this->t('Not connected') : $this->t('@merchant@name (@source)', ['@merchant' => $merchant, '@name' => isset($record['name']) ? ' — ' . $record['name'] : '', '@source' => $source])],
      [$this->t('Credential'), strlen((string) ($identity['secret'] ?? '')) >= 32 ? $this->t('Stored on this server') : $this->t('Missing')],
      [$this->t('AiFinPay network'), match ($record['network_mode'] ?? NULL) { 'test' => $this->t('Test: testnet payments only'), 'live' => $this->t('Live: real payments'), default => $this->t('Unknown') }],
      [$this->t('Site URL'), $record === NULL ? '—' : (($record['site_url'] ?? NULL) === NULL ? $this->t('Not set in AiFinPay') : $this->t('@url (domain @state)', ['@url' => $record['site_url'], '@state' => $record['site_verified'] ? $this->t('verified') : $this->t('not verified')]))],
      [$this->t('Charging hosts'), $hosts === [] ? $this->t('None') : $this->t('@hosts (@source)', ['@hosts' => implode(', ', $hosts), '@source' => ($resolved['trusted_hosts_source'] ?? '') === 'merchant_record' ? $this->t('site URL in AiFinPay') : $this->t('settings.php')])],
      [$this->t('Mode'), match ($mode) { 'ENFORCE' => $this->t('Enforce: agents pay'), 'SHADOW' => $this->t('Shadow: agents observed, nothing charged'), default => $this->t('Off') }],
      [$this->t('Charging approved'), $this->approvalText(MerchantRecord::approvalReason($resolved, $record), ReceiptGate::approved($resolved))],
      [$this->t('Who reads for free'), $this->freeReadersText($config)],
    ];
    return ['#type' => 'table', '#rows' => array_map(static fn(array $r): array => [['data' => $r[0], 'header' => TRUE], $r[1]], $rows)];
  }

  private function approvalText(string $reason, bool $approved): \Stringable {
    return match ($reason) {
      'settings_php' => $approved ? $this->t('Yes (settings.php)') : $this->t('No: settings.php does not approve the self-hosted gate on this environment.'),
      'test_merchant' => $this->t('Yes: test merchant, testnet payments only.'),
      'live_domain_verified' => $this->t('Yes: live merchant, site domain verified in AiFinPay.'),
      'live_domain_unverified' => $this->t('No: verify the domain of the site URL in the AiFinPay dashboard to charge real payments.'),
      'not_connected' => $this->t('No: not connected. Paste a connect code above.'),
      default => $this->t('No: the AiFinPay merchant record could not be read. Try again later.'),
    };
  }

  /** What the setting does now, and why `proven` is not active yet. */
  private function freeReadersText(array $config): \Stringable {
    $missing = FreeReaders::missing($config, ReaderKeys::get());
    return match (FreeReaders::chosen($config)) {
      FreeReaders::NAMED => $this->t('Everyone who does not name itself an AI agent'),
      FreeReaders::EVERYONE => $this->t('Nobody: every request without a receipt pays'),
      default => $missing === []
        ? $this->t('Readers who passed the browser check, and verified search engines')
        : $this->t('Nobody yet: every request without a receipt pays until @missing @verb set.', [
          '@missing' => implode(', ', array_map(static fn(string $m): string => ['turnstile_sitekey' => 'the Turnstile site key', 'turnstile_secret' => 'the Turnstile secret key', 'reader_key' => 'the reader key (saved with this form)'][$m], $missing)),
          '@verb' => count($missing) === 1 ? 'is' : 'are',
        ]),
    };
  }

  private function fieldNames(string $text): array {
    return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', $text) ?: []), static fn(string $s): bool => $s !== '')));
  }

  private function lines(string $text): array {
    return array_values(array_filter(array_map('trim', explode("\n", $text)), static fn(string $s): bool => $s !== ''));
  }

}

