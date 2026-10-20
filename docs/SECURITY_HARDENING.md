# Security hardening and scale pass (2026-10-14)

This pass attacked the money paths, uploads and visual search the way an attacker (or an unlucky
network) would, fixed what broke, rebuilt the vector search for millions of rows, added Redis caching,
and wrote the tests that prove each fix. It covers the Laravel backend and the Expo app.

| | |
|---|---|
| Backend test suite | **209 tests, 1,459 assertions, all passing** on PostgreSQL 16 + pgvector 0.8.7 and Redis (`php vendor/bin/phpunit`, about 30 s). 8 of them are real multi-process race tests. |
| Coverage of the named critical paths | LedgerService **100%**, StripePaymentGateway **100%**, AutoTagService **100%**, PriceGuard **100%**, StripeWebhookHandler **100%**, OrderPayments 99.7%, CheckoutService 96.9% (pcov, lines). Concurrent checkout is proven by the multi-process suite (section 6). |
| Vector search at 1M rows | HNSW, p50 **0.68 ms**, p95 **1.03 ms**, p99 **1.40 ms**, recall@10 **0.990** (ef_search 100, 2 vCPU). |
| Mobile | TypeScript strict passes (0 errors), ESLint finds nothing new, 32 behaviour checks of the new client code pass (section 6.4). |
| Delivery | 142 files on the Mac: 70 new and 72 changed (backend 62 / 48, mobile 7 / 22, this document, `CHANGES.md`, `README.md`), merged with the vision work added on the Mac during the pass (section 3.4). Nothing deleted. |

Sections: [1 Threat model](#1-threat-model) · [2 Attacks and fixes: money](#2-attacks-and-fixes-money) ·
[3 Attacks and fixes: uploads, visual search, spoofing](#3-attacks-and-fixes-uploads-visual-search-and-product-spoofing) ·
[4 Database: migrations and indexes](#4-database-migrations-constraints-and-indexes) ·
[5 Redis caching](#5-redis-caching) · [6 Test suite](#6-test-suite) · [7 Mobile client](#7-mobile-client-hardening) ·
[8 Runbook](#8-runbook) · [9 Known limits](#9-known-limits-and-follow-ups)

---

## 1. Threat model

Assets: buyers' money (card holds and captures), the platform's ledger (commissions, price-protection
credits, payouts), stock, buyers' saved cards and addresses, catalog integrity, the bearer tokens.

Attackers and failure sources considered:

| Who / what | Capability | Examples tried |
|---|---|---|
| Malicious buyer | Any HTTP request with their own token; parallel requests; replayed requests | double taps, 50 parallel checkouts, replay with a changed body, stacking discounts, starting flash drops, farming price protection and watch rewards, saving someone else's card |
| Malicious seller | Catalog sync with arbitrary URLs and images | SSRF via image URL, copied photos of another seller's product, decompression bombs, polyglot files |
| Forger | Can POST to the public webhook URL | unsigned or re-signed events, old events replayed, test-mode events in live, amounts that do not match the order |
| The network | Loses requests and responses, duplicates, reorders | response lost after Stripe charged, request lost before Stripe, webhook delivered 8 times at once or out of order |
| Stripe | Rate limits, 5xx, timeouts, partial states | capture timeout, capture winning a race against undo, 3-D Secure abandoned |
| Our own workers | Overlapping cron runs on several servers | four capture workers at once |
| A compromised or curious client build | Reads the bundle, the keychain, the network | client API keys, tokens sent to third parties, cleartext API, secrets kept in memory |

Invariants the system now enforces (each one is checked by tests and most by the database itself):

1. Every journal balances per currency, has at least two lines, and is applied at most once (unique
   idempotency key). Balances always equal the sum of lines (`ledger:verify` runs daily).
2. An order is `paid` only after a real card capture (CHECK constraint), and payment state only moves
   forward (trigger).
3. One request intent creates at most one order, one Stripe PaymentIntent and one charge, whatever the
   retries, parallelism or lost responses.
4. Stock never goes below zero; a squad never exceeds its size.
5. A webhook changes nothing unless it is authentic, fresh, in the right mode, about one of our
   PaymentIntents, and its amount and currency match the order.
6. Only images that passed size, type, pixel and integrity checks are decoded, indexed or cached.
7. A buyer only ever sees listings of verified sellers that are active, and the client total they
   confirmed is the total they pay.

---

## 2. Attacks and fixes: money

Status before this pass, what an attack did, and the fix. "Test" names the test that now proves it.

### 2.1 Checkout, gifts, squads and blind boxes took no money

| Before | Attack / failure | Fix | Test |
|---|---|---|---|
| `CheckoutService` and `HabitScaleService` wrote orders and gifts as `paid` without any card payment ("guard" flag). | Free goods: every checkout, gift and blind-box pull was "paid" without a charge. | All four purchase paths go through one orchestrator, `App\Services\Commerce\Purchases` + `App\Services\Payments\OrderPayments`: (1) order + authorization row + stock in one short transaction, (2) Stripe authorization **outside** the transaction with key `order:{id}:authorize`, (3) the result applied forward-only. Capture happens after the undo window by `payments:capture-due`. Side effects (commissions, price protection, gift claimable, box revealed) run once, at capture. | `CheckoutPaymentFlowTest::test_checkout_authorizes_then_captures_after_the_undo_window_with_every_effect_once`, `PurchasePathsTest` (squad, gift, blind box, withdrawal) |
| The database allowed `status = 'paid'` on any row. | A bug or a direct write marks an unpaid order paid. | `orders_paid_means_captured` CHECK, `orders_payment_status_transition` and `status_transitions` triggers. | `AbuseRulesTest::test_the_database_refuses_paid_orders_without_a_capture_and_backward_payment_moves` |

### 2.2 Idempotency failures

| Attack / failure | Fix | Test |
|---|---|---|
| Double tap or parallel taps create several orders and several card holds. | `EnforceIdempotency` middleware on every route that moves money or reserves stock (`checkout`, `checkout/one-tap`, `squads/authorize`, `squads/{id}/authorize-join`, `orders/{id}/cancel`, `orders/{id}/withdraw`, `gifts`, `blind-boxes/{id}/pull`, `payment-methods/setup`, `payment-methods/confirm`): the key is claimed atomically (`INSERT .. ON CONFLICT DO NOTHING`) before the controller runs; a twin gets `409 REQUEST_IN_PROGRESS`. Services keep their own idempotency underneath (`orders.idempotency_key`, ledger keys, Stripe keys). | `ConcurrencyTest::test_ten_concurrent_taps_with_one_key_create_one_order_and_one_authorization`, `IdempotencyKeyTest` |
| Same key, different body (e.g. a cheaper product) to reuse a stored success. | Request fingerprint `sha256(user, method, path, canonical body, uploaded file hashes)`; a mismatch is `422 IDEMPOTENCY_KEY_REUSED`. | `IdempotencyKeyTest::test_a_repeated_request_is_replayed_and_a_different_one_with_the_same_key_is_refused` |
| **Bug found by the tests:** multipart requests all had the same fingerprint (`json_encode` failed on the uploaded file and produced an empty string), so any upload could replay another's result. | Canonical body from `$request->input()` plus SHA-256 of each uploaded file, encoded with `JSON_THROW_ON_ERROR`. | `PaymentEdgeCasesTest::test_multipart_uploads_are_part_of_the_idempotency_fingerprint` |
| Response lost after Stripe charged; client retries. | The retry replays the stored response (`Idempotent-Replayed: true`); Stripe sees the same idempotency key. 5xx, 408, 409, 423, 425, 429 are never stored, so the client may retry with the same key. | `CheckoutPaymentFlowTest::test_a_response_lost_after_stripe_charged_is_replayed_not_charged_twice` |
| A replay leaks a 3-D Secure client secret from storage. | Stored copies have `next_action` and every key ending in `secret` blanked; the app fetches a fresh secret with `POST /orders/{id}/sync`. | `IdempotencyKeyTest::test_no_secret_is_ever_stored_for_a_replay` |
| A crashed request leaves its key locked forever. | Claims expire after 60 s and can be taken over; keys are pruned after 24 h (`idempotency:prune`). | `IdempotencyKeyTest::test_a_request_in_flight_blocks_its_twin_and_a_dead_claim_can_be_taken_over` |

### 2.3 Race conditions on the double-entry ledger

| Before | Attack / failure | Fix | Test |
|---|---|---|---|
| Journals had no idempotency key. | A retried job or two workers post the same commission twice. | `ledger_journals.idempotency_key` UNIQUE; `LedgerService::post()` inserts with `ON CONFLICT DO NOTHING` and refuses a key reused for different lines. Keys are derived from business facts: `commission_pending:{order}:{user}`, `commission_reverse:{order}:{user}:{target}`, `price_protection:{order}:{cumulative}`, `order:{id}:refund:{cumulative}`. | `ConcurrencyTest::test_one_ledger_key_posted_by_ten_processes_is_one_journal`, `LedgerServiceTest::test_a_posting_is_applied_once_per_key_and_a_reused_key_is_refused` |
| Balances were computed by reading lines, then writing a wallet row (read-modify-write). | Two releases read the same pending balance and both pay it out. | `ledger_balances` maintained by an `AFTER INSERT` trigger in the same transaction as the lines; `pending` can never go negative, `available` only through reversal kinds; per-order advisory locks (`pg_advisory_xact_lock(hashtextextended('ledger:order:'||id, 0))`); lines sorted by account so locks are always taken in the same order (no deadlocks). Platform accounts are sharded 16 ways to avoid a hot row. `user_wallets.cash_minor` is only written by the trigger. | `ConcurrencyTest::test_concurrent_releases_cannot_overdraw_a_pending_balance`, `LedgerServiceTest::test_pending_never_goes_negative_and_available_only_through_reversals` |
| The balance check ignored currency. | 100 USD debited against 100 EUR credited "balanced". | `ledger_journal_must_balance()` checks per currency, deferred to commit. | `LedgerServiceTest::test_the_database_refuses_edits_and_unbalanced_journals_even_without_the_service` |
| A refund reversed commission from the gross. | Partial refunds over-reversed; a release racing a refund could pay out money that was refunded. | Net-proportional reversal: kept commission = `floor(commission × kept / total)`; reversal keyed by the target amount, so repeats and races converge. | `ConcurrencyTest::test_a_release_racing_a_full_refund_always_nets_to_zero`, `LedgerServiceTest::test_commission_lifecycle_release_partial_refund_full_refund_and_replays` |

### 2.4 Stripe webhook forgery and event handling

| Before | Attack / failure | Fix | Test |
|---|---|---|---|
| Signature verified, but no size cap, no mode check, amounts not compared, partial refunds treated as full, 3-D Secure shown as authorized, no failed or dispute handling, no index from PaymentIntent to order. | Forged, replayed, oversized, wrong-mode or tampered events; a valid event for an unknown intent or someone else's order. | Body capped at 1 MB; signature tolerance 300 s; `livemode` must match the secret's mode; the intent must be ours (stored id, or metadata `order_id` + `buyer_id` that match); amount and currency must match or the event is recorded in `payment_anomalies` and nothing changes. Events are processed idempotently, then recorded in `stripe_events` with an outcome (`applied`, `ignored`, `anomaly`, `duplicate`). Refunds use the cumulative `amount_refunded`; disputes move forward only (open → won/lost; lost = full refund without restock). | `WebhookSecurityTest` (11 tests), `ConcurrencyTest::test_one_webhook_delivered_eight_times_at_once_is_applied_once` |
| Money captured for an order we had already cancelled was kept. | Late capture after a cancel. | Compensation: a late hold is cancelled, a late capture refunded automatically. | `WebhookSecurityTest::test_money_taken_for_a_cancelled_order_is_refunded_automatically` |

### 2.5 Stripe gateway failure handling

| Before | Failure | Fix | Test |
|---|---|---|---|
| **Bug found by the tests:** `RateLimitException` extends `InvalidRequestException` in stripe-php, so rate limits were reported as card declines. | A Stripe rate limit shows the buyer "declined" and releases a payment that may have been fine. | `StripePaymentGateway::call()` maps rate limit, authentication and permission errors to *unavailable* first; connection errors and 5xx to *outcome unknown* (retried later with the same key by `payments:reconcile`); card and invalid-request errors are definite. Timeouts are bounded (connect 5 s, total 20 s), API version pinnable. | `StripePaymentGatewayTest` (18 tests) |
| Lost requests left orders hanging. | Request lost before Stripe; Stripe unreachable for minutes; 3-D Secure abandoned. | `payments:reconcile` re-sends the same key up to 6 times, then releases; abandoned 3-D Secure (> 30 min) is cancelled and restocked; capture claims rows with `FOR UPDATE SKIP LOCKED` and uses `clock_timestamp()`. | `CheckoutPaymentFlowTest`, `PaymentEdgeCasesTest`, `ConcurrencyTest::test_four_capture_workers_capture_each_authorization_exactly_once` |

### 2.6 Concurrent stock purchases

`UPDATE products SET stock = stock - q WHERE id = ? AND stock >= q` under the row lock, and the same for
flash-drop units, inside the order transaction; a squad's size is enforced by the `squad_members_capacity`
trigger with a row lock.
Tests: `ConcurrencyTest::test_twenty_buyers_racing_for_five_units_get_exactly_five_orders`,
`ConcurrencyTest::test_twelve_people_joining_a_five_person_squad_leaves_exactly_five`.

### 2.7 Pricing, discounts and abuse

| Before | Attack | Fix | Test |
|---|---|---|---|
| `POST /drops/live` created a flash drop on request. | Any viewer starts a 40% flash drop for any product. | `drops/live` is read-only (GET and POST); drops are created only by `drops:rotate` on the scheduler. | `AbuseRulesTest::test_a_client_cannot_start_a_flash_drop_only_the_scheduler_can` |
| Discounts stacked to 80% (drop + squad + coupon). | Stack everything. | `DiscountPolicy`: max(flash, squad) + one coupon, capped at `commerce.max_discount_bps` (5,000). | `CheckoutPaymentFlowTest::test_discounts_never_stack_past_the_cap` |
| The client could be charged a price it never saw. | Price changes between view and tap. | `expected_total_minor` on checkout: a different server total is `409 PRICE_CHANGED` with `meta.total`, before anything is reserved. Checkout always prices from the locked row, never from a cache. | `CheckoutPaymentFlowTest::test_a_stale_price_is_refused_before_anything_is_reserved`, `CachingTest::test_checkout_ignores_a_stale_cached_price` |
| Price protection paid any momentary drop, across currencies, on refunded orders, and was never taken back. | Seller and buyer collude: flash a 1-cent price for a second. | `PriceGuard`: the lower price must have held 24 h (`product_price_history`), same currency, after the purchase, within 14 days; payout capped at 50% of the paid unit price; cumulative keys pay only the difference; refunds take the credit back. | `PriceGuardTest` (6 tests) |
| Watch rewards trusted the client's duration and paid authors. | Script "watches" thousands of posts. | Server duration for videos, once per post, daily caps (20 watches), never on your own post. | `AbuseRulesTest::test_watch_rewards_use_the_server_duration_are_capped_daily_and_never_self_paid` |
| Saved card confirmed by any `pm_` id (IDOR). | Attach someone else's card to your account. | Confirmation by `setup_intent_id` verified with Stripe (succeeded, same Stripe customer); a payment method owned by another account is refused. | `PurchasePathsTest::test_cards_are_saved_only_for_their_owner`, `::test_card_confirmation_refuses_every_mismatch` |
| Buyer protection limits | Account takeover spending | Monthly spend limit (net of refunds, race-safe), pause switch, single-order cap (`max_order_minor`), late-night bursts held until morning. | `CheckoutPaymentFlowTest` |

---

## 3. Attacks and fixes: uploads, visual search and product spoofing

### 3.1 Malicious image payloads

`App\Services\Media\ImageGuard` runs before any decoder, cache or index sees the bytes:

| Attack | Defence |
|---|---|
| Decompression bomb (tiny PNG declaring 30,000 × 30,000 px) | Header dimensions read without decoding; edge ≤ 12,000 px and ≤ 16 MP, refused before `imagecreatefromstring`. |
| Oversized body / oversized base64 | 8 MB cap; base64 length pre-checked (`max:11184816`) and strictly decoded. |
| Polyglot, truncated or corrupt file, SVG, GIF, HTML with an image extension | Magic bytes must be JPEG, PNG or WebP, and agree with the header and the decoder; anything else is `422 IMAGE_REJECTED`. Uploads also use `mimetypes:` validation. |
| Invalid or forged image hash | Optional `image_sha256` from the client must match the received bytes (`422 IMAGE_HASH_MISMATCH`). The scan cache is keyed by the server's own hash, and only bytes that passed the guard are ever cached. |
| Flat or near-blank images matching everything | Signatures of flat images are refused. |

Tests: `ImageSecurityTest` (bombs, polyglots, mislabelled, SVG, GIF, truncated, base64 tricks, hash
mismatch, endpoints return a clean 422).

### 3.2 SSRF through catalog image URLs

`SafeImageFetcher` (seller catalog sync): https on port 443 only; the host is resolved once and must be a
public address (`FILTER_FLAG_GLOBAL_RANGE`: no private, loopback, link-local, metadata or reserved
ranges); the connection is pinned to that address (`CURLOPT_RESOLVE`, no DNS rebinding); no redirects;
size and time caps. Indexing runs in a queued job (`IndexProductImage`), off the request.
Test: `ImageSecurityTest::test_the_fetcher_refuses_anything_but_public_https_hosts`.

### 3.3 Product spoofing

| Attack | Defence | Test |
|---|---|---|
| A seller lists another seller's product photo for a different product. | On indexing, an exact (`sha256`) or near-duplicate photo (64-bit dHash Hamming ≤ 6 **and** luminance cosine ≥ 0.95) from a different seller with a different `canonical_key` (GTIN) creates a `listing_flags` row and moves the listing to `review`; it disappears from search, offers and auto-tagging. Offers of the same GTIN may share the maker's photo. | `AutoTagAndSpoofingTest::test_a_copied_photo_for_a_different_product_is_flagged_and_hidden_everywhere`, `::test_offers_of_the_same_gtin_may_share_the_makers_photo` |
| Unverified or de-verified sellers in search. | `product_embeddings.searchable` is maintained by triggers from the listing status and the seller's verification; the HNSW index is partial on it. | `::test_a_seller_losing_verification_disappears_from_search`, `VectorIndexTest::test_the_database_keeps_searchable_in_sync_with_listing_state` |
| Auto-tag attaches the wrong product to a reel. | Two signals (luminance and edge structure) must both pass, and the best match must beat the runner-up by a margin, otherwise it is only suggested. | `::test_two_equally_good_listings_are_only_suggested` |
| Catalog sync with bad data. | https-only image URLs, GTIN check digit, ISO currency (locked per listing), price and stock bounds. | `::test_catalog_sync_validates_urls_gtins_and_currency_and_indexes_off_the_request` |

### 3.4 The vision cross-check layer (your Vision describer work, merged)

While this pass was running, the project on the Mac gained a vision describer (`App\Services\Vision`:
an OpenAI or Anthropic model names the scanned product's category, brand and colors; a local describer
reads colors only), `ColorPalette`, `VisionCrossCheck`, the `ScanCatalogSeed` service and seeder,
`catalog:embed-scans`, the migration `2026_10_14_001100_product_vision_attributes.php`, the
`VisionAccuracyTest`, and mobile changes to the scan results sheet. All of it is kept. It was merged
on top of the hardened pipeline (your two accuracy tests pass unchanged), with these changes:

| Your code | Risk found | Merged behaviour |
|---|---|---|
| `ScanAndTapService::search()` described the photo before any validation (`imagecreatefromstring` on raw bytes) | A decompression bomb or a malformed file reaches GD and the vision provider. | ImageGuard runs first; the describer only ever sees bytes that passed it. Test: `VisionScanSecurityTest::test_the_describer_only_sees_images_that_passed_the_guard_and_once_per_frame`. |
| A scan called the vision model on every request | Cost and latency: one paid model call per viewer of a viral reel. | The description is cached with the candidates per image hash (`scan:v3:{sha256}`, 60 s): one call per frame per minute. |
| The vector fallback read `SELECT ... FROM products ... LIMIT 200` without an order | With a large catalog the nearest products are usually not among 200 arbitrary rows; full-table work per scan. | Candidates come from the HNSW index (`VectorStore::nearest`), then your strict / semantic / canonical rules apply unchanged. |
| `byTags()` loaded every in-stock product of the category, including listings in review and unverified sellers | Copied-photo listings and unverified sellers come back through the tag path; unbounded memory per scan. | Only buyable listings (in stock, active, platform or verified seller), the 300 cheapest of the category, served by the new expression index `products_vision_category_idx`. Test: `::test_a_recognized_category_never_returns_hidden_or_unverified_listings`. |
| `RemoteVisionDescriber` sent every image as `image/jpeg`, waited up to 20 s, and passed the model's text through | PNG/WebP refused by the provider; a 20-second worker stall per scan; prompt-injected text (a photo can carry instructions) echoed to the app in `detected`. | Real media type; 3 s connect / 8 s total (`VISION_TIMEOUT_SECONDS`); brand and features reduced to short plain text, unknown categories dropped, failures fall back to local colors. Tests: `::test_untrusted_model_output_is_reduced_to_known_values`, `::test_a_failing_provider_falls_back_to_local_colors_and_the_real_media_type_is_sent`. |
| The describer binding picked a remote model whenever a key was in `.env`, tests included | Test runs send test photos to a provider and depend on its answers. | The testing environment always uses the local describer. Test: `::test_tests_and_unconfigured_installs_never_call_a_vision_provider`. |
| `ScanCatalogSeed` downloaded with `file_get_contents($product->image_url)` | SSRF from catalog URLs (internal addresses, metadata endpoints, `file://`). | The SSRF-safe fetcher and ImageGuard; every photo is also indexed into `product_embeddings` (what search reads). Offers that share one photo get one `canonical_key` (`seed:night-jacket`, ...), so the copied-photo check does not send them to review. |
| Migration timestamp `2026_10_14_001100` was used by both changes | Two migrations with one timestamp. | Yours keeps its name (it may already be applied on your database); the pgvector migration from this pass is `2026_10_14_001110_visual_search_pgvector.php`. |

Privacy: when `OPENAI_API_KEY` or `ANTHROPIC_API_KEY` is set, the scanned photo goes to that provider.
List it as a processor (DPA, data-transfer terms) and mention it in the privacy notice before enabling it
in production.

---

## 4. Database: migrations, constraints and indexes

Three new migrations from this pass, plus yours, run in this order after the existing chain
(`php artisan migrate`). Each has a full `down()`; up → down → up of the whole chain was verified.

| Migration | What it does |
|---|---|
| `2026_10_14_001000_harden_ledger_and_payments.php` | Ledger idempotency keys (legacy rows backfilled as `legacy:{id}`), new journal kinds, line CHECKs (account format, ISO currency, amount cap) added `NOT VALID`, per-currency balance trigger, `ledger_balances` + trigger + backfill, `ledger_balances_protect`, `user_wallets_protect_cash`; orders: `payment_status`, `purpose`, refunds, commissions, `captured_at`/`cancelled_at`, CHECKs and transition triggers; new states for `payment_authorizations`; `stripe_events.livemode`/`outcome`; `payment_anomalies`, `payment_disputes`, `idempotency_keys`; gift and blind-pull states; `squad_members_capacity` trigger; products `canonical_key`, `listing_status`, `image_sha256`, `products_image_url_https` (NOT VALID); `product_price_history` + trigger; `listing_flags`. Short locks only. |
| `2026_10_14_001100_product_vision_attributes.php` (yours) | `vision_category`, `vision_colors`, `vision_brand`, `vision_features` on products. |
| `2026_10_14_001110_visual_search_pgvector.php` | `product_embeddings` (vector(64) luminance and structure, bit(64) dHash, `searchable`), the searchable triggers, HNSW indexes. Falls back to `double precision[]` and exact scans if pgvector is missing (development only). |
| `2026_10_14_001200_scale_indexes_concurrently.php` | Every index on existing large tables, `CREATE INDEX CONCURRENTLY IF NOT EXISTS` outside a transaction (an interrupted build's INVALID index is dropped and rebuilt on the next run), then `VALIDATE CONSTRAINT` for the ledger CHECKs (no write lock). |

### 4.1 Index definitions

Vector search (`001100`):

```sql
CREATE INDEX product_embeddings_luma_hnsw ON product_embeddings
  USING hnsw (luma vector_cosine_ops) WITH (m = 16, ef_construction = 128)
  WHERE searchable;
CREATE INDEX product_embeddings_phash_hnsw ON product_embeddings
  USING hnsw (phash bit_hamming_ops) WITH (m = 16, ef_construction = 64);
CREATE INDEX product_embeddings_sha_idx ON product_embeddings (image_sha256);
CREATE INDEX product_embeddings_seller_idx ON product_embeddings (seller_id) WHERE seller_id IS NOT NULL;
```

Money paths and catalog (`001200`, all `CONCURRENTLY`):

```sql
CREATE INDEX ledger_journals_order_kind_idx     ON ledger_journals (order_id, kind) WHERE order_id IS NOT NULL;
CREATE INDEX ledger_lines_account_currency_idx  ON ledger_lines (account, currency);   -- replaces ledger_lines_account_idx
CREATE INDEX orders_buyer_created_idx           ON orders (buyer_id, created_at DESC);
CREATE INDEX orders_product_paid_idx            ON orders (product_id) WHERE status = 'paid';
CREATE INDEX orders_release_due_idx             ON orders (delivered_at) WHERE status = 'paid' AND delivered_at IS NOT NULL;
CREATE INDEX payment_authorizations_order_idx   ON payment_authorizations (order_id);
CREATE INDEX payment_authorizations_due_idx     ON payment_authorizations (status, capture_after)
  WHERE status IN ('pending', 'requires_action', 'authorized');
CREATE INDEX reward_events_user_kind_idx        ON reward_events (user_id, kind, created_at DESC);
CREATE INDEX products_canonical_idx             ON products (canonical_key) WHERE canonical_key IS NOT NULL;
CREATE INDEX products_seller_idx                ON products (seller_id) WHERE seller_id IS NOT NULL;
CREATE INDEX products_moderation_idx            ON products (updated_at DESC) WHERE listing_status <> 'active';
CREATE INDEX price_protections_open_idx         ON price_protections (protected_until);
CREATE INDEX products_vision_category_idx       ON products (lower(vision_category), price_minor) WHERE vision_category IS NOT NULL;

CREATE UNIQUE INDEX orders_payment_intent_uidx        ON orders (stripe_payment_intent_id) WHERE stripe_payment_intent_id IS NOT NULL;
CREATE UNIQUE INDEX blind_pulls_one_per_day_uidx      ON blind_pulls (user_id, pulled_on) WHERE status <> 'failed';  -- replaces blind_pulls_user_id_pulled_on_key
CREATE UNIQUE INDEX blind_pulls_order_uidx            ON blind_pulls (order_id) WHERE order_id IS NOT NULL;
CREATE UNIQUE INDEX reward_events_purchase_once_idx   ON reward_events (user_id, source_id) WHERE kind = 'purchase' AND source_id IS NOT NULL;
CREATE UNIQUE INDEX commission_ledger_earn_once_uidx  ON commission_ledger (order_id, referral_link_id) WHERE entry = 'earn' AND order_id IS NOT NULL;
```

### 4.2 Why HNSW, and how it behaves at millions of rows

- **HNSW, not IVFFlat.** IVFFlat needs a training step on representative data and degrades as the
  catalog drifts from its centroids (periodic rebuilds); HNSW is built incrementally, keeps recall as
  rows are added, and supports iterative scans. IVFFlat remains an option for a write-heavy bulk-loaded
  catalog where build time matters more than recall; then use `lists ≈ rows / 1000` and
  `ivfflat.probes ≈ sqrt(lists)`.
- **Partial index (`WHERE searchable`).** Only listings a buyer may see are in the graph, and the
  query repeats the predicate so the planner uses the index. Hidden listings never cost latency.
- **Query-time knobs, per transaction** (`App\Services\Search\VectorStore`, via `set_config(..., true)`):
  `hnsw.ef_search = max(limit, 100)`, `hnsw.iterative_scan = relaxed_order` (filters after the ANN
  step cannot starve the result), `hnsw.max_scan_tuples = 20000` (hard bound on that extra work, so
  no latency spikes). The inner query is a plain `ORDER BY luma <=> $1 LIMIT k`; candidates are
  re-scored exactly on both signals afterwards.
- **Benchmark** (`php artisan visual-search:benchmark`, 1,000,000 vectors in 5,000 clusters, 90%
  searchable, PostgreSQL 16, pgvector 0.8.7, 2 vCPU container):

  | ef_search | p50 | p95 | p99 | recall@10 |
  |---|---|---|---|---|
  | 40 | 0.83 ms | 1.63 ms | 2.09 ms | 0.980 |
  | **100 (default)** | **0.68 ms** | **1.03 ms** | **1.40 ms** | **0.990** |
  | 200 | 0.89 ms | 1.58 ms | 2.04 ms | 0.990 |

  Build: 131.5 s for 1M rows; index 489 MB, table 300 MB. Plan: `Index Scan using ... hnsw`.
- **Capacity.** About 0.5 GB of index per million 64-d vectors: keep the index in RAM
  (`shared_buffers` + page cache). At 10M+ rows: build with `maintenance_work_mem` ≥ 8 GB and
  `max_parallel_maintenance_workers`; consider `halfvec(64)` (half the memory, negligible recall
  loss) or binary quantization with re-ranking; partition by category only if queries are
  category-scoped.
- **Bulk loads.** Load first, then `php artisan visual-search:rebuild-index` (REINDEX CONCURRENTLY, no
  write lock). `php artisan visual-search:doctor` checks the extension version, indexes and plans.

---

## 5. Redis caching

All caching goes through `App\Support\SingleFlightCache` (any store with atomic locks; Redis in
production):

- **Fresh / stale / missing.** Fresh values are served directly. Stale values (within the stale
  window) are served while exactly one request holds the refresh lock and recomputes: no thundering
  herd when a hot key expires. A missing key waits up to 3 s for the computing request, then computes
  without the cache rather than fail.
- **Degrades, never fails.** A Redis outage or lock error falls back to computing from PostgreSQL.
- **Versioned namespaces.** `version()` / `bump()` invalidate a whole family of keys with one `INCR`
  (for example every offer list of one GTIN after a seller changes a price).

| Key family | What | TTL (fresh + stale) | Invalidation |
|---|---|---|---|
| `feed:fy:global:v1` | For You candidate pool: top 1,000 public post ids by `rank_score` | 20 s + 60 s | time |
| `feed:fy:tag:v1:{sha256(tag)}` | Per-tag pools (top 200) for the viewer's 5 strongest tags | 20 s + 60 s | time |
| `feed:taste:v1:{viewer}` | Viewer's tag weights | 60 s | forgotten on every taste update |
| product graph / offers (versioned by product and canonical key) | Multi-seller offers per destination country (landed totals), related products (same GTIN, co-tagged, co-bought) | offers 60 s, graph 600 s | `bump()` on seller catalog sync and price changes |
| `scan:v3:{sha256(image)}` | One frame's analysis: the vision description and the HNSW candidates (a viral reel scanned by thousands costs one model call and one index search per minute) | 60 s | time; only guard-passed bytes |

Safety rules: caches hold **ids and public data only**. Every page re-reads the rows and re-applies
visibility, blocks and soft deletes, so a cached id never leaks a hidden, deleted or blocked post
(`CachingTest::test_a_cached_id_never_leaks_a_deleted_hidden_or_blocked_post`). Checkout never prices
from a cache. Set `CACHE_STORE=redis` (phpredis) in production; the test suite also runs the Redis
store itself (`CachingTest::test_the_redis_store_works_with_locks_and_versions`).

---

## 6. Test suite

Run: `php vendor/bin/phpunit` (PostgreSQL 16 + pgvector ≥ 0.8; the user needs `CREATEDB` so the race
suite can create `social_race`; Redis on localhost for the Redis-store test, which is skipped without it). Coverage: install pcov and
run `php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text`.

### 6.1 Inventory (new in this pass)

| File | Tests | Proves |
|---|---|---|
| `tests/Unit/Payments/StripePaymentGatewayTest.php` | 18 | Exact Stripe requests (manual capture, card only, metadata, idempotency keys), zero-decimal currencies, 3-D Secure, declines as results, timeouts and 5xx as unknown outcomes, rate limits not treated as declines, capture/cancel races, refunds, bounded transport timeouts. Uses a fake HTTP client, never the network. |
| `tests/Feature/Ledger/LedgerServiceTest.php` | 10 (+ data sets) | Invalid postings refused, once per key, balances = sum of lines, pending never negative, commission lifecycle with partial and full refunds, price-protection credits, the database refusing edits and unbalanced journals without the service. |
| `tests/Feature/Payments/CheckoutPaymentFlowTest.php` | 16 | Authorize → undo window → capture with every effect once; declines release stock, coupon and flash units; 3-D Secure; lost requests and responses; Stripe unavailable; stale price; discount cap; order cap; spend limit under parallel taps; night hold. |
| `tests/Feature/Payments/WebhookSecurityTest.php` | 11 | Forged, tampered, replayed, oversized, wrong-mode and wrong-amount events; unknown intents; duplicates and out-of-order events; refunds; disputes; compensation. |
| `tests/Feature/Payments/IdempotencyKeyTest.php` | 8 (+ data sets) | Key format, replay, body mismatch, per-user scope, in-flight twin, takeover, release on 5xx, secrets never stored, oversized and non-JSON answers, pruning. |
| `tests/Feature/Payments/PaymentEdgeCasesTest.php` | 12 | Unknown orders, capture timeouts, Stripe outages during cancel/refund/sync, late holds, foreign intents, failing jobs, referral budget, inconsistent requests, multipart fingerprint, wrong-state operations. |
| `tests/Feature/Payments/PurchasePathsTest.php` | 8 | Squads (solo → squad price, expiry, capacity), gifts, blind boxes, withdrawal, saved-card ownership. |
| `tests/Feature/Commerce/PriceGuardTest.php` | 6 | Only captured orders, flash dips pay nothing, held drops pay once, cap, currency switches, refunds and disputes. |
| `tests/Feature/Commerce/AutoTagAndSpoofingTest.php` | 8 | Auto-tag margins and idempotency, copied photos flagged, GTIN-shared photos allowed, verification, catalog validation. |
| `tests/Feature/Commerce/AbuseRulesTest.php` | 6 | Flash drops only from the scheduler, watch-reward rules, database refusals, every money job scheduled, release and reconcile end to end. |
| `tests/Feature/Media/ImageSecurityTest.php` | 7 (+ data sets) | Hostile files, base64 tricks, hash mismatch, signatures robust to re-encoding, SSRF rules, clean errors. |
| `tests/Feature/Cache/CachingTest.php` | 10 | Cached feed pages without duplicates or leaks, per-country offers with instant invalidation, checkout ignores caches, scan cache, single flight under contention and outages, Redis locks and versions. |
| `tests/Feature/Search/VectorIndexTest.php` | 4 | HNSW parameters, the plan uses the partial HNSW index, exact re-scoring, `searchable` kept in sync. |
| `tests/Feature/Search/VisionScanSecurityTest.php` | 6 | The vision layer (section 3.4): no hidden or unverified listings through category matches, semantic matches stay in the category and colors, describer only after ImageGuard and once per frame, model output sanitized, provider failures and media types, no provider calls in tests. |
| `tests/Feature/VisionAccuracyTest.php` (yours) | 2 | Unchanged and passing on the merged code. |
| `tests/Feature/Concurrency/ConcurrencyTest.php` | 8 | Real parallel PHP processes released together by an advisory-lock barrier (below). |

### 6.2 Concurrency suite

Each scenario starts N separate PHP processes on committed data and asserts what must hold under any
interleaving:

| Scenario | Must hold |
|---|---|
| 20 buyers race for 5 units | exactly 5 orders, stock 0, never negative |
| 10 parallel taps with one Idempotency-Key | 1 order, 1 authorization |
| Concurrent commission releases | pending balance never overdrawn |
| One ledger key posted by 10 processes | 1 journal |
| Release racing a full refund | commission nets to zero |
| 12 people join a 5-person squad | exactly 5 members |
| One webhook delivered 8 times at once | applied once |
| 4 capture workers | each authorization captured exactly once |

### 6.3 Coverage (lines, pcov, full suite)

| Class | Lines |
|---|---|
| `Commerce\LedgerService` | 100% (206/206) |
| `Payments\StripePaymentGateway` | 100% (143/143) |
| `Commerce\AutoTagService` | 100% (40/40) |
| `Commerce\PriceGuard` | 100% (80/80) |
| `Payments\StripeWebhookHandler` | 100% (97/97) |
| `Feed\ForYouCandidates` | 100% (50/50) |
| `Payments\OrderPayments` | 99.7% (318/319) |
| `Commerce\ProductGraphService` | 99.0% |
| `Payments\CardPaymentService` | 98.5% |
| `Media\VisualEmbedding` | 98.5% |
| `Commerce\ScanAndTapService` | 97.8% |
| `Media\ImageGuard` | 97.6% |
| `Commerce\CheckoutService` | 96.9% |
| `Commerce\SquadPriceService` | 95.5% |
| `Http\Middleware\EnforceIdempotency` | 97.3% |
| `Commerce\Purchases` | 92.5% |

What the remaining lines are: the losing side of a unique-key race in `Purchases` (re-read the winner)
and conditional stock updates that can only fail under a race. Those paths run in the multi-process
suite, whose child processes pcov cannot see. `SafeImageFetcher`'s network fetch (37.5%) is not run
because the test environment has no outbound network; its address and URL rules are fully tested.

### 6.4 Mobile checks

The Expo app has no test runner (adding Jest would need new packages). The new client logic was checked
in a separate harness outside the project (Node + esbuild with stubs for native modules): 27 checks
of release behaviour and 5 of development behaviour, covering the URL parser, HTTPS-only API
origin, retry rules (same key and body on retry, no retry of unkeyed writes, Retry-After, timeouts,
caller abort), path traversal, token migration to a device-only keychain item, the vision parser
dropping unsafe links, expected-total pricing, the 3-D Secure follow-through, and that a release build
fabricates no balance. TypeScript strict: 0 errors. ESLint (eslint-config-expo 57): no new findings.

---

## 7. Mobile client hardening

| Before | Risk | Fix |
|---|---|---|
| `api()` had no time limit and no retries; `EXPO_PUBLIC_API_URL` fell back to `http://127.0.0.1:8000`. | Hanging taps; a release build sending the bearer token over cleartext or to a default host. | Per-attempt time limits (15 s reads, 35 s keyed writes and uploads). Retries with backoff and jitter only for GET/HEAD and keyed requests, on lost answers, 408/425/429 (Retry-After ≤ 10 s), 5xx and `REQUEST_IN_PROGRESS`, always with the same key and body. Release builds require an https API URL (strict parser) and otherwise refuse to send anything (`ApiConfigError`). Route ids can never contain `.`/`..` segments. |
| The vision client sent the user's bearer token and `EXPO_PUBLIC_AI_API_KEY` to the configured third-party endpoint. | Token theft by the vision provider; a model key readable by anyone with the app. | The token goes only to our own API origin; the client key is removed entirely (and never referenced, because Expo inlines every `process.env.EXPO_PUBLIC_*` it sees). Vision answers are untrusted: links must be https, images https, unsafe or malformed matches dropped one by one, 1 MB cap. **Rotate any key that was ever set in `EXPO_PUBLIC_AI_API_KEY`.** |
| Money calls without Idempotency-Key (gifts, blind boxes, cancel, card setup/confirm) or with `Date.now()/Math.random()` keys; a new key per tap. | Double orders after a lost answer; every hardened route would now answer 422. | `newIdempotencyKey()` (CSPRNG UUID v4) and `IntentKeys`: one key per intent, reused while the outcome is unknown, replaced after a definite answer (the API replays definite refusals for a key). |
| 3-D Secure handled only in 1-Tap; replays carry no secret; the app polled and waited for webhooks. | Checkout, squads, gifts and blind boxes left in `requires_action`; stuck "processing". | `completePayment()`: runs `next_action` in the Stripe SDK, fetches a fresh secret with `POST /orders/{id}/sync` when a replay has none, syncs right after authentication, then polls for a bounded ~25 s; a cancelled or failed authentication releases the hold. 1-Tap now decides by `payment_status` (the old `status` mapping showed declines as "cancelled"). Secrets are never kept in app state. |
| Card setup expected fields the API never sent (`setup_intent_client_secret`, `ephemeral_key_secret`). | Saving a card could not work. | API returns `{ id, client_secret, publishable_key }`; the app passes customer details to PaymentSheet only when both are present. |
| Checkout sent no expected total. | Charged a price the buyer did not see. | `expected_total_minor` computed like the server (flash + one coupon, capped, floor per unit); `PRICE_CHANGED` shows the new total and the next tap confirms it. |
| Token in SecureStore with the default accessibility. | Token restored onto another device from an iCloud/iTunes backup. | `WHEN_UNLOCKED_THIS_DEVICE_ONLY`, migrated once from the old item (delete then create, because an update keeps the old accessibility); malformed tokens are refused. |
| Scan uploads had no integrity hash. | Silent corruption or tampering in transit. | Upload carries `image_sha256` of the exact bytes (file on native, base64 on web); `IMAGE_HASH_MISMATCH` lets the user resend. EXIF/GPS were already stripped by re-encoding. |
| Share-token deep links accepted from any scheme and inside query strings; gift tokens sent unchecked. | Attribution injection, odd requests. | Share tokens only from https links or the app's scheme, first path segment, alphanumeric; gift links validated before any request; the address form mirrors the server's rules. |
| Merchant links opened with `Linking.openURL`. | `intent:`/custom-scheme launches from untrusted links. | `openExternalUrl()` opens https only, in the in-app browser. |
| The demo wallet (opening balance, top-ups, withdrawals, referral credits) and the drop-slot sheet settled money on the device and invented `pm_` references. | A store build showing balances and purchases that never happened. | `demoMoneyEnabled` (development builds, or `EXPO_PUBLIC_DEMO_MONEY=1`): otherwise the device never credits or debits a balance, the balance starts at zero, the demo sheet never opens (offers with a merchant link open the merchant), and no payment reference is invented. |
| `src/lib/go-back.ts` imported a type that expo-router 57 no longer exports. | TypeScript error. | Uses `ImperativeRouter`. |

---

## 8. Runbook

### 8.1 Install

1. Backend files and `composer install` (no new packages; `stripe/stripe-php ^22` is already required).
2. PostgreSQL 16 with **pgvector ≥ 0.8** (`CREATE EXTENSION vector` needs a superuser once). Check with
   `php artisan visual-search:doctor`.
3. `php artisan migrate`. `001200` builds indexes concurrently: on a large production database run it
   in a maintenance window or let it run (it takes no write lock). If it is interrupted, run it again.
4. Redis: `CACHE_STORE=redis` with phpredis.
5. Scheduler: a cron entry `* * * * * php artisan schedule:run` (locally `php artisan schedule:work`).
   It runs `payments:capture-due` and `payments:reconcile` every minute, `ledger:release-commissions`,
   `price-protection:sweep` and `gifts:refund-expired` hourly, `drops:rotate` every 10 minutes,
   `idempotency:prune` daily and `ledger:verify` daily at 03:17. **Without the scheduler, authorized
   payments are never captured.**
6. Queue worker for `IndexProductImage` (catalog sync indexes images off the request).
7. Stripe: `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` (the mode of the secret must match
   the events), optionally `STRIPE_API_VERSION` pinned to the webhook endpoint's version. Subscribe the
   endpoint `/api/v1/stripe/webhook` to `payment_intent.*`, `charge.refunded` and `charge.dispute.*`.
8. Optional limits (`config/commerce.php`): `COMMERCE_UNDO_SECONDS` (120), `COMMERCE_MAX_DISCOUNT_BPS`
   (5000), `COMMERCE_MAX_COMMISSION_BPS` (2000), `COMMERCE_MAX_ORDER_MINOR` (500000),
   `VISUAL_SEARCH_EF_SEARCH` (100), `VISUAL_SEARCH_MAX_SCAN_TUPLES` (20000).
9. Optional vision model for scans: `OPENAI_API_KEY` (and `OPENAI_VISION_MODEL`) or `ANTHROPIC_API_KEY`
   (and `ANTHROPIC_VISION_MODEL`); `VISION_TIMEOUT_SECONDS` (8) and `VISION_CONNECT_TIMEOUT_SECONDS` (3).
   Without a key, scans use local colors and the visual match. Index catalog photos with
   `php artisan catalog:index-images` (queued, SSRF-safe); `php artisan catalog:embed-scans` seeds the demo
   catalog photos and price variants (development).
10. Mobile: no new packages. Release builds need `EXPO_PUBLIC_API_URL=https://...`. Remove
   `EXPO_PUBLIC_AI_API_KEY` from every environment and rotate that key. Leave `EXPO_PUBLIC_DEMO_MONEY`
   unset for store builds.

### 8.2 Watch

- `payment_anomalies`: webhook data that did not match our records (amount, currency, mode, unknown
  intent). Should be empty; investigate every row.
- `stripe_events.outcome`: `anomaly` and unusual `ignored` counts.
- `ledger:verify` output (daily): balances against the sum of lines.
- `payment_authorizations` stuck in `pending` or `requires_action` for more than an hour (the reconciler
  should resolve them).
- `listing_flags` with `status = 'open'`: the moderation queue for copied photos.

### 8.3 Roll back

Each migration has a `down()`. Roll back `001200` first (indexes only), then `001100`, then `001000`.
`001000`'s down drops the new columns and tables, so take a backup first if real payments have run.

---

## 9. Known limits and follow-ups

- **Legacy rows.** Ledger line CHECKs and `products_image_url_https` were added `NOT VALID` and
  `001200` validates the ledger ones; if old rows violate them, validation is logged and skipped, and the
  constraint still applies to every new row. `orders_paid_means_captured` allows `purpose = 'legacy'`
  rows from before this pass.
- **Gift addresses** are stored in plain columns. Encrypt them (Laravel encrypted casts) and purge them
  after delivery.
- **Landed cost.** The shop card shows a landed total (shipping, VAT, duty) but checkout charges the
  merchandise total. Decide whether to charge or only display it, then align both.
- **Watch rewards on image posts** use the client's dwell time (images have no server duration);
  bounded by once per post and 20 per day.
- **Demo surfaces in release builds** (wallet top-up and withdrawal, drop slots) are inert. Wire them to
  the API or hide them before a store submission.
- **ESLint**: 66 findings existed before this pass (mostly `react-hooks/immutability` on Reanimated
  `.value` writes and `set-state-in-effect`); none were introduced, none were fixed here.
- **SafeImageFetcher**'s live network path was not exercised in the test environment (no outbound
  network); run one catalog sync against a real https image host in staging.
- **Vision provider calls** (OpenAI / Anthropic) were tested against faked HTTP responses only; run a few
  real scans in staging with a key set, and add the provider to the processor list (section 3.4).
- **Old embedding columns.** `products.embedding` and `structure_embedding` (visual_v1) are still written
  by `catalog:embed-scans` and your accuracy test, but search reads `product_embeddings` (visual_v2). They
  can be dropped in a later migration once nothing writes them.
