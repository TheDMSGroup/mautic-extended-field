<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Scott Shipman
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 *
 * Overrides the LeadBundle ListModel.php to handle extendedField filter types
 */

namespace MauticPlugin\MauticExtendedFieldBundle\Model;

use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\ListModel;
use MauticPlugin\MauticExtendedFieldBundle\Entity\OverrideLeadListRepository as OverrideLeadListRepository;

/**
 * Class OverrideListModel
 * {@inheritdoc}
 */
class OverrideListModel extends ListModel
{
    /**
     * @return OverrideLeadListRepository
     */
    public function getRepository()
    {
        /** @var \Mautic\LeadBundle\Entity\LeadListRepository $repo */
        $metastart = new ClassMetadata(LeadList::class);
        $repo      = new OverrideLeadListRepository($this->em, $metastart, $this->factory->getModel('lead.field'));

        $repo->setDispatcher($this->dispatcher);
        $repo->setTranslator($this->translator);

        return $repo;
    }

    /**
     * Overrides the getChoiceFields()method from the ListModel in LeadBundle
     * in order to correct a form validation error on field operator choice types.
     *
     * Get a list of field choices for filters.
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
