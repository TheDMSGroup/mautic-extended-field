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

use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\LeadBundle\Form\Type\FieldType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Class ExtendedFieldExtension.
 *
 * Updates the Mautic Lead Bundle FieldType.php for Object field choice values.
 */
class ExtendedFieldExtension extends AbstractTypeExtension
{
    /** @var CoreParametersHelper */
    protected $coreParameters;

    public function __construct(MauticFactory $factory)
    {
        /* @var CoreParametersHelper coreParameters */
        $this->coreParameters = $factory->getDispatcher()->getContainer()->get('mautic.helper.core_parameters');
    }

    /**
     * Returns the name of the type being extended.
     *
     * @return string The name of the type being extended
     */
    public function getExtendedType()
    {
        // use FormType::class to modify (nearly) every field in the system
        return FieldType::class;
    }

    /**
     * Add a custom 'object' type to write to a corresponding table for that new custom value.
     *
     * @param FormBuilderInterface $builder
     * @param array                $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $new      = !empty($options['data']) && $options['data']->getAlias() ? false : true;

        // change default option to "extendedField" from "lead" when plugin is enabled and config set
        $disallowLead = $this->coreParameters->getParameter('disable_lead_table_fields', false);
        if ($disallowLead && $new) {
            $options['data']->setObject('extendedField');
        }
        $disabled = !empty($options['data']) ? $options['data']->isFixed() : false;

        $builder->add(
            'object',
            ChoiceType::class,
            [
                'choices'           => [
                    'mautic.lead.contact'             => 'lead',
                    'mautic.company.company'          => 'company',
                    'mautic.lead.extendedField'       => 'extendedField',
                    'mautic.lead.extendedFieldSecure' => 'extendedFieldSecure',
                ],
                'choices_as_values' => true,
                'choice_attr'       => function ($key) use ($disallowLead) {
                    // set "Contact" option disabled to true based on key or index of the choice.
                    if ('lead' === $key && $disallowLead) {
                        return ['disabled' => 'disabled'];
                    } else {
                        return [];
                    }
                },
                'expanded'          => false,
                'multiple'          => false,
                'label'             => 'mautic.lead.field.object',
                'placeholder'       => false,
                'attr'              => [
                    'class' => 'form-control',
                ],
                'required'          => false,
                'disabled'          => ($disabled || !$new),
                'data'              => $options['data']->getObject(),
            ]
        );

        // Add a bunch more 'custom' groups beside the original 4
        $builder->add(
            'group',
            ChoiceType::class,
            [
                'choices' => [
                    'core'         => 'core', // Personally Identifiable
                    'auto'         => 'auto',
                    'client'       => 'client',
                    'consent'      => 'consent',
                    'education'    => 'education',
                    'enhancement'  => 'enhancement',
                    'finance'      => 'finance',
                    'personal'     => 'personal', // Health
                    'home'         => 'home',
                    'politics'     => 'politics',
                    'professional' => 'professional',
                    'social'       => 'social',
                    'system'       => 'system',
                ],
                'attr' => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.lead.field.form.group.help',
                ],
                'expanded'    => false,
                'multiple'    => false,
                'label'       => 'mautic.lead.field.group',
                'placeholder' => false,
                'required'    => false,
                'disabled'    => $disabled,
            ]
        );
    }
}
