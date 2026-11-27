# NextaPost Security Audit Report — 2.0

**Scope:** the full NextaPost 2.0 codebase (core PHP 8.1+, MySQL/SQLite, Tailwind, Alpine.js), with focus on everything new in 2.0: REST API and API keys, MCP server and OAuth 2.1 sign-in for AI apps, webhooks, AI writing assistant and AI interface translation, ideas board, RSS feeds, repurposing rules, media sources (Unsplash, Giphy, Pexels, Canva, Google Drive/Photos), 17 interface languages, 17 networks.
**Version audited:** 2.0.0 release candidate. **Fixed in:** 2.0.0. **Date:** 21 Nov 2026.
**Method:** manual review of every new or changed controller, service, provider and view; proof-of-concept tests against a disposable local install; automated attack simulation, API and form fuzzing, a permission matrix of every write route per role, and a browser crawl. No real user data or third-party systems were touched, and no secret values appear in this report.

> This audit doesn't replace an independent penetration test, which is still recommended before onboarding paying agency clients.

The 1.0.1 audit (20 findings, all fixed) is summarised in the changelog; every one of its regression tests still passes on 2.0.

---

## 1. Executive summary

2.0 adds several new ways into NextaPost (API keys, AI apps over MCP, webhooks, outbound fetches to feeds, media libraries and AI services), so this audit concentrated on **who keeps access, and for how long**, and on **outbound requests**.

The audit found **3 High, 5 Medium and 4 Low** issues. The most serious was that **access outlived the person**: an API key or a connected AI app kept working after its owner's account was deactivated, and a contributor could change or delete other people's posts through the API. All 12 findings are fixed and covered by tests that pass.

Outbound requests were already well protected: every v2 feature that fetches a URL goes through the SSRF guard, and seven live SSRF attempts were refused.

**Verdict: SAFE WITH CONDITIONS** (section 9). The conditions are configuration and live checks that can't be done in the audit environment, chiefly a test install on real MySQL.

---

## 2. Vulnerability report

| # | Severity | Issue | Affected file(s) | Impact |
|---|---|---|---|---|
| 1 | **High** | API keys kept working after their owner was deactivated | `src/Services/ApiAuth.php` (`member()`) | A former team member could still read, create, schedule and publish posts through the REST API or MCP |
| 2 | **High** | AI apps connected over MCP (OAuth tokens) kept working after their owner was deactivated | `src/Services/OAuthService.php` (`memberOk`) | Same as #1, through Claude, ChatGPT or any other connected AI app |
| 3 | **High** | Contributors could edit, move, delete, retry, schedule and publish **other people's** posts (UI and API) | `src/Controllers/PostController.php`, `src/Services/AgentApi.php` | Broken object-level authorisation: the lowest writing role could change or remove an admin's posts |
| 4 | Medium | Removing a member left their API keys and connected apps for that workspace active; re-adding the person later revived old access | `src/Controllers/WorkspaceController.php` (`removeMember`) | Forgotten credentials come back to life when someone rejoins |
| 5 | Medium | Erasing an account (System → Users → Delete) left 2.0 data: API keys, connected-app tokens and codes, Canva/Google sign-ins, language and notification settings | `src/Controllers/SystemController.php` (`deleteUser`) | Incomplete erasure under the DPDP Act / GDPR; stored third-party credentials remained |
| 6 | Medium | Repurposing rules kept publishing on behalf of a deactivated owner | `src/Services/RepurposeService.php` | Automated posts in the name of someone who has left |
| 7 | Medium | An OAuth app's redirect address host wasn't validated before being placed in the sign-in page's CSP `form-action` | `src/Services/OAuthService.php`, `src/Controllers/OAuthController.php` | A crafted app registration could inject extra directives into the Content-Security-Policy header |
| 8 | Medium | A contributor's composer showed Duplicate and editable fields for posts they couldn't change, and the API's `editable` flag ignored ownership | `views/posts/composer.php`, `PostController` | Misleading UI that invited actions the server then refused (no data exposure once #3 was fixed) |
| 9 | Low | After sign-in, the `to` path was used without normalising it | `src/Controllers/AuthController.php` | Defence in depth against open redirects (`//host`, backslashes, control characters) |
| 10 | Low | Search text wasn't capped and `%`/`_` weren't escaped in `LIKE` queries | `AgentApi`, `IdeaService`, `PostController` | 100,000-character searches and wildcard abuse caused slow queries |
| 11 | Low | Sending a list where text was expected (`name[]=x`) crashed some forms and API calls with a 500 | 60 places across controllers and services | Error noise and a possible way to probe internals; passwords were also trimmed |
| 12 | Low | New 2.0 settings (`API_RATE_LIMIT`, `AI_RATE_LIMIT`, `AI_TRANSLATE_RATE_LIMIT`, `MCP_ALLOWED_ORIGINS`, `AI_ALLOW_PRIVATE_BASE_URL`, …) weren't documented | `.env.example` | Admins couldn't tune or tighten them |

**Checked with no issue found:**
- **SQL injection:** all queries parameterised, including the new API filters and search.
- **IDOR/BOLA:** every API operation, webhook, label, template, idea, feed and repurposing rule is scoped to the workspace. Tested with another workspace's IDs.
- **CSRF:** 419 on every web write without a token. The REST API and MCP use bearer tokens only, with no cookies.
- **SSRF:** RSS feeds, media import from URL, webhooks, the AI base URL, OAuth client metadata documents and WordPress sites all go through the SSRF guard, with DNS pinning and no redirects.
- **XXE:** RSS and Atom parsing doesn't load external entities.
- **Stored XSS:** feed items, idea text, labels (strict hex colours), AI output and translations are escaped. `x-html` is used only for avatars, which escape their input.
- **Token storage:** API keys and OAuth tokens are stored as SHA-256 hashes. Webhook secrets, AI keys and media-source credentials are encrypted with libsodium.
- **Webhooks:** signed with HMAC-SHA256 and a timestamp.
- **OAuth 2.1:** PKCE (S256) required, codes are single-use, redirect URIs must match exactly, and tokens are bound to the requested resource.
- **MCP:** `Origin` checked against `MCP_ALLOWED_ORIGINS` to prevent DNS rebinding.
- **AI translation:** placeholders and markup are validated before saving, so a model can't inject HTML into the interface.
- **Clickjacking:** `frame-ancestors 'none'`.

**Payments:** 2.0 still has no payment gateway, coupons or credits, so the payment section of the brief doesn't apply.

---

## 3. Fixes implemented

| # | Fix | Verified by |
|---|---|---|
| 1, 2 | Every API key and OAuth token lookup now requires `users.is_active = 1` as well as membership | `qa_suite`: a deactivated person's API key and connected AI app both get 401 |
| 3 | New `Gate::canChangePost()`: contributors may change only their own posts. Used by `PostController::ownPost()` (update, move, delete, retry) and `AgentApi::ownPost()` (update, delete, schedule, unschedule, submit, publish_now, retry) | `qa_suite` contributor-ownership checks; permission matrix of 88 write routes × 4 roles |
| 4 | Removing a member revokes their API keys and OAuth tokens for that workspace | `tests/qa_lifecycle.py` (new) |
| 5 | Account erasure also revokes all API keys, deletes OAuth tokens and codes and Canva/Google connections, and clears language and notification settings. The "only admin" message is translated | `tests/qa_lifecycle.py`: 14/14 checks |
| 6 | A repurposing rule runs only while its owner is active; otherwise it pauses | `qa_suite`: rule of a deactivated owner pauses |
| 7 | New `OAuthService::validHost()` accepts DNS names, IPv4 or bracketed IPv6 only. It's used to validate redirect URIs, and the CSP origin is added only when it passes | `qa_suite` OAuth host checks |
| 8 | The composer's `editable` flag includes ownership; Duplicate is hidden for roles that can't create posts | Browser crawl as each role |
| 9 | Sign-in redirect goes through `safe_local_path()` | `qa_suite` |
| 10 | New `like_pattern()`: trims, caps at 100 characters, escapes `%`, `_`, `!` (`ESCAPE '!'`) | `qa_suite`: a 100,000-character search answers normally |
| 11 | New `str_val()` replaces 60 unsafe casts; passwords read untrimmed via `Request::raw()` | API fuzz (1,550 calls) and form fuzz (540 submissions): 0 crashes |
| 12 | `.env.example` documents every new setting, with safe defaults | Review |

Workspace deletion was also checked: every 2.0 table cascades from `workspaces` (foreign keys enforced on SQLite and MySQL), so purged workspaces leave nothing behind.

---

## 4. Security test results (actual runs, 21 Nov 2026)

| Suite | Result |
|---|---|
| `tests/suite.php`: integration, API, MCP, OAuth, webhooks, AI, i18n and QA/security regressions (SQLite) | **679 / 679 passed** |
| `tests/update_suite.php`: updater (signatures, tampering, zip-slip, rollback) | **24 / 24 passed** |
| `tests/security_http.py`: live HTTP attack simulation (headers, CSRF, brute force, session fixation, IDOR, privilege escalation, XSS, SQLi, upload exploits, path traversal, SSRF, open redirect) | **53 / 53 passed** |
| `tests/qa_lifecycle.py`: member removal and account erasure (new) | **14 / 14 passed** |
| `tests/qa_writes.py`: every POST route as viewer, approver, contributor and a non-member | **352 requests, all decisions correct, 0 crashes** |
| `tests/qa_fuzz.py`: every API operation with hostile input (wrong types, huge strings, unicode, injection payloads) | **1,550 calls, 0 server errors** |
| `tests/qa_forms.py`: every web form with hostile input | **540 submissions, 0 server errors** |
| Live SSRF (loopback, private, link-local/metadata, CGNAT, IPv6-local, feed, media import, OAuth metadata, WordPress, AI, webhook) | **All refused** |
| Browser end-to-end suites (core, v2, developers, OAuth, AI, networks, repurpose, ideas, media sources, languages, MCP) | **All passed** (see QA report) |
| Dependency check | No Composer dependencies; Alpine 3.14.9 has no known vulnerabilities |
| `php -l` on every PHP file | Clean |

---

## 5. Secrets audit

No secret values are printed here.

| Item | Location | Status |
|---|---|---|
| Hardcoded API keys, passwords, tokens or private keys | Searched `src/`, `views/`, `public/`, `bin/`, `tools/`, `lang/`, `docs/` | **None found** |
| DB credentials, `APP_KEY` | `.env` only (installer writes it with mode 0600) | OK |
| REST API keys | `api_keys.token_hash` (SHA-256); the full key is shown once | OK |
| OAuth access and refresh tokens, codes | Stored as SHA-256 hashes | OK |
| Webhook signing secrets | `webhooks.secret_enc` (encrypted), shown once | OK |
| AI provider keys | `workspaces.ai_key_enc` (encrypted), never sent to the browser | OK |
| Canva/Google media-source sign-ins | `media_connections.credentials_enc` (encrypted) | OK |
| Network tokens, OAuth app secrets, SMTP password | Encrypted, as in 1.0.1 | OK |
| `.env.example` | Placeholders only | OK |
| Test fixtures | Dummy values (e.g. `password123`), excluded from the release zip | OK |
| Release zip | Built by `tools/build-release.php`, which excludes `.env`, `storage/*`, tests, keys and `*.sqlite` | OK |

No secret needs rotation.

---

## 6. Personal data flow map

| Data | Collected from | Stored | Shared with | Retention / deletion |
|---|---|---|---|---|
| Name, email, timezone, theme, language, notification choices | User, admin invites | `users` | SMTP provider | Erased via System → Users → Delete |
| Password | User | Argon2id hash | Nobody | Replaced on change or erasure |
| Sessions, reset links | System | Server files; reset tokens as hashes | User's inbox | 12 h idle / 7 days; links single-use |
| IP address, actions | Requests | `activity_log` | Nobody | IP dropped after 90 days or on erasure |
| API keys | User (Developers) | Hash only | The app the user gives it to | Revoked on member removal, deactivation or erasure |
| Connected AI apps (MCP) | User consent screen | Token hashes, client name | The AI app the user connects | Revoked or deleted on removal or erasure; the user can disconnect any time |
| Post text and media | Users, AI apps via API/MCP | `posts`, `public/media` | Target networks; **the workspace's AI provider** when someone uses the writing assistant | Clean-up rules; deleted workspaces purged after 30 days |
| Interface text | App | `storage/lang` | The admin's AI provider, only when they click "Translate missing" (interface text only, no user data) | Until reset |
| Webhook events (post data) | System | Delivery log | The URL the admin configures (signed) | Delivery log pruned |
| RSS feed items | Public feeds | `rss_items` | Nobody | With the feed or workspace |
| Media-library searches and imports | User | Imported files in `public/media` | Unsplash, Giphy, Pexels, Canva or Google (search terms, the user's own sign-in) | Connection deleted on disconnect or erasure |
| Social account names, avatars, tokens | Network APIs | `channels` (tokens encrypted) | The network | With the channel or workspace |
| Version, PHP version, hashed site URL | System | – | Update server (daily check) | – |

---

## 7. Unresolved issues and residual risks

| Item | Risk | Why it's open / what to do |
|---|---|---|
| Not run on a live MySQL server | Medium | **BLOCKED** in the audit environment (no MySQL server). The schema passed a static MySQL compatibility check and all tests ran on SQLite. **Do a test install on your cPanel MySQL before release.** |
| Live network, AI, Canva and Google calls not exercised | Medium | Verified against recorded and fake API responses only (needs your app keys). Publish one real post per network and try each AI provider once. |
| No two-factor authentication | Medium | Still not built. Recommended for super admins in 2.1. |
| CSP keeps `'unsafe-eval'` | Low | Needed by Alpine's standard build; output escaping stays the main defence. |
| AI output is shown for review, not trusted | Low | AI text is escaped and never published without a person or an approved API call. AI apps act with the role of the person who connected them, so give AI apps to roles you trust. |
| A super admin can upload an update zip | By design | Equivalent to code execution; keep super admin to people you'd trust with cPanel. |
| Uploaded media is public via unguessable URLs | Low | Required for Meta and others to fetch images. |
| Nginx ignores `.htaccess` | Medium (config) | On Nginx the document root **must** be `public/` (the cPanel default). |
| No independent penetration test | Medium | Recommended before paying clients. |

---

## 8. Deployment checklist

| Requirement | Status |
|---|---|
| Debug off (`APP_DEBUG="false"`) | ✅ Pass |
| `APP_KEY` generated and validated at start-up | ✅ Pass |
| Security headers (CSP with nonces, HSTS on https, XFO/`frame-ancestors`, nosniff, Referrer-Policy) | ✅ Pass |
| CSRF on every web write; bearer-only API and MCP | ✅ Pass |
| Rate limits: login, reset, sign-up, writes, uploads, API, AI, AI translation | ✅ Pass |
| Access removed when a person is deactivated, removed or erased | ✅ Pass (fixed in this audit) |
| SSRF guard on every outbound fetch | ✅ Pass |
| `.env`, `storage`, `lang`, `docs`, internal folders unreachable over HTTP | ✅ Pass |
| No default credentials, test routes or seed endpoints in the release zip | ✅ Pass |
| Installer locked after install | ✅ Pass |
| HTTPS / AutoSSL and `APP_URL` starting `https://` | ⚠️ Your action |
| Document root = `nextapost/public` (mandatory on Nginx) | ⚠️ Your action |
| Cron job every minute | ⚠️ Your action |
| SMTP configured | ⚠️ Your action |
| Update signing key configured | ⚠️ Your action |
| `MCP_ALLOWED_ORIGINS` set if you put NextaPost behind a different public hostname | ⚠️ Your action (if needed) |
| Live MySQL test install | ❌ Not done (blocked here) |
| One real post per network; one call per AI provider | ❌ Not done (needs your keys) |
| Independent penetration test | ⚠️ Recommended |

---

## 9. Final verdict: **SAFE WITH CONDITIONS**

All 12 findings are fixed. The tests pass: 679 integration, 24 updater, 53 attack simulations and 14 lifecycle checks, plus fuzzing and the permission matrix with no failures. No secrets are exposed and no High or Medium code issue is open.

NextaPost 2.0.0 is fit for production on cPanel **once**:
1. a test install on your own MySQL server works;
2. HTTPS, the `public/` document root, the cron job, SMTP and an update signing key are set up;
3. one real post per network you offer has succeeded.

Add 2FA for super admins and an independent penetration test before onboarding paying clients.

---

## Addendum — 2.0.1 (26 Nov 2026)

Found during the 2.0.1 QA pass and fixed in 2.0.1:

| # | Severity | Issue | Affected file | Fix | Verified by |
|---|---|---|---|---|---|
| 13 | Medium | Switching the AI assistant to an OpenAI-compatible server, or changing that server's address, kept the previously saved API key and sent it to the new server | `src/Services/AiService.php` | The saved key is kept only for the same provider and server address; otherwise it's dropped (keyless local servers still work) | `qa_suite`, `tests/qa_fixes_201.py` |
| 14 | Low | Pages didn't carry LiteSpeed's own no-cache header; a server-level cache rule could store a signed-in page with a stale form token | `src/Core/Response.php` | `X-LiteSpeed-Cache-Control: no-cache` on every page | `tests/qa_fixes_201.py` |

The test database now refuses any value longer than its MySQL column (strict-mode emulation), so the integration suite catches MySQL-only failures. The verdict is unchanged: **SAFE WITH CONDITIONS**.

---

## Addendum — 2.1.0 Comment-to-DM flows (27 Nov 2026)

The new optional module was reviewed and tested before release. It is **off by default**; while off, its pages, the webhook and the tracked links answer 404, and no Meta messaging permission is requested.

| Area | Control | Verified by |
|---|---|---|
| Webhook authenticity | Every POST must carry `X-Hub-Signature-256`, an HMAC-SHA256 of the exact body with your Meta app secret (Instagram or Facebook app); compared in constant time. Unsigned, forged or re-signed-with-another-key requests get 403 | `dm_suite`, `e2e_dm` |
| Webhook verification | The GET handshake needs the random verify token (constant-time compare) and only echoes a plain challenge (`[A-Za-z0-9_-]`), so it can't reflect markup | `e2e_dm` |
| Abuse of the endpoint | Bodies over 1 MB are refused (413); malformed JSON 400; unknown accounts, unknown objects and malformed entries are ignored; Meta's repeat deliveries are de-duplicated per comment | `dm_suite`, `e2e_dm` |
| Button taps (postbacks) | The payload names an event by random UUID; it's acted on only if it belongs to one of the account's channels **and** the tapper is the person who commented. Anyone else's tap is ignored | `dm_suite` |
| Loops | The account's own comments (including its public replies) never trigger a flow | `dm_suite` |
| Permissions | Admins and editors manage flows; only admins see the Meta setup and can subscribe accounts; contributors, approvers and viewers get 403; other workspaces 404; every write needs the CSRF token | `e2e_dm`, `qa_writes`, crawl per role |
| Links | Only `http(s)` links can be saved; the tracked `/go/<uuid>` link only forwards to the flow's saved address (no open redirect) | `dm_suite`, `e2e_dm` |
| Tokens | Channel tokens stay encrypted; Meta calls send them in an `Authorization` header, never in the URL | `dm_suite` |
| Meta's limits | One private reply per comment, within 7 days; at most `DM_PRIVATE_REPLIES_PER_HOUR` (default 700 of Meta's 750) per account per hour, the rest queued; each event is claimed atomically so the webhook and cron never send twice | `dm_suite` |
| Personal data | Commenters' names and comments are stored only for matching comments, shown only to the workspace, and deleted after 90 days; deleting a channel or flow deletes its activity | `dm_suite` |

No issues found. The overall verdict is unchanged: **SAFE WITH CONDITIONS**. The extra condition for this module: test one real flow on your own account after adding the webhook in your Meta app (a live Meta delivery can't be exercised here).
