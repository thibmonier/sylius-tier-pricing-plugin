# Sylius Tier Pricing Plugin

[![Build](https://github.com/thibmonier/sylius-tier-pricing-plugin/actions/workflows/build.yaml/badge.svg)](https://github.com/thibmonier/sylius-tier-pricing-plugin/actions/workflows/build.yaml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Volume (tier) pricing for Sylius 2: "from 10 pieces, 9.00 € per piece".**

Sylius 2 has one price per variant and channel. Quantity price breaks are a basic need of
wholesale and B2B shops (and of many consumer shops), but the open source plugins that did it
target Sylius 1, and the Sylius Plus / Elesto engines are proprietary.

This plugin adds price break rules per channel, customer group, product or variant, applies
them in the cart, and shows them on product pages and in the cart as conditional prices, free
and open source.

## Features

| Need | What the plugin does |
|---|---|
| Lower the unit price from a quantity | **Rules**: from `N` pieces (N >= 2), a **fixed unit price** or a **percentage off** the regular price, per channel, optionally for one **customer group**, with optional start and end dates. |
| Target what you sell | A rule applies to **one variant** or to **every variant of a product**. The threshold counts the cart line, or **every variant of the product** (mix and match: 6 red + 4 blue = 10). |
| Predictable results | The most specific rule wins: variant over product, then customer group over everybody, then the highest threshold reached. |
| Correct carts | An order processor (priority 44) changes the unit price of the cart line; cart promotions, shipping and taxes are computed on it. Locked (immutable) lines, e.g. negotiated quote lines, are left untouched. |
| No double discount | With a catalog promotion, the customer gets the **best of** the tier price and the promotion price (a percentage is computed on the regular price). The channel pricing minimum price is a floor. |
| Show the prices | **Tier table** on the product page (re-rendered when the variant changes), **"add 3 more to pay 8.00 € per piece"** nudges on the product page and in the cart, "volume price from 10 pieces" on cart lines. |
| Stay on the right side of price rules | Tiers are shown as conditional prices: no struck-through price, no "-x %" in consumer channels by default, prices with taxes in consumer channels (see [Legal notes](#legal-notes)). |
| Manage the rules | Admin grid (*Catalog > Volume pricing*), rule form, **Volume pricing tab** on the product page. |
| Bulk changes | `tmonier:tier-pricing:import` / `:export` (CSV, upsert or replace, dry run, all or nothing). |
| Headless | Admin API CRUD `/api/v2/admin/tier-price-rules`, shop API `GET /api/v2/shop/product-variants/{code}/tier-prices`. |

Also included: translations in English, French, German, Spanish, Italian, Dutch and Polish;
Twig Hooks everywhere; tagged cache per product, channel and customer group; demo fixture;
MySQL, MariaDB and PostgreSQL support.

## Screenshots

![Product page](docs/images/shop-product-tier-table.png)

![Cart](docs/images/shop-cart-tier.png)

![Admin rules](docs/images/admin-tier-price-rules.png)

## Requirements

| | Version |
|---|---|
| PHP | 8.2+ (Sylius 2.2), 8.3+ (Sylius 2.3) |
| Sylius | 2.2.9+, 2.3 |
| Symfony | 6.4, 7.4 (Sylius 2.2); 6.4, 7.4, 8.0 (Sylius 2.3, Symfony 8 needs PHP 8.4) |

Sylius 2.2.9 is the floor because the plugin changes cart prices: earlier 2.2 releases are
affected by GHSA-2rv4-pjmm-7fxf and GHSA-vv4h-q2x8-74g4 (payment requests).

## Installation

1. Require the package:

    ```bash
    composer require tmonier/sylius-tier-pricing-plugin
    ```

2. Register the bundle in `config/bundles.php` (Symfony Flex does it automatically once a recipe exists):

    ```php
    Tmonier\SyliusTierPricingPlugin\TmonierSyliusTierPricingPlugin::class => ['all' => true],
    ```

3. Import the configuration in `config/packages/tmonier_sylius_tier_pricing.yaml`:

    ```yaml
    imports:
        - { resource: "@TmonierSyliusTierPricingPlugin/config/config.yaml" }
    ```

4. Import the admin routes in `config/routes/tmonier_sylius_tier_pricing.yaml`:

    ```yaml
    tmonier_sylius_tier_pricing:
        resource: "@TmonierSyliusTierPricingPlugin/config/routes.yaml"
    ```

    The API operations are registered with the Sylius API (`/api/v2`) automatically.

5. Run the migrations (a table and a CHECK constraint):

    ```bash
    bin/console doctrine:migrations:migrate
    ```

## Configuration

```yaml
# config/packages/tmonier_sylius_tier_pricing.yaml
tmonier_sylius_tier_pricing:
    b2b_channels: []        # channel codes shown without taxes (every other channel: with taxes)
    percentage_column:      # "-x %" column of the tier table
        b2c: false
        b2b: true
    product_page: true      # tier table and nudge on product pages
    cart: true              # tier applied and next tier in the cart
    cache_ttl: 3600         # cached rules per product (0 = no cache); saving a rule invalidates them
```

The rules of a product are cached in the `cache.tmonier_tier_pricing` pool (tags: product,
channel, customer group; adapter `cache.app` by default). Any rule saved through Doctrine
(admin, API, import) invalidates its product.

### Rules

| Field | Values |
|---|---|
| Channel | required; amounts are in its base currency |
| Customer group | empty = everybody |
| Applies to | `product` (every variant) or `variant` |
| Quantity counted | `line` (the variant) or `product` (every variant of the product, mix and match) |
| From quantity | 2 to 1,000,000 |
| Price | `percentage_off` (of the regular price, before catalog promotions) or `fixed_unit_price` (same tax convention as the channel prices) |
| Enabled, starts at, ends at | the end is excluded |

One rule per channel, customer group, target and threshold over a period: checked by the
application (a unique index would treat the "everybody" rules as distinct on MySQL, MariaDB
and PostgreSQL < 15).

### CSV import and export

```bash
bin/console tmonier:tier-pricing:export rules.csv [--channel=FASHION_WEB]
bin/console tmonier:tier-pricing:import rules.csv --dry-run
bin/console tmonier:tier-pricing:import rules.csv [--mode=upsert|replace] [--channel=FASHION_WEB]
```

```csv
channel,customer_group,scope,product,variant,quantity_basis,min_quantity,type,value,enabled,starts_at,ends_at
FASHION_WEB,,product,Everyday_white_basic_T_Shirt,,line,10,percentage_off,7.5,1,,
FASHION_WEB,wholesale,variant,,Everyday_white_basic_T_Shirt-variant-0,line,50,fixed_unit_price,6.40,1,2027-01-01T00:00:00+00:00,
```

`value` is an amount (`6.40`) for a fixed price and a percentage (`7.5`) for a percentage off.
`upsert` updates the rule with the same channel, group, target, threshold and dates, or creates
it; `replace` deletes every rule (of `--channel`) first. The whole file runs in one transaction:
one invalid line and nothing is saved (every error is listed with its line number). The entity
manager is cleared every 500 lines.

### Extension point

Implement `Tmonier\SyliusTierPricingPlugin\OrderProcessing\TierPriceExemptionCheckerInterface`
(autoconfigured) to keep some cart lines out of tier pricing (they keep their price and do not
count in the quantity of their product).

## Customisation

| Hook | Content |
|---|---|
| `sylius_shop.product.show.content.info.summary.add_to_cart` → `tmonier_tier_pricing` | Tier table and nudge (between quantity and button) |
| `sylius_shop.cart.index.content.form.sections.general.items.body` → `unit_price` | Core unit price cell + tier applied + nudge (replaces the core template) |
| `sylius_admin.product.update.content.form.side_navigation` / `.sections` → `tmonier_tier_pricing` | Volume pricing tab of the product form |
| `tmonier_tier_pricing_admin.tier_price_rule.create\|update.content.form.sections.general` → `fields` | Rule form layout |

Templates can be overridden in `templates/bundles/TmonierSyliusTierPricingPlugin/`. Twig
functions: `tmonier_tier_pricing_table(variant)`, `tmonier_tier_pricing_cart_table(item)`,
`tmonier_tier_pricing_display_amount(variant, amount)`, `tmonier_tier_pricing_item_tier(item)`.
In PHP, `TierPriceCalculatorInterface::quote()` / `table()` give the tier price of a variant for
given quantities.

## How prices are computed

- `OrderPricesRecalculator` (priority 50) resets the unit price of each mutable line to the
  catalog price; the quote price lock of
  [tmonier/sylius-quote-request-plugin](https://github.com/thibmonier/sylius-quote-request-plugin)
  runs at 45; the tier processor at **44**; then shipping (40, 30), promotions (20), taxes (10).
- For each mutable, non-exempt line: rules of the product for the channel and the customer
  group of the order, resolved for the line quantity and the product quantity (mutable lines
  only). Price = fixed price, or regular price minus the percentage, rounded half up, at least
  the channel pricing minimum price, at most the current price.
- The line gets a **neutral** `tmonier_tier_price` adjustment (no effect on totals) with the
  rule id, threshold and price without tier: the order keeps a record of the tier applied.
- `ChannelPricing` is never written: the price history used for the "lowest price before the
  discount" stays the catalog one.
- Consequence: cart promotion rules on the items total and free shipping thresholds see the
  tier prices.

## Legal notes

*This is not legal advice; check with your counsel, especially for consumer (B2C) channels in
the EU.*

- Since CJEU C-330/23 (Aldi Süd, 26 September 2024) and art. 6a of the Price Indication
  Directive 98/6/EC (Omnibus Directive 2019/2161), an announced **price reduction** must be
  computed from the lowest price of the last 30 days. A quantity tier is a **conditional price**
  ("9.00 € from 10 pieces"), not a reduction of the price: the plugin shows it that way, does
  not strike the regular price through for a tier, and hides the "-x %" column in consumer
  channels by default (it compares with the price of one piece, not with a previous price).
- Reductions that the plugin does not create (catalog promotions) keep the Sylius display,
  which uses the lowest price before the discount, not `originalPrice`.
- Consumer prices must include taxes: tier prices are shown with taxes in every channel not
  listed in `b2b_channels`, computed with the tax rate of the variant in the channel default
  tax zone.

## Limitations

- No taxon (category) scope or quantity basis yet; no `on_top` or `skip_if_promoted`
  stacking modes (best of only); no admin CSV upload form; no badge or "from X" on product
  lists (planned for 1.1).
- The product page nudge is computed from the cart content, not from the quantity typed in
  the add to cart form (the Sylius component does not re-render on quantity changes).
- A percentage applies to `originalPrice` when a catalog promotion is applied; if the merchant
  also uses `originalPrice` as a manual "compare at" price, the base of the percentage is that
  price (the best-of rule still caps the result at the promotion price).
- The display helper uses the default tax zone of the channel, not the customer's address.
- Exempt lines still count in the quantities shown by the cart nudge.
- Tier prices in Sylius Plus price lists and the Elesto Advanced Pricing Engine are not
  supported or tested.

## Development

```bash
docker compose up -d mysql mailhog   # host ports in compose.override.yml
composer install
(cd vendor/sylius/test-application && yarn install && yarn build)
vendor/bin/console doctrine:database:create
vendor/bin/console doctrine:migrations:migrate -n
vendor/bin/console sylius:fixtures:load -n     # includes demo tier rules

vendor/bin/phpunit --testsuite unit
APP_ENV=test vendor/bin/phpunit --testsuite non-unit   # needs a test database with fixtures
vendor/bin/phpstan analyse
vendor/bin/ecs check
```

## License

MIT. See [LICENSE](LICENSE).
