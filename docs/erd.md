# Entity relationship diagram

Keys and relationships only; see `backend/database/sql/*.sql` for every column and constraint.
Renders in GitHub, Cursor's Markdown preview and any Mermaid viewer.

```mermaid
erDiagram
    users ||--o{ user_devices : "installs"
    users ||--o{ user_consents : "grants"
    users ||--o{ shipping_addresses : "saves"
    users ||--o{ social_connections : "requests / receives"
    users |o--o{ users : "referred_by"

    brands ||--o{ products : "labels"
    merchants ||--o{ products : "sells"

    users ||--o{ cart_items : "adds"
    products ||--o{ cart_items : "in"

    users ||--o{ drops : "creates"
    products |o--o{ drops : "item drop"
    brands ||--o{ drops : "brand-wide drop"
    drops ||--o{ drop_participants : "slots"
    users ||--o{ drop_participants : "joins"

    users ||--o{ share_links : "shares"
    share_links |o--o{ share_links : "parent (influence tree)"

    users |o--o{ affiliate_clicks : "clicks out"
    merchants ||--o{ affiliate_clicks : "to"
    affiliate_clicks |o--o{ affiliate_conversions : "SubID match"
    merchants ||--o{ affiliate_conversions : "reports"

    users ||--o{ orders : "places"
    shipping_addresses |o--o{ orders : "ships to"
    orders ||--o{ merchant_orders : "one per merchant"
    merchants ||--o{ merchant_orders : "fulfils"
    affiliate_conversions |o--o| merchant_orders : "confirms"
    merchant_orders ||--o{ order_items : "lines"
    products |o--o{ order_items : "bought"
    drops |o--o{ order_items : "qualifies for"
    share_links |o--o{ order_items : "attributed to"
    order_items |o--o| drop_participants : "first qualifying purchase"

    order_items ||--o{ user_closet : "one row per unit"
    users ||--o{ user_closet : "owns"
    user_closet ||--o{ resale_listings : "listed as"
    users ||--o{ resale_listings : "sells / buys"
    resale_listings |o--o| user_closet : "buyer's unit (provenance)"
    users ||--o{ feed_posts : "posts (opt-in)"

    order_items ||--o{ attribution_tree : "credited to"
    users ||--o{ attribution_tree : "referrer / buyer"

    users |o--o{ ledger_accounts : "wallets"
    drops |o--o{ ledger_accounts : "pool"
    merchants |o--o{ ledger_accounts : "receivable"
    transactions ||--|{ ledger_entries : "balanced legs"
    ledger_accounts ||--o{ ledger_entries : "posted to"
    affiliate_conversions |o--o{ transactions : "accrual / clearance / reversal"
    drops |o--o{ transactions : "pool release / forfeit"
    resale_listings |o--o{ transactions : "resale sale"
    payouts |o--o{ transactions : "payout lifecycle"
    users ||--o{ payouts : "withdraws"
```

## Money flow for one affiliate purchase

```mermaid
flowchart LR
    A[Click with SubID] --> B[Purchase on merchant site]
    B --> C[Network reports conversion: pending]
    C --> D[commission_accrual: receivable vs pending rewards + pool + platform]
    D --> E{Hold >= 14 days AND network approved?}
    E -- no --> E
    E -- yes --> F[reward_clearance: pending -> available]
    C -- network reverses --> R[commission_reversal / clawback]
    F --> G[payout -> payout_settlement]
    D --> P{Drop closed?}
    P -- succeeded --> Q[drop_pool_release at achieved tier]
    P -- failed --> S[drop_pool_forfeit]
```

## Release 2 additions (social discovery and 1-Tap)

New tables and the relationships they add; columns and triggers are in the `2026_10_12_*` migrations and
explained in `docs/ARCHITECTURE.md` section 4.

```mermaid
erDiagram
    users ||--o{ feed_posts : "posts (kind = media)"
    products |o--o{ feed_posts : "shoppable"
    feed_posts ||--o{ feed_post_likes : "liked (PK post + user)"
    users ||--o{ feed_post_likes : "likes"
    feed_posts ||--o{ feed_post_comments : "comments"
    feed_post_comments |o--o{ feed_post_comments : "replies (one level)"
    users ||--o{ feed_post_comments : "writes"
    products ||--o{ product_image_embeddings : "vector(512) per image x model x view"
    users ||--o{ visual_scans : "scans"
    users ||--o{ orders : "places (idempotency_key)"
    orders ||--o{ merchant_orders : "payment_reference, payment_status, capture_after"
    merchant_orders |o--o{ transactions : "marketplace_fee"
```
