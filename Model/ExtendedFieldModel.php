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


use Mautic\CoreBundle\Form\RequestTrait;
use Mautic\CoreBundle\Model\AjaxLookupModelInterface;
use Mautic\CoreBundle\Model\FormModel as CommonFormModel;
use Mautic\EmailBundle\Helper\EmailValidator;
use Mautic\LeadBundle\Model\DefaultValueTrait;
use Mautic\LeadBundle\Entity\LeadEventLog;
use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\LeadEvents;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * Class CompanyModel.
 */
abstract class ExtendedFieldModel extends CommonFormModel implements AjaxLookupModelInterface
{
    use DefaultValueTrait, RequestTrait;

    /**
     * @var FieldModel
     */
    protected $leadFieldModel;

    /**
     * @var array
     */
    protected $ExtendedFields;


    /**
     * ExtendedFieldsModel constructor.
     *
     * @param FieldModel     $leadFieldModel
     */
    public function __construct(FieldModel $leadFieldModel)
    {
        $this->leadFieldModel = $leadFieldModel;
    }

  /**
   * @return array
   */
  public function getExtendedFieldFields()
  {
    $extendedFieldFields = $this->getEntities([
      'filter' => [
        'force' => [
          [
            'column' => 'f.object',
            'expr'   => 'like',
            'value'  => 'extendedField',
          ],
        ],
      ],
    ]);

    return $extendedFieldFields;
  }

    /**
     * @param ExtendedField $entity
     * @param bool    $unlock
     */
    public function saveEntity($entity, $unlock = true)
    {
        $this->setEntityDefaultValues($entity, 'ExtendedField');

        parent::saveEntity($entity, $unlock);
    }

    /**
     * {@inheritdoc}
     *
     * @return \Mautic\LeadBundle\Entity\ExtendedFieldsRepository
     */
    public function getRepository()
    {
        return $this->em->getRepository('MauticExtendedFieldBundle:ExtendedFieldCommon');
    }

    /**
     * {@inheritdoc}
     *
     * @return \Mautic\ExtendedFieldBundle\Entity\ExtendedFieldRepository
     */
    public function getExtendedFieldRepository()
    {
        return $this->em->getRepository('MauticExtendedFieldBundle:ExtendedFieldCommon');
    }


    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getNameGetter()
    {
        return 'getPrimaryIdentifier';
    }


    /**
     * {@inheritdoc}
     *
     * @param should be concat of lead id and leadField (field name)
     *
     * @return extendedField|null
     */
    public function getEntity($id = null)
    {
        if ($id === null) {
            return new ExtendedField();
        }

        return parent::getEntity($id);
    }

    /**
     * Reorganizes a field list to be keyed by field's group then alias.
     *
     * @param $fields
     *
     * @return array
     */
    public function organizeFieldsByGroup($fields)
    {
        $array = [];

        foreach ($fields as $field) {
            if ($field instanceof LeadField) {
                $alias = $field->getAlias();
                if ($field->getObject() === 'ExtendedField') {
                    $group                          = $field->getGroup();
                    $array[$group][$alias]['id']    = $field->getId();
                    $array[$group][$alias]['group'] = $group;
                    $array[$group][$alias]['label'] = $field->getLabel();
                    $array[$group][$alias]['alias'] = $alias;
                    $array[$group][$alias]['type']  = $field->getType();
                }
            } else {
                $alias   = $field['alias'];
                $field[] = $alias;
                if ($field['object'] === 'ExtendedField') {
                    $group                          = $field['group'];
                    $array[$group][$alias]['id']    = $field['id'];
                    $array[$group][$alias]['group'] = $group;
                    $array[$group][$alias]['label'] = $field['label'];
                    $array[$group][$alias]['alias'] = $alias;
                    $array[$group][$alias]['type']  = $field['type'];
                }
            }
        }

        //make sure each group key is present
        $groups = ['core', 'social', 'personal', 'professional'];
        foreach ($groups as $g) {
            if (!isset($array[$g])) {
                $array[$g] = [];
            }
        }

        return $array;
    }

    /**
     * {@inheritdoc}
     *
     * @param $action
     * @param $event
     * @param $entity
     * @param $isNew
     *
     * @throws \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException
     */
    protected function dispatchEvent($action, &$entity, $isNew = false, Event $event = null)
    {
        if (!$entity instanceof Company) {
            throw new MethodNotAllowedHttpException(['Email']);
        }

        switch ($action) {
            case 'pre_save':
                $name = LeadEvents::EXTENDED_FIELDS_PRE_SAVE;
                break;
            case 'post_save':
                $name = LeadEvents::EXTENDED_FIELDS_POST_SAVE;
                break;
            case 'pre_delete':
                $name = LeadEvents::EXTENDED_FIELDS_PRE_DELETE;
                break;
            case 'post_delete':
                $name = LeadEvents::EXTENDED_FIELDS_POST_DELETE;
                break;
            default:
                return null;
        }

        if ($this->dispatcher->hasListeners($name)) {
            if (empty($event)) {
                $event = new ExtendedFieldEvent($entity, $isNew);
                $event->setEntityManager($this->em);
            }

            $this->dispatcher->dispatch($name, $event);

            return $event;
        } else {
            return null;
        }
    }


    /**
     * @return array
     */
    public function fetchExtendedField()
    {
        if (empty($this->ExtendedFields)) {
            $this->ExtendedFields = $this->leadFieldModel->getEntities(
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
                                'expr'   => 'eq',
                                'value'  => 'extendedField',
                            ],
                        ],
                    ],
                    'hydration_mode' => 'HYDRATE_ARRAY',
                ]
            );
        }

        return $this->ExtendedFields;
    }

    /**
     * @param $mappedFields
     * @param $data
     *
     * @return array
     */
    public function extractExtendedFieldDataFromImport(array &$mappedFields, array &$data)
    {
        $ExtendedFieldsData    = [];
        $ExtendedFields  = [];
        $internalFields = $this->fetchExtendedField();

        foreach ($mappedFields as $mauticField => $importField) {
            foreach ($internalFields as $entityField) {
                if ($entityField['alias'] === $mauticField) {
                    $ExtendedFieldsData[$importField]   = $data[$importField];
                    $ExtendedFields[$mauticField] = $importField;
                    unset($data[$importField]);
                    unset($mappedFields[$mauticField]);
                    break;
                }
            }
        }

        return [$ExtendedField, $ExtendedFieldData];
    }

    /**
     * @param array        $fields
     * @param array        $data
     * @param null         $owner
     * @param null         $list
     * @param null         $tags
     * @param bool         $persist
     * @param LeadEventLog $eventLog
     *
     * @return bool|null
     *
     * @throws \Exception
     */
    public function import($fields, $data, $owner = null, $list = null, $tags = null, $persist = true, LeadEventLog $eventLog = null)
    {
        $fields = array_flip($fields);
        // do something here and then return it

        return null;
    }
}
