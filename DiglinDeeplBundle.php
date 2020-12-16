<?php
/**
 * Diglin GmbH - Switzerland.
 *
 * @author      Sylvain Rayé <support at diglin.com>
 * @category    DigitalDrink - OroCommerce
 * @copyright   2020 - Diglin (https://www.diglin.com)
 */

namespace Diglin\Bundle\DeeplBundle;

use Diglin\Bundle\DeeplBundle\DependencyInjection\DiglinDeeplExtension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class DiglinDeeplBundle extends Bundle
{
    public function getContainerExtension()
    {
        return new DiglinDeeplExtension();
    }
}
