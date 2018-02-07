<?php

namespace MauticPlugin\MauticExtendedFieldBundle\Entity;

use Mautic\LeadBundle\Helper\CustomFieldHelper;
use Doctrine\DBAL\Query\QueryBuilder;


trait ExtendedFieldRepositoryTrait
{

  /**
   * @var array
   */
  protected $customExtendedFieldList = [];

  /**
   * @var array
   */
  protected $customExtendedFieldSecureList = [];


  /**
   * @var array
   */
  protected $fields = [];


  /**
   * @param string $object
   *
   * @return array [$fields, $fixedFields]
   */
  public function getExtendedCustomFieldList($object)
  {
    $thisList = $object == 'extendedField' ? $this->customExtendedFieldList : $this->customExtendedFieldSecureList;
    if (empty($thisList)) {
      //Get the list of custom fields
      $fq = $this->getEntityManager()->getConnection()->createQueryBuilder();
      $fq->select('f.id, f.label, f.alias, f.type, f.field_group as "group", f.object, f.is_fixed')
        ->from(MAUTIC_TABLE_PREFIX.'lead_fields', 'f')
        ->where('f.is_published = :published')
        ->andWhere($fq->expr()->eq('object', ':object'))
        ->setParameter('published', true, 'boolean')
        ->setParameter('object', $object);
      $results = $fq->execute()->fetchAll();

      $fields      = [];
      $fixedFields = [];
      foreach ($results as $r) {
        $fields[$r['alias']] = $r;
        if ($r['is_fixed']) {
          $fixedFields[$r['alias']] = $r['alias'];
        }
      }

      unset($results);

      if($object=='extendedField') {
        $this->customExtendedFieldList = [$fields, $fixedFields];
        $thisList = $this->customExtendedFieldList;
      } else {
        $this->customExtendedFieldSecureList = [$fields, $fixedFields];
        $thisList = $this->customExtendedFieldSecureList;
      }
    }

    return $thisList;
  }


  /**
   * @param        $id (from leads table) identifies the lead
   * @param bool   $byGroup
   * @param string $object = "extendedField" or "extendedFieldSecure"
   * @param string $object = "extendedField" or "extendedFieldSecure"
   *
   * @return array
   */
  public function getExtendedFieldValues($id, $byGroup = true, $object = 'extendedField')
  {
    //use DBAL to get entity fields

    $customExtendedFieldList = $this->getExtendedCustomFieldList($object);
    $fields=[];
    // the 0 key is the list of fields ;  the 1 key is the list of is_fixed fields
    foreach($customExtendedFieldList[0] as $key => $customExtendedField) {
      // 'lead_fields_leads_'.$dataType.($secure ? '_secure' : '').'_xref');
      $dataType = $customExtendedField['type'];
      $secure = $object == 'extendedFieldSecure' ? TRUE : FALSE;
      $tableName = 'lead_fields_leads_' . $dataType . ($secure ? '_secure' : '') . '_xref';

      $fq = $this->getEntityManager()->getConnection()->createQueryBuilder();
      $fq->select('f.lead_id, f.lead_field_id, f.value')
        ->from(MAUTIC_TABLE_PREFIX . $tableName, 'f')
        ->where('f.lead_field_id = :lead_field_id')
        ->andWhere($fq->expr()->eq('lead_id', ':lead_id'))
        ->setParameter('lead_field_id', $customExtendedField['id'])
        ->setParameter('lead_id', $id);
      $values = $fq->execute()->fetchAll();
      $fields[$key] = reset($values);
    }

    return $this->formatExtendedFieldValues($fields, $byGroup, $object); // should always be 0=>values, want just values
  }

  /**
   * @param array  $values
   * @param bool   $byGroup
   * @param string $object
   *
   * @return array
   */
  protected function formatExtendedFieldValues($values, $byGroup = true, $object = 'extendedField') {
    list($fields, $fixedFields) = $this->getExtendedCustomFieldList($object);

    $this->removeNonFieldColumns($values, $fixedFields);

    // Reorder leadValues based on field order


    $fieldValues = [];

    //loop over results to put fields in something that can be assigned to the entities
    foreach ($values as $k => $r) {
      if (!empty($values[$k])) {
        if (isset($r['value'])) {
          $r = CustomFieldHelper::fixValueType($fields[$k]['type'], $r['value']);
          if (!is_null($r)) {
            switch ($fields[$k]['type']) {
              case 'number':
                $r = (float) $r;
                break;
              case 'boolean':
                $r = (int) $r;
                break;
            }
          }
        }
        else {
          $r = NULL;
        }
      }
      else {
        $r = NULL;
      }
      if ($byGroup) {
        $fieldValues[$fields[$k]['group']][$fields[$k]['alias']] = $fields[$k];
        $fieldValues[$fields[$k]['group']][$fields[$k]['alias']]['value'] = $r;
      }
      else {
        $fieldValues[$fields[$k]['alias']] = $fields[$k];
        $fieldValues[$fields[$k]['alias']]['value'] = $r;
      }
      unset($fields[$k]);
    }

    if ($byGroup) {
      //make sure each group key is present
      $groups = $this->getFieldGroups();
      foreach ($groups as $g) {
        if (!isset($fieldValues[$g])) {
          $fieldValues[$g] = [];
        }
      }
    }

    return $fieldValues;
  }

  /**
   * {@inheritdoc}
   *
   * @param $entity
   * @param $flush
   */
  public function saveExtendedEntity($entity, $flush = true)
  {
    $this->preSaveEntity($entity);

    $this->getEntityManager()->persist($entity);

    if ($flush) {
      $this->getEntityManager()->flush($entity);
    }

    // Includes prefix
    $fields = $entity->getUpdatedFields();
    $table  = $this->getEntityManager()->getClassMetadata($this->getClassName())->getTableName();

    // Get Extended Fields to separate from standard Update statement.
    $extendedFields=[];
    $entityConfig = $entity->getFields();
    foreach($fields as $fieldname=>$formData) {
      foreach ($entityConfig as $group) {
        foreach ($group as $field => $config) {
          if ($field === $fieldname && isset($config['object']) && strpos($config['object'], 'extendedField') !== FALSE) {
            $extendedFields[$fieldname]['value'] = $formData;
            $extendedFields[$fieldname]['type'] = $config['type'];
            $extendedFields[$fieldname]['id'] = $config['id'];
            $extendedFields[$fieldname]['name'] = $fieldname;
            $extendedFields[$fieldname]['secure'] = strpos($config['object'], 'Secure') !== FALSE ? TRUE : FALSE;
            unset($fields[$fieldname]);
            break 2;
          }
        }
      }
    }


    if (method_exists($entity, 'getChanges')) {
      $changes = $entity->getChanges();

      // remove the fields that are part of changes as they were already saved via a setter
      $fields = array_diff_key($fields, $changes);
    }

    if (!empty($fields)) {
      $this->prepareDbalFieldsForSave($fields);
      $this->getEntityManager()->getConnection()->update($table, $fields, ['id' => $entity->getId()]);
    }

    if (!empty($extendedFields)) {

      foreach($extendedFields as $extendedField => $values){
        $column = array('lead_field_id' => $values['id'], 'value' => $values['value']);
        $extendedTable = 'lead_fields_leads_' . $values['type'] . ($values['secure'] ? '_secure' : '') . '_xref';
        $this->prepareDbalFieldsForSave($column);

        // insert (no pre-existing value per lead) or update

        if($changes['fields'][$values['name']][0] == NULL){
            // need to do an insert, no previous value for this lead id
          $column['lead_id'] = $entity->getId();
          $this->getEntityManager()->getConnection()->insert($extendedTable, $column);

        } else {
          $this->getEntityManager()->getConnection()->update($extendedTable, $column, ['lead_id' => $entity->getId()]);

        }
      }
    }

    $this->postSaveEntity($entity);
  }




}