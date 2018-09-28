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

use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Entity\CompanyLead;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Event\LeadChangeCompanyEvent;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Model\CompanyModel;
use MauticPlugin\MauticExtendedFieldBundle\Entity\OverrideLeadRepository;

class OverrideCompanyModel extends CompanyModel
{
    /** Add lead to company
     * @param array|Company $companies
     * @param array|Lead    $lead
     *
     * @return bool
     *
     * @throws \Doctrine\ORM\ORMException
     */
    public function addLeadToCompany($companies, $lead)
    {
        // Primary company name to be peristed to the lead's contact company field
        $companyName        = '';
        $companyLeadAdd     = [];
        $searchForCompanies = [];

        $dateManipulated = new \DateTime();

        if (!$lead instanceof Lead) {
            $leadId = (is_array($lead) && isset($lead['id'])) ? $lead['id'] : $lead;
            $lead   = $this->em->getReference('MauticLeadBundle:Lead', $leadId);
        }

        if ($companies instanceof Company) {
            $companyLeadAdd[$companies->getId()] = $companies;
            $companies                           = [$companies->getId()];
        } elseif (!is_array($companies)) {
            $companies = [$companies];
        }

        //make sure they are ints
        foreach ($companies as $k => &$l) {
            $l = (int) $l;

            if (!isset($companyLeadAdd[$l])) {
                $searchForCompanies[] = $l;
            }
        }

        if (!empty($searchForCompanies)) {
            $companyEntities = $this->getEntities([
                'filter' => [
                    'force' => [
                        [
                            'column' => 'comp.id',
                            'expr'   => 'in',
                            'value'  => $searchForCompanies,
                        ],
                    ],
                ],
            ]);

            foreach ($companyEntities as $company) {
                $companyLeadAdd[$company->getId()] = $company;
            }
        }

        unset($companyEntities, $searchForCompanies);

        $persistCompany = [];
        $dispatchEvents = [];
        $contactAdded   = false;
        foreach ($companies as $companyId) {
            if (!isset($companyLeadAdd[$companyId])) {
                // List no longer exists in the DB so continue to the next
                continue;
            }

            $companyLead = $this->getCompanyLeadRepository()->findOneBy(
                [
                    'lead'    => $lead,
                    'company' => $companyLeadAdd[$companyId],
                ]
            );

            if (null != $companyLead) {
                // @deprecated support to be removed in 3.0
                if ($companyLead->wasManuallyRemoved()) {
                    $companyLead->setManuallyRemoved(false);
                    $companyLead->setManuallyAdded(false);
                    $contactAdded     = true;
                    $persistCompany[] = $companyLead;
                    $dispatchEvents[] = $companyId;
                    $companyName      = $companyLeadAdd[$companyId]->getName();
                } else {
                    // Detach from Doctrine
                    $this->em->detach($companyLead);

                    continue;
                }
            } else {
                $companyLead = new CompanyLead();
                $companyLead->setCompany($companyLeadAdd[$companyId]);
                $companyLead->setLead($lead);
                $companyLead->setDateAdded($dateManipulated);
                $contactAdded     = true;
                $persistCompany[] = $companyLead;
                $dispatchEvents[] = $companyId;
                $companyName      = $companyLeadAdd[$companyId]->getName();
            }
        }

        if (!empty($persistCompany)) {
            $this->getCompanyLeadRepository()->saveEntities($persistCompany);
        }

        if (!empty($companyName)) {
            $currentCompanyName = $lead->getCompany();
            if ($currentCompanyName !== $companyName) {
                $lead->addUpdatedField('company', $companyName)
                    ->setDateModified(new \DateTime());
                // $this->em->getRepository('MauticLeadBundle:Lead')->saveEntity($lead);
                $metastart = new ClassMetadata(Lead::class);
                $repo      = new OverrideLeadRepository($this->em, $metastart, $this->leadFieldModel);
                $repo->saveEntity($lead);
            }
        }

        if (!empty($dispatchEvents) && ($this->dispatcher->hasListeners(LeadEvents::LEAD_COMPANY_CHANGE))) {
            foreach ($dispatchEvents as $companyId) {
                $event = new LeadChangeCompanyEvent($lead, $companyLeadAdd[$companyId]);
                $this->dispatcher->dispatch(LeadEvents::LEAD_COMPANY_CHANGE, $event);

                unset($event);
            }
        }

        // Clear CompanyLead entities from Doctrine memory
        $this->em->clear(CompanyLead::class);

        return $contactAdded;
    }
}
