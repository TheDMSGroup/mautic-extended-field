<?php

namespace MauticPlugin\MauticExtendedFieldBundle\Tracker;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\IpLookupHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Tracker\ContactTracker;
use Mautic\LeadBundle\Tracker\DeviceTracker;
use Mautic\LeadBundle\Tracker\Service\ContactTrackingService\ContactTrackingServiceInterface;
use Monolog\Logger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class OverrideContactTracker extends ContactTracker
{
    /**
     * @var LeadRepository
     */
    private $leadRepository;

    /**
     * OverrideContactTracker constructor.
     * @param LeadRepository $leadRepository
     * @param ContactTrackingServiceInterface $contactTrackingService
     * @param DeviceTracker $deviceTracker
     * @param CorePermissions $security
     * @param Logger $logger
     * @param IpLookupHelper $ipLookupHelper
     * @param RequestStack $requestStack
     * @param CoreParametersHelper $coreParametersHelper
     * @param EventDispatcherInterface $dispatcher
     * @param FieldModel $leadFieldModel
     */
    public function __construct(LeadRepository $leadRepository, ContactTrackingServiceInterface $contactTrackingService, DeviceTracker $deviceTracker, CorePermissions $security, Logger $logger, IpLookupHelper $ipLookupHelper, RequestStack $requestStack, CoreParametersHelper $coreParametersHelper, EventDispatcherInterface $dispatcher, FieldModel $leadFieldModel) {
        parent::__construct($leadRepository, $contactTrackingService, $deviceTracker, $security, $logger, $ipLookupHelper, $requestStack, $coreParametersHelper, $dispatcher, $leadFieldModel);
        $this->leadRepository = $leadRepository;
    }

    /**
     * @param Lead $lead
     */
    private function hydrateCustomFieldData(Lead $lead = null)
    {
        if (null === $lead) {
            return;
        }

        // Hydrate fields with custom field data
        $fields = $this->leadRepository->getExtendedFieldValues($lead->getId());
        $lead->setFields($fields);
    }

}