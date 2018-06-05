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

use MauticPlugin\MauticExtendedFieldBundle\Entity\OverrideLeadRepository;
use MauticPlugin\MauticExtendedFieldBundle\Model\ExtendedFieldModel;
use MauticPlugin\MauticExtendedFieldBundle\Model\OverrideLeadModel;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Class OverrideFieldModelPass.
 */
class OverrideFieldModelPass implements CompilerPassInterface
{
    /**
     * @param ContainerBuilder $container
     *
     * OVERRIDES the service from the lead bundle :
     * 'mautic.lead.model.field' => [
     *  'class'     => 'Mautic\LeadBundle\Model\FieldModel',
     *   'arguments' => [
     *      'mautic.schema.helper.factory',
     *    ],
     *  ],
     */
    public function process(ContainerBuilder $container)
    {
        $definition = $container->getDefinition('mautic.lead.model.field');
        $definition->setClass(ExtendedFieldModel::class);

        $definition3 = $container->getDefinition('mautic.lead.model.lead');
        $definition3->setClass(OverrideLeadModel::class);

        $definition4 = $container->getDefinition('mautic.lead.repository.lead');
        $definition4->setClass(OverrideLeadRepository::class);
    }
}
