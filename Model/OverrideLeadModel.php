<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Scott Shipman
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 *
 *
 */

namespace MauticPlugin\MauticExtendedFieldBundle\Model;

use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Entity\CompanyChangeLog;
use Mautic\LeadBundle\Entity\CompanyLead;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Event\LeadChangeCompanyEvent;
use Mautic\LeadBundle\Helper\IdentifyCompanyHelper;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\MauticExtendedFieldBundle\Entity\OverrideLeadRepository;

/**
 * Class OverrideLeadModel
 * {@inheritdoc}
 */
class OverrideLeadModel extends LeadModel
{
    /** @var bool */
    public $companyWasUpdated = false;

    /** @var bool */
    public $extendedFieldsAdded = false;

    /**
     * Get a specific entity or generate a new one if id is empty.
     *
     * @param $id
     *
     * @return null|Lead
     */
    public function getEntity($id = null)
    {
        if (null === $id) {
            return new Lead();
        }
        $fieldModel = $this->leadFieldModel;
        $metastart  = new ClassMetadata(Lead::class);
        $repo       = new OverrideLeadRepository($this->em, $metastart, $fieldModel);
        $entity     = $repo->getEntity($id);
        //$entity = parent::getEntity($id);

        if (null === $entity) {
            // Check if this contact was merged into another and if so, return the new contact
            $entity = $this->getMergeRecordRepository()->findMergedContact($id);
        }

        return $entity;
    }

    /**
     * Overrides the LeadBundle LeadModel.php instance of saveEntity
     * prevents calling parent:: and replaces with custom doSave() method.
     *
     * {@inheritdoc}
     *
     * @param Lead $entity
     * @param bool $unlock
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

        $this->setEntityDefaultValues($entity);

        //parent::saveEntity($entity, $unlock);

        $this->doSaveEntity($entity, $unlock);

        $this->em->persist($entity);
        $this->em->flush($entity);

        if (!empty($company) && !$this->companyWasUpdated) {
            // Save after the lead in for new leads created through the API and maybe other places
            $this->companyModel->addLeadToCompany($companyEntity, $entity);
            $this->companyWasUpdated = true;

            $this->setPrimaryCompany($companyEntity->getId(), $entity->getId());
        }

        $this->em->clear(CompanyChangeLog::class);
    }

    /**
     * Create/edit entity.
     * forces the OverrideLeadRepository repo instance to use custom sql
     * for extendedField schema handling.
     *
     * @param object $entity
     * @param bool   $unlock
     */
    public function doSaveEntity($entity, $unlock = true)
    {
        //$isNew = FormModel::isNewEntity($entity);
        $isNew = $this->isNewEntity($entity);
        //set some defaults
        $this->setTimestamps($entity, $isNew, $unlock);

        $event = $this->dispatchEvent('pre_save', $entity, $isNew);
        $this->getRepository()->saveExtendedEntity($entity);
        //  $this->getRepository()->saveEntity($entity);
        $this->dispatchEvent('post_save', $entity, $isNew, $event);
    }

    /**
     * Overrides the LeadBundle LeadModel.php instance of getRepository()
     * forces using the OverrideLeadRepository instead.
     *
     * {@inheritdoc}
     *
     * @return \MauticPlugin\MauticExtendedFieldBundle\Entity\OverrideLeadRepository
     */
    public function getRepository()
    {
        static $repoSetup;

        $metastart = new ClassMetadata(Lead::class);
        $repo      = new OverrideLeadRepository($this->em, $metastart, $this->leadFieldModel);
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
     * Overrides the LeadModel instance of setFieldValues to include extendedFields
     * Populates custom field values for updating the lead. Also retrieves social media data.
     *
     * @param Lead       $lead
     * @param array      $data
     * @param bool|false $overwriteWithBlank
     * @param bool|true  $fetchSocialProfiles
     * @param bool|false $bindWithForm        Send $data through the Lead form and only use valid data (should be used
     *                                        with request data)
     *
     * @return array|void
     */
    public function setFieldValues(
        Lead &$lead,
        array $data,
        $overwriteWithBlank = false,
        $fetchSocialProfiles = true,
        $bindWithForm = false
    ) {
        if ($fetchSocialProfiles) {
            //@todo - add a catch to NOT do social gleaning if a lead is created via a form, etc as we do not want the user to experience the wait
            //generate the social cache
            list($socialCache, $socialFeatureSettings) = $this->integrationHelper->getUserProfiles(
                $lead,
                $data,
                true,
                null,
                false,
                true
            );

            //set the social cache while we have it
            if (!empty($socialCache)) {
                $lead->setSocialCache($socialCache);
            }
        }

        if (isset($data['stage'])) {
            $stagesChangeLogRepo = $this->getStagesChangeLogRepository();
            $currentLeadStage    = $stagesChangeLogRepo->getCurrentLeadStage($lead->getId());

            if ($data['stage'] !== $currentLeadStage) {
                $stage = $this->em->getRepository('MauticStageBundle:Stage')->find($data['stage']);
                $lead->stageChangeLogEntry(
                    $stage,
                    $stage->getId().':'.$stage->getName(),
                    $this->translator->trans('mautic.stage.event.changed')
                );
            }
        }

        //save the field values
        $fieldValues = $lead->getFields();

        if (empty($fieldValues) || $bindWithForm) {
            // Lead is new or they haven't been populated so let's build the fields now
            static $flatFields, $fields;
            if (empty($flatFields)) {
                // modified line below to get Extended Field Entities too.
                $flatFields = $this->getExtendedEntities();
                $fields     = $this->organizeFieldsByGroup($flatFields);
            }

            if (empty($fieldValues)) {
                $fieldValues = $fields;
            }
        }

        if ($bindWithForm) {
            // Cleanup the field values
            $form = $this->createForm(
                new Lead(), // use empty lead to prevent binding errors
                $this->formFactory,
                null,
                ['fields' => $flatFields, 'csrf_protection' => false, 'allow_extra_fields' => true]
            );

            // Unset stage and owner from the form because it's already been handled
            unset($data['stage'], $data['owner'], $data['tags']);
            // Prepare special fields
            $this->prepareParametersFromRequest($form, $data, $lead);
            // Submit the data
            $form->submit($data);

            if ($form->getErrors()->count()) {
                $this->logger->addDebug('LEAD: form validation failed with an error of '.(string) $form->getErrors());
            }
            foreach ($form as $field => $formField) {
                if (isset($data[$field])) {
                    if ($formField->getErrors()->count()) {
                        $this->logger->addDebug(
                            'LEAD: '.$field.' failed form validation with an error of '.(string) $formField->getErrors()
                        );
                        // Don't save bad data
                        unset($data[$field]);
                    } else {
                        $data[$field] = $formField->getData();
                    }
                }
            }
        }

        //update existing values
        foreach ($fieldValues as $group => &$groupFields) {
            foreach ($groupFields as $alias => &$field) {
                if (!isset($field['value'])) {
                    $field['value'] = null;
                }

                // Only update fields that are part of the passed $data array
                if (array_key_exists($alias, $data)) {
                    if (!$bindWithForm) {
                        $this->cleanFields($data, $field);
                    }
                    $curValue = $field['value'];
                    $newValue = isset($data[$alias]) ? $data[$alias] : '';

                    if (is_array($newValue)) {
                        $newValue = implode('|', $newValue);
                    }

                    $isEmpty = (null === $newValue || '' === $newValue);
                    if ($curValue !== $newValue && (!$isEmpty || ($isEmpty && $overwriteWithBlank))) {
                        $field['value'] = $newValue;
                        $lead->addUpdatedField($alias, $newValue, $curValue);
                    }

                    //if empty, check for social media data to plug the hole
                    if (empty($newValue) && !empty($socialCache)) {
                        foreach ($socialCache as $service => $details) {
                            //check to see if a field has been assigned

                            if (!empty($socialFeatureSettings[$service]['leadFields'])
                                && in_array($field['alias'], $socialFeatureSettings[$service]['leadFields'])
                            ) {
                                //check to see if the data is available
                                $key = array_search($field['alias'], $socialFeatureSettings[$service]['leadFields']);
                                if (isset($details['profile'][$key])) {
                                    //Found!!
                                    $field['value'] = $details['profile'][$key];
                                    $lead->addUpdatedField($alias, $details['profile'][$key]);
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        $lead->setFields($fieldValues);
    }

    /**
     * Gets array of extended Field config.
     *
     * @param array $args
     *
     * @return array
     */
    public function getExtendedEntities(array $args = [])
    {
        //TODO check for perms of user to see if object should include extendedFieldSecure

        $fq = $this->em->getConnection()->createQueryBuilder();
        $fq->select('*')
            ->from(MAUTIC_TABLE_PREFIX.'lead_fields', 'f')
            ->where('f.object <> :object')
            ->andWhere($fq->expr()->eq('is_published', ':isPub'))
            ->setParameter('object', 'company')
            ->setParameter('isPub', true);
        $values = $fq->execute()->fetchAll();

        if($args['keys'])
        {
            // rekey the results by the provided key value
            $fields=[];
            foreach($values as $v){
                if($v[$args['keys']]){
                    $fields[$v[$args['keys']]] = $v;
                }
            }
            $values = $fields;
        }

        return $values;
    }

    /**
     * Overrides the LeadModel organizeFieldsByGroup() method
     * Reorganizes a field list to be keyed by field's group then alias.
     *
     * @param $fields
     *
     * @return array
     */
    public function organizeFieldsByGroup($fields)
    {
        $array = [];

        foreach ($fields as $field) {
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
                if ((isset($field['isPublished']) && $field['isPublished']) ||
                    (isset($field['is_published']) && $field['is_published']) &&
                    'company' !== $field['object']) {
                    $alias                           = $field['alias'];
                    $group                           = isset($field['group']) ? $field['group'] : $field['field_group'];
                    $array[$group][$alias]['id']     = $field['id'];
                    $array[$group][$alias]['group']  = $group;
                    $array[$group][$alias]['label']  = $field['label'];
                    $array[$group][$alias]['alias']  = $alias;
                    $array[$group][$alias]['type']   = $field['type'];
                    $array[$group][$alias]['object'] = $field['object'];
                }
            }
        }

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
     * Overrides LeadBundle version of modifyCompanies
     * to remove extended fields from list before adding companies to prevent
     * recursive saves that fail.
     *
     * Modify companies for lead.
     *
     * @param Lead $lead
     * @param      $companies
     *
     * @throws \Doctrine\ORM\ORMException
     */
    public function modifyCompanies(Lead $lead, $companies)
    {
        // See which companies belong to the lead already
        $leadCompanies = $this->companyModel->getCompanyLeadRepository()->getCompaniesByLeadId($lead->getId());

        foreach ($leadCompanies as $key => $leadCompany) {
            if (false === array_search($leadCompany['company_id'], $companies)) {
                $this->companyModel->removeLeadFromCompany([$leadCompany['company_id']], $lead);
            }
        }

        if (count($companies)) {
            $this->addLeadToCompany($companies, $lead);
        } else {
            // update the lead's company name to nothing
            $lead->addUpdatedField('company', '');
            $this->getRepository()->saveExtendedEntity($lead);
        }
    }

    /** Add lead to company
     * @param array      $companies
     * @param array|Lead $lead
     *
     * @return bool
     *
     * @throws \Doctrine\ORM\ORMException
     */
    public function addLeadToCompany($companies, $lead)
    {
        // Primary company name to be peristed to the lead's contact company field
        $companyName        = '';
        $companyLeadAdd     = [];
        $searchForCompanies = [];

        $dateManipulated = new \DateTime();

        if (!$lead instanceof Lead) {
            $leadId = (is_array($lead) && isset($lead['id'])) ? $lead['id'] : $lead;
            $lead   = $this->em->getReference('MauticLeadBundle:Lead', $leadId);
        }

        if ($companies instanceof Company) {
            $companyLeadAdd[$companies->getId()] = $companies;
            $companies                           = [$companies->getId()];
        } elseif (!is_array($companies)) {
            $companies = [$companies];
        }

        //make sure they are ints
        foreach ($companies as $k => &$l) {
            $l = (int) $l;

            if (!isset($companyLeadAdd[$l])) {
                $searchForCompanies[] = $l;
            }
        }

        if (!empty($searchForCompanies)) {
            $companyEntities = $this->em->getRepository('MauticLeadBundle:Company')->getEntities(
                [
                    'filter' => [
                        'force' => [
                            [
                                'column' => 'comp.id',
                                'expr'   => 'in',
                                'value'  => $searchForCompanies,
                            ],
                        ],
                    ],
                ]
            );

            foreach ($companyEntities as $company) {
                $companyLeadAdd[$company->getId()] = $company;
            }
        }

        unset($companyEntities, $searchForCompanies);

        $persistCompany = [];
        $dispatchEvents = [];
        $contactAdded   = false;
        foreach ($companies as $companyId) {
            if (!isset($companyLeadAdd[$companyId])) {
                // List no longer exists in the DB so continue to the next
                continue;
            }

            $companyLead = $this->em->getRepository('MauticLeadBundle:CompanyLead')->findOneBy(
                [
                    'lead'    => $lead,
                    'company' => $companyLeadAdd[$companyId],
                ]
            );

            if (null != $companyLead) {
                // @deprecated support to be removed in 3.0
                if ($companyLead->wasManuallyRemoved()) {
                    $companyLead->setManuallyRemoved(false);
                    $companyLead->setManuallyAdded(false);
                    $contactAdded     = true;
                    $persistCompany[] = $companyLead;
                    $dispatchEvents[] = $companyId;
                    $companyName      = $companyLeadAdd[$companyId]->getName();
                } else {
                    // Detach from Doctrine
                    $this->em->detach($companyLead);

                    continue;
                }
            } else {
                $companyLead = new CompanyLead();
                $companyLead->setCompany($companyLeadAdd[$companyId]);
                $companyLead->setLead($lead);
                $companyLead->setDateAdded($dateManipulated);
                $contactAdded     = true;
                $persistCompany[] = $companyLead;
                $dispatchEvents[] = $companyId;
                $companyName      = $companyLeadAdd[$companyId]->getName();
            }
        }

        if (!empty($persistCompany)) {
            $this->em->getRepository('MauticLeadBundle:CompanyLead')->saveEntities($persistCompany);
        }

        if (!empty($companyName)) {
            $currentCompanyName = $lead->getCompany();
            if ($currentCompanyName !== $companyName) {
                $lead->addUpdatedField('company', $companyName)
                    ->setDateModified(new \DateTime());
                $this->saveEntity($lead);
            }
        }

        if (!empty($dispatchEvents) && ($this->dispatcher->hasListeners(LeadEvents::LEAD_COMPANY_CHANGE))) {
            foreach ($dispatchEvents as $companyId) {
                $event = new LeadChangeCompanyEvent($lead, $companyLeadAdd[$companyId]);
                $this->dispatcher->dispatch(LeadEvents::LEAD_COMPANY_CHANGE, $event);

                unset($event);
            }
        }

        // Clear CompanyLead entities from Doctrine memory
        $this->em->clear(CompanyLead::class);

        return $contactAdded;
    }

    /**
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
            $this->saveEntity($lead);
            $this->companyModel->getCompanyLeadRepository()->saveEntities($companyArray, false);
        }

        // Clear CompanyLead entities from Doctrine memory
        $this->em->clear(CompanyLead::class);

        return ['oldPrimary' => $oldPrimaryCompany, 'newPrimary' => $companyId];
    }

    /**
     * Gets the details of a lead if not already set.
     *
     * @param $lead
     *
     * @return mixed
     */
    public function getLeadDetails($lead)
    {
        if ($lead instanceof Lead) {
            $fields = $lead->getFields();
            if (!empty($fields)) {
                return $fields;
            }
        }

        $leadId = ($lead instanceof Lead) ? $lead->getId() : (int) $lead;

        return $this->getRepository()->getExtendedFieldValues($leadId);
    }
}
