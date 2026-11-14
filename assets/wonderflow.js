/*
 * Wonderflow: visit attribution and WhatsApp click tracking.
 *
 * - Stores the first origin of the visit (90-day first-party cookie `wf_first`)
 *   and the latest new origin (`wf_last`), only with analytics consent when a
 *   consent banner is present (WP Consent API, Complianz, Cookiebot, CookieYes).
 * - Adds them to every form as hidden fields `wf_first_touch` / `wf_last_touch`.
 * - Tags WhatsApp links and buttons with a 5-character Ref and reports the click
 *   (anonymous) so the CRM can match the message that arrives with that Ref.
 *
 * Config: window.wonderflowConfig = {
 *   clickUrl: "<WordPress REST route>",            // WordPress mode
 *   whatsapp: { enabled, number, message },
 *   publicEndpoint: "https://.../ingest", publicKey: "whp_..." // sites without WordPress
 * }
 */
(function () {
  "use strict";

  var config = window.wonderflowConfig || {};
  var whatsapp = config.whatsapp || {};
  var DAYS = 90;
  var KEYS = ["utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_content", "gclid", "gbraid", "wbraid", "fbclid", "msclkid"];
  var REF_ALPHABET = "23456789ABCDEFGHJKMNPQRSTUVWXYZ";
  var TRAILING_REF = /\s*Ref [2-9A-HJKMNP-Z]{5}\s*$/;

  // ---- consent -------------------------------------------------------------

  /** true / false when a consent tool answers, null when there is none. */
  function analyticsConsent() {
    try {
      if (typeof window.wp_has_consent === "function") return !!window.wp_has_consent("statistics");
      if (typeof window.cmplz_has_consent === "function") return !!window.cmplz_has_consent("statistics");
      if (window.Cookiebot && window.Cookiebot.consent) return !!window.Cookiebot.consent.statistics;
      if (typeof window.getCkyConsent === "function") {
        var cky = window.getCkyConsent();
        return !!(cky && cky.categories && cky.categories.analytics);
      }
      var cookieYes = readCookie("cookieyes-consent");
      if (cookieYes) return /analytics:yes/.test(cookieYes);
    } catch (e) {
      return false;
    }
    return null;
  }

  function canStore() {
    var consent = analyticsConsent();
    return consent === null || consent === true;
  }

  // ---- cookies -------------------------------------------------------------

  function readCookie(name) {
    var match = document.cookie.match(new RegExp("(?:^|; )" + name.replace(/[-.]/g, "\\$&") + "=([^;]*)"));
    return match ? decodeURIComponent(match[1]) : null;
  }

  function readTouch(name) {
    try {
      var value = readCookie(name);
      return value ? JSON.parse(value) : null;
    } catch (e) {
      return null;
    }
  }

  function writeTouch(name, touch) {
    var secure = location.protocol === "https:" ? "; Secure" : "";
    document.cookie = name + "=" + encodeURIComponent(JSON.stringify(touch)) + "; Max-Age=" + DAYS * 86400 + "; Path=/; SameSite=Lax" + secure;
  }

  // ---- attribution ---------------------------------------------------------

  function cut(value, max) {
    return String(value).slice(0, max);
  }

  /** Origin of this page view; `isNew` when it carries a campaign or an external referrer. */
  function pageTouch() {
    var params = new URLSearchParams(location.search);
    var touch = {};
    var isNew = false;
    KEYS.forEach(function (key) {
      var value = params.get(key);
      if (value) {
        touch[key] = cut(value, 200);
        isNew = true;
      }
    });
    var referrer = document.referrer;
    if (referrer) {
      try {
        if (new URL(referrer).hostname !== location.hostname) {
          touch.referrer = cut(referrer, 500);
          isNew = true;
        }
      } catch (e) {
        /* ignore malformed referrers */
      }
    }
    touch.landing_page = cut(location.origin + location.pathname, 500);
    touch.occurred_at = new Date().toISOString();
    return { touch: touch, isNew: isNew };
  }

  var current = pageTouch();
  var stored = false;

  function store() {
    if (stored || !canStore()) return;
    stored = true;
    if (!readTouch("wf_first")) writeTouch("wf_first", current.touch);
    if (current.isNew) writeTouch("wf_last", current.touch);
  }

  function touches() {
    if (!canStore()) return { first: null, last: null };
    return { first: readTouch("wf_first") || current.touch, last: readTouch("wf_last") };
  }

  function onConsentChange() {
    store();
  }

  // Consent tools announce changes with their own events.
  document.addEventListener("wp_listen_for_consent_change", onConsentChange);
  document.addEventListener("cmplz_status_change", onConsentChange);
  document.addEventListener("cmplz_fire_categories", onConsentChange);
  document.addEventListener("cookieyes_consent_update", onConsentChange);
  window.addEventListener("CookiebotOnAccept", onConsentChange);

  // ---- forms ---------------------------------------------------------------

  function setHidden(form, name, value) {
    var input = form.querySelector('input[name="' + name + '"]');
    if (!value) {
      if (input) input.value = "";
      return;
    }
    if (!input) {
      input = document.createElement("input");
      input.type = "hidden";
      input.name = name;
      form.appendChild(input);
    }
    input.value = JSON.stringify(value);
  }

  function tagForm(form) {
    var t = touches();
    setHidden(form, "wf_first_touch", t.first);
    setHidden(form, "wf_last_touch", t.last);
  }

  // Capture phase: runs before the builders read the form (AJAX submissions included).
  document.addEventListener(
    "submit",
    function (event) {
      if (event.target && event.target.tagName === "FORM") {
        tagForm(event.target);
        if (config.publicEndpoint && event.target.hasAttribute("data-wf-form")) sendPublicLead(event.target);
      }
    },
    true
  );

  // ---- WhatsApp ------------------------------------------------------------

  function newRef() {
    var bytes = new Uint8Array(5);
    (window.crypto || window.msCrypto).getRandomValues(bytes);
    var ref = "";
    for (var i = 0; i < 5; i++) ref += REF_ALPHABET.charAt(bytes[i] % REF_ALPHABET.length);
    return ref;
  }

  /** Parsed WhatsApp chat URL, or null. Group invites (chat.whatsapp.com) are left alone. */
  function parseWhatsapp(href) {
    if (!href || typeof href !== "string") return null;
    var url;
    try {
      url = new URL(href, location.href);
    } catch (e) {
      return null;
    }
    var host = url.hostname.replace(/^www\./, "");
    var phone = "";
    if (url.protocol === "whatsapp:") {
      phone = url.searchParams.get("phone") || "";
    } else if (host === "wa.me") {
      phone = url.pathname.replace(/\D/g, "");
    } else if (host === "api.whatsapp.com" || host === "web.whatsapp.com") {
      phone = url.searchParams.get("phone") || "";
    } else {
      return null;
    }
    return { url: url, phone: phone.replace(/\D/g, "") };
  }

  function isClinicNumber(phone) {
    var number = String(whatsapp.number || "");
    return !number || !phone || phone === number || phone.slice(-9) === number.slice(-9);
  }

  /** Returns the URL with the Ref in its message, and reports the click. */
  function tagWhatsapp(href) {
    if (!whatsapp.enabled) return href;
    var parsed = parseWhatsapp(href);
    if (!parsed || !isClinicNumber(parsed.phone)) return href;
    var ref = newRef();
    var text = (parsed.url.searchParams.get("text") || "").replace(TRAILING_REF, "");
    var message = text ? text + " Ref " + ref : String(whatsapp.message || "Ref {ref}").replace("{ref}", ref);
    parsed.url.searchParams.set("text", message);
    reportClick(ref);
    return parsed.url.toString();
  }

  function reportClick(ref) {
    var body = { type: "whatsapp_click", ref: ref, occurred_at: new Date().toISOString() };
    var first = touches().first;
    if (first) body.first_touch = first;
    var json = JSON.stringify(body);

    if (config.clickUrl) {
      // text/plain: a simple request, no preflight, survives the page unload.
      var blob = new Blob([json], { type: "text/plain" });
      if (navigator.sendBeacon && navigator.sendBeacon(config.clickUrl, blob)) return;
      fetch(config.clickUrl, { method: "POST", body: json, keepalive: true, credentials: "same-origin" }).catch(function () {});
    } else if (config.publicEndpoint && config.publicKey) {
      postPublic("/v1/events", json, "click-" + ref);
    }
  }

  document.addEventListener(
    "click",
    function (event) {
      var link = event.target && event.target.closest ? event.target.closest("a[href]") : null;
      if (!link) return;
      var tagged = tagWhatsapp(link.getAttribute("href"));
      if (tagged !== link.getAttribute("href")) link.setAttribute("href", tagged);
    },
    true
  );

  // Buttons that open WhatsApp from script (Joinchat, Click to Chat, ...).
  var nativeOpen = window.open;
  window.open = function (url) {
    var args = Array.prototype.slice.call(arguments);
    if (typeof url === "string") args[0] = tagWhatsapp(url);
    return nativeOpen.apply(window, args);
  };

  // ---- sites without WordPress (public key) --------------------------------

  function postPublic(route, json, idempotencyKey) {
    return fetch(String(config.publicEndpoint).replace(/\/$/, "") + route, {
      method: "POST",
      keepalive: true,
      headers: {
        Authorization: "Bearer " + config.publicKey,
        "Content-Type": "application/json",
        "Idempotency-Key": idempotencyKey,
      },
      body: json,
    }).catch(function () {});
  }

  function fieldValue(form, name) {
    var input = form.querySelector('[name="' + name + '"]');
    if (!input) return undefined;
    if (input.type === "checkbox") return input.checked;
    return input.value ? cut(input.value.trim(), 120) : undefined;
  }

  /** Forms marked data-wf-form with inputs named name / phone / email / consent_*. */
  function sendPublicLead(form) {
    var t = touches();
    var body = {
      name: fieldValue(form, "name"),
      phone: fieldValue(form, "phone"),
      email: fieldValue(form, "email"),
      form_name: cut(form.getAttribute("data-wf-form") || "site", 120),
      website: "",
    };
    var consents = {};
    ["data_processing", "utility", "marketing"].forEach(function (key) {
      var value = fieldValue(form, "consent_" + key);
      if (value !== undefined) consents[key] = !!value;
    });
    if (Object.keys(consents).length) {
      consents.text_version = form.getAttribute("data-wf-consent-version") || "site-v1";
      body.consents = consents;
    }
    if (t.first) body.first_touch = t.first;
    if (t.last) body.last_touch = t.last;
    postPublic("/v1/leads", JSON.stringify(body), "form-" + newRef() + "-" + Date.now());
  }

  // ---- start ---------------------------------------------------------------

  // Wait for the page load so a consent banner script has had time to start;
  // without consent nothing is stored until one of the events above fires.
  if (document.readyState === "complete") store();
  else window.addEventListener("load", store);
})();
