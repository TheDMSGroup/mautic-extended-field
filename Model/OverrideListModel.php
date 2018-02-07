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
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\ListModel as ListModel;
use MauticPlugin\MauticExtendedFieldBundle\Entity\OverrideLeadListRepository as OverrideLeadListRepository;


/**
 * Class OverrideListModel
 * {@inheritdoc}
 */
class OverrideListModel extends ListModel
{


  /**
   * {@inheritdoc}
   *
   * @return \Mautic\LeadBundle\Entity\OverrideLeadListRepository
   *
   * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
   * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
   */
  public function getRepository()
  {
    /** @var \Mautic\LeadBundle\Entity\LeadListRepository $repo */
    $metastart = new ClassMetadata(LeadList::class);
    $repo = new OverrideLeadListRepository($this->em, $metastart);

    $repo->setDispatcher($this->dispatcher);
    $repo->setTranslator($this->translator);

    return $repo;
  }



}
