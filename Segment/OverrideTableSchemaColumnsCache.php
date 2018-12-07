<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Digital Media Solutions, LLC
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html\
 */

namespace MauticPlugin\MauticExtendedFieldBundle\Segment;

use Doctrine\ORM\EntityManager;
use Mautic\LeadBundle\Segment\TableSchemaColumnsCache;
use MauticPlugin\MauticExtendedFieldBundle\Model\ExtendedFieldModel;

/**
 * Class OverrideTableSchemaColumnsCache.
 *
 * Alterations from core:
 *  Prevents exception when running getColumns() to ensure a column exists.
 *      This is irrelevant for eav-like fields on joins.
 */
class OverrideTableSchemaColumnsCache extends TableSchemaColumnsCache
{
    /** @var array */
    protected $extendedFieldAliases;

    /** @var EntityManager */
    private $entityManager;

    /** @var array */
    private $cache;

    /**
     * OverrideTableSchemaColumnsCache constructor.
     *
     * @param EntityManager      $entityManager
     * @param ExtendedFieldModel $fieldModel
     */
    public function __construct(EntityManager $entityManager)
    {
        parent::__construct($entityManager);
        $this->entityManager = $entityManager;
        $this->cache         = [];
    }

    /**
     * @param $tableName
     *
     * @return array|false
     */
    public function getColumns($tableName)
    {
        if (!isset($this->cache[$tableName])) {
            $columns = $this->entityManager->getConnection()->getSchemaManager()->listTableColumns($tableName);
            if ('leads' === $tableName) {
                if (!$this->extendedFieldAliases) {
                    // Hacked this query into here to avoid a recursive dependency in 2.15.0
                    $fq = $this->entityManager->getConnection()->createQueryBuilder();
                    $fq->select('f.alias')
                        ->from(MAUTIC_TABLE_PREFIX.'lead_fields', 'f')
                        ->where(
                            $fq->expr()->orX(
                                $fq->expr()->eq('f.object', $fq->expr()->literal('extendedField')),
                                $fq->expr()->eq('f.object', $fq->expr()->literal('extendedFieldSecure'))
                            )
                        );
                    foreach ($fq->execute()->fetchAll() as $result) {
                        $this->extendedFieldAliases[$result['alias']] = 'extendedField - This is never used as an actual column object';
                    }
                }
                if ($this->extendedFieldAliases) {
                    $columns = array_merge($columns, $this->extendedFieldAliases);
                }
            }
            $this->cache[$tableName] = $columns ?: [];
        }

        return $this->cache[$tableName];
    }
}
