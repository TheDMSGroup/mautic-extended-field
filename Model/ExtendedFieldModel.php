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
use Mautic\CoreBundle\Doctrine\Helper\ColumnSchemaHelper;
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
     * @param      $entity
     * @param bool $unlock
     *
     * @return mixed|void
     *
     * @throws DBALException
     * @throws DriverException
     * @throws \Mautic\CoreBundle\Exception\SchemaException
     */
    public function saveEntity($entity, $unlock = true)
    {
        if (!$entity instanceof LeadField) {
            throw new MethodNotAllowedHttpException(['LeadEntity']);
        }

        $isNew = $entity->getId() ? false : true;
        //set some defaults
        // custom table names
        $dataType  = $this->getSchemaDefinition($entity->getAlias(), $entity->getType());
        $dataType  = $dataType['type'];
        $secure    = 'extendedFieldSecure' === $entity->getObject() ? '_secure' : '';
        $tableName = MAUTIC_TABLE_PREFIX.'lead_fields_leads_'.$dataType.$secure.'_xref';

        $this->setTimestamps($entity, $isNew, $unlock);
        $objects = [
            'lead'                => 'leads',
            'company'             => 'companies',
            'extendedField'       => $tableName,
            'extendedFieldSecure' => $tableName,
        ];
        $alias   = $entity->getAlias();
        $object  = $objects[$entity->getObject()];

        if ($isNew) {
            if (empty($alias)) {
                $alias = $entity->getName();
            }

            if (empty($object)) {
                $object = $objects[$entity->getObject()];
            }

            // clean the alias
            $alias = $this->cleanAlias($alias, 'f_', 25);

            // make sure alias is not already taken
            $repo      = $this->getRepository();
            $testAlias = $alias;
            $aliases   = $repo->getAliases($entity->getId(), false, true, $entity->getObject());
            $count     = (int) in_array($testAlias, $aliases);
            $aliasTag  = $count;

            while ($count) {
                $testAlias = $alias.$aliasTag;
                $count     = (int) in_array($testAlias, $aliases);
                ++$aliasTag;
            }

            if ($testAlias != $alias) {
                $alias = $testAlias;
            }

            $entity->setAlias($alias);
        }

        $type = $entity->getType();

        if ('time' == $type) {
            //time does not work well with list filters
            $entity->setIsListable(false);
        }

        // Save the entity now if it's an existing entity

        if (!$isNew) {
            $event = $this->dispatchEvent('pre_save', $entity, $isNew);
            $this->getRepository()->saveEntity($entity);
            $this->dispatchEvent('post_save', $entity, $isNew, $event);
        }

        // Create the field as its own column in the leads table.
        // dont do this for extendedField or extendedFieldSecure object types
        if (!$this->isExtendedField($entity)) {
            /** @var ColumnSchemaHelper $leadsSchema */
            $leadsSchema = $this->schemaHelperFactory->getSchemaHelper('column', $object);
            $isUnique    = $entity->getIsUniqueIdentifier();
            // If the column does not exist in the contacts table, add it
            if (!$leadsSchema->checkColumnExists($alias)) {
                $schemaDefinition = self::getSchemaDefinition($alias, $type, $isUnique);

                $leadsSchema->addColumn($schemaDefinition);

                try {
                    $leadsSchema->executeChanges();
                    $isCreated = true;
                } catch (DriverException $e) {
                    $this->logger->addWarning($e->getMessage());

                    if (1118 === $e->getErrorCode() /* ER_TOO_BIG_ROWSIZE */) {
                        $isCreated = false;
                        throw new DBALException($this->translator->trans('mautic.core.error.max.field'));
                    } else {
                        throw $e;
                    }
                }
            }
            // Update the unique_identifier_search index and add an index for this field
            /** @var \Mautic\CoreBundle\Doctrine\Helper\IndexSchemaHelper $modifySchema */
            $modifySchema = $this->schemaHelperFactory->getSchemaHelper('index', $object);

            if ('string' == $schemaDefinition['type']) {
                try {
                    $modifySchema->addIndex([$alias], $alias.'_search');
                    $modifySchema->allowColumn($alias);

                    if ($isUnique) {
                        // Get list of current uniques
                        $uniqueIdentifierFields = $this->getUniqueIdentifierFields();

                        // Always use email
                        $indexColumns   = ['email'];
                        $indexColumns   = array_merge($indexColumns, array_keys($uniqueIdentifierFields));
                        $indexColumns[] = $alias;

                        // Only use three to prevent max key length errors
                        $indexColumns = array_slice($indexColumns, 0, 3);
                        $modifySchema->addIndex($indexColumns, 'unique_identifier_search');
                    }

                    $modifySchema->executeChanges();
                } catch (DriverException $e) {
                    if (1069 === $e->getErrorCode() /* ER_TOO_MANY_KEYS */) {
                        $this->logger->addWarning($e->getMessage());
                    } else {
                        throw $e;
                    }
                }
            }
        }

        // If this is a new contact field, and it was successfully added to the contacts table, save it
        if (true === $isNew) {
            $event = $this->dispatchEvent('pre_save', $entity, $isNew);
            $this->getRepository()->saveEntity($entity);
            $this->dispatchEvent('post_save', $entity, $isNew, $event);
        }

        // Update order of the other fields.
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
     * @return array
     */
    public function getLeadFields()
    {
        if (false) { // TODO change this to a permission base
            // get extended and lead ONLY
            $expr = [
                'filter' => [
                    'force' => [
                        'column' => 'f.object',
                        'expr'   => 'neq',
                        'value'  => 'extendedFieldSecure',
                    ],
                ],
            ];
        } else {
            //get all of 'em (no filters)
            $expr = [];
        }

        $leadFields = $this->getEntities($expr);

        return $leadFields;
    }

    /**
     * Get list of custom field values for autopopulate fields.
     *
     * @param $type
     * @param $filter
     * @param $limit
     *
     * @return array
     */
    public function getLookupResults($type, $filter = '', $limit = 10)
    {
        $repo = $this->getRepository();

        return $repo->getValueList($type, $filter, $limit);
    }

    /**
     * @param bool|true $byGroup
     * @param bool|true $alphabetical
     * @param array     $filters
     *
     * @return array
     */
    public function getFieldList(
        $byGroup = true,
        $alphabetical = true,
        $filters = [
            'isPublished' => true,
            //'object' => 'lead'  instead, get all non-company fields (lead, extendedField, extendedFieldSecure)
        ]
    ) {
        $forceFilters = [];
        foreach ($filters as $col => $val) {
            $forceFilters[] = [
                'column' => "f.{$col}",
                'expr'   => 'eq',
                'value'  => $val,
            ];
        }
        // Get a list of custom form fields
        $fields = $this->getEntities(
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
     * @param string $object
     *
     * @return array
     */
    public function getPublishedFieldArrays($object = 'lead')
    {
        // if object is lead, get all objects except company else get the requested object
        if ('lead' == $object) {
            $value = 'company';
            $expr  = 'neq';
        } else {
            $value = $object;
            $expr  = 'eq';
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
                            'expr'   => $expr,
                            'value'  => $value,
                        ],
                    ],
                ],
                'hydration_mode' => 'HYDRATE_ARRAY',
            ]
        );
    }

    /**
     * {@inheritdoc}
     *
     * @param  $entity
     */
    public function deleteEntity($entity)
    {
        if ($this->isExtendedField($entity)) {
            $dataType      = $this->getSchemaDefinition($entity->getName(), $entity->getType());
            $dataType      = $dataType['type'];
            $secure        = 'extendedFieldSecure' === $entity->getObject() ? '_secure' : '';
            $extendedTable = MAUTIC_TABLE_PREFIX.'lead_fields_leads_'.$dataType.$secure.'_xref';
            $column        = [
                'lead_field_id' => $entity->getId(),
            ];

            $this->em->getConnection()->delete(
                $extendedTable,
                $column
            );

            $id    = $entity->getId();
            $event = $this->dispatchEvent('pre_delete', $entity);
            $this->getRepository()->deleteEntity($entity);

            //set the id for use in events
            $entity->deletedId = $id;
            $this->dispatchEvent('post_delete', $entity, false, $event);
        } else {
            parent::deleteEntity($entity);

            $objects = ['lead' => 'leads', 'company' => 'companies'];
            $object  = $objects[$entity->getObject()];

            //remove the column from the leads table
            $leadsSchema = $this->schemaHelperFactory->getSchemaHelper('column', $object);
            $leadsSchema->dropColumn($entity->getAlias());
            $leadsSchema->executeChanges();
        }
    }
}
