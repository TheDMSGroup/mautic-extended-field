<?php

/*
 * @copyright   2016 Mautic, Inc. All rights reserved
 * @author      Mautic, Inc
 *
 * @link        https://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

use Mautic\LeadBundle\Form\Type\FieldType;
use Mautic\LeadBundle\Form\Type\LeadType;
use Mautic\LeadBundle\Form\Type\ListType;
use Mautic\LeadBundle\Form\Type\UpdateLeadActionType;
use MauticPlugin\MauticExtendedFieldBundle\EventListener\ConfigSubscriber;

return [
    'name'        => 'Extended Fields',
    'description' => 'Extend Mautic custom fields for scalability and HIPAA/PCI compliance.',
    'version'     => '2.12',
    'author'      => 'Mautic',
    'parameters'  => [
        // set default to block creation of lead table columns
        'disable_lead_table_fields' => 1,
    ],
    'services'    => [
        'events' => [
            'mautic.extended_field.config.subscriber' => [
                'class' => ConfigSubscriber::class,
            ],
        ],
        'forms'  => [
            'mautic.extended_field.form.config' => [
                'class' => 'MauticPlugin\MauticExtendedFieldBundle\Form\ConfigType',
                'alias' => 'extendedField_config',
            ],
        ],
        'other'  => [
            // Form extensions
            'mautic.form.extension.updatelead_action' => [
                'class'        => 'MauticPlugin\MauticExtendedFieldBundle\Form\UpdateLeadActionExtension',
                'arguments'    => ['mautic.factory'],
                'tag'          => 'form.type_extension',
                'tagArguments' => [
                    'extended_type' => UpdateLeadActionType::class,
                ],
            ],
            'mautic.form.extension.extended_field'    => [
                'class'        => 'MauticPlugin\MauticExtendedFieldBundle\Form\ExtendedFieldExtension',
                'arguments'    => ['mautic.factory'],
                'tag'          => 'form.type_extension',
                'tagArguments' => [
                    'extended_type' => FieldType::class,
                ],
            ],
            'mautic.form.extension.extended_lead'     => [
                'class'        => 'MauticPlugin\MauticExtendedFieldBundle\Form\LeadTypeExtension',
                'arguments'    => ['mautic.factory', 'mautic.lead.model.company'],
                'tag'          => 'form.type_extension',
                'tagArguments' => [
                    'extended_type' => LeadType::class,
                ],
            ],
            'mautic.form.extension.extended_list'     => [
                'class'        => 'MauticPlugin\MauticExtendedFieldBundle\Form\ListTypeExtension',
                'arguments'    => [
                    'translator',
                    'mautic.lead.model.list',
                    'mautic.email.model.email',
                    'mautic.security',
                    'mautic.lead.model.lead',
                    'mautic.stage.model.stage',
                    'mautic.category.model.category',
                    'mautic.helper.user',
                ],
                'tag'          => 'form.type_extension',
                'tagArguments' => [
                    'extended_type' => ListType::class,
                ],
            ],
        ],
        'models' => [
            'mautic.lead.model.extended_field' => [
                'class'     => 'MauticPlugin\MauticExtendedFieldBundle\Model\ExtendedFieldModel',
                'arguments' => [
                    'mautic.lead.model.field',
                ],
            ],
        ],
    ],
];
