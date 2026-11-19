# NextaPost Security Audit Report

**Scope:** full NextaPost codebase (core PHP 8.3, MySQL/SQLite, Tailwind, Alpine.js): 9 social providers, installer, updater, backups, cron worker.
**Version audited:** 1.0.0. **Fixed in:** 1.0.1. **Date:** 18 Nov 2026.
**Method:** manual code review of every controller, service, provider, view and config file; proof-of-concept exploits; regression tests; automated attack simulation against a disposable local install. No real user data or third-party systems were touched.

> This AI-assisted audit is not a replacement for an independent penetration test, which is recommended before onboarding paying agency clients.

---

## 1. Executive summary

The 1.0.0 codebase was already in good shape: parameterised SQL everywhere, CSRF on all writes, output escaping, workspace scoping on every query, encrypted tokens and no hardcoded secrets. The audit found **1 High, 9 Medium and 10 Low** issues. The most serious was a **stored XSS**: a malicious Mastodon server could save a `javascript:` link that ran in NextaPost when someone clicked "View post". It was confirmed with a working proof of concept.

All 20 findings are fixed and verified. A few residual risks need configuration or a decision (section 7).

**Verdict: SAFE WITH CONDITIONS** (section 9).

---

## 2. Vulnerability report and fixes

| # | Severity | Issue | Where | Impact | Fix (verified) |
|---|---|---|---|---|---|
| 1 | **High** | Stored XSS via `javascript:` URLs returned by remote servers (post link, avatar). **PoC confirmed:** stored `external_url = javascript:alert(document.cookie)` | `PublishService::publishTarget`, `ChannelController::saveAccount`, `views/posts/composer.php`, `helpers::channel_avatar` | Script runs as the clicking user, e.g. a super admin, giving full account takeover | New `safe_url()` allows only absolute http(s). Applied when storing and when rendering. Regression test re-runs the PoC and passes |
| 2 | Medium | No Content-Security-Policy; inline event handlers present | `Response`, layouts, installer, settings view | No second line of defence against XSS | CSP with per-request nonce: `script-src 'self' 'nonce-…' 'unsafe-eval'`, `object-src 'none'`, `frame-ancestors 'none'`, `form-action 'self'`, `base-uri 'self'`. Inline handlers and the `javascript:` link removed; installer has its own strict CSP. Browser test shows 0 console/CSP errors |
| 3 | Medium | Installer hijack window: before setup, anyone could point the site at a database they control | `public/install/index.php` | Site takeover during the minutes between upload and install | Only `localhost`/`127.0.0.1`/socket allowed unless `storage/allow-remote-db` exists (needs file-system access) |
| 4 | Medium | SSRF gaps: no DNS-rebinding protection, CGNAT/IPv6-local ranges not blocked, Mastodon/Bluesky publish requests not re-checked, http allowed | `Http::assertPublicUrl`, Mastodon/Bluesky providers | Requests to internal services or cloud metadata from your server | Resolve once, block private/loopback/link-local/CGNAT/metadata/ULA/IPv4-mapped addresses, pin curl to the vetted IP (`CURLOPT_RESOLVE`), no redirects, https required, credentials in URLs rejected. Applied to every request to a user-supplied server |
| 5 | Medium | Secrets could land in logs: exception traces record function arguments (e.g. passwords, tokens) | `App::boot`, `Logger` | Credential exposure through log files | `zend.exception_ignore_args=1` at boot (verified by test) |
| 6 | Medium | Update metadata not validated: a compromised update server could inject script into the admin page; auto-install accepted unsigned packages | `Updater::check`, `views/system/index.php` | XSS / code execution from a compromised update server | Version, sha256, URL and min-PHP strictly validated; values JSON-encoded in JS context; auto-install only with a signing key; UI warns when unsigned |
| 7 | Medium | No abuse limits on writes, uploads or channel connects; no storage quota; image decompression bombs possible | Router, `MediaController`, `MediaService` | Disk fill, memory exhaustion, outbound request abuse | Global write limit (300 per 5 min per user/IP, returns 429), uploads 120/hour, channel connects 20 per 10 min, per-workspace quota (10 GB, configurable), images over 12000 px per side or 50 MP rejected before decoding |
| 8 | Medium | Sessions never expired while the browser stayed open | `Auth::user` | Stolen or shared-device sessions stay valid indefinitely | 12 h idle and 7-day absolute timeouts (configurable), strict session INI settings |
| 9 | Medium | HTTPS not enforced | `App::run` | Session cookie and credentials could travel over plain HTTP | 301 to HTTPS when `APP_URL` is https (port preserved); HSTS with includeSubDomains |
| 10 | Medium | No way to erase a person's data; deleted workspaces kept forever | System, housekeeping | DPDP Act / GDPR erasure obligations | System → Users → Delete (anonymises, removes memberships and reset links); deleted workspaces purged with their files after 30 days; activity-log IPs dropped after 90 days |
| 11 | Low | Older reset links stayed valid after a reset or password change; reset limited per IP only | `AuthController`, `AccountController` | A leaked old reset link could be reused | All outstanding links invalidated; 3 resets per email per hour |
| 12 | Low | Sign-in timing revealed disabled accounts | `Auth::attempt` | Account enumeration | Hash always verified before status checks |
| 13 | Low | bcrypt instead of Argon2id | `Auth` | Weaker offline cracking resistance | Argon2id when available; existing hashes upgrade on next sign-in |
| 14 | Low | Invite set-password link shown to the inviting admin even when the email was sent | `WorkspaceController::addMember` | The admin could claim the invitee's account first | Link shown only if email delivery failed |
| 15 | Low | `X-Powered-By` header, version on `/health`, HTML pages cacheable | `Response`, `HomeController` | Fingerprinting; private pages in shared caches | Header removed, version removed, `Cache-Control: no-store, private` on HTML |
| 16 | Low | Generic 500 page had no way to correlate with logs | `App::run` | Slow incident response | Request IDs in header, error page and log entry |
| 17 | Low | Bad or missing `APP_KEY`/`APP_URL` only failed later, mid-feature | `App::run` | Confusing failures; possible weak config | Fail fast with a generic error and a log entry |
| 18 | Low | Backup files had predictable names | `Backup::create` | Easier guessing if storage is ever exposed (e.g. Nginx misconfiguration) | Random 48-bit suffix; storage stays deny-all |
| 19 | Low | Internal folders relied only on the root rewrite rule | `bin/ src/ views/ database/ tools/ resources/ tests/` | Direct file access if `mod_rewrite` is off | `Require all denied` in each folder |
| 20 | Low | LinkedIn upload URL host not checked; JS-string escaping via `addslashes` | `LinkedInProvider`, two views | Defence in depth | Host allow-list (`linkedin.com`, `licdn.com`); `json_attr()` used for all JS strings |

**Checked with no issue found:** SQL injection (all queries parameterised, dynamic SQL built only from constants); IDOR/BOLA (every `/w/{ws}` route verifies membership, and every post, media, channel and member lookup is scoped by workspace); RBAC enforced server-side through `Gate` on every action; CSRF (419 on missing or forged tokens); open redirects (internal paths and same-host Referer only); upload MIME spoofing (finfo allow-list, random names, no SVG, no-exec media folder); zip-slip in the updater and backups; mass assignment (explicit field lists only); CORS (no CORS headers, same-origin only); `localStorage` (only the calendar view choice); clickjacking.

**Payments:** NextaPost 1.0 has no payment gateway, coupons or credits, so section 5 of the brief doesn't apply. Run this audit again when Razorpay billing is added (webhook signatures, idempotency, server-side prices).

---

## 3. Security test results (actual runs)

| Suite | Result |
|---|---|
| `tests/suite.php`: integration + security regressions (SQLite) | **150 / 150 passed** |
| `tests/update_suite.php`: updater (signatures, tampering, zip-slip, rollback, malicious metadata, unsigned auto-install) | **24 / 24 passed** |
| `tests/security_http.py`: live HTTP attack simulation (headers, CSRF, brute force, session fixation, IDOR, privilege escalation, XSS, SQLi, upload exploits, path traversal, SSRF, CSS injection, open redirect) | **44 / 44 passed** |
| `tests/e2e.py`: Playwright + Chrome end-to-end, desktop and mobile, under the strict CSP | **25 / 25 passed, 0 console errors** |
| Manual checks | HTTP→HTTPS 301 with port kept; invalid `APP_KEY` returns a generic 500 with a reference and a log entry |
| `php -l` on every PHP file | Clean |

---

## 4. Secrets audit

| Item | Location | Status |
|---|---|---|
| Hardcoded API keys, passwords, tokens, private keys | Searched all of `src/`, `views/`, `public/`, `bin/`, `tools/` | **None found** |
| DB credentials, `APP_KEY` | `.env` only, written by the installer with mode 0600 | OK |
| OAuth client secrets, SMTP password | `settings` table, libsodium-encrypted with `APP_KEY` | OK |
| Channel tokens (Meta, LinkedIn, X, Telegram, Discord, Mastodon, Bluesky) | `channels.credentials_enc`, encrypted, never sent to the browser | OK |
| Update signing key | Generated by `tools/keygen.php` into `storage/keys/` (git-ignored); only the public key is configured | OK. Key not generated yet (action for you) |
| `.gitignore` | Created: `.env`, `storage/*`, `public/media/*`, `dist/`, keys, `*.sqlite`, logs | OK |
| `.env.example` | Placeholders only | OK |
| Git history | The project isn't in Git yet, so there's no history to scan | N/A. Nothing to rotate |
| Test fixtures | Dummy passwords such as `password123`, test-only, excluded from the release zip | OK |

No secret needs rotation.

---

## 5. Personal data flow map

| Data | Collected from | Stored | Shared with | Retention / deletion |
|---|---|---|---|---|
| Name, email, timezone, theme | User, admin invites | `users` | SMTP provider (emails) | Until erased via System → Users → Delete (anonymised) |
| Password | User | Argon2id hash only | Nobody | Replaced on change or erasure |
| Reset tokens | System | SHA-256 hash, 1 h (invites 7 days) | User's inbox | Invalidated on use or password change; purged daily |
| Session | System | Server files in `storage/sessions` | Nobody | 12 h idle / 7 days max |
| IP address, actions | Requests | `activity_log` | Nobody | IP removed after 90 days; entries after 1 year |
| Social account names, avatars, tokens | Network APIs | `channels`, tokens encrypted | The network itself | Deleted with the channel or workspace |
| Post text and media | Users | `posts`, `public/media` (unguessable URLs) | The networks the post targets | Optional clean-up rules; deleted workspaces purged after 30 days |
| Version, PHP version, hashed site URL, licence key | System | – | Update server (daily check) | – |
| Backups (everything, including `.env`) | Admin | `storage/backups`, 0600, deny-all, last 5–10 kept | Nobody | Admin deletes or rotates |

---

## 6. Changes in 1.0.1 (files)

`src/helpers.php`, `src/Core/{App,Auth,Http,Response,Router,Session}.php`, `src/Services/{MediaService,Updater,Backup,Worker,RetentionService (new)}.php`, `src/Controllers/{Auth,Account,Channel,Media,Post,Workspace,System,Home}Controller.php`, `src/Providers/{Mastodon,Bluesky,LinkedIn}Provider.php`, `views/{layouts/*,posts/composer,channels/index,settings/workspace,system/index,errors/error}.php`, `public/install/index.php`, `database/migrations/2026_11_18_000001_retention_privacy.sql`, `.gitignore`, deny-all `.htaccess` files, tests.

---

## 7. Unresolved issues and residual risks

| Item | Risk | Why it's open / what to do |
|---|---|---|
| Not tested on a live MySQL server | Medium | No MySQL server in the audit sandbox; SQL is written to be portable and all tests ran on SQLite. **Do a test install on your cPanel first.** |
| Live Facebook/Instagram/LinkedIn/X calls not exercised | Medium | Verified only against mocked API responses (needs your app keys). Test each network with one real post. |
| CSP keeps `'unsafe-eval'` | Low | Required by Alpine's standard build. Output escaping stays the main defence. Switching to Alpine's CSP build would mean rewriting every template expression; consider for v2. |
| No two-factor authentication | Medium | Not in v1. Recommended for super-admin accounts in v1.1. |
| Updates verified by checksum only until you set a signing key | Medium | Run `php tools/keygen.php` and paste the public key into System → Updates on every install. |
| A super admin can upload an update zip | By design | Equivalent to code execution. Give super admin only to people you'd trust with cPanel access. |
| Uploaded media is public via unguessable URLs | Low | Required so Meta can fetch images. Don't upload confidential files. |
| JPEG EXIF metadata (e.g. GPS) not stripped | Low | Most networks strip it on publish; stripping here would re-encode images. |
| No email verification for self sign-up | Low | Sign-up is off by default (invite-only). Keep it off unless you need it. |
| Nginx ignores `.htaccess` | Medium (config) | On Nginx the document root **must** be `public/`. This is the default on cPanel (Apache/LiteSpeed). |
| A failed update's database changes aren't undone automatically | Low | Files roll back; the pre-update backup contains a full SQL dump for manual restore. |
| `log` mail driver writes reset links to `storage/logs/mail.log` | Low | Testing only. Use SMTP in production. |
| Dependency CVE scan | Low | No Composer dependencies. Alpine 3.14.9 was built from the official source; Tailwind runs at build time only. `npm audit` couldn't run (sandbox has no network access). |

---

## 8. Deployment checklist

| Requirement | Status |
|---|---|
| Debug off (`APP_DEBUG="false"`, installer default) | ✅ Pass |
| `APP_KEY` generated and validated at start-up | ✅ Pass |
| HTTPS / AutoSSL and `APP_URL` starting `https://` | ⚠️ Your action |
| Document root = `nextapost/public` (mandatory on Nginx) | ⚠️ Your action |
| Installer locked after install (`storage/installed.lock`) | ✅ Pass. Optionally delete `public/install` |
| Local database only; DB user has rights on its own database only | ✅ Pass (enforced) / ⚠️ verify in cPanel |
| Cron job every minute | ⚠️ Your action |
| Update signing key configured | ⚠️ Your action |
| SMTP configured (resets, invites) | ⚠️ Your action |
| Security headers (CSP, HSTS, XFO, nosniff, Referrer-Policy) | ✅ Pass |
| `.env`, storage, internal folders unreachable over HTTP | ✅ Pass |
| No default credentials, test routes or seed endpoints | ✅ Pass (tests excluded from the release zip) |
| Rate limits on login, reset, sign-up, writes, uploads, connects | ✅ Pass |
| Backups scheduled (System → Backups + cPanel backup of `public/media`) | ⚠️ Your action |
| Meta App Review / LinkedIn / X app setup | ⚠️ Your action |
| Live MySQL test install | ⚠️ Pending |
| Independent penetration test before paying clients | ⚠️ Recommended |

---

## 9. Final verdict: **SAFE WITH CONDITIONS**

Every High and Medium code-level finding is fixed and covered by tests that pass: 150 integration, 24 updater, 44 attack simulations and 25 browser checks. NextaPost 1.0.1 is fit for production on cPanel **once** you have enabled HTTPS, set the document root to `public/`, added the cron job, configured an update signing key, and confirmed a test install on your own MySQL server. Add 2FA for super admins and an independent penetration test before onboarding paying clients.
