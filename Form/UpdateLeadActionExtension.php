<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Digital Media Solutions, LLC
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticExtendedFieldBundle\Form;

use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\LeadBundle\Form\Type\EntityFieldsBuildFormTrait;
use Mautic\LeadBundle\Form\Type\UpdateLeadActionType;
use MauticPlugin\MauticExtendedFieldBundle\Model\ExtendedFieldModel;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Class UpdateLeadActionExtension.
 */
class UpdateLeadActionExtension extends AbstractTypeExtension
{
    use EntityFieldsBuildFormTrait {
        getFormFields as getFormFieldsExtended;
    }

    /** @var MauticFactory */
    private $factory;

    /**
     * @param MauticFactory $factory
     */
    public function __construct(MauticFactory $factory)
    {
        $this->factory = $factory;
    }

    /**
     * Returns the name of the type being extended.
     *
     * @return string The name of the type being extended
     */
    public function getExtendedType()
    {
        // use FormType::class to modify (nearly) every field in the system
        return UpdateLeadActionType::class;
    }

    /**
     * Appends UpdateLeadActionType:buildForm().
     *
     * Alterations to core:
     *  Appends extended objects to the form.
     *
     * @param FormBuilderInterface $builder
     * @param array                $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        /** @var ExtendedFieldModel $extendedFieldModel */
        $extendedFieldModel =  $this->factory->getModel('lead.field');
        $leadFields         = $extendedFieldModel->getEntities(
            [
                'force' => [
                    [
                        'column' => 'f.isPublished',
                        'expr'   => 'eq',
                        'value'  => true,
                    ],
                ],
                'hydration_mode' => 'HYDRATE_ARRAY',
            ]
        );

        $options['fields']                      = $leadFields;
        $options['ignore_required_constraints'] = true;

        $this->getFormFields($builder, $options, 'extendedField');
        $this->getFormFields($builder, $options, 'extendedFieldSecure');
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'updatelead_action';
    }
}
