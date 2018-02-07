<?php

/*
 * @copyright   2016 Mautic, Inc. All rights reserved
 * @author      Mautic, Inc
 *
 * @link        https://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
use MauticPlugin\MauticExtendedFieldBundle\ExtendedFieldExtension;
use Mautic\LeadBundle\Form\Type\FieldType;
use Mautic\LeadBundle\Form\Type\LeadType;
use Symfony\Component\DependencyInjection\ContainerBuilde;

return [
    'name'        => 'Extended Fields',
    'description' => 'Extend Mautic custom fields for scalability and HIPAA/PCI compliance.',
    'version'     => '0.1',
    'author'      => 'Mautic',
    'services'   => [
      'other' => [
        // Form extensions
        'mautic.form.extension.extended_field' => [
          'class'     => 'MauticPlugin\MauticExtendedFieldBundle\Form\ExtendedFieldExtension',
          'tag'          => 'form.type_extension',
          'tagArguments' => [
            'extended_type' => FieldType::class,
          ],
        ],
        'mautic.form.extension.extended_lead' => [
          'class'     => 'MauticPlugin\MauticExtendedFieldBundle\Form\LeadTypeExtension',
          'arguments' => ['mautic.factory', 'mautic.lead.model.company'],
          'tag'          => 'form.type_extension',
          'tagArguments' => [
            'extended_type' => LeadType::class,
          ],
        ],
      ],
       'models'   => [
         'mautic.lead.model.extended_field' => [
           'class'     => 'MauticPlugin\MauticExtendedFieldBundle\Model\ExtendedFieldModel',
           'arguments' => [
             'mautic.lead.model.field',
           ],
         ],
       ]
    ]
];
