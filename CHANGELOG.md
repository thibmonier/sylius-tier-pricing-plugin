# Changelog

## 1.1.0 (2026-10-12)

### Added

- **Taxon rules** (`scope: taxon`): every product of a taxon and of its children, matched through
  any of the product's taxons (not only the main one). Admin form (taxon autocomplete), grid,
  admin API (`taxon`), CSV (`taxon` column).
- **Taxon quantity basis** (`quantity_basis: taxon`, taxon rules only): every product of the
  taxon counts towards the threshold (shop: "**" footnote on the product page, note on the cart
  line; shop API: `taxonBasis` per tier).
- **Precedence**: variant and product rules, else the rules of the deepest taxon of the product,
  else none; the level is chosen whatever the quantity (the price never goes up with it).
- **Cumulation with catalog promotions**, per rule (`cumulation`): `best_of` (default, 1.0
  behaviour), `on_top` (percentage off the promotion price), `skip_if_promoted`.
- `TaxonQuantityCounter`.

### Changed

- `TierPriceCalculatorInterface::quote()` and `table()` have a new optional `$taxonQuantities`
  argument; `TierPriceRuleInterface` has `getTaxon()`, `setTaxon()`, `getCumulation()`,
  `setCumulation()` and new constants. Custom implementations of these interfaces must add them
  (extending the plugin classes is enough).
- `TierPriceRuleData` and `TierPriceContext` have new optional constructor arguments;
  `TierPriceRow` has `taxonBasis`; the order processor marker details have `taxonBasis`,
  `taxonId` and `cumulation`.
- CSV export writes the 14 columns of 1.1; the import accepts 1.0 (12 columns) and 1.1 files.
- `phpunit.xml.dist`: the default suite runs every test once.

### Fixed

- PostgreSQL: the rule id is now sequence-based like the ORM mapping expects (the 1.0
  IDENTITY / SERIAL column worked but `doctrine:schema:validate` reported a difference).

Upgrade: `bin/console doctrine:migrations:migrate` (three migrations; existing rules get
`cumulation = best_of` and keep their behaviour).

## 1.0.0 (2026-10-11)

First release.
