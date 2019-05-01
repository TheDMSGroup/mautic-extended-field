<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Scott Shipman
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 *
 * Provides methods to override the LeadBundle LeadRepository.php
 */

namespace MauticPlugin\MauticExtendedFieldBundle\Entity;

use Doctrine\DBAL\Query\Expression\CompositeExpression;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Mautic\CoreBundle\Helper\SearchStringHelper;
use Mautic\LeadBundle\Entity\CustomFieldRepositoryInterface;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Model\FieldModel;

/**
 * Class OverrideLeadRepository.
 *
 * Overrides: LeadRepository
 *
 * Alterations to core:
 *  Uses ExtendedFieldRepositoryTrait.
 *  Constructs with FieldModel (used for schema definitions).
 */
class OverrideLeadRepository extends LeadRepository implements CustomFieldRepositoryInterface
{
    use ExtendedFieldRepositoryTrait;

    /** @var FieldModel */
    public $leadFieldModel;

    /** @var array */
    protected $extendedFieldFilters = [];

    /** @var array */
    private $availableSocialFields = [];

    /**
     * OverrideLeadRepository constructor.
     *
     * @param EntityManager $em
     * @param ClassMetadata $class
     * @param FieldModel    $fieldModel
     */
    public function __construct(EntityManager $em, ClassMetadata $class = null, FieldModel $fieldModel)
    {
        if (!$class) {
            $class = new ClassMetadata(Lead::class);
        }
        parent::__construct($em, $class);
        $this->leadFieldModel = $fieldModel;
    }

    /**
     * Overrides LeadRepository::getEntity().
     *
     * Alterations to core:
     *  Uses getExtendedFieldValues instead of getFieldValues to prevent recursion (otherwise identical).
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
            $q->andWhere($this->getTableAlias().'.id = :id')
                ->setParameter('id', (int) $contactId);
            $entity = $q->getQuery()->getSingleResult();
        } catch (\Exception $e) {
            $entity = null;
        }

        if (null === $entity) {
            return $entity;
        }

        // triggerModel is private in LeadModel. Not really an error bur not really proper either.
        //  if (!empty($this->triggerModel)) {
        //      $entity->setColor($this->triggerModel->getColorForLeadPoints($entity->getPoints()));
        //  }

        // Alterations to core start.
        $fieldValues = $this->getExtendedFieldValues($id, true, 'lead');
        // Alterations to core end.
        $entity->setFields($fieldValues);

        $entity->setAvailableSocialFields($this->availableSocialFields);

        return $entity;
    }

    /**
     * Duplicates CommonRepository::buildWhereClause but with extended field capability.
     *
     * Alterations to core:
     *  Adds the $extendedFieldFilters property, to be used subsequently by other methods.
     *  Replaces addAdvancedSearchWhereClause with addExtendedAdvancedSearchWhereClause.
     *  Adds left joins for extended object filters.
     *
     * @param \Doctrine\ORM\QueryBuilder $q
     * @param array                      $args
     * @param array                      $extendedFieldFilters
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
        // Alteration to core start.
        $this->extendedFieldFilters = $extendedFieldFilters;
        // Alteration to core end.

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
                    $this->buildWhereClauseFromArray($q, $filter['where']);
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

                // Alteration to core start.
                // Original: list($expr, $parameters) = $this->addAdvancedSearchWhereClause($q, $advancedFilters);
                list($expr, $parameters) = $this->addExtendedAdvancedSearchWhereClause($q, $advancedFilters);
                // Alteration to core end.
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

        // Alteration to core start.
        // Add joins for extended fields
        foreach ($this->extendedFieldFilters as $extendedFilter) {
            $leadFieldModel   = $this->leadFieldModel;
            $dataType         = $leadFieldModel->getSchemaDefinition($extendedFilter['alias'], $extendedFilter['type']);
            $dataType         = $dataType['type'];
            $secure           = 'extendedFieldSecure' === $extendedFilter['object'] ? '_secure' : '';
            $tableName        = MAUTIC_TABLE_PREFIX.'lead_fields_leads_'.$dataType.$secure.'_xref';
            $tableAlias       = $dataType.$secure.$extendedFilter['id'];
            $extendedJoinExpr = $q->expr()->andX(
                $q->expr()->eq('l.id ', $tableAlias.'.lead_id'),
                $q->expr()->eq($tableAlias.'.lead_field_id', $extendedFilter['id'])
            );

            $q->leftjoin('l', $tableName, $tableAlias, $extendedJoinExpr);
        }
        // Alteration to core end.

        // Parameters have to be set even if there are no expressions just in case a search command
        // passed back a parameter it used
        foreach ($queryParameters as $k => $v) {
            if (true === $v || false === $v) {
                $q->setParameter($k, $v, 'boolean');
            } else {
                $q->setParameter($k, $v);
            }
        }
    }

    /**
     * Alterations to core:
     *  Uses parseExtendedSearchFilters instead of parseSearchFilters.
     *
     * @param \Doctrine\ORM\QueryBuilder $qb
     * @param object                     $filters
     *
     * @return array
     */
    protected function addExtendedAdvancedSearchWhereClause($qb, $filters)
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
    }

    /**
     * Extends CommonRepository::parseSearchFilters for extended fields.
     *
     * Alterations to core:
     *  Uses addExtendedAdvancedSearchWhereClause instead of addAdvancedSearchWhereClause
     *
     * @param $parseFilters
     * @param $qb
     * @param $expressions
     * @param $parameters
     */
    protected function parseExtendedSearchFilters($parseFilters, $qb, $expressions, &$parameters)
    {
        foreach ($parseFilters as $f) {
            if (isset($f->children)) {
                list($expr, $params) = $this->addExtendedAdvancedSearchWhereClause($qb, $f);
            } else {
                if (!empty($f->command)) {
                    // is this an Extended Field Filter?
                    if (in_array($f->command, array_keys($this->extendedFieldFilters))) {
                        // Change the where clause to use the extended table alias.
                        $extendedFilter = $this->extendedFieldFilters[$f->command];
                        $schema         = $this->leadFieldModel->getSchemaDefinition(
                            $extendedFilter['alias'],
                            $extendedFilter['type']
                        );
                        $secure              = 'extendedFieldSecure' === $extendedFilter['object'] ? '_secure' : '';
                        $tableAlias          = $schema['type'].$secure.$extendedFilter['id'];
                        list($expr, $params) = $this->addStandardCatchAllWhereClause($qb, $f, [$tableAlias.'.value']);
                    } elseif ($this->isSupportedSearchCommand($f->command, $f->string)) {
                        list($expr, $params) = $this->addSearchCommandWhereClause($qb, $f);
                    } else {
                        //treat the command:string as if its a single word
                        $f->string           = $f->command.':'.$f->string;
                        $f->not              = false;
                        $f->strict           = true;
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
    }

    /**
     * Adds the "catch all" where clause to the QueryBuilder.
     *
     * @param \Doctrine\ORM\QueryBuilder|\Doctrine\DBAL\Query\QueryBuilder $q
     * @param                                                              $filter
     *
     * @return array
     */
    protected function addCatchAllWhereClause($q, $filter)
    {
        $filterContainsPhone = $this->filterContainsPhone($filter); // might need to add a '+' so pass by reference
        $filterContainsEmail = $this->filterContainsEmail($filter);
        $filterContainsZip   = $this->filterContainsZip($filter);

        $columns = array_merge(
            $filterContainsPhone,
            $filterContainsEmail,
            $filterContainsZip
        );

        if (empty($columns)) {
            $columns = array_merge(
                [
                    'l.firstname',
                    'l.lastname',
                    'l.company',
                    'l.city',
                    'l.state',
                    'l.country',
                ],
                $this->availableSocialFields
            );
        }

        return $this->addStandardCatchAllWhereClause($q, $filter, $columns);
    }

    /**
     * @param $filter
     *
     * @return array
     */
    protected function filterContainsZip($filter)
    {
        $return = [];

        if (
            (isset($filter->string) && preg_match('/^\d{5}(?:[-\s]\d{4})?$/', $filter->string))
            || false !== strpos(serialize($filter), 'zipcode')
        ) {
            $return = ['l.zipcode'];
        }

        return $return;
    }

    /**
     * @param $filter
     *
     * @return array
     */
    protected function filterContainsPhone(&$filter)
    {
        $return = [];

        // detect e.164 and 10 digit phone number specs. Deal with the '+' sign in the string as phone not mautic's Strict filter char
        if (isset($filter->string)) {
            if (
                // if the '+' was in original string, it gets dropped. the result on E.164 would be an 11 digit number (US numbers)
                11 == strlen($filter->string)
                && is_numeric($filter->string)
            ) {
                $return           = ['l.phone'];
                $filter->string   = '+'.$filter->string; // add a second '+' to apply the plus as a E.164 phone format back
                $filter->strict   = 1; // set filter to strict so it doesnt use wildcards, ie, doesnt do a begins with syntax.
            }

            // backwards compatible (non E.164) and other $filter array key structures
            if (10 == strlen($filter->string)
                && is_numeric($filter->string)
            ) {
                $return           = ['l.phone'];
                $filter->strict   = 1; // set filter to strict so it doesnt use wildcards, ie, doesnt do a begins with syntax.
            }
        } elseif (false !== strpos(serialize($filter), 'phone')
        ) {
            $return = ['l.phone'];
        }

        return $return;
    }

    /**
     * @param $filter
     *
     * @return array
     */
    protected function filterContainsEmail($filter)
    {
        $return = [];

        if (
            (isset($filter->string) && filter_var($filter->string, FILTER_VALIDATE_EMAIL))
            || false !== strpos(serialize($filter), 'email')
        ) {
            $return = ['l.email'];
        }

        return $return;
    }

    /*
     * @todo - Support retrieving leads by unique IDs that are also extended fields.
     *
     * Alterations to core:
     *  Override LeadRepository::getLeadIdsByUniqueFields to join and pivot on columns.
     *
     * @param     $uniqueFieldsWithData is an array of columns & values to filter by
     * @param int $leadId               is the current lead id. Added to query to skip and find other leads
     *
     * @return array
     */
    // public function getLeadIdsByUniqueFields($uniqueFieldsWithData, $leadId = null)
    // {
    // }
}
