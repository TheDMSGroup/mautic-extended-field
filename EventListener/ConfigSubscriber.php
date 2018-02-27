<?php

namespace MauticPlugin\MauticExtendedFieldBundle\EventListener;

use Mautic\ConfigBundle\Event\ConfigEvent;
use Mautic\CoreBundle\EventListener\CommonSubscriber;
use Mautic\ConfigBundle\ConfigEvents;
use Mautic\ConfigBundle\Event\ConfigBuilderEvent;


/**
 * Class ConfigSubscriber
 */
class ConfigSubscriber extends CommonSubscriber
{

    /**
     * @return array
     */
    static public function getSubscribedEvents()
    {return array(
            ConfigEvents::CONFIG_ON_GENERATE => array('onConfigGenerate', 0),
        );
    }

    /**
     * @param ConfigBuilderEvent $event
     */
    public function onConfigGenerate(ConfigBuilderEvent $event)
    {
        $params = !empty($event->getParametersFromConfig('MauticExtendedFieldBundle')) ? $event->getParametersFromConfig('MauticExtendedFieldBundle') : array();
        $event->addForm(
            array(
                'bundle'     => "MauticExtendedFieldBundle",
                'formAlias'  => 'extendedField_config',
                'formTheme'  => 'MauticExtendedFieldBundle:Config',
                'parameters' => $params,
            )
        );
    }

}