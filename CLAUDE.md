# CLAUDE.md

Sylius 2 plugin: volume (tier) pricing. Price break rules per channel, customer group, product
or variant, applied in the cart by an order processor, shown on product pages and in the cart
as conditional prices. Functional spec and decisions: `SPEC.md`. Public docs: `README.md`.

## Commands

PHP needs more than 128 MB to compile the test application container: prefix console and
PHPStan calls with `php -d memory_limit=2G` when running locally.

```bash
docker compose up -d mysql mailhog        # ports come from compose.override.yml (gitignored)
vendor/bin/phpunit --testsuite unit
APP_ENV=test vendor/bin/phpunit --testsuite non-unit   # functional + integration, test DB + fixtures
vendor/bin/phpstan analyse                # level max, src + tests
vendor/bin/ecs check
vendor/bin/console lint:container
vendor/bin/console tmonier:tier-pricing:export
```

Local env overrides (gitignored): `compose.override.yml` (MySQL published on 3326, MailHog on
8045/1045), `tests/TestApplication/.env.local` (DATABASE_URL + MAILER_DSN) and
`.env.test.local` (DATABASE_URL). Databases: `tmonier_sylius_tier_pricing_dev` / `_test`, both
loaded with the default fixture suite, which includes the `tmonier_tier_pricing` demo fixture
(rules on the first 12 products of each channel: 5+/10+/25+ percentages, wholesale group 10+,
mix and match 12+ on multi-variant products, fixed price 50+ on the first variant). Dev
server: `symfony server:start -d --port=8092 --no-tls` (admin `sylius` / `sylius`, shop
`shop@example.com` / `sylius`); e.g. `/en_US/products/ethereal-drift-t-shirt`.

## Architecture

- `src/Entity/TierPriceRule` (Sylius resource, XML mapped superclass in `config/doctrine`,
  migrations in `src/Migrations`: Schema API table + plain SQL CHECK on scope/target).
- `Pricing/TierPriceResolver`: pure (no DB, no clock); 1.1 precedence by level (variant/product, then deepest taxon), lowest price wins among the applicable rules of that level (specificity breaks ties), cumulation `best_of` / `on_top` / `skip_if_promoted`; `Pricing/TaxonQuantityCounter` (taxon basis); `TierPriceRuleData` / `TierPriceContext`
  value objects. `Pricing/CachedTierPriceRuleProvider`: rules per channel/product/group from
  `Repository/TierPriceRuleRepository::findCandidateData()`, tag-aware pool
  `cache.tmonier_tier_pricing` + memo (kernel.reset). `EventListener/TierPriceCacheInvalidator`
  (onFlush/postFlush) invalidates products of changed rules.
- `Pricing/TierPriceCalculator`: `quote()` (one line) and `table()` (rows + nudge) from the
  variant channel pricing (base = originalPrice when a catalog promotion is applied).
- `OrderProcessing/TierPriceOrderProcessor`: tag `sylius.order_processor` priority 44 (after
  the quote lock at 45 of tmonier/sylius-quote-request-plugin), skips immutable and exempt
  lines (`TierPriceExemptionCheckerInterface`, composite), neutral `tmonier_tier_price`
  adjustment as marker. Never writes `ChannelPricing`.
- Group rules: `Pricing/OrderCustomerGroupResolver`: in shop / shop API sections (Sylius
  section provider) only for the signed-in customer unless `group_prices_for_guests`; admin,
  admin API and console always use the order customer's group. In consumer channels the processor also sets `originalUnitPrice`
  of tier lines to the tier price (shop API shows no reduction).
- Display: `Twig/TierPricingExtension` (table, cart table, tax convention, percentage; skips
  exempt lines), `Pricing/PriceDisplayResolver` (tax convention label, amounts shown as stored;
  `b2b_channels` only drives the percentage column default), `Twig/OrderItemOriginalPriceToDisplayExtension`
  decorates the core function (no struck-through price for tiers). Hooks in `config/twig_hooks`.
- Admin: resource routes `config/routes/admin.yaml` (hook prefix
  `tmonier_tier_pricing_admin.tier_price_rule`), grid `config/grids`, `Form/Type/TierPriceRuleType`
  (value entered as `percentage` or `amount`, unmapped), `Factory/TierPriceRuleFactory`
  (`createForProduct` for the product tab), validation `config/validation` +
  `Validator/ValidTierPriceRule` (target, value, dates, uniqueness with NULL groups).
- CSV: `Csv/TierPriceRuleCsvImporter` (one transaction, flush per line, clear every 500, BOM, strict `enabled`, duplicate lines reported, offsets kept) and
  exporter, commands in `src/Command`.
- API: `config/api_platform` (admin `TierPriceRule` CRUD, shop `TierPriceTableView` read model
  with `Api/TierPriceTableProvider`), groups in `config/serialization`.

## Rules

- No core entity change: plugin table only.
- Test application: `tests/TestApplication/config/exemption.php` exempts variants whose code
  ends with `_exempt`; `TestCatalog::businessChannel()` creates the `TMONIER_B2B` channel
  (host `b2b.tmonier.test`, listed in `b2b_channels`).
- Tests never depend on random fixtures: `tests/TestCatalog.php` creates products, rules,
  groups, tax rates and carts inside the rolled-back transaction (`TierPricingTestTrait`);
  carts of a logged-in customer use `setCustomerWithAuthorization()` (else the cart context
  ignores them). No `markTestSkipped` (`NoSkippedTestsTest`).
- Labels in `messages`, validation messages in `validators`; every key in en, fr, de, es, it,
  nl, pl (`TranslationsTest` also checks keys used in src/templates/config). Clear the cache
  (dev and test) after adding a translation file.
- Legal display rules (B2C): no struck-through price and no "-x %" for a tier by default,
  tier amounts in the tax convention of the Sylius prices next to them, labelled only when known.
  Keep them when changing templates (see README, Legal notes).
- Admin forms use `@SyliusAdmin/shared/form_theme.html.twig` (through the CRUD templates).
