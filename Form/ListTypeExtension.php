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

use Mautic\LeadBundle\Form\Type\ListType;
use Symfony\Component\Form\AbstractTypeExtension;

/**
 * Class ListTypeExtension.
 *
 * Updates the Mautic Lead Bundle LeadType.php for Object field choice values
 * to use a new getFormExtendedFields($builder, $options);
 */
class ListTypeExtension extends AbstractTypeExtension
{
    /**
     * Returns the name of the type being extended.
     *
     * @return string The name of the type being extended
     */
    public function getExtendedType()
    {
        // use FormType::class to modify (nearly) every field in the system
        return ListType::class;
    }
}
