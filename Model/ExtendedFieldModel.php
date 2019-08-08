<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Digital Media Solutions, LLC
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
 * Class ExtendedFieldModel.
 */
class ExtendedFieldModel extends FieldModel
{
    /**
     * Method used to get a whitelist of extended fields for query consideration.
     *
     * @return array
     */
    public function getExtendedFields()
    {
        $result = [];
        $fields = $this->getEntities(
            [
                'filter'         => [
                    'where' => [
                        [
                            'expr' => 'orX',
                            'val'  => [
                                ['column' => 'f.object', 'expr' => 'eq', 'value' => 'extendedField'],
                                ['column' => 'f.object', 'expr' => 'eq', 'value' => 'extendedFieldSecure'],
                            ],
                        ],
                    ],
                ],
                'hydration_mode' => 'HYDRATE_ARRAY',
            ]
        );
        foreach ($fields as $field) {
            $result[$field['alias']] = $field;
        }

        return $result;
    }

    /**
     * Returns lead custom fields.
     *
     * Alterations to core:
     *  Include extended objects when various methods attempt to get fields of object 'lead'.
     *
     * @param $args
     *
     * @return array
     */
    public function getEntities(array $args = [])
    {
        // @todo - use permission base to exclude secure if necessary.
        $replacementFilter = [
            'column' => 'f.object',
            'expr'   => 'neq',
            'value'  => ['company'],
        ];
        foreach ($args as $type => &$arg) {
            if ('filter' === $type) {
                foreach ($arg as $key => &$filter) {
                    if ('force' === $key) {
                        foreach ($filter as $forceKey => &$forceFilter) {
                            if (
                                !empty($forceFilter['column'])
                                && 'f.object' == $forceFilter['column']
                                && !empty($forceFilter['expr'])
                                && 'eq' == $forceFilter['expr']
                                && !empty($forceFilter['value'])
                                && 'lead' == $forceFilter['value']
                            ) {
                                $forceFilter = $replacementFilter;
                            }
                        }
                    } elseif ('object' === $key && 'lead' === $filter) {
                        // Move the filter to force mode in order to replace.
                        if (!isset($arg['force'])) {
                            $arg['force'] = [];
                        }
                        $arg['force'][] = $replacementFilter;
                        unset($arg[$key]);
                    }
                }
            }
        }

        return parent::getEntities($args);
    }

    /**
     * Save an extended field.
     *
     * Alterations to core:
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
     * @return OverrideLeadFieldRepository
     */
    public function getRepository()
    {
        $metastart = new ClassMetadata(LeadField::class);

        return new OverrideLeadFieldRepository($this->em, $metastart, $this, $this->coreParametersHelper);
    }

    /**
     * Get list of custom field values for autopopulate fields.
     *
     * Alterations to core:
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
     * Alterations to core:
     *  If an extended field entity, delete entries in xref tables instead of dropping a column.
     *
     * @param object $entity
     *
     * @throws \Doctrine\DBAL\Exception\InvalidArgumentException
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

    /**
     * Alterations to core:
     *  Include extended field arrays if the object is not company.
     *
     * @param string $object
     *
     * @return array
     */
    public function getPublishedFieldArrays($object = 'lead')
    {
        if ('company' != $object) {
            $expr   = 'neq';
            $object = 'company';
        } else {
            $expr = 'eq';
        }

        return $this->getEntities(
            [
                'filter' => [
                    'force' => [
                        [
                            'column' => 'f.isPublished',
                            'expr'   => 'eq',
                            'value'  => true,
                        ],
                        [
                            'column' => 'f.object',
                            'expr'   => $expr,
                            'value'  => $object,
                        ],
                    ],
                ],
                'hydration_mode' => 'HYDRATE_ARRAY',
            ]
        );
    }

    /**
     * Alterations to core:
     *  Include extended fields.
     *
     * @return array
     */
    public function getLeadFields()
    {
        $leadFields = $this->getEntities([
            'filter' => [
                'force' => [
                    [
                        'column' => 'f.object',
                        'expr'   => 'neq',
                        'value'  => 'company',
                    ],
                ],
            ],
        ]);

        return $leadFields;
    }
}
