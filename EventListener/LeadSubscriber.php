<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Digital Media Solutions, LLC
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticExtendedFieldBundle\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Mautic\LeadBundle\Event\LeadListFilteringEvent;
use Mautic\LeadBundle\Event\LeadListQueryBuilderGeneratedEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\MauticExtendedFieldBundle\Model\ExtendedFieldModel;

/**
 * Class LeadSubscriber.
 */
class LeadSubscriber implements EventSubscriberInterface
{
    /** @var ExtendedFieldModel */
    protected $leadModel;

    /** @var array */
    protected $extendedFields;

    /** @var array */
    protected $aliases;

    /** @var array */
    protected $seen;

    /**
     * LeadSubscriber constructor.
     *
     * @param ExtendedFieldModel $leadModel
     */
    public function __construct(ExtendedFieldModel $leadModel)
    {
        $this->leadModel = $leadModel;
        $this->aliases   = $this->seen = [];
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            LeadEvents::LIST_FILTERS_ON_FILTERING           => ['addExtendedFieldFilters'],
            LeadEvents::LIST_FILTERS_QUERYBUILDER_GENERATED => ['correctExtendedFieldColumns'],
        ];
    }

    /**
     * @param LeadListFilteringEvent $event
     */
    public function addExtendedFieldFilters(LeadListFilteringEvent $event)
    {
        $details = $event->getDetails();
        if (isset($details['object']) && 'lead' === $details['object']) {
            $fieldAlias = $details['field'];
            if (!$this->extendedFields) {
                $this->extendedFields = $this->leadModel->getExtendedFields();
            }
            if (isset($this->extendedFields[$fieldAlias])) {
                //prevent duplicate joins without preventing joins
                if (!array_key_exists($fieldAlias, $this->seen)) {
                    $joins = $event->getQueryBuilder()->getQueryPart('join');
                    if (isset($joins['l'])) {
                        foreach ($joins['l'] as $join) {
                            if (isset($this->seen[$fieldAlias]) && $join['joinAlias'] === $this->seen[$fieldAlias]) {
                                return;
                            }
                        }
                    }
                }
                // This is an extended field that needs to be modified to use the appropriate xref table.
                $field         = $this->extendedFields[$fieldAlias];
                $schema        = $this->leadModel->getSchemaDefinition($fieldAlias, $field['type']);
                $secure        = 'extendedFieldSecure' === $field['object'] ? '_secure' : '';
                $extendedTable = MAUTIC_TABLE_PREFIX.'lead_fields_leads_'.$schema['type'].$secure.'_xref';
                $joinAlias     = $event->getAlias();
                $q             = $event->getQueryBuilder();
                $q->leftJoin(
                    'l',
                    $extendedTable,
                    $joinAlias,
                    $joinAlias.'.lead_id = l.id AND '.$joinAlias.'.lead_field_id = '.(int) $field['id']
                );
                $this->aliases[$joinAlias] = $fieldAlias;
                $this->seen[$fieldAlias]   = $joinAlias;
            }
        }
    }

    /**
     * After addExtendedFieldFilters, and the query is fully built, we must correct extended fields to use
     * the correct table aliases instead of "l.".
     *
     * @param LeadListQueryBuilderGeneratedEvent $event
     */
    public function correctExtendedFieldColumns(LeadListQueryBuilderGeneratedEvent $event)
    {
        $q            = $event->getQueryBuilder();
        $parts        = $q->getQueryParts();
        $changedParts = [];
        $aliases      = [];
        if (isset($parts['join']['l']) && !empty($this->aliases)) {
            // Confirm the aliases are for the current query by present joins.
            foreach ($parts['join']['l'] as $key => $join) {
                if (
                    is_array($join)
                    && isset($join['joinType'])
                    && 'left' === $join['joinType']
                    && isset($this->aliases[$join['joinAlias']])
                ) {
                    $aliases[$join['joinAlias']] = $this->aliases[$join['joinAlias']];
                }
            }
            if (count($aliases)) {
                foreach ($aliases as $joinAlias => $fieldAlias) {
                    foreach (['where', 'orWhere', 'andWhere', 'having', 'orHaving', 'andHaving'] as $type) {
                        if (isset($parts[$type])) {
                            $changedParts[$type] = $this->partCorrect($parts[$type], $fieldAlias, $this->seen[$fieldAlias]);
                        }
                    }
                }
            }
            foreach ($changedParts as $type => $t) {
                $q->setQueryPart($type, $parts[$type]);
            }
        }
    }

    /**
     * @param $part
     * @param $fieldAlias
     * @param $tableAlias
     *
     * @return bool
     */
    private function partCorrect(&$part, $fieldAlias, $tableAlias)
    {
        $result = false;
        if (is_array($part)) {
            foreach ($part as &$subPart) {
                if ($this->partCorrect($subPart, $fieldAlias, $tableAlias)) {
                    $result = true;
                }
            }

            return $result;
        }

        $partOriginal = strval($part);
        $partChanged  = preg_replace(
            '/\bl.'.$fieldAlias.' /m',
            $tableAlias.'.value ',
            $partOriginal
        );
        if (null !== $partChanged && $partChanged !== $partOriginal) {
            $part   = $partChanged;
            $result = true;
        }

        return $result;
    }
}
