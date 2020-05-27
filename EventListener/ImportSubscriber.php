<?php
/**
 * Created by PhpStorm.
 * User: nbush
 * Date: 1/4/19
 * Time: 3:36 PM.
 */

namespace MauticPlugin\MauticExtendedFieldBundle\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\MauticExtendedFieldBundle\Entity\ExtendedFieldBoolean;
use MauticPlugin\MauticExtendedFieldBundle\Entity\ExtendedFieldBooleanSecure;
use MauticPlugin\MauticExtendedFieldBundle\Entity\ExtendedFieldDate;
use MauticPlugin\MauticExtendedFieldBundle\Entity\ExtendedFieldDateSecure;
use MauticPlugin\MauticExtendedFieldBundle\Entity\ExtendedFieldDatetime;
use MauticPlugin\MauticExtendedFieldBundle\Entity\ExtendedFieldDatetimeSecure;
use MauticPlugin\MauticExtendedFieldBundle\Entity\ExtendedFieldFloat;
use MauticPlugin\MauticExtendedFieldBundle\Entity\ExtendedFieldFloatSecure;
use MauticPlugin\MauticExtendedFieldBundle\Entity\ExtendedFieldString;
use MauticPlugin\MauticExtendedFieldBundle\Entity\ExtendedFieldStringSecure;
use MauticPlugin\MauticExtendedFieldBundle\Entity\ExtendedFieldText;
use MauticPlugin\MauticExtendedFieldBundle\Entity\ExtendedFieldTextSecure;
use MauticPlugin\MauticExtendedFieldBundle\Entity\ExtendedFieldTime;
use MauticPlugin\MauticExtendedFieldBundle\Entity\ExtendedFieldTimeSecure;
use Doctrine\ORM\EntityManager;

class ImportSubscriber implements EventSubscriberInterface
{

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $em;

    /**
     * @param EntityManager $em
     */
    public function __construct(EntityManager $em) {
        $this->em = $em;
    }

    public static function getSubscribedEvents()
    {
        return [
            LeadEvents::IMPORT_BATCH_PROCESSED => 'clearExtendedFieldEntities',
        ];
    }

    public function clearExtendedFieldEntities()
    {
        $this->em->clear(ExtendedFieldBoolean::class);
        $this->em->clear(ExtendedFieldBooleanSecure::class);
        $this->em->clear(ExtendedFieldDate::class);
        $this->em->clear(ExtendedFieldDateSecure::class);
        $this->em->clear(ExtendedFieldDatetime::class);
        $this->em->clear(ExtendedFieldDatetimeSecure::class);
        $this->em->clear(ExtendedFieldFloat::class);
        $this->em->clear(ExtendedFieldFloatSecure::class);
        $this->em->clear(ExtendedFieldString::class);
        $this->em->clear(ExtendedFieldStringSecure::class);
        $this->em->clear(ExtendedFieldText::class);
        $this->em->clear(ExtendedFieldTextSecure::class);
        $this->em->clear(ExtendedFieldTime::class);
        $this->em->clear(ExtendedFieldTimeSecure::class);
    }
}
