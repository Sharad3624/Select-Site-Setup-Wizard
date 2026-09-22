# Site Setup Wizard

A step-by-step WordPress admin wizard (**Tools → Site Setup Wizard**) that automates first-run setup on a fresh install: theme, content wipe, homepage, a fixed plugin stack, WooCommerce, and Elementor site settings.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Usage: step by step](#usage-step-by-step)
- [Setting up WP Mail SMTP (Gmail)](#setting-up-wp-mail-smtp-gmail)
- [Troubleshooting](#troubleshooting)
- [Security notes](#security-notes)
- [File structure](#file-structure)

## Requirements

- WordPress, **single-site only**. The wizard refuses to run on multisite: it manages the shared `wp-content/plugins` directory, and `manage_options` alone doesn't map onto the network-level `install_plugins` / `activate_plugins` / `delete_plugins` capabilities multisite actually needs for that.
- An admin user (`manage_options`).
- Outbound HTTPS access from the server to `underscores.me`, `api.wordpress.org` / `downloads.wordpress.org`, and (if using Identity Mirror or installing Elementor Pro's Theme Builder pieces) `api.github.com` / `codeload.github.com`.
- PHP 7.4+.

## Installation

Pick one:

**A. Copy the folder (local dev, e.g. XAMPP/MAMP/Local)**
1. Copy this repo's contents into `wp-content/plugins/site-setup-wizard/` (so `wp-content/plugins/site-setup-wizard/site-setup-wizard.php` exists).
2. In wp-admin, go to **Plugins** and activate **Site Setup Wizard**.

**B. Upload as a zip**
1. Zip this repo's contents (the zip's top-level folder should be named `site-setup-wizard`).
2. In wp-admin, go to **Plugins → Add New → Upload Plugin**, choose the zip, install, then activate.

**C. Clone directly into the site**
```bash
cd wp-content/plugins/
git clone https://github.com/Sharad3624/Select-Site-Setup-Wizard.git site-setup-wizard
```
Then activate from the Plugins screen.

After activating, open **Tools → Site Setup Wizard**.

## Usage: step by step

The wizard is linear — one step at a time, with Back/Continue navigation and a progress bar during each running step. A step only advances once its AJAX call actually succeeds; a failure keeps you on the same step so you can retry or adjust the inputs.

### Step 1 — Theme from Underscores.me
Enter a theme name and continue. The wizard POSTs to `underscores.me`'s generator and installs the resulting zip. If that generator is unreachable, it silently falls back to installing the base [`_s`](https://github.com/Automattic/_s) theme from GitHub instead, and tells you which one happened.

### Step 2 — Wipe default content
**Destructive.** Deletes every other installed plugin, all posts (any status, not just published), and any page whose title or content contains "hello world" (catches the default Hello World post and any leftover sample content). Optionally also deletes the default "Sample Page."

This requires an explicit **"Yes, I understand this will delete existing content"** confirmation — enforced both by disabling the button client-side *and* by refusing the request server-side if the confirmation flag isn't set, so it can't be triggered by accident (e.g. a stray API call) even if you already have a valid session.

### Step 3 — Create & set homepage
Creates a new published page with the title you give it and sets it as the static front page (Settings → Reading).

### Step 4 — Install plugin stack
Installs and activates, one at a time with a progress bar:
Elementor, WP Mail SMTP, Yoast SEO, Duplicate Page, Secure Custom Fields, Permalink Manager, Classic Editor — all from wordpress.org via WordPress's own plugin installer APIs.

**Elementor Pro / ProElements** isn't on wordpress.org (license-gated), so this step also offers a manual zip upload field — download it yourself from [proelements.org](https://proelements.org/) (or your Elementor Pro account) and upload it here.

### Step 5 — WooCommerce
Yes installs and activates WooCommerce from wordpress.org; No skips it. Either way the wizard continues to Step 6.

### Step 6 — Elementor site settings
- **Identity**: either fill in site title / tagline / logo (applied to both WordPress core options and Elementor's active Kit), or check the box to install & use [Identity Mirror](https://github.com/Sharad3624/Identity-Mirror) instead.
- **Breakpoints**: two ready-made presets (7-tier with Widescreen, or 6-tier without) or fully custom values per device. Applied to the active Elementor Kit's `viewport_*` settings, with Elementor's "Additional Custom Breakpoints" experiment enabled automatically as needed.
- **Container width**: a responsive value per breakpoint tier (Widescreen/Desktop/Laptop/Tablet Landscape/Mobile), each with its own unit (px, %, em, rem, vw, custom). Devices not listed inherit the next-wider tier's value, matching Elementor's own responsive-control cascade.
- **Container padding**: top/right/bottom/left with a selectable shared unit.
- **Page layout**: sets the Kit's default page template to "Elementor Full Width."
- **Header/Footer templates**: creates blank Header and Footer templates in Elementor's Theme Builder. If Elementor Pro/ProElements is active, they're also set to display site-wide ("Entire Site" condition); otherwise they're left as blank saved templates for you to wire up once Pro is installed.

## Setting up WP Mail SMTP (Gmail)

Step 4 installs WP Mail SMTP but doesn't configure a mailer — that needs your own Google Cloud OAuth credentials:

1. In [Google Cloud Console](https://console.cloud.google.com/), create (or reuse) an OAuth 2.0 Client ID of type "Web application."
2. Add `https://connect.wpmailsmtp.com/google/` as an **Authorized redirect URI**. WP Mail SMTP always routes the OAuth callback through WPForms' relay, even when you supply your own Client ID/Secret — not your site's own URL.
3. If the OAuth consent screen is still in "Testing" publishing status, add the Gmail account you'll authenticate as under **OAuth consent screen → Test users**, or Google will block the login.
4. In wp-admin, go to **Settings → WP Mail SMTP**, set the mailer to **Gmail**, and paste in your Client ID and Client Secret, and a From Email matching the Gmail account. (Store these as regular settings here, not as `wp-config.php` constants — constants lock the fields as read-only in this screen.)
5. Go to the **Authorize** tab and click **"Allow plugin to send emails using your Google account."** This step requires you to log into that Google account in your browser and grant consent — it can't be automated or done on your behalf.

## Troubleshooting

**WordPress keeps asking for FTP credentials on every install/update.**
This happens when the WordPress files are owned by a different OS user than the one PHP/Apache runs as (common on XAMPP/MAMP, where you own the files but Apache runs as `daemon`/`_www`). WordPress compares file ownership to decide whether it can write directly; if they don't match, it assumes it needs FTP. Fix: add to `wp-config.php`:
```php
define('FS_METHOD', 'direct');
```
This is safe as long as the web server user actually has write access to `wp-content` (see next item).

**A step fails partway through unpacking/installing, with no clear reason.**
Check that `wp-content/upgrade/`, `wp-content/plugins/`, `wp-content/themes/`, and `wp-content/uploads/` are writable by the user PHP/Apache runs as — not just readable. On a local install where you created these directories yourself (e.g. via WP-CLI as your own user) while the web server runs as a different user, they can end up without the group-write bit the web server needs:
```bash
chmod -R g+w wp-content/upgrade wp-content/plugins wp-content/themes wp-content/uploads
```

**Header/Footer Theme Builder templates don't show up on the front end.**
This requires Elementor Pro/ProElements *and* a theme that either natively supports Elementor's theme-builder hooks (e.g. Hello Elementor) or gets patched by Pro's own compatibility shim. A freshly generated `_s`/underscores.me theme has no built-in awareness of Elementor at all; if the header/footer don't appear after Step 6, try switching to the Hello Elementor theme.

## Security notes

- Every AJAX action requires both a valid nonce and `manage_options` — none are registered for logged-out users.
- Step 2's destructive confirmation is enforced server-side, not just by disabling a button in the UI.
- The plugin refuses to run at all on multisite (see [Requirements](#requirements)).
- Every remote download goes to a hardcoded HTTPS host — `underscores.me`, wordpress.org (via WordPress core's own trusted installer APIs, the same path "Add New Plugin" uses), or GitHub, pinned to a specific repo. User input never constructs a download URL, so there's no way to redirect an install elsewhere.
- The manual plugin-zip upload is capability-gated to admins only — the same trust level as WordPress core's native "Upload Plugin" screen, not a privilege escalation.

## File structure

```
site-setup-wizard/
├── site-setup-wizard.php        # Bootstrap, admin page (HTML/CSS/JS), AJAX routing
├── includes/
│   └── class-ssw-steps.php      # All step logic (SSW_Steps class)
└── README.md
```
