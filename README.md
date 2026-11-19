# NextaPost — self-hosted social media content calendar

Plan, approve and publish social media posts from one calendar. Built in plain **PHP 8 + MySQL + Tailwind CSS + Alpine.js** so it runs on ordinary **cPanel hosting**: no Docker, Node, Redis or queue daemon needed.

By **Nexta Web Solution Pvt. Ltd.** · Version 1.0.1

---

## Features (v1.1)

| Area | What you get |
|---|---|
| **Calendar** | Week / Month / List views, drag-and-drop rescheduling, unscheduled-drafts panel, "now" line, per-workspace time zone, keyboard shortcuts (← → T N) |
| **Composer** | One text for all channels plus per-channel customisation, live character counters per network (X counts links as 23), media upload and library, reordering, live preview, Post now / Schedule / Draft / Submit for review |
| **Documents & carousels** | Upload PDF, PowerPoint (PPTX) or Word (DOCX) and post it as a swipeable document on LinkedIn (profiles and Pages), or as a file on Telegram and Discord. Different media per channel in one post (e.g. the PDF for LinkedIn, the slides as images for Instagram). Files are scanned (no scripts, macros, embedded files, encryption or remote templates) and kept private. |
| **Networks** | Facebook Pages, Instagram (feed, carousel, Reels), LinkedIn profiles and company Pages, X/Twitter, Telegram, Discord, Mastodon, Bluesky, plus a Sandbox test channel |
| **Teams & agencies** | Unlimited workspaces (one per client/brand), roles (Admin, Editor, Contributor, Approver, Viewer), approval workflow with "request changes" notes, activity log |
| **Publishing engine** | Cron-driven job queue with atomic claims, automatic retries with back-off (1 / 5 / 15 min), partial-publish tracking, token refresh, channels flagged for reconnect on auth errors |
| **Light / dark / auto theme** | Saved per user; the whole UI uses semantic colour tokens |
| **Mobile responsive** | Phone-first List view, slide-out navigation, stacked composer with Write/Preview tabs, no horizontal scrolling |
| **Web installer** | `/install`: server check, database setup, admin account; locks itself afterwards |
| **Auto-updater** | One-click or automatic updates from your update server, SHA-256 + Ed25519 signature verification, full backup first, automatic rollback |
| **Storage clean-up** | Per workspace, off by default: delete images/videos of published posts after 30–365 days (the post text and links stay), delete old post history, delete unused uploads. Drafts, scheduled posts and files they use are never touched. Runs daily. |
| **Privacy** | Erase a person's account (System → Users → Delete), activity-log IPs kept 90 days, deleted workspaces purged after 30 days |
| **Backups** | Code + `.env` + full SQL dump, downloadable from System → Backups |
| **Security** | Audited in 1.0.1 (see SECURITY-AUDIT.md): CSP with nonces, CSRF on every write, Argon2id passwords, session timeouts, rate limits, SSRF guard with DNS pinning, encrypted tokens, upload MIME sniffing and pixel limits, no-exec media folder, signed updates |

---

## Requirements

- PHP **8.1+** (8.3 recommended) with: `pdo_mysql`, `curl`, `mbstring`, `sodium`, `fileinfo`, `gd`, `zip`, `openssl` (+ `intl` recommended)
- MySQL **5.7+** or MariaDB **10.3+**
- HTTPS (AutoSSL). Facebook, Instagram, LinkedIn and X require it.
- A cron job that runs every minute

## Install on cPanel (about 5 minutes)

Choose one layout:

| | **A. Recommended: document root = `public/`** | **B. Simple: everything in `public_html`** |
|---|---|---|
| How | Extract to `~/nextapost/`, point the domain or subdomain document root to `~/nextapost/public` | Extract the *contents* of `nextapost/` straight into `public_html` (or a subdomain folder) |
| URLs | `https://social.yourdomain.com/login` | Same clean URLs; the root `.htaccess` sends requests to `public/` internally |
| Security | `.env`, backups, logs and code sit **outside** the web root, so they can't be reached even if a server rule fails | Protected by `.htaccess` deny rules only. Safe on Apache/LiteSpeed (cPanel); **don't use on Nginx** |

1. **Create a subdomain**, e.g. `social.yourdomain.com` (layout A: set its *Document Root* to `nextapost/public`).
2. **Upload** the zip with File Manager and **Extract** it (layout A into your home folder; layout B into the site folder).
3. **Create a database:** cPanel → *MySQL Databases* → create a database and a user, then add the user to the database with **ALL PRIVILEGES**.
4. **Select PHP 8.1+:** cPanel → *Select PHP Version* (or *MultiPHP Manager*). Enable the extensions listed above.
5. Open `https://social.yourdomain.com/` and follow the **installer**.
6. **Add the cron job:** cPanel → *Cron Jobs* → *Once Per Minute* → command:
   ```
   /usr/local/bin/php /home/CPANELUSER/nextapost/bin/cron.php >/dev/null 2>&1
   ```
   (System → Health shows the exact command with your path.)
7. Sign in → **Channels** → connect a channel (try *Sandbox* first) → **Create post**.

Security notes: keep the document root on `/public` (required on Nginx, where `.htaccess` files are ignored), keep `APP_DEBUG="false"`, and use HTTPS. The installer only accepts a **local** database. To use a remote database server, create an empty file `storage/allow-remote-db` first.

Optional: System → **Email** (SMTP for password resets and invites), System → **General** (white-label product name, public sign-ups).

## Connecting the big networks

Create a developer app on each network and paste its keys in **System → Integrations**. Each card shows the exact *Redirect URL* to add to the app.

| Network | Developer app | Notes |
|---|---|---|
| Facebook Pages + Instagram | developers.facebook.com → Business app → *Facebook Login for Business* + Instagram Graph API | Needs **Meta App Review** for `pages_manage_posts`, `instagram_content_publish` before clients can connect. Instagram must be a Business/Creator account linked to a Page. Media must be reachable over HTTPS. |
| LinkedIn (profile) | linkedin.com/developers → products *Share on LinkedIn* + *Sign In with LinkedIn (OpenID Connect)* | Self-serve. Personal profiles. Tokens last 60 days. |
| LinkedIn Page (company) | A **second** app linked to your company Page → request *Community Management API* (keep it the only product on that app) | LinkedIn review: Development tier, then Standard tier with a screencast. The person connecting must be a Page super admin or content admin. |
| X (Twitter) | developer.x.com → OAuth 2.0, Web App, read + write | X bills API usage to your developer account (pay-per-use). |
| Telegram / Discord / Mastodon / Bluesky | No app review: connect with a bot token, webhook, access token or app password | |

### What each network accepts

| Network | Images | Video | Documents (PDF/PPTX/DOCX) | For a "slides" post |
|---|---|---|---|---|
| LinkedIn profile / Page | up to 20 | 1 | **Yes** — 1 file, up to 100 MB / 300 pages, shown as a swipeable carousel | Upload the PDF or PPTX |
| Instagram | carousel up to 10 | Reels | No (Meta API has no document posts) | Export slides as images (1080×1350) → carousel |
| Facebook Page | up to 10 | 1 | No (Pages API has no document posts) | Export slides as images → multi-photo post |
| X | up to 4 | 1 | No | Up to 4 slide images |
| Telegram | album up to 10 | 1 | **Yes** — sent as a file, up to 50 MB | PDF as a file, or images as an album |
| Discord | up to 10 | 1 | **Yes** — attached file, up to 10 MB | PDF attachment |
| Mastodon / Bluesky | 4 | 1 | No | Up to 4 slide images |

When a post has a document and also a channel that can't take documents, the composer says which channel and lets you give that channel its own media with one click. Documents are stored in `storage/documents/` (not in the public folder) and only signed-in members of the workspace can download them. Old `.doc`/`.ppt` files, macro-enabled files and password-protected PDFs are refused — save as PDF/PPTX/DOCX first.

## Updates

**System → Updates** checks `https://updates.nextawebsolution.com` (configurable). An update:

1. downloads the package over HTTPS and verifies its **SHA-256** (and **Ed25519 signature** when a public key is set)
2. writes a full **backup** (code + `.env` + database) to `storage/backups`
3. puts the site in maintenance mode, replaces files, runs migrations
4. **rolls back automatically** if anything fails

It never touches `.env`, `.htaccess` files (cPanel writes PHP-handler lines there), `storage/` or `public/media/`. You can also **upload a .zip** manually or run `php bin/console.php update package.zip`. Automatic installs (via cron) are opt-in.

Updating by copying the files yourself (File Manager, FTP, git) also works: keep your `.env`, `.htaccess` files and `storage/` folder, overwrite the rest, and the database is brought up to date on the next page load. Take a backup first (System → Backups).

### Update server contract

```
GET /api/v1/check?product=nextapost&version=1.0.0&channel=stable&php=8.3.6&site=<hash>&license=<key>
200 {
  "update_available": true,
  "version": "1.0.1",
  "released_at": "2026-12-01",
  "notes": "What's new…",
  "download_url": "https://updates.nextawebsolution.com/dl/nextapost-1.0.1.zip",
  "sha256": "<hex sha256 of the zip>",
  "signature": "<base64 Ed25519 signature of the sha256 hex string>",
  "min_php": "8.1"
}
```

Publishing a release:

```bash
php tools/keygen.php storage/keys            # once; keep update-signing.key secret
echo 1.0.1 > VERSION
php tools/build-release.php --key=storage/keys/update-signing.key --notes="Bug fixes"
# → dist/nextapost-1.0.1.zip + dist/nextapost-1.0.1.json (serve both from the update server)
```

Schema changes after 1.0 go into `database/migrations/YYYY_MM_DD_NNNNNN_name.sql` (MySQL syntax). They're applied once and tracked in the `migrations` table.

## Command line

```bash
php bin/cron.php                     # scheduler (cron, every minute)
php bin/cron.php --no-lock           # extra parallel worker on a VPS
php bin/console.php migrate
php bin/console.php create-admin you@example.com 'password' 'Your Name'
php bin/console.php reset-password you@example.com 'new-password'
php bin/console.php backup
php bin/console.php update dist/nextapost-1.0.1.zip
```

## Development

```bash
# Rebuild CSS after changing classes (Tailwind v4, needs Node only on your machine)
npm i tailwindcss@4 && node tools/build-css.mjs

# Local server + tests (SQLite, no network needed)
php -S 127.0.0.1:8090 -t public tests/router.php
bash tests/run.sh               # 197 integration + 24 updater checks
python3 tests/e2e.py http://127.0.0.1:8090   # Playwright browser test (needs an installed copy)
python3 tests/e2e_docs.py http://127.0.0.1:8090 deck.pdf   # documents + per-channel media (after adding a Mastodon channel named Masto)
php tests/security_setup.php > /tmp/sec.json && python3 tests/security_http.py http://127.0.0.1:8090 /tmp/sec.json   # 53 attack simulations
```

Structure: `src/Core` (router, DB, sessions, crypto, mailer), `src/Services` (posts, publishing, queue, worker, media, updater, backup, installer), `src/Providers` (one class per network), `src/Controllers`, `views/` (PHP templates), `public/assets` (compiled CSS, Alpine.js components), `database/schema.sql`.

### Adding a network

Create `src/Providers/YourNetworkProvider.php` extending `AbstractProvider` (implement `meta()`, `limits()`, `connect()` or the OAuth pair, and `publish()`), then register it in `ProviderFactory::MAP`.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Yellow banner "scheduler cron job isn't running" | Add the cron job (step 6). System → Health → *Run scheduler now* to test. |
| Upload fails for big videos | Raise `upload_max_filesize` and `post_max_size` in cPanel → *MultiPHP INI Editor*. |
| Instagram "media could not be fetched" | Site must be HTTPS and publicly reachable, not behind a login or IP block. |
| Channel shows "Reconnect" | The token expired or was revoked: Channels → Reconnect. |
| 500 error | Check `storage/logs/app-YYYY-MM-DD.log`. Temporarily set `APP_DEBUG="true"` in `.env`. |

## Licence

Proprietary © Nexta Web Solution Pvt. Ltd. Bundled third-party components: Alpine.js (MIT, built from the official source), Tailwind CSS (MIT, build-time only). Network names and logos belong to their owners.
