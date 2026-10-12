<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Entity;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Customer\Model\CustomerGroupInterface;
use Sylius\Resource\Model\ResourceInterface;
use Sylius\Resource\Model\TimestampableInterface;
use Sylius\Resource\Model\ToggleableInterface;

/**
 * A quantity price break: from `minQuantity` units, the unit price of the target (a variant or
 * every variant of a product) becomes a fixed price or the regular price minus a percentage, in
 * one channel, optionally for one customer group only.
 */
interface TierPriceRuleInterface extends ResourceInterface, TimestampableInterface, ToggleableInterface
{
    public const SCOPE_VARIANT = 'variant';

    public const SCOPE_PRODUCT = 'product';

    public const SCOPES = [self::SCOPE_VARIANT, self::SCOPE_PRODUCT];

    /** The quantity of the cart line (one variant). */
    public const BASIS_LINE = 'line';

    /** The quantity of every line of the same product (mix and match of its variants). */
    public const BASIS_PRODUCT = 'product';

    public const BASES = [self::BASIS_LINE, self::BASIS_PRODUCT];

    /** `value` is a unit price in the channel base currency, in minor units (cents). */
    public const TYPE_FIXED_UNIT_PRICE = 'fixed_unit_price';

    /** `value` is a percentage off the regular price, in basis points (1000 = 10 %). */
    public const TYPE_PERCENTAGE_OFF = 'percentage_off';

    public const TYPES = [self::TYPE_FIXED_UNIT_PRICE, self::TYPE_PERCENTAGE_OFF];

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

    /** The product of the rule, whatever its scope. */
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
