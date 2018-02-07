<?php

/*
 * @copyright   2014 Mautic Contributorextfld. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\ExtendedFieldBundle\Entity;

use Doctrine\DBAL\Query\Expression\CompositeExpression;
use Doctrine\ORM\QueryBuilder;
use Mautic\CoreBundle\Entity\CommonRepository;
use Mautic\LeadBundle\Entity\CustomFieldRepositoryTrait;
use Mautic\LeadBundle\Entity\CustomFieldRepositoryInterface;

/**
 * Class CompanyRepository.
 */
class ExtendedFieldRepository extends CommonRepository implements CustomFieldRepositoryInterface
{
    use CustomFieldRepositoryTrait;

    /**
     * {@inheritdoc}
     *
     * @param int $id should be concat of lead (lead_id) and leadField (field name)
     *
     * @return mixed|null
     */
    public function getEntity($id = 0)
    { // TODO: break ID into the two fields lead and leadField explode on seperator
        try {
            $q = $this->createQueryBuilder($this->getTableAlias());
            if (is_array($id)) {
                $this->buildSelectClause($q, $id);
                $extendedFieldId = $id['id'];
            } else {
                $extendedFieldId = $id;
            }
            $idArray = explode('@', $extendedFieldId);
            $leadId = isset($idArray[0]) && !isNUll($idArray[0]) ? $idArray[0]: NULL;
            $leadField = isset($idArray[1]) && !isNUll($idArray[1]) ? $idArray[1]: NULL;
            $q->andWhere($this->getTableAlias().'.lead = '.$leadId);
            $q->andWhere($this->getTableAlias().'.leadField = '.$leadField);
            $entity = $q->getQuery()->getSingleResult();
        } catch (\Exception $e) {
            $entity = null;
        }

        if ($entity != null) {
            $fieldValues = $this->getFieldValues($extendedFieldId, true, 'extendedField');
            $entity->setFields($fieldValues);
        }

        return $entity;
    }

    /**
     * Get a list of leads.
     *
     * @param array $args
     *
     * @return array
     */
    public function getEntities(array $args = [])
    {
        return $this->getEntitiesWithCustomFields('extendedField', $args);
    }

    /**
     * @return \Doctrine\DBAL\Query\QueryBuilder
     */
    public function getEntitiesDbalQueryBuilder()
    {
      // 'lead_fields_leads_'.$dataType.($secure ? '_secure' : '').'_xref');
      $dataType = ''; $secure = ''; // TODO: get these values from somewhere
      $tableName = 'lead_fields_leads_'.$dataType.($secure ? '_secure' : '').'_xref';
        $dq = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->from(MAUTIC_TABLE_PREFIX.$tableName, $this->getTableAlias());

        return $dq;
    }

    /**
     * @param $order
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function getEntitiesOrmQueryBuilder($order)
    {
        $q = $this->getEntityManager()->createQueryBuilder();
        $q->select($this->getTableAlias().','.$order)
            ->from('MauticExtendedFieldBundle:ExtendedFieldCommon', $this->getTableAlias(), $this->getTableAlias().'.id');

        return $q;
    }

    /**
     * Get the groups available for fields.
     *
     * @return array
     */
    public function getFieldGroups()
    {
        return ['core', 'professional', 'social', 'other'];
    }

    /**
     * Get extendedField by lead.
     *
     * @param   $leadId
     *
     * @return array
     */
    public function getExtendedFieldByLeadId($leadId)
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $alias = $this->getTableAlias();
        // 'lead_fields_leads_'.$dataType.($secure ? '_secure' : '').'_xref');
        $dataType = ''; $secure = ''; // TODO: get these values from somewhere
        $tableName = 'lead_fields_leads_'.$dataType.($secure ? '_secure' : '').'_xref';

        $q->select('$alias.id, $alias.lead, $alias.leadfield, $alias.value')
            ->from(MAUTIC_TABLE_PREFIX.$tableName, '$alias')
            ->where($alias . '.lead_id = :leadId')
            ->setParameter('leadId', $leadId)
            ->orderBy($alias . '.lead', 'DESC');

        $results = $q->execute()->fetchAll();

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function getTableAlias()

      // 'lead_fields_leads_'.$dataType.($secure ? '_secure' : '').'_xref'
    {
        return 'extfld';
    }

    /**
     * {@inheritdoc}
     */
    protected function addCatchAllWhereClause($q, $filter)
    {
        return $this->addStandardCatchAllWhereClause(
            $q,
            $filter,
            [
                'extfld.leadfield',
                'extfld.value',
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function addSearchCommandWhereClause($q, $filter)
    {
        return $this->addStandardSearchCommandWhereClause($q, $filter);
    }

    /**
     * {@inheritdoc}
     */
    public function getSearchCommands()
    {
        return $this->getStandardSearchCommands();
    }

    /**
     * @param string $id
     *
     * @return array|mixed
     */
    public function getExtendedField($id = '')
    {
        $dataType = ''; $secure = ''; // TODO: get these values from somewhere
        $tableName = 'lead_fields_leads_'.$dataType.($secure ? '_secure' : '').'_xref';
        $q                = $this->_em->getConnection()->createQueryBuilder();
        static $extendedField = [];

        if ($user) {
            $user = $this->currentUser;
        }

        $key = $id;
        if (isset($extendedField[$key])) {
            return $extendedField[$key];
        }

        $q->select('extfld.*')
            ->from(MAUTIC_TABLE_PREFIX.$tableName, 'extfld');

        if (!empty($id)) {
            $idArray = explode('@', $extendedFieldId);
            $leadId = isset($idArray[0]) && !isNUll($idArray[0]) ? $idArray[0]: '';
            $leadField = isset($idArray[1]) && !isNUll($idArray[1]) ? $idArray[1]: '';
            $q->where($this->getTableAlias().'.lead = '.$leadId);
            $q->andWhere($this->getTableAlias().'.leadField = '.$leadField);
        }

        if ($user) {
            $q->andWhere('extfld.created_by = :user');
            $q->setParameter('user', $user->getId());
        }

        $q->orderBy('extfld.lead', 'ASC');

        $results = $q->execute()->fetchAll();

        $extendedField[$key] = $results;

        return $results;
    }

    /**
     * Get a count of leads that belong to the company.
     *
     * @param $companyIds
     *
     * @return array
     */
    public function getLeadCount($extendedFieldIds)
    {
        $q = $this->_em->getConnection()->createQueryBuilder();

        $q->select('count(el.extendedField_id) as thecount, el.extendedField_id')
            ->from(MAUTIC_TABLE_PREFIX.'extended_field_leads', 'el');

        $returnArray = (is_array($extendedFieldIds));

        if (!$returnArray) {
            $extendedFieldIds = [$extendedFieldIds];
        }

        $q->where(
            $q->expr()->in('el.extended_field_id', $extendedFieldIds),
            $q->expr()->eq('el.manually_removed', ':false')
        )
            ->setParameter('false', false, 'boolean')
            ->groupBy('el.extended_field_id');

        $result = $q->execute()->fetchAll();

        $return = [];
        foreach ($result as $r) {
            $return[$r['extended_field_id']] = $r['thecount'];
        }

        // Ensure lists without leads have a value
        foreach ($extendedFieldIds as $l) {
            if (!isset($return[$l])) {
                $return[$l] = 0;
            }
        }

        return ($returnArray) ? $return : $return[$extendedFieldIds[0]];
    }


    /**
     * Get Extended Fields grouped by column.
     *
     * @param QueryBuilder $query
     *
     * @return array
     *
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function getExtendedFieldByGroup($query, $column)
    {
        $query->select('count(extfld.id) as extendedField, '.$column)
            ->addGroupBy($column)
            ->andWhere(
                $query->expr()->andX(
                    $query->expr()->isNotNull($column),
                    $query->expr()->neq($column, $query->expr()->literal(''))
                )
            );

        $results = $query->execute()->fetchAll();

        return $results;
    }

    /**
     * @param     $query
     * @param int $limit
     * @param int $offset
     *
     * @return mixed
     */
    public function getMostExtendedField($query, $limit = 10, $offset = 0)
    {
        $query->setMaxResults($limit)
            ->setFirstResult($offset);

        $results = $query->execute()->fetchAll();

        return $results;
    }

    /**
     * @param CompositeExpression|null $expr
     * @param array                    $parameters
     * @param null                     $labelColumn
     * @param string                   $valueColumn
     *
     * @return array
     */
    public function getAjaxSimpleList(CompositeExpression $expr = null, array $parameters = [], $labelColumn = null, $valueColumn = 'value')
    {
        $q = $this->_em->getConnection()->createQueryBuilder();

        $alias = $prefix = $this->getTableAlias();
        if (!empty($prefix)) {
            $prefix .= '.';
        }

        $tableName = $this->_em->getClassMetadata($this->getEntityName())->getTableName();

        $class      = '\\'.$this->getClassName();
        $reflection = new \ReflectionClass(new $class());

        // Get the label column if necessary
        if ($labelColumn == null) {
            if ($reflection->hasMethod('getTitle')) {
                $labelColumn = 'title';
            } else {
                $labelColumn = 'name';
            }
        }

        $q->select($prefix.$valueColumn.' as value, extfld.leadfield as label')
            ->from($tableName, $alias)
            ->orderBy($prefix.$labelColumn);

        if ($expr !== null && $expr->count()) {
            $q->where($expr);
        }

        if (!empty($parameters)) {
            $q->setParameters($parameters);
        }

        // Published only
        if ($reflection->hasMethod('getIsPublished')) {
            $q->andWhere(
                $q->expr()->eq($prefix.'is_published', ':true')
            )
                ->setParameter('true', true, 'boolean');
        }

        return $q->execute()->fetchAll();
    }
}
