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

use Doctrine\ORM\QueryBuilder;
use Mautic\CoreBundle\Entity\CommonRepository;
use Mautic\LeadBundle\Entity\CustomFieldRepositoryInterface;
use Mautic\LeadBundle\Entity\CustomFieldRepositoryTrait;

/**
 * Class ExtendedFieldRepository.
 */
class ExtendedFieldRepository extends CommonRepository implements CustomFieldRepositoryInterface
{
    use CustomFieldRepositoryTrait;

    /**
     * @param int $id should be concat of lead (lead_id) and leadField (field name)
     *
     * @return mixed|null
     */
    public function getEntity($id = 0)
    {
        try {
            $q = $this->createQueryBuilder($this->getTableAlias());
            if (is_array($id)) {
                $this->buildSelectClause($q, $id);
                $extendedFieldId = $id['id'];
            } else {
                $extendedFieldId = $id;
            }
            $idArray   = explode('@', $extendedFieldId);
            $leadId    = isset($idArray[0]) && !is_null($idArray[0]) ? $idArray[0] : null;
            $leadField = isset($idArray[1]) && !is_null($idArray[1]) ? $idArray[1] : null;
            $q->andWhere($this->getTableAlias().'.lead = '.$leadId);
            $q->andWhere($this->getTableAlias().'.leadField = '.$leadField);
            $entity = $q->getQuery()->getSingleResult();
        } catch (\Exception $e) {
            $entity = null;
        }

        if (null != $entity && isset($extendedFieldId)) {
            // @todo - likely needs refactoring...
            $fieldValues = $this->getFieldValues($extendedFieldId, true, 'extendedField');
            $entity->setFields($fieldValues);
        }

        return $entity;
    }

    /**
     * @return mixed|string|void
     */
    public function getTableAlias()
    {
        // just nothing
    }

    /**
     * @return $this|\Doctrine\DBAL\Query\QueryBuilder
     */
    public function getEntitiesDbalQueryBuilder()
    {
        $alias = 'l';
        $dq    = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->from(MAUTIC_TABLE_PREFIX.'leads', $alias)
            ->leftJoin($alias, MAUTIC_TABLE_PREFIX.'users', 'u', 'u.id = '.$alias.'.owner_id');

        return $dq;
    }

    /**
     * @param $order
     *
     * @return QueryBuilder|void
     */
    public function getEntitiesOrmQueryBuilder($order)
    {
        // just nothing
    }

    /**
     * @return array|void
     */
    public function getFieldGroups()
    {
        // @todo - Implement getFieldGroups() method.
    }
}
