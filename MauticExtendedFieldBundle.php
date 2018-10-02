<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Digital Media Solutions, LLC
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticExtendedFieldBundle;

use Mautic\PluginBundle\Bundle\PluginBundleBase;
use MauticPlugin\MauticExtendedFieldBundle\DependencyInjection\Compiler\OverrideCompanyModelPass;
use MauticPlugin\MauticExtendedFieldBundle\DependencyInjection\Compiler\OverrideFieldModelPass;
use MauticPlugin\MauticExtendedFieldBundle\DependencyInjection\Compiler\OverrideListModelPass;
use MauticPlugin\MauticExtendedFieldBundle\DependencyInjection\Compiler\OverrideTableSchemaColumnsCachePass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Class MauticExtendedFieldBundle.
 *
 * Implements Compiler Passes to Override the Lead Bundle FieldModel
 * https://symfony.com/doc/2.8/service_container/compiler_passes.html
 * allows to add a custom extendedField object value.
 */
class MauticExtendedFieldBundle extends PluginBundleBase
{
    /**
     * @param ContainerBuilder $container
     */
    public function build(ContainerBuilder $container)
    {
        $container->addCompilerPass(new OverrideFieldModelPass());
        $container->addCompilerPass(new OverrideListModelPass());
        $container->addCompilerPass(new OverrideTableSchemaColumnsCachePass());
        $container->addCompilerPass(new OverrideCompanyModelPass());

        parent::build($container);
    }
}
