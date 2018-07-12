<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Digital Media Solutions, LLC
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html\
 */

namespace MauticPlugin\MauticExtendedFieldBundle\Model;

use Mautic\LeadBundle\Model\ListModel;

/**
 * Class OverrideListModel.
 */
class OverrideListModel extends ListModel
{
    /**
     * Get a list of field choices for filters.
     *
     * Alterations to core:
     *  Form validation to support extended fields.
     *
     * @return array
     */
    public function getChoiceFields()
    {
        $choices = parent::getChoiceFields();

        // Shift all extended fields into the "lead" object.
        $resort = false;
        foreach (['extendedField', 'extendedFieldSecure'] as $key) {
            if (isset($choices[$key])) {
                foreach ($choices[$key] as $fieldAlias => $field) {
                    $choices['lead'][$fieldAlias] = $field;
                    unset($choices[$key][$fieldAlias]);
                }
                unset($choices[$key]);
                $resort = true;
            }
        }
        // Sort after we included extended fields (same as core).
        if ($resort) {
            foreach ($choices as $key => $choice) {
                $cmp = function ($a, $b) {
                    return strcmp($a['label'], $b['label']);
                };
                uasort($choice, $cmp);
                $choices[$key] = $choice;
            }
        }

        return $choices;
    }
}
