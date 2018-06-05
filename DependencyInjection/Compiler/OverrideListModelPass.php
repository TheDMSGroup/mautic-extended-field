<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticExtendedFieldBundle\DependencyInjection\Compiler;

use MauticPlugin\MauticExtendedFieldBundle\Model\OverrideListModel;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Class OverrideListModelPass.
 */
class OverrideListModelPass implements CompilerPassInterface
{
    /**
     * @param ContainerBuilder $container
     */
    public function process(ContainerBuilder $container)
    {
        /** @var \Mautic\LeadBundle\Model\ListModel $definition */
        $definition = $container->getDefinition('mautic.lead.model.list');
        $definition->setClass(OverrideListModel::class);
    }
}
