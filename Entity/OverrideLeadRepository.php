<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Scott Shipman
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 *
 * Provides methods to override the LeadBundle LeadRepository.php
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

//  use CustomFieldRepositoryTrait;
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

  /**
   * **********   NOT USED YET  ***********************
   *
   * Overrides LeadBundle instance of getLeadIdsByUniqueFields
   * to handle extended field table schema differences from lead table
   * IE - needs a join and pivot on columns
   *
   * Get list of lead Ids by unique field data.
   *
   * @param $uniqueFieldsWithData is an array of columns & values to filter by
   * @param int $leadId is the current lead id. Added to query to skip and find other leads
   *
   * @return array
   */
  public function getLeadIdsByUniqueFields($uniqueFieldsWithData, $leadId = null)
  {
    $q = $this->getEntityManager()->getConnection()->createQueryBuilder()
      ->select('l.id')
      ->from(MAUTIC_TABLE_PREFIX.'leads', 'l');

    // loop through the fields and
    foreach ($uniqueFieldsWithData as $col => $val) {
      $q->orWhere("l.$col = :".$col)
        ->setParameter($col, $val);
    }

    // if we have a lead ID lets use it
    if (!empty($leadId)) {
      // make sure that its not the id we already have
      $q->andWhere('l.id != '.$leadId);
    }

    $results = $q->execute()->fetchAll();

    return $results;
  }


}
