# Wonderflow for WordPress

The clinic website's entry point into the Wonderflow CRM: it stores the origin of each
visit, sends the forms to the CRM from the server and tags WhatsApp clicks with a Ref
code that the CRM matches when the message arrives (through Telnyx).

API reference: `docs/api-ingest.md` in the CRM repository (`POST /v1/leads`,
`POST /v1/events`). PHP 7.4+, WordPress 6.5+, no runtime dependencies.

## How it works

| Piece | File | What it does |
|---|---|---|
| Settings | `includes/class-wonderflow-settings.php`, `class-wonderflow-admin.php` | Settings -> Wonderflow: endpoint, server key (`whk_`, never printed back nor sent to the browser), country, consent text version, field mapping, WhatsApp number and message, "Test connection", send log |
| Attribution | `assets/wonderflow.js`, `class-wonderflow-attribution.php` | Cookies `wf_first` (first origin, 90 days) and `wf_last` (latest new origin); hidden fields `wf_first_touch` / `wf_last_touch` added to every form on submit |
| Forms | `class-wonderflow-forms.php` | Server-side adapters; fields detected by type and name (pt and en), or a manual JSON mapping per form; consents `data_processing`, `utility`, `marketing` |
| Queue | `class-wonderflow-queue.php` | Table `{prefix}wonderflow_queue`; WP-Cron sends right away and every 5 minutes; retries network, 429 and 5xx errors with 1, 2, 4... minutes (8 attempts), never 4xx; payload deleted when settled; log kept 30 days |
| WhatsApp | `assets/wonderflow.js`, `class-wonderflow-whatsapp.php` | Ref of 5 characters from `23456789ABCDEFGHJKMNPQRSTUVWXYZ`; anonymous click by `sendBeacon` to `POST /wp-json/wonderflow/v1/click` (20 per IP per minute), forwarded with the key to `POST /v1/events`; shortcode `[wonderflow_whatsapp label="..."]` |

### Consent detection

Nothing is stored until analytics consent is given when one of these answers; with no
consent tool the origin is stored (first-party, functional attribution).

| Tool | Read | Change event |
|---|---|---|
| WP Consent API | `wp_has_consent('statistics')` | `wp_listen_for_consent_change` |
| Complianz | `cmplz_has_consent('statistics')` | `cmplz_status_change`, `cmplz_fire_categories` |
| Cookiebot | `Cookiebot.consent.statistics` | `CookiebotOnAccept` |
| CookieYes | `getCkyConsent().categories.analytics`, or the `cookieyes-consent` cookie (`analytics:yes`) | `cookieyes_consent_update` |

### WhatsApp detection

Links to `wa.me`, `api.whatsapp.com`, `web.whatsapp.com` and `whatsapp://` (the
Elementor button and WP Social Chat render plain links), plus every `window.open` call
to those URLs (Joinchat, Click to Chat). Group invites (`chat.whatsapp.com`) are left
alone. When a number is set, only links to it get a Ref. A link with a message keeps it
and gets ` Ref XXXXX` at the end; a link without one gets the configured message.

### Personal data

- The free-text message of a form is never sent (it may hold health data; the CRM
  never stores it either).
- The queue keeps the payload only until the send is settled; the log has kind, form
  name, status, attempts, code and dates.
- Nothing is logged with `error_log`. The click route stores no IP (a hashed key in a
  2-minute transient for the rate limit).

### Hooks

- `wonderflow_lead` (filter): `( array|false $body, string $form_key )`, change or skip
  a lead before it is queued.

## Development

```bash
npm -g i @wordpress/env   # once
wp-env start              # http://localhost:8888 (admin / password), with Contact Form 7
composer install && composer lint   # PHPCS (WordPress standards + PHP 7.4 compatibility)
```

For a local CRM, set the endpoint to `http://host.docker.internal:54321/functions/v1/ingest`
(the settings only accept https in production: use a tunnel or edit the option with
`wp-env run cli wp option patch update wonderflow_settings endpoint <url>`).

## Release

Bump `Version:` in `wonderflow.php` and `Stable tag:` in `readme.txt`, then push a tag
`vX.Y.Z`. The GitHub Action checks PHP 7.4 / 8.3 syntax and PHPCS, and attaches
`wonderflow-vX.Y.Z.zip` to the release.

## Sites without WordPress (Google Tag Manager)

See `docs/gtm.md`.

## Install guide for a clinic site

`docs/guia-instalacao.md` (pt-PT, for the team).
