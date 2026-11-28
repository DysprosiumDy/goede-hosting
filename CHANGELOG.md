# Changelog

## 1.2.0 (2026-11-28)
- **Modules in the admin:** menu **Goede Hosting > Modules** to switch modules on and off (administrators), with descriptions, settings links and dependencies (switching a module on brings along what it needs; switching one off takes the modules that need it along). Code can still switch modules off for good (filter `goede_hosting_modules`). Other plugins add modules with `goede_hosting_register_module()` on `goede_hosting_register_modules`.
- **Form builder** (module `contact-form`, now "Formulieren"): any number of forms in **Goede Hosting > Formulieren**, built in the block editor with the block "Formulierveld" and placed with the block "Goede Hosting Formulier" or `[goedehosting_formulier id="…"]`.
  - Field types: text, textarea, e-mail, phone, URL, number, dropdown, radio buttons, checkboxes, date, file upload, consent, hidden field; required, placeholder, help text, options, default value, half width, min/max.
  - Conditional logic: show or hide fields based on other answers (also enforced on the server: hidden fields are not required and their answers are dropped).
  - Multi-step forms with the block "Volgende stap" (progress bar, per-step check).
  - Form settings: button text, confirmation, recipient (administrators only).
  - File uploads: per-field types within a safe list, real content check, stored only for valid submissions under random names in a protected folder, downloadable by administrators only, deleted with the submission.
  - Submissions per form: column and filter "Formulier" in Inzendingen, files linked in the submission.
- The theme's contact form works as before (same fields filter, template and submissions).
- Hidden fields always use the value set in the builder (a visitor can't change them).

## 1.1.1 (2026-11-28)
- Contact form:
  - The site-wide limit (30 per hour) no longer blocks the form: above it messages are stored but not mailed, and the visitor sees the normal confirmation. The limit per IP address (5 per hour) now counts every attempt.
  - An expired session or form (cached page, form older than 2 hours) or a too fast submission keeps what the visitor typed, instead of losing it.
  - The hashed IP address is no longer stored with a submission (it was only needed for the limit); the daily cleanup removes it once from existing ones.
  - Mail has a From header with the site name and an address on the site's own domain (filter `goede_hosting_contact_from_email`).
  - Behind a proxy that doesn't pass the real IP address: set `GOEDE_HOSTING_PROXY_IP_HEADER` (e.g. `HTTP_CF_CONNECTING_IP`) or the filter `goede_hosting_contact_proxy_ip_header`.
  - After a failed or successful submission the message gets keyboard/screen reader focus. Fields inside a group (first/last name) have visible labels instead of placeholder text. Link to the privacy statement under the form (`goede_hosting_contact_privacy_url`, default the WordPress privacy page).
- Importer: SVG files must be UTF-8 and are checked for a DOCTYPE after parsing too; attributes are checked by local name (any namespace prefix). The temp dir fallback in uploads is now `uploads/.ht-goedehosting-import` (denied by the usual nginx rules as well); old temp dirs in the uploads root are still cleaned up.

## 1.1.0 (2026-11-28)
- New modules, moved from the Goede Hosting theme and made generic (no client details or texts built in):
  - **company**: Instellingen > Bedrijfsgegevens, shortcodes, block bindings source `goedehosting/company`. All fields empty by default; extra fields via a filter.
  - **contact-form**: block "Goede Hosting Contactformulier", stored submissions (Inzendingen), Inzendingen > Instellingen (recipient, retention), privacy export/erase, daily cleanup. Fields, markup (template override) and CSS adjustable per theme.
  - **importer**: Weergave > Goede Hosting Importeer Inhoud (content packages), plus the reset of page templates a previous theme left behind.
  - **whatsapp-button**: floating WhatsApp button, switched on in Bedrijfsgegevens. Shows the official WhatsApp logo (inline SVG) on a green 50px circle, like the button on goedehosting.nl.
- Modules can be switched off with the filter `goede_hosting_modules`; a theme that still contains a feature itself keeps it (the module stays off, with a notice).
- Existing data keeps working: same option names, post type `gh_inzending`, meta keys and shortcodes as the theme used.
- Update check: cached for six hours (GitHub API rate limit), version header and constant now match.
- Code follows the WordPress Coding Standards.

## 1.0.5 (2026-11-06)
- Scroll reveal helpers (`.gh-reveal`) and automatic updates from GitHub Releases.
