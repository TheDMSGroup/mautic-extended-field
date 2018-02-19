<?php

/*
 * @copyright   Mautic, Inc. All rights reserved
 * @author      Mautic, Inc
 *
 * @link        https://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticExtendedFieldBundle;

use Mautic\PluginBundle\Bundle\PluginBundleBase;
use MauticPlugin\MauticExtendedFieldBundle\DependencyInjection\Compiler\OverrideFieldModelPass;
use MauticPlugin\MauticExtendedFieldBundle\DependencyInjection\Compiler\OverrideListModelPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Mautic\LeadBundle;

class MauticExtendedFieldBundle extends PluginBundleBase
{
/*
 * Implements Compiler Passes to Override the Lead Bundle FieldModel
 * https://symfony.com/doc/2.8/service_container/compiler_passes.html
 *
 * allows to add a custom extendedField object value
 */

  public function build(ContainerBuilder $container)
  {
    parent::build($container);

    $container->addCompilerPass(new OverrideFieldModelPass());
    $container->addCompilerPass(new OverrideListModelPass());
  }

}

