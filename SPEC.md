# Tier pricing plugin: specification (v1)

Status: v1 built on 2026-10-11. Source: B2B decision memo of 2026-10-11 (4 research reports,
8 adversarial verifications), section 3.3 "tmonier/sylius-tier-pricing-plugin". Where a
verifier corrected the research, the verified version is kept.

## 1. Research conclusions

- **Need**: quantity price breaks are a market standard (Shopify, Adobe Commerce, BigCommerce,
  OroCommerce) and proven in Sylius by Brille24/SyliusTierPricePlugin (~186k installs, Sylius 1).
- **Gap**: nothing installs on Sylius 2.3 + Symfony 8. Brille24 v4 targets `~2.0.0` (open PR
  towards ~2.2.9), the leomoshko fork is `<2.3`, Setono 2.x is alpha, Symfony <= 7.4 and
  percentage only. The core has no quantity rule per line.
- **Proprietary overlap**: Sylius Plus price lists (tiers not verified, closed docs) and the
  Elesto distribution (quantity pricing, category discounts:
  https://addons.sylius.com/en_US/products/elesto-distribution). Taxon scope is therefore not
  a differentiator; mix and match between variants (Shopify cannot,
  https://help.shopify.com/en/manual/b2b/getting-started/plan-features), the "add N more"
  nudge, CSV/API by codes and Sylius 2.3 / Symfony 8 support are.
- **Legal (EU, B2C)**: art. 6a of Directive 98/6/EC (as amended by Directive 2019/2161) and
  CJEU C-330/23 (Aldi Süd, 26 September 2024): an announced price reduction must be computed
  from the lowest price of the previous 30 days. Commission guidance 2021/C 526/02 (primary
  text not re-read; corroborated by Bird & Bird,
  https://www.twobirds.com/en/insights/2025/global/transparency-of-price-reductions-a-closer-look-at-the-legal-framework-in-the-eu,
  and RPC): conditional prices (quantity offers) are not price reductions in that sense if
  presented as such. Hence: tiers shown as conditional prices, no struck-through price, no
  "-x %" relative to the tier in B2C, prices with taxes in B2C. Not legal advice.

## 2. Scope v1

In: `TierPriceRule` entity, pure resolver, order processor, product page table + nudge, cart
display + nudge, admin CRUD + product tab, CSV import/export command, admin API CRUD, shop
API read endpoint, tagged cache, demo fixture, translations (7 locales).

Out (v1.1+): taxon scope and basis (mainTaxon vs productTaxons to decide), `on_top` and
`skip_if_promoted` stacking modes, admin upload form, bulk endpoint, snapshot table (to be
written by a workflow listener, not by the processor), listing badge / "from X" in listings,
Brille24 importer, Sylius Plus cohabitation (not testable offline).

## 3. Design

### 3.1 Model: `tmonier_tier_price_rule`

| Column | Notes |
|---|---|
| `channel_id` | FK, cascade |
| `customer_group_id` | nullable FK, cascade; NULL = everybody |
| `scope` | `variant` or `product` |
| `product_id`, `variant_id` | two nullable FKs (cascade) + CHECK: exactly the one of the scope. No polymorphic target without FK. |
| `quantity_basis` | `line` or `product` (mix and match) |
| `min_quantity` | >= 2 |
| `type`, `value` | `fixed_unit_price` (cents, channel base currency) or `percentage_off` (basis points) |
| `enabled`, `starts_at`, `ends_at` | `[starts_at, ends_at)` |

- The CHECK constraint is added by a second migration with plain SQL valid on MySQL >= 8.0.16,
  MariaDB and PostgreSQL (the Schema API has no CHECK). Verified on MySQL 8.4: ON DELETE
  CASCADE is allowed on columns of a CHECK (only SET NULL / ON UPDATE actions are not).
- Uniqueness (channel, group, target, threshold, overlapping period) is an application
  validator, not a unique index: MySQL, MariaDB and PostgreSQL < 15 treat NULLs as distinct.
  The check is per period overlap so that seasonal rules can share a threshold.

### 3.2 Resolution (`TierPriceResolver`, pure)

Candidates: enabled rules of the product in the channel, for everybody or the customer's group
(group rules only for signed-in customers by default: a guest typing the e-mail of an existing
B2B customer at checkout gets that customer attached by Sylius). A rule applies when its target
matches, its threshold is reached for its quantity basis and it is active now. Each applicable
rule gives a price: fixed value or `round_half_up(base × (1 − p))` where `base` is the regular
price (`originalPrice` when a catalog promotion is applied, else the price), then
`max(·, minimumPrice, 0)`, then `min(·, current price)` (**best of** tier and catalog promotion,
never cumulated).

Decision (revised after review, 2026-10-12): **the lowest price wins** among the applicable
rules; specificity (variant over product, group over everybody, highest threshold, then
oldest rule) only breaks ties. The first version ranked specificity before the threshold and
never compared prices: a group rule at 20+ for 9.50 beat an everybody rule at 10+ for 8.00, so
20 pieces cost more than 19 and the table (which dropped the non-decreasing row) advertised a
price the cart did not charge. With best price wins, the set of applicable rules only grows
with the quantity, so the price never goes up, and the table is computed with the same function
for each threshold: what is shown is what is charged (`OrderProcessingTest`, resolver tests).

### 3.3 Order processing (`TierPriceOrderProcessor`, priority 44)

- Runs after `OrderPricesRecalculator` (50) and the quote price lock of
  tmonier/sylius-quote-request-plugin (45, which resets unlocked lines through the
  recalculator), before shipment (40), shipping charges (30), promotions (20), taxes (10).
- `canBeProcessed()` checked; immutable lines skipped and not counted; lines matched by a
  `TierPriceExemptionCheckerInterface` (tagged, composite) skipped and not counted.
- Writes `unitPrice` of the line (and, in consumer channels, `originalUnitPrice` = tier price,
  so that the shop API exposes no reduction from the regular price); never `ChannelPricing`
  (Omnibus price history).
- Marker: neutral adjustment `tmonier_tier_price` on the item (no effect on totals). The
  adjustments clearer (60) only clears the core types, so the processor removes its own
  markers on mutable lines before recomputing. Markers on immutable lines are left as they are.
- Documented effect: item-total promotion rules and free shipping thresholds see tier prices.
- Cohabitation with RFQ: no dependency. Quote lines are immutable (skipped); the group
  discount suggestion of RFQ is unrelated to the group targeting of tiers (not merged).
  Tested by `ProcessorPriorityTest` (44 < 45, > shipment/promotions/taxes) and
  `OrderProcessingTest::testImmutableItemsAreLeftUntouchedAndDoNotCount`.

### 3.4 Display

- Product page: child of `sylius_shop.product.show.content.info.summary.add_to_cart`
  (receives `form`; re-rendered by `AddToCartFormComponent` on variant change, nothing emitted
  on quantity change, so it is not a standalone live component). Table rows: price of 1 piece
  then each threshold whose price is lower than the previous row. Nudge from the cart content.
- Cart: the `unit_price` child of `sylius_shop.cart.index.content.form.sections.general.items.body`
  is replaced by the same cell plus "volume price from N pieces" and the nudge.
- `sylius_shop.twig.extension.order_item_original_price_to_display` is decorated: no
  struck-through price for a line with a tier price; only a cart promotion on top is shown,
  from the tier price
  (https://github.com/Sylius/Sylius/blob/2.3/src/Sylius/Bundle/ShopBundle/Twig/OrderItemOriginalPriceToDisplayExtension.php).
- Taxes (revised after review): Sylius has no "prices with/without tax" setting per channel.
  Converting tier amounts to gross in B2C put tax-included amounts next to tax-excluded Sylius
  prices (product price, cart unit price), so a cheaper next tier looked more expensive. Tier
  amounts are now shown as stored, like the Sylius prices next to them, and labelled from the
  rate of the variant in the channel default tax zone (`PriceDisplayResolver::taxConvention()`,
  memoized per category and zone); no label when unknown (no default zone or rate). Merchants
  of EU consumer channels must store prices with taxes.
- "-x %" column: configurable, off by default in B2C, on in B2B; computed against the price
  of one piece, never against `originalPrice`.

### 3.5 Admin, import, API, cache

- Resource CRUD (`/admin/tier-price-rules`), grid with search (product / variant code),
  channel and enabled filters; "Volume pricing" tab on the product update page (inside the
  product form, so links only, no nested form) with "add a price break" pre-filled for the
  product.
- CSV: `tmonier:tier-pricing:import` (upsert / replace, `--channel`, `--dry-run`), one
  transaction, every line validated (same validator as the admin), flushed line by line so
  that the uniqueness check sees previous lines, entity manager cleared every 500 lines with
  `getReference()` by cached ids. `tmonier:tier-pricing:export` writes the same format.
- API: `TierPriceRule` admin resource (GetCollection, Get, Post, Put, Delete; ids; IRIs of
  channels, groups, products, variants; validation group `sylius`). Shop:
  `GET /api/v2/shop/product-variants/{code}/tier-prices` (amounts as stored, `taxIncluded` true/false/null).
- Cache: pool `cache.tmonier_tier_pricing` (tag aware, `cache.app`), key per channel, product
  and group, tags product / channel / group / all; Doctrine `onFlush`/`postFlush` listener
  invalidates the products of changed rules (old and new product); import invalidates all;
  per-request memo reset by `kernel.reset`.

## 4. Decisions and open points

| Topic | Decision |
|---|---|
| Neutral adjustment as marker (proposal of the memo to validate) | Validated: neutral item adjustments do not change totals (tested), persist with the order and keep a record. Removed and recreated by the processor itself. |
| Percentage rounding | Half up on cents (unit price), before multiplying by the quantity. |
| Group targeting vs RFQ group discounts | Kept separate (memo §3). |
| Catalog promotion stacking | Best of only (v1). |
| Taxes in display | As stored + label from the channel default tax zone (documented limitation). |
| Core display of catalog promotions | Unchanged: Sylius strikes `originalPrice` through; merchants keep it equal to the 30-day lowest price or override the templates (README, Legal notes). |
| Multi-node cache | Pool on `cache.app`: must be shared (Redis) with several nodes, or `cache_ttl: 0` (README). |

Not verified: tiers in Sylius Plus price lists (closed docs); primary text of 2021/C 526/02;
possible Brille24 release for 2.2 (the lasting gap is 2.3 / Symfony 8). The CI matrix (Sylius
2.2 / 2.3, Symfony 6.4 / 7.4 / 8.0, MySQL / MariaDB / PostgreSQL) was written but only the
local MySQL 8.4 + PHP 8.5 + Symfony 7.4 + Sylius 2.3.0 combination was run.
