<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Entity;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Customer\Model\CustomerGroupInterface;
use Sylius\Resource\Model\ResourceInterface;
use Sylius\Resource\Model\TimestampableInterface;
use Sylius\Resource\Model\ToggleableInterface;

/**
 * A quantity price break: from `minQuantity` units, the unit price of the target (a variant,
 * every variant of a product, or every product of a taxon and its children) becomes a fixed
 * price or the regular price minus a percentage, in one channel, optionally for one customer
 * group only.
 */
interface TierPriceRuleInterface extends ResourceInterface, TimestampableInterface, ToggleableInterface
{
    public const SCOPE_VARIANT = 'variant';

    public const SCOPE_PRODUCT = 'product';

    /** Every product classified in the taxon or one of its children (any of its taxons). */
    public const SCOPE_TAXON = 'taxon';

    public const SCOPES = [self::SCOPE_VARIANT, self::SCOPE_PRODUCT, self::SCOPE_TAXON];

    /** The quantity of the cart line (one variant). */
    public const BASIS_LINE = 'line';

    /** The quantity of every line of the same product (mix and match of its variants). */
    public const BASIS_PRODUCT = 'product';

    /** The quantity of every line whose product is in the taxon of the rule (taxon rules only). */
    public const BASIS_TAXON = 'taxon';

    public const BASES = [self::BASIS_LINE, self::BASIS_PRODUCT, self::BASIS_TAXON];

    /** `value` is a unit price in the channel base currency, in minor units (cents). */
    public const TYPE_FIXED_UNIT_PRICE = 'fixed_unit_price';

    /** `value` is a percentage off the regular price, in basis points (1000 = 10 %). */
    public const TYPE_PERCENTAGE_OFF = 'percentage_off';

    public const TYPES = [self::TYPE_FIXED_UNIT_PRICE, self::TYPE_PERCENTAGE_OFF];

    /** The best of the tier price and the catalog promotion price (1.0 behaviour). */
    public const CUMULATION_BEST_OF = 'best_of';

    /** A percentage off the catalog promotion price (a fixed price stays the best of both). */
    public const CUMULATION_ON_TOP = 'on_top';

    /** No tier price for a variant with a catalog promotion. */
    public const CUMULATION_SKIP_IF_PROMOTED = 'skip_if_promoted';

    public const CUMULATIONS = [self::CUMULATION_BEST_OF, self::CUMULATION_ON_TOP, self::CUMULATION_SKIP_IF_PROMOTED];

    public const MIN_QUANTITY = 2;

    /** Highest fixed unit price, in minor units (10,000,000.00): the column is a 32-bit integer. */
    public const MAX_AMOUNT = 1_000_000_000;

    public function getId(): ?int;

    public function getChannel(): ?ChannelInterface;

    public function setChannel(?ChannelInterface $channel): void;

    public function getCustomerGroup(): ?CustomerGroupInterface;

    public function setCustomerGroup(?CustomerGroupInterface $customerGroup): void;

    public function getScope(): string;

    public function setScope(string $scope): void;

    public function getProduct(): ?ProductInterface;

    public function setProduct(?ProductInterface $product): void;

    public function getVariant(): ?ProductVariantInterface;

    public function setVariant(?ProductVariantInterface $variant): void;

    public function getTaxon(): ?TaxonInterface;

    public function setTaxon(?TaxonInterface $taxon): void;

    public function getCumulation(): string;

    public function setCumulation(string $cumulation): void;

    /** The product of the rule, whatever its scope (null for a taxon rule). */
    public function getTargetProduct(): ?ProductInterface;

    public function getQuantityBasis(): string;

    public function setQuantityBasis(string $quantityBasis): void;

    public function getMinQuantity(): int;

    public function setMinQuantity(int $minQuantity): void;

    public function getType(): string;

    public function setType(string $type): void;

    public function getValue(): int;

    public function setValue(int $value): void;

    public function getStartsAt(): ?\DateTimeInterface;

    public function setStartsAt(?\DateTimeInterface $startsAt): void;

    public function getEndsAt(): ?\DateTimeInterface;

    public function setEndsAt(?\DateTimeInterface $endsAt): void;
}
