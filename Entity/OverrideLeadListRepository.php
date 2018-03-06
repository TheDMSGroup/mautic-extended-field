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

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\DateType;
use Doctrine\DBAL\Types\FloatType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\TimeType;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Mautic\CoreBundle\Doctrine\QueryFormatter\AbstractFormatter;
use Mautic\CoreBundle\Doctrine\Type\UTCDateTimeType;
use Mautic\CoreBundle\Helper\DateTimeHelper;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Entity\LeadListRepository;
use Mautic\LeadBundle\Event\LeadListFilteringEvent;
use Mautic\LeadBundle\Event\LeadListFiltersOperatorsEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\MauticExtendedFieldBundle\Model\ExtendedFieldModel;

/**
 * OverrideLeadRepository.
 */
class OverrideLeadListRepository extends LeadListRepository
{
    /** @var \Doctrine\DBAL\Schema\Column[] */
    protected $extendedFieldTableSchema;

    /** @var bool */
    protected $hasExtendedFieldFilter = false;

    /** @var ExtendedFieldModel */
    protected $fieldModel;

    /**
     * OverrideLeadListRepository constructor.
     * Initializes a new EntityRepository.
     *
     * @param EntityManager      $em
     * @param ClassMetadata      $class
     * @param ExtendedFieldModel $fieldModel
     */
    public function __construct(EntityManager $em, ClassMetadata $class, ExtendedFieldModel $fieldModel)
    {
        parent::__construct($em, $class);
        $this->fieldModel = $fieldModel;
    }

    /**
     * Overrides the LeadBundle LeadListRepository.php getLeadsByList method
     * adds left joins for extendedField xref tables when needed.
     *
     * @param       $lists
     * @param array $args
     *
     * @return array
     */
    public function getLeadsByList($lists, $args = [])
    {
        // Return only IDs
        $idOnly = (!array_key_exists(
            'idOnly',
            $args
        )) ? false : $args['idOnly'];
        // Return counts
        $countOnly = (!array_key_exists(
            'countOnly',
            $args
        )) ? false : $args['countOnly'];
        // Return only leads that have not been added or manually manipulated to the lists yet
        $newOnly = (!array_key_exists(
            'newOnly',
            $args
        )) ? false : $args['newOnly'];
        // Return leads that do not belong to a list based on filters
        $nonMembersOnly = (!array_key_exists(
            'nonMembersOnly',
            $args
        )) ? false : $args['nonMembersOnly'];
        // Use filters to dynamically generate the list
        $dynamic = ($newOnly || $nonMembersOnly || (!$newOnly && !$nonMembersOnly && $countOnly));
        // Limiters
        $batchLimiters = (!array_key_exists(
            'batchLimiters',
            $args
        )) ? false : $args['batchLimiters'];
        $start         = (!array_key_exists('start', $args)) ? false : $args['start'];
        $limit         = (!array_key_exists('limit', $args)) ? false : $args['limit'];
        $withMinId     = (!array_key_exists(
            'withMinId',
            $args
        )) ? false : $args['withMinId'];

        // Get list of extended Fields for special table / query handling
        $extendedFieldList = $this->getExtendedFieldList(false);

        if ((!($lists instanceof PersistentCollection) && !is_array(
                    $lists
                )) || isset($lists['id'])) {
            $lists = [$lists];
        }

        $return = [];
        foreach ($lists as $l) {
            $leads = ($countOnly) ? 0 : [];

            if ($l instanceof LeadList) {
                $id      = $l->getId();
                $filters = $l->getFilters();
            } elseif (is_array($l)) {
                $id      = $l['id'];
                $filters = (!$dynamic) ? [] : $l['filters'];
            } elseif (!$dynamic) {
                $id      = $l;
                $filters = [];
            }

            $parameters = [];

            foreach ($filters as $filter) {
                if (in_array(
                    $filter['field'],
                    array_keys($extendedFieldList)
                )) {
                    $this->hasExtendedFieldFilter = true;
                }
            }

            if ($dynamic && count($filters)) {
                $q = $this->getEntityManager()
                    ->getConnection()
                    ->createQueryBuilder();
                if ($countOnly) {
                    $count  = ($this->hasCompanyFilter || $this->hasExtendedFieldFilter) ? 'count(distinct(l.id))' : 'count(l.id)';
                    $select = $count.' as lead_count, max(l.id) as max_id';
                    if ($withMinId) {
                        $select .= ', min(l.id) as min_id';
                    }
                } elseif ($idOnly) {
                    $select = 'l.id';
                } else {
                    $select = 'l.*';
                }

                $q->select($select)
                    ->from(MAUTIC_TABLE_PREFIX.'leads', 'l');

                $batchExpr = $q->expr()->andX();
                // Only leads that existed at the time of count
                if ($batchLimiters) {
                    if (!empty($batchLimiters['minId']) && !empty($batchLimiters['maxId'])) {
                        $batchExpr->add(
                            $q->expr()->comparison(
                                'l.id',
                                'BETWEEN',
                                "{$batchLimiters['minId']} and {$batchLimiters['maxId']}"
                            )
                        );
                    } elseif (!empty($batchLimiters['maxId'])) {
                        $batchExpr->add(
                            $q->expr()->lte('l.id', $batchLimiters['maxId'])
                        );
                    }
                }

                if ($newOnly || !$nonMembersOnly) { // !$nonMembersOnly is mainly used for tests as we just want a live count
                    $expr = $this->generateSegmentExpression(
                        $filters,
                        $parameters,
                        $q,
                        null,
                        $id
                    );
                    if ((!$this->hasCompanyFilter && !$this->hasExtendedFieldFilter) && !$expr->count()) {
                        // Treat this as if it has no filters since all the filters are now invalid (fields were deleted)
                        $return[$id] = [];
                        if ($countOnly) {
                            $return[$id] = [
                                'count' => 0,
                                'maxId' => 0,
                            ];
                            if ($withMinId) {
                                $return[$id]['minId'] = 0;
                            }
                        }

                        continue;
                    }

                    // Leads that do not have any record in the lead_lists_leads table for this lead list
                    // For non null fields - it's apparently better to use left join over not exists due to not using nullable
                    // fields - https://explainextended.com/2009/09/18/not-in-vs-not-exists-vs-left-join-is-null-mysql/
                    $listOnExpr = $q->expr()->andX(
                        $q->expr()->eq('ll.leadlist_id', $id),
                        $q->expr()->eq('ll.lead_id', 'l.id')
                    );

                    if (!empty($batchLimiters['dateTime'])) {
                        // Only leads in the list at the time of count
                        $listOnExpr->add(
                            $q->expr()->lte(
                                'll.date_added',
                                $q->expr()->literal($batchLimiters['dateTime'])
                            )
                        );
                    }

                    $q->leftJoin(
                        'l',
                        MAUTIC_TABLE_PREFIX.'lead_lists_leads',
                        'll',
                        $listOnExpr
                    );

                    // add joins for filters that contain extendedFields
                    $this->addExtendedFieldJoins(
                        $filters,
                        $extendedFieldList,
                        $q
                    );

                    if ($newOnly) {
                        $expr->add($q->expr()->isNull('ll.lead_id'));
                    }

                    if ($batchExpr->count()) {
                        $expr->add($batchExpr);
                    }

                    if ($expr->count()) {
                        $q->andWhere($expr);
                    }

                    if (!$newOnly) { // live count
                        // Include manually added
                        $q->orWhere(
                            $q->expr()->eq('ll.manually_added', 1)
                        );

                        $q->andWhere(
                            $q->expr()->orX(
                                $q->expr()->isNull('ll.manually_removed'),
                                // account for those not in a list yet
                                $q->expr()->eq(
                                    'll.manually_removed',
                                    0
                                ) //exclude manually removed
                            )
                        );
                    }
                } elseif ($nonMembersOnly) {
                    // Only leads that are part of the list that no longer match filters and have not been manually removed
                    $q->join(
                        'l',
                        MAUTIC_TABLE_PREFIX.'lead_lists_leads',
                        'll',
                        'l.id = ll.lead_id'
                    );

                    // add joins for filters that contain extendedFields
                    // $this->addExtendedFieldJoins(
                    //   $filters,
                    //   $extendedFieldList,
                    //   $q
                    // );

                    $mainExpr = $q->expr()->andX();
                    if ($batchLimiters && !empty($batchLimiters['dateTime'])) {
                        // Only leads in the list at the time of count
                        $mainExpr->add(
                            $q->expr()->lte(
                                'll.date_added',
                                $q->expr()->literal($batchLimiters['dateTime'])
                            )
                        );
                    }

                    // Ignore those that have been manually added
                    $mainExpr->addMultiple(
                        [
                            $q->expr()->eq('ll.manually_added', ':false'),
                            $q->expr()->eq('ll.leadlist_id', (int) $id),
                        ]
                    );
                    $q->setParameter('false', false, 'boolean');

                    // Find the contacts that are in the segment but no longer have filters that are applicable
                    $sq = $this->getEntityManager()
                        ->getConnection()
                        ->createQueryBuilder();
                    $sq->select('l.id')
                        ->from(MAUTIC_TABLE_PREFIX.'leads', 'l');

                    $expr = $this->generateSegmentExpression(
                        $filters,
                        $parameters,
                        $sq,
                        $q
                    );

                    // add joins for filters that contain extendedFields
                    $this->addExtendedFieldJoins(
                        $filters,
                        $extendedFieldList,
                        $sq
                    );

                    if ($expr->count()) {
                        $sq->andWhere($expr);
                    }
                    $mainExpr->add(
                        sprintf('l.id NOT IN (%s)', $sq->getSQL())
                    );

                    if ($batchExpr->count()) {
                        $mainExpr->add($batchExpr);
                    }

                    if (!empty($mainExpr) && $mainExpr->count() > 0) {
                        $q->andWhere($mainExpr);
                    }
                }

                // Set limits if applied
                if (!empty($limit)) {
                    $q->setFirstResult($start)
                        ->setMaxResults($limit);
                }

                if ($countOnly) {
                    // remove any possible group by
                    $q->resetQueryPart('groupBy');
                }

                $realQuery = $q->getSql(); // for debug purposes only
                $results   = $q->execute()->fetchAll();

                foreach ($results as $r) {
                    if ($countOnly) {
                        $leads = [
                            'count' => $r['lead_count'],
                            'maxId' => $r['max_id'],
                        ];
                        if ($withMinId) {
                            $leads['minId'] = $r['min_id'];
                        }
                    } elseif ($idOnly) {
                        $leads[$r['id']] = $r['id'];
                    } else {
                        $leads[$r['id']] = $r;
                    }
                }
            } elseif (!$dynamic) {
                $q = $this->getEntityManager()
                    ->getConnection()
                    ->createQueryBuilder();
                if ($countOnly) {
                    $q->select(
                        'max(ll.lead_id) as max_id, count(ll.lead_id) as lead_count'
                    )
                        ->from(MAUTIC_TABLE_PREFIX.'lead_lists_leads', 'll');
                } elseif ($idOnly) {
                    $q->select('ll.lead_id as id')
                        ->from(MAUTIC_TABLE_PREFIX.'lead_lists_leads', 'll');
                } else {
                    $q->select('l.*')
                        ->from(MAUTIC_TABLE_PREFIX.'leads', 'l')
                        ->join(
                            'l',
                            MAUTIC_TABLE_PREFIX.'lead_lists_leads',
                            'll',
                            'l.id = ll.lead_id'
                        );
                }

                // Filter by list
                $expr = $q->expr()->andX(
                    $q->expr()->eq('ll.leadlist_id', ':list'),
                    $q->expr()->eq('ll.manually_removed', ':false')
                );

                $q->setParameter('list', (int) $id)
                    ->setParameter('false', false, 'boolean');

                // Set limits if applied
                if (!empty($limit)) {
                    $q->setFirstResult($start)
                        ->setMaxResults($limit);
                }
                if (!empty($expr) && $expr->count() > 0) {
                    $q->where($expr);
                }

                $results = $q->execute()->fetchAll();

                foreach ($results as $r) {
                    if ($countOnly) {
                        $leads = [
                            'count' => $r['lead_count'],
                            'maxId' => $r['max_id'],
                        ];
                    } elseif ($idOnly) {
                        $leads[] = $r['id'];
                    } else {
                        $leads[] = $r;
                    }
                }
            }

            $return[$id] = $leads;

            unset($filters, $parameters, $q, $expr, $results, $dynamicExpr, $leads);
        }

        return $return;
    }

    /**
     * Get an extendedField list.
     *
     * @param bool $secure
     *
     * @return array
     */
    public function getExtendedFieldList($secure = true)
    {
        //TODO Actually implement the Permission Pass
        if (!$secure) {
            $secure = true; // do a real permission check here instead.
        }
        // finish TODO

        $object = 'extendedField';

        $q = $this->getEntityManager()->createQueryBuilder()
            ->from('MauticLeadBundle:LeadField', 'l');

        if (!$secure) {
            $q->select('partial l.{id, label, alias, object, type}')
                ->andWhere($q->expr()->eq('l.object', ':object'))
                ->setParameter('object', $object);
        } else {
            $q->select('partial l.{id, label, alias, object, type}')
                ->andWhere($q->expr()->like('l.object', ':object'))
                ->setParameter('object', "%$object%");
        }

        $q->andWhere(
            $q->expr()->eq('l.isPublished', true)
        );

        $results = $q->getQuery()->getResult();
        $fields  = [];
        foreach ($results as $field) {
            $fieldAlias                             = $field->getAlias();
            $fieldModel                             = $this->fieldModel;
            $dataType                               = $fieldModel->getSchemaDefinition($fieldAlias, $field->getType());
            $dataType                               = $dataType['type'];
            $secure                                 = false !== strpos(
                $field->getObject(),
                'Secure'
            ) ? '_secure' : '';
            $tableName                              = 'lead_fields_leads_'.$dataType.$secure.'_xref';
            $fields[$fieldAlias]['alias']           = $fieldAlias;
            $fields[$fieldAlias]['id']              = $field->getId();
            $fields[$fieldAlias]['label']           = $field->getLabel();
            $fields[$fieldAlias]['type']            = $field->getType();
            $fields[$fieldAlias]['group']           = $field->getGroup();
            $fields[$fieldAlias]['properties']      = $field->getProperties();
            $fields[$fieldAlias]['table']           = $tableName;
            $fields[$fieldAlias]['secure']          = $secure;
            $fields[$fieldAlias]['original_object'] = $field->getObject();
        }

        return $fields;
    }

    /**
     * Get an extendedField list.
     *
     * @param $filters            an array of filters (fields and values)
     * @param $extendedFieldList  an array of all extendedFields and paramters
     * @param $q                  current state of the queryBuilder object
     *
     * called by getLeadsByList() for each extendedField Filter type
     */
    public function addExtendedFieldJoins($filters, $extendedFieldList, $q)
    {
        foreach ($filters as $k => $details) {
            // get extendedField Filters first
            if (in_array($details['field'], array_keys($extendedFieldList))) {
                // its an extended field, build a join expressions
                $extendedLinkExpr = $extendedFieldList[$details['field']]['table'].'.lead_id = l.id';
                $q->leftJoin(
                    'l',
                    MAUTIC_TABLE_PREFIX.$extendedFieldList[$details['field']]['table'],
                    $extendedFieldList[$details['field']]['table'],
                    $extendedLinkExpr
                );
            }
        }
    }

    /**
     * This is a public method that can be used by 3rd party.
     * Do not change the signature.
     *
     * This instance overrides the LeadBundle LeadListRepository instance
     * to handle inclusion of extendedField filter types by altering the schema for
     * each instance of the field filters of extendedField type to use different column/value structure.
     *
     * @param              $filters
     * @param              $parameters
     * @param QueryBuilder $q
     * @param bool         $isNot
     * @param null         $leadId
     * @param string       $object
     * @param null         $listId
     *
     * @return \Doctrine\DBAL\Query\Expression\CompositeExpression|mixed
     */
    public function getListFilterExpr(
        $filters,
        &$parameters,
        QueryBuilder $q,
        $isNot = false,
        $leadId = null,
        $object = 'lead',
        $listId = null
    ) {
        if (!count($filters)) {
            return $q->expr()->andX();
        }

        // Get a list of all ExtendedFields for later
        $extendedFieldList = $this->getExtendedFieldList(false);

        $schema = $this->getEntityManager()->getConnection()->getSchemaManager();
        // Get table columns
        if (null === $this->leadTableSchema) {
            $this->leadTableSchema = $schema->listTableColumns(
                MAUTIC_TABLE_PREFIX.'leads'
            );
        }
        if (null === $this->companyTableSchema) {
            $this->companyTableSchema = $schema->listTableColumns(
                MAUTIC_TABLE_PREFIX.'companies'
            );
        }

        $options = $this->getFilterExpressionFunctions();

        // Add custom filters operators
        if ($this->dispatcher && $this->dispatcher->hasListeners(
                LeadEvents::LIST_FILTERS_OPERATORS_ON_GENERATE
            )) {
            $event = new LeadListFiltersOperatorsEvent(
                $options,
                $this->translator
            );
            $this->dispatcher->dispatch(
                LeadEvents::LIST_FILTERS_OPERATORS_ON_GENERATE,
                $event
            );
            $options = $event->getOperators();
        }

        $groups    = [];
        $groupExpr = $q->expr()->andX();

        $defaultObject = $object;
        foreach ($filters as $k => $details) {
            $isExtendedField = in_array(
                $details['field'],
                array_keys($extendedFieldList)
            ) ? true : false;
            $object          = $defaultObject;
            if (!empty($details['object'])) {
                $object = $details['object'];
            }

            if ($isExtendedField) {
                // get the original object definition from the extendedFieldList
                $object = $extendedFieldList[$details['field']]['original_object'];
            }

            if ($isExtendedField) {
                $fieldModel                     = $this->fieldModel;
                $dataType                       = $fieldModel->getSchemaDefinition(
                    $extendedFieldList[$details['field']]['alias'],
                    $extendedFieldList[$details['field']]['type']
                );
                $dataType                       = $dataType['type'];
                $secure                         = false !== strpos($object, 'Secure') ? '_secure' : '';
                $tableName                      = 'lead_fields_leads_'.$dataType.$secure.'_xref';
                $this->extendedFieldTableSchema = $schema->listTableColumns(
                    MAUTIC_TABLE_PREFIX.$tableName
                );
            }

            if ('lead' == $object) {
                $column = isset($this->leadTableSchema[$details['field']]) ? $this->leadTableSchema[$details['field']] : false;
            } elseif ('company' == $object) {
                $column = isset($this->companyTableSchema[$details['field']]) ? $this->companyTableSchema[$details['field']] : false;
            } elseif ($isExtendedField) {
                $column = isset($this->extendedFieldTableSchema['value']) ? $this->extendedFieldTableSchema['value'] : false;
            }

            // DBAL does not have a not() function so we have to use the opposite
            $operatorDetails = $options[$details['operator']];
            $func            = $isNot ? $operatorDetails['negate_expr'] : $operatorDetails['expr'];

            if ('lead' === $object) {
                $field = "l.{$details['field']}";
            } elseif ('company' === $object) {
                $field = "comp.{$details['field']}";
            } elseif ($isExtendedField) {
                // this is an extendedField Custom Field type that needs custom table joins
                // for simplicity, the full tablename is used as the table alias
                $field = $tableName.'.value';
            }

            $columnType = false;
            if ($column) {
                // Format the field based on platform specific functions that DBAL doesn't support natively
                $formatter  = AbstractFormatter::createFormatter(
                    $this->getEntityManager()->getConnection()
                );
                $columnType = $column->getType();

                switch ($details['type']) {
                    case 'datetime':
                        if (!$columnType instanceof UTCDateTimeType) {
                            $field = $formatter->toDateTime($field);
                        }
                        break;
                    case 'date':
                        if (!$columnType instanceof DateType && !$columnType instanceof UTCDateTimeType) {
                            $field = $formatter->toDate($field);
                        }
                        break;
                    case 'time':
                        if (!$columnType instanceof TimeType && !$columnType instanceof UTCDateTimeType) {
                            $field = $formatter->toTime($field);
                        }
                        break;
                    case 'number':
                        if (!$columnType instanceof IntegerType && !$columnType instanceof FloatType) {
                            $field = $formatter->toNumeric($field);
                        }
                        break;
                }
            }

            //the next one will determine the group
            if ('or' == $details['glue']) {
                // Create a new group of andX expressions
                if ($groupExpr->count()) {
                    $groups[]  = $groupExpr;
                    $groupExpr = $q->expr()->andX();
                }
            }

            $parameter        = $this->generateRandomParameterName();
            $exprParameter    = ":$parameter";
            $ignoreAutoFilter = false;

            // Special handling of relative date strings
            if ('datetime' === $details['type'] || 'date' === $details['type']) {
                $relativeDateStrings = $this->getRelativeDateStrings();
                // Check if the column type is a date/time stamp
                $isTimestamp = ('datetime' === $details['type'] || $columnType instanceof UTCDateTimeType);
                $getDate     = function (&$string) use (
                    $isTimestamp,
                    $relativeDateStrings,
                    &$details,
                    &$func
                ) {
                    $key             = array_search($string, $relativeDateStrings);
                    $dtHelper        = new DateTimeHelper(
                        'midnight today',
                        null,
                        'local'
                    );
                    $requiresBetween = in_array(
                            $func,
                            ['eq', 'neq']
                        ) && $isTimestamp;
                    $timeframe       = str_replace('mautic.lead.list.', '', $key);
                    $modifier        = false;
                    $isRelative      = true;

                    switch ($timeframe) {
                        case 'birthday':
                        case 'anniversary':
                            $func                = 'like';
                            $isRelative          = false;
                            $details['operator'] = 'like';
                            $details['filter']   = '%'.date('-m-d');
                            break;
                        case 'today':
                        case 'tomorrow':
                        case 'yesterday':
                            if ('yesterday' === $timeframe) {
                                $dtHelper->modify('-1 day');
                            } elseif ('tomorrow' === $timeframe) {
                                $dtHelper->modify('+1 day');
                            }

                            // Today = 2015-08-28 00:00:00
                            if ($requiresBetween) {
                                // eq:
                                //  field >= 2015-08-28 00:00:00
                                //  field < 2015-08-29 00:00:00

                                // neq:
                                // field < 2015-08-28 00:00:00
                                // field >= 2015-08-29 00:00:00
                                $modifier = '+1 day';
                            } else {
                                // lt:
                                //  field < 2015-08-28 00:00:00
                                // gt:
                                //  field > 2015-08-28 23:59:59

                                // lte:
                                //  field <= 2015-08-28 23:59:59
                                // gte:
                                //  field >= 2015-08-28 00:00:00
                                if (in_array($func, ['gt', 'lte'])) {
                                    $modifier = '+1 day -1 second';
                                }
                            }
                            break;
                        case 'week_last':
                        case 'week_next':
                        case 'week_this':
                            $interval = str_replace('week_', '', $timeframe);
                            $dtHelper->setDateTime(
                                'midnight monday '.$interval.' week',
                                null
                            );

                            // This week: Monday 2015-08-24 00:00:00
                            if ($requiresBetween) {
                                // eq:
                                //  field >= Mon 2015-08-24 00:00:00
                                //  field <  Mon 2015-08-31 00:00:00

                                // neq:
                                // field <  Mon 2015-08-24 00:00:00
                                // field >= Mon 2015-08-31 00:00:00
                                $modifier = '+1 week';
                            } else {
                                // lt:
                                //  field < Mon 2015-08-24 00:00:00
                                // gt:
                                //  field > Sun 2015-08-30 23:59:59

                                // lte:
                                //  field <= Sun 2015-08-30 23:59:59
                                // gte:
                                //  field >= Mon 2015-08-24 00:00:00
                                if (in_array($func, ['gt', 'lte'])) {
                                    $modifier = '+1 week -1 second';
                                }
                            }
                            break;

                        case 'month_last':
                        case 'month_next':
                        case 'month_this':
                            $interval = substr($key, -4);
                            $dtHelper->setDateTime(
                                'midnight first day of '.$interval.' month',
                                null
                            );

                            // This month: 2015-08-01 00:00:00
                            if ($requiresBetween) {
                                // eq:
                                //  field >= 2015-08-01 00:00:00
                                //  field <  2015-09:01 00:00:00

                                // neq:
                                // field <  2015-08-01 00:00:00
                                // field >= 2016-09-01 00:00:00
                                $modifier = '+1 month';
                            } else {
                                // lt:
                                //  field < 2015-08-01 00:00:00
                                // gt:
                                //  field > 2015-08-31 23:59:59

                                // lte:
                                //  field <= 2015-08-31 23:59:59
                                // gte:
                                //  field >= 2015-08-01 00:00:00
                                if (in_array($func, ['gt', 'lte'])) {
                                    $modifier = '+1 month -1 second';
                                }
                            }
                            break;
                        case 'year_last':
                        case 'year_next':
                        case 'year_this':
                            $interval = substr($key, -4);
                            $dtHelper->setDateTime(
                                'midnight first day of January '.$interval.' year',
                                null
                            );

                            // This year: 2015-01-01 00:00:00
                            if ($requiresBetween) {
                                // eq:
                                //  field >= 2015-01-01 00:00:00
                                //  field <  2016-01-01 00:00:00

                                // neq:
                                // field <  2015-01-01 00:00:00
                                // field >= 2016-01-01 00:00:00
                                $modifier = '+1 year';
                            } else {
                                // lt:
                                //  field < 2015-01-01 00:00:00
                                // gt:
                                //  field > 2015-12-31 23:59:59

                                // lte:
                                //  field <= 2015-12-31 23:59:59
                                // gte:
                                //  field >= 2015-01-01 00:00:00
                                if (in_array($func, ['gt', 'lte'])) {
                                    $modifier = '+1 year -1 second';
                                }
                            }
                            break;
                        default:
                            $isRelative = false;
                            break;
                    }

                    // check does this match php date params pattern?
                    if ('anniversary' !== $timeframe &&
                        (stristr($string[0], '-') || stristr($string[0], '+'))) {
                        $date = new \DateTime('now');
                        $date->modify($string);

                        $dateTime = $date->format('Y-m-d H:i:s');
                        $dtHelper->setDateTime($dateTime, null);

                        $isRelative = true;
                    }

                    if ($isRelative) {
                        if ($requiresBetween) {
                            $startWith = ($isTimestamp) ? $dtHelper->toUtcString(
                                'Y-m-d H:i:s'
                            ) : $dtHelper->toUtcString('Y-m-d');

                            $dtHelper->modify($modifier);
                            $endWith = ($isTimestamp) ? $dtHelper->toUtcString(
                                'Y-m-d H:i:s'
                            ) : $dtHelper->toUtcString('Y-m-d');

                            // Use a between statement
                            $func              = ('neq' == $func) ? 'notBetween' : 'between';
                            $details['filter'] = [$startWith, $endWith];
                        } else {
                            if ($modifier) {
                                $dtHelper->modify($modifier);
                            }

                            $details['filter'] = $isTimestamp ? $dtHelper->toUtcString(
                                'Y-m-d H:i:s'
                            ) : $dtHelper->toUtcString('Y-m-d');
                        }
                    }
                };

                if (is_array($details['filter'])) {
                    foreach ($details['filter'] as &$filterValue) {
                        $getDate($filterValue);
                    }
                } else {
                    $getDate($details['filter']);
                }
            }

            // Generate a unique alias
            $alias = $this->generateRandomParameterName();

            switch ($details['field']) {
                case 'hit_url':
                case 'referer':
                case 'source':
                case 'source_id':
                case 'url_title':
                    $operand = in_array(
                        $func,
                        [
                            'eq',
                            'like',
                            'regexp',
                            'notRegexp',
                            'startsWith',
                            'endsWith',
                            'contains',
                        ]
                    ) ? 'EXISTS' : 'NOT EXISTS';

                    $ignoreAutoFilter = true;
                    $column           = $details['field'];

                    if ('hit_url' == $column) {
                        $column = 'url';
                    }

                    $subqb = $this->getEntityManager()->getConnection()
                        ->createQueryBuilder()
                        ->select('id')
                        ->from(MAUTIC_TABLE_PREFIX.'page_hits', $alias);

                    switch ($func) {
                        case 'eq':
                        case 'neq':
                            $parameters[$parameter] = $details['filter'];
                            $subqb->where(
                                $q->expr()->andX(
                                    $q->expr()->eq(
                                        $alias.'.'.$column,
                                        $exprParameter
                                    ),
                                    $q->expr()->eq($alias.'.lead_id', 'l.id')
                                )
                            );
                            break;
                        case 'regexp':
                        case 'notRegexp':
                            $parameters[$parameter] = $this->prepareRegex(
                                $details['filter']
                            );
                            $not                    = ('notRegexp' === $func) ? ' NOT' : '';
                            $subqb->where(
                                $q->expr()->andX(
                                    $q->expr()->eq($alias.'.lead_id', 'l.id'),
                                    $alias.'.'.$column.$not.' REGEXP '.$exprParameter
                                )
                            );
                            break;
                        case 'like':
                        case 'notLike':
                        case 'startsWith':
                        case 'endsWith':
                        case 'contains':
                            switch ($func) {
                                case 'like':
                                case 'notLike':
                                case 'contains':
                                    $parameters[$parameter] = '%'.$details['filter'].'%';
                                    break;
                                case 'startsWith':
                                    $parameters[$parameter] = $details['filter'].'%';
                                    break;
                                case 'endsWith':
                                    $parameters[$parameter] = '%'.$details['filter'];
                                    break;
                            }

                            $subqb->where(
                                $q->expr()->andX(
                                    $q->expr()->like(
                                        $alias.'.'.$column,
                                        $exprParameter
                                    ),
                                    $q->expr()->eq($alias.'.lead_id', 'l.id')
                                )
                            );
                            break;
                    }
                    // Specific lead
                    if (!empty($leadId)) {
                        $subqb->andWhere(
                            $subqb->expr()
                                ->eq($alias.'.lead_id', $leadId)
                        );
                    }

                    $groupExpr->add(
                        sprintf('%s (%s)', $operand, $subqb->getSQL())
                    );
                    break;
                case 'device_model':
                    $ignoreAutoFilter = true;
                    $operand          = in_array(
                        $func,
                        ['eq', 'like', 'regexp', 'notRegexp']
                    ) ? 'EXISTS' : 'NOT EXISTS';

                    $column = $details['field'];
                    $subqb  = $this->getEntityManager()->getConnection()
                        ->createQueryBuilder()
                        ->select('id')
                        ->from(MAUTIC_TABLE_PREFIX.'lead_devices', $alias);
                    switch ($func) {
                        case 'eq':
                        case 'neq':
                            $parameters[$parameter] = $details['filter'];
                            $subqb->where(
                                $q->expr()->andX(
                                    $q->expr()->eq(
                                        $alias.'.'.$column,
                                        $exprParameter
                                    ),
                                    $q->expr()->eq($alias.'.lead_id', 'l.id')
                                )
                            );
                            break;
                        case 'like':
                        case '!like':
                            $parameters[$parameter] = '%'.$details['filter'].'%';
                            $subqb->where(
                                $q->expr()->andX(
                                    $q->expr()->like(
                                        $alias.'.'.$column,
                                        $exprParameter
                                    ),
                                    $q->expr()->eq($alias.'.lead_id', 'l.id')
                                )
                            );
                            break;
                        case 'regexp':
                        case 'notRegexp':
                            $parameters[$parameter] = $this->prepareRegex(
                                $details['filter']
                            );
                            $not                    = ('notRegexp' === $func) ? ' NOT' : '';
                            $subqb->where(
                                $q->expr()->andX(
                                    $q->expr()->eq($alias.'.lead_id', 'l.id'),
                                    $alias.'.'.$column.$not.' REGEXP '.$exprParameter
                                )
                            );
                            break;
                    }
                    // Specific lead
                    if (!empty($leadId)) {
                        $subqb->andWhere(
                            $subqb->expr()
                                ->eq($alias.'.lead_id', $leadId)
                        );
                    }
                    $groupExpr->add(
                        sprintf('%s (%s)', $operand, $subqb->getSQL())
                    );
                    break;
                case 'hit_url_date':
                case 'lead_email_read_date':
                    $operand = (in_array(
                        $func,
                        ['eq', 'gt', 'lt', 'gte', 'lte', 'between']
                    )) ? 'EXISTS' : 'NOT EXISTS';
                    $table   = 'page_hits';
                    $column  = 'date_hit';

                    if ('lead_email_read_date' == $details['field']) {
                        $column = 'date_read';
                        $table  = 'email_stats';
                    }

                    $subqb = $this->getEntityManager()->getConnection()
                        ->createQueryBuilder()
                        ->select('id')
                        ->from(MAUTIC_TABLE_PREFIX.$table, $alias);

                    switch ($func) {
                        case 'eq':
                        case 'neq':
                            $parameters[$parameter] = $details['filter'];

                            $subqb->where(
                                $q->expr()
                                    ->andX(
                                        $q->expr()
                                            ->eq($alias.'.'.$column, $exprParameter),
                                        $q->expr()
                                            ->eq($alias.'.lead_id', 'l.id')
                                    )
                            );
                            break;
                        case 'between':
                        case 'notBetween':
                            // Filter should be saved with double || to separate options
                            $parameter2              = $this->generateRandomParameterName();
                            $parameters[$parameter]  = $details['filter'][0];
                            $parameters[$parameter2] = $details['filter'][1];
                            $exprParameter2          = ":$parameter2";
                            $ignoreAutoFilter        = true;
                            $field                   = $column;

                            if ('between' == $func) {
                                $subqb->where(
                                    $q->expr()
                                        ->andX(
                                            $q->expr()->gte(
                                                $alias.'.'.$field,
                                                $exprParameter
                                            ),
                                            $q->expr()->lt(
                                                $alias.'.'.$field,
                                                $exprParameter2
                                            ),
                                            $q->expr()->eq($alias.'.lead_id', 'l.id')
                                        )
                                );
                            } else {
                                $subqb->where(
                                    $q->expr()
                                        ->andX(
                                            $q->expr()->lt(
                                                $alias.'.'.$field,
                                                $exprParameter
                                            ),
                                            $q->expr()->gte(
                                                $alias.'.'.$field,
                                                $exprParameter2
                                            ),
                                            $q->expr()->eq($alias.'.lead_id', 'l.id')
                                        )
                                );
                            }
                            break;
                        default:
                            $parameters[$parameter] = $details['filter'];

                            $subqb->where(
                                $q->expr()
                                    ->andX(
                                        $q->expr()
                                            ->$func(
                                                $alias.'.'.$column,
                                                $exprParameter
                                            ),
                                        $q->expr()
                                            ->eq($alias.'.lead_id', 'l.id')
                                    )
                            );
                            break;
                    }
                    // Specific lead
                    if (!empty($leadId)) {
                        $subqb->andWhere(
                            $subqb->expr()
                                ->eq($alias.'.lead_id', $leadId)
                        );
                    }
                    $groupExpr->add(
                        sprintf('%s (%s)', $operand, $subqb->getSQL())
                    );
                    break;
                case 'page_id':
                case 'email_id':
                case 'redirect_id':
                case 'notification':
                    $operand = ('eq' == $func) ? 'EXISTS' : 'NOT EXISTS';
                    $column  = $details['field'];
                    $table   = 'page_hits';
                    $select  = 'id';

                    if ('notification' == $details['field']) {
                        $table  = 'push_ids';
                        $column = 'id';
                    }

                    $subqb = $this->getEntityManager()->getConnection()
                        ->createQueryBuilder()
                        ->select($select)
                        ->from(MAUTIC_TABLE_PREFIX.$table, $alias);

                    if (1 == $details['filter']) {
                        $subqb->where(
                            $q->expr()
                                ->andX(
                                    $q->expr()
                                        ->isNotNull($alias.'.'.$column),
                                    $q->expr()
                                        ->eq($alias.'.lead_id', 'l.id')
                                )
                        );
                    } else {
                        $subqb->where(
                            $q->expr()
                                ->andX(
                                    $q->expr()
                                        ->isNull($alias.'.'.$column),
                                    $q->expr()
                                        ->eq($alias.'.lead_id', 'l.id')
                                )
                        );
                    }
                    // Specific lead
                    if (!empty($leadId)) {
                        $subqb->andWhere(
                            $subqb->expr()
                                ->eq($alias.'.lead_id', $leadId)
                        );
                    }

                    $groupExpr->add(
                        sprintf('%s (%s)', $operand, $subqb->getSQL())
                    );
                    break;
                case 'sessions':
                    $operand = 'EXISTS';
                    $column  = $details['field'];
                    $table   = 'page_hits';
                    $select  = 'COUNT(id)';
                    $subqb   = $this->getEntityManager()->getConnection()
                        ->createQueryBuilder()
                        ->select($select)
                        ->from(MAUTIC_TABLE_PREFIX.$table, $alias);

                    $alias2 = $this->generateRandomParameterName();
                    $subqb2 = $this->getEntityManager()->getConnection()
                        ->createQueryBuilder()
                        ->select($alias2.'.id')
                        ->from(MAUTIC_TABLE_PREFIX.$table, $alias2);

                    $subqb2->where(
                        $q->expr()
                            ->andX(
                                $q->expr()->eq($alias2.'.lead_id', 'l.id'),
                                $q->expr()->gt(
                                    $alias2.'.date_hit',
                                    '('.$alias.'.date_hit - INTERVAL 30 MINUTE)'
                                ),
                                $q->expr()->lt(
                                    $alias2.'.date_hit',
                                    $alias.'.date_hit'
                                )
                            )
                    );

                    $parameters[$parameter] = $details['filter'];

                    $subqb->where(
                        $q->expr()
                            ->andX(
                                $q->expr()
                                    ->eq($alias.'.lead_id', 'l.id'),
                                $q->expr()
                                    ->isNull($alias.'.email_id'),
                                $q->expr()
                                    ->isNull($alias.'.redirect_id'),
                                sprintf('%s (%s)', 'NOT EXISTS', $subqb2->getSQL())
                            )
                    );

                    $opr = '';
                    switch ($func) {
                        case 'eq':
                            $opr = '=';
                            break;
                        case 'gt':
                            $opr = '>';
                            break;
                        case 'gte':
                            $opr = '>=';
                            break;
                        case 'lt':
                            $opr = '<';
                            break;
                        case 'lte':
                            $opr = '<=';
                            break;
                    }
                    if ($opr) {
                        $parameters[$parameter] = $details['filter'];
                        $subqb->having($select.$opr.$details['filter']);
                    }
                    $groupExpr->add(
                        sprintf('%s (%s)', $operand, $subqb->getSQL())
                    );
                    break;
                case 'hit_url_count':
                case 'lead_email_read_count':
                    $operand = 'EXISTS';
                    $column  = $details['field'];
                    $table   = 'page_hits';
                    $select  = 'COUNT(id)';
                    if ('lead_email_read_count' == $details['field']) {
                        $table  = 'email_stats';
                        $select = 'COALESCE(SUM(open_count),0)';
                    }
                    $subqb = $this->getEntityManager()->getConnection()
                        ->createQueryBuilder()
                        ->select($select)
                        ->from(MAUTIC_TABLE_PREFIX.$table, $alias);

                    $parameters[$parameter] = $details['filter'];
                    $subqb->where(
                        $q->expr()
                            ->andX(
                                $q->expr()
                                    ->eq($alias.'.lead_id', 'l.id')
                            )
                    );

                    $opr = '';
                    switch ($func) {
                        case 'eq':
                            $opr = '=';
                            break;
                        case 'gt':
                            $opr = '>';
                            break;
                        case 'gte':
                            $opr = '>=';
                            break;
                        case 'lt':
                            $opr = '<';
                            break;
                        case 'lte':
                            $opr = '<=';
                            break;
                    }

                    if ($opr) {
                        $parameters[$parameter] = $details['filter'];
                        $subqb->having($select.$opr.$details['filter']);
                    }

                    $groupExpr->add(
                        sprintf('%s (%s)', $operand, $subqb->getSQL())
                    );
                    break;

                case 'dnc_bounced':
                case 'dnc_unsubscribed':
                case 'dnc_bounced_sms':
                case 'dnc_unsubscribed_sms':
                    // Special handling of do not contact
                    $func = (('eq' == $func && $details['filter']) || ('neq' == $func && !$details['filter'])) ? 'EXISTS' : 'NOT EXISTS';

                    $parts   = explode('_', $details['field']);
                    $channel = 'email';

                    if (3 === count($parts)) {
                        $channel = $parts[2];
                    }

                    $channelParameter = $this->generateRandomParameterName();
                    $subqb            = $this->getEntityManager()
                        ->getConnection()
                        ->createQueryBuilder()
                        ->select('null')
                        ->from(MAUTIC_TABLE_PREFIX.'lead_donotcontact', $alias)
                        ->where(
                            $q->expr()->andX(
                                $q->expr()->eq($alias.'.reason', $exprParameter),
                                $q->expr()->eq($alias.'.lead_id', 'l.id'),
                                $q->expr()->eq(
                                    $alias.'.channel',
                                    ":$channelParameter"
                                )
                            )
                        );

                    // Specific lead
                    if (!empty($leadId)) {
                        $subqb->andWhere(
                            $subqb->expr()->eq($alias.'.lead_id', $leadId)
                        );
                    }

                    $groupExpr->add(
                        sprintf('%s (%s)', $func, $subqb->getSQL())
                    );

                    // Filter will always be true and differentiated via EXISTS/NOT EXISTS
                    $details['filter'] = true;

                    $ignoreAutoFilter = true;

                    $parameters[$parameter]        = ('bounced' === $parts[1]) ? DoNotContact::BOUNCED : DoNotContact::UNSUBSCRIBED;
                    $parameters[$channelParameter] = $channel;

                    break;

                case 'leadlist':
                    $table                       = 'lead_lists_leads';
                    $column                      = 'leadlist_id';
                    $falseParameter              = $this->generateRandomParameterName();
                    $parameters[$falseParameter] = false;
                    $trueParameter               = $this->generateRandomParameterName();
                    $parameters[$trueParameter]  = true;
                    $func                        = in_array(
                        $func,
                        ['eq', 'in']
                    ) ? 'EXISTS' : 'NOT EXISTS';
                    $ignoreAutoFilter            = true;

                    if ($filterListIds = (array) $details['filter']) {
                        $listQb = $this->getEntityManager()
                            ->getConnection()
                            ->createQueryBuilder()
                            ->select('l.id, l.filters')
                            ->from(MAUTIC_TABLE_PREFIX.'lead_lists', 'l');
                        $listQb->where(
                            $listQb->expr()->in('l.id', $filterListIds)
                        );
                        $filterLists = $listQb->execute()->fetchAll();
                        $not         = 'NOT EXISTS' === $func;

                        // Each segment's filters must be appended as ORs so that each list is evaluated individually
                        $existsExpr = ($not) ? $listQb->expr()->andX() : $listQb->expr()->orX();

                        foreach ($filterLists as $list) {
                            $alias = $this->generateRandomParameterName();
                            $id    = (int) $list['id'];
                            if ($id === (int) $listId) {
                                // Ignore as somehow self is included in the list
                                continue;
                            }

                            $listFilters = unserialize($list['filters']);
                            if (empty($listFilters)) {
                                // Use an EXISTS/NOT EXISTS on contact membership as this is a manual list
                                $subQb = $this->createFilterExpressionSubQuery(
                                    $table,
                                    $alias,
                                    $column,
                                    $id,
                                    $parameters,
                                    $leadId,
                                    [
                                        $alias.'.manually_removed' => $falseParameter,
                                    ]
                                );
                            } else {
                                // Build a EXISTS/NOT EXISTS using the filters for this list to include/exclude those not processed yet
                                // but also leverage the current membership to take into account those manually added or removed from the segment

                                // Build a "live" query based on current filters to catch those that have not been processed yet
                                $subQb      = $this->createFilterExpressionSubQuery(
                                    'leads',
                                    $alias,
                                    null,
                                    null,
                                    $parameters,
                                    $leadId
                                );
                                $filterExpr = $this->generateSegmentExpression(
                                    $listFilters,
                                    $parameters,
                                    $subQb,
                                    null,
                                    $id,
                                    false,
                                    $alias
                                );

                                // Left join membership to account for manually added and removed
                                $membershipAlias = $this->generateRandomParameterName();
                                $subQb->leftJoin(
                                    $alias,
                                    MAUTIC_TABLE_PREFIX.$table,
                                    $membershipAlias,
                                    "$membershipAlias.lead_id = $alias.id AND $membershipAlias.leadlist_id = $id"
                                )
                                    ->where(
                                        $subQb->expr()->orX(
                                            $filterExpr,
                                            $subQb->expr()->eq(
                                                "$membershipAlias.manually_added",
                                                ":$trueParameter"
                                            ) //include manually added
                                        )
                                    )
                                    ->andWhere(
                                        $subQb->expr()->eq("$alias.id", 'l.id'),
                                        $subQb->expr()->orX(
                                            $subQb->expr()->isNull(
                                                "$membershipAlias.manually_removed"
                                            ), // account for those not in a list yet
                                            $subQb->expr()->eq(
                                                "$membershipAlias.manually_removed",
                                                ":$falseParameter"
                                            ) //exclude manually removed
                                        )
                                    );
                            }

                            $existsExpr->add(
                                sprintf('%s (%s)', $func, $subQb->getSQL())
                            );
                        }

                        if ($existsExpr->count()) {
                            $groupExpr->add($existsExpr);
                        }
                    }

                    break;
                case 'tags':
                case 'globalcategory':
                case 'lead_email_received':
                case 'lead_email_sent':
                case 'device_type':
                case 'device_brand':
                case 'device_os':
                    // Special handling of lead lists and tags
                    $func = in_array(
                        $func,
                        ['eq', 'in']
                    ) ? 'EXISTS' : 'NOT EXISTS';

                    $ignoreAutoFilter = true;

                    // Collect these and apply after building the query because we'll want to apply the lead first for each of the subqueries
                    $subQueryFilters = [];
                    switch ($details['field']) {
                        case 'tags':
                            $table  = 'lead_tags_xref';
                            $column = 'tag_id';
                            break;
                        case 'globalcategory':
                            $table  = 'lead_categories';
                            $column = 'category_id';
                            break;
                        case 'lead_email_received':
                            $table  = 'email_stats';
                            $column = 'email_id';

                            $trueParameter                      = $this->generateRandomParameterName();
                            $subQueryFilters[$alias.'.is_read'] = $trueParameter;
                            $parameters[$trueParameter]         = true;
                            break;
                        case 'lead_email_sent':
                            $table  = 'email_stats';
                            $column = 'email_id';
                            break;
                        case 'device_type':
                            $table  = 'lead_devices';
                            $column = 'device';
                            break;
                        case 'device_brand':
                            $table  = 'lead_devices';
                            $column = 'device_brand';
                            break;
                        case 'device_os':
                            $table  = 'lead_devices';
                            $column = 'device_os_name';
                            break;
                    }

                    $subQb = $this->createFilterExpressionSubQuery(
                        $table,
                        $alias,
                        $column,
                        $details['filter'],
                        $parameters,
                        $leadId,
                        $subQueryFilters
                    );

                    $groupExpr->add(
                        sprintf('%s (%s)', $func, $subQb->getSQL())
                    );
                    break;
                case 'stage':
                    // A note here that SQL EXISTS is being used for the eq and neq cases.
                    // I think this code might be inefficient since the sub-query is rerun
                    // for every row in the outer query's table. This might have to be refactored later on
                    // if performance is desired.

                    $subQb = $this->getEntityManager()->getConnection()
                        ->createQueryBuilder()
                        ->select('null')
                        ->from(MAUTIC_TABLE_PREFIX.'stages', $alias);

                    switch ($func) {
                        case 'empty':
                            $groupExpr->add(
                                $q->expr()->isNull('l.stage_id')
                            );
                            break;
                        case 'notEmpty':
                            $groupExpr->add(
                                $q->expr()->isNotNull('l.stage_id')
                            );
                            break;
                        case 'eq':
                            $parameters[$parameter] = $details['filter'];

                            $subQb->where(
                                $q->expr()->andX(
                                    $q->expr()->eq($alias.'.id', 'l.stage_id'),
                                    $q->expr()->eq($alias.'.id', ":$parameter")
                                )
                            );
                            $groupExpr->add(
                                sprintf('EXISTS (%s)', $subQb->getSQL())
                            );
                            break;
                        case 'neq':
                            $parameters[$parameter] = $details['filter'];

                            $subQb->where(
                                $q->expr()->andX(
                                    $q->expr()->eq($alias.'.id', 'l.stage_id'),
                                    $q->expr()->eq($alias.'.id', ":$parameter")
                                )
                            );
                            $groupExpr->add(
                                sprintf('NOT EXISTS (%s)', $subQb->getSQL())
                            );
                            break;
                    }

                    break;
                case 'integration_campaigns':
                    $parameter2       = $this->generateRandomParameterName();
                    $operand          = in_array(
                        $func,
                        ['eq', 'neq']
                    ) ? 'EXISTS' : 'NOT EXISTS';
                    $ignoreAutoFilter = true;

                    $subQb = $this->getEntityManager()->getConnection()
                        ->createQueryBuilder()
                        ->select('null')
                        ->from(MAUTIC_TABLE_PREFIX.'integration_entity', $alias);
                    switch ($func) {
                        case 'eq':
                        case 'neq':
                            if (false !== strpos($details['filter'], '::')) {
                                list($integrationName, $campaignId) = explode(
                                    '::',
                                    $details['filter']
                                );
                            } else {
                                // Assuming this is a Salesforce integration for BC with pre 2.11.0
                                $integrationName = 'Salesforce';
                                $campaignId      = $details['filter'];
                            }

                            $parameters[$parameter]  = $campaignId;
                            $parameters[$parameter2] = $integrationName;
                            $subQb->where(
                                $q->expr()->andX(
                                    $q->expr()->eq(
                                        $alias.'.integration',
                                        ":$parameter2"
                                    ),
                                    $q->expr()->eq(
                                        $alias.'.integration_entity',
                                        "'CampaignMember'"
                                    ),
                                    $q->expr()->eq(
                                        $alias.'.integration_entity_id',
                                        ":$parameter"
                                    ),
                                    $q->expr()->eq(
                                        $alias.'.internal_entity',
                                        "'lead'"
                                    ),
                                    $q->expr()->eq(
                                        $alias.'.internal_entity_id',
                                        'l.id'
                                    )
                                )
                            );
                            break;
                    }

                    $groupExpr->add(
                        sprintf('%s (%s)', $operand, $subQb->getSQL())
                    );

                    break;
                default:
                    if (!$column) {
                        // Column no longer exists so continue
                        continue;
                    }

                    if ('company' === $object) {
                        // Must tell getLeadsByList how to best handle the relationship with the companies table
                        if (!in_array(
                            $func,
                            ['empty', 'neq', 'notIn', 'notLike']
                        )) {
                            $this->listFiltersInnerJoinCompany = true;
                        }
                    }
                    if ($isExtendedField) {
                        $fieldNameColumn = $tableName.'.lead_field_id';
                        $fieldNameValue  = $extendedFieldList[$details['field']]['id'];
                        switch ($func) {
                            case 'between':
                            case 'notBetween':
                                // Filter should be saved with double || to separate options
                                $parameter2              = $this->generateRandomParameterName();
                                $parameters[$parameter]  = $details['filter'][0];
                                $parameters[$parameter2] = $details['filter'][1];
                                $exprParameter2          = ":$parameter2";
                                $ignoreAutoFilter        = true;

                                if ('between' == $func) {
                                    $groupExpr->add(
                                        $q->expr()->andX(
                                        // first the column for filter field name
                                            $q->expr()->eq(
                                                $fieldNameColumn,
                                                $fieldNameValue
                                            ),
                                            $q->expr()->gte($field, $exprParameter),
                                            $q->expr()->lt($field, $exprParameter2)
                                        )
                                    );
                                } else {
                                    $groupExpr->add(
                                        $q->expr()->andX(
                                        // first the column for filter field name
                                            $q->expr()->eq(
                                                $fieldNameColumn,
                                                $fieldNameValue
                                            ),
                                            $q->expr()->lt($field, $exprParameter),
                                            $q->expr()->gte($field, $exprParameter2)
                                        )
                                    );
                                }
                                break;

                            case 'notEmpty':
                                $groupExpr->add(
                                    $q->expr()->andX(
                                    // first the column for filter field name
                                        $q->expr()->eq(
                                            $fieldNameColumn,
                                            $fieldNameValue
                                        ),
                                        $q->expr()->isNotNull($field),
                                        $q->expr()->neq(
                                            $field,
                                            $q->expr()->literal('')
                                        )
                                    )
                                );
                                $ignoreAutoFilter = true;
                                break;

                            case 'empty':
                                $details['filter'] = '';
                                $groupExpr->add(
                                // first the column for filter field name
                                    $q->expr()->andX(
                                        $q->expr()->eq(
                                            $fieldNameColumn,
                                            $fieldNameValue
                                        ),
                                        $this->generateFilterExpression(
                                            $q,
                                            $field,
                                            'eq',
                                            $exprParameter,
                                            true
                                        )
                                    )
                                );
                                break;

                            case 'in':
                            case 'notIn':
                                foreach ($details['filter'] as &$value) {
                                    $value = $q->expr()->literal(
                                        InputHelper::clean($value)
                                    );
                                }
                                if ('multiselect' == $details['type']) {
                                    foreach ($details['filter'] as $filter) {
                                        $filter = trim($filter, "'");

                                        if ('not' === substr($func, 0, 3)) {
                                            $operator = 'NOT REGEXP';
                                        } else {
                                            $operator = 'REGEXP';
                                        }

                                        $groupExpr->add(
                                        // first the column for filter field name
                                            $q->expr()->andX(
                                                $q->expr()->eq(
                                                    $fieldNameColumn,
                                                    $fieldNameValue
                                                ),
                                                $field." $operator '\\\\|?$filter\\\\|?'"
                                            )
                                        );
                                    }
                                } else {
                                    $groupExpr->add(
                                        $this->generateFilterExpression(
                                            $q,
                                            $field,
                                            $func,
                                            $details['filter'],
                                            null
                                        )
                                    );
                                }
                                $ignoreAutoFilter = true;
                                break;

                            case 'neq':
                                $groupExpr->add(
                                // first the column for filter field name
                                    $q->expr()->andX(
                                        $q->expr()->eq(
                                            $fieldNameColumn,
                                            $fieldNameValue
                                        ),
                                        $this->generateFilterExpression(
                                            $q,
                                            $field,
                                            $func,
                                            $exprParameter,
                                            null
                                        )
                                    )
                                );
                                break;

                            case 'like':
                            case 'notLike':
                            case 'startsWith':
                            case 'endsWith':
                            case 'contains':
                                $ignoreAutoFilter = true;

                                switch ($func) {
                                    case 'like':
                                    case 'notLike':
                                        $parameters[$parameter] = (false === strpos(
                                                $details['filter'],
                                                '%'
                                            )) ? '%'.$details['filter'].'%'
                                            : $details['filter'];
                                        break;
                                    case 'startsWith':
                                        $func                   = 'like';
                                        $parameters[$parameter] = $details['filter'].'%';
                                        break;
                                    case 'endsWith':
                                        $func                   = 'like';
                                        $parameters[$parameter] = '%'.$details['filter'];
                                        break;
                                    case 'contains':
                                        $func                   = 'like';
                                        $parameters[$parameter] = '%'.$details['filter'].'%';
                                        break;
                                }

                                $groupExpr->add(
                                    $this->generateFilterExpression(
                                        $q,
                                        $field,
                                        $func,
                                        $exprParameter,
                                        null
                                    )
                                );
                                break;
                            case 'regexp':
                            case 'notRegexp':
                                $ignoreAutoFilter       = true;
                                $parameters[$parameter] = $this->prepareRegex(
                                    $details['filter']
                                );
                                $not                    = ('notRegexp' === $func) ? ' NOT' : '';
                                $groupExpr->add(
                                // first the column for filter field name
                                    $q->expr()->andX(
                                        $q->expr()->eq(
                                            $fieldNameColumn,
                                            $fieldNameValue
                                        ),
                                        // Escape single quotes while accounting for those that may already be escaped
                                        $field.$not.' REGEXP '.$exprParameter
                                    )
                                );
                                break;
                            default:
                                $groupExpr->add(
                                // first the column for filter field name
                                    $q->expr()->andX(
                                        $q->expr()->eq(
                                            $fieldNameColumn,
                                            $fieldNameValue
                                        ),
                                        $q->expr()->$func($field, $exprParameter)
                                    )
                                );
                        }
                    } else {
                        switch ($func) {
                            case 'between':
                            case 'notBetween':
                                // Filter should be saved with double || to separate options
                                $parameter2              = $this->generateRandomParameterName();
                                $parameters[$parameter]  = $details['filter'][0];
                                $parameters[$parameter2] = $details['filter'][1];
                                $exprParameter2          = ":$parameter2";
                                $ignoreAutoFilter        = true;

                                if ('between' == $func) {
                                    $groupExpr->add(
                                        $q->expr()->andX(
                                            $q->expr()->gte($field, $exprParameter),
                                            $q->expr()->lt($field, $exprParameter2)
                                        )
                                    );
                                } else {
                                    $groupExpr->add(
                                        $q->expr()->andX(
                                            $q->expr()->lt($field, $exprParameter),
                                            $q->expr()->gte($field, $exprParameter2)
                                        )
                                    );
                                }
                                break;

                            case 'notEmpty':
                                $groupExpr->add(
                                    $q->expr()->andX(
                                        $q->expr()->isNotNull($field),
                                        $q->expr()->neq(
                                            $field,
                                            $q->expr()->literal('')
                                        )
                                    )
                                );
                                $ignoreAutoFilter = true;
                                break;

                            case 'empty':
                                $details['filter'] = '';
                                $groupExpr->add(
                                    $this->generateFilterExpression(
                                        $q,
                                        $field,
                                        'eq',
                                        $exprParameter,
                                        true
                                    )
                                );
                                break;

                            case 'in':
                            case 'notIn':
                                foreach ($details['filter'] as &$value) {
                                    $value = $q->expr()->literal(
                                        InputHelper::clean($value)
                                    );
                                }
                                if ('multiselect' == $details['type']) {
                                    foreach ($details['filter'] as $filter) {
                                        $filter = trim($filter, "'");

                                        if ('not' === substr($func, 0, 3)) {
                                            $operator = 'NOT REGEXP';
                                        } else {
                                            $operator = 'REGEXP';
                                        }

                                        $groupExpr->add(
                                            $field." $operator '\\\\|?$filter\\\\|?'"
                                        );
                                    }
                                } else {
                                    $groupExpr->add(
                                        $this->generateFilterExpression(
                                            $q,
                                            $field,
                                            $func,
                                            $details['filter'],
                                            null
                                        )
                                    );
                                }
                                $ignoreAutoFilter = true;
                                break;

                            case 'neq':
                                $groupExpr->add(
                                    $this->generateFilterExpression(
                                        $q,
                                        $field,
                                        $func,
                                        $exprParameter,
                                        null
                                    )
                                );
                                break;

                            case 'like':
                            case 'notLike':
                            case 'startsWith':
                            case 'endsWith':
                            case 'contains':
                                $ignoreAutoFilter = true;

                                switch ($func) {
                                    case 'like':
                                    case 'notLike':
                                        $parameters[$parameter] = (false === strpos(
                                                $details['filter'],
                                                '%'
                                            )) ? '%'.$details['filter'].'%'
                                            : $details['filter'];
                                        break;
                                    case 'startsWith':
                                        $func                   = 'like';
                                        $parameters[$parameter] = $details['filter'].'%';
                                        break;
                                    case 'endsWith':
                                        $func                   = 'like';
                                        $parameters[$parameter] = '%'.$details['filter'];
                                        break;
                                    case 'contains':
                                        $func                   = 'like';
                                        $parameters[$parameter] = '%'.$details['filter'].'%';
                                        break;
                                }

                                $groupExpr->add(
                                    $this->generateFilterExpression(
                                        $q,
                                        $field,
                                        $func,
                                        $exprParameter,
                                        null
                                    )
                                );
                                break;
                            case 'regexp':
                            case 'notRegexp':
                                $ignoreAutoFilter       = true;
                                $parameters[$parameter] = $this->prepareRegex(
                                    $details['filter']
                                );
                                $not                    = ('notRegexp' === $func) ? ' NOT' : '';
                                $groupExpr->add(
                                // Escape single quotes while accounting for those that may already be escaped
                                    $field.$not.' REGEXP '.$exprParameter
                                );
                                break;
                            default:
                                $groupExpr->add(
                                    $q->expr()->$func($field, $exprParameter)
                                );
                        }
                    }
            }

            if (!$ignoreAutoFilter) {
                if (!is_array($details['filter'])) {
                    switch ($details['type']) {
                        case 'number':
                            $details['filter'] = (float) $details['filter'];
                            break;

                        case 'boolean':
                            $details['filter'] = (bool) $details['filter'];
                            break;
                    }
                }

                $parameters[$parameter] = $details['filter'];
            }

            if ($this->dispatcher && $this->dispatcher->hasListeners(
                    LeadEvents::LIST_FILTERS_ON_FILTERING
                )) {
                $event = new LeadListFilteringEvent(
                    $details,
                    $leadId,
                    $alias,
                    $func,
                    $q,
                    $this->getEntityManager()
                );
                $this->dispatcher->dispatch(
                    LeadEvents::LIST_FILTERS_ON_FILTERING,
                    $event
                );
                if ($event->isFilteringDone()) {
                    $groupExpr->add($event->getSubQuery());
                }
            }
        }

        // Get the last of the filters
        if ($groupExpr->count()) {
            $groups[] = $groupExpr;
        }
        if (1 === count($groups)) {
            // Only one andX expression
            $expr = $groups[0];
        } elseif (count($groups) > 1) {
            // Sets of expressions grouped by OR
            $orX = $q->expr()->orX();
            $orX->addMultiple($groups);

            // Wrap in a andX for other functions to append
            $expr = $q->expr()->andX($orX);
        } else {
            $expr = $groupExpr;
        }

        return $expr;
    }
}
