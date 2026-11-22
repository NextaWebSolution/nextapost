# Changelog

## 2.0.0 — 2026-11-21

The biggest NextaPost release so far: AI agents and an API, seven new networks, an ideas board, repurposing, team notes and 18 interface languages.

New — AI and automation:
- **MCP server** at `/mcp`: connect Claude, ChatGPT or any MCP app as a custom connector, sign in, pick a workspace, and let it draft, schedule and manage posts. 57 tools; approvals and roles still apply
- **REST API** (`/api/v1`) with the same 57 operations: posts (single and batch), channels, posting schedules, media upload or import from a link, labels, signatures, templates, notes, ideas, feeds and webhooks. Full reference in `docs/API.md`
- **API keys** per workspace (optionally with a lower role and an expiry) and **OAuth 2.1 sign-in** for apps (PKCE, dynamic client registration, rotating refresh tokens); Developers & AI page to manage keys and connected apps
- **Webhooks** for post events (created, scheduled, approved, published, failed and more), signed with HMAC-SHA256, retried, paused after repeated failures, with a delivery log and replay
- **AI writing assistant** with your own key (OpenAI, Anthropic, Google Gemini or any OpenAI-compatible service): write from an idea, rephrase, shorten, lengthen, change tone, fix spelling, translate, fit a channel's limit, add hashtags, and write alt text for images. Brand voice per workspace

New — planning and publishing:
- **Posting times and queue**: weekly posting times per channel, "Add to queue" takes each channel's next free time, suggested times
- **Recurring posts** (daily, weekly, monthly, yearly, with an end)
- **Ideas board** with columns, drag and drop, labels and "turn into post"; **RSS / Atom / JSON feeds** inbox that can save new articles as ideas automatically
- **Repurpose**: new Instagram or Threads posts are copied to the channels you choose (for example Reels to TikTok, Shorts and Facebook)
- **Labels** to colour-code posts and filter the calendar, **signatures** (reusable hashtags or call to action) and **templates**
- **Team notes** on posts with @mentions and email notifications
- **Post analytics**: likes, comments, shares and views fetched for 30 days after publishing (Instagram, Facebook, X, YouTube, Threads, Mastodon, Bluesky)
- **Media sources**: add photos and designs from Unsplash, Canva, Google Photos and Google Drive, or import from a link

New — networks:
- **WordPress** (posts with featured image, category, tags and excerpt; published, draft or pending review), **Threads** (posts, polls, ghost posts), **Instagram** without a Facebook Page (Instagram Login), **Pinterest** (image, video and carousel pins), **YouTube** (videos and Shorts), **Google Business Profile** (updates, events, offers), **TikTok** (video and photo posts with the creator privacy rules)
- X and Mastodon **polls**; Facebook **Reels and Stories**; Instagram **Reels, Stories** and first comment
- Per-channel post types and options in the composer, checked before publishing

New — languages:
- **18 interface languages**: English, Hindi, Bengali, Arabic (right to left), German, Greek, Spanish, French, Italian, Japanese, Korean, Dutch, Polish, Portuguese, Russian, Turkish, Ukrainian and Chinese. Each person picks theirs under Your account
- Hindi and Bengali are fully translated; the other languages cover the everyday screens and show English for rarer text
- **System → Languages**: see coverage and fill missing text with a workspace's AI key; those translations are kept across updates
- Dates, day and month names follow the chosen language

Security (from the 2.0 audit, see SECURITY-AUDIT.md):
- API keys, connected AI apps and repurposing rules stop working as soon as their owner is deactivated
- Contributors can change only their own posts, in the app and through the API
- Removing a member revokes their API keys and connected apps for that workspace; deleting an account also removes its API access, Canva/Google sign-ins and personal settings
- OAuth redirect addresses must have a valid host; sign-in redirects stay on this site
- Searches are capped and wildcard-safe; unexpected input types no longer cause server errors

Upgrade notes:
- Two database migrations run automatically (from the in-app updater, or on the first page load after copying the files yourself)
- New optional `.env` settings: `API_RATE_LIMIT`, `AI_RATE_LIMIT`, `AI_TRANSLATE_RATE_LIMIT`, `MCP_ALLOWED_ORIGINS`
- The new networks each need their own developer app in System → Integrations, and some need the network's approval before they can post for other people (Meta App Review, TikTok audit, Pinterest Standard access, Google Business Profile API access, YouTube quota)

## 1.1.2 — 2026-11-19

Fixed:
- On phones, the post editor was wider than the screen, so long text and the channel tabs were cut off on the right

Other:
- New README with screenshots (light and dark), network support table and FAQ

## 1.1.1 — 2026-11-18

Fixed:
- Team & settings showed "Server error" after updating by uploading the files by hand (cPanel File Manager, FTP, git), because the new database table from 1.1.0 was never created. The app now applies any pending database changes by itself on the first page load after new files arrive, however they were installed (with a lock so two visitors can't run it twice). The in-app updater already did this.

## 1.1.0 — 2026-11-18

New:
- Documents: upload PDF, PowerPoint (PPTX) and Word (DOCX) files and post them as a LinkedIn document (swipeable carousel) on profiles and company Pages, as a file on Telegram, or as an attachment on Discord
- Different media per channel in one post ("Use different media for…"): e.g. the PDF for LinkedIn and the slides as images for Instagram and Facebook
- The composer warns when a channel can't post documents and fixes it in one click; document preview card with page count
- Media library shows documents with page count and a download button; the "Document title" is the title LinkedIn shows
- Channel buttons in the composer now have accessible names

Security:
- Documents are checked by their content, not their name: PDFs with JavaScript, launch actions, embedded files, rich media, XFA forms or encryption are refused (also when hidden in compressed streams or with #hex escapes); PPTX/DOCX with macros, ActiveX, embedded programs or remote templates are refused; zip bombs and legacy .doc/.ppt are refused
- Documents are stored outside the public folder (owner-readable only) and served only to signed-in workspace members, as a download with a sandbox Content-Security-Policy and no caching
- LinkedIn upload URLs are only followed on linkedin.com / licdn.com

Upgrade note: the update adds one database migration (runs automatically).

## 1.0.1 — 2026-11-18 (security release)

Security fixes from a full code audit (details in SECURITY-AUDIT.md):
- Fixed stored XSS through post/avatar links returned by remote servers (only http/https links are stored and shown)
- Content-Security-Policy with per-request nonces; removed inline event handlers; no X-Powered-By; no-store on pages
- SSRF hardening: user-supplied servers are resolved once, checked against private/CGNAT/metadata/IPv6-local ranges and pinned
- Installer only accepts a local database unless explicitly allowed (prevents pre-install hijacking)
- Argon2id password hashing (automatic upgrade on next sign-in), constant-time sign-in, session idle (12 h) and absolute (7 days) timeouts
- All password-reset links invalidated after a reset/password or email change; per-email reset limit
- Global write rate limit, upload/connect limits, per-workspace storage quota, image decompression-bomb guard
- Update metadata validated; automatic installs require a signing key; unguessable backup names
- HTTPS enforced when APP_URL is https; startup validation of APP_KEY/APP_URL; error reference IDs
- Function arguments never recorded in exception logs; deny-all .htaccess in internal folders; .gitignore

New:
- LinkedIn company Pages (Community Management API): connect every Page you administer and post as the organization
- Connecting a channel now shows which account will be used, a sign-out link to switch accounts, and always asks you to confirm the account before saving
- Storage & automatic clean-up per workspace: delete media of published posts, old post history and unused uploads after a chosen period (off by default)
- System → Users → Delete: erases a person's account data (anonymised); IP addresses in the activity log kept 90 days
- Deleted workspaces are purged with their files after 30 days

## 1.0.0 — 2026-11-18

First production release.

- Content calendar: week, month and list views with drag-and-drop rescheduling and an unscheduled-drafts panel
- Composer with per-channel text, character counters, media library, live preview, post now / schedule / draft / review
- Networks: Facebook Pages, Instagram (feed, carousel, Reels), LinkedIn, X, Telegram, Discord, Mastodon, Bluesky, Sandbox
- Workspaces, roles, approval workflow with change requests, activity log
- Cron job queue with retries, token refresh and reconnect detection
- Light, dark and auto themes; mobile-responsive layout
- Web installer, signed one-click/automatic updates with backup and rollback, backups, SMTP email, password reset, team invites
