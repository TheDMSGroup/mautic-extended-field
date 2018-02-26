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

use Mautic\LeadBundle\Entity\LeadFieldRepository as LeadFieldRepository;
use MauticPlugin\MauticExtendedFieldBundle\Model\ExtendedFieldModel as ExtendedFieldModel;
use Doctrine\ORM\Mapping\ClassMetadata;

class OverrideLeadFieldRepository extends LeadFieldRepository

{
    protected $fieldModel;
    /**
     * Initializes a new <tt>EntityRepository</tt>.
     *
     * @param EntityManager         $em    The EntityManager to use.
     * @param Mapping\ClassMetadata $class The class descriptor.
     */
    public function __construct($em, ClassMetadata $class, ExtendedFieldModel $fieldModel)
    {
        parent::__construct($em, $class);
        $this->fieldModel = $fieldModel;
    }

    /**
     * Overrides LeadBundle compareValue() method
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

        if($isExtendedField = $this->isExtendedField($field)) {
            $this->extendedCompareValue($q, $isExtendedField, $lead, $field, $value, $operatorExpr);
        } else {

            $q->select('l.id')
              ->from(MAUTIC_TABLE_PREFIX.'leads', 'l');

            if ($field === 'tags') {
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

                if (($operatorExpr === 'eq') || ($operatorExpr === 'like')) {
                    return !empty($result['id']);
                } elseif (($operatorExpr === 'neq') || ($operatorExpr === 'notLike')) {
                    return empty($result['id']);
                } else {
                    return false;
                }
            } else {
                // Standard field
                if ($operatorExpr === 'empty' || $operatorExpr === 'notEmpty') {
                    $q->where(
                      $q->expr()->andX(
                        $q->expr()->eq('l.id', ':lead'),
                        ($operatorExpr === 'empty') ?
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
                } elseif ($operatorExpr === 'regexp' || $operatorExpr === 'notRegexp') {
                    if ($operatorExpr === 'regexp') {
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

                    if ($operatorExpr == 'neq') {
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
     * @return mixed
     */

    public function isExtendedField($field){
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

    public function extendedCompareValue($q, $isExtendedField, $lead, $field, $value, $operatorExpr) {
        $fieldModel = $this->fieldModel;
        $dataType = $fieldModel->getSchemaDefinition(
          $isExtendedField['alias'],
          $isExtendedField['type']
        );
        $dataType = $dataType['type'];

        $secure = strpos($isExtendedField['object'], "Secure") !== FALSE ? "_secure": "";
        $tableName = "lead_fields_leads_" . $dataType . $secure . '_xref';

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

        if ($operatorExpr === 'empty' || $operatorExpr === 'notEmpty') {
            $q->where(
              $q->expr()->andX(
                $q->expr()->eq('l.lead_id', ':lead'),
                ($operatorExpr === 'empty') ?
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
        } elseif ($operatorExpr === 'regexp' || $operatorExpr === 'notRegexp') {
            if ($operatorExpr === 'regexp') {
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

            if ($operatorExpr == 'neq') {
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
                  $q->expr()->$operatorExpr('l.value', ':value')
                );
            }

            $q->where($expr)
              ->setParameter('lead', (int) $lead)
              ->setParameter('value', $value);
        }
    }

}