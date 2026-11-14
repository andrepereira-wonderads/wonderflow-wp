=== Wonderflow ===
Contributors: wonderads
Tags: crm, leads, forms, whatsapp, attribution
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends the clinic website's forms and WhatsApp clicks to the Wonderflow CRM, with the origin of each visit.

== Description ==

* Stores the first and the latest origin of each visit (UTM parameters, Google / Meta / Microsoft click ids, referrer, landing page) in a 90-day first-party cookie, only with analytics consent when a consent banner is present (WP Consent API, Complianz, Cookiebot, CookieYes).
* Sends Elementor Pro, Contact Form 7, WPForms, Gravity Forms and Fluent Forms submissions to the CRM from the server, with the origin. The free-text message is never sent.
* Adds a Ref code to WhatsApp links and buttons (wa.me, api.whatsapp.com, whatsapp://, Joinchat, Click to Chat, WP Social Chat, Elementor buttons) so the CRM links the WhatsApp conversation to the visit.
* Failed sends are retried in the background; the visitor never waits for the CRM.

== Installation ==

1. Upload the zip in Plugins -> Add New -> Upload, and activate it.
2. Settings -> Wonderflow: paste the endpoint URL and the server key created in the CRM (Settings -> Integrations).
3. Click "Test connection", then turn on forms and / or WhatsApp tracking.

== Changelog ==

= 0.1.0 =
* First version.
