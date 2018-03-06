<?php
/**
 * Mautic Extended Field Form Extension
 * Created by Scott Shipman.
 *
 * Date: 1/30/18
 *
 * Updates the Mautic Lead Bundle FieldType.php for Object field choice values
 */

namespace MauticPlugin\MauticExtendedFieldBundle\Form;

use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\LeadBundle\Form\Type\FieldType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;

class ExtendedFieldExtension extends AbstractTypeExtension
{
    protected $coreParameters;

    public function __construct(MauticFactory $factory)
    {
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
        // change default option to "extendedField" from "lead" when plugin is enabled and config set
        $no_lead_table = $this->coreParameters->getParameter('disable_lead_table_fields', false);
        if ($no_lead_table) {
            $options['data']->setObject('extendedField');
        }

        $disabled = (!empty($options['data'])) ? $options['data']->isFixed() : false;
        $new      = (!empty($options['data']) && $options['data']->getAlias()) ? false : true;
        $builder->add(
            'object',
            'choice',
            [
                'choices'           => [
                    'mautic.lead.contact'             => 'lead',
                    'mautic.company.company'          => 'company',
                    'mautic.lead.extendedField'       => 'extendedField',
                    'mautic.lead.extendedFieldSecure' => 'extendedFieldSecure',
                ],
                'choices_as_values' => true,
                'choice_attr'       => function ($key, $val, $index) use ($no_lead_table) {
                    // set "Contact" option disabled to true based on key or index of the choice.
                    if ('lead' === $key && $no_lead_table) {
                        return ['disabled' => 'disabled'];
                    } else {
                        return [];
                    }
                },
                'expanded'          => false,
                'multiple'          => false,
                'label'             => 'mautic.lead.field.object',
                'empty_value'       => false,
                'attr'              => [
                    'class' => 'form-control',
                ],
                'required'          => false,
                'disabled'          => ($disabled || !$new),
            ]
        );
    }
}
