<?php

namespace MauticPlugin\MauticExtendedFieldBundle\EventListener;

use Mautic\ConfigBundle\ConfigEvents;
use Mautic\ConfigBundle\Event\ConfigBuilderEvent;
use Mautic\CoreBundle\EventListener\CommonSubscriber;
use Mautic\ReportBundle\Event\ReportQueryEvent;
use Mautic\ReportBundle\ReportEvents;
use Mautic\ReportBundle\Event\ReportGraphEvent;

/**
 * Class ConfigSubscriber.
 */
class ConfigSubscriber extends CommonSubscriber
{

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            ConfigEvents::CONFIG_ON_GENERATE       => ['onConfigGenerate', 0],
            ReportEvents::REPORT_QUERY_PRE_EXECUTE => ['onReportQueryPreExecute'],
            ReportEvents::REPORT_ON_GRAPH_GENERATE => ['onReportGraphGenerate', 20],
        ];
    }

    /**
     * @param ConfigBuilderEvent $event
     */
    public function onConfigGenerate(ConfigBuilderEvent $event)
    {
        $params = !empty(
        $event->getParametersFromConfig(
            'MauticExtendedFieldBundle'
        )
        ) ? $event->getParametersFromConfig('MauticExtendedFieldBundle') : [];
        $event->addForm(
            [
                'bundle'     => 'MauticExtendedFieldBundle',
                'formAlias'  => 'extendedField_config',
                'formTheme'  => 'MauticExtendedFieldBundle:Config',
                'parameters' => $params,
            ]
        );
    }


    /**
     * @param ConfigBuilderEvent $event
     */
    public function onReportQueryPreExecute(ReportQueryEvent $event)
    {
        $this->event = $event;
        $this->query = $event->getQuery();
        $this->convertToExtendedFieldQuery();
        $this->event->setQuery($this->query);
        $event = $this->event;
    }

    /**
     * @param ConfigBuilderEvent $event
     */
    public function onReportGraphGenerate(ReportGraphEvent $event)
    {
        $this->event = $event;
        $this->query = $event->getQueryBuilder();
        $this->convertToExtendedFieldQuery();
        $this->event->setQueryBuilder($this->query);
        $event = $this->event;
    }

    /**
     * helper method to convert queries with extendedField optins
     * in select, orderBy and GroupBy to work with the
     * extendedField schema
     */
    private function convertToExtendedFieldQuery()
    {
        $this->fieldModel = $this->dispatcher->getContainer()->get('mautic.lead.model.field');
        $this->leadModel  = $this->dispatcher->getContainer()->get('mautic.lead.model.lead');

        $this->selectParts    = $this->query->getQueryPart('select');
        $this->orderByParts   = $this->query->getQueryPart('orderBy');
        $this->groupByParts   = $this->query->getQueryPart('groupBy');
        $this->filters        = $this->event->getReport()->getFilters();
        $this->where          = $this->query->getQueryPart('where');
        $args                 = ['keys' => 'alias'];
        $this->extendedFields = $this->leadModel->getExtendedEntities($args);
        $this->fieldTables    = isset($this->fieldTables) ? $this->fieldTables : [];
        $this->count          = 0;

        $this->alterSelect();
        if ($this->event instanceof ReportQueryEvent) {$this->alterOrderBy();}
        $this->alterGroupBy();
        $this->alterWhere();

        $this->query->select($this->selectParts);
        if ($this->event instanceof ReportQueryEvent && !empty($this->orderByParts))  {
            $orderBy = implode(',', $this->orderByParts);
            $this->query->add('orderBy', $orderBy);
        }
        if(!empty($this->groupByParts)) {$this->query->groupBy($this->groupByParts);}
        $this->query->where($this->where);

    }

    /**
     * @return mixed
     */
    private function alterSelect()
    {

        foreach ($this->selectParts as $key => $selectPart) {
            if (strpos($selectPart, 'l.') === 0) {
                // field from the lead table, so check if its an extended field
                $partStrings = (explode(' AS ', $selectPart));
                if ($this->event instanceof ReportQueryEvent) {
                    $fieldAlias = $this->event->getOptions()['columns'][$partStrings[0]]['alias'];
                } else {
                    $fieldAlias = $partStrings[1];
                }

                if (isset($this->extendedFields[$fieldAlias]) && $this->extendedFields[$fieldAlias]['object'] != 'lead') {
                    // is extended field, so rewrite the SQL part.
                    $dataType  = $this->fieldModel->getSchemaDefinition(
                        $this->extendedFields[$fieldAlias]['alias'],
                        $this->extendedFields[$fieldAlias]['type']
                    );
                    $dataType  = $dataType['type'];
                    $secure    = 'extendedFieldSecure' == $this->extendedFields[$fieldAlias]['object'] ? '_secure' : '';
                    $tableName = 'lead_fields_leads_'.$dataType.$secure.'_xref';
                    $this->count++;
                    $fieldId                 = $this->extendedFields[$fieldAlias]['id'];

                    if (array_key_exists($fieldAlias, $this->fieldTables)) {
                        $this->selectParts[$key] = $this->fieldTables[$fieldAlias]['alias'].'.value AS '.$fieldAlias;
                    } else {

                        $this->selectParts[$key] = "t$this->count.value AS $fieldAlias";

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
                    }

                }
            }
        }
    }

    /**
     * @return mixed
     */
    private function alterOrderBy()
    {
        foreach ($this->orderByParts as $key => $orderByPart) {
            if (strpos($orderByPart, 'l.') === 0) {
                // field from the lead table, so check if its an extended field
                $partStrings = (explode(' ', $orderByPart));
                $fieldAlias = substr($partStrings[0], 2);

                if (isset($this->extendedFields[$fieldAlias]) && $this->extendedFields[$fieldAlias]['object'] != 'lead') {
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
                        $secure    = 'extendedFieldSecure' == $this->extendedFields[$fieldAlias]['object'] ? '_secure' : '';
                        $tableName = 'lead_fields_leads_'.$dataType.$secure.'_xref';
                        $this->count++;
                        $fieldId             = $this->extendedFields[$fieldAlias]['id'];

                        $this->fieldTables[$fieldAlias] = [
                            'table' => $tableName,
                            'alias' => 't'.$this->count,
                        ];
                        $this->query->leftJoin(
                            'l',
                            $tableName,
                            't'.$this->count,
                            'l.id = t'.$this->count.'.lead_id AND t'.$this->count.'.lead_field_id = '.$this->extendedFields[$fieldAlias]['id']
                        );
                        $this->orderByParts[$key] = 't'.$this->count.'.value';
                    }
                }
            }
        }
    }

    /**
     * @return mixed
     */
    private function alterGroupBy()
    {
        foreach ($this->groupByParts as $key => $groupByPart) {
            if (strpos($groupByPart, 'l.') === 0) {
                // field from the lead table, so check if its an extended
                $fieldAlias = substr($groupByPart, 2);
                if (isset($this->extendedFields[$fieldAlias]) && $this->extendedFields[$fieldAlias]['object'] != 'lead') {
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
                        $secure    = 'extendedFieldSecure' == $this->extendedFields[$fieldAlias]['object'] ? '_secure' : '';
                        $tableName = 'lead_fields_leads_'.$dataType.$secure.'_xref';
                        $this->count++;
                        $fieldId                  = $this->extendedFields[$fieldAlias]['id'];

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
        $where = $this->where->__toString();
        foreach($this->filters as $filter)
        {
            if (strpos($filter['column'], 'l.') === 0) {
                // field from the lead table, so check if its an extended
                $fieldAlias = substr($filter['column'], 2);
                if (isset($this->extendedFields[$fieldAlias]) && $this->extendedFields[$fieldAlias]['object'] != 'lead') {
                    // is extended field, so rewrite the SQL part.
                    if (array_key_exists($fieldAlias, $this->fieldTables)) {
                        // set using the existing table alias from the altered select statement
                        $where = str_replace($filter['column'], $this->fieldTables[$fieldAlias]['alias'].'.value', $where);

                    } else {
                        // field hasnt been identified yet so generate unique alias and table
                        $dataType  = $this->fieldModel->getSchemaDefinition(
                            $this->extendedFields[$fieldAlias]['alias'],
                            $this->extendedFields[$fieldAlias]['type']
                        );
                        $dataType  = $dataType['type'];
                        $secure    = 'extendedFieldSecure' == $this->extendedFields[$fieldAlias]['object'] ? '_secure' : '';
                        $tableName = 'lead_fields_leads_'.$dataType.$secure.'_xref';
                        $this->count++;
                        $fieldId                  = $this->extendedFields[$fieldAlias]['id'];

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
