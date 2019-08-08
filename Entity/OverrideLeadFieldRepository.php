<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Digital Media Solutions, LLC
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticExtendedFieldBundle\Entity;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\CoreBundle\Helper\CacheStorageHelper;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\LeadBundle\Entity\LeadFieldRepository;
use MauticPlugin\MauticExtendedFieldBundle\Model\ExtendedFieldModel;

class OverrideLeadFieldRepository extends LeadFieldRepository
{
    /** @var ExtendedFieldModel */
    protected $fieldModel;

    /** @var CoreParametersHelper */
    protected $coreParametersHelper;

    /**
     * @var array
     */
    protected $extendedFieldConfigurations;

    /**
     * OverrideLeadFieldRepository constructor.
     *
     * @param EntityManager        $em
     * @param ClassMetadata        $class
     * @param ExtendedFieldModel   $fieldModel
     * @param CoreParametersHelper $coreParametersHelper
     */
    public function __construct(EntityManager $em, ClassMetadata $class, ExtendedFieldModel $fieldModel, CoreParametersHelper $coreParametersHelper)
    {
        parent::__construct($em, $class);
        $this->fieldModel                  = $fieldModel;
        $this->coreParametersHelper        = $coreParametersHelper;
    }

    /**
     * Overrides LeadFieldRepository::compareValue().
     *
     * Alterations to core:
     *  If the field is extended a join is added and the property is set to "x.value",
     *      otherwise this method is identical to core, but will have to be updated frequently due to the nature
     *      of this core method. It is always expanding, but is not extensible by overriding.
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
        // Alterations to core start.
        // Run the standard compareValue if not an extended field for better BC.
        $extendedField               = $this->getExtendedFieldConfigurations($field);
        if (!$extendedField) {
            return parent::compareValue($lead, $field, $value, $operatorExpr);
        }
        // Alterations to core end.

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
            // Standard field / UTM field
            // Irrelevant for extended fields.
            $utmField = in_array($field, ['utm_campaign', 'utm_content', 'utm_medium', 'utm_source', 'utm_term']);
            // if ($utmField) {
            //     $q->join('l', MAUTIC_TABLE_PREFIX.'lead_utmtags', 'u', 'l.id = u.lead_id');
            //     $property = 'u.'.$field;
            // } else {
            //     $property = 'l.'.$field;
            // }

            // Alterations to core start.
            // We already know this is an extended field, so add out join and override the property.
            $secure    = 'extendedFieldSecure' === $extendedField['object'] ? '_secure' : '';
            $schema    = $this->fieldModel->getSchemaDefinition($extendedField['alias'], $extendedField['type']);
            $tableName = MAUTIC_TABLE_PREFIX.'lead_fields_leads_'.$schema['type'].$secure.'_xref';
            $q->join('l', $tableName, 'x', 'l.id = x.lead_id AND '.$extendedField['id'].' = x.lead_field_id');
            $property = 'x.value';
            // Alterations to core end.

            if ('empty' === $operatorExpr || 'notEmpty' === $operatorExpr) {
                //use a left join to pick up nulls
                $q->resetQueryPart('join')
                    ->leftJoin('l', $tableName, 'x', 'l.id = x.lead_id AND '.$extendedField['id'].' = x.lead_field_id');
                $valueExpr = 'empty' === $operatorExpr
                    ? $q->expr()->orX(
                        $q->expr()->isNull($property),
                        "$property = ''"
                    )
                    : $q->expr()->andX(
                        $q->expr()->isNotNull($property),
                        "$property != ''"
                    );
                $q->where(
                    $q->expr()->eq('l.id', ':lead'),
                    $valueExpr
                )
                ->setParameter('lead', (int) $lead);
            } elseif ('regexp' === $operatorExpr || 'notRegexp' === $operatorExpr || 'like' === $operatorExpr || 'notLike' === $operatorExpr) {
                if ('regexp' === $operatorExpr || 'like' === $operatorExpr) {
                    $where = $property.' REGEXP  :value';
                } else {
                    $where = $property.' NOT REGEXP  :value';
                }

                $q->where(
                    $q->expr()->eq('l.id', ':lead'),
                    $where
                )
                    ->setParameter('lead', (int) $lead)
                    ->setParameter('value', $value);
            } elseif ('in' === $operatorExpr || 'notIn' === $operatorExpr) {
                $value = $q->expr()->literal(
                    InputHelper::clean($value)
                );

                $value = trim($value, "'");
                if ('not' === substr($operatorExpr, 0, 3)) {
                    $operator = 'NOT REGEXP';
                } else {
                    $operator = 'REGEXP';
                }

                $expr = $q->expr()->andX(
                    $q->expr()->eq('l.id', ':lead')
                );

                $expr->add(
                    $property." $operator '\\\\|?$value\\\\|?'"
                );

                $q->where($expr)
                    ->setParameter('lead', (int) $lead);
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
                        $q->expr()->$operatorExpr($property, ':value')
                    );
                }

                $q->where($expr)
                    ->setParameter('lead', (int) $lead)
                    ->setParameter('value', $value);
            }
            if ($utmField) {
                // Match only against the latest UTM properties.
                $q->orderBy('u.date_added', 'DESC');
                $q->setMaxResults(1);
            }
            $result = $q->execute()->fetch();

            return !empty($result['id']);
        }
    }

    /**
     * @param $fieldAlias
     *
     * @return array|bool|mixed|null
     */
    private function getExtendedFieldConfigurations($fieldAlias)
    {
        if (!$this->extendedFieldConfigurations) {
            $cacheHelper     = new CacheStorageHelper(
                CacheStorageHelper::ADAPTOR_FILESYSTEM,
                'extendedFieldConfiguration',
                null,
                $this->coreParametersHelper->getParameter('mautic.cache_path'),
                60
            );
            $extendedFieldConfigurations = $cacheHelper->get('extendedFieldConfiguration', 60);
            if (empty($extendedFieldConfigurations)) {
                $qf = $this->_em->getConnection()->createQueryBuilder();
                $qf->select('lf.alias, lf.id, lf.object, lf.type, lf.field_group as "group", lf.object, lf.label')
                    ->from(MAUTIC_TABLE_PREFIX.'lead_fields', 'lf', 'lf.alias')
                    ->where(
                        $qf->expr()->orX(
                            $qf->expr()->eq('lf.object', $qf->expr()->literal('extendedField')),
                            $qf->expr()->eq('lf.object', $qf->expr()->literal('extendedFieldSecure'))
                        )
                    );
                $results = $qf->execute()->fetchAll(0);

                foreach ($results as $field) {
                    $extendedFieldConfigurations[$field['alias']] = $field;
                }

                $cacheHelper->set('extendedFieldConfiguration', $extendedFieldConfigurations, 60);
            }
            $this->extendedFieldConfigurations = $extendedFieldConfigurations;
        }

        if ($fieldAlias) {
            return isset($this->extendedFieldConfigurations[$fieldAlias]) ? $this->extendedFieldConfigurations[$fieldAlias] : null;
        } else {
            return $this->extendedFieldConfigurations;
        }
    }

    /**
     * Overrides CustomFieldRepositoryTrait::getValueList().
     *
     * Alterations to core:
     *  Different query for extended fields to join correctly.
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
        $fieldModel = $this->fieldModel;

        // get list of extendedFields
        $extendedField               = $this->getExtendedFieldConfigurations($field);
        if ($extendedField) {
            $dataType   = $fieldModel->getSchemaDefinition(
                $extendedField['alias'],
                $extendedField['type']
            );
            $dataType   = $dataType['type'];
            $secure     = 'extendedFieldSecure' === $extendedField['object'] ? '_secure' : '';
            $table      = MAUTIC_TABLE_PREFIX.'lead_fields_leads_'.$dataType.$secure.'_xref';
            $alias      = $dataType.$extendedField['id'];
            $col        = $alias.'.value';
            $q          = $this->getEntityManager()->getConnection()->createQueryBuilder();
            $q->select("DISTINCT $col")
                ->from($table, $alias)
                ->where($alias.'.lead_field_id = :fieldid')
                ->setParameter('fieldid', $extendedField['id']);
            if (!empty($search)) {
                $q->andWhere("$col LIKE :search")
                    ->setParameter('search', "{$search}%");
            }

            $q->orderBy($col);

            if (!empty($limit)) {
                $q->setFirstResult($start)
                    ->setMaxResults($limit);
            }

            $results = $q->execute()->fetchAll();
        } else {
            // The following is same as core CustomFieldRepositoryTrait::getValueList()
            // Includes prefix
            // hardcodes the lead table if its not Extended Field
            $col   = 'l.'.$field;
            $q     = $this->getEntityManager()->getConnection()->createQueryBuilder()
                ->select("DISTINCT $col")
                ->from('leads', 'l');

            $q->where(
                $q->expr()->andX(
                    $q->expr()->neq($col, $q->expr()->literal('')),
                    $q->expr()->isNotNull($col)
                )
            );

            if (!empty($search)) {
                $q->andWhere("$col LIKE :search")
                    ->setParameter('search', "{$search}%");
            }

            $q->orderBy($col);

            if (!empty($limit)) {
                $q->setFirstResult($start)
                    ->setMaxResults($limit);
            }

            $results = $q->execute()->fetchAll();
        }

        return $results;
    }
}
