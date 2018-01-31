<?php

/*
 * @copyright   2016 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\ExtendedFieldBundle\Event;

use Mautic\CoreBundle\Event\CommonEvent;
use MauticPlugin\ExtendedFieldBundle\Entity\ExtendedFieldCommon;

/**
 * Class ExtendedFieldEvent.
 */
class ExtendedFieldEvent extends CommonEvent
{

    /**
     * @param ExtendedField $ExtendedField
     * @param bool    $isNew
     * @param int     $score
     */
    public function __construct(ExtendedField $ExtendedField, $isNew = false)
    {
        $this->entity = $ExtendedField;
        $this->isNew  = $isNew;
    }

    /**
     * Returns the ExtendedField entity.
     *
     * @return ExtendedField
     */
    public function getExtendedField()
    {
        return $this->entity;
    }

    /**
     * Sets the ExtendedField entity.
     *
     * @param ExtendedField $ExtendedField
     */
    public function setCompany(ExtendedField $ExtendedField)
    {
        $this->entity = $ExtendedField;
    }

}
