<?php

declare(strict_types=1);

namespace Drupal\aifinpay\Render;

/** The scripts and pages an unproven browser is sent; markup only, no decisions. */
final class HeldPage {

  public const TURNSTILE = '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=aifpReady&amp;render=explicit" async defer></script>';
  public const ROBOTS_META = '<meta name="robots" content="noindex">';

  private static function js(mixed $value): string {
    return (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
  }

  public static function termsTag(array $terms): string {
    return '<script type="application/json" id="aifp-402">' . json_encode($terms, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
  }

  private static function passCheck(string $sitekey, string $checkUrl, string $onPass, string $onRefused): string {
    return 'window.aifpReady=function(){turnstile.render("#aifp-w",{sitekey:' . self::js($sitekey) . ',appearance:"interaction-only","error-callback":' . $onRefused . ','
      . 'callback:function(token){fetch(' . self::js($checkUrl) . ',{method:"POST",credentials:"same-origin",headers:{"content-type":"application/json"},body:JSON.stringify({token:token})})'
      . '.then(function(r){return r.status===204?' . $onPass . '():' . $onRefused . '();}).catch(' . $onRefused . ');}});};';
  }

  /** On a held page: pass the check, then fill each holder from the page itself, or reload. */
  public static function fillScript(string $sitekey, string $checkUrl): string {
    return '<script>(function(){var holds=document.querySelectorAll("[data-aifp-hold]");if(!holds.length)return;'
      . 'var w=document.createElement("div");w.id="aifp-w";holds[0].insertBefore(w,holds[0].firstChild);'
      . 'function refused(){for(var i=0;i<holds.length;i++){var m=holds[i].querySelector(".aifp-m");if(m)m.textContent="This part of the page is paid for automated access.";}}'
      . 'function fill(){return fetch(location.href,{credentials:"same-origin",headers:{accept:"text/html"}}).then(function(p){'
      . 'if(p.status!==200)return location.reload();'
      . 'return p.text().then(function(t){var doc=new DOMParser().parseFromString(t,"text/html"),paid=doc.querySelectorAll("[data-aifp-paid]"),used={},pairs=[];'
      . 'for(var i=0;i<holds.length;i++){var key=holds[i].getAttribute("data-aifp-hold"),k=used[key]||0,n=0,found=null;used[key]=k+1;'
      . 'for(var j=0;j<paid.length;j++){if(paid[j].getAttribute("data-aifp-paid")===key){if(n===k){found=paid[j];break;}n++;}}'
      . 'if(!found)return location.reload();pairs.push([holds[i],found]);}'
      . 'pairs.forEach(function(p){p[0].innerHTML=p[1].innerHTML;p[0].setAttribute("data-aifp-paid",p[1].getAttribute("data-aifp-paid"));p[0].removeAttribute("data-aifp-hold");});});}).catch(function(){location.reload();});}'
      . self::passCheck($sitekey, $checkUrl, 'fill', 'refused') . '})();</script>' . self::TURNSTILE;
  }

  /** On a free page: pass the check unseen, so the next paid page opens whole. */
  public static function quietScript(string $sitekey, string $checkUrl): string {
    return '<div id="aifp-w" hidden></div><script>(function(){function done(){}' . self::passCheck($sitekey, $checkUrl, 'done', 'done') . '})();</script>' . self::TURNSTILE;
  }

  /** When the page cannot be shown with its paid part held back. */
  public static function barePage(array $terms, string $sitekey, string $checkUrl): string {
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
      . self::ROBOTS_META . '<title>Payment required</title>'
      . '<style>body{font:16px/1.5 system-ui,sans-serif;margin:0;display:grid;place-items:center;min-height:100vh;color:#1c2430;background:#f6f8fb}main{max-width:34rem;padding:2rem}</style></head>'
      . '<body><main><div id="aifp-w"></div><p id="aifp-m">' . PaidFields::HOLD_MESSAGE . '</p>'
      . '<p>Automated clients: this page is paid. The price and how to pay are in this response (HTTP 402).</p></main>'
      . self::termsTag($terms)
      . '<script>(function(){function refused(){document.getElementById("aifp-m").textContent="This page is paid for automated access.";}function reload(){location.reload();}'
      . self::passCheck($sitekey, $checkUrl, 'reload', 'refused') . '})();</script>' . self::TURNSTILE . '</body></html>';
  }

  /** The HTML with $snippet before its last </body>, or NULL when there is none. */
  public static function beforeBodyEnd(string $html, string $snippet): ?string {
    $at = strripos($html, '</body>');
    return $at === FALSE ? NULL : substr($html, 0, $at) . $snippet . substr($html, $at);
  }

}
