# Price comparison

People search for a product (text, or a photo that becomes a search). The app shows the three best
current prices from different external stores, with shipping when the store published it, and opens
the store’s own product page. This app is not the merchant and does not take payment.

The social layer (Reels, Explore, follows, profiles) is discovery that leads into that comparison.
In-app checkout, cart payments, squads, auctions, gifts, blind boxes, seller payouts, wallet, and
loot boxes stay in the tree behind `COMMERCE_PURCHASES`, which is **off** unless set to `true`.
Those routes answer 404 while the flag is off.

Laravel 12 (`laravel/framework` ^12) and PostgreSQL back the API. The Expo app is the client.
`stripe/stripe-php` remains for the flagged purchase code. Schema changes after the core SQL live in
`backend/database/migrations/`.

## Compare prices

`GET /api/v1/compare?q=nike` ranks the top three in-stock offers from different stores by landed
total: item price, plus shipping when the store published it, plus tax or VAT when the store
published it and it is not already included in the item price. Unknown shipping or tax is labeled
and is not treated as free. A currency with no rate is dropped rather than relabeled. Prices are
converted with rates from `fx:refresh` (`FX_FEED_URL`, Frankfurter by default). Until that feed
succeeds, `USD`, `EUR`, and `GBP` use the static fallback in `config/compare.php`.

`country` (or the shopper’s saved country, or a proxy country header / Accept-Language) is sent to
the provider as `gl` and `hl`. `GET /api/v1/shop` and `PUT /api/v1/shop` store the override. The
public profile does not include it. When a store lists the countries it ships to, the offer says
whether that includes the shopper’s country.

`GET /api/v1/compare/history` returns up to 90 days of observed landed prices and a hint:
`good_now`, `wait`, `typical`, or `learning`. `prices:snapshot` (hourly) re-checks recent searches
and saved alerts so the chart gains a point. Filters: `brand`, `size`, `min_minor`, `max_minor`,
`in_stock`, `sort` (`total_asc`, `total_desc`, `price_asc`), `currency`, `country`.

`POST /api/v1/compare/visual` accepts a JPEG, PNG, or WebP up to 500 KB. With the fake provider it
uses the demo Nike search. With SerpAPI it uploads the image and reads the first Google Lens product
title, then runs the same top-3 search. Out-of-stock offers are not shown as current.

`POST /api/v1/compare/clicks` with `offer_id` records the store host and returns the https URL,
appending `AFFILIATE_QUERY_PARAM` and `AFFILIATE_ID` when both are set. The client cannot submit an
arbitrary URL. A result older than `COMPARE_CACHE_TTL` (default 600 seconds) is not served, and a
provider error is `503 COMPARE_UNAVAILABLE` rather than a stale hit.

`COMPARE_PROVIDER=fake` (the default in `.env.example`) returns a deterministic catalog for local
development and tests. `COMPARE_PROVIDER=serpapi` calls [SerpAPI Google Shopping](https://serpapi.com/google-shopping-api)
(`engine=google_shopping`) with `SERPAPI_API_KEY`, then [Google Immersive Product](https://serpapi.com/google-immersive-product-api)
for each candidate’s sellers. A shopping row has no merchant link. Brand, size, and the store URL
come from the immersive product. A successful response that maps to no sellers is not cached.
A shopping response with an empty product list is a genuine miss and is cached. The HTTP timeout is
`COMPARE_TIMEOUT` (default 12 seconds, and never below 8). If that provider is selected and the key
is empty, search returns 503. It does not silently switch to the fake catalog. Shipping is known only
when the store published a currency amount or an unconditional “Free delivery”. A threshold such as
“Free delivery on $49+”, a date, or a day count stays unknown and is ranked by the item price. A
currency missing from the rate table is dropped rather than relabeled. Store links must be https and
must not point at SerpAPI, Google, or an IP address.

`POST /api/v1/wishlist` saves a search. An optional `target_minor` alerts when the best in-stock total
reaches that price. `prices:check-alerts` (hourly) emails, and sends an Expo push when a device token
is registered, on a drop or a newly reached target. It does not repeat a target that was already met.
Registration sends a welcome email. Mail uses `MAIL_MAILER` (`log` locally, `smtp` in `.env.production.example`).

Store ratings and review counts come from the provider when it publishes them. A rating of 4 or more
with at least 100 reviews is labeled trusted. Admins flag or block a host with `POST /api/v1/admin/stores`
(`open`, `flagged`, or `blocked`). A blocked host is left out of results and its link is refused.
`POST /api/v1/admin/coupons` adds a code for a store host; matching offers include it.

`POST /api/v1/assistant` sends a plain-language request to `LLM_PROVIDER`. `fake` (the default) returns a fixed note and then the normal top-3 search. `http` calls an OpenAI-compatible chat endpoint with `LLM_API_KEY`, `LLM_MODEL`, and `LLM_API_URL`. An empty key returns 503 and does not fall back to the fake note. The route is limited to 8 requests a minute.

`GET /api/v1/compare/forecast` estimates buy-now or wait from the price history and a typical sale calendar (Black Friday, Prime Day, Singles’ Day, and others). The response is labeled as an estimate.

`POST /api/v1/compare/link` accepts a TikTok, Instagram, or YouTube https URL, reads the page thumbnail (`og:image`), and runs the same photo search. It does not download the video. A rejected host is 422. A missing thumbnail is 503.

`GET /api/v1/compare/try-on?category=shoes` describes a camera guide for shoes and glasses. Other categories get a still preview. It is not a body scan.

`GET /api/v1/compare/alternatives` suggests another brand in the same kind of search when that option is cheaper, with the percent saved.

Shared lists (`/api/v1/shared-lists`) can be joined with a token, voted on, and used to tally pledges. Pledges are not payments.

`GET /api/v1/savings` totals the gap between the offer someone opened and the highest offer in that top 3, plus a few badges.

`extension/` is a Manifest V3 Chrome and Firefox extension. The popup stores the API base URL and a
Sanctum token. On a product page it asks the API whether another store is cheaper.

SerpAPI shopping and immersive results often omit tax and the countries a store ships to. Those stay
unknown and are labeled. Google Lens needs `SERPAPI_API_KEY`. Currencies beyond the static fallback
appear after `fx:refresh` succeeds. The owner must supply, when they want live prices, photos, and
mail: `SERPAPI_API_KEY`, `FX_FEED_URL` (optional; a default is set), `LLM_PROVIDER` (`fake` by default; `http` needs `LLM_API_KEY`, `LLM_MODEL`, and `LLM_API_URL`), `AFFILIATE_QUERY_PARAM`,
`AFFILIATE_ID`, `SENTRY_LARAVEL_DSN`, and the `MAIL_*` SMTP settings.

## Launch

- `Dockerfile` runs nginx in front of PHP-FPM (not `artisan serve`). `compose.yaml` adds PostgreSQL
  (pgvector), a `scheduler` service (`schedule:work`), and a `queue` service (`queue:work`). Copy
  `backend/.env.production.example` to `backend/.env`, set `APP_KEY`, and keep `APP_DEBUG=false`.
  The image installs the PostgreSQL 16 client so `db:backup` can run `pg_dump` against the pgvector 16
  server. Dumps go to the `backups` volume mounted at `storage/app/backups`.
- `deploy/crontab` and `deploy/supervisor.conf` use `/var/www/backend` on a host that is not using Compose.
- `GET /up` checks the database and, unless the queue driver is `sync`, the queue. The body is
  `{status: up}` or `{status: down}` with no exception text.
- `php artisan db:backup` writes `pg_dump` output under `storage/app/backups` and keeps seven files.
  It is scheduled daily at 02:10.
- `SENTRY_LARAVEL_DSN` posts a Sentry envelope (`event_id`, `sent_at`, and a timestamped event). An empty DSN does nothing.
- `LOG_LEVEL=error` in the production example.
- The web session is an HttpOnly cookie (`AUTH_COOKIE_SAME_SITE`, `AUTH_COOKIE_SECURE`). Native apps
  still use the bearer token. A cookie is only accepted together with `X-Requested-With`.
- Terms, privacy, and the affiliate disclosure are on the Legal screen in the app.
- CI runs Pint, PHPUnit with `COMMERCE_PURCHASES=true`, PHPUnit again with `COMMERCE_PURCHASES=false`
  (purchase tests are grouped and skipped), `tsc`, ESLint, and `npm audit` (the audit step is report-only).

`APP_KEY` is generated per environment and is not committed.

Demo buyer: `buyer@example.com` / `correct-horse` / `demo_buyer`. The admin password and TOTP secret
are generated by `php artisan db:seed --class=Database\\Seeders\\DemoAccountsSeeder` and printed once.
They are not in git. A later run prints a new pair only when that admin is missing or the stored
secret is still the one that used to be committed. To rotate a secret that was already generated,
clear `users.totp_secret` for that admin and run the seeder again. `DemoAccountsSeeder` and
`DemoReelsSeeder` do nothing when `APP_ENV=production`.

## Audit fixes (2026-10-23)

Money and visibility:

- Checkout, 1-Tap, and gifts charge the landed total: discounted merchandise, shipping once (plus 800 minor units when the parcel crosses a border), duty on discounted merchandise when it crosses a border, and VAT on merchandise plus shipping for an EU destination. The buyer’s saved address country wins over the profile country when `address_id` is sent. `expected_total_minor` is optional; a mismatch returns `409 PRICE_CHANGED`. Squad card holds stay merchandise-only.
- `POST /squads/{id}/join` does not reserve a seat and does not change the discount. `max_members` and the checkout discount count only buyers with a paid or authorized order. `SquadPriceService::settle` unlocks capture only when at least two of those squad orders exist, and only for a caller who has paid.
- `GET /auctions/live` is read-only. A bid authorizes the saved card first and stores the PaymentIntent; the previous leader’s hold is cancelled. Auction wins are holds, not a new order purpose.
- `POST /rewards/loot-box` is idempotent. The client keeps one Idempotency-Key until the call succeeds.
- `GET /api/v1/catalog/feed` requires a Sanctum token and lists only active products from verified sellers (or unsold catalog rows). Store pages use `FeedPost::visibleTo`, so friends-only posts stay hidden.
- Gift street addresses are Laravel-encrypted in `address_ciphertext`. Claiming the same gift again returns it to the same person.
- pgvector below 0.8 (this image ships 0.6.0, which has no `bit_hamming_ops`) stores embeddings as `double precision[]` and scans them exactly. Production should install pgvector ≥ 0.8 for HNSW.

Accounts and commerce:

- `POST /api/v1/auth/register` and `/auth/login` (10/minute) issue a Sanctum token that expires after `SANCTUM_EXPIRATION` minutes (default 30 days). `POST /api/v1/auth/logout` deletes the current token and can revoke one device. Registration leaves `social_feed_enabled` off. Bad credentials are `422 AUTH_FAILED`.
- Friendship is mutual. Follows are one-way (`POST` and `DELETE /users/{id}/follow`). `followers` and `following` are those follow rows, counted separately from friendship and block. A block deletes both follow directions. `viewer.connection` is `self`, `none`, `pending_out`, `pending_in`, `friends`, or `blocked`.
- Cart lines, encrypted shipping addresses, `POST /cart/checkout` (one Idempotency-Key, a derived key per line), `GET /orders` with fulfillment and tracking, and `POST /orders/{id}/withdraw` for a return. Sellers mark `POST /seller/orders/{id}/ship` only for their own processing or paid orders.
- Account deletion sets `deletion_requested_at` and pauses the account. After 14 days, `accounts:anonymize-due` calls `anonymize_user` and deletes Sanctum tokens. `POST /trust/unpause` clears a pause that is not a deletion or an anonymized account. Export includes profile, consents, devices without push tokens, posts, comments, likes, connections, orders, and closet.
- `POST /devices` registers an Expo push token. After sign-in the app asks for notification permission and calls that route with `expo-notifications`.
- Seller onboarding sends the legal name, country, and registration number the person types. The app no longer posts a sample business.
- Admin is a separate Sanctum guard. `POST /admin/login` requires the admin role, the password, and a TOTP code (or `ADMIN_DEMO_OTP` outside production). The token ability is `admin`. A normal login, even for an admin, only has the `mobile` ability and cannot call admin routes. The panel can schedule and end auctions, block a listing, verify or reject a seller, hide or remove a post or comment, and ban a member. Hidden posts and comments stay out of For You, Following, and hashtag search. A ban revokes tokens and login returns `403 ACCOUNT_BANNED`.
- Saved cards: `GET /payment-methods` lists brand and last4, `DELETE /payment-methods/{id}` detaches one. With no Stripe secret the app finishes a SetupIntent through `POST /payment-methods/setup/test-card` (Visa 4242, no PAN). With a publishable key, web uses Stripe Elements and native uses the Stripe React Native SDK, then `POST /payment-methods/setup/confirm`.
- `DemoReelsSeeder` adds four public image reels and hashtags so Reels and Explore are not empty. `DemoAccountsSeeder` adds `buyer@example.com`, `admin@example.com`, and `reels@example.com` outside production. The admin password and TOTP secret are generated at seed time and printed once. Both seeders are skipped when `APP_ENV=production`.

The Drops, Closet, and Wallet tabs redirect away from fixture money. The product sheet and dupe finder read live offers and related products.

`npm audit` (no `--force`) still reports 14 findings (8 moderate, 6 high). Every remaining fix is a major downgrade: `expo@44` (this app is Expo SDK 57; 44 also pulls an older `zod` and `node-forge`), `@stripe/stripe-react-native@0.18.1` (the app uses 0.64), `expo-router@58`, `nativewind@2` (the screens are NativeWind 4), or `tailwindcss@4` (NativeWind 4.2 requires Tailwind 3). Those upgrades change the SDK or the styling pipeline, so they are not applied. `npm audit fix` without `--force` does not clear them.

Scheduled work (capture, reconcile, anonymize, and the rest) runs from `php artisan schedule:work`, or from the cron in `deploy/crontab`. `deploy/supervisor.conf` also runs `queue:work` for catalog image indexing. The app does not broadcast and the default queue is the database, so Horizon and Reverb are not installed.

## Hardening pass (2026-10-14)

Money paths, uploads and visual search were attacked and hardened, and covered by tests: real card
payments with an undo window on every purchase path, Idempotency-Key on every money route, an idempotent
per-currency double-entry ledger with trigger-maintained balances, verified and forward-only Stripe
webhooks, upload and SSRF guards, copied-photo detection, pgvector HNSW search (sub-2 ms p99 at 1M
vectors), Redis caching, and a hardened Expo client. 209 tests, including multi-process race tests.
Read [`docs/SECURITY_HARDENING.md`](docs/SECURITY_HARDENING.md); files and merges are listed in
[`CHANGES.md`](CHANGES.md). Without a running scheduler (`php artisan schedule:run` every minute),
authorized payments are never captured.

## Release 2: social discovery and 1-Tap commerce

| | |
|---|---|
| Reels feed | `GET /api/v1/feed` (For You by an engagement/recency score, Following by time), keyset cursors, video and image posts (ffmpeg strips location metadata, faststart, poster), likes, comments with one level of replies, tracked share links. App: full-screen vertical paging, one playing video with the neighbours pre-buffered and cached, tap to pause, double-tap like, comments sheet. |
| Visual search | `POST /api/v1/visual-search`: photo or screenshot in, ranked products out (pgvector HNSW over multi-view embeddings + full-text + category). Built-in deterministic analyzer for development; plug a CLIP/SigLIP-class model with one env switch. App: camera with a radar/grid scanner, photo fallback, results with the detected box and 1-Tap Buy. |
| 1-Tap checkout | `POST /api/v1/checkout/one-tap`: saved card, default address, atomic stock reservation, Stripe Connect PaymentIntent with the vendor as merchant of record, 3-D Secure, undo window (authorization voided, nothing charged), scheduled capture, reconciler, signed idempotent webhooks. Affiliate products get a tracked redirect from the same button. App: glowing buy card, haptics, status banner with the undo countdown, PaymentSheet to save a card. |

Read next: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) (design, mapping from the specification, API,
flows, scaling, compliance, gaps) and [`docs/CURSOR_PLAYBOOK.md`](docs/CURSOR_PLAYBOOK.md) (step-by-step
integration with Cursor). New and changed files, and how your own edits were merged, are listed in
[`CHANGES.md`](CHANGES.md).

```
backend/app/Services/Feed, Media          feed queries, posting, comments, video/image processing
backend/app/Services/VisualSearch         analyzers, matcher, search service
backend/app/Services/Checkout, Payments   1-Tap, payment state machine, Stripe gateway, saved cards, webhooks
backend/app/Http                          controllers, form requests, API resources (17 endpoints + webhook)
backend/app/Jobs                          capture, reconcile, index catalog images, purge scans, process webhooks
backend/database/migrations/2026_10_12_*  six migrations (users, catalog + pgvector, feed, scans, 1-Tap, erasure)
backend/tests/Feature                     feed, video, visual search, 1-Tap, saved cards, webhooks, DB rules
mobile/src/features/reels, scanner        InfiniteFeedScreen, VisualScannerScreen and their parts
mobile/src/features/one-tap, payments     1-Tap engine, buy card, banner; Stripe bridge (native + web)
.cursor/rules/70-social-discovery.mdc     rules for these modules
```

Install on top of release 1: `composer require stripe/stripe-php:^21.3`, PostgreSQL with pgvector,
ffmpeg, copy the files, `php artisan migrate`, then the Stripe and visual-search settings in
`docs/ARCHITECTURE.md` section 11. Mobile: `npx expo install expo-camera expo-video
expo-image-manipulator expo-crypto @stripe/stripe-react-native` and the `app.json` plugin entries.

Verified: 64 PHPUnit tests (2,498 assertions) on PostgreSQL 16 + pgvector, the release 1 SQL invariant
and concurrency suites on the migrated schema, TypeScript strict and ESLint on the new mobile code, and an
end-to-end browser run of the web build against the real API (25 of 25 checks).

# Release 1: data architecture

```
.cursor/rules/            Cursor project rules (.mdc) - schema, money/ledger, security/privacy, backend, mobile, flows
backend/
  database/sql/           00_foundation ... 70_operations: the schema (plain PostgreSQL 15+)
  database/sql/tests/     invariants_test.sql (92 checks), concurrency_test.sh (9 race checks)
  database/migrations/    Laravel migration that loads the SQL files
  app/Models/             27 Eloquent models with every relationship
  app/Enums/              41 backed enums mirroring the CHECK constraints
  app/Services/Ledger/    LedgerService + LedgerLeg (the only way money moves)
  app/Services/Rewards/   CommissionSplitter (cashback / referral tiers / drop pool / platform)
  app/Support/            Money (integer minor units + FX), StripeAmount (Stripe's currency special cases)
  config/rewards.php      split percentages, hold days, drop tiers, payout minimums
  tests/                  Unit (Money, StripeAmount, splitter) + Feature (ledger invariants, enum/CHECK parity)
docs/erd.md               Mermaid ERD + money-flow diagram
```

## Install into a Laravel 12 app

1. Copy `backend/app`, `backend/config/rewards.php`, `backend/database` and `backend/tests` into the app;
   merge `AppServiceProvider` into yours. Copy `.cursor/` to the repository root.
2. Users have UUID keys, so adjust Laravel's stock migrations before the first `migrate`:
   - `0001_01_01_000000_create_users_table.php`: delete the `Schema::create('users', ...)` block (and its
     drop in `down()`); in `sessions` change `$table->foreignId('user_id')` to `$table->foreignUuid('user_id')`.
   - `php artisan install:api` (Sanctum), then in the published `personal_access_tokens` migration change
     `$table->morphs('tokenable')` to `$table->uuidMorphs('tokenable')`.
3. `.env`: `DB_CONNECTION=pgsql` (PostgreSQL 15+), `FINGERPRINT_PEPPER=<random 32+ bytes>`; add to
   `config/services.php`: `'fingerprint' => ['pepper' => env('FINGERPRINT_PEPPER')],`.
   Optional but recommended: `'timezone' => 'UTC'` on the pgsql connection in `config/database.php`.
4. `phpunit.xml`: point `DB_CONNECTION`/`DB_DATABASE` at a PostgreSQL test database (the stock SQLite
   setting cannot run this schema).
5. `php artisan migrate`, then in CI:
   `psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/sql/tests/invariants_test.sql`,
   `PGHOST=... PGUSER=... database/sql/tests/concurrency_test.sh`, and `php artisan test`.

## Changes from the brief, and why

| Brief | Built | Why |
|---|---|---|
| Cashback `pending -> cleared` after a 14-day hold | Clears only when the hold (>= 14 days) has passed **and** the network approved the commission. Enforced by triggers. | Networks keep commissions reversible for weeks (CJ's default lock is the 10th of the following month; Impact's is later). A 14-day clock alone pays out money that is later clawed back. |
| One `transactions` row with fee/cashback shares | Double-entry ledger: `transactions` (headers) + `ledger_entries` (legs) + `ledger_accounts`. | A purchase pays a variable number of people (buyer, 1-2 referrers, drop pool, platform). Legs make that exact, auditable and provably balanced; balances can't go negative. |
| `payout_status` on transactions | `clearance_status` + `hold_until`; separate `payouts` table for withdrawals. | Two different "payout statuses" would be confused by people and by Cursor. |
| `orders -> order_items` with `fx_rate` and Stripe PI on orders | `orders -> merchant_orders -> order_items`; FX rate and payment reference per merchant order. | Each merchant charges in its own currency, refunds and reports per merchant, and with affiliate links you never hold a PaymentIntent at all. |
| Commission rate / integration flags on `brands`, network on `products` | New `merchants` table (one row per affiliate program/storefront). | Brand is not merchant: Nike sold by Zalando pays Zalando's commission; Nike US (Impact) and Nike UK (Awin) are separate programs. |
| `users.push_notification_token` | `user_devices` | Users have several devices; tokens rotate and must be revoked per device. |
| "Real-time inventory locks" | Slot reservations with a TTL and race-safe capacity. | Stock at a third-party merchant can't be reserved through an affiliate link; we can only lock drop slots. |
| `discount_tier` | `pool_share_bps` + `reward_tiers` (share of the pool released per tier). | Self-funding: the platform can never promise more than the commission it earns. |
| `.cursorrules` file | `.cursor/rules/*.mdc` (7 scoped rule files) | Cursor's docs mark `.cursorrules` as legacy and slated for deprecation. Scoped rules also load only when relevant. |
| (missing for the brief to work) | `affiliate_clicks`, `affiliate_conversions`, `share_links`, `cart_items`, `feed_posts`, `user_consents`, `payouts`, `inbound_webhooks`, `currencies`, `status_transitions` | Attribution needs click ids (SubIDs) and network conversions; the influence tree needs share links; the opt-in feed, GDPR consent, withdrawals and idempotent webhooks need their own tables. |

## What the database guarantees (all tested)

- Ledger: every posting balances per currency at COMMIT; legs are append-only and can't be added later;
  headers are immutable; user and drop balances can't go negative, even under concurrent withdrawals;
  idempotency keys stop double posting; a `ledger_balance_drift` view must stay empty.
- Rewards: accrual holds are >= 14 days; clearance needs hold + network approval; pool release needs the
  drop to have succeeded and the participant's rewards to have cleared.
- Drops: capacity is race-safe (row lock + CHECK); one active slot per user; terms are immutable; only real
  participants' matching purchases count; affiliate-funded drops are blocked for no-cashback merchants.
- Ownership: composite foreign keys prove addresses, closet items and listings belong to the acting user.
- Privacy: feed posts are refused until the user opts in; addresses are encrypted; `anonymize_user()`
  erases identity while keeping financial records.
- Status changes follow `status_transitions` for drops, slots, conversions, orders, items, closet,
  listings, payouts and friendships.

The lockfile is Laravel 12. Release 1 was verified on PostgreSQL 16: 92 SQL invariant checks, 9 concurrency checks with real
parallel sessions, 166 end-to-end Eloquent checks (all 155 relationships run lazily and eagerly, all 44
enum/CHECK pairs, migration down/up), and the 22 shipped tests.

## Decisions to make before building on top of this

1. **Which merchants can fund rewards.** Per affiliate directories, Zara only runs a gated creator program
   that doesn't accept cashback sites, and Nike's programs (Impact US, Awin elsewhere) restrict cashback and
   coupon publishers. Confirm each program's terms when you apply; mark `cashback_allowed` only where
   allowed, or negotiate `merchant_funded` deals.
2. **Checkout model.** Start with affiliate redirect. Offer in-app checkout only where the merchant
   supports delegated checkout and stays merchant of record. Buying on the user's behalf would make you the
   merchant of record (returns, chargebacks, tax) and usually voids the commission.
3. **Payments entity.** Stripe doesn't onboard businesses registered in Georgia; the operating entity
   needs to be in a Stripe-supported country.
4. **Holding user money.** Keep user balances as rewards owed by the company and pay out through licensed
   providers (Stripe Connect, PayPal Payouts). Whether this needs e-money or money-transmitter licensing
   depends on the market. Check with counsel per launch market.
5. **Multi-tier referrals.** Keep the depth at 2 and pay only from the commission on real purchases, never
   for sign-ups. That keeps it clear of pyramid-scheme rules (e.g. EU UCPD Annex I, point 14), but review
   it per market.
6. **Resale tax reporting.** EU DAC7 (and similar regimes) can require you to report sellers. Collect what
   is needed during Connect onboarding.
