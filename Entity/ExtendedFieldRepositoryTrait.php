<?php

namespace MauticPlugin\MauticExtendedFieldBundle\Entity;

use Doctrine\DBAL\Query\QueryBuilder;
use Mautic\LeadBundle\Entity\CustomFieldEntityTrait;
use Mautic\LeadBundle\Helper\CustomFieldHelper;

/**
 * Trait ExtendedFieldRepositoryTrait.
 */
trait ExtendedFieldRepositoryTrait
{
    use CustomFieldEntityTrait;

    /** @var array */
    protected $customExtendedFieldList = [];

    /** @var array */
    protected $customExtendedFieldSecureList = [];

    /** @var array */
    protected $customLeadFieldList = [];

    /**
     * @param string $object
     *
     * @return array [$fields, $fixedFields]
     */
    public function getCustomFieldList($object)
    {
        if ('lead' == $object) {
            $thisList = $this->customLeadFieldList;
        } else {
            $thisList = 'extendedField' == $object ? $this->customExtendedFieldList : $this->customExtendedFieldSecureList;
        }

        if (empty($thisList)) {
            //Get the list of custom fields
            if (isset($this->em)) {
                $fq = $this->em->getConnection()->createQueryBuilder();
            } else {
                $fq = $this->getEntityManager()
                    ->getConnection()
                    ->createQueryBuilder();
            }

            // if object==lead we really want everything but company
            if ('lead' == $object) {
                $objectexpr = 'company';
                $expr       = 'neq';
            } else {
                $expr       = 'eq';
                $objectexpr = $object;
            }

            $fq->select(
                'f.id, f.label, f.alias, f.type, f.field_group as "group", f.object, f.is_fixed'
            )
                ->from(MAUTIC_TABLE_PREFIX.'lead_fields', 'f')
                ->where('f.is_published = :published')
                ->andWhere($fq->expr()->$expr('object', ':object'))
                ->setParameter('published', true, 'boolean')
                ->setParameter('object', $objectexpr);
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

            if ('extendedField' == $object) {
                $this->customExtendedFieldList = [$fields, $fixedFields];
                $thisList                      = $this->customExtendedFieldList;
            } elseif ('extendedFieldSecure' == $object) {
                $this->customExtendedFieldSecureList = [$fields, $fixedFields];
                $thisList                            = $this->customExtendedFieldSecureList;
            } else {
                $this->customLeadFieldList = [$fields, $fixedFields];
                $thisList                  = $this->customLeadFieldList;
            }
        }

        return $thisList;
    }

    /**
     * @param        $id      (from leads table) identifies the lead
     * @param bool   $byGroup
     * @param string $object  = "extendedField" or "extendedFieldSecure"
     *
     * @return array
     */
    public function getExtendedFieldValues(
        $id,
        $byGroup = true,
        $object = 'leads'
    ) {
        //use DBAL to get entity fields

        $customExtendedFieldList = $this->getCustomFieldList($object);
        if ('lead' == $object) {
            $fields = $this->getFieldValues($id, false, 'lead');
        } else {
            $fields = [];
        }
        // the 0 key is the list of fields ;  the 1 key is the list of is_fixed fields
        foreach ($customExtendedFieldList[0] as $key => $customExtendedField) {
            if (false !== strpos($customExtendedField['object'], 'extendedField')) {
                // 'lead_fields_leads_'.$dataType.($secure ? '_secure' : '').'_xref');
                $fieldModel = $this->leadFieldModel;
                $dataType   = $fieldModel->getSchemaDefinition(
                    $customExtendedField['alias'],
                    $customExtendedField['type']
                );
                $dataType   = $dataType['type'];
                $secure     = 'extendedFieldSecure' == $object ? true : false;
                $tableName  = 'lead_fields_leads_'.$dataType.($secure ? '_secure' : '').'_xref';

                $fq = $this->getEntityManager()
                    ->getConnection()
                    ->createQueryBuilder();
                $fq->select('f.lead_id, f.lead_field_id, f.value')
                    ->from(MAUTIC_TABLE_PREFIX.$tableName, 'f')
                    ->where('f.lead_field_id = :lead_field_id')
                    ->andWhere($fq->expr()->eq('lead_id', ':lead_id'))
                    ->setParameter('lead_field_id', $customExtendedField['id'])
                    ->setParameter('lead_id', $id);
                $values                = $fq->execute()->fetchAll();
                $fields[$key]['value'] = !empty($values[0]) ? $values[0]['value'] : null;
            }
        }

        return $this->formatExtendedFieldValues(
            $fields,
            $byGroup,
            $object
        ); // should always be 0=>values, want just values
    }

    /**
     * @param array  $values
     * @param bool   $byGroup
     * @param string $object
     *
     * @return array
     */
    protected function formatExtendedFieldValues(
        $values,
        $byGroup = true,
        $object = 'extendedField'
    ) {
        list($fields, $fixedFields) = $this->getCustomFieldList($object);

        $this->removeNonFieldColumns($fields, $fixedFields);

        // Reorder leadValues based on field order

        $fieldValues = [];

        //loop over results to put fields in something that can be assigned to the entities
        foreach ($values as $k => $r) {
            if (!empty($values[$k])) {
                if (isset($r['value'])) {
                    $r = CustomFieldHelper::fixValueType(
                        $fields[$k]['type'],
                        $r['value']
                    );
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
                } else {
                    $r = null;
                }
            } else {
                $r = null;
            }
            if ($byGroup) {
                $fieldValues[$fields[$k]['group']][$fields[$k]['alias']]          = $fields[$k];
                $fieldValues[$fields[$k]['group']][$fields[$k]['alias']]['value'] = $r;
            } else {
                $fieldValues[$fields[$k]['alias']]          = $fields[$k];
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
        $table  = $this->getEntityManager()->getClassMetadata(
            $this->getClassName()
        )->getTableName();

        // Get Extended Fields to separate from standard Update statement.
        $extendedFields = [];
        $entityConfig   = $entity->getFields();
        foreach ($fields as $fieldname => $formData) {
            foreach ($entityConfig as $group) {
                if (isset($group[$fieldname]) && isset($group[$fieldname]['object']) && false !== strpos(
                        $group[$fieldname]['object'],
                        'extendedField'
                    )) {
                    $extendedFields[$fieldname]['value']  = $formData;
                    $extendedFields[$fieldname]['type']   = $group[$fieldname]['type'];
                    $extendedFields[$fieldname]['id']     = $group[$fieldname]['id'];
                    $extendedFields[$fieldname]['name']   = $fieldname;
                    $extendedFields[$fieldname]['secure'] = false !== strpos(
                        $group[$fieldname]['object'],
                        'Secure'
                    ) ? true : false;
                    unset($fields[$fieldname]);
                    break;
                }
            }
        }

        if (method_exists($entity, 'getChanges')) {
            $changes = $entity->getChanges();

            // remove the fields that are part of changes as they were already saved via a setter
            $fields = array_diff_key($fields, $changes);
            // Overriden to check a deeper recursion in changes since fields may already have been saved that
            // are not company fields, IE - extended fields and extended fields secure
            if (isset($changes['fields'])) {
                $fields = array_diff_key($fields, $changes['fields']);
            }
        }

        if (!empty($fields)) {
            $this->prepareDbalFieldsForSave($fields);
            $this->getEntityManager()->getConnection()->update(
                $table,
                $fields,
                ['id' => $entity->getId()]
            );
        }

        if (!empty($extendedFields)) {
            foreach ($extendedFields as $extendedField => $values) {
                $fieldModel    = $this->leadFieldModel;
                $dataType      = $fieldModel->getSchemaDefinition($values['name'], $values['type']);
                $dataType      = $dataType['type'];
                $column        = [
                    'lead_field_id' => $values['id'],
                    'value'         => $values['value'],
                ];
                $extendedTable = 'lead_fields_leads_'.$dataType.($values['secure'] ? '_secure' : '').'_xref';
                $this->prepareDbalFieldsForSave($column);

                // insert (no pre-existing value per lead) or update

                if (isset($changes['fields'])
                    && !empty($changes['fields'][$values['name']][0])
                    && empty($changes['fields'][$values['name']][1])) {
                    // need to delete the row from db table because new value is empty
                    $lead_id = $entity->getId();
                    $column  = [
                        'lead_field_id' => $values['id'],
                        'lead_id'       => $lead_id,
                    ];
                    $this->getEntityManager()->getConnection()->delete(
                        $extendedTable,
                        $column
                    );
                } else {
                    if (isset($changes['fields'])
                        && $changes['fields'][$values['name']][0] == null
                        && !empty($changes['fields'][$values['name']][1])) {
                        // need to do an insert, no previous value for this lead id
                        $column['lead_id'] = $entity->getId();
                        $this->getEntityManager()->getConnection()->insert(
                            $extendedTable,
                            $column
                        );
                    } else {
                        $this->getEntityManager()->getConnection()->update(
                            $extendedTable,
                            $column,
                            [
                                'lead_id'       => $entity->getId(),
                                'lead_field_id' => $values['id'],
                            ]
                        );
                    }
                }
            }
        }

        $this->postSaveEntity($entity);
    }

    /**
     * @param      $object
     * @param      $args
     * @param null $resultsCallback
     *
     * @return array
     */
    public function getEntitiesWithCustomFields(
        $object,
        $args,
        $resultsCallback = null
    ) {
        $originalArgs               = $args;
        list($fields, $fixedFields) = $this->getCustomFieldList($object);
        $extendedFieldList          = [];
        foreach ($fields as $k => $field) {
            if (false !== strpos($field['object'], 'extended')) {
                $extendedFieldList[$k] = $field;
            }
        }

        //Fix arguments if necessary
        $args = $this->convertOrmProperties($this->getClassName(), $args);

        //DBAL
        /** @var QueryBuilder $dq */
        $dq = isset($args['qb']) ? $args['qb'] : $this->getEntitiesDbalQueryBuilder();

        // check to see if $args has any extendedFields
        $extendedFieldFilters = !empty($this->ExtendedFieldFilters) ? $this->ExtendedFieldFilters : $this->getExtendedFieldFilters(
            $args,
            $extendedFieldList
        );

        // Generate where clause first to know if we need to use distinct on primary ID or not
        $this->useDistinctCount = false;

        if (!empty($extendedFieldFilters)) {
            $this->ExtendedBuildWhereClause($dq, $args, $extendedFieldFilters);
        } else {
            $this->buildWhereClause($dq, $args);
        }

        // Distinct is required here to get the correct count when group by is used due to applied filters
        $countSelect = ($this->useDistinctCount) ? 'COUNT(DISTINCT('.$this->getTableAlias(
            ).'.id))' : 'COUNT('.$this->getTableAlias().'.id)';
        $dq->select($countSelect.' as count');

        // Advanced search filters may have set a group by and if so, let's remove it for the count.
        if ($groupBy = $dq->getQueryPart('groupBy')) {
            $dq->resetQueryPart('groupBy');
        }

        $query = $dq->getSQL(); // debug purposes only

        //get a total count
        $result = $dq->execute()->fetchAll();
        $total  = ($result) ? $result[0]['count'] : 0;

        if (!$total) {
            $results = [];
        } else {
            if ($groupBy) {
                $dq->groupBy($groupBy);
            }
            //now get the actual paginated results

            $this->buildOrderByClause($dq, $args);
            $this->buildLimiterClauses($dq, $args);

            $dq->resetQueryPart('select');
            $this->buildSelectClause($dq, $args);

            $query = $dq->getSQL(); // debug purposes only

            $results = $dq->execute()->fetchAll();

            //loop over results to put fields in something that can be assigned to the entities
            $fieldValues         = [];
            $groups              = $this->getFieldGroups();
            $lead_ids            = array_map('reset', $results);
            $extendedFieldValues = $this->getExtendedFieldValuesMultiple(
                $extendedFieldList,
                $lead_ids
            );

            foreach ($results as $result) {
                $id = $result['id'];
                //unset all the columns that are not fields
                $this->removeNonFieldColumns($result, $fixedFields);

                foreach ($result as $k => $r) {
                    if (isset($fields[$k])) {
                        $fieldValues[$id][$fields[$k]['group']][$fields[$k]['alias']]          = $fields[$k];
                        $fieldValues[$id][$fields[$k]['group']][$fields[$k]['alias']]['value'] = $r;
                    }
                    // And...add the extended field to result if the current lead has that field value
                    foreach ($extendedFieldList as $fieldToAdd => $e_config) {
                        // todo Apply filters from extended fields
                        $e_value                                                                                 = isset($extendedFieldValues[$id][$fieldToAdd]) ? $extendedFieldValues[$id][$fieldToAdd] : null;
                        $fieldValues[$id][$fields[$fieldToAdd]['group']][$fields[$fieldToAdd]['alias']]          = $fields[$fieldToAdd];
                        $fieldValues[$id][$fields[$fieldToAdd]['group']][$fields[$fieldToAdd]['alias']]['value'] = $e_value;
                    }
                }

                //make sure each group key is present
                foreach ($groups as $g) {
                    if (!isset($fieldValues[$id][$g])) {
                        $fieldValues[$id][$g] = [];
                    }
                }
            }

            unset($results, $fields);

            //get an array of IDs for ORM query
            $ids = array_keys($fieldValues);

            if (count($ids)) {
                //ORM

                //build the order by id since the order was applied above
                //unfortunately, doctrine does not have a way to natively support this and can't use MySQL's FIELD function
                //since we have to be cross-platform; it's way ugly

                //We should probably totally ditch orm for leads
                $order = '(CASE';
                foreach ($ids as $count => $id) {
                    $order .= ' WHEN '.$this->getTableAlias().'.id = '.$id.' THEN '.$count;
                    ++$count;
                }
                $order .= ' ELSE '.$count.' END) AS HIDDEN ORD';

                //ORM - generates lead entities
                $q = $this->getEntitiesOrmQueryBuilder($order);
                $this->buildSelectClause($dq, $args);

                //only pull the leads as filtered via DBAL
                $q->where(
                    $q->expr()->in($this->getTableAlias().'.id', ':entityIds')
                )->setParameter('entityIds', $ids);

                $q->orderBy('ORD', 'ASC');

                $results = $q->getQuery()
                    ->getResult();

                //assign fields
                foreach ($results as $r) {
                    $id = $r->getId();
                    $r->setFields($fieldValues[$id]);

                    if (is_callable($resultsCallback)) {
                        $resultsCallback($r);
                    }
                }
            } else {
                $results = [];
            }
        }

        return (!empty($args['withTotalCount'])) ?
            [
                'count'   => $total,
                'results' => $results,
            ] : $results;
    }

    /**
     * @param string|array $args
     * @param array        $extendedFieldList
     *
     * @return array
     */
    private function getExtendedFieldFilters($args, $extendedFieldList)
    {
        $result = [];

        if (isset($args['filter'])) {
            foreach (array_keys($extendedFieldList) as $extendedField) {
                // @todo - this strpos checking will need to be refactored to use regex and check word boundries.
                if (
                    (is_string($args['filter']['string']) && false !== strpos(
                            $args['filter']['string'],
                            $extendedField
                        ))
                    || (is_string($args['filter']['force']) && false !== strpos(
                            $args['filter']['force'],
                            $extendedField
                        ))
                ) {
                    // field is in the filter array somewhere
                    $result[$extendedField] = $extendedFieldList[$extendedField];
                    continue;
                }
                $extendedFieldLength = strlen($extendedField);
                foreach (['string', 'force'] as $type) {
                    if (is_array($args['filter'][$type])) {
                        foreach ($args['filter'][$type] as $filter) {
                            if (isset($filter['column'])) {
                                if (substr(
                                        $filter['column'],
                                        strlen($filter['column']) - $extendedFieldLength - 1
                                    ) == '.'.$extendedField) {
                                    $result[$extendedField] = $extendedFieldList[$extendedField];
                                    continue;
                                }
                            }
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param array $extendedFieldList
     *
     * @return mixed
     */
    public function getExtendedFieldValuesMultiple(
        $extendedFieldList = [],
        $lead_ids = []
    ) {
        if (empty($extendedFieldList)) {
            return [];
        }
        // get a query builder for extendedField values to get.
        if (isset($this->em)) {
            $eq = $this->em->getConnection();
        } else {
            $eq = $this->getEntityManager()->getConnection();
        }
        $extendedTables = [];
        $ex_expr        = '';
        $ids_str        = implode(',', $lead_ids);
        $where_in       = !empty($lead_ids) ? "Where lead_id IN ($ids_str)" : '';
        foreach ($extendedFieldList as $k => $details) {
            $fieldModel = $this->leadFieldModel;
            $dataType   = $fieldModel->getSchemaDefinition($details['alias'], $details['type']);
            $dataType   = $dataType['type'];
            // get extendedField Filters first
            // its an extended field, build a join expressions
            $secure    = false !== strpos(
                $details['object'],
                'Secure'
            ) ? '_secure' : '';
            $tableName = 'lead_fields_leads_'.$dataType.$secure.'_xref';
            if (!isset($extendedTables[$tableName])) {
                $count            = count($extendedTables);
                $union            = $count > 0 ? ' UNION' : '';
                $extendedTables[] = $tableName; //array of tables to query now

                $ex_expr .= "$union SELECT t$count.lead_id, t$count.lead_field_id, t$count.value, lf.alias FROM $tableName t$count LEFT JOIN lead_fields lf ON t$count.lead_field_id = lf.id $where_in";
            }
        }
        $ex_query = $eq->prepare($ex_expr);
        $ex_query->execute();
        $results = $ex_query->fetchAll();
        // group results by lead_id
        $leads = [];
        foreach ($results as $result) {
            $leads[$result['lead_id']][$result['alias']] = $result['value'];
        }

        return $leads;
    }
}
