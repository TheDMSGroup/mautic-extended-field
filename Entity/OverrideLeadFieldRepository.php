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

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\LeadBundle\Entity\LeadFieldRepository as LeadFieldRepository;
use MauticPlugin\MauticExtendedFieldBundle\Model\ExtendedFieldModel as ExtendedFieldModel;

class OverrideLeadFieldRepository extends LeadFieldRepository
{
    /** @var ExtendedFieldModel */
    protected $fieldModel;

    /**
     * OverrideLeadFieldRepository constructor.
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
     * @param string $field
     *
     * @return null|array
     */
    public function getExtendedField($field)
    {
        $qf = $this->_em->getConnection()->createQueryBuilder();
        $qf->select('lf.id, lf.object, lf.type, lf.alias, lf.field_group, lf.label')
            ->from(MAUTIC_TABLE_PREFIX.'lead_fields', 'lf')
            ->where(
                $qf->expr()->andX(
                    $qf->expr()->eq('lf.alias', ':alias'),
                    $qf->expr()->like('lf.object', $qf->expr()->literal('extended%'))
                )
            )
            ->setParameter('alias', $field);

        $fieldConfig = $qf->execute()->fetch();

        return $fieldConfig;
    }

    /**
     * Overrides LeadBundle compareValue() method.
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
            // Standard field / UTM field / Extended field
            $extendedField = $this->getExtendedField($field);
            if ($extendedField) {
                $secure    = (false !== strpos($extendedField['object'], 'Secure')) ? '_secure' : '';
                $schemaDef = $this->fieldModel->getSchemaDefinition(
                    $extendedField['alias'],
                    $extendedField['type']
                );
                $tableName = MAUTIC_TABLE_PREFIX.'lead_fields_leads_'.$schemaDef['type'].$secure.'_xref';
                $q->join('l', $tableName, 'x', 'l.id = x.lead_id AND '.$extendedField['id'].' = x.lead_field_id');
                $property = 'x.value';
            } elseif (in_array($field, ['utm_campaign', 'utm_content', 'utm_medium', 'utm_source', 'utm_term'])) {
                $q->join('l', MAUTIC_TABLE_PREFIX.'lead_utmtags', 'u', 'l.id = u.lead_id');
                $q->orderBy('u.date_added', 'DESC');
                $q->setMaxResults(1);
                $property = 'u.'.$field;
            } else {
                $property = 'l.'.$field;
            }
            if ('empty' === $operatorExpr || 'notEmpty' === $operatorExpr) {
                $q->where(
                    $q->expr()->andX(
                        $q->expr()->eq('l.id', ':lead'),
                        ('empty' === $operatorExpr) ?
                            $q->expr()->orX(
                                $q->expr()->isNull($property),
                                $q->expr()->eq($property, $q->expr()->literal(''))
                            )
                            :
                            $q->expr()->andX(
                                $q->expr()->isNotNull($property),
                                $q->expr()->neq($property, $q->expr()->literal(''))
                            )
                    )
                )
                    ->setParameter('lead', (int) $lead);
            } elseif ('regexp' === $operatorExpr || 'notRegexp' === $operatorExpr) {
                if ('regexp' === $operatorExpr) {
                    $where = $property.' REGEXP  :value';
                } else {
                    $where = $property.' NOT REGEXP  :value';
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
                            $q->expr()->$operatorExpr($property, ':value'),
                            $q->expr()->isNull($property)
                        )
                    );
                } else {
                    switch ($operatorExpr) {
                        case 'startsWith':
                            $operatorExpr    = 'like';
                            $value           = $value.'%';
                            break;
                        case 'endsWith':
                            $operatorExpr   = 'like';
                            $value          = '%'.$value;
                            break;
                        case 'contains':
                            $operatorExpr   = 'like';
                            $value          = '%'.$value.'%';
                            break;
                    }

                    $expr->add(
                        $q->expr()->$operatorExpr($property, ':value')
                    );
                }

                $q->where($expr)
                    ->setParameter('lead', (int) $lead)
                    ->setParameter('value', $value);
            }

            $result = $q->execute()->fetch();

            return !empty($result['id']);
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
        if ($extendedField = $this->getExtendedField($field)) {
            $fieldModel = $this->fieldModel;
            $dataType   = $fieldModel->getSchemaDefinition(
                $extendedField['alias'],
                $extendedField['type']
            );
            $dataType   = $dataType['type'];
            $secure     = false !== strpos($extendedField['object'], 'Secure') ? '_secure' : '';
            $table      = MAUTIC_TABLE_PREFIX.'lead_fields_leads_'.$dataType.$secure.'_xref';
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
            $q->where("$alias.lead_field_id = :fieldid")
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
