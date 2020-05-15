<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Digital Media Solutions, LLC
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

/**
 * TODO
 * UTM Tags are being added to the $columns array as a shim on Segment Data Sources, so that UTM Tags are available on all reports that show leads.
 *
 * This could be handled by Core, pending approval.
 *
 * This MAY create duplicate lead records because of the One to Many relationship of UTM Tags to Leads.
 */

namespace MauticPlugin\MauticExtendedFieldBundle\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Mautic\LeadBundle\Report\FieldsBuilder;
use Mautic\ReportBundle\Event\ReportBuilderEvent;
use Mautic\ReportBundle\Event\ReportGeneratorEvent;
use Mautic\ReportBundle\Event\ReportGraphEvent;
use Mautic\ReportBundle\ReportEvents;

/**
 * Class ConfigSubscriber.
 */
class ReportSubscriber implements EventSubscriberInterface
{
    /**
     * @var
     */
    protected $event;

    /**
     * @var
     */
    protected $query;

    /**
     * @var
     */
    protected $fieldModel;

    /**
     * @var array
     */
    protected $selectParts = [];

    /**
     * @var array
     */
    protected $orderByParts = [];

    /**
     * @var array
     */
    protected $groupByParts = [];

    /**
     * @var array
     */
    protected $filters = [];

    /**
     * @var
     */
    protected $where;

    /**
     * @var array
     */
    protected $extendedFields = [];

    /**
     * @var array
     */
    protected $fieldTables = [];

    /**
     * @var int
     */
    protected $count = 0;

    /**
     * @var FieldsBuilder
     */
    private $fieldsBuilder;

    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    const SEGMENT_MEMBERSHIP = 'segment.membership';
    const GROUP_CONTACTS     = 'contacts';

    /**
     * @param FieldsBuilder $fieldsBuilder
     * @param EventDispatcherInterface $dispatcher
     */
    public function __construct(FieldsBuilder $fieldsBuilder, EventDispatcherInterface $dispatcher)
    {
        $this->fieldsBuilder    = $fieldsBuilder;
        $this->dispatcher       = $dispatcher;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        // Need to set the priority depending on if this is a Report edit or a Report Generate/View
        // and only $_SERVER has necessary data when this method is invoked
        $priority = isset($_SERVER['REQUEST_URI']) && false !== strpos($_SERVER['REQUEST_URI'], '/edit/') ? -20 : 20;

        $eventList = [
            ReportEvents::REPORT_ON_BUILD          => ['onReportBuilder', $priority], // Adding UTM Tags into $columns array
            ReportEvents::REPORT_ON_GENERATE       => ['onReportGenerate', -20], // Adding UTM Tags into $columns array
            ReportEvents::REPORT_ON_GRAPH_GENERATE => ['onReportGraphGenerate', 20],
            ReportEvents::REPORT_QUERY_PRE_EXECUTE => ['onReportQueryPreExecute'],
        ];

        return $eventList;
    }

    /**
     * @param $event
     */
    public function onReportQueryPreExecute($event)
    {
        $this->fieldTables = [];
        $this->event       = $event;
        $this->query       = $event->getQuery();
        $this->convertToExtendedFieldQuery();
        $this->event->setQuery($this->query);
    }

    /**
     * helper method to convert queries with extendedField optins
     * in select, orderBy and GroupBy to work with the
     * extendedField schema.
     */
    private function convertToExtendedFieldQuery()
    {
        $this->fieldModel   = $this->dispatcher->getContainer()->get('mautic.lead.model.field');
        $this->selectParts  = $this->query->getQueryPart('select');
        $this->orderByParts = $this->query->getQueryPart('orderBy');
        $this->groupByParts = $this->query->getQueryPart('groupBy');
        $this->filters      = $this->event->getReport()->getFilters();
        $this->where        = $this->query->getQueryPart('where');
        $this->fieldTables  = isset($this->fieldTables) ? $this->fieldTables : [];
        $this->count        = 0;
        if (!$this->extendedFields) {
            // Previous method deprecated:
            // $fields = $this->fieldModel->getEntities(
            //     [
            //         [
            //             'column' => 'f.isPublished',
            //             'expr'   => 'eq',
            //             'value'  => true,
            //         ],
            //         'force'          => [
            //             'column' => 'f.object',
            //             'expr'   => 'in',
            //             'value'  => ['extendedField', 'extendedFieldSecure'],
            //         ],
            //         'hydration_mode' => 'HYDRATE_ARRAY',
            //     ]
            // );
            // // Key by alias.
            // foreach ($fields as $field) {
            //     $this->extendedFields[$field['alias']] = $field;
            // }
            $this->extendedFields = $this->fieldModel->getExtendedFields();
        }

        $this->alterSelect();
        if (method_exists($this->event, 'getQuery')) { // identify ReportQueryEvent instance in backwards compatible way
            $this->alterOrderBy();
        }
        $this->alterGroupBy();
        $this->alterWhere();

        $this->query->select($this->selectParts);
        if (method_exists($this->event, 'getQuery') && !empty($this->orderByParts)) {
            $orderBy = implode(',', $this->orderByParts);
            $this->query->add('orderBy', $orderBy);
        }
        if (!empty($this->groupByParts)) {
            $this->query->groupBy($this->groupByParts);
        }
        if (!empty($this->where)) {
            $this->query->where($this->where);
        }
    }

    private function alterSelect()
    {
        foreach ($this->selectParts as $key => $selectPart) {
            $aggregate = false;
            if (false !== strpos($selectPart, 'l.')) {
                // field from the lead table, so check if its an extended field
                $partStrings = (explode(' AS ', $selectPart));
                if (method_exists($this->event, 'getQuery')) {
                    // just in case the select contains an aggregation method like COUNT or MAX
                    if (false !== strpos($partStrings[0], '(')) {
                        preg_match('/\((.*?)\)/', $partStrings[0], $string);
                        $fieldAlias = $this->event->getOptions()['columns'][$string[1]]['alias'];
                        $realField  = substr($string[1], strrpos($string[1], '.') + 1);
                        $aggregate  = true;
                    } else {
                        $fieldAlias = $this->event->getOptions()['columns'][$partStrings[0]]['alias'];
                        $realField  = substr($partStrings[0], strrpos($partStrings[0], '.') + 1);
                    }
                } else {
                    $fieldAlias = $realField = $partStrings[1];
                }

                if (isset($this->extendedFields[$realField])) {
                    // is extended field, so rewrite the SQL part.
                    $dataType  = $this->fieldModel->getSchemaDefinition(
                        $this->extendedFields[$realField]['alias'],
                        $this->extendedFields[$realField]['type']
                    );
                    $dataType  = $dataType['type'];
                    $secure    = 'extendedFieldSecure' === $this->extendedFields[$realField]['object'] ? '_secure' : '';
                    $tableName = MAUTIC_TABLE_PREFIX.'lead_fields_leads_'.$dataType.$secure.'_xref';
                    ++$this->count;
                    $fieldId = $this->extendedFields[$realField]['id'];

                    if (array_key_exists($realField, $this->fieldTables)) {
                        if ($aggregate) {
                            $this->selectParts[$key] = preg_replace("/$string[1]/", $this->fieldTables[$realField]['alias'].'.value', $selectPart, 1);
                        } else {
                            $this->selectParts[$key] = $this->fieldTables[$realField]['alias'].'.value AS '.$fieldAlias;
                        }
                    } else {
                        if ($aggregate) {
                            $this->selectParts[$key] = preg_replace("/$string[1]/", "t$this->count.value", $selectPart, 1);
                        } else {
                            $this->selectParts[$key] = "t$this->count.value AS $fieldAlias";
                        }

                        $this->fieldTables[$realField] = [
                            'table' => $tableName,
                            'alias' => 't'.$this->count,
                        ];
                        $this->query->leftJoin(
                            'l',
                            $tableName,
                            't'.$this->count,
                            'l.id = t'.$this->count.'.lead_id AND t'.$this->count.'.lead_field_id = '.$fieldId
                        );
                    }
                }
            }
        }
    }

    private function alterOrderBy()
    {
        foreach ($this->orderByParts as $key => $orderByPart) {
            if (0 === strpos($orderByPart, 'l.')) {
                // field from the lead table, so check if its an extended field
                $partStrings = (explode(' ', $orderByPart));
                $fieldAlias  = substr($partStrings[0], 2);

                if (isset($this->extendedFields[$fieldAlias])) {
                    // is extended field, so rewrite the SQL part.
                    if (array_key_exists($fieldAlias, $this->fieldTables)) {
                        // set using the existing table alias from the previously altered select statement
                        $this->orderByParts[$key] = $fieldAlias;
                    } else {
                        // field hasnt been identified yet
                        // add a join statement
                        $dataType  = $this->fieldModel->getSchemaDefinition(
                            $this->extendedFields[$fieldAlias]['alias'],
                            $this->extendedFields[$fieldAlias]['type']
                        );
                        $dataType  = $dataType['type'];
                        $secure    = 'extendedFieldSecure' === $this->extendedFields[$fieldAlias]['object'] ? '_secure' : '';
                        $tableName = MAUTIC_TABLE_PREFIX.'lead_fields_leads_'.$dataType.$secure.'_xref';
                        ++$this->count;
                        $fieldId = $this->extendedFields[$fieldAlias]['id'];

                        $this->fieldTables[$fieldAlias] = [
                            'table' => $tableName,
                            'alias' => 't'.$this->count,
                        ];
                        $this->query->leftJoin(
                            'l',
                            $tableName,
                            't'.$this->count,
                            'l.id = t'.$this->count.'.lead_id AND t'.$this->count.'.lead_field_id = '.$fieldId
                        );
                        $this->orderByParts[$key] = 't'.$this->count.'.value';
                    }
                }
            }
        }
    }

    private function alterGroupBy()
    {
        foreach ($this->groupByParts as $key => $groupByPart) {
            if (0 === strpos($groupByPart, 'l.')) {
                // field from the lead table, so check if its an extended
                $fieldAlias = substr($groupByPart, 2);
                if (isset($this->extendedFields[$fieldAlias])) {
                    // is extended field, so rewrite the SQL part.
                    if (array_key_exists($fieldAlias, $this->fieldTables)) {
                        // set using the existing table alias from the altered select statement
                        $this->groupByParts[$key] = $this->fieldTables[$fieldAlias]['alias'].'.value';
                    } else {
                        // field hasnt been identified yet so generate unique alias and table
                        $dataType  = $this->fieldModel->getSchemaDefinition(
                            $this->extendedFields[$fieldAlias]['alias'],
                            $this->extendedFields[$fieldAlias]['type']
                        );
                        $dataType  = $dataType['type'];
                        $secure    = 'extendedFieldSecure' === $this->extendedFields[$fieldAlias]['object'] ? '_secure' : '';
                        $tableName = MAUTIC_TABLE_PREFIX.'lead_fields_leads_'.$dataType.$secure.'_xref';
                        ++$this->count;
                        $fieldId = $this->extendedFields[$fieldAlias]['id'];

                        $this->fieldTables[$fieldAlias] = [
                            'table' => $tableName,
                            'alias' => 't'.$this->count,
                        ];
                        $this->query->leftJoin(
                            'l',
                            $tableName,
                            't'.$this->count,
                            'l.id = t'.$this->count.'.lead_id AND t'.$this->count.'.lead_field_id = '.$fieldId
                        );
                        $this->groupByParts[$key] = 't'.$this->count.'.value';
                    }
                }
            }
        }
    }

    private function alterWhere()
    {
        if (!empty($this->where)) {
            $where = $this->where->__toString();
            foreach ($this->filters as $filter) {
                if (0 === strpos($filter['column'], 'l.')) {
                    // field from the lead table, so check if its an extended
                    $fieldAlias = substr($filter['column'], 2);
                    if (isset($this->extendedFields[$fieldAlias])) {
                        // is extended field, so rewrite the SQL part.
                        if (array_key_exists($fieldAlias, $this->fieldTables)) {
                            // set using the existing table alias from the altered select statement
                            $where = str_replace(
                                $filter['column'],
                                $this->fieldTables[$fieldAlias]['alias'].'.value',
                                $where
                            );
                        } else {
                            // field hasnt been identified yet so generate unique alias and table
                            $dataType  = $this->fieldModel->getSchemaDefinition(
                                $this->extendedFields[$fieldAlias]['alias'],
                                $this->extendedFields[$fieldAlias]['type']
                            );
                            $dataType  = $dataType['type'];
                            $secure    = 'extendedFieldSecure' === $this->extendedFields[$fieldAlias]['object'] ? '_secure' : '';
                            $tableName = MAUTIC_TABLE_PREFIX.'lead_fields_leads_'.$dataType.$secure.'_xref';
                            ++$this->count;
                            $fieldId = $this->extendedFields[$fieldAlias]['id'];

                            $this->fieldTables[$fieldAlias] = [
                                'table' => $tableName,
                                'alias' => 't'.$this->count,
                            ];
                            $this->query->leftJoin(
                                'l',
                                $tableName,
                                't'.$this->count,
                                'l.id = t'.$this->count.'.lead_id AND t'.$this->count.'.lead_field_id = '.$fieldId
                            );
                            $where = str_replace($filter['column'], 't'.$this->count.'.value', $where);
                        }
                    }
                }
            }
            $this->where = $where;
        }
    }

    /**
     * @param ReportGraphEvent $event
     */
    public function onReportGraphGenerate(ReportGraphEvent $event)
    {
        $this->fieldTables = [];
        $this->event       = $event;
        $this->query       = $event->getQueryBuilder();
        $this->convertToExtendedFieldQuery();
        $this->event->setQueryBuilder($this->query);
    }

    /**
     * Add available tables and columns to the report builder lookup.
     *
     * @param ReportBuilderEvent $event
     */
    public function onReportBuilder(ReportBuilderEvent $event)
    {
        if (!$event->checkContext([self::SEGMENT_MEMBERSHIP])) {
            return;
        }
        $columns        = $this->fieldsBuilder->getLeadFieldsColumns('l.');

        $filters = $this->fieldsBuilder->getLeadFilter('l.', 'lll.');

        $standardColumns = $event->getStandardColumns('s.', ['publish_up', 'publish_down']);

        $utmTagColumns = [
            'utm.utm_campaign' => [
                'label' => 'mautic.lead.report.utm.campaign',
                'type'  => 'text',
            ],
            'utm.utm_content' => [
                'label' => 'mautic.lead.report.utm.content',
                'type'  => 'text',
            ],
            'utm.utm_medium' => [
                'label' => 'mautic.lead.report.utm.medium',
                'type'  => 'text',
            ],
            'utm.utm_source' => [
                'label' => 'mautic.lead.report.utm.source',
                'type'  => 'text',
            ],
            'utm.utm_term' => [
                'label' => 'mautic.lead.report.utm.term',
                'type'  => 'text',
            ],
        ];

        $segmentColumns = [
            'lll.manually_removed' => [
                'label' => 'mautic.lead.report.segment.manually_removed',
                'type'  => 'bool',
            ],
            'lll.manually_added' => [
                'label' => 'mautic.lead.report.segment.manually_added',
                'type'  => 'bool',
            ],
        ];

        $data = [
            'display_name' => 'mautic.lead.report.segment.membership',
            'columns'      => array_merge($columns, $segmentColumns, $utmTagColumns, $standardColumns),
            'filters'      => $filters,
        ];
        $event->addTable(self::SEGMENT_MEMBERSHIP, $data, self::GROUP_CONTACTS);
        unset($columns, $filters, $segmentColumns, $data);
    }

    /**
     * Initialize the QueryBuilder object to generate reports from.
     *
     * @param ReportGeneratorEvent $event
     */
    public function onReportGenerate(ReportGeneratorEvent $event)
    {
        if (!$event->checkContext([self::SEGMENT_MEMBERSHIP])) {
            return;
        }

        $qb = $event->getQueryBuilder();
        $qb->leftJoin('l', MAUTIC_TABLE_PREFIX.'lead_utmtags', 'utm', 'l.id = utm.lead_id');

        $event->setQueryBuilder($qb);
    }
}
