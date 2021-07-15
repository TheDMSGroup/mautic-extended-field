<?php

declare(strict_types=1);

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticExtendedFieldBundle\Field;

use Mautic\LeadBundle\Field\FieldList;

class OverrideFieldList extends FieldList
{
    public function getFieldList(bool $byGroup = true, bool $alphabetical = true, array $filters = ['isPublished' => true, 'object' => 'lead']): array
    {

        $forceFilters = [];
        foreach ($filters as $col => $val) {
            if ($col == 'object' && $val  != 'company') {
                $expr   = 'neq';
                $val = 'company';
            } else {
                $expr = 'eq';
            }

            $forceFilters[] = [
                'column' => "f.{$col}",
                'expr'   => $expr,
                'value'  => $val,
            ];
        }
        // Get a list of custom form fields
        $fields = $this->leadFieldRepository->getEntities([
             'filter' => [
                 'force' => $forceFilters,
             ],
             'orderBy'    => 'f.order',
             'orderByDir' => 'asc',
        ]);

        $leadFields = [];

        foreach ($fields as $f) {
            if ($byGroup) {
                $fieldName                              = $this->translator->trans('mautic.lead.field.group.'.$f->getGroup());
                $leadFields[$fieldName][$f->getAlias()] = $f->getLabel();
            } else {
                $leadFields[$f->getAlias()] = $f->getLabel();
            }
        }

        if ($alphabetical) {
            // Sort the groups
            uksort($leadFields, 'strnatcmp');

            if ($byGroup) {
                // Sort each group by translation
                foreach ($leadFields as &$fieldGroup) {
                    uasort($fieldGroup, 'strnatcmp');
                }
            }
        }

        return $leadFields;
    }
}
