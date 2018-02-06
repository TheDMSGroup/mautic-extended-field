<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticExtendedFieldBundle\Entity;

use Mautic\LeadBundle\Entity\LeadRepository as LeadRepository;
use Mautic\LeadBundle\Entity\OperatorListTrait;
use Mautic\LeadBundle\Entity\CustomFieldRepositoryInterface;
use Mautic\LeadBundle\Entity\CustomFieldRepositoryTrait;
use Mautic\LeadBundle\Entity\ExpressionHelperTrait;
use MauticPlugin\MauticExtendedFieldBundle\Entity\ExtendedFieldRepositoryTrait;

/**
 * OverrideLeadRepository.
 */
class OverrideLeadRepository extends LeadRepository implements CustomFieldRepositoryInterface
{

  use CustomFieldRepositoryTrait;
  use ExpressionHelperTrait;
  use OperatorListTrait;
  use ExtendedFieldRepositoryTrait;

  /**
   * @var EventDispatcherInterface
   */
  protected $dispatcher;

  /**
   * @var array
   */
  private $availableSocialFields = [];

  /**
   * @var array
   */
  private $availableSearchFields = [];

  /**
   * Required to get the color based on a lead's points.
   *console
   * @var TriggerModel
   */
  private $triggerModel;

  /**
   * Used by search functions to search social profiles.
   *
   * @param array $fields
   */
  public function setAvailableSocialFields(array $fields)
  {
    $this->availableSocialFields = $fields;
  }

  /**
   * {@inheritdoc}
   *
   * @param int $id
   *
   * @return mixed|null
   *
   * Gets the lead object with all core and custom fields
   */
  public function getEntity($id = 0)
  {
    try {
      $q = $this->createQueryBuilder($this->getTableAlias());
      if (is_array($id)) {
        $this->buildSelectClause($q, $id);
        $contactId = (int) $id['id'];
      } else {
        $q->select('l, u, i')
          ->leftJoin('l.ipAddresses', 'i')
          ->leftJoin('l.owner', 'u');
        $contactId = $id;
      }
      $q->andWhere($this->getTableAlias().'.id = '.(int) $contactId);
      $entity = $q->getQuery()->getSingleResult();
    } catch (\Exception $e) {
      $entity = null;
    }

    if ($entity != null) {
      if (!empty($this->triggerModel)) {
        $entity->setColor($this->triggerModel->getColorForLeadPoints($entity->getPoints()));
      }

      $fieldValues = $this->getFieldValues($id);
      $extendedFieldValues = $this->getExtendedFieldValues($id, TRUE, 'extendedField');
      $extendedFieldSecureValues = $this->getExtendedFieldValues($id, TRUE, 'extendedFieldSecure');
      // TODO pass a Permission Base check here for secure values

      $fieldValues = array_merge_recursive($fieldValues, $extendedFieldValues, $extendedFieldSecureValues);
      $entity->setFields($fieldValues);

      $entity->setAvailableSocialFields($this->availableSocialFields);
    }

    return $entity;
  }

  /**
   * Get a list of leads.
   *
   * @param array $args
   *
   * @return array
   */
  public function getEntities(array $args = [])
  {
    $contacts = $this->getEntitiesWithCustomFields(
      'lead',
      $args,
      function ($r) {
        if (!empty($this->triggerModel)) {
          $r->setColor($this->triggerModel->getColorForLeadPoints($r->getPoints()));
        }
        $r->setAvailableSocialFields($this->availableSocialFields);
      }
    );

    $contactCount = isset($contacts['results']) ? count($contacts['results']) : count($contacts);
    if ($contactCount && (!empty($args['withPrimaryCompany']) || !empty($args['withChannelRules']))) {
      $withTotalCount = (array_key_exists('withTotalCount', $args) && $args['withTotalCount']);
      /** @var Lead[] $tmpContacts */
      $tmpContacts = ($withTotalCount) ? $contacts['results'] : $contacts;

      $withCompanies   = !empty($args['withPrimaryCompany']);
      $withPreferences = !empty($args['withChannelRules']);
      $contactIds      = array_keys($tmpContacts);

      if ($withCompanies) {
        $companies = $this->getEntityManager()->getRepository('MauticLeadBundle:Company')->getCompaniesForContacts($contactIds);
      }

      if ($withPreferences) {
        /** @var FrequencyRuleRepository $frequencyRepo */
        $frequencyRepo  = $this->getEntityManager()->getRepository('MauticLeadBundle:FrequencyRule');
        $frequencyRules = $frequencyRepo->getFrequencyRules(null, $contactIds);

        /** @var DoNotContactRepository $dncRepository */
        $dncRepository = $this->getEntityManager()->getRepository('MauticLeadBundle:DoNotContact');
        $dncRules      = $dncRepository->getChannelList(null, $contactIds);
      }

      foreach ($contactIds as $id) {
        if ($withCompanies && isset($companies[$id]) && !empty($companies[$id])) {
          $primary = null;

          // Try to find the primary company
          foreach ($companies[$id] as $company) {
            if ($company['is_primary'] == 1) {
              $primary = $company;
            }
          }

          // If no primary was found, just grab the first
          if (empty($primary)) {
            $primary = $companies[$id][0];
          }

          if (is_array($tmpContacts[$id])) {
            $tmpContacts[$id]['primaryCompany'] = $primary;
          } elseif ($tmpContacts[$id] instanceof Lead) {
            $tmpContacts[$id]->setPrimaryCompany($primary);
          }
        }

        if ($withPreferences) {
          $contactFrequencyRules = (isset($frequencyRules[$id])) ? $frequencyRules[$id] : [];
          $contactDncRules       = (isset($dncRules[$id])) ? $dncRules[$id] : [];

          $channelRules = Lead::generateChannelRules($contactFrequencyRules, $contactDncRules);
          if (is_array($tmpContacts[$id])) {
            $tmpContacts[$id]['channelRules'] = $channelRules;
          } elseif ($tmpContacts[$id] instanceof Lead) {
            $tmpContacts[$id]->setChannelRules($channelRules);
          }
        }
      }

      if ($withTotalCount) {
        $contacts['results'] = $tmpContacts;
      } else {
        $contacts = $tmpContacts;
      }
    }

    return $contacts;
  }


  /**
   * Overrides instance from LeadRepository, called by PluginBundle::pushLead
   *
   * Get a contact entity with the primary company data populated.
   *
   * The primary company data will be a flat array on the entity
   * with a key of `primaryCompany`
   *
   * @param mixed $entity
   *
   * @return mixed|null
   */
  public function getEntityWithPrimaryCompany($entity)
  {
    if (is_int($entity)) {
      $entity = $this->getEntity($entity);
    }

    if ($entity instanceof Lead) {
      $id        = $entity->getId();
      $companies = $this->getEntityManager()->getRepository('MauticLeadBundle:Company')->getCompaniesForContacts([$id]);

      if (!empty($companies[$id])) {
        $primary = null;

        foreach ($companies as $company) {
          if (isset($company['is_primary']) && $company['is_primary'] == 1) {
            $primary = $company;
          }
        }

        if (empty($primary)) {
          $primary = $companies[$id][0];
        }

        $entity->setPrimaryCompany($primary);
      }
    }

    return $entity;
  }

}
