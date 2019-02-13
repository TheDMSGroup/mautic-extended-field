<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Digital Media Solutions, LLC
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticExtendedFieldBundle\Model;

use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\LeadBundle\Entity\CompanyChangeLog;
use Mautic\LeadBundle\Entity\CompanyLead;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadEventLog;
use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Helper\IdentifyCompanyHelper;
use Mautic\LeadBundle\Model\IpAddressModel;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\MauticExtendedFieldBundle\Entity\OverrideLeadRepository;

/**
 * Class OverrideLeadModel.
 */
class OverrideLeadModel extends LeadModel
{
    /**
     * @var IpAddressModel
     */
    public $ipAddressModel;

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
                    $alias = $field['alias'];
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
     *
     * @throws \Exception
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
            // Alteration to core start.
            $this->getRepository()->saveEntity($lead);
            // Alteration to core end.
            $this->companyModel->getCompanyLeadRepository()->saveEntities($companyArray, false);
        }

        // Clear CompanyLead entities from Doctrine memory
        $this->em->clear(CompanyLead::class);

        return ['oldPrimary' => $oldPrimaryCompany, 'newPrimary' => $companyId];
    }

    /**
     * Alteration to core:
     *  Avoid parent::saveEntity recursion.
     *
     * @param Lead $entity
     * @param bool $unlock
     *
     * @throws \Exception
     */
    public function saveEntity($entity, $unlock = true)
    {
        $companyFieldMatches = [];
        $fields              = $entity->getFields();
        $company             = null;

        //check to see if we can glean information from ip address
        if (!$entity->imported && count($ips = $entity->getIpAddresses())) {
            $details = $ips->first()->getIpDetails();
            // Only update with IP details if none of the following are set to prevent wrong combinations
            if (empty($fields['core']['city']['value']) && empty($fields['core']['state']['value']) && empty($fields['core']['country']['value']) && empty($fields['core']['zipcode']['value'])) {
                if (!empty($details['city'])) {
                    $entity->addUpdatedField('city', $details['city']);
                    $companyFieldMatches['city'] = $details['city'];
                }

                if (!empty($details['region'])) {
                    $entity->addUpdatedField('state', $details['region']);
                    $companyFieldMatches['state'] = $details['region'];
                }

                if (!empty($details['country'])) {
                    $entity->addUpdatedField('country', $details['country']);
                    $companyFieldMatches['country'] = $details['country'];
                }

                if (!empty($details['zipcode'])) {
                    $entity->addUpdatedField('zipcode', $details['zipcode']);
                }
            }
        }

        $updatedFields = $entity->getUpdatedFields();
        if (isset($updatedFields['company'])) {
            $companyFieldMatches['company']            = $updatedFields['company'];
            list($company, $leadAdded, $companyEntity) = IdentifyCompanyHelper::identifyLeadsCompany(
                $companyFieldMatches,
                $entity,
                $this->companyModel
            );
            if ($leadAdded) {
                $entity->addCompanyChangeLogEntry(
                    'form',
                    'Identify Company',
                    'Lead added to the company, '.$company['companyname'],
                    $company['id']
                );
            }
        }

        $this->processManipulator($entity);

        $this->setEntityDefaultValues($entity);

        // For BC w/ 2.14.2-
        if ($this->ipAddressModel) {
            $this->ipAddressModel->saveIpAddressesReferencesForContact($entity);
        }

        // Alteration to core start.
        // parent::saveEntity($entity, $unlock);
        $isNew = $this->isNewEntity($entity);
        $this->setTimestamps($entity, $isNew, $unlock);
        $event = $this->dispatchEvent('pre_save', $entity, $isNew);
        $this->getRepository()->saveEntity($entity);
        $this->setFieldValues($entity, $entity->getUpdatedFields(), false, false);
        $this->dispatchEvent('post_save', $entity, $isNew, $event);
        // Alteration to core end.

        if (!empty($company)) {
            // Save after the lead in for new leads created through the API and maybe other places
            $this->companyModel->addLeadToCompany($companyEntity, $entity);
            $this->setPrimaryCompany($companyEntity->getId(), $entity->getId());
        }

        $this->em->clear(CompanyChangeLog::class);
    }

    /**
     * Duplication from core:
     *  Exact copy of LeadModel method (which is private).
     *
     * @param Lead $lead
     */
    private function processManipulator(Lead $lead)
    {
        if ($lead->isNewlyCreated() || $lead->wasAnonymous()) {
            // Only store an entry once for created and once for identified, not every time the lead is saved
            $manipulator = $lead->getManipulator();
            if (null !== $manipulator) {
                $manipulationLog = new LeadEventLog();
                $manipulationLog->setLead($lead)
                    ->setBundle($manipulator->getBundleName())
                    ->setObject($manipulator->getObjectName())
                    ->setObjectId($manipulator->getObjectId());
                if ($lead->isAnonymous()) {
                    $manipulationLog->setAction('created_contact');
                } else {
                    $manipulationLog->setAction('identified_contact');
                }
                $description = $manipulator->getObjectDescription();
                $manipulationLog->setProperties(['object_description' => $description]);

                $lead->addEventLog($manipulationLog);
                $lead->setManipulator(null);
            }
        }
    }
}
