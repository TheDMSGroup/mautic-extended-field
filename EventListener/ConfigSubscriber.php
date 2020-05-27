<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Digital Media Solutions, LLC
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticExtendedFieldBundle\EventListener;

use Mautic\ConfigBundle\ConfigEvents;
use Mautic\ConfigBundle\Event\ConfigBuilderEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use MauticPlugin\MauticExtendedFieldBundle\Form\ConfigType;

/**
 * Class ConfigSubscriber.
 */
class ConfigSubscriber implements EventSubscriberInterface
{
    /**
     * @var
     */
    protected $event;

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        $eventList = [
            ConfigEvents::CONFIG_ON_GENERATE       => ['onConfigGenerate', 0],
        ];

        return $eventList;
    }

    /**
     * @param ConfigBuilderEvent $event
     */
    public function onConfigGenerate(ConfigBuilderEvent $event)
    {
        $params = !empty(
        $event->getParametersFromConfig(
            'MauticExtendedFieldBundle'
        )
        ) ? $event->getParametersFromConfig('MauticExtendedFieldBundle') : [];
        $event->addForm(
            [
                'bundle'     => 'MauticExtendedFieldBundle',
                'formAlias'  => 'extendedField_config',
                'formTheme'  => 'MauticExtendedFieldBundle:Config',
                'formType'   => ConfigType::class,
                'parameters' => $params,
            ]
        );
    }
}
