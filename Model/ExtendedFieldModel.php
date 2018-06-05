<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticExtendedFieldBundle\Model;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\MauticExtendedFieldBundle\Entity\OverrideLeadFieldRepository;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * Class ExtendedFieldModel
 */
class ExtendedFieldModel extends FieldModel
{
    /**
     * @return OverrideLeadFieldRepository
     */
    public function getRepository()
    {
        $metastart = new ClassMetadata(LeadField::class);

        return new OverrideLeadFieldRepository($this->em, $metastart, $this);
    }

    /**
     * Save an extended field.
     *
     * Alterations from core:
     *  The save uses OverrideLeadFieldRepository if the field is extended.
     *  We also prevent alias collisions (as it wouldn't be a column definition).
     *
     * @param object $entity
     * @param bool   $unlock
     *
     * @throws DBALException
     * @throws DriverException
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws \Mautic\CoreBundle\Exception\SchemaException
     */
    public function saveEntity($entity, $unlock = true)
    {
        if (!$this->isExtendedField($entity)) {
            parent::saveEntity($entity, $unlock);

            return;
        }

        if (!$entity instanceof LeadField) {
            throw new MethodNotAllowedHttpException(['LeadEntity']);
        }

        $isNew = $entity->getId() ? false : true;
        $this->setTimestamps($entity, $isNew, $unlock);
        $alias = $entity->getAlias();
        if ($isNew) {
            if (empty($alias)) {
                $alias = $entity->getName();
            }

            // clean the alias
            $alias = $this->cleanAlias($alias, 'f_', 25);

            // make sure alias is not already taken
            $repo      = $this->getRepository();
            $aliases   = $repo->getAliases($entity->getId(), false, true, $entity->getObject());
            $aliasTag  = 1;
            $testAlias = $alias;
            while (in_array($testAlias, $aliases)) {
                $testAlias = $alias.$aliasTag++;
            }
            $entity->setAlias($testAlias);
        }

        // Special treatment for time (same as core).
        if ('time' == $entity->getType()) {
            $entity->setIsListable(false);
        }

        // Presave and postsave (same as core, but using OverrideLeadFieldRepository).
        $event = $this->dispatchEvent('pre_save', $entity, $isNew);
        $this->getRepository()->saveEntity($entity);
        $this->dispatchEvent('post_save', $entity, $isNew, $event);

        // Update order of the other fields (same as core).
        $this->reorderFieldsByEntity($entity);
    }

    /**
     * @param $entity
     *
     * @return bool
     */
    public function isExtendedField($entity)
    {
        return in_array($entity->getObject(), ['extendedField', 'extendedFieldSecure']);
    }

    /**
     * Alterations from core:
     *  Include extended objects.
     *  Check permissions (if secure).
     *
     * @return array
     */
    public function getLeadFields()
    {
        // @todo - Change this to a permission base.
        if (false) {
            $leadFields = $this->getEntities(
                [
                    'filter' => [
                        'force' => [
                            [
                                'column' => 'f.object',
                                'expr'   => 'in',
                                'value'  => ['lead', 'extendedField'],
                            ],
                        ],
                    ],
                ]
            );
        } else {
            $leadFields = $this->getEntities(
                [
                    'filter' => [
                        'force' => [
                            [
                                'column' => 'f.object',
                                'expr'   => 'in',
                                'value'  => ['lead', 'extendedField', 'extendedFieldSecure'],
                            ],
                        ],
                    ],
                ]
            );
        }

        return $leadFields;
    }

    /**
     * Get list of custom field values for autopopulate fields.
     *
     * Alterations from core:
     *  Uses OverrideLeadFieldRepository.
     *
     * @param $type
     * @param $filter
     * @param $limit
     *
     * @return array
     */
    public function getLookupResults($type, $filter = '', $limit = 10)
    {
        return $this->getRepository()->getValueList($type, $filter, $limit);
    }

    /**
     * Alterations from core:
     *  Include extended field objects if retrieving lead fields.
     *
     * @param bool|true $byGroup
     * @param bool|true $alphabetical
     * @param array     $filters
     *
     * @return array
     */
    public function getFieldList(
        $byGroup = true,
        $alphabetical = true,
        $filters = ['isPublished' => true, 'object' => 'lead']
    ) {
        if (empty($filters['object']) || 'lead' != $filters['object']) {
            return parent::getFieldList($byGroup, $alphabetical, $filters);
        }

        $forceFilters = [];
        foreach ($filters as $col => $val) {
            if ('object' === $col && 'lead' === $val) {
                $forceFilters[] = [
                    'column' => "f.{$col}",
                    'expr'   => 'in',
                    'value'  => ['lead', 'extendedField', 'extendedFieldSecure'],
                ];
            } else {
                $forceFilters[] = [
                    'column' => "f.{$col}",
                    'expr'   => 'eq',
                    'value'  => $val,
                ];
            }
        }

        // The rest of this method is the same as core...
        $fields     = $this->getEntities(
            [
                'filter'     => [
                    'force' => $forceFilters,
                ],
                'orderBy'    => 'f.order',
                'orderByDir' => 'asc',
            ]
        );
        $leadFields = [];
        foreach ($fields as $f) {
            if ($byGroup) {
                $fieldName                              = $this->translator->trans(
                    'mautic.lead.field.group.'.$f->getGroup()
                );
                $leadFields[$fieldName][$f->getAlias()] = $f->getLabel();
            } else {
                $leadFields[$f->getAlias()] = $f->getLabel();
            }
        }
        if ($alphabetical) {
            // Sort the groups
            uksort($leadFields, 'strnatcmp');
            if ($byGroup) {
                // Sort each group by translation
                foreach ($leadFields as $group => &$fieldGroup) {
                    uasort($fieldGroup, 'strnatcmp');
                }
            }
        }

        return $leadFields;
    }

    /**
     * Alterations from core:
     *  Include extended field objects if retrieving lead fields
     *
     * @param string $object
     *
     * @return array
     */
    public function getPublishedFieldArrays($object = 'lead')
    {
        if ('lead' !== $object) {
            return parent::getPublishedFieldArrays($object);
        }

        return $this->getEntities(
            [
                'filter'         => [
                    'force' => [
                        [
                            'column' => 'f.isPublished',
                            'expr'   => 'eq',
                            'value'  => true,
                        ],
                        [
                            'column' => 'f.object',
                            'expr'   => 'in',
                            'value'  => ['lead', 'extendedField', 'extendedFieldSecure'],
                        ],
                    ],
                ],
                'hydration_mode' => 'HYDRATE_ARRAY',
            ]
        );
    }

    /**
     * Alterations from core:
     *  If an extended field entity, delete entries in xref tables instead of dropping a column.
     *
     * @param object $entity
     *
     * @throws \Mautic\CoreBundle\Exception\SchemaException
     */
    public function deleteEntity($entity)
    {
        if (!$this->isExtendedField($entity)) {
            return parent::deleteEntity($entity);
        }

        // Pre-delete event (same as core).
        $id    = $entity->getId();
        $event = $this->dispatchEvent('pre_delete', $entity);
        $this->getRepository()->deleteEntity($entity);

        $schema        = $this->getSchemaDefinition($entity->getName(), $entity->getType());
        $secure        = 'extendedFieldSecure' === $entity->getObject() ? '_secure' : '';
        $extendedTable = MAUTIC_TABLE_PREFIX.'lead_fields_leads_'.$schema['type'].$secure.'_xref';
        $column        = [
            'lead_field_id' => $entity->getId(),
        ];
        $this->em->getConnection()->delete(
            $extendedTable,
            $column
        );

        // Post-delete event (same as core).
        // set the id for use in events
        $entity->deletedId = $id;
        $this->dispatchEvent('post_delete', $entity, false, $event);
    }
}
