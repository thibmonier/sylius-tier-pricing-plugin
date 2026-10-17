# Sylius Tier Pricing Plugin

> Français : [README.fr.md](README.fr.md) <!-- vitrine:lang -->

[![Build](https://github.com/thibmonier/sylius-tier-pricing-plugin/actions/workflows/build.yaml/badge.svg)](https://github.com/thibmonier/sylius-tier-pricing-plugin/actions/workflows/build.yaml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Volume (tier) pricing for Sylius 2: "from 10 pieces, 9.00 € per piece".**

Sylius 2 has one price per variant and channel. Quantity price breaks are a basic need of
wholesale and B2B shops (and of many consumer shops), but the open source plugins that did it
target Sylius 1.

This plugin adds price break rules per channel, customer group, product or variant, applies
them in the cart, and shows them on product pages and in the cart as conditional prices, free
and open source.

## Features

| Need | What the plugin does |
|---|---|
| Lower the unit price from a quantity | **Rules**: from `N` pieces (N >= 2), a **fixed unit price** or a **percentage off** the regular price, per channel, optionally for one **customer group**, with optional start and end dates. |
| Target what you sell | A rule applies to **one variant**, to **every variant of a product** or *(1.1)* to **every product of a taxon** and of its children (through any of the product's taxons, not only the main one). The threshold counts the cart line, **every variant of the product** (mix and match: 6 red + 4 blue = 10) or *(1.1)* **every product of the taxon** (4 drills + 6 saws = 10 tools). |
| Clear precedence *(1.1)* | Variant and product rules first, else the rules of the **deepest taxon** of the product, else the regular price. |
| Predictable results | The most specific level that has rules for the customer wins (variant/product, else the deepest taxon, see [How prices are computed](#how-prices-are-computed)); within it, the **lowest unit price** among the rules reached wins (specificity only breaks ties). The price never goes up with the quantity, and the product table shows what the cart charges for the line (taxon-basis tiers also count the other products of the taxon in the cart). |
| Correct carts | An order processor (priority 44) changes the unit price of the cart line; cart promotions, shipping and taxes are computed on it. Locked (immutable) lines, e.g. negotiated quote lines, are left untouched. |
| No double discount, or a chosen one | Per rule *(1.1)*: **best of** the tier price and the catalog promotion price (default, 1.0 behaviour; a percentage is computed on the regular price), **on top** (the percentage is taken off the promotion price) or **skip if promoted** (no tier price for a variant with a catalog promotion). The channel pricing minimum price is a floor; cart promotions always apply on top of the tier price. |
| Show the prices | **Tier table** on the product page (re-rendered when the variant changes), **"add 3 more to pay 8.00 € per piece"** nudges on the product page and in the cart, "volume price from 10 pieces" on cart lines. |
| Stay on the right side of price rules | Tiers are shown as conditional prices: no struck-through price (also in the shop API: in consumer channels `originalUnitPrice` of a tier line is the tier price and its `tmonier_tier_price` adjustment has a zero amount), no "-x %" in consumer channels by default, amounts in the tax convention of the Sylius prices next to them, labelled (see [Legal notes](#legal-notes)). |
| Manage the rules | Admin grid (*Catalog > Volume pricing*), rule form, **Volume pricing tab** on the product page. |
| Bulk changes | `tmonier:tier-pricing:import` / `:export` (CSV, upsert or replace, dry run, all or nothing). |
| Headless | Admin API CRUD `/api/v2/admin/tier-price-rules`, shop API `GET /api/v2/shop/product-variants/{code}/tier-prices`. |

Also included: translations in English, French, German, Spanish, Italian, Dutch and Polish;
Twig Hooks everywhere; tagged cache per product, channel and customer group; demo fixture;
MySQL, MariaDB and PostgreSQL support.

## Screenshots

![Volume prices table on the product page, with the mix and match tier marked](docs/images/shop-product-tier-table.jpg)

![Cart with volume prices: two sizes of a T-shirt counted together (from 12 pieces) and two caps counted together through their category (from 10 pieces), with the "add 19 more" nudge](docs/images/shop-cart-volume-prices.jpg)

![Volume pricing tab of the product form in the back office: price breaks per customer group, mix and match and a fixed price on one variant, with the precedence warning over the category rules](docs/images/admin-product-volume-pricing.jpg)

![Tier price rule form: 15 % off every product of the Caps taxon from 10 pieces, counted across the taxon, best of the tier price and the catalog promotion price](docs/images/admin-tier-price-rule-taxon.jpg)

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
    b2b_channels: []        # business channel codes (only changes the defaults below)
    percentage_column:      # "-x %" column of the tier table
        b2c: false
        b2b: true
    product_page: true      # tier table and nudge on product pages
    cart: true              # tier applied and next tier in the cart
    group_prices_for_guests: false  # customer group rules for guest checkouts too (see below)
    cache_ttl: 3600         # cached rules per product (0 = no cache); saving a rule invalidates them
```

**Customer group rules.** In shop requests (shop pages and shop API), group rules apply only
when the order's customer is the signed-in customer. At a guest checkout, Sylius attaches an
existing customer (without shop account, common for B2B customers created in the admin) to the
order from the e-mail typed by the visitor: anyone knowing that e-mail would get the group
prices. Everywhere else (admin, admin API, console, message consumers: orders created on behalf
of a customer, quote conversions, imports) the group of the order's customer applies. Set
`group_prices_for_guests: true` to apply group rules in shop requests too.

**Taxes.** Sylius has no "prices with / without taxes" setting per channel: a channel price
includes the tax or not depending on the tax rate (`includedInPrice`) of the variant. Tier
amounts are shown as stored, in the same convention as the Sylius product price and cart unit
price next to them, with an "including / excluding taxes" label taken from the rate of the
variant in the channel default tax zone (no label when it is unknown: no default tax zone, no
rate). Consumer channels in the EU should store prices with taxes (rates `includedInPrice`).

The rules of a product are cached in the `cache.tmonier_tier_pricing` pool (tags: product,
channel, customer group; adapter `cache.app` by default). Any rule saved through Doctrine
(admin, API, import) invalidates its product **in that pool**. With several web or worker
nodes, `cache.app` must be shared (e.g. Redis), or configure the pool on a shared tag-aware
adapter, or set `cache_ttl: 0`: with a local filesystem cache, the other nodes keep the old
rules until `cache_ttl` expires.

```yaml
framework:
    cache:
        pools:
            cache.tmonier_tier_pricing:
                adapter: cache.adapter.redis_tag_aware
                provider: '%env(REDIS_URL)%'
```

### Rules

| Field | Values |
|---|---|
| Channel | required; amounts are in its base currency |
| Customer group | empty = everybody |
| Applies to | `product` (every variant), `variant` or `taxon` (every product of the taxon and of its children, 1.1) |
| Quantity counted | `line` (the variant), `product` (every variant of the product, mix and match) or `taxon` (every product of the taxon of the rule, taxon rules only, 1.1) |
| With catalog promotions (1.1) | `best_of` (default), `on_top` or `skip_if_promoted` |
| From quantity | 2 to 1,000,000 |
| Price | `percentage_off` (of the regular price, before catalog promotions) or `fixed_unit_price` (same tax convention as the channel prices, at most 10,000,000.00) |
| Enabled, starts at, ends at | the end is excluded; dates are stored in the PHP default timezone (UTC recommended, as for Sylius), a date given with another offset keeps its instant |

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
channel,customer_group,scope,product,variant,quantity_basis,min_quantity,type,value,enabled,starts_at,ends_at,taxon,cumulation
FASHION_WEB,,product,Everyday_white_basic_T_Shirt,,line,10,percentage_off,7.5,1,,,,best_of
FASHION_WEB,wholesale,variant,,Everyday_white_basic_T_Shirt-variant-0,line,50,fixed_unit_price,6.40,1,2027-01-01T00:00:00+00:00,,,
FASHION_WEB,,taxon,,,taxon,20,percentage_off,5,1,,,t_shirts,on_top
```

The last two columns are new in 1.1 (`taxon`: code of the taxon of a taxon rule; `cumulation`:
empty = `best_of`); files with the 12 columns of 1.0 are still imported.

`value` is an amount (`6.40`) for a fixed price and a percentage (`7.5`) for a percentage off.
`enabled` is `1`, `true`, `yes`, `0`, `false`, `no` or empty (enabled); anything else is an
error. Dates are ISO 8601; their offset is kept (converted to the application timezone). A
leading UTF-8 byte order mark (Excel "CSV UTF-8") is ignored. Two lines for the same rule are
reported (`duplicate of line N`).
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
| `sylius_shop.cart.index.content.form.sections.general.items.body` → `item` | Core product cell + tier applied + nudge under the product (replaces the core template) |
| `sylius_admin.product.update.content.form.side_navigation` / `.sections` → `tmonier_tier_pricing` | Volume pricing tab of the product form |
| `tmonier_tier_pricing_admin.tier_price_rule.create\|update.content.form.sections.general` → `fields` | Rule form layout |

Templates can be overridden in `templates/bundles/TmonierSyliusTierPricingPlugin/`. Twig
functions: `tmonier_tier_pricing_table(variant)`, `tmonier_tier_pricing_cart_table(item)`,
`tmonier_tier_pricing_tax_convention(variant)`, `tmonier_tier_pricing_item_tier(item)`.
In PHP, `TierPriceCalculatorInterface::quote()` / `table()` give the tier price of a variant for
given quantities.

## How prices are computed

- `OrderPricesRecalculator` (priority 50) resets the unit price of each mutable line to the
  catalog price; the quote price lock of
  [tmonier/sylius-quote-request-plugin](https://github.com/thibmonier/sylius-quote-request-plugin)
  runs at 45; the tier processor at **44**; then shipping (40, 30), promotions (20), taxes (10).
- For each mutable, non-exempt line: rules of the product (and, 1.1, of the taxons of the
  product and their ancestors) for the channel and the customer group of the order (in shop
  requests, only for the signed-in customer by default).
- **Precedence (1.1)**: the variant and product rules; if none targets the variant, the
  customer and today, the rules of the deepest taxon that has such rules (several taxons at that
  depth compete); else none. A level is chosen whatever the quantity, so a product rule from 20
  pieces hides a taxon rule from 5 pieces: below 20 pieces there is no tier price (the price
  never goes up with the quantity). The admin warns about it on the product page (Volume
  pricing tab) and on the rule form.
  **Recipe**: to keep the category tiers below the product ones, copy them onto the product
  (e.g. category 10+ -5 %, product 50+ -10 %: add a product rule 10+ -5 %), or give the product
  no rule and let the category apply.
- Within that level, the rules applicable for the line quantity, the product quantity or the
  taxon quantity (mutable, non-exempt lines only; `skip_if_promoted` rules are left out for a
  variant with a catalog promotion) each give a price: fixed price, or regular price (`on_top`:
  current price) minus the percentage, rounded half up, at least the channel pricing minimum
  price, at most the current price. The **lowest** price wins; ties go to the most specific rule
  (variant, then customer group, then highest threshold), then to the oldest.
  The product table is computed with the same function for each threshold.
- In consumer channels the original unit price of a tier line is set to the tier price, so that
  neither the cart nor a headless front using the shop API (`originalUnitPrice > unitPrice`)
  shows the regular price struck through. Business channels keep the regular price there.
- The line gets a **neutral** `tmonier_tier_price` adjustment (no effect on totals) with the
  rule id, threshold and price without tier in its details: the order keeps a record of the
  tier applied. Its amount is the difference with the price without tier in business channels,
  **0 in consumer channels** (1.1): the shop API adjustment endpoints
  (`/orders/{token}/adjustments`, `/orders/{token}/items/{id}/adjustments`) would otherwise let a
  headless front list it as a reduction from the regular price. The details are not exposed by
  the shop API.
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
  not strike the regular price through for a tier (shop pages and shop API), and hides the
  "-x %" column in consumer channels by default (it compares with the price of one piece, not
  with a previous price).
- Reductions the plugin does not create keep the **core Sylius display**, which is not
  changed by this plugin: during a catalog promotion (or with a manual "compare at" price), the
  product page and the cart strike `ChannelPricing.originalPrice` through, and the product page
  shows the lowest price of the last 30 days on a separate line. The **product cards** (product
  lists, taxon pages, homepage, associated / related products) strike `originalPrice` through
  **without** the lowest price line. For B2C compliance, keep
  `originalPrice` equal to the lowest price of the last 30 days, or override those core
  templates. When a tier price wins over a catalog promotion, no struck-through price is shown.
- Consumer prices must include taxes: store consumer channel prices with taxes (tax rates
  `includedInPrice`). Tier amounts follow the convention of the channel prices and are labelled
  accordingly; no "including taxes" label is shown when the convention cannot be determined.

## Limitations

- No admin CSV upload form; no badge or "from X" on product lists (planned for 1.2).
- `skip_if_promoted` only looks at catalog promotions: cart promotions are computed after the
  tier price (priority 20 < 44) and always apply on top of it.
- `on_top` with a fixed unit price behaves as `best_of` (the best of the fixed price and the
  promotion price).
- On the product page, a taxon-basis threshold counts the taxon lines already in the cart; the
  nudge does not know which other products the customer will add.
- The product page nudge is computed from the cart content, not from the quantity typed in
  the add to cart form (the Sylius component does not re-render on quantity changes).
- A percentage applies to `originalPrice` when a catalog promotion is applied; if the merchant
  also uses `originalPrice` as a manual "compare at" price, the base of the percentage is that
  price (the best-of rule still caps the result at the promotion price).
- The tax label uses the default tax zone of the channel, not the customer's address.
- The admin grid loads product names once per product (translations are not joined).
- Tier prices in Sylius Plus price lists and the Elesto Advanced Pricing Engine are not
  supported or tested.

## Development

```bash
docker compose up -d mysql mailhog   # empty root password; host ports in compose.override.yml
composer install
(cd vendor/sylius/test-application && yarn install && yarn build)
vendor/bin/console doctrine:database:create
vendor/bin/console doctrine:migrations:migrate -n
vendor/bin/console sylius:fixtures:load -n     # includes demo tier rules
```

The functional and integration tests need a **test database with the Sylius fixtures** (admin
`sylius`, `shop@example.com`, a channel with hostname `localhost` and a default tax zone).
`tests/TestApplication/.env.test` uses the CI credentials (`root:root`); with the local Docker
MySQL, override it in `tests/TestApplication/.env.test.local`:

```bash
echo 'DATABASE_URL=mysql://root@127.0.0.1:3306/tmonier_sylius_tier_pricing_%kernel.environment%' > tests/TestApplication/.env.test.local
APP_ENV=test vendor/bin/console doctrine:database:create
APP_ENV=test vendor/bin/console doctrine:migrations:migrate -n
APP_ENV=test vendor/bin/console sylius:fixtures:load -n

vendor/bin/phpunit --testsuite unit
APP_ENV=test vendor/bin/phpunit --testsuite non-unit
vendor/bin/phpstan analyse
vendor/bin/ecs check
```

<!-- vitrine:demo-b2b:start -->
## B2B demo

This plugin is used in the [Sylius B2B demo](https://github.com/thibmonier/sylius-b2b-demo): a Sylius 2.3 shop with fictitious sample data where the B2B plugins work together. The source code, setup and fixtures of the demo are in that repository.

The demo is an example, not a production setup.
<!-- vitrine:demo-b2b:end -->

## License

MIT. See [LICENSE](LICENSE).
