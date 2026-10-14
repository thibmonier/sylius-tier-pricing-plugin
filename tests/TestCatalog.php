<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusTierPricingPlugin;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTaxonInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Core\Model\TaxRateInterface;
use Sylius\Component\Customer\Model\CustomerGroupInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Component\Product\Factory\ProductFactoryInterface;
use Sylius\Component\Product\Factory\ProductVariantFactoryInterface;
use Sylius\Component\Taxation\Model\TaxCategoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Tmonier\SyliusTierPricingPlugin\Entity\TierPriceRuleInterface;
use Webmozart\Assert\Assert;

/**
 * Data created by the tests themselves (inside their rolled-back transaction), so that no test
 * depends on the random Sylius fixtures: products, rules, groups, tax rates, carts.
 */
final readonly class TestCatalog
{
    public function __construct(private ContainerInterface $container)
    {
    }

    public function channel(): ChannelInterface
    {
        $channel = $this->entityManager()->getRepository(ChannelInterface::class)->findOneBy(['hostname' => 'localhost']);
        if (!$channel instanceof ChannelInterface) {
            $channel = $this->entityManager()->getRepository(ChannelInterface::class)->findOneBy(['enabled' => true]);
        }
        Assert::isInstanceOf($channel, ChannelInterface::class);

        return $channel;
    }

    /**
     * A channel listed in `b2b_channels` of the test application (code TMONIER_B2B), reached
     * with the host b2b.tmonier.test, copying the locale, currency and tax zone of channel().
     */
    public function businessChannel(): ChannelInterface
    {
        $template = $this->channel();
        /** @var FactoryInterface<ChannelInterface> $factory */
        $factory = $this->container->get('sylius.factory.channel');
        $channel = $factory->createNew();
        $channel->setCode('TMONIER_B2B');
        $channel->setName('Tier B2B');
        $channel->setHostname('b2b.tmonier.test');
        $channel->setEnabled(true);
        $channel->setTaxCalculationStrategy($template->getTaxCalculationStrategy());
        $channel->setDefaultLocale($template->getDefaultLocale());
        foreach ($template->getLocales() as $locale) {
            $channel->addLocale($locale);
        }
        $channel->setBaseCurrency($template->getBaseCurrency());
        foreach ($template->getCurrencies() as $currency) {
            $channel->addCurrency($currency);
        }
        $channel->setDefaultTaxZone($template->getDefaultTaxZone());
        $channel->setMenuTaxon($template->getMenuTaxon());
        $this->entityManager()->persist($channel);
        $this->entityManager()->flush();

        return $channel;
    }

    /**
     * A new enabled product sold in the channel, one untracked variant per price.
     *
     * @param list<int> $prices
     */
    public function product(ChannelInterface $channel, array $prices = [1000], ?TaxCategoryInterface $taxCategory = null): ProductInterface
    {
        /** @var ProductFactoryInterface<ProductInterface> $productFactory */
        $productFactory = $this->container->get('sylius.factory.product');
        /** @var ProductVariantFactoryInterface<ProductVariantInterface> $variantFactory */
        $variantFactory = $this->container->get('sylius.factory.product_variant');
        /** @var FactoryInterface<ChannelPricingInterface> $pricingFactory */
        $pricingFactory = $this->container->get('sylius.factory.channel_pricing');
        $locale = (string) $channel->getDefaultLocale()?->getCode();
        $code = 'tmonier_tier_' . bin2hex(random_bytes(4));

        $product = $productFactory->createNew();
        $product->setCode($code);
        $product->setCurrentLocale($locale);
        $product->setFallbackLocale($locale);
        $product->setName('Tier product ' . $code);
        $product->setSlug($code);
        $product->setEnabled(true);
        $product->addChannel($channel);

        foreach ($prices as $index => $price) {
            $variant = $variantFactory->createForProduct($product);
            Assert::isInstanceOf($variant, ProductVariantInterface::class);
            $variant->setCode(sprintf('%s_v%d', $code, $index + 1));
            $variant->setCurrentLocale($locale);
            $variant->setFallbackLocale($locale);
            $variant->setName(sprintf('Variant %d', $index + 1));
            $variant->setEnabled(true);
            $variant->setTracked(false);
            $variant->setPosition($index);
            $variant->setTaxCategory($taxCategory);
            $pricing = $pricingFactory->createNew();
            $pricing->setChannelCode($channel->getCode());
            $pricing->setPrice($price);
            $pricing->setOriginalPrice($price);
            $variant->addChannelPricing($pricing);
            $product->addVariant($variant);
        }

        $this->entityManager()->persist($product);
        $this->entityManager()->flush();

        return $product;
    }

    public function variant(ProductInterface $product, int $index = 0): ProductVariantInterface
    {
        $variant = $product->getVariants()->get($index);
        Assert::isInstanceOf($variant, ProductVariantInterface::class);

        return $variant;
    }

    public function rule(
        ChannelInterface $channel,
        ProductInterface|ProductVariantInterface|TaxonInterface $target,
        int $minQuantity,
        string $type,
        int $value,
        ?CustomerGroupInterface $customerGroup = null,
        string $basis = TierPriceRuleInterface::BASIS_LINE,
        ?\DateTimeInterface $startsAt = null,
        ?\DateTimeInterface $endsAt = null,
        bool $enabled = true,
        string $cumulation = TierPriceRuleInterface::CUMULATION_BEST_OF,
    ): TierPriceRuleInterface {
        /** @var FactoryInterface<TierPriceRuleInterface> $factory */
        $factory = $this->container->get('tmonier_tier_pricing.factory.tier_price_rule');
        $rule = $factory->createNew();
        $rule->setChannel($channel);
        $rule->setCustomerGroup($customerGroup);
        if ($target instanceof TaxonInterface) {
            $rule->setScope(TierPriceRuleInterface::SCOPE_TAXON);
            $rule->setTaxon($target);
        } elseif ($target instanceof ProductVariantInterface) {
            $rule->setScope(TierPriceRuleInterface::SCOPE_VARIANT);
            $rule->setVariant($target);
        } else {
            $rule->setScope(TierPriceRuleInterface::SCOPE_PRODUCT);
            $rule->setProduct($target);
        }
        $rule->setQuantityBasis($basis);
        $rule->setMinQuantity($minQuantity);
        $rule->setType($type);
        $rule->setValue($value);
        $rule->setStartsAt($startsAt);
        $rule->setEndsAt($endsAt);
        $rule->setEnabled($enabled);
        $rule->setCumulation($cumulation);
        $this->entityManager()->persist($rule);
        $this->entityManager()->flush();

        return $rule;
    }

    /** A taxon of a new tree (root) or a child of `$parent`. */
    public function taxon(?TaxonInterface $parent = null): TaxonInterface
    {
        /** @var FactoryInterface<TaxonInterface> $factory */
        $factory = $this->container->get('sylius.factory.taxon');
        $taxon = $factory->createNew();
        $code = 'tmonier_taxon_' . bin2hex(random_bytes(4));
        $taxon->setCode($code);
        $taxon->setCurrentLocale('en_US');
        $taxon->setFallbackLocale('en_US');
        $taxon->setName('Taxon ' . $code);
        $taxon->setSlug($code);
        if ($parent instanceof \Sylius\Component\Core\Model\TaxonInterface) {
            $parent->addChild($taxon);
        }
        $this->entityManager()->persist($taxon);
        $this->entityManager()->flush();

        return $taxon;
    }

    /** Classifies the product in the taxons (the first one as main taxon when `$main`). */
    public function classify(ProductInterface $product, bool $main, TaxonInterface ...$taxons): void
    {
        /** @var FactoryInterface<ProductTaxonInterface> $factory */
        $factory = $this->container->get('sylius.factory.product_taxon');
        foreach ($taxons as $index => $taxon) {
            $productTaxon = $factory->createNew();
            $productTaxon->setProduct($product);
            $productTaxon->setTaxon($taxon);
            $productTaxon->setPosition($index);
            $product->addProductTaxon($productTaxon);
            if ($main && 0 === $index) {
                $product->setMainTaxon($taxon);
            }
        }
        $this->entityManager()->flush();
    }

    public function customerGroup(): CustomerGroupInterface
    {
        /** @var FactoryInterface<CustomerGroupInterface> $factory */
        $factory = $this->container->get('sylius.factory.customer_group');
        $group = $factory->createNew();
        $group->setCode('tmonier_group_' . bin2hex(random_bytes(4)));
        $group->setName('Tier group');
        $this->entityManager()->persist($group);
        $this->entityManager()->flush();

        return $group;
    }

    /** A tax category with one rate in the default tax zone of the channel. */
    public function taxCategory(ChannelInterface $channel, float $amount, bool $includedInPrice): TaxCategoryInterface
    {
        /** @var FactoryInterface<TaxCategoryInterface> $categoryFactory */
        $categoryFactory = $this->container->get('sylius.factory.tax_category');
        /** @var FactoryInterface<TaxRateInterface> $rateFactory */
        $rateFactory = $this->container->get('sylius.factory.tax_rate');
        $zone = $channel->getDefaultTaxZone();
        Assert::notNull($zone, 'The test channel needs a default tax zone.');

        $category = $categoryFactory->createNew();
        $category->setCode('tmonier_tax_' . bin2hex(random_bytes(4)));
        $category->setName('Tier tax');
        $rate = $rateFactory->createNew();
        $rate->setCode('tmonier_rate_' . bin2hex(random_bytes(4)));
        $rate->setName('Tier rate');
        $rate->setAmount($amount);
        $rate->setIncludedInPrice($includedInPrice);
        $rate->setCalculator('default');
        $rate->setZone($zone);
        $rate->setCategory($category);
        $this->entityManager()->persist($category);
        $this->entityManager()->persist($rate);
        $this->entityManager()->flush();

        return $category;
    }

    /**
     * A processed cart of the channel.
     *
     * @param list<array{ProductVariantInterface, int}> $lines
     */
    public function cart(ChannelInterface $channel, array $lines, ?CustomerInterface $customer = null, bool $flush = true): OrderInterface
    {
        /** @var FactoryInterface<OrderInterface> $orderFactory */
        $orderFactory = $this->container->get('sylius.factory.order');
        /** @var FactoryInterface<OrderItemInterface> $itemFactory */
        $itemFactory = $this->container->get('sylius.factory.order_item');
        /** @var OrderItemQuantityModifierInterface $quantityModifier */
        $quantityModifier = $this->container->get('sylius.modifier.order_item_quantity');

        $order = $orderFactory->createNew();
        $order->setChannel($channel);
        $order->setLocaleCode((string) $channel->getDefaultLocale()?->getCode());
        $order->setCurrencyCode((string) $channel->getBaseCurrency()?->getCode());
        // "With authorization": a signed-in customer (found by the cart context, gets group
        // prices); without customer the cart is a guest cart.
        if ($customer instanceof \Sylius\Component\Core\Model\CustomerInterface) {
            $order->setCustomerWithAuthorization($customer);
        }
        foreach ($lines as [$variant, $quantity]) {
            $item = $itemFactory->createNew();
            $item->setVariant($variant);
            $quantityModifier->modify($item, $quantity);
            $order->addItem($item);
        }
        $this->process($order);
        if ($flush) {
            $this->entityManager()->persist($order);
            $this->entityManager()->flush();
        }

        return $order;
    }

    public function changeQuantity(OrderInterface $order, OrderItemInterface $item, int $quantity): void
    {
        /** @var OrderItemQuantityModifierInterface $quantityModifier */
        $quantityModifier = $this->container->get('sylius.modifier.order_item_quantity');
        $quantityModifier->modify($item, $quantity);
        $this->process($order);
    }

    public function process(OrderInterface $order): void
    {
        /** @var OrderProcessorInterface $processor */
        $processor = $this->container->get('sylius.order_processing.order_processor');
        $processor->process($order);
    }

    public function item(OrderInterface $order, ProductVariantInterface $variant): OrderItemInterface
    {
        foreach ($order->getItems() as $item) {
            if ($item instanceof OrderItemInterface && $item->getVariant() === $variant) {
                return $item;
            }
        }

        throw new \LogicException('No item for this variant.');
    }

    public function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->container->get('doctrine.orm.entity_manager');
        Assert::isInstanceOf($entityManager, EntityManagerInterface::class);

        return $entityManager;
    }
}
