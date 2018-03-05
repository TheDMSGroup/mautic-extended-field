<?php
/**
 * Created scottshipman
 *  OVERRIDES the service from the lead bundle :
 *  'mautic.lead.model.list' => [
 *  'class'     => 'Mautic\LeadBundle\Model\ListModel',
 *    'arguments' => [
 *    'mautic.helper.core_parameters',
 *     ],
 *   ],.
 */

namespace MauticPlugin\MauticExtendedFieldBundle\DependencyInjection\Compiler;

use MauticPlugin\MauticExtendedFieldBundle\Model\OverrideListModel;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class OverrideListModelPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        $definition = $container->getDefinition('mautic.lead.model.list');
        $definition->setClass(OverrideListModel::class);
    }
}
