<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Digital Media Solutions, LLC
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticExtendedFieldBundle\Event;

use Mautic\CoreBundle\Event\CommonEvent;
use MauticPlugin\MauticExtendedFieldBundle\Entity\ExtendedFieldCommon;

/**
 * Class ExtendedFieldEvent.
 */
class ExtendedFieldEvent extends CommonEvent
{
    /**
     * ExtendedFieldEvent constructor.
     *
     * @param ExtendedFieldCommon $ExtendedField
     * @param bool                $isNew
     */
    public function __construct(ExtendedFieldCommon $ExtendedField, $isNew = false)
    {
        $this->entity = $ExtendedField;
        $this->isNew  = $isNew;
    }

    /**
     * @return ExtendedFieldCommon
     */
    public function getExtendedField()
    {
        return $this->entity;
    }

    /**
     * Sets the ExtendedField entity.
     *
     * @param ExtendedFieldCommon $ExtendedField
     */
    public function setCompany(ExtendedFieldCommon $ExtendedField)
    {
        $this->entity = $ExtendedField;
    }
}
