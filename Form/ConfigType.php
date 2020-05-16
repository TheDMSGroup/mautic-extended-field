<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Digital Media Solutions, LLC
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticExtendedFieldBundle\Form;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Class ConfigType.
 */
class ConfigType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array                $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add(
            'disable_lead_table_fields',
            YesNoButtonGroupType::class,
            [
                'label' => 'mautic.extendedField.disable_lead_table_fields',
                'data'  => $options['data']['disable_lead_table_fields'],
                'attr'  => [
                    'tooltip' => 'mautic.form.config.disable_lead_table_fields',
                ],
            ]
        );
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'extendedField_config';
    }
}
