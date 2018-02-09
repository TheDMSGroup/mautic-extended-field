<?php
/**
 * Mautic List Type Form Extension
 * Created by Scott Shipman
 *
 * Date: 1/30/18
 *
 * Updates the Mautic Lead Bundle LeadType.php for Object field choice values
 * to use a new getFormExtendedFields($builder, $options);

 */

namespace MauticPlugin\MauticExtendedFieldBundle\Form;

use Symfony\Component\Form\AbstractTypeExtension;
use Mautic\LeadBundle\Form\Type\ListType;


/**
 * Class ListTypeExtension.
 */

class ListTypeExtension extends AbstractTypeExtension {

  /**
   * Returns the name of the type being extended.
   *
   * @return string The name of the type being extended
   */
  public function getExtendedType() {
    // use FormType::class to modify (nearly) every field in the system
    return ListType::class;
  }


}
