# Sites without WordPress (Google Tag Manager)

The same script runs in a GTM "Custom HTML" tag with the clinic's **public key**
(`whp_...`, created in the CRM under Settings -> Integrations with the site's exact
origins, e.g. `https://www.clinica.pt`). Public keys only work from those origins and
are limited to 30 requests per minute.

1. In GTM, create a Custom HTML tag fired on "All Pages":

   ```html
   <script>
     window.wonderflowConfig = {
       publicEndpoint: "https://<project>.supabase.co/functions/v1/ingest",
       publicKey: "whp_...",
       whatsapp: { enabled: true, number: "351912345678", message: "Olá! Ref {ref}" }
     };
   </script>
   <script>
     /* paste the content of assets/wonderflow.js here */
   </script>
   ```

2. Mark each form to send with `data-wf-form="<form name>"`, and name its inputs
   `name`, `phone`, `email`, and the consent checkboxes `consent_data_processing`,
   `consent_utility`, `consent_marketing`. Optional: `data-wf-consent-version="site-v1"`.

   ```html
   <form data-wf-form="Marcação" data-wf-consent-version="site-2026-11">
     <input name="name"> <input name="phone"> <input name="email" type="email">
     <label><input type="checkbox" name="consent_utility"> Lembretes por WhatsApp</label>
     <label><input type="checkbox" name="consent_marketing"> Novidades</label>
   </form>
   ```

Notes:

- The form still submits to its own action; the CRM copy is sent in parallel with
  `fetch(..., { keepalive: true })`, so there is no retry queue in this mode.
- WhatsApp clicks are sent to `POST /v1/events` with the public key (needs the
  `whatsapp` feature in the clinic plan).
