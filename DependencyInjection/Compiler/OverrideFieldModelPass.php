<?php
/**
 * Created scottshipman
 *  OVERRIDES the service from the lead bundle :
 *  'mautic.lead.model.field' => [
 *   'class'     => 'Mautic\LeadBundle\Model\FieldModel',
 *    'arguments' => [
 *        'mautic.schema.helper.factory',
 *     ],
 *    ],
 */

namespace MauticPlugin\MauticExtendedFieldBundle\DependencyInjection\Compiler;

use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\MauticExtendedFieldBundle\Model\ExtendedFieldModel;
use MauticPlugin\MauticExtendedFieldBundle\Model\OverrideLeadModel;
use MauticPlugin\MauticExtendedFieldBundle\Entity\OverrideLeadRepository;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class OverrideFieldModelPass implements CompilerPassInterface
{
  public function process(ContainerBuilder $container)
  {
    $definition = $container->getDefinition('mautic.lead.model.field');
    $definition->setClass(ExtendedFieldModel::class);

    $definition2 = $container->getDefinition('mautic.form.type.leadfield');
    $definition2->setClass(ExtendedFieldModel::class);

    $definition3 = $container->getDefinition('mautic.lead.model.lead');
    $definition3->setClass(OverrideLeadModel::class);

    $definition3 = $container->getDefinition('mautic.lead.repository.lead');
    $definition3->setClass(OverrideLeadRepository::class);
  }

}