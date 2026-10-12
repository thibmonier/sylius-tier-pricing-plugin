<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Tests\Tmonier\SyliusTierPricingPlugin\Exemption\ExemptVariantChecker;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('tmonier_tier_pricing_test.exemption_checker', ExemptVariantChecker::class)
        ->tag('tmonier_tier_pricing.exemption_checker')
    ;
};
