<?php
/**
 * Mautic Extended Field Form Extension
 * Created by Scott Shipman
 *
 * Date: 1/30/18
 *
 * Updates the Mautic Lead Bundle FieldType.php for Object field choice values

 */

namespace MauticPlugin\MauticExtendedFieldBundle\Form;

use Symfony\Component\Form\AbstractTypeExtension;
use Mautic\LeadBundle\Form\Type\FieldType;
use Symfony\Component\Form\ChoiceList\View\ChoiceView;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormBuilderInterface;

class ExtendedFieldExtension extends AbstractTypeExtension {
  /**
   * Returns the name of the type being extended.
   *
   * @return string The name of the type being extended
   */
  public function getExtendedType() {
    // use FormType::class to modify (nearly) every field in the system
    return FieldType::class;
  }


  /*
   * Add a custom 'object' type to write to a corresponding table for that new custom value
   */


  public function buildForm(FormBuilderInterface $builder, array $options)
  {
    $disabled = (!empty($options['data'])) ? $options['data']->isFixed() : false;
    $new         = (!empty($options['data']) && $options['data']->getAlias()) ? false : true;
    $builder->add(
      'object',
      'choice',
      [
        'choices' => [
          'mautic.lead.contact'    => 'lead',
          'mautic.company.company' => 'company',
          'mautic.lead.extendedField' => 'extendedField',
          'mautic.lead.extendedFieldSecure' => 'extendedFieldSecure',
        ],
        'choices_as_values' => true,
        'expanded'          => false,
        'multiple'          => false,
        'label'             => 'mautic.lead.field.object',
        'empty_value'       => false,
        'attr'              => [
          'class' => 'form-control',
        ],
        'required' => false,
        'disabled' => ($disabled || !$new),
      ]
    );
  }
}
