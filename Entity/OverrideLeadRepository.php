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
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\MauticExtendedFieldBundle\Entity\ExtendedFieldRepositoryTrait;
use Mautic\CoreBundle\Helper\SearchStringHelper;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\EntityManager;

/**
 * OverrideLeadRepository.
 */
class OverrideLeadRepository extends LeadRepository implements CustomFieldRepositoryInterface
{


    //  use CustomFieldRepositoryTrait;
    use ExtendedFieldRepositoryTrait;


    /**
     * @var array
     */
    private $availableSocialFields = [];


    /**
     * @var FieldModel
     */
    public $fieldModel;

    /**
     * Stores a boolean if args has extended field filters.
     *
     * @var array
     */
    protected $extendedFieldFilters = [];

    public function __construct(EntityManager $em, ClassMetadata $class, FieldModel $fieldmodel)
    {
        parent::__construct($em, $class);
        $this->fieldModel = $fieldmodel;
    }

    /**
     * @param \Doctrine\ORM\QueryBuilder $q
     * @param array                      $args
     */
    protected function ExtendedBuildWhereClause($q, array $args, array $extendedFieldFilters)
    {
        $filter                    = array_key_exists('filter', $args) ? $args['filter'] : '';
        $filterHelper              = new SearchStringHelper();
        $advancedFilters           = new \stdClass();
        $advancedFilters->root     = [];
        $advancedFilters->commands = [];
        // Reset advanced filter commands to be used in search query building
        $this->advancedFilterCommands = [];
        $advancedFilterStrings        = [];
        $queryParameters              = [];
        $queryExpression              = $q->expr()->andX();
        $this->extendedFieldFilters   = $extendedFieldFilters;


        if (isset($args['ids'])) {
            $ids = array_map('intval', $args['ids']);
            if ($q instanceof QueryBuilder) {
                $param = $this->generateRandomParameterName();
                $queryExpression->add(
                    $q->expr()->in($this->getTableAlias().'.id', ':'.$param)
                );
                $queryParameters[$param] = $ids;
            } else {
                $queryExpression->add(
                    $q->expr()->in($this->getTableAlias().'.id', $ids)
                );
            }
        } elseif (!empty($args['ownedBy'])) {
            $queryExpression->add(
                $q->expr()->in($this->getTableAlias().'.'.$args['ownedBy'][0], (int) $args['ownedBy'][1])
            );
        }

        if (!empty($filter)) {
            if (is_array($filter)) {
                if (!empty($filter['where'])) {
                    // build clauses from array
                    $this->buildExtendedWhereClauseFromArray($q, $filter['where']);
                } elseif (!empty($filter['criteria']) || !empty($filter['force'])) {
                    $criteria = !empty($filter['criteria']) ? $filter['criteria'] : $filter['force'];
                    if (is_array($criteria)) {
                        //defined columns with keys of column, expr, value
                        foreach ($criteria as $criterion) {
                            if ($criterion instanceof Query\Expr || $criterion instanceof CompositeExpression) {
                                $queryExpression->add($criterion);

                                if (isset($criterion->parameters) && is_array($criterion->parameters)) {
                                    $queryParameters = array_merge($queryParameters, $criterion->parameters);
                                    unset($criterion->parameters);
                                }
                            } elseif (is_array($criterion)) {
                                list($expr, $parameters) = $this->getFilterExpr($q, $criterion);
                                $queryExpression->add($expr);
                                if (is_array($parameters)) {
                                    $queryParameters = array_merge($queryParameters, $parameters);
                                }
                            } else {
                                //string so parse as advanced search
                                $advancedFilterStrings[] = $criterion;
                            }
                        }
                    } else {
                        //string so parse as advanced search
                        $advancedFilterStrings[] = $criteria;
                    }
                }

                if (!empty($filter['string'])) {
                    $advancedFilterStrings[] = $filter['string'];
                }
            } else {
                $advancedFilterStrings[] = $filter;
            }

            if (!empty($advancedFilterStrings)) {
                foreach ($advancedFilterStrings as $parseString) {
                    $parsed = $filterHelper->parseString($parseString);

                    $advancedFilters->root = array_merge($advancedFilters->root, $parsed->root);
                    $filterHelper->mergeCommands($advancedFilters, $parsed->commands);
                }
                $this->advancedFilterCommands = $advancedFilters->commands;

                list($expr, $parameters) = $this->addExtendedAdvancedSearchWhereClause(
                    $q,
                    $advancedFilters,
                    $extendedFieldFilters
                );
                $this->appendExpression($queryExpression, $expr);

                if (is_array($parameters)) {
                    $queryParameters = array_merge($queryParameters, $parameters);
                }
            }
        }

        //parse the filter if set
        if ($queryExpression->count()) {
            $q->andWhere($queryExpression);
        }

        // Add joins for extended fields
        foreach ($this->extendedFieldFilters as $extendedFilter) {
            $fieldModel       = $this->fieldModel;
            $dataType         = $fieldModel->getSchemaDefinition($extendedFilter['alias'], $extendedFilter['type']);
            $dataType         = $dataType['type'];
            $secure           = strpos($extendedFilter['object'], "Secure") !== false ? "_secure" : "";
            $tableName        = "lead_fields_leads_".$dataType.$secure."_xref";
            $tableAlias       = $dataType.$secure.$extendedFilter['id'];
            $extendedJoinExpr = $q->expr()->andX(
                $q->expr()->eq('l.id ', $tableAlias.'.lead_id'),
                $q->expr()->eq($tableAlias.'.lead_field_id', $extendedFilter['id'])
            );

            $q->leftjoin('l', $tableName, $tableAlias, $extendedJoinExpr);
        }

        // Parameters have to be set even if there are no expressions just in case a search command
        // passed back a parameter it used
        foreach ($queryParameters as $k => $v) {
            if ($v === true || $v === false) {
                $q->setParameter($k, $v, 'boolean');
            } else {
                $q->setParameter($k, $v);
            }
        }
    }    /**
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

            $fieldValues = $this->getExtendedFieldValues($id, true, 'lead');
            $entity->setFields($fieldValues);

            $entity->setAvailableSocialFields($this->availableSocialFields);
        }

        return $entity;
    }

    /**
     * @param QueryBuilder|\Doctrine\DBAL\Query\QueryBuilder $query
     * @param array                                          $clauses [['expr' => 'expression', 'col' => 'DB column',
     *                                                                'val' => 'value to search for']]
     * @param                                                $expr
     */
    protected function buildExtendedWhereClauseFromArray($query, array $clauses, $expr = null)
    {
        $isOrm       = $query instanceof QueryBuilder;
        $columnValue = [
            'eq',
            'neq',
            'lt',
            'lte',
            'gt',
            'gte',
            'like',
            'notLike',
            'in',
            'notIn',
            'between',
            'notBetween',
        ];
        $justColumn  = ['isNull', 'isNotNull', 'isEmpty', 'isNotEmpty'];
        $andOr       = ['andX', 'orX'];

        if ($clauses && is_array($clauses)) {
            foreach ($clauses as $clause) {
                if (!empty($clause['internal']) && 'formula' === $clause['expr']) {
                    $whereClause = array_key_exists('value', $clause) ? $clause['value'] : $clause['val'];
                    if ($expr) {
                        $expr->add($whereClause);
                    } else {
                        $query->andWhere($whereClause);
                    }

                    continue;
                }

                if (in_array($clause['expr'], $andOr)) {
                    $composite = $query->expr()->{$clause['expr']}();
                    $this->buildWhereClauseFromArray($query, $clause['val'], $composite);

                    if (null === $expr) {
                        $query->andWhere($composite);
                    } else {
                        $expr->add($composite);
                    }
                } else {
                    $clause = $this->validateWhereClause($clause);
                    $column = (strpos($clause['col'], '.') === false) ? $this->getTableAlias(
                        ).'.'.$clause['col'] : $clause['col'];

                    $whereClause = null;
                    switch ($clause['expr']) {
                        case 'between':
                        case 'notBetween':
                            if (is_array($clause['val']) && count($clause['val']) === 2) {
                                $not   = 'notBetween' === $clause['expr'] ? ' NOT' : '';
                                $param = $this->generateRandomParameterName();
                                $query->setParameter($param, $clause['val'][0]);
                                $param2 = $this->generateRandomParameterName();
                                $query->setParameter($param2, $clause['val'][1]);

                                $whereClause = $column.$not.' BETWEEN :'.$param.' AND :'.$param2;
                            }
                            break;
                        case 'isEmpty':
                        case 'isNotEmpty':
                            if ('empty' === $clause['expr']) {
                                $whereClause = $query->expr()->orX(
                                    $query->expr()->eq($column, $query->expr()->literal('')),
                                    $query->expr()->isNull($column)
                                );
                            } else {
                                $whereClause = $query->expr()->andX(
                                    $query->expr()->neq($column, $query->expr()->literal('')),
                                    $query->expr()->isNotNull($column)
                                );
                            }
                            break;
                        case 'in':
                        case 'notIn':
                            if (!$isOrm) {
                                $whereClause = $query->expr()->{$clause['expr']}($column, (array) $clause['val']);
                            } else {
                                $param       = $this->generateRandomParameterName();
                                $whereClause = $query->expr()->{$clause['expr']}($column, ':'.$param);
                                $query->setParameter($param, $clause['val']);
                            }
                        default:
                            if (method_exists($query->expr(), $clause['expr'])) {
                                if (in_array($clause['expr'], $columnValue)) {
                                    $param       = $this->generateRandomParameterName();
                                    $whereClause = $query->expr()->{$clause['expr']}($column, ':'.$param);
                                    $query->setParameter($param, $clause['val']);
                                } elseif (in_array($clause['expr'], $justColumn)) {
                                    $whereClause = $query->expr()->{$clause['expr']}($column);
                                }
                            }
                    }

                    if ($whereClause) {
                        if ($expr) {
                            $expr->add($whereClause);
                        } else {
                            $query->andWhere($whereClause);
                        }
                    }
                }
            }
        }
    }    /**
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
                $companies = $this->getEntityManager()->getRepository(
                    'MauticLeadBundle:Company'
                )->getCompaniesForContacts($contactIds);
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
     * @param \Doctrine\ORM\QueryBuilder|\Doctrine\DBAL\Query\QueryBuilder $q
     * @param                                                              $filter
     *
     * @return array
     */
    protected function addExtendedAdvancedSearchWhereClause($qb, $filters, $extendedFieldFilters)
    {
        $parseFilters = [];
        if (isset($filters->root[0])) {
            // Function is determined by the second clause type
            $type         = (isset($filters->root[1])) ? $filters->root[1]->type : $filters->root[0]->type;
            $parseFilters = &$filters->root;
        } elseif (isset($filters->children[0])) {
            $type         = (isset($filters->children[1])) ? $filters->children[1]->type : $filters->children[0]->type;
            $parseFilters = &$filters->children;
        } elseif (is_array($filters)) {
            $type         = (isset($filters[1])) ? $filters[1]->type : $filters[0]->type;
            $parseFilters = &$filters;
        }

        if (empty($type)) {
            $type = 'and';
        }

        $parameters  = [];
        $expressions = $qb->expr()->{"{$type}X"}();

        if ($parseFilters) {
            $this->parseExtendedSearchFilters($parseFilters, $qb, $expressions, $parameters);
        }

        return [$expressions, $parameters];
    }    /**
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
            $companies = $this->getEntityManager()->getRepository('MauticLeadBundle:Company')->getCompaniesForContacts(
                [$id]
            );

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
     * @param $parseFilters
     * @param $qb
     * @param $expressions
     * @param $parameters
     */
    protected function parseExtendedSearchFilters($parseFilters, $qb, $expressions, &$parameters)
    {
        foreach ($parseFilters as $f) {
            if (isset($f->children)) {
                list($expr, $params) = $this->addExtendedAdvancedSearchWhereClause(
                    $qb,
                    $f,
                    $this->extendedFieldFilters
                );
            } else {
                if (!empty($f->command)) {
                    // is this an Extended Field Filter?
                    if (in_array($f->command, array_keys($this->extendedFieldFilters))) {
                        // do special where clause for extendedFields
                        list($expr, $params) = $this->addStandardExtendedlWhereClause($qb, $f);
                    } elseif ($this->isSupportedSearchCommand($f->command, $f->string)) {
                        list($expr, $params) = $this->addExtendedSearchCommandWhereClause($qb, $f);
                    } else {
                        //treat the command:string as if its a single word
                        $f->string = $f->command.':'.$f->string;
                        $f->not    = false;
                        $f->strict = true;
                        list($expr, $params) = $this->addCatchAllWhereClause($qb, $f);
                    }
                } else {
                    list($expr, $params) = $this->addCatchAllWhereClause($qb, $f);
                }
            }
            if (!empty($params)) {
                $parameters = array_merge($parameters, $params);
            }

            $this->appendExpression($expressions, $expr);
        }
    }    /**
     * **********   NOT USED YET  ***********************
     *
     * Overrides LeadBundle instance of getLeadIdsByUniqueFields
     * to handle extended field table schema differences from lead table
     * IE - needs a join and pivot on columns
     *
     * Get list of lead Ids by unique field data.
     *
     * @param     $uniqueFieldsWithData is an array of columns & values to filter by
     * @param int $leadId               is the current lead id. Added to query to skip and find other leads
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

    /**
     * @param \Doctrine\ORM\QueryBuilder $q
     * @param object                     $filter
     * @param array                      $columns
     *
     * @return array
     */
    protected function addStandardExtendedlWhereClause(&$q, $filter)
    {
        $unique         = $this->generateRandomParameterName(
        ); //ensure that the string has a unique parameter identifier
        $string         = $filter->string;
        $extendedFilter = $this->extendedFieldFilters[$filter->command];

        $fieldModel = $this->fieldModel;
        $dataType   = $fieldModel->getSchemaDefinition($extendedFilter['alias'], $extendedFilter['type']);
        $dataType   = $dataType['type'];
        $secure     = strpos($extendedFilter['object'], "Secure") !== false ? "_secure" : "";
        $tableAlias = $dataType.$secure.$extendedFilter['id'];
        $col        = $tableAlias.".value";

        if (!$filter->strict) {
            if (strpos($string, '%') === false) {
                $string = "$string%";
            }
        }

        $ormQb = true;

        if ($q instanceof QueryBuilder) {
            $xFunc    = 'orX';
            $exprFunc = 'like';
        } else {
            $ormQb = false;
            if ($filter->not) {
                $xFunc    = 'andX';
                $exprFunc = 'notLike';
            } else {
                $xFunc    = 'orX';
                $exprFunc = 'like';
            }
        }

        $expr = $q->expr()->$xFunc();

        $expr->add(
            $q->expr()->$exprFunc($col, ":$unique")
        );


        if ($ormQb && $filter->not) {
            $expr = $q->expr()->not($expr);
        }

        return [
            $expr,
            ["$unique" => $string],
        ];
    }

    /**
     * @param \Doctrine\ORM\QueryBuilder|\Doctrine\DBAL\Query\QueryBuilder $q
     * @param                                                              $filter
     *
     * @return array
     */
    protected function addExtendedSearchCommandWhereClause($q, $filter)
    {
        $command = $filter->command;
        $expr    = false;

        switch ($command) {
            case $this->translator->trans('mautic.core.searchcommand.ids'):
            case $this->translator->trans('mautic.core.searchcommand.ids', [], null, 'en_US'):
                $expr = $this->getIdsExpr($q, $filter);
                break;
        }

        return [
            $expr,
            [],
        ];
    }










}
