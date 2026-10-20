# Hardening pass: money paths, uploads, visual search at scale, tests (2026-10-14)

Written on top of the project as it was in `~/Desktop/ecommerce/social-commerce ცვლილება` on 2026-10-14,
including the vision work you added on the Mac while this pass was running (merged, see below). The full
write-up (threat model, every attack and fix, migrations and index definitions, benchmark, caching, test
suite, coverage, runbook) is [`docs/SECURITY_HARDENING.md`](docs/SECURITY_HARDENING.md).

| | New files | Changed files |
|---|---|---|
| Backend (Laravel) | 62 | 48 |
| Mobile (Expo) | 7 | 22 |
| Docs (`docs/SECURITY_HARDENING.md`, this file, `README.md`) | 1 | 2 |
| **Total** | **70** | **72** |

Nothing was deleted. Before any file was written, the project was archived to
`~/Desktop/ecommerce/social-commerce-backup-2026-10-14/before-hardening.tgz` (with its `.sha256`), and
`MANIFEST-before-hardening-delivery.sha256` in the same folder lists the checksum of every project file
as it was right before this delivery, including your latest edits.

Verified: 209 PHPUnit tests (1,459 assertions) on PostgreSQL 16 + pgvector 0.8.7 and Redis, 8 of them
multi-process race tests; migrate, roll back and re-apply of the whole chain; TypeScript strict and
ESLint on the mobile app (no new findings); 32 behaviour checks of the new mobile client code.

## Your edits made during this pass: kept and merged

| Your file | What happened |
|---|---|
| `backend/app/Services/Vision/VisionDescriber.php`, `LocalVisionDescriber.php`, `VisionCrossCheck.php`, `backend/app/Services/Media/ColorPalette.php`, `backend/database/seeders/ScanCatalogSeed.php`, `backend/tests/Feature/VisionAccuracyTest.php` | Unchanged. Your two accuracy tests pass on the merged code. |
| `backend/database/migrations/2026_10_14_001100_product_vision_attributes.php` | Unchanged, same name (it may already be applied on your database). The pgvector migration of this pass moved to `2026_10_14_001110_visual_search_pgvector.php` so the timestamps no longer collide. |
| `backend/app/Services/Commerce/ScanAndTapService.php` | Merged: your strict / semantic / canonical cross-check and the `detected`, `exact_match`, `fallback` fields are kept, on top of the hardened pipeline (ImageGuard first, HNSW candidates, one analysis per frame per minute, only buyable listings, bounded category matches). Analyzer name `vision_crosscheck_v2`. |
| `backend/app/Services/Vision/RemoteVisionDescriber.php` | Kept, with: the real image media type (PNG and WebP were sent as JPEG), 3 s / 8 s timeouts instead of 20 s, and the model's answer reduced to short plain text (a photo can carry prompt-injected text). |
| `backend/app/Services/Commerce/ScanCatalogSeed.php` | Kept, with: downloads through the SSRF-safe fetcher and ImageGuard instead of `file_get_contents`, each photo also indexed into `product_embeddings`, and one `canonical_key` per offer family so shared photos are not flagged as copies. |
| `backend/app/Providers/AppServiceProvider.php` | Merged: your `VisionDescriber` binding kept; the testing environment always uses the local describer (no provider calls from tests), and the timeouts come from `config/services.php`. |
| `backend/config/services.php` | Merged: your `openai` and `anthropic` blocks kept, next to the Stripe settings of this pass and a new `vision` timeout block. |
| `backend/routes/console.php` | Merged: your `catalog:embed-scans` command kept, next to the payment and ledger schedule. |
| `backend/tests/Feature/PlatformP0Test.php` | Merged: the analyzer assertion now expects `vision_crosscheck_v2`. |
| `mobile/src/api/contracts.ts` | Merged: your `detected`, `exact_match`, `fallback` and `landed` fields kept; `fallback` is typed `'semantic' \| 'canonical' \| null`. |
| `mobile/src/lib/i18n.ts` | Merged line by line: your new scanner strings kept. |
| `mobile/src/features/scanner/scan-results-sheet.tsx` | Unchanged (yours). |

Your Cursor rules (`.cursorrules`, `.cursor/rules/*`) were not touched. They currently tell Cursor that
commerce code is out of scope and that the platform takes no payments, which is the opposite of what
this pass hardens; update them if Cursor should work on the commerce code.

## Backend: new files

| File | What it is |
|---|---|
| `app/Http/Middleware/EnforceIdempotency.php` | Idempotency-Key on every money route: atomic claim, request fingerprint (body + file hashes), replay, 409 for a twin in flight, secrets never stored. |
| `app/Services/Commerce/Purchases.php`, `app/Services/Payments/OrderPayments.php`, `OrderEffects.php` | One orchestrator for checkout, 1-Tap, squads, gifts and blind boxes: order and stock in one transaction, Stripe authorization outside it with an idempotency key, forward-only application, capture after the undo window, reconcile, compensation, refunds, disputes; side effects once, at capture. |
| `app/Services/Payments/PaymentStep.php`, `PaymentIntentResult.php`, `AuthorizeRequest.php`, `PaymentOutcomeUnknown.php`, `PaymentGatewayUnavailable.php`, `PaymentRejected.php` | Payment results and the three failure classes (unknown outcome, provider unavailable, definite refusal). |
| `app/Services/Commerce/DiscountPolicy.php` | Flash or squad discount plus one coupon, capped (no more 80% stacks). |
| `app/Services/Media/ImageGuard.php`, `VisualSignature.php`, `VisualEmbedding.php` | Upload trust boundary (type, size, declared pixels, hash) and the visual_v2 signature (zero-mean luminance, edge structure, dHash). |
| `app/Services/Media/ImageFetcher.php`, `SafeImageFetcher.php`, `SafeImageFetcherRules.php`, `ImageFetchFailed.php`, `FakeImageFetcher.php` | SSRF-safe catalog image downloads (public https only, pinned address, no redirects, caps). |
| `app/Services/Commerce/CatalogImageIndexer.php`, `app/Jobs/IndexProductImage.php` | Indexes listing photos off the request; flags copied photos of other sellers' products. |
| `app/Services/Search/VectorStore.php` | pgvector HNSW queries with per-transaction `ef_search`, iterative scans and a scan bound; exact fallback without pgvector. |
| `app/Support/SingleFlightCache.php`, `app/Services/Feed/ForYouCandidates.php`, `app/Services/Commerce/ProductGraphService.php` | Redis caching: stale-while-revalidate with single flight, For You candidate pools, multi-seller offers and related products. |
| `app/Support/Currency.php`, `app/Support/Gtin.php`, `config/commerce.php` | Money formatting, GTIN check digits, server-side commerce limits. |
| `app/Console/Commands/*` (12) | `payments:capture-due`, `payments:reconcile`, `ledger:release-commissions`, `ledger:verify`, `price-protection:sweep`, `gifts:refund-expired`, `drops:rotate`, `idempotency:prune`, `catalog:index-images`, `visual-search:benchmark`, `visual-search:rebuild-index`, `visual-search:doctor`. |
| `database/migrations/2026_10_14_001000_harden_ledger_and_payments.php` | Ledger idempotency, per-currency balance, trigger-maintained balances; payment states and transitions; idempotency keys, anomalies, disputes; abuse guards. |
| `database/migrations/2026_10_14_001110_visual_search_pgvector.php` | `product_embeddings` and its HNSW indexes. |
| `database/migrations/2026_10_14_001200_scale_indexes_concurrently.php` | Indexes on large tables built CONCURRENTLY, then the ledger CHECKs validated. |
| `tests/Unit/Payments/StripePaymentGatewayTest.php` and 14 feature test files under `tests/Feature/{Cache,Commerce,Concurrency,Ledger,Media,Payments,Search}` | The test suite (inventory in `docs/SECURITY_HARDENING.md` section 6). |
| `tests/Support/BuildsCommerce.php`, `FakeStripeHttpClient.php`, `HostileImages.php`, `race_worker.php` | Test helpers: commerce fixtures, a fake Stripe HTTP client, hostile image generators, the race worker. |

## Backend: changed files

| File | Change |
|---|---|
| `app/Services/Commerce/LedgerService.php` | Idempotent postings, per-currency balances from `ledger_balances`, net-proportional reversals, cumulative price-protection keys. |
| `app/Services/Commerce/CheckoutService.php`, `ScanAndTapService.php`, `SquadPriceService.php`, `HabitScaleService.php`, `GrowthService.php` | Every purchase path takes real card payments through `Purchases`; `expected_total_minor` and `PRICE_CHANGED`; gifts and blind boxes reveal only after capture. |
| `app/Services/Payments/StripePaymentGateway.php`, `PaymentGateway.php`, `FakePaymentGateway.php`, `CardPaymentService.php`, `StripeWebhookHandler.php`, `SellerPayouts.php`, `StripeSellerPayouts.php`, `FakeSellerPayouts.php` | Exception classification (rate limits are not declines), timeouts, idempotency keys on every call; card setup verified by SetupIntent and Stripe customer; webhooks verified, mode-checked, amount-checked and forward-only. |
| `app/Services/Commerce/PriceGuard.php`, `RewardService.php`, `AutoTagService.php`, `ShareEarnService.php`, `DropHourService.php`, `SellerService.php`, `LandedCost.php` | Price protection only for held drops, capped and reversible; server-side watch rewards with caps; auto-tag margins; read-only drops; catalog validation. |
| `app/Services/Feed/FeedService.php`, `TasteService.php`, `app/Services/Profile/ProfileService.php` | Cached For You head; cache invalidation on taste changes; eager loading. |
| `app/Services/Media/ImageEmbedding.php`, `StructureEmbedding.php` | Marked deprecated (visual_v1), kept for the old columns. |
| `app/Http/Controllers/*` (7 files), `app/Exceptions/ApiException.php` | Validation (image hash, mime types, GTIN, https URLs, price bounds), new error codes, idempotency keys passed to services, order sync endpoint. |
| `routes/api.php`, `routes/console.php`, `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `config/services.php`, `phpunit.xml` | Idempotent routes, schedule, middleware alias, bindings, Stripe and vision settings, test suites. |
| `tests/Feature/CriticalFlowsTest.php`, `GrowthP2Test.php`, `PlatformP0Test.php`, `SchemaParityTest.php`, `tests/Support/insert_balanced_journal.php`, `insert_order_once.php` | Updated to the payment rules (a paid order now needs a real capture) and the new schema. |

## Mobile: new files

| File | What it is |
|---|---|
| `src/lib/idempotency.ts` | CSPRNG Idempotency-Keys and one key per purchase intent. |
| `src/lib/safe-url.ts` | Strict URL parser; https-only external links in the in-app browser. |
| `src/lib/sha256.ts` | SHA-256 of upload bytes (`image_sha256`). |
| `src/lib/demo-mode.ts` | Demo money only in development builds (or `EXPO_PUBLIC_DEMO_MONEY=1`). |
| `src/features/payments/complete-payment.ts` | 3-D Secure, sync and bounded polling for checkout, squads, gifts and blind boxes. |
| `src/features/payments/purchase-copy.ts`, `expected-total.ts` | Purchase outcome copy from error codes; the expected total priced like the server. |

## Mobile: changed files

| File | Change |
|---|---|
| `src/api/client.ts` | Time limits, safe retries with the same key, https-only API origin in release builds, no path traversal. |
| `src/api/social-commerce.ts`, `src/api/contracts.ts` | Idempotency-Key on every money call, `expected_total_minor`, `image_sha256`, order sync, offers and related products, read-only live drop; payment types from the API. |
| `src/features/one-tap/one-tap-engine.ts`, `src/features/payments/types.ts`, `stripe-bridge.native.ts` | Decisions by `payment_status`, a new key after definite refusals, fresh 3-D Secure secrets through sync, the card-setup contract fixed, secrets never kept in state. |
| `src/features/reels/infinite-feed-screen.tsx`, `app/play.tsx`, `app/gift/[token].tsx` | Buy, squad, gift and blind box with stable keys and 3-D Secure; `PRICE_CHANGED` confirmation; gift links and addresses validated. |
| `src/lib/auth-token.ts` | Token in a device-only keychain item, migrated once; malformed tokens refused. |
| `src/services/ai-vision.ts`, `src/services/keys.ts` | No bearer token to third-party origins, no client model key, untrusted vision answers filtered. |
| `src/features/scanner/prepare-scan-image.ts`, `visual-scanner-screen.tsx` | `image_sha256` of the exact upload; hash-mismatch handling. |
| `src/lib/deep-links.ts` | Share tokens only from https links or the app scheme. |
| `src/stores/wallet.ts`, `src/stores/checkout.ts`, `src/features/wallet/top-up.ts`, `src/features/checkout/settle-purchase.ts`, `src/features/social/referral.ts` | Demo money gated; no balance invented on the device in release builds; no fake payment references. |
| `src/lib/i18n.ts`, `src/lib/go-back.ts` | Purchase and scanner copy (merged with yours); a TypeScript error from the expo-router 57 upgrade fixed. |

## Upgrade

`composer install` (no new packages), PostgreSQL with pgvector ≥ 0.8, `php artisan migrate`,
`CACHE_STORE=redis`, a cron for `php artisan schedule:run` (without it authorized payments are never
captured), a queue worker, and the Stripe webhook events listed in `docs/SECURITY_HARDENING.md`
section 8. Remove `EXPO_PUBLIC_AI_API_KEY` from every environment and rotate that key.

# Changes in release 2: Reels feed, AI visual search, 1-Tap checkout

Release date 2026-10-12. Written on top of the project as it was in `~/Desktop/ecommerce/social-commerce`
on that day, including your own edits made after the previous deliveries (merged, see below).

| | New files | Changed files |
|---|---|---|
| Backend (Laravel) | 92 | 11 |
| Mobile (Expo) | 31 | 11 |
| Docs, Cursor rules, README | 4 | 3 |
| **Total** | **127** | **25** |

Nothing was deleted. The originals of every changed file are in
`~/Desktop/ecommerce/social-commerce-backup-2026-10-12/` under the same paths, with
`MANIFEST-before-release2.sha256` (checksums of the project before this release).

## Your edits: kept and merged

These edits were made on your Mac after the earlier deliveries. All of them are kept.

| Your file | What happened |
|---|---|
| `backend/database/migrations/2026_10_08_000000_add_drop_stream_and_product_embedding_ref.php` | Unchanged. It runs before the release 2 migrations; the whole chain migrates, rolls back and re-applies cleanly, and every test passes with it. |
| `backend/app/Models/Drop.php` (`stream_url`) | Unchanged. |
| `backend/app/Models/Product.php` | Merged: your `embedding_ref` note plus the release 2 catalog notes. |
| `.cursor/rules/10-database.mdc` | Unchanged. |
| `backend/api/` (TypeScript prototype) | Unchanged. The release 2 screens call the Laravel API. |
| Mobile Search home: `app/(tabs)/index.tsx`, `src/features/search/*`, `src/features/feed/feed-screen.tsx` | Unchanged. |
| `mobile/app/_layout.tsx`, `mobile/app/(tabs)/_layout.tsx`, `mobile/src/components/ui/tab-bar.tsx`, `mobile/src/lib/i18n.ts` | Merged line by line: your Search home, `StartOnSearch`, `GlobalSearch`, Search icon and label stay; Reels is the second tab; the comments sheet and the 1-Tap banner sit next to `GlobalSearch`. |

`products.embedding_ref` stays reserved for your external vector index. The built-in visual search
stores its vectors in `product_image_embeddings` and does not read or write `embedding_ref`.

## Backend: new files

### HTTP: routes, controllers, validation, JSON

| File | What it is |
|---|---|
| `backend/app/Exceptions/ApiException.php` | Every API refusal with a stable `code` (e.g. `OUT_OF_STOCK`, `PAYMENT_DECLINED`, `IDEMPOTENCY_KEY_REUSED`). |
| `backend/app/Http/Controllers/CheckoutController.php` | `POST /checkout/one-tap` (`processOneTap`): Idempotency-Key header, native or affiliate outcome. |
| `backend/app/Http/Controllers/Controller.php` | Base controller. |
| `backend/app/Http/Controllers/FeedCommentController.php` | Comments and one level of replies: list, create, delete. |
| `backend/app/Http/Controllers/FeedController.php` | Feed (For You / Following, keyset cursor), create post, like and unlike (idempotent), share link. |
| `backend/app/Http/Controllers/OrderController.php` | Order status polling after a tap, and undo inside the window. |
| `backend/app/Http/Controllers/PaymentMethodController.php` | Saved card: start a PaymentSheet setup, confirm it (verified with Stripe), show the default card. |
| `backend/app/Http/Controllers/StripeWebhookController.php` | Signature check, minimized storage, one row per event (`createOrFirst`), queued processing. |
| `backend/app/Http/Controllers/VisualSearchController.php` | `POST /visual-search`: multipart or base64 image in, ranked products out. |
| `backend/app/Http/Requests/CommentRequest.php` | Comment validation (body, parent). |
| `backend/app/Http/Requests/FeedIndexRequest.php` | Feed query validation (mode, cursor, limit). |
| `backend/app/Http/Requests/OneTapCheckoutRequest.php` | 1-Tap validation: product, size, quantity and the Idempotency-Key header only. |
| `backend/app/Http/Requests/SharePostRequest.php` | Share validation (channel). |
| `backend/app/Http/Requests/StorePostRequest.php` | Post upload validation (media, thumbnail, caption, tags, product, audience). |
| `backend/app/Http/Requests/VisualSearchRequest.php` | Scan validation (multipart file or base64, optional category). |
| `backend/app/Http/Resources/FeedCommentResource.php` | Comment JSON. |
| `backend/app/Http/Resources/FeedPostResource.php` | Post JSON: media URLs, counters, the viewer's own like flag, shoppable product. |
| `backend/app/Http/Resources/OneTapResource.php` | Tap outcome JSON: order, payment status, undo deadline, 3-D Secure secret or affiliate URL. |
| `backend/app/Http/Resources/OrderStatusResource.php` | Order status JSON for polling. |
| `backend/app/Http/Resources/ProductCardResource.php` | Product card JSON (price, stock, sizes, and how it is bought: 1-Tap or affiliate). |
| `backend/app/Http/Resources/UserSummaryResource.php` | Public author summary (no private fields). |
| `backend/app/Http/Resources/VisualSearchResultResource.php` | Scan result JSON: detection box, category, ranked matches with scores. |
| `backend/bootstrap/app.php` | Laravel 13 bootstrap: API routing, the `catalog:index-images` command, JSON errors for `/api`. |
| `backend/routes/api.php` | All `/api/v1` routes: feed, posts, likes, comments, shares, visual search, 1-Tap, orders, saved cards (17, behind Sanctum and rate limits) and the Stripe webhook. |
| `backend/routes/console.php` | Schedule: capture after the undo window (every minute), reconciler (every 5 minutes), scan purge (daily). |

### Feed and media

| File | What it is |
|---|---|
| `backend/app/Services/Feed/CommentService.php` | Comment rules with friendly errors; the database stays the guard. |
| `backend/app/Services/Feed/CreateMediaPost.php` | Stores an image or video post (processed media, poster, social opt-in check). |
| `backend/app/Services/Feed/FeedCursor.php` | Opaque keyset cursor (exact `rank_score` text plus id). |
| `backend/app/Services/Feed/FeedPage.php` | A page of posts plus the next cursor. |
| `backend/app/Services/Feed/FeedService.php` | Feed queries with visibility and block filters, keyset pages. |
| `backend/app/Services/Media/Contracts/VideoProcessor.php` | Video processing contract. |
| `backend/app/Services/Media/FfmpegVideoProcessor.php` | ffprobe validation, metadata stripped, `+faststart`, poster frame. |
| `backend/app/Services/Media/PassthroughVideoProcessor.php` | Local development without ffmpeg only. |
| `backend/app/Services/Media/ProcessedVideo.php` | Processed video: path, duration, dimensions, poster. |

### Visual search

| File | What it is |
|---|---|
| `backend/app/Services/VisualSearch/AnalyzerFailed.php` | Analyzer outage (mapped to `VISUAL_SEARCH_UNAVAILABLE`). |
| `backend/app/Services/VisualSearch/Contracts/ImageAnalyzer.php` | Analyzer contract (embedding, views, labels, category, box). |
| `backend/app/Services/VisualSearch/DecodedImage.php` | The upload trust boundary: JPEG/PNG/WebP only, size caps, EXIF orientation, sha256. |
| `backend/app/Services/VisualSearch/DeterministicImageAnalyzer.php` | Built-in analyzer for development and tests (multi-view 512-d, no external service). |
| `backend/app/Services/VisualSearch/HttpImageAnalyzer.php` | Production analyzer: your CLIP/SigLIP-class model service over HTTP. |
| `backend/app/Services/VisualSearch/ImageAnalysis.php` | Analysis value object with `toArray()` / `fromArray()` for caching. |
| `backend/app/Services/VisualSearch/MatchedProduct.php` | One ranked match with its scores. |
| `backend/app/Services/VisualSearch/ProductMatcher.php` | Hybrid ranking: pgvector HNSW candidates, then 0.75 visual + 0.15 text + 0.10 category. |
| `backend/app/Services/VisualSearch/VisualSearchResult.php` | Scan result value object. |
| `backend/app/Services/VisualSearch/VisualSearchService.php` | The pipeline: decode, analyze (cached as an array), match, log the scan. |

### 1-Tap checkout and payments

| File | What it is |
|---|---|
| `backend/app/Services/Checkout/AffiliateRedirector.php` | Affiliate products: tracked click with SubID and the redirect URL. |
| `backend/app/Services/Checkout/OneTapCheckoutService.php` | 1-Tap: key binding and replay, atomic stock reservation, order rows, PaymentIntent (create, store, confirm), undo. |
| `backend/app/Services/Checkout/OneTapOutcome.php` | What the app does next (3-D Secure, undo window, done, or redirect). |
| `backend/app/Services/Checkout/OneTapRequest.php` | Tap input value object. |
| `backend/app/Services/Checkout/PaymentLifecycle.php` | The only writer of `payment_status` (row lock + legal moves); used by tap, undo, capture, reconciler and webhooks. |
| `backend/app/Services/Payments/Contracts/PaymentGateway.php` | Payment provider contract (PaymentIntents, SetupIntents, customers). |
| `backend/app/Services/Payments/PaymentGatewayUnavailable.php` | Provider outage (mapped to `PAYMENT_PROVIDER_UNAVAILABLE`). |
| `backend/app/Services/Payments/PaymentIntentRequest.php` | PaymentIntent input value object. |
| `backend/app/Services/Payments/PaymentResult.php` | PaymentIntent result value object. |
| `backend/app/Services/Payments/SavedPaymentMethods.php` | Saved card: setup, verified confirm, newest setup wins (compare-and-set), forget. |
| `backend/app/Services/Payments/StripeEventHandler.php` | Webhook events to state changes (payment, refund, Connect account, saved card). |
| `backend/app/Services/Payments/StripePayload.php` | Allowlist that minimizes stored webhook payloads. |
| `backend/app/Services/Payments/StripePaymentGateway.php` | Stripe implementation (Connect `on_behalf_of`, manual capture, idempotency keys, pinned API version). |

### Privacy and support

| File | What it is |
|---|---|
| `backend/app/Services/Privacy/EraseUserMedia.php` | Account erasure step 1: delete the user's media and scan files before `anonymize_user()`. |
| `backend/app/Support/MediaUrl.php` | URLs from storage keys (the CDN when configured, otherwise the disk URL). |
| `backend/app/Support/MoneyPayload.php` | `{ amount_minor, currency }` JSON for money. |
| `backend/app/Support/SafeImageDownloader.php` | Product image downloads: https only, public IPs only, no redirects, size cap. |

### Models, enums, jobs, commands

| File | What it is |
|---|---|
| `backend/app/Console/Commands/IndexCatalogImages.php` | `php artisan catalog:index-images`: indexes the catalog for visual search. |
| `backend/app/Enums/MediaType.php` | `image` / `video`. |
| `backend/app/Enums/PaymentStatus.php` | Card payment states and the legal moves between them. |
| `backend/app/Enums/ScanStatus.php` | Scan outcome. |
| `backend/app/Jobs/CaptureAuthorizedPayments.php` | Captures authorizations whose undo window has passed. |
| `backend/app/Jobs/IndexProductImages.php` | Downloads and embeds a product's images. |
| `backend/app/Jobs/ProcessStripeWebhook.php` | Processes a stored webhook once (queue `webhooks`). |
| `backend/app/Jobs/PurgeScanImages.php` | Deletes scan frames past `retention_days`. |
| `backend/app/Jobs/ReconcileOneTapPayments.php` | Settles orders left open by crashes, timeouts, lost webhooks or abandoned 3-D Secure. |
| `backend/app/Models/Casts/VectorCast.php` | pgvector column cast. |
| `backend/app/Models/FeedPostComment.php` | Comment model (one level of replies). |
| `backend/app/Models/ProductImageEmbedding.php` | One embedding per product image, model and view. |
| `backend/app/Models/VisualScan.php` | Scan log (private, purged after the retention period). |

### Configuration and migrations

| File | What it is |
|---|---|
| `backend/config/commerce.php` | Stripe keys, webhook tolerance and pinned API version; 1-Tap undo window and amount cap; affiliate cookie days. |
| `backend/config/feed.php` | Feed settings: media disk and CDN, share links, page sizes, For You cache, video and image limits, comments. |
| `backend/config/visual_search.php` | Analyzer, thresholds, weights, HNSW search width, cache and retention. |
| `backend/database/migrations/2026_10_12_000100_extend_users_for_social_discovery.php` | Users: bio, social score, trend level, settings, saved card summary. |
| `backend/database/migrations/2026_10_12_000200_extend_catalog_for_universal_commerce.php` | pgvector + pg_trgm, marketplace vendors, product stock and taxonomy, `product_image_embeddings` (HNSW). |
| `backend/database/migrations/2026_10_12_000300_extend_feed_for_media_and_engagement.php` | Media posts, counters and `rank_score` triggers, likes, comments. |
| `backend/database/migrations/2026_10_12_000400_create_visual_scans.php` | `visual_scans`. |
| `backend/database/migrations/2026_10_12_000500_add_one_tap_checkout.php` | Order idempotency key, payment state on vendor orders, ledger link, `marketplace_fee`. |
| `backend/database/migrations/2026_10_12_000600_extend_user_erasure.php` | `anonymize_user()` covers the new personal data and waits for in-flight payments. |

### Tests

| File | What it is |
|---|---|
| `backend/tests/Feature/FeedTest.php` | Feed: visibility, blocks, likes, comments, shares, pagination. |
| `backend/tests/Feature/OneTapCheckoutTest.php` | 1-Tap: authorize, undo, capture, replay, key reuse, 3-D Secure, decline, last unit, outage, reconciler. |
| `backend/tests/Feature/PaymentMethodTest.php` | Saved cards: verified confirm, foreign setups refused, newest setup wins. |
| `backend/tests/Feature/SocialDiscoverySchemaTest.php` | The same rules proven at the database level. |
| `backend/tests/Feature/StripeWebhookTest.php` | Webhooks: signatures, duplicates, out-of-order events, refunds, minimized storage. |
| `backend/tests/Feature/VideoPostTest.php` | Real ffmpeg processing of video posts. |
| `backend/tests/Feature/VisualSearchTest.php` | Visual search: screenshot match, base64, category filter, no false matches, outage, cache, purge. |
| `backend/tests/Support/BuildsCommerce.php` | Fixture builders (real PostgreSQL rows). |
| `backend/tests/Support/FakePaymentGateway.php` | In-memory Stripe stand-in for tests. |

## Backend: changed files

| File | What changed |
|---|---|
| `backend/app/Enums/FeedPostKind.php` | `media`. |
| `backend/app/Enums/MerchantIntegration.php` | `marketplace`. |
| `backend/app/Enums/TransactionType.php` | `marketplace_fee`. |
| `backend/app/Models/FeedPost.php` | Media posts: tags, media fields, counters, product, likers, comments. |
| `backend/app/Models/LedgerTransaction.php` | `merchantOrder()` for marketplace fees. |
| `backend/app/Models/Merchant.php` | Connect fields and `acceptsOneTap()`. |
| `backend/app/Models/MerchantOrder.php` | Payment status, application fee, capture time. |
| `backend/app/Models/Product.php` | Catalog notes (taxonomy, generated columns, stock). **Merged with your `embedding_ref` note.** |
| `backend/app/Models/User.php` | Profile fields, saved card summary, likes, comments and scans relations, `hasOneTapPaymentMethod()`. |
| `backend/app/Providers/AppServiceProvider.php` | Bindings for the analyzer, matcher, video processor, payment gateway; rate limits. |
| `backend/app/Services/Ledger/LedgerService.php` | Accepts `merchant_order_id` on a posting. |

## Mobile: new files

| File | What it is |
|---|---|
| `mobile/app/(tabs)/reels.tsx` | Reels tab (`InfiniteFeedScreen`). |
| `mobile/app/+native-intent.tsx` | Deep links that are not screens (3-D Secure return, share links). |
| `mobile/app/scan.tsx` | Scanner route (`VisualScannerScreen`). |
| `mobile/src/api/contracts.ts` | TypeScript types of the API responses. |
| `mobile/src/api/social-commerce.ts` | API calls for feed, comments, visual search, 1-Tap, orders and saved cards. |
| `mobile/src/features/one-tap/one-tap-banner.tsx` | Status banner with the undo countdown and retry actions. |
| `mobile/src/features/one-tap/one-tap-buy-card.tsx` | Glowing 1-Tap Buy card with haptics, size chips and the saved card. |
| `mobile/src/features/one-tap/one-tap-engine.ts` | The only caller of `/checkout/one-tap`: keys, 3-D Secure, polling, undo, save card then buy. |
| `mobile/src/features/one-tap/one-tap-store.ts` | Purchase state per tap. |
| `mobile/src/features/one-tap/use-payment-method.ts` | The saved card shown on every buy card. |
| `mobile/src/features/payments/publishable-key.ts` | Publishable key served by the API, with a build-time fallback. |
| `mobile/src/features/payments/stripe-bridge.native.ts` | Stripe SDK on iOS/Android: PaymentSheet setup, 3-D Secure, redirects. |
| `mobile/src/features/payments/stripe-bridge.ts` | Web stub with the same type (no card payments on the web build). |
| `mobile/src/features/payments/types.ts` | `StripeBridge` type shared by both bridges. |
| `mobile/src/features/reels/action-rail.tsx` | Author, like, comments, share and mute buttons. |
| `mobile/src/features/reels/comments-sheet.tsx` | Comments sheet: replies, composer, keyboard lift, drag to dismiss. |
| `mobile/src/features/reels/feed-cache.ts` | Patches cached posts and products (likes, counters, stock). |
| `mobile/src/features/reels/infinite-feed-screen.tsx` | `InfiniteFeedScreen`: vertical paging, one playing video, prefetch, For You / Following. |
| `mobile/src/features/reels/reel-item.tsx` | One page: video or image, tap to pause, double-tap like with burst, progress, caption, buy card. |
| `mobile/src/features/reels/reel-playback-store.ts` | Feed-wide mute switch. |
| `mobile/src/features/reels/reel-video.tsx` | expo-video player with caching; plays only on screen, focused and in the foreground. |
| `mobile/src/features/reels/tap-classifier.ts` | Single tap versus double tap. |
| `mobile/src/features/reels/use-reels.ts` | Queries and mutations: feed, likes (optimistic), share, comments. |
| `mobile/src/features/scanner/prepare-scan-image.ts` | Resizes to 1280 px and re-encodes before upload (drops EXIF/GPS). |
| `mobile/src/features/scanner/scan-results-sheet.tsx` | Ranked matches with 1-Tap Buy. |
| `mobile/src/features/scanner/scan-reticle.tsx` | Grid, sweeping scan line and corners. |
| `mobile/src/features/scanner/visual-scanner-screen.tsx` | `VisualScannerScreen`: camera, photo fallback, analysis stages, detection box, results. |
| `mobile/src/lib/relative-time.ts` | Compact counts and relative times. |
| `mobile/src/lib/use-app-active.ts` | Foreground state. |
| `mobile/src/lib/use-keyboard-height.ts` | Keyboard height for the comments composer. |
| `mobile/src/stores/comments-sheet.ts` | Which post's comments are open. |

## Mobile: changed files

| File | What changed |
|---|---|
| `mobile/.env.example` | API URL, Stripe values, development token. |
| `mobile/app.json` | Camera and Stripe plugins, camera permission text, no microphone. |
| `mobile/app/(tabs)/_layout.tsx` | Reels tab after Search. **Merged with your `StartOnSearch` and initial route.** |
| `mobile/app/_layout.tsx` | Comments sheet and 1-Tap banner on top of every screen, `/scan` route, 3-D Secure redirects, Android back. **Merged with your `GlobalSearch`.** |
| `mobile/package-lock.json` | Lock for the five packages. |
| `mobile/package.json` | `expo-camera`, `expo-video`, `expo-image-manipulator`, `expo-crypto`, `@stripe/stripe-react-native` (SDK 57 versions). |
| `mobile/src/api/client.ts` | Typed errors (field errors, retry-after), network errors, 204, JSON and query helpers. |
| `mobile/src/api/keys.ts` | Query keys for reels, comments, orders and the saved card. |
| `mobile/src/components/ui/tab-bar.tsx` | Reels icon. **Merged with your Search icon.** |
| `mobile/src/lib/auth-token.ts` | Never throws; the development token exists only in development builds. |
| `mobile/src/lib/i18n.ts` | Strings for Reels, comments, 1-Tap and the scanner (your `Search` label kept). |

## Docs, Cursor rules, README

| File | | Notes |
|---|---|---|
| `.cursor/rules/70-social-discovery.mdc` | new | Cursor rules for the feed, visual search, 1-Tap and their mobile code. |
| `CHANGES.md` | new | This file. |
| `docs/ARCHITECTURE.md` | new | Design, specification-to-code mapping, data model, API, flows, mobile, visual search, scaling, compliance, configuration, gaps. |
| `docs/CURSOR_PLAYBOOK.md` | new | Step-by-step integration with Cursor, with a prompt and a check per module. |
| `.cursor/rules/00-core.mdc` | changed | Release 2 in the product context, stack and layout. |
| `README.md` | changed | Release 2 summary on top; release 1 below. |
| `docs/erd.md` | changed | Release 2 tables. |

## Next steps on your Mac

1. Backend: follow `docs/CURSOR_PLAYBOOK.md` from step 1 (Laravel 13 app, PostgreSQL with pgvector and
   pg_trgm, ffmpeg, `composer require stripe/stripe-php:^21.3`, `php artisan migrate`,
   `php artisan catalog:index-images`, the scheduler and queue workers). Settings are in
   `docs/ARCHITECTURE.md` section 11. Reels, the scanner and 1-Tap need this API running.
2. Mobile: in `mobile/`, run `npm install` (package.json and the lock already list the five packages).
   Add the new keys from `.env.example` to your `mobile/.env` (`EXPO_PUBLIC_API_URL` is your Mac's LAN
   address for a phone; until sign-in exists, a development token). Then `npx expo start`: Expo Go runs
   Reels, the scanner and the card sheet; Apple Pay needs a development build (playbook step 8).
3. Before a public launch: sign-in, shipping and tax in the 1-Tap total, and the other items in
   `docs/ARCHITECTURE.md` section 13.

## How it was checked

- 64 PHPUnit tests (2,498 assertions) on PostgreSQL 16 + pgvector, with your migration and model edits in
  place.
- Release 1 SQL invariant checks and concurrency checks on the fully migrated schema; all migrations
  (yours included) roll back and re-apply.
- Mobile: TypeScript strict; ESLint reports nothing in the new files (the 61 earlier findings are
  unchanged); web export.
- End to end: the web build against the real API and database (test payment provider), 25 of 25 checks:
  autoplay, tap to pause, likes, comments, shares, size choice, 1-Tap with undo (stock reserved and
  returned), affiliate redirect, Following, camera scanner, photo search with the right product first,
  buying from the results, and the no-match path.
