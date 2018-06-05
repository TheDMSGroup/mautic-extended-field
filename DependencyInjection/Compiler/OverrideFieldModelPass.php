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
     */
    public function process(ContainerBuilder $container)
    {
        /** @var \Mautic\FormBundle\Model\FieldModel $definition */
        $definition = $container->getDefinition('mautic.lead.model.field');
        $definition->setClass(ExtendedFieldModel::class);

        /** @var \Mautic\LeadBundle\Model\LeadModel $definition */
        $definition = $container->getDefinition('mautic.lead.model.lead');
        $definition->setClass(OverrideLeadModel::class);

        /** @var \Doctrine\ORM\EntityRepository $definition */
        $definition = $container->getDefinition('mautic.lead.repository.lead');
        $definition->setClass(OverrideLeadRepository::class);
    }
}
