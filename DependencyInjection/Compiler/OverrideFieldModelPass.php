<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Digital Media Solutions, LLC
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
use Symfony\Component\DependencyInjection\Reference;

/**
 * Class OverrideFieldModelPass.
 */
class OverrideFieldModelPass implements CompilerPassInterface
{
    /**
     * @param ContainerBuilder $container
     */
    public function process(ContainerBuilder $container)
    {
        $container->getDefinition('mautic.lead.model.field')
            ->setFactory(null)
            ->setClass(ExtendedFieldModel::class);

        $container->getDefinition('mautic.lead.model.lead')
            ->setFactory(null)
            ->setClass(OverrideLeadModel::class);

        $container->getDefinition('mautic.lead.repository.lead')
            ->setFactory(null)
            ->setArguments(
                [
                    new Reference('doctrine.orm.entity_manager'),
                    null,
                    new Reference('mautic.lead.model.field'),
                ]
            )
            ->setClass(OverrideLeadRepository::class);
    }
}
