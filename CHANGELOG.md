# Changelog

## 1.1.0 (2026-11-28)
- New modules, moved from the Goede Hosting theme and made generic (no client details or texts built in):
  - **company**: Instellingen > Bedrijfsgegevens, shortcodes, block bindings source `goedehosting/company`. All fields empty by default; extra fields via a filter.
  - **contact-form**: block "Goede Hosting Contactformulier", stored submissions (Inzendingen), Inzendingen > Instellingen (recipient, retention), privacy export/erase, daily cleanup. Fields, markup (template override) and CSS adjustable per theme.
  - **importer**: Weergave > Goede Hosting Importeer Inhoud (content packages), plus the reset of page templates a previous theme left behind.
  - **whatsapp-button**: floating WhatsApp button, switched on in Bedrijfsgegevens.
- Modules can be switched off with the filter `goede_hosting_modules`; a theme that still contains a feature itself keeps it (the module stays off, with a notice).
- Existing data keeps working: same option names, post type `gh_inzending`, meta keys and shortcodes as the theme used.
- Update check: cached for six hours (GitHub API rate limit), version header and constant now match.
- Code follows the WordPress Coding Standards.

## 1.0.5 (2026-11-06)
- Scroll reveal helpers (`.gh-reveal`) and automatic updates from GitHub Releases.
