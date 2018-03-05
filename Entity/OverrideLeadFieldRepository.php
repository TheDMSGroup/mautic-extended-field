<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Scott Shipman
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 *
 * Provides methods to override the LeadBundle LeadFieldRepository.php
 */

namespace MauticPlugin\MauticExtendedFieldBundle\Entity;

use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\LeadBundle\Entity\LeadFieldRepository as LeadFieldRepository;
use MauticPlugin\MauticExtendedFieldBundle\Model\ExtendedFieldModel as ExtendedFieldModel;

class OverrideLeadFieldRepository extends LeadFieldRepository
{
    protected $fieldModel;

    /**
     * Initializes a new <tt>EntityRepository</tt>.
     *
     * @param EntityManager         $em    the EntityManager to use
     * @param Mapping\ClassMetadata $class the class descriptor
     */
    public function __construct($em, ClassMetadata $class, ExtendedFieldModel $fieldModel)
    {
        parent::__construct($em, $class);
        $this->fieldModel = $fieldModel;
    }

    /**
     * Overrides LeadBundle compareValue() method.
     *
     *
     * Compare a form result value with defined value for defined lead.
     * to handle extended field table schema differences from lead table
     * IE - needs a join and pivot on columns
     *
     * @param int    $lead         ID
     * @param int    $field        alias
     * @param string $value        to compare with
     * @param string $operatorExpr for WHERE clause
     *
     * @return bool
     */
    public function compareValue($lead, $field, $value, $operatorExpr)
    {
        $q = $this->_em->getConnection()->createQueryBuilder();

        if ($isExtendedField = $this->isExtendedField($field)) {
            $this->extendedCompareValue($q, $isExtendedField, $lead, $field, $value, $operatorExpr);
        } else {
            $q->select('l.id')
                ->from(MAUTIC_TABLE_PREFIX.'leads', 'l');

            if ('tags' === $field) {
                // Special reserved tags field
                $q->join('l', MAUTIC_TABLE_PREFIX.'lead_tags_xref', 'x', 'l.id = x.lead_id')
                    ->join('x', MAUTIC_TABLE_PREFIX.'lead_tags', 't', 'x.tag_id = t.id')
                    ->where(
                        $q->expr()->andX(
                            $q->expr()->eq('l.id', ':lead'),
                            $q->expr()->eq('t.tag', ':value')
                        )
                    )
                    ->setParameter('lead', (int) $lead)
                    ->setParameter('value', $value);

                $result = $q->execute()->fetch();

                if (('eq' === $operatorExpr) || ('like' === $operatorExpr)) {
                    return !empty($result['id']);
                } elseif (('neq' === $operatorExpr) || ('notLike' === $operatorExpr)) {
                    return empty($result['id']);
                } else {
                    return false;
                }
            } else {
                // Standard field
                if ('empty' === $operatorExpr || 'notEmpty' === $operatorExpr) {
                    $q->where(
                        $q->expr()->andX(
                            $q->expr()->eq('l.id', ':lead'),
                            ('empty' === $operatorExpr) ?
                                $q->expr()->orX(
                                    $q->expr()->isNull('l.'.$field),
                                    $q->expr()->eq('l.'.$field, $q->expr()->literal(''))
                                )
                                :
                                $q->expr()->andX(
                                    $q->expr()->isNotNull('l.'.$field),
                                    $q->expr()->neq('l.'.$field, $q->expr()->literal(''))
                                )
                        )
                    )
                        ->setParameter('lead', (int) $lead);
                } elseif ('regexp' === $operatorExpr || 'notRegexp' === $operatorExpr) {
                    if ('regexp' === $operatorExpr) {
                        $where = 'l.'.$field.' REGEXP  :value';
                    } else {
                        $where = 'l.'.$field.' NOT REGEXP  :value';
                    }

                    $q->where(
                        $q->expr()->andX(
                            $q->expr()->eq('l.id', ':lead'),
                            $q->expr()->andX($where)
                        )
                    )
                        ->setParameter('lead', (int) $lead)
                        ->setParameter('value', $value);
                } else {
                    $expr = $q->expr()->andX(
                        $q->expr()->eq('l.id', ':lead')
                    );

                    if ('neq' == $operatorExpr) {
                        // include null
                        $expr->add(
                            $q->expr()->orX(
                                $q->expr()->$operatorExpr('l.'.$field, ':value'),
                                $q->expr()->isNull('l.'.$field)
                            )
                        );
                    } else {
                        switch ($operatorExpr) {
                            case 'startsWith':
                                $operatorExpr = 'like';
                                $value        = $value.'%';
                                break;
                            case 'endsWith':
                                $operatorExpr = 'like';
                                $value        = '%'.$value;
                                break;
                            case 'contains':
                                $operatorExpr = 'like';
                                $value        = '%'.$value.'%';
                                break;
                        }

                        $expr->add(
                            $q->expr()->$operatorExpr('l.'.$field, ':value')
                        );
                    }

                    $q->where($expr)
                        ->setParameter('lead', (int) $lead)
                        ->setParameter('value', $value);
                }
            }
        }
        $result = $q->execute()->fetch();

        return !empty($result['id']);
    }

    /**
     * @param $field
     *
     * @return mixed
     */
    public function isExtendedField($field)
    {
        $qf = $this->_em->getConnection()->createQueryBuilder();
        $qf->select('lf.id, lf.object, lf.type, lf.alias, lf.field_group, lf.label')
            ->from(MAUTIC_TABLE_PREFIX.'lead_fields', 'lf')
            ->where(
                $qf->expr()->andX(
                    $qf->expr()->eq('lf.alias', ':alias')
                )
            )
            ->setParameter('alias', $field);

        $fieldConfig = $qf->execute()->fetch();

        return $fieldConfig;
    }

    /**
     * @param $isExtendedField
     * @param $lead
     * @param $field
     * @param $value
     * @param $operatorExpr
     */
    public function extendedCompareValue($q, $isExtendedField, $lead, $field, $value, $operatorExpr)
    {
        $fieldModel = $this->fieldModel;
        $dataType   = $fieldModel->getSchemaDefinition(
            $isExtendedField['alias'],
            $isExtendedField['type']
        );
        $dataType   = $dataType['type'];

        $secure    = false !== strpos($isExtendedField['object'], 'Secure') ? '_secure' : '';
        $tableName = 'lead_fields_leads_'.$dataType.$secure.'_xref';

        // select from the correct table
        $q->select('l.lead_id')
            ->from(MAUTIC_TABLE_PREFIX.$tableName, 'l');
        // and the lead_field_id matches the $field
        $q->where(
            $q->expr()->andX(
                $q->expr()->eq('l.lead_field_id', ':leadfieldid')
            )
        )
            ->setParameter('leadfieldid', (int) $isExtendedField['id']);
        // add a Where clause based on type

        if ('empty' === $operatorExpr || 'notEmpty' === $operatorExpr) {
            $q->where(
                $q->expr()->andX(
                    $q->expr()->eq('l.lead_id', ':lead'),
                    ('empty' === $operatorExpr) ?
                        $q->expr()->orX(
                            $q->expr()->isNull('l.value'),
                            $q->expr()->eq('l.value', $q->expr()->literal(''))
                        )
                        :
                        $q->expr()->andX(
                            $q->expr()->isNotNull('l.value'),
                            $q->expr()->neq('l.value', $q->expr()->literal(''))
                        )
                )
            )
                ->setParameter('lead', (int) $lead);
        } elseif ('regexp' === $operatorExpr || 'notRegexp' === $operatorExpr) {
            if ('regexp' === $operatorExpr) {
                $where = 'l.value REGEXP  :value';
            } else {
                $where = 'l.value NOT REGEXP  :value';
            }

            $q->where(
                $q->expr()->andX(
                    $q->expr()->eq('l.lead_id', ':lead'),
                    $q->expr()->andX($where)
                )
            )
                ->setParameter('lead', (int) $lead)
                ->setParameter('value', $value);
        } else {
            $expr = $q->expr()->andX(
                $q->expr()->eq('l.lead_id', ':lead')
            );

            if ('neq' == $operatorExpr) {
                // include null
                $expr->add(
                    $q->expr()->orX(
                        $q->expr()->$operatorExpr('l.value', ':value'),
                        $q->expr()->isNull('l.value')
                    )
                );
            } else {
                switch ($operatorExpr) {
                    case 'startsWith':
                        $operatorExpr = 'like';
                        $value        = $value.'%';
                        break;
                    case 'endsWith':
                        $operatorExpr = 'like';
                        $value        = '%'.$value;
                        break;
                    case 'contains':
                        $operatorExpr = 'like';
                        $value        = '%'.$value.'%';
                        break;
                }

                $expr->add(
                    $q->expr()->$operatorExpr('l.value', ':value')
                );
            }

            $q->where($expr)
                ->setParameter('lead', (int) $lead)
                ->setParameter('value', $value);
        }
    }

    /**
     * Gets a list of unique values from fields for autocompletes.
     * Overrides the method defined in CustomFieldRepositoryTrait
     * to included extended field value lookups.
     *
     * @param        $field
     * @param string $search
     * @param int    $limit
     * @param int    $start
     *
     * @return array
     */
    public function getValueList($field, $search = '', $limit = 10, $start = 0)
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();

        // get list of extendedFields
        if (!empty($extendedField = $this->isExtendedField($field))) {
            $fieldModel = $this->fieldModel;
            $dataType   = $fieldModel->getSchemaDefinition(
                $extendedField['alias'],
                $extendedField['type']
            );
            $dataType   = $dataType['type'];
            $secure     = false !== strpos($extendedField['object'], 'Secure') ? '_secure' : '';
            $table      = 'lead_fields_leads_'.$dataType.$secure.'_xref';
            $alias      = $dataType.$extendedField['id'];
            $col        = $alias.'.value';
        } else {
            $alias = 'l';
            // Not an Extended Field, Carry On.
            $table = $this->getEntityManager()->getClassMetadata($this->getClassName())->getTableName();
            $col   = $this->getTableAlias().'.'.$field;
        }

        $q
            ->select("DISTINCT $col AS $field")
            ->from($table, $alias);

        if (!empty($extendedField)) {
            $q->Where("$alias.lead_field_id = :fieldid")
                ->setParameter('fieldid', $extendedField['id']);
        } else {
            $q->where(
                $q->expr()->andX(
                    $q->expr()->neq($col, $q->expr()->literal('')),
                    $q->expr()->isNotNull($col)
                )
            );
        }

        if (!empty($search)) {
            $q->andWhere("$col LIKE :search")
                ->setParameter('search', "{$search}%");
        }

        $q->orderBy($field);

        if (!empty($limit)) {
            $q->setFirstResult($start)
                ->setMaxResults($limit);
        }

        $results = $q->execute()->fetchAll();

        return $results;
    }
}
