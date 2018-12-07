<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Digital Media Solutions, LLC
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

return [
    'name'        => 'Extended Fields',
    'description' => 'Extends custom fields for scalability and HIPAA/PCI compliance.',
    'version'     => '2.15',
    'author'      => 'Mautic',
    'parameters'  => [
        // set default to block creation of lead table columns
        'disable_lead_table_fields' => 1,
    ],
    'services'    => [
        'events' => [
            'mautic.extended_field.config.subscriber' => [
                'class' => 'MauticPlugin\MauticExtendedFieldBundle\EventListener\ConfigSubscriber',
            ],
            'mautic.extended_field.report.subscriber' => [
                'class'     => 'MauticPlugin\MauticExtendedFieldBundle\EventListener\ReportSubscriber',
                'arguments' => [
                    'mautic.lead.reportbundle.fields_builder',
                ],
            ],
            'mautic.extended_field.lead_subscriber'   => [
                'class'     => 'MauticPlugin\MauticExtendedFieldBundle\EventListener\LeadSubscriber',
                'arguments' => [
                    'mautic.lead.model.field',
                ],
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
                    'extended_type' => 'Mautic\LeadBundle\Form\Type\UpdateLeadActionType',
                ],
            ],
            'mautic.form.extension.extended_field'    => [
                'class'        => 'MauticPlugin\MauticExtendedFieldBundle\Form\ExtendedFieldExtension',
                'arguments'    => ['mautic.factory'],
                'tag'          => 'form.type_extension',
                'tagArguments' => [
                    'extended_type' => 'Mautic\LeadBundle\Form\Type\FieldType',
                ],
            ],
            'mautic.form.extension.extended_lead'     => [
                'class'        => 'MauticPlugin\MauticExtendedFieldBundle\Form\LeadTypeExtension',
                'arguments'    => ['mautic.factory', 'mautic.lead.model.company'],
                'tag'          => 'form.type_extension',
                'tagArguments' => [
                    'extended_type' => 'Mautic\LeadBundle\Form\Type\LeadType',
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
                    'extended_type' => 'Mautic\LeadBundle\Form\Type\ListType',
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
