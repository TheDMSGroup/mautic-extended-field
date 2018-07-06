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
use Mautic\LeadBundle\Form\Type\LeadType;
use Mautic\LeadBundle\Model\CompanyModel;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

/**
 * Class LeadTypeExtension.
 */
class LeadTypeExtension extends AbstractTypeExtension
{
    use EntityFieldsBuildFormTrait {
        getFormFields as getFormFieldsExtended;
    }

    /** @var \Mautic\CoreBundle\Translation\Translator */
    private $translator;

    /** @var MauticFactory */
    private $factory;

    /** @var CompanyModel */
    private $companyModel;

    /**
     * @param MauticFactory $factory
     */
    public function __construct(MauticFactory $factory, CompanyModel $companyModel)
    {
        $this->translator   = $factory->getTranslator();
        $this->factory      = $factory;
        $this->companyModel = $companyModel;
    }

    /**
     * Returns the name of the type being extended.
     *
     * @return string The name of the type being extended
     */
    public function getExtendedType()
    {
        // use FormType::class to modify (nearly) every field in the system
        return LeadType::class;
    }

    /**
     * Appends LeadType::buildForm().
     *
     * Alterations to core:
     *  Appends extended objects to the form.
     *
     * @param FormBuilderInterface $builder
     * @param array                $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $this->getFormFieldsExtended($builder, $options, 'extendedField');
        $this->getFormFieldsExtended($builder, $options, 'extendedFieldSecure');
    }

    /**
     * @param OptionsResolverInterface $resolver
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(
            [
                'data_class'  => 'Mautic\LeadBundle\Entity\Lead',
                'isShortForm' => false,
            ]
        );

        $resolver->setRequired(['fields', 'isShortForm']);
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'lead';
    }
}
