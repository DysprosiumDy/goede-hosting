# Goede Hosting plugin

Basic functionality for every WordPress site Goede Hosting builds. Generic: no client details, texts or design
are built in; every site fills its own details and every theme can adapt fields, markup and styling.

Requires WordPress 6.6+ and PHP 8.5+. Updates itself from the latest GitHub Release (asset `goede-hosting.zip`, checked at most every
six hours; *Dashboard > Updates > Opnieuw controleren* checks right away).

## Modules
Every module can be switched off per site (theme `functions.php` or a must-use plugin):

```php
add_filter( 'goede_hosting_modules', function ( $modules ) {
	return array_diff( $modules, array( 'whatsapp-button' ) );
} );
```

| Module | What | Admin |
| --- | --- | --- |
| `company` | Company details, shortcodes, block bindings | Instellingen > Bedrijfsgegevens |
| `contact-form` | Contact form, stored submissions, privacy tools, daily cleanup | Inzendingen (+ Instellingen) |
| `importer` | Imports a content package (pages, media, company details) | Weergave > Goede Hosting Importeer Inhoud |
| `whatsapp-button` | Floating WhatsApp button (needs `company`) | checkbox in Bedrijfsgegevens |

A theme that still contains one of these features itself (older Goede Hosting themes) is detected: that module stays
off and the admin sees a notice, so updating the plugin before the theme is safe.

## Company details (`company`)
All empty until filled in (or set by a content package). Fields: `email`, `phone`, `phone_e164`, `whatsapp`, `address1`,
`address2`, `kvk` (+ `whatsapp_button`). More fields: filter `goede_hosting_company_fields`
(`key => array( 'label', 'type' => text|email|tel|checkbox, 'help', 'codes' )`).

- PHP: `goede_hosting_company( 'email' )`, `goede_hosting_company_link( 'email' | 'phone' | 'whatsapp' )`.
- Shortcodes: `[goedehosting_email]`, `[goedehosting_telefoon]`, `[goedehosting_whatsapp tekst="…"]`, `[goedehosting_kvk]`,
  `[goedehosting_adres]` (`scheiding="<br>"` for two lines), `[goedehosting_jaar]`; `link="nee"` for plain text.
- Block bindings source `goedehosting/company` (keys `email`, `email_link`, `telefoon`, `telefoon_link`, `whatsapp_link`,
  `kvk`, plus extra fields), e.g. a Button with `{"source":"goedehosting/company","args":{"key":"whatsapp_link","tekst":"Hallo"}}`.

## Contact form (`contact-form`)
Block **Goede Hosting Contactformulier** (`goede-hosting/contact-form`: button text, confirmation, optional recipient) or
`goede_hosting_contact_form( array( 'button_text' => '', 'success_text' => '' ) )` in PHP.

- Fields: filter `goede_hosting_contact_fields`. Default: `naam`, `email`, `bericht`. Per field: `label`, `type`
  (text, email, tel, url, textarea), `required`, `maxlength`, `autocomplete` (name / given-name / family-name make up the
  sender's name), `placeholder`, `summary` (shown in the submission title), `group` + `group_label` (fields side by side
  under one legend), `error`. The first email field is the sender (Reply-To, privacy tools).
- Markup: copy `templates/contact-form.php` to `<theme>/goede-hosting/contact-form.php`.
  Helpers: `goede_hosting_contact_control( $field, $attrs )`, `goede_hosting_contact_error( $field, $class )`.
- Styling: `assets/css/contact-form.css` (neutral). Off: `add_filter( 'goede_hosting_contact_form_css', '__return_false' );`
- Recipient: the block's own address (only on pages written and last edited by an administrator), else
  Inzendingen > Instellingen, else the company e-mail, else the admin e-mail. A theme block can carry a recipient too:
  filter `goede_hosting_contact_form_blocks`.
- Spam protection: nonce, honeypot, minimum fill time (3 s), form valid for 2 hours, 5 attempts per IP per hour
  (IPv6 per /64; every attempt counts). Site-wide, only 30 per hour are mailed: the rest is stored without mail
  (marked in the submission), so a flood can't block the form. An expired session or too fast submission sends the
  visitor back with what they typed (kept 15 minutes).
- Behind a proxy that doesn't restore the visitor's IP: `define( 'GOEDE_HOSTING_PROXY_IP_HEADER', 'HTTP_CF_CONNECTING_IP' );`
  in wp-config.php (or filter `goede_hosting_contact_proxy_ip_header`). Only when every request passes that proxy.
- Submissions: post type `gh_inzending`, read-only, admins only, deleted after the retention period
  (Inzendingen > Instellingen, default 24 months), included in WordPress' personal data export/erase. The IP address
  is only used (hashed) for the limit and not stored with a submission.
- Mail: From "<site name>" with the company e-mail when it is on the site's domain, else `wordpress@<domain>`
  (filter `goede_hosting_contact_from_email`; '' leaves it to WordPress / an SMTP plugin). Reply-To is the sender.
  Make sure the host (or an SMTP plugin) sends with SPF/DKIM for that domain.
- Privacy link under the form: the WordPress privacy page (filter `goede_hosting_contact_privacy_url`).
- Other hooks: `goede_hosting_contact_errors` (extra validation), `goede_hosting_contact_recipient`,
  `goede_hosting_contact_submitted` (action), `goede_hosting_contact_anchor`.
- Page caching: exclude the contact page if you can; a cached form still works once (the visitor is sent back with
  their text and a fresh form).

## Content package (`importer`)
A zip with `manifest.json`, `pages/<key>.html` (block markup) and `media/<file>` (jpg, png, webp, svg; max 20 MB in total).

```json
{
  "format": 1,
  "pages": [ { "key": "contact", "title": "Contact", "slug": "contact", "parent": null, "menu_order": 4, "front_page": false, "file": "pages/contact.html" } ],
  "media": [ { "file": "team.webp", "alt": "…", "title": "…" } ],
  "company": { "email": "info@example.nl" }
}
```

In the page markup `{{media:<file>}}` becomes the media URL and `{{media-id:<file>}}` its ID. Pages are matched by path
(parent slug + slug): running it again updates instead of duplicating; pages edited by hand since the last import are
only overwritten when ticked. Media are matched by content hash. Company details only fill empty fields unless
"overwrite" is ticked. The package is unpacked into the system temp dir and nothing in it is executed.

The importer module also resets page templates the active theme doesn't have (left behind by a previous theme), once
per theme, so pages can be saved again.

## WhatsApp button (`whatsapp-button`)
Off until *Zwevende WhatsApp-knop tonen* is ticked in Bedrijfsgegevens and a WhatsApp number is set. Class
`.gh-whatsapp-button` (stylesheet handle `goede-hosting-whatsapp`); filters `goede_hosting_whatsapp_button_link`,
`goede_hosting_whatsapp_button_html`.

## Development
WordPress Coding Standards + PHP 8.5 compatibility: `phpcs` in this folder (`phpcs.xml.dist`), `phpcbf` fixes spacing.
Release: bump `Version` in `goede-hosting.php` and `GOEDE_HOSTING_VERSION`, zip the folder as `goede-hosting/…` into
`goede-hosting.zip`, and publish a GitHub Release `v<version>` with that asset.
