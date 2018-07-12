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
use Mautic\LeadBundle\Model\FieldModel;
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
    /** @var FieldModel */
    protected $fieldModel;

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
    public function __construct(EntityManager $entityManager, ExtendedFieldModel $fieldModel)
    {
        parent::__construct($entityManager);
        $this->entityManager = $entityManager;
        $this->fieldModel    = $fieldModel;
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
                    $this->extendedFieldAliases = array_keys($this->fieldModel->getExtendedFields());
                }
                if ($this->extendedFieldAliases) {
                    foreach ($this->extendedFieldAliases as $alias) {
                        // This'll tip us off if this is ever used for more than just validating column existence.
                        $columns[$alias] = 'extendedField - This is never used as an actual column object';
                    }
                }
            }
            $this->cache[$tableName] = $columns ?: [];
        }

        return $this->cache[$tableName];
    }
}
