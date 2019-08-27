<?php

namespace MauticPlugin\MauticExtendedFieldBundle\Entity;

use Doctrine\DBAL\Connections\MasterSlaveConnection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\EntityManager;
use Mautic\LeadBundle\Entity\CustomFieldEntityTrait;

/**
 * Trait ExtendedFieldRepositoryTrait.
 *
 * Used by: OverrideLeadRepository
 * Overrides: CustomFieldRepositoryTrait
 *
 * When used in addition to CustomFieldRepositoryTrait, it overrides:
 *  CustomFieldRepositoryTrait::getCustomFieldList
 *  CustomFieldRepositoryTrait::saveEntity
 *  CustomFieldRepositoryTrait::getEntitiesWithCustomFields
 *
 * Adds:
 *  getExtendedFieldValues          (used in OverrideLeadRepository, but only calls getExtendedFieldValuesMultiple now)
 *  getExtendedFieldValuesMultiple  (used only here)
 *  formatExtendedFieldValues       (used only here)
 *  getExtendedFieldFilters         (used only here)
 */
trait ExtendedFieldRepositoryTrait
{
    use CustomFieldEntityTrait;

    /** @var array */
    protected $customFieldListByObject = [];

    /**
     * Alterations to core:
     *  Include extended objects when requesting lead custom fields.
     *
     * Merging:
     *  When merging to core, the core method should instead use getEntities instead of a query,
     *      then we won't have to modify the method for extended field compatibility.
     *  The original method doesn't seem to be cognizant of object changes.
     *
     * @param string $object
     *
     * @return array [$fields, $fixedFields]
     */
    public function getCustomFieldList($object)
    {
        if (empty($this->customFieldListByObject[$object])) {
            if ('lead' !== $object) {
                return parent::getCustomFieldList($object);
            }

            //Get the list of custom fields
            /** @var QueryBuilder $fq */
            $fq = $this->getEntityManager()->getConnection()->createQueryBuilder();
            $fq->select('f.id, f.label, f.alias, f.type, f.field_group as "group", f.object, f.is_fixed')
                ->from(MAUTIC_TABLE_PREFIX.'lead_fields', 'f')
                ->where('f.is_published = :published')
                ->andWhere(
                    $fq->expr()->orX(
                        $fq->expr()->eq('f.object', $fq->expr()->literal('lead')),
                        $fq->expr()->eq('f.object', $fq->expr()->literal('extendedField')),
                        $fq->expr()->eq('f.object', $fq->expr()->literal('extendedFieldSecure'))
                    )
                )
                ->setParameter('published', true, 'boolean');
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

            $this->customFieldListByObject[$object] = [$fields, $fixedFields];
        }

        return $this->customFieldListByObject[$object];
    }

    /**
     * Extends:
     *  getFieldValues to include extended field values.
     *
     * @param        $id      (from leads table) identifies the lead
     * @param bool   $byGroup
     * @param string $object  = "extendedField" or "extendedFieldSecure"
     *
     * @return array
     */
    public function getExtendedFieldValues(
        $id,
        $byGroup = true,
        $object = 'lead'
    ) {
        if ('lead' !== $object) {
            return $this->getFieldValues($id, $byGroup, $object);
        }

        $fields = $this->getFieldValues($id, false, $object);

        $values = [];
        foreach ($fields as $key => $value) {
            $values[$key] = $value['value'];
        }

        // Discern which fields are extended.
        $extendedFieldList = [];
        foreach ($fields as $key => $field) {
            if (in_array($field['object'], ['extendedField', 'extendedFieldSecure'])) {
                $extendedFieldList[$key] = $field;
            }
        }

        // Get the values of the extended fields.
        $extendedFieldValues = $this->getExtendedFieldValuesMultiple($extendedFieldList, [$id]);
        if ($extendedFieldValues) {
            $extendedFieldValues = reset($extendedFieldValues);
        }

        // Update the extended fields with the values retrieved.
        foreach ($extendedFieldList as $key => $field) {
            if (isset($extendedFieldValues[$key])) {
                $values[$key] = $extendedFieldValues[$key];
            } else {
                // The value would be an ID so we should always nullify.
                $values[$key] = null;
            }
        }

        return $this->formatFieldValues($values, $byGroup, $object);
    }

    /**
     * Join all the EAV data into one consumable array.
     *
     * @param array $extendedFieldList
     * @param array $leadIds
     *
     * @return array
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    private function getExtendedFieldValuesMultiple(
        $extendedFieldList = [],
        $leadIds = []
    ) {
        $leads = [];

        if (!$leadIds) {
            return $leads;
        }

        /** @var EntityManager $em */
        $em = $this->getEntityManager();

        // Attempt to use a slave connection as this can become a heavy query.
        $connection = $em->getConnection();
        if ($connection instanceof MasterSlaveConnection) {
            $connection->connect('slave');
        }

        $leadIdsStr = implode(',', $leadIds);

        $selects = [];
        foreach (['string', 'float', 'boolean', 'date', 'datetime', 'time', 'text'] as $data_type) {
            foreach (['', '_secure'] as $secure) {
                $xrefTable = MAUTIC_TABLE_PREFIX."lead_fields_leads_{$data_type}{$secure}_xref";

                $selects[] = <<<EOSQL
SELECT lead_id, lead_field_id, `value`
FROM  {$xrefTable}
WHERE lead_id IN ({$leadIdsStr})
AND `value` IS NOT NULL
EOSQL;
            }
        }
        $xrefQuery = implode("\nUNION\n", $selects);

        $leadsTable      = $em->getRepository('MauticLeadBundle:Lead')->getTableName();
        $lt              = $em->getRepository('MauticLeadBundle:Lead')->getTableAlias();
        $leadFieldsTable = $em->getRepository('MauticLeadBundle:LeadField')->getTableName();
        $lft             = $em->getRepository('MauticLeadBundle:LeadField')->getTableAlias();

        $where = "{$lft}.object IN ('extendedField','extendedFieldSecure') ";
        if (!empty($extendedFieldList)) {
            $ids             = array_map(
                function ($e) {
                    return (int) $e['id'];
                },
                $extendedFieldList
            );
            $leadFiieldIdStr = implode(',', $ids);
            $where           = "{$lft}.id IN ({$leadFiieldIdStr}) ";
        }

        $sql = <<<EOSQL
SELECT x.lead_id, x.alias, v.value
FROM
(
    SELECT {$lt}.id AS lead_id,
    {$lft}.id as lead_field_id,
    {$lft}.alias
    FROM {$leadsTable} {$lt} CROSS JOIN {$leadFieldsTable} {$lft}
    WHERE {$where}
    AND {$lt}.id  IN ({$leadIdsStr})
) x
LEFT JOIN
(
    {$xrefQuery}
) v USING (lead_id, lead_field_id)
WHERE v.value IS NOT NULL
EOSQL;

        // Using executeQuery to ensure this can be executed on a slave MySQL.
        $stmt = $connection->executeQuery($sql);
        while ($result = $stmt->fetch()) {
            if (!isset($leads[$result['lead_id']])) {
                $leads[$result['lead_id']] = [];
            }
            $leads[$result['lead_id']][$result['alias']] = $result['value'];
        }

        return $leads;
    }

    /**
     * Alterations to core:
     *  Saves all extended field data in xref tables.
     *
     * @param $entity
     * @param $flush
     */
    public function saveEntity($entity, $flush = true)
    {
        $this->preSaveEntity($entity);

        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush($entity);
        }

        // Get updated fields, and include changes so that we have everything.
        $fields  = $entity->getUpdatedFields();
        $changes = [];
        if (method_exists($entity, 'getChanges')) {
            $changes = $entity->getChanges();

            // remove the fields that are part of changes as they were already saved via a setter
            $fields = array_diff_key($fields, $changes);
        }

        // Get Extended Fields to separate from standard Update statement.
        $extendedFields = [];
        $fieldList      = $this->getCustomFieldList('lead');
        foreach ($fields as $alias => $value) {
            if (in_array($fieldList[0][$alias]['object'], ['extendedField', 'extendedFieldSecure'])) {
                // Depends on object added by alteration in organizeFieldsByGroup.
                $extendedFields[$alias]['object'] = $fieldList[0][$alias]['object'];
                $extendedFields[$alias]['id']     = $fieldList[0][$alias]['id'];
                $extendedFields[$alias]['type']   = $fieldList[0][$alias]['type'];
                $extendedFields[$alias]['alias']  = $alias;
                $extendedFields[$alias]['value']  = $value;
                unset($fields[$alias]);
            }
        }

        $this->prepareDbalFieldsForSave($fields);

        // Save standard/core fields (same as core).
        if (!empty($fields)) {
            $table = $this->getEntityManager()->getClassMetadata($this->getClassName())->getTableName();
            $this->getEntityManager()->getConnection()->update($table, $fields, ['id' => $entity->getId()]);
        }

        // Now to update extended fields if there were any to be updated.
        if (!empty($extendedFields)) {
            $insertUpdates = $deletions = [];
            $leadId        = $entity->getId();
            /** @var \Doctrine\DBAL\Connection $connection */
            $connection = $this->getEntityManager()->getConnection();
            foreach ($extendedFields as $extendedField) {
                if (
                    !isset($changes['fields'])
                    || !isset($changes['fields'][$extendedField['alias']])
                ) {
                    // No change to this extended field detected.
                    continue;
                }
                $schema  = $this->leadFieldModel->getSchemaDefinition($extendedField['alias'], $extendedField['type']);
                $columns = [
                    'value' => $extendedField['value'],
                ];
                // Corrects boolean field values, nothing more at this point.
                $this->prepareDbalFieldsForSave($columns);
                $secure    = 'extendedFieldSecure' === $extendedField['object'] ? '_secure' : '';
                $tableName = MAUTIC_TABLE_PREFIX.'lead_fields_leads_'.$schema['type'].$secure.'_xref';

                if (
                    null !== $changes['fields'][$extendedField['alias']][0]
                    && null === $changes['fields'][$extendedField['alias']][1]
                ) {
                    // Removed an existing value.
                    $connection->delete(
                        $tableName,
                        [
                            'lead_id'       => $leadId,
                            'lead_field_id' => $extendedField['id'],
                        ]
                    );
                } else {
                    // Mark for insert/update.
                    if (!isset($insertUpdates[$tableName])) {
                        $insertUpdates[$tableName] = [];
                    }
                    if (!isset($insertUpdates[$tableName][$extendedField['id']])) {
                        $insertUpdates[$tableName][$extendedField['id']] = $columns['value'];
                    }
                }
            }
            // Handle inserts/updates in bulk by table.
            if ($insertUpdates) {
                foreach ($insertUpdates as $tableName => $leadField) {
                    $rows = $params = [];
                    foreach ($leadField as $leadFieldId => $value) {
                        $key          = $tableName.$leadFieldId;
                        $rows[]       = $leadId.', '.$leadFieldId.', :'.$key;
                        $params[$key] = $value;
                    }
                    $sql  = 'INSERT INTO '.$tableName.' (`lead_id`, `lead_field_id`, `value`) '.
                        'VALUES ('.implode('),(', $rows).') '.
                        'ON DUPLICATE KEY UPDATE `value`=VALUES(`value`);';
                    $stmt = $connection->prepare($sql);
                    $stmt->execute($params);
                }
            }
        }

        $this->postSaveEntity($entity);
    }

    /**
     * Alterations to core:
     *  If extended fields are being used, it uses ExtendedBuildWhereClause instead of buildWhereClause.
     *  Overlays field values with extended field values.
     *
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
        // Run core method if we are not dealing with a lead.
        if ('lead' !== $object) {
            return parent::getEntitiesWithCustomFields($object, $args, $resultsCallback);
        }

        list($fields, $fixedFields) = $this->getCustomFieldList($object);

        //Fix arguments if necessary
        $args = $this->convertOrmProperties($this->getClassName(), $args);

        //DBAL
        /** @var QueryBuilder $dq */
        $dq = isset($args['qb']) ? $args['qb'] : $this->getEntitiesDbalQueryBuilder();

        // Generate where clause first to know if we need to use distinct on primary ID or not
        $this->useDistinctCount = false;

        // Alteration to core start.
        // Check to see if $args has any extendedFields.
        $extendedFieldList = [];
        foreach ($fields as $k => $field) {
            if (in_array($field['object'], ['extendedField', 'extendedFieldSecure'])) {
                $extendedFieldList[$k] = $field;
            }
        }
        $extendedFieldFilters = !empty($this->ExtendedFieldFilters) ? $this->ExtendedFieldFilters : $this->getExtendedFieldFilters(
            $args,
            $extendedFieldList
        );
        if (!empty($extendedFieldFilters)) {
            $this->ExtendedBuildWhereClause($dq, $args, $extendedFieldFilters);
        } else {
            $this->buildWhereClause($dq, $args);
        }
        // Alteration to core end.

        // Distinct is required here to get the correct count when group by is used due to applied filters
        $countSelect = ($this->useDistinctCount) ? 'COUNT(DISTINCT('.$this->getTableAlias(
            ).'.id))' : 'COUNT('.$this->getTableAlias().'.id)';
        $dq->select($countSelect.' as count');

        // Advanced search filters may have set a group by and if so, let's remove it for the count.
        if ($groupBy = $dq->getQueryPart('groupBy')) {
            $dq->resetQueryPart('groupBy');
        }

        $total   = 0;
        $results = [];
        if (isset($args['withTotalCount']) && $args['withTotalCount']) {
            //get a total count
            $result = $dq->execute()->fetchAll();
            $total  = ($result) ? $result[0]['count'] : $total;
            if (!$total) {
                return [
                    'count'   => $total,
                    'results' => $results,
                ];
            }
        }

        if ($total || !(isset($args['withTotalCount']) && $args['withTotalCount'])) {
            if ($groupBy) {
                $dq->groupBy($groupBy);
            }
            //now get the actual paginated results

            $this->buildOrderByClause($dq, $args);
            $this->buildLimiterClauses($dq, $args);

            $dq->resetQueryPart('select');
            $this->buildSelectClause($dq, $args);

            $results = $dq->execute()->fetchAll();

            //loop over results to put fields in something that can be assigned to the entities
            $fieldValues = [];
            $groups      = $this->getFieldGroups();

            // Alteration to core start.
            $lead_ids            = array_map('reset', $results);
            $extendedFieldValues = $this->getExtendedFieldValuesMultiple(
                $extendedFieldList,
                $lead_ids
            );
            // Alteration to core end.

            foreach ($results as $result) {
                $id = $result['id'];
                //unset all the columns that are not fields
                $this->removeNonFieldColumns($result, $fixedFields);

                foreach ($result as $k => $r) {
                    if (isset($fields[$k])) {
                        $fieldValues[$id][$fields[$k]['group']][$fields[$k]['alias']]          = $fields[$k];
                        $fieldValues[$id][$fields[$k]['group']][$fields[$k]['alias']]['value'] = $r;
                    }
                }

                // Alteration to core start.
                // Add the extended field to result if the current lead has that field value
                foreach ($extendedFieldList as $fieldToAdd => $e_config) {
                    $e_value                                                                                 = isset($extendedFieldValues[$id][$fieldToAdd]) ? $extendedFieldValues[$id][$fieldToAdd] : null;
                    $fieldValues[$id][$fields[$fieldToAdd]['group']][$fields[$fieldToAdd]['alias']]          = $fields[$fieldToAdd];
                    $fieldValues[$id][$fields[$fieldToAdd]['group']][$fields[$fieldToAdd]['alias']]['value'] = $e_value;
                }
                // Alteration to core end.

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
                /** @var \Doctrine\ORM\QueryBuilder $q */
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
                /** @var Lead $r */
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

        return (isset($args['withTotalCount']) && $args['withTotalCount']) ?
            [
                'count'   => $total,
                'results' => $results,
            ] : $results;
    }

    /**
     * Discern search filters that are bound to extended fields.
     *
     * @param string|array $args
     * @param array        $extendedFieldList
     *
     * @return array
     */
    private function getExtendedFieldFilters($args, $extendedFieldList)
    {
        $result = [];
        if (isset($args['filter'])) {
            foreach (array_keys($extendedFieldList) as $alias) {
                // @todo - Instead of strpos use regex w/ word boundry, loop below needs cleanup.
                if (
                    isset($args['filter']['string'])
                    && (
                        (
                            is_string($args['filter']['string']) && false !== strpos(
                                $args['filter']['string'],
                                $alias
                            )
                        )
                        || (
                            is_string($args['filter']['force']) && false !== strpos(
                                $args['filter']['force'],
                                $alias
                            )
                        )
                    )
                ) {
                    // field is in the filter array somewhere
                    $result[$alias] = $extendedFieldList[$alias];
                    continue;
                }
                $extendedFieldLength = strlen($alias);
                foreach (['string', 'force'] as $type) {
                    if (
                        isset($args['filter'][$type])
                        && is_array($args['filter'][$type])
                    ) {
                        foreach ($args['filter'][$type] as $filter) {
                            if (isset($filter['column'])) {
                                if (substr(
                                        $filter['column'],
                                        strlen($filter['column']) - $extendedFieldLength - 1
                                    ) == '.'.$alias) {
                                    $result[$alias] = $extendedFieldList[$alias];
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
}
