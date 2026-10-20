# Cursor playbook: integrating release 2 module by module

How to bring the social discovery and 1-Tap release into your repository with Cursor, one module at a
time, with a check after each step so nothing is missing. Read `docs/ARCHITECTURE.md` once first; Cursor
will read it too when you mention it. If the release files are already in your repository (copied in, or
written there for you), skip the "Copy from the package" parts and run each step's prompt and check.

## 0. How Cursor will work with this repository

- **Rules** live in `.cursor/rules/*.mdc`. `00-core.mdc` always applies (money, ledger, privacy rules);
  the others load when Cursor touches matching files (`10-database`, `20-money-ledger`,
  `30-security-privacy`, `40-laravel-backend`, `50-mobile-expo`, `60-commerce-flows`, and the new
  `70-social-discovery` for the feed, visual search and 1-Tap code). Keep them in the repository root.
- **Agent mode** for each step below. Start every step in a new chat so the context is only that module.
- **Mention files with `@`** (`@docs/ARCHITECTURE.md`, `@backend/app/Services/Checkout`). Cursor reads
  exactly what you mention plus the matching rules.
- **Review every diff** before accepting. The rules tell Cursor never to edit `database/sql/*.sql`, never
  to weaken a constraint, and never to touch `ios/` or `android/`; reject any diff that does.
- **Run the checks yourself** (the terminal commands in each step) and paste failures back to Cursor.

Target layout (monorepo):

```
your-repo/
  .cursor/rules/          from this package
  docs/                   from this package (ARCHITECTURE.md, CURSOR_PLAYBOOK.md, erd.md)
  backend/                a Laravel 13 app with this package's backend/ merged in
  mobile/                 your Expo app with this package's mobile/ changes
```

## 1. Prerequisites (install once)

| What | Version | Why |
|---|---|---|
| PHP | 8.3+ with `pdo_pgsql`, `gd` (JPEG, PNG, WebP), `intl`, `mbstring`, `fileinfo` | GD decodes scans and product images; pgsql for the schema |
| Composer | 2.x | |
| PostgreSQL | 15+ (16 tested) with the `vector` (pgvector) and `pg_trgm` extensions | Embeddings index (HNSW) and catalog search |
| ffmpeg + ffprobe | any recent | Video posts (metadata strip, faststart, poster). Without it set `FEED_VIDEO_PROCESSOR=passthrough` for local development only |
| Redis | 7+ | Cache, queues, rate limits in production |
| Node.js | 22 LTS | Expo SDK 57 |
| Stripe account | test mode | In a Stripe-supported country (not Georgia); Connect enabled for vendors |
| Xcode / Android Studio or EAS | | Development builds for Apple Pay; Expo Go covers the rest |

pgvector: `brew install pgvector` (macOS), `apt install postgresql-16-pgvector` (Debian/Ubuntu), or a
managed Postgres with pgvector (Supabase, Neon, RDS, Cloud SQL).

## 2. Backend foundation

**Do yourself:**

```bash
composer create-project laravel/laravel backend "^13.0"
cd backend
composer require laravel/sanctum:^4.3 stripe/stripe-php:^21.3
php artisan install:api            # Sanctum + routes/api.php
```

Apply release 1's README steps "Install into a Laravel 13 app" (UUID users, Sanctum `uuidMorphs`,
PostgreSQL in `phpunit.xml`) if you have not already.

**Copy from the package** into `backend/` (overwrite when asked):
`app/`, `config/commerce.php`, `config/feed.php`, `config/visual_search.php`, `config/rewards.php`,
`database/`, `routes/api.php`, `routes/console.php`, `bootstrap/app.php`, `tests/`.

**Prompt (Agent mode):**

> Read @docs/ARCHITECTURE.md sections 2–5 and @backend/bootstrap/app.php @backend/app/Providers/AppServiceProvider.php.
> I copied release 2 into this Laravel 13 app. Check that: bootstrap/app.php registers routes/api.php with the
> /api prefix and renders JSON for api/*; AppServiceProvider is listed in bootstrap/providers.php; every class
> referenced in AppServiceProvider exists; composer.json has laravel/sanctum and stripe/stripe-php. List anything
> missing and fix only that. Do not change any migration or SQL file.

**Check:**

```bash
php artisan migrate                      # 2026_10_05 core schema + six 2026_10_12 migrations
php artisan route:list --path=api/v1     # 18 routes
php artisan test                         # or vendor/bin/phpunit: 64 tests
psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/sql/tests/invariants_test.sql
```

If `CREATE EXTENSION vector` fails, pgvector is not installed on the database server.

## 3. Feed module

Files: `app/Services/Feed/*`, `app/Services/Media/*`, `FeedController`, `FeedCommentController`,
`StorePostRequest`, `FeedIndexRequest`, `CommentRequest`, `SharePostRequest`, `FeedPostResource`,
`FeedCommentResource`, `UserSummaryResource`, `config/feed.php`, migration `000300`.

**Prompt:**

> Using @docs/ARCHITECTURE.md (sections 4, 5, 6.1, 6.2) and @backend/app/Services/Feed, verify the feed module is
> wired: the FEED_* settings exist in .env.example with comments, the public disk is linked (php artisan storage:link)
> or FEED_MEDIA_DISK points to S3 with FEED_CDN_URL, and ffmpeg/ffprobe paths are configurable. Add missing
> .env.example entries only.

**Check:** `vendor/bin/phpunit --filter 'FeedTest|VideoPostTest|SocialDiscoverySchemaTest'`
(VideoPostTest skips itself when ffmpeg is missing).

## 4. Visual search module

Files: `app/Services/VisualSearch/*`, `app/Support/SafeImageDownloader.php`, `VisualSearchController`,
`VisualSearchRequest`, `VisualSearchResultResource`, `IndexProductImages`, `PurgeScanImages`,
`IndexCatalogImages` command, `config/visual_search.php`, migrations `000200` and `000400`.

**Do yourself:** index the catalog once and whenever product images change:

```bash
php artisan catalog:index-images            # queued (queue "indexing")
php artisan catalog:index-images --sync     # inline, for small catalogs or local development
```

**Prompt (only when you connect a real model):**

> Read @backend/app/Services/VisualSearch/HttpImageAnalyzer.php and @docs/ARCHITECTURE.md section 8. Our model
> service is at <URL> and returns <describe its JSON>. Adapt HttpImageAnalyzer's response mapping to it without
> changing the ImageAnalyzer contract, keep embeddings L2-normalized at 512 dimensions, and add a feature test with
> Http::fake. Then tell me which env values to set and to re-run catalog:index-images.

**Check:** `vendor/bin/phpunit --filter VisualSearchTest`.

## 5. Payments and 1-Tap module

Files: `app/Services/Payments/*`, `app/Services/Checkout/*`, `CheckoutController`, `OrderController`,
`PaymentMethodController`, `StripeWebhookController`, the `CaptureAuthorizedPayments`,
`ReconcileOneTapPayments` and `ProcessStripeWebhook` jobs, `routes/console.php`, `config/commerce.php`,
migrations `000500` and `000600`.

**Do yourself:**

1. `.env`: `STRIPE_SECRET=sk_test_…`, `STRIPE_KEY=pk_test_…`, `STRIPE_WEBHOOK_SECRET=whsec_…`,
   `ONE_TAP_UNDO_WINDOW=120`.
2. Stripe Dashboard → Developers → Webhooks: endpoint `https://<api>/api/v1/webhooks/stripe` with events
   `payment_intent.succeeded`, `payment_intent.amount_capturable_updated`,
   `payment_intent.payment_failed`, `payment_intent.canceled`, `payment_intent.requires_action`,
   `charge.refunded`, `setup_intent.succeeded`, `payment_method.detached`, and on the Connect endpoint
   `account.updated`. Locally: `stripe listen --forward-to localhost:8000/api/v1/webhooks/stripe`.
3. Run a scheduler and workers: `php artisan schedule:work` and
   `php artisan queue:work --queue=payments,webhooks,indexing,default` (Horizon in production).
4. Vendors: onboard them to Stripe Connect, then set `integration_type = 'marketplace'`,
   `stripe_connect_account_id`, `connect_charges_enabled = true`, `marketplace_fee_bps` on their
   `merchants` row (the `account.updated` webhook keeps `connect_charges_enabled` current).

**Prompt (production hardening, when you are ready):**

> Read @docs/ARCHITECTURE.md sections 6.4–6.6 and 13 and @backend/app/Services/Checkout/PaymentLifecycle.php.
> Add the ledger reversal of the platform fee when Stripe refunds an application fee (application_fee.refunded),
> through LedgerService::post with a deterministic idempotency key, a new TransactionType only if the database
> CHECK allows it (otherwise a new migration that extends it), and a feature test proving a replayed webhook posts once.

**Check:** `vendor/bin/phpunit --filter 'OneTapCheckoutTest|PaymentMethodTest|StripeWebhookTest'`.

## 6. Mobile: dependencies and configuration

**Do yourself** in `mobile/` (versions pinned to Expo SDK 57). If `mobile/package.json` already lists these
packages (it does in this repository), `npm install` is enough; in another Expo app:

```bash
npx expo install expo-camera expo-video expo-image-manipulator expo-crypto @stripe/stripe-react-native
```

Then copy from the package (the full list with reasons is in `CHANGES.md`):
- `app.json`: the `expo-camera` and `@stripe/stripe-react-native` plugin entries and the
  `expo-image-picker` options (camera text, `microphonePermission: false`).
- `.env.example` → `.env`: `EXPO_PUBLIC_API_URL` (your computer's LAN IP for a phone), Stripe values,
  and for development only `EXPO_PUBLIC_DEV_AUTH_TOKEN` (create one with
  `php artisan tinker` → `User::first()->createToken('dev')->plainTextToken`).

**Prompt:**

> Compare @mobile/package.json and @mobile/app.json with @CHANGES.md (mobile section). List any dependency or
> plugin entry that is missing or has a version that is not the Expo SDK 57 one. Fix with `npx expo install`
> commands, not by editing versions by hand. Do not create ios/ or android/.

**Check:** `npx expo-doctor` and `npx tsc --noEmit`.

## 7. Mobile: modules in dependency order

Copy each group, then run `npx tsc --noEmit` before the next. Each group compiles on its own once the
earlier ones are in.

| Order | Copy | Depends on |
|---|---|---|
| 1 | `src/api/client.ts`, `src/api/contracts.ts`, `src/api/social-commerce.ts`, `src/api/keys.ts`, `src/lib/auth-token.ts` | – |
| 2 | `src/lib/relative-time.ts`, `src/lib/use-app-active.ts`, `src/lib/use-keyboard-height.ts`, `src/lib/i18n.ts` (merge the new keys) | 1 |
| 3 | `src/features/payments/*` (`stripe-bridge.ts`, `stripe-bridge.native.ts`, `types.ts`, `publishable-key.ts`) | 1 |
| 4 | `src/features/one-tap/*` | 1–3, `src/features/reels/feed-cache.ts` |
| 5 | `src/features/reels/*`, `src/stores/comments-sheet.ts` | 1–4 |
| 6 | `src/features/scanner/*` | 1, 2, 4 |
| 7 | `app/(tabs)/reels.tsx`, `app/scan.tsx`, `app/+native-intent.tsx`, the changes to `app/_layout.tsx`, `app/(tabs)/_layout.tsx`, `src/components/ui/tab-bar.tsx` | all |

**Prompt (after step 7):**

> Read @mobile/app/_layout.tsx and @docs/ARCHITECTURE.md section 7. Confirm that CommentsSheet and OneTapBanner are
> rendered after the Stack (so they sit above every screen), that the Linking listener passes URLs to
> stripeBridge.handleRedirect, that the scan route is registered, and that the comments sheet closes on navigation
> and on Android back. Fix only what is missing.

**Check:**

```bash
npx tsc --noEmit
npx expo lint
npx expo start          # press i / a; open the Reels tab
```

On a device: Reels plays, double tap likes, the comments sheet opens, the scan button opens the camera,
Photos finds a product you indexed, and 1-Tap Buy shows "Ordered · Undo" with a test card
(`4242 4242 4242 4242`; for a 3-D Secure challenge on every payment use `4000 0027 6000 3184`).

## 8. Development build (Apple Pay)

Apple Pay needs an Apple merchant id and a development build:

1. Apple Developer → Identifiers → Merchant IDs → create `merchant.<your.bundle.id>`; upload Stripe's
   CSR (Stripe Dashboard → Settings → Apple Pay).
2. `app.json` → the Stripe plugin: `{ "merchantIdentifier": "merchant.<your.bundle.id>", "enableGooglePay": true }`;
   `.env`: `EXPO_PUBLIC_STRIPE_MERCHANT_IDENTIFIER` with the same value.
3. `npx eas-cli@latest build --profile development --platform ios` (or `npx expo run:ios`).

## 9. Asking Cursor for the next features

Start each with `@docs/ARCHITECTURE.md` and the module folder. Prompts that respect the architecture:

- **Sign-in:** "Add email one-time-code sign-in that issues Sanctum tokens: POST /auth/code, POST /auth/verify,
  rate limited, codes hashed with an expiry, tokens stored by the app only in expo-secure-store via writeAuthToken.
  Feature tests for expiry, reuse and rate limits."
- **Shipping and tax:** "Before charging in OneTapCheckoutService, compute the vendor's shipping rate for the
  default address and tax with Stripe Tax; store both on the merchant order; include them in total and
  application fee rules; show the all-in total on OneTapBuyCard before the tap. Keep the amount limit on the total."
- **HLS:** "Move video processing to a queued job: create posts in a processing state, produce HLS renditions,
  set media ready, and let the app show a placeholder until ready. Keep metadata stripping."
- **Viral counters:** "Buffer likes in Redis and flush to feed_posts every 5 seconds without double counting,
  keeping feed_post_likes as the source of truth."

## 10. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `500` on the second scan of the same image | A release older than this one cached analysis objects; this release caches arrays. Clear the cache once: `php artisan cache:clear`. |
| `SQLSTATE … type "vector" does not exist` | pgvector missing on the database server. |
| Reels say "Sign in to see the feed" | No token: set `EXPO_PUBLIC_DEV_AUTH_TOKEN` in development, or sign in. |
| Phone cannot reach the API | Use `http://<LAN IP>:8000` and `php artisan serve --host 0.0.0.0`. |
| `PAYMENT_METHOD_REQUIRED` loops | Webhook not reaching the API and the app on the web (no PaymentSheet). On a phone, saving a card confirms through `/payment-methods/confirm` immediately. |
| Videos show "could not be played" in Chrome on Linux | Chromium builds without H.264; Chrome, Safari and the apps play MP4/H.264. |
| Typed route error for `/scan` | Run `npx expo start` once to regenerate `.expo/types`. |
