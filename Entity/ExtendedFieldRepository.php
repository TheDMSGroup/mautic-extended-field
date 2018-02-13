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
 * Class ExtendedFieldRepository.
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
    {
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


}
