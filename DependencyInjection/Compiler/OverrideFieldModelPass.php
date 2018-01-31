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
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class OverrideFieldModelPass implements CompilerPassInterface
{
  public function process(ContainerBuilder $container)
  {
    $definition = $container->getDefinition('mautic.lead.model.field');
    $definition->setClass(ExtendedFieldModel::class);
  }
}