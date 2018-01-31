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
  public function finishView(FormView $view, FormInterface $form, array $options)
  {
    foreach($view->children as $child){
       if ($child->vars['name'] == 'object' && isset($child->vars['choices'])) {
         $choices = $child->vars['choices'];
         $data = $form->getViewData();
         $newChoice = new ChoiceView($data->getObject(), 'extendedField', 'Extended Field', array());
         $choices[] = $newChoice;
         $child->vars['choices'] = $choices;
       }
    }
  }
}