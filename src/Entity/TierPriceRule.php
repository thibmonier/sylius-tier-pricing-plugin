<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Entity;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Customer\Model\CustomerGroupInterface;
use Sylius\Resource\Model\TimestampableTrait;
use Sylius\Resource\Model\ToggleableTrait;

class TierPriceRule implements TierPriceRuleInterface
{
    use TimestampableTrait;
    use ToggleableTrait;

    protected ?int $id = null;

    protected ?ChannelInterface $channel = null;

    protected ?CustomerGroupInterface $customerGroup = null;

    protected string $scope = self::SCOPE_PRODUCT;

    protected ?ProductInterface $product = null;

    protected ?ProductVariantInterface $variant = null;

    protected ?TaxonInterface $taxon = null;

    protected string $cumulation = self::CUMULATION_BEST_OF;

    protected string $quantityBasis = self::BASIS_LINE;

    protected int $minQuantity = 10;

    protected string $type = self::TYPE_PERCENTAGE_OFF;

    protected int $value = 0;

    protected ?\DateTimeInterface $startsAt = null;

    protected ?\DateTimeInterface $endsAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChannel(): ?ChannelInterface
    {
        return $this->channel;
    }

    public function setChannel(?ChannelInterface $channel): void
    {
        $this->channel = $channel;
    }

    public function getCustomerGroup(): ?CustomerGroupInterface
    {
        return $this->customerGroup;
    }

    public function setCustomerGroup(?CustomerGroupInterface $customerGroup): void
    {
        $this->customerGroup = $customerGroup;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function setScope(string $scope): void
    {
        $this->scope = $scope;
    }

    public function getProduct(): ?ProductInterface
    {
        return $this->product;
    }

    public function setProduct(?ProductInterface $product): void
    {
        $this->product = $product;
    }

    public function getVariant(): ?ProductVariantInterface
    {
        return $this->variant;
    }

    public function setVariant(?ProductVariantInterface $variant): void
    {
        $this->variant = $variant;
    }

    public function getTaxon(): ?TaxonInterface
    {
        return $this->taxon;
    }

    public function setTaxon(?TaxonInterface $taxon): void
    {
        $this->taxon = $taxon;
    }

    public function getCumulation(): string
    {
        return $this->cumulation;
    }

    public function setCumulation(string $cumulation): void
    {
        $this->cumulation = $cumulation;
    }

    public function getTargetProduct(): ?ProductInterface
    {
        if (self::SCOPE_TAXON === $this->scope) {
            return null;
        }
        if (self::SCOPE_VARIANT === $this->scope) {
            $product = $this->variant?->getProduct();

            return $product instanceof ProductInterface ? $product : null;
        }

        return $this->product;
    }

    public function getQuantityBasis(): string
    {
        return $this->quantityBasis;
    }

    public function setQuantityBasis(string $quantityBasis): void
    {
        $this->quantityBasis = $quantityBasis;
    }

    public function getMinQuantity(): int
    {
        return $this->minQuantity;
    }

    public function setMinQuantity(int $minQuantity): void
    {
        $this->minQuantity = $minQuantity;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function setValue(int $value): void
    {
        $this->value = $value;
    }

    public function getStartsAt(): ?\DateTimeInterface
    {
        return $this->startsAt;
    }

    public function setStartsAt(?\DateTimeInterface $startsAt): void
    {
        $this->startsAt = self::inApplicationTimezone($startsAt);
    }

    public function getEndsAt(): ?\DateTimeInterface
    {
        return $this->endsAt;
    }

    public function setEndsAt(?\DateTimeInterface $endsAt): void
    {
        $this->endsAt = self::inApplicationTimezone($endsAt);
    }

    /**
     * The `datetime` columns store the wall time without offset and are read back in the PHP
     * default timezone: a date given with another offset (CSV, API) is converted first, so that
     * it keeps its instant.
     */
    private static function inApplicationTimezone(?\DateTimeInterface $date): ?\DateTimeInterface
    {
        if (null === $date) {
            return null;
        }
        $timezone = date_default_timezone_get();
        if ($date instanceof \DateTime && $date->getTimezone()->getName() === $timezone) {
            return $date;
        }

        return \DateTime::createFromInterface($date)->setTimezone(new \DateTimeZone($timezone));
    }
}
