<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticExtendedFieldBundle\Model;

use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\LeadBundle\Entity\CompanyLead;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\MauticExtendedFieldBundle\Entity\OverrideLeadRepository;

/**
 * Class OverrideLeadModel.
 */
class OverrideLeadModel extends LeadModel
{
    /**
     * Alterations to core:
     *  Returns OverrideLeadRepository.
     *
     * @return \MauticPlugin\MauticExtendedFieldBundle\Entity\OverrideLeadRepository
     */
    public function getRepository()
    {
        static $repoSetup;

        $metastart = new ClassMetadata(Lead::class);
        $repo      = new OverrideLeadRepository($this->em, $metastart, $this->leadFieldModel);

        // The rest of this method functions similar to core (with the exception of avoiding $this->repoSetup):
        $repo->setDispatcher($this->dispatcher);

        if (!$repoSetup) {
            $repoSetup = true;

            //set the point trigger model in order to get the color code for the lead
            $fields = $this->leadFieldModel->getFieldList(true, false);

            $socialFields = (!empty($fields['social'])) ? array_keys($fields['social']) : [];
            $repo->setAvailableSocialFields($socialFields);

            $searchFields = [];
            foreach ($fields as $group => $groupFields) {
                $searchFields = array_merge($searchFields, array_keys($groupFields));
            }
            $repo->setAvailableSearchFields($searchFields);
        }

        return $repo;
    }

    /**
     * Alterations to core:
     *  Includes extended objects.
     *  Adds 'object' to the array for later use within this plugin.
     *
     * @todo - make this more explicit than "!= company".
     *
     * @param $fields
     *
     * @return array
     */
    public function organizeFieldsByGroup($fields)
    {
        $array = [];

        foreach ($fields as $field) {
            // $field should never be an instance of LeadField... old plugin BC here.
            if ($field instanceof LeadField) {
                $alias = $field->getAlias();
                if ($field->isPublished() and 'Company' !== $field->getObject()) {
                    $group                           = $field->getGroup();
                    $array[$group][$alias]['id']     = $field->getId();
                    $array[$group][$alias]['group']  = $group;
                    $array[$group][$alias]['label']  = $field->getLabel();
                    $array[$group][$alias]['alias']  = $alias;
                    $array[$group][$alias]['type']   = $field->getType();
                    $array[$group][$alias]['object'] = $field->getObject();
                }
            } else {
                if (
                    (isset($field['isPublished']) && $field['isPublished']) ||
                    // @todo - "is_published" shouldn't be used anymore, confirm:
                    (isset($field['is_published']) && $field['is_published']) &&
                    in_array($field['object'], ['lead', 'extendedField', 'extendedFieldSecure'])
                ) {
                    $alias                           = $field['alias'];
                    // @todo - "field_group" shouldn't be used anymore, confirm:
                    $group                           = isset($field['group']) ? $field['group'] : $field['field_group'];
                    $array[$group][$alias]['id']     = $field['id'];
                    $array[$group][$alias]['group']  = $group;
                    $array[$group][$alias]['label']  = $field['label'];
                    $array[$group][$alias]['alias']  = $alias;
                    $array[$group][$alias]['type']   = $field['type'];
                    $array[$group][$alias]['object'] = $field['object'];
                }
            }
        }// in_array($field['object'], ['lead', 'extendedField', 'extendedFieldSecure'])

        //make sure each group key is present
        $groups = ['core', 'social', 'personal', 'professional'];
        foreach ($groups as $g) {
            if (!isset($array[$g])) {
                $array[$g] = [];
            }
        }

        return $array;
    }

    /**
     * Alterations to core:
     *  One line uses $this->saveEntity($lead); instead of the core repo.
     *
     * @param $companyId
     * @param $leadId
     *
     * @return array
     */
    public function setPrimaryCompany($companyId, $leadId)
    {
        $companyArray      = [];
        $oldPrimaryCompany = $newPrimaryCompany = false;

        $lead = $this->getEntity($leadId);

        $companyLeads = $this->companyModel->getCompanyLeadRepository()->getEntitiesByLead($lead);

        /** @var CompanyLead $companyLead */
        foreach ($companyLeads as $companyLead) {
            $company = $companyLead->getCompany();

            if ($companyLead) {
                if ($companyLead->getPrimary() && !$oldPrimaryCompany) {
                    $oldPrimaryCompany = $companyLead->getCompany()->getId();
                }
                if ($company->getId() === (int) $companyId) {
                    $companyLead->setPrimary(true);
                    $newPrimaryCompany = $companyId;
                    $lead->addUpdatedField('company', $company->getName());
                } else {
                    $companyLead->setPrimary(false);
                }
                $companyArray[] = $companyLead;
            }
        }

        if (!$newPrimaryCompany) {
            $latestCompany = $this->companyModel->getCompanyLeadRepository()->getLatestCompanyForLead($leadId);
            if (!empty($latestCompany)) {
                $lead->addUpdatedField('company', $latestCompany['companyname'])
                    ->setDateModified(new \DateTime());
            }
        }

        if (!empty($companyArray)) {
            // The following is the only line altered in this method from core.
            // @todo - patch core to avoid having to do this.
            $this->saveEntity($lead);
            $this->companyModel->getCompanyLeadRepository()->saveEntities($companyArray, false);
        }

        // Clear CompanyLead entities from Doctrine memory
        $this->em->clear(CompanyLead::class);

        return ['oldPrimary' => $oldPrimaryCompany, 'newPrimary' => $companyId];
    }
}
