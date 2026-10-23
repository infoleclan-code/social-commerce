# Social commerce platform

Laravel 12.69 (`laravel/framework` ^12) + PostgreSQL backend, Expo app and Cursor rules for a social
e-commerce + rewards app: universal cart, affiliate cashback, group drops, multi-tier attribution,
digital closet, P2P resale (release 1), and a Reels-style feed, AI visual search and 1-Tap checkout
(release 2). Payments use `stripe/stripe-php` ^22. Schema changes after the core SQL live in
`backend/database/migrations/2026_10_13_*`, `2026_10_14_*`, and `2026_10_23_120000_secure_gifts_and_auctions.php`.

## Audit fixes (2026-10-23)

Money and visibility:

- Checkout, 1-Tap, and gifts charge the landed total: discounted merchandise, shipping once (plus 800 minor units when the parcel crosses a border), duty on discounted merchandise when it crosses a border, and VAT on merchandise plus shipping for an EU destination. The buyer’s saved address country wins over the profile country when `address_id` is sent. `expected_total_minor` is optional; a mismatch returns `409 PRICE_CHANGED`. Squad card holds stay merchandise-only.
- Unpaid `POST /squads/{id}/join` still reserves a seat and still discounts a later checkout by member count. `SquadPriceService::settle` unlocks capture only when at least two distinct buyers have non-cancelled `purpose = squad` orders, and only for a caller who has paid.
- `GET /auctions/live` is read-only. A bid authorizes the saved card first and stores the PaymentIntent; the previous leader’s hold is cancelled. Auction wins are holds, not a new order purpose.
- `POST /rewards/loot-box` is idempotent. The client keeps one Idempotency-Key until the call succeeds.
- `GET /api/v1/catalog/feed` requires a Sanctum token and lists only active products from verified sellers (or unsold catalog rows). Store pages use `FeedPost::visibleTo`, so friends-only posts stay hidden.
- Gift street addresses are Laravel-encrypted in `address_ciphertext`. Claiming the same gift again returns it to the same person.
- pgvector below 0.8 (this image ships 0.6.0, which has no `bit_hamming_ops`) stores embeddings as `double precision[]` and scans them exactly. Production should install pgvector ≥ 0.8 for HNSW.

Accounts and commerce:

- `POST /api/v1/auth/register` and `/auth/login` (10/minute) issue a Sanctum token that expires after `SANCTUM_EXPIRATION` minutes (default 30 days). `POST /api/v1/auth/logout` deletes the current token and can revoke one device. Registration leaves `social_feed_enabled` off. Bad credentials are `422 AUTH_FAILED`.
- Friendship is mutual. `followers` and `following` are both the accepted-friend count. `viewer.connection` is `self`, `none`, `pending_out`, `pending_in`, `friends`, or `blocked`. Declined requests are shown as `none` so a new request can be sent.
- Cart lines, encrypted shipping addresses, `POST /cart/checkout` (one Idempotency-Key, a derived key per line), `GET /orders` with fulfillment and tracking, and `POST /orders/{id}/withdraw` for a return. Sellers mark `POST /seller/orders/{id}/ship` only for their own processing or paid orders.
- Account deletion sets `deletion_requested_at` and pauses the account. After 14 days, `accounts:anonymize-due` calls `anonymize_user` and deletes Sanctum tokens. `POST /trust/unpause` clears a pause that is not a deletion or an anonymized account. Export includes profile, consents, devices without push tokens, posts, comments, likes, connections, orders, and closet.
- `POST /devices` registers an Expo push token. The mobile app does not call it yet: `expo-notifications` is not a dependency.
- Seller onboarding sends the legal name, country, and registration number the person types. The app no longer posts a sample business.

The Drops, Closet, and Wallet tabs redirect away from fixture money. Demo sheets that are not on a tab (product sheet, dupe finder) still contain sample copy.

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
