# ICNHAEM-2027 website

A clean, modern rebuild of the ICEAM site for **ICNHAEM-2027** — *International Conference on Nanomaterials, Hydrogen and Advanced Energy Materials and Devices*, **18–20 March 2027**, Banaras Hindu University, Varanasi, India.

Domain: **icnhaem2027.com**

## Pages in this build

| File | Purpose |
| --- | --- |
| `index.html` | Home — hero, countdown, themes, dedication, dates, organisers |
| `about.html` | Scope, themes, full committee and venue |
| `abstract.php` | Abstract guidelines + working submission form |
| `registration.php` | Fees + working registration form |
| `contact.html` | Organisers, message form and travel information |

The navigation bar on every page links to exactly these five pages and works on desktop and mobile (hamburger menu with keyboard/outside-click support).

## Before you deploy

1. **Database** — create a MySQL database and import `schema.sql`.
2. **Credentials** — edit `config.php`:
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
   - `SITE_EMAIL` — the address submissions appear to come from
   - `NOTIFY_EMAILS` — committee inboxes that receive a copy of every submission
3. **Writable folders** — make `uploads/abstract/` and `uploads/registration/` writable by the web server (e.g. `chmod 755` / ownership to the PHP user).
4. **Contact details** — `info@icnhaem2027.com` is a placeholder used in the contact page, forms, footer and confirmation e-mails. Replace it with the real inbox and add phone numbers where marked in `contact.html`.
5. **Dates** — the brochure only confirms the conference dates (18–20 March 2027). Abstract deadlines and other milestones are shown as *To be announced*; update the home page timeline and the notices on `abstract.php` / `registration.php` when confirmed.
6. **Abstract template** — `files/abstract-template.docx` is the generic one-page template copied from the previous site. Replace it if the committee issues an ICNHAEM-branded version.
7. **Payment link** — add the real payment/receipt details to the registration page notice once available.

Upload the whole folder to the web root of `icnhaem2027.com`. The site-wide `.htaccess` blocks direct access to `config.php`, `schema.sql`, `README.md` and PHP execution inside upload directories.

## Submission flows

- **Abstract** — fields are validated server-side, the Word file is checked by MIME type (max 10 MB), a temporary ID `ICNHAEM-ABS-0001` is generated, the row is stored in `abstracts`, the committee is notified by e-mail and the author receives a confirmation with their ID.
- **Registration** — validates the form, uploads payment proof (and student proof for PhD students), stores the row in `registrations`, optionally looks up an abstract ID, generates `ICNHAEM-REG-0001`, and sends notification + confirmation e-mails.

Both forms use CSRF tokens, a honeypot field and a 30-second per-session throttle. All IDs and e-mail content come from the constants in `config.php`.

## Styling

`css/style.css` is a new design system (soft ivory, deep teal and warm gold tones) shared by all pages. `js/main.js` handles the navigation, countdown and scroll reveals; `js/abstract.js` and `js/registration.js` handle the form requests.
