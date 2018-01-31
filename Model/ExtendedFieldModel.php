<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticExtendedFieldBundle\Model;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Exception\DriverException;
use Mautic\CoreBundle\Doctrine\Helper\ColumnSchemaHelper;
use Mautic\CoreBundle\Doctrine\Helper\SchemaHelperFactory;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\CoreBundle\Model\FormModel;
use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Entity\LeadFieldRepository;
use Mautic\LeadBundle\Event\LeadFieldEvent;
use Mautic\LeadBundle\Helper\FormFieldHelper;
use Mautic\LeadBundle\LeadEvents;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Mautic\LeadBundle\Model\FieldModel as FieldModel;

/**
 * Class ExtendedFieldModel
 * {@inheritdoc}
 */
class ExtendedFieldModel extends FieldModel {

  /**
   * @param   $entity
   * @param   $unlock
   *
   * @throws DBALException
   *
   * @return mixed
   */
  public function saveEntity($entity, $unlock = TRUE) {
    if (!$entity instanceof LeadField) {
      throw new MethodNotAllowedHttpException(['LeadEntity']);
    }

    $isNew = $entity->getId() ? FALSE : TRUE;
    //set some defaults
    // custom table names
    $dataType = $entity->getType();
    $secure = NULL; // for now its null until I can find a way to know what it should be
    $tableName = 'lead_fields_leads_' . $dataType . ($secure ? '_secure' : '') . '_xref';

    $this->setTimestamps($entity, $isNew, $unlock);
    $objects = [
      'lead' => 'leads',
      'company' => 'companies',
      'extendedField' => $tableName
    ];
    $alias = $entity->getAlias();
    $object = $objects[$entity->getObject()];

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
      $repo = $this->getRepository();
      $testAlias = $alias;
      $aliases = $repo->getAliases($entity->getId(), FALSE, TRUE, $entity->getObject());
      $count = (int) in_array($testAlias, $aliases);
      $aliasTag = $count;

      while ($count) {
        $testAlias = $alias . $aliasTag;
        $count = (int) in_array($testAlias, $aliases);
        ++$aliasTag;
      }

      if ($testAlias != $alias) {
        $alias = $testAlias;
      }

      $entity->setAlias($alias);
    }

    $type = $entity->getType();

    if ($type == 'time') {
      //time does not work well with list filters
      $entity->setIsListable(FALSE);
    }

    // Save the entity now if it's an existing entity

    if (!$isNew) {
      $event = $this->dispatchEvent('pre_save', $entity, $isNew);
      $this->getRepository()->saveEntity($entity);
      $this->dispatchEvent('post_save', $entity, $isNew, $event);
    }
    // Create the field as its own column in the leads table.
    // dont do this for extendedField object types
    if ($entity->getObject != 'extendedField') {
      /** @var ColumnSchemaHelper $leadsSchema */
      $leadsSchema = $this->schemaHelperFactory->getSchemaHelper('column', $object);
      $isUnique = $entity->getIsUniqueIdentifier();
      // If the column does not exist in the contacts table, add it
      if (!$leadsSchema->checkColumnExists($alias)) {
        $schemaDefinition = self::getSchemaDefinition($alias, $type, $isUnique);

        $leadsSchema->addColumn($schemaDefinition);

        try {
          $leadsSchema->executeChanges();
          $isCreated = TRUE;
        } catch (DriverException $e) {
          $this->logger->addWarning($e->getMessage());

          if ($e->getErrorCode() === 1118 /* ER_TOO_BIG_ROWSIZE */) {
            $isCreated = FALSE;
            throw new DBALException($this->translator->trans('mautic.core.error.max.field'));
          }
          else {
            throw $e;
          }
        }
      }
    }
    // If this is a new contact field, and it was successfully added to the contacts table, save it
    if ($isNew === TRUE) {
      $event = $this->dispatchEvent('pre_save', $entity, $isNew);
      $this->getRepository()->saveEntity($entity);
      $this->dispatchEvent('post_save', $entity, $isNew, $event);
    }

    // Update the unique_identifier_search index and add an index for this field
    /** @var \Mautic\CoreBundle\Doctrine\Helper\IndexSchemaHelper $modifySchema */
    $modifySchema = $this->schemaHelperFactory->getSchemaHelper('index', $object);

    if ('string' == $schemaDefinition['type']) {
      try {
        $modifySchema->addIndex([$alias], $alias . '_search');
        $modifySchema->allowColumn($alias);

        if ($isUnique) {
          // Get list of current uniques
          $uniqueIdentifierFields = $this->getUniqueIdentifierFields();

          // Always use email
          $indexColumns = ['email'];
          $indexColumns = array_merge($indexColumns, array_keys($uniqueIdentifierFields));
          $indexColumns[] = $alias;

          // Only use three to prevent max key length errors
          $indexColumns = array_slice($indexColumns, 0, 3);
          $modifySchema->addIndex($indexColumns, 'unique_identifier_search');
        }

        $modifySchema->executeChanges();
      } catch (DriverException $e) {
        if ($e->getErrorCode() === 1069 /* ER_TOO_MANY_KEYS */) {
          $this->logger->addWarning($e->getMessage());
        }
        else {
          throw $e;
        }
      }
    }


    // Update order of the other fields.
    $this->reorderFieldsByEntity($entity);
  }

}
