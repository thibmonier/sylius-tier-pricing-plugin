<?php

declare(strict_types=1);

namespace Tmonier\SyliusTierPricingPlugin\Pricing;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Customer\Model\CustomerGroupInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Contracts\Service\ResetInterface;
use Tmonier\SyliusTierPricingPlugin\Repository\TierPriceRuleRepositoryInterface;

/**
 * Rules of a product per channel and customer group, cached with the tags product, channel and
 * group (a rule change invalidates its product, see TierPriceCacheInvalidator) and memoized for
 * the request (the order processor runs several times per request).
 */
final class CachedTierPriceRuleProvider implements TierPriceRuleProviderInterface, ResetInterface
{
    private const ALL_TAG = 'tmonier_tier_pricing.all';

    /** @var array<string, list<TierPriceRuleData>> */
    private array $memo = [];

    public function __construct(
        private readonly TierPriceRuleRepositoryInterface $repository,
        private readonly TagAwareCacheInterface $cache,
        private readonly int $ttl,
    ) {
    }

    public function getRules(ChannelInterface $channel, ProductInterface $product, ?CustomerGroupInterface $customerGroup): array
    {
        $productId = $product->getId();
        $channelId = $channel->getId();
        if (null === $productId || null === $channelId) {
            return [];
        }
        $groupId = $customerGroup?->getId();
        $key = sprintf('tmonier_tier_pricing.rules.%d.%d.%s', $channelId, (int) $productId, null === $groupId ? 'all' : (string) $groupId);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        if (0 === $this->ttl) {
            return $this->memo[$key] = $this->repository->findCandidateData($channel, $product, $customerGroup);
        }

        /** @var list<TierPriceRuleData> $rules */
        $rules = $this->cache->get($key, function (ItemInterface $item) use ($channel, $product, $customerGroup, $channelId, $productId, $groupId): array {
            $item->expiresAfter($this->ttl);
            $tags = [self::ALL_TAG, self::productTag((int) $productId), 'tmonier_tier_pricing.channel_' . $channelId];
            if (null !== $groupId) {
                $tags[] = 'tmonier_tier_pricing.group_' . $groupId;
            }
            $item->tag($tags);

            return $this->repository->findCandidateData($channel, $product, $customerGroup);
        });

        return $this->memo[$key] = $rules;
    }

    public function invalidateProducts(int ...$productIds): void
    {
        $this->memo = [];
        if ([] === $productIds) {
            return;
        }
        $this->cache->invalidateTags(array_map(self::productTag(...), array_values(array_unique($productIds))));
    }

    public function invalidateAll(): void
    {
        $this->memo = [];
        $this->cache->invalidateTags([self::ALL_TAG]);
    }

    public function reset(): void
    {
        $this->memo = [];
    }

    public static function productTag(int $productId): string
    {
        return 'tmonier_tier_pricing.product_' . $productId;
    }
}
