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
use Doctrine\ORM\NonUniqueResultException;
use Mautic\CoreBundle\Entity\IpAddress;
use Mautic\CoreBundle\Helper\DateTimeHelper;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\LeadBundle\DataObject\LeadManipulator;
use Mautic\LeadBundle\Entity\CompanyChangeLog;
use Mautic\LeadBundle\Entity\CompanyLead;
use Mautic\LeadBundle\Entity\DoNotContact as DNC;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadEventLog;
use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Entity\PointsChangeLog;
use Mautic\LeadBundle\Entity\StagesChangeLog;
use Mautic\LeadBundle\Helper\IdentifyCompanyHelper;
use Mautic\LeadBundle\Model\IpAddressModel;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\StageBundle\Entity\Stage;
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

    /**
     * @param array        $fields
     * @param array        $data
     * @param null         $owner
     * @param null         $list
     * @param null         $tags
     * @param bool         $persist
     * @param LeadEventLog $eventLog
     *
     * @return bool|null
     *
     * @throws \Exception
     */
    public function import(
        $fields,
        $data,
        $owner = null,
        $list = null,
        $tags = null,
        $persist = true,
        LeadEventLog $eventLog = null,
        $importId = null
    ) {
        $fields    = array_flip($fields);
        $fieldData = [];

        // Extract company data and import separately
        // Modifies the data array
        $company                           = null;
        list($companyFields, $companyData) = $this->companyModel->extractCompanyDataFromImport($fields, $data);

        if (!empty($companyData)) {
            $companyFields = array_flip($companyFields);
            $this->companyModel->import($companyFields, $companyData, $owner, $list, $tags, $persist, $eventLog);
            $companyFields = array_flip($companyFields);

            $companyName    = isset($companyFields['companyname']) ? $companyData[$companyFields['companyname']] : null;
            $companyCity    = isset($companyFields['companycity']) ? $companyData[$companyFields['companycity']] : null;
            $companyCountry = isset($companyFields['companycountry']) ? $companyData[$companyFields['companycountry']] : null;
            $companyState   = isset($companyFields['companystate']) ? $companyData[$companyFields['companystate']] : null;

            $company = $this->companyModel->getRepository()->identifyCompany(
                $companyName,
                $companyCity,
                $companyCountry,
                $companyState
            );
        }

        foreach ($fields as $leadField => $importField) {
            // Prevent overwriting existing data with empty data
            if (array_key_exists($importField, $data) && !is_null($data[$importField]) && '' != $data[$importField]) {
                $fieldData[$leadField] = InputHelper::_($data[$importField], 'string');
            }
        }

        $lead   = $this->checkForDuplicateContact($fieldData);
        $merged = ($lead->getId());

        if (!empty($fields['dateAdded']) && !empty($data[$fields['dateAdded']])) {
            $dateAdded = new DateTimeHelper($data[$fields['dateAdded']]);
            $lead->setDateAdded($dateAdded->getUtcDateTime());
        }
        unset($fieldData['dateAdded']);

        if (!empty($fields['dateModified']) && !empty($data[$fields['dateModified']])) {
            $dateModified = new DateTimeHelper($data[$fields['dateModified']]);
            $lead->setDateModified($dateModified->getUtcDateTime());
        }
        unset($fieldData['dateModified']);

        if (!empty($fields['lastActive']) && !empty($data[$fields['lastActive']])) {
            $lastActive = new DateTimeHelper($data[$fields['lastActive']]);
            $lead->setLastActive($lastActive->getUtcDateTime());
        }
        unset($fieldData['lastActive']);

        if (!empty($fields['dateIdentified']) && !empty($data[$fields['dateIdentified']])) {
            $dateIdentified = new DateTimeHelper($data[$fields['dateIdentified']]);
            $lead->setDateIdentified($dateIdentified->getUtcDateTime());
        }
        unset($fieldData['dateIdentified']);

        if (!empty($fields['createdByUser']) && !empty($data[$fields['createdByUser']])) {
            $userRepo      = $this->em->getRepository('MauticUserBundle:User');
            $createdByUser = $userRepo->findByIdentifier($data[$fields['createdByUser']]);
            if (null !== $createdByUser) {
                $lead->setCreatedBy($createdByUser);
            }
        }
        unset($fieldData['createdByUser']);

        if (!empty($fields['modifiedByUser']) && !empty($data[$fields['modifiedByUser']])) {
            $userRepo       = $this->em->getRepository('MauticUserBundle:User');
            $modifiedByUser = $userRepo->findByIdentifier($data[$fields['modifiedByUser']]);
            if (null !== $modifiedByUser) {
                $lead->setModifiedBy($modifiedByUser);
            }
        }
        unset($fieldData['modifiedByUser']);

        if (!empty($fields['ip']) && !empty($data[$fields['ip']])) {
            $addresses = explode(',', $data[$fields['ip']]);
            foreach ($addresses as $address) {
                $ipAddress = new IpAddress();
                $ipAddress->setIpAddress(trim($address));
                $lead->addIpAddress($ipAddress);
            }
        }
        unset($fieldData['ip']);

        // Handle UTM Tags
        $utmFields = [
            'utm_campaign',
            'utm_source',
            'utm_medium',
            'utm_content',
            'utm_term',
            'user_agent',
            'url',
            'referer',
            'query',
            'remote_host',
        ];
        $utmParams = [];
        foreach ($utmFields as $utmField) {
            if (!empty($fields[$utmField]) && !empty($data[$fields[$utmField]])) {
                $utmParams[$utmField] = $data[$fields[$utmField]];
                unset($fieldData[$utmField]);
            }
        }

        if (!empty($utmParams)) {
            $this->addUTMTags($lead, $utmParams);
        }

        if (!empty($fields['points']) && !empty($data[$fields['points']]) && null === $lead->getId()) {
            // Add points only for new leads
            $lead->setPoints($data[$fields['points']]);

            //add a lead point change log
            $log = new PointsChangeLog();
            $log->setDelta($data[$fields['points']]);
            $log->setLead($lead);
            $log->setType('lead');
            $log->setEventName($this->translator->trans('mautic.lead.import.event.name'));
            $log->setActionName(
                $this->translator->trans(
                    'mautic.lead.import.action.name',
                    [
                        '%name%' => $this->userHelper->getUser()->getUsername(),
                    ]
                )
            );
            $log->setIpAddress($this->ipLookupHelper->getIpAddress());
            $log->setDateAdded(new \DateTime());
            $lead->addPointsChangeLog($log);
        }

        if (!empty($fields['stage']) && !empty($data[$fields['stage']])) {
            static $stages = [];
            $stageName     = $data[$fields['stage']];
            if (!array_key_exists($stageName, $stages)) {
                // Set stage for contact
                $stage = $this->em->getRepository('MauticStageBundle:Stage')->getStageByName($stageName);

                if (empty($stage)) {
                    $stage = new Stage();
                    $stage->setName($stageName);
                    $stages[$stageName] = $stage;
                }
            } else {
                $stage = $stages[$stageName];
            }

            $lead->setStage($stage);

            //add a contact stage change log
            $log = new StagesChangeLog();
            $log->setStage($stage);
            $log->setEventName($stage->getId().':'.$stage->getName());
            $log->setLead($lead);
            $log->setActionName(
                $this->translator->trans(
                    'mautic.stage.import.action.name',
                    [
                        '%name%' => $this->userHelper->getUser()->getUsername(),
                    ]
                )
            );
            $log->setDateAdded(new \DateTime());
            $lead->stageChangeLog($log);
        }
        unset($fieldData['stage']);

        // Set unsubscribe status
        if (!empty($fields['doNotEmail']) && !empty($data[$fields['doNotEmail']]) && (!empty($fields['email']) && !empty($data[$fields['email']]))) {
            $doNotEmail = filter_var($data[$fields['doNotEmail']], FILTER_VALIDATE_BOOLEAN);
            if ($doNotEmail) {
                $reason = $this->translator->trans(
                    'mautic.lead.import.by.user',
                    [
                        '%user%' => $this->userHelper->getUser()->getUsername(),
                    ]
                );

                // The email must be set for successful unsubscribtion
                $lead->addUpdatedField('email', $data[$fields['email']]);
                $this->addDncForLead($lead, 'email', $reason, DNC::MANUAL);
            }
        }
        unset($fieldData['doNotEmail']);

        if (!empty($fields['ownerusername']) && !empty($data[$fields['ownerusername']])) {
            try {
                $newOwner = $this->userProvider->loadUserByUsername($data[$fields['ownerusername']]);
                $lead->setOwner($newOwner);
                //reset default import owner if exists owner for contact
                $owner = null;
            } catch (NonUniqueResultException $exception) {
                // user not found
            }
        }
        unset($fieldData['ownerusername']);

        if (null !== $owner) {
            $lead->setOwner($this->em->getReference('MauticUserBundle:User', $owner));
        }

        if (null !== $tags) {
            $this->modifyTags($lead, $tags, null, false);
        }

        if (empty($this->leadFields)) {
            $this->leadFields = $this->leadFieldModel->getEntities(
                [
                    'filter'         => [
                        'force' => [
                            [
                                'column' => 'f.isPublished',
                                'expr'   => 'eq',
                                'value'  => true,
                            ],
                            [
                                'column' => 'f.object',
                                'expr'   => 'eq',
                                'value'  => 'lead',
                            ],
                        ],
                    ],
                    'hydration_mode' => 'HYDRATE_ARRAY',
                ]
            );
        }

        $fieldErrors = [];

        foreach ($this->leadFields as $leadField) {
            if (isset($fieldData[$leadField['alias']])) {
                if ('NULL' === $fieldData[$leadField['alias']]) {
                    $fieldData[$leadField['alias']] = null;

                    continue;
                }

                try {
                    $this->cleanFields($fieldData, $leadField);
                } catch (\Exception $exception) {
                    $fieldErrors[] = $leadField['alias'].': '.$exception->getMessage();
                }

                if ('email' === $leadField['type'] && !empty($fieldData[$leadField['alias']])) {
                    try {
                        $this->emailValidator->validate($fieldData[$leadField['alias']], false);
                    } catch (\Exception $exception) {
                        $fieldErrors[] = $leadField['alias'].': '.$exception->getMessage();
                    }
                }

                // Skip if the value is in the CSV row
                continue;
            } elseif ($lead->isNew() && $leadField['defaultValue']) {
                // Fill in the default value if any
                $fieldData[$leadField['alias']] = ('multiselect' === $leadField['type']) ? [$leadField['defaultValue']] : $leadField['defaultValue'];
            }
        }

        if ($fieldErrors) {
            $fieldErrors = implode("\n", $fieldErrors);

            throw new \Exception($fieldErrors);
        }

        // All clear
        foreach ($fieldData as $field => $value) {
            $lead->addUpdatedField($field, $value);
        }

        $lead->imported = true;

        if ($eventLog) {
            $action = $merged ? 'updated' : 'inserted';
            $eventLog->setAction($action);
        }

        if ($persist) {
            $lead->setManipulator(
                new LeadManipulator(
                    'lead',
                    'import',
                    $importId,
                    $this->userHelper->getUser()->getName()
                )
            );
            $this->saveEntity($lead);

            if (null !== $list) {
                $this->addToLists($lead, [$list]);
            }

            if (null !== $company) {
                $this->companyModel->addLeadToCompany($company, $lead);
            }

            if ($eventLog) {
                $lead->addEventLog($eventLog);
            }
        }

        return $merged;
    }
}
