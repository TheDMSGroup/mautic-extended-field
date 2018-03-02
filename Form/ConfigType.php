<?php


namespace MauticPlugin\MauticExtendedFieldBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Class ConfigType
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
            'yesno_button_group',
            array(
                'label' => 'mautic.extendedField.disable_lead_table_fields',
                'data'  => $options['data']['disable_lead_table_fields'],
                'attr'  => array(
                    'tooltip' => 'mautic.form.config.disable_lead_table_fields'
                )
            )
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'extendedField_config';
    }
}