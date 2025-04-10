<?php
declare(strict_types=1);

namespace DiskQuota\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class ConfigForm extends Form
{
    /**
     * Initialize the form elements.
     */
    public function init(): void
    {

        // Default global quota (in MB)
        $this->add([
            'name' => 'diskquota_default_global_quota',
            'type' => Element\Number::class,
            'options' => [
                'label' => 'Default global quota (MB)', // @translate
                'info' => 'Global quota limit (MB) for all users.'
                    . ' Set to 0 to allow unlimited usage.', // @translate
            ],
            'attributes' => [
                'required' => true,
                'min' => 0,
                'step' => 1,
                'value' => 10000, // Default 10GB
            ],
        ]);
        
        // Default quota per site (in MB)
        $this->add([
            'name' => 'diskquota_default_site_quota',
            'type' => Element\Number::class,
            'options' => [
                'label' => 'Default quota per site (MB)', // @translate
                'info' => 'Default disk quota for each site in megabytes. Set to 0 for unlimited.', // @translate
            ],
            'attributes' => [
                'required' => true,
                'min' => 0,
                'step' => 1,
                'value' => 1000, // Default 1GB
            ],
        ]);
        
        // Default quota per user (in MB)
        $this->add([
            'name' => 'diskquota_default_user_quota',
            'type' => Element\Number::class,
            'options' => [
                'label' => 'Default quota per user (MB)', // @translate
                'info' => 'Default disk quota for each user in megabytes. Set to 0 for unlimited.', // @translate
            ],
            'attributes' => [
                'required' => true,
                'min' => 0,
                'step' => 1,
                'value' => 500, // Default 500MB
            ],
        ]);
        

        // Warning threshold percentage
        $this->add([
            'name' => 'diskquota_warning_threshold',
            'type' => Element\Number::class,
            'options' => [
                'label' => 'Warning threshold (%)', // @translate
                'info' => 'Warn when this percentage of quota is reached.'
                    . ' E.g., 15 = warn at 85% usage.', // @translate
            ],
            'attributes' => [
                'required' => true,
                'min' => 1,
                'max' => 50,
                'step' => 1,
                'value' => 15, // Default 15%
            ],
        ]);

        
        // Add input filters for validation
        $inputFilter = $this->getInputFilter();
        
        $inputFilter->add([
            'name' => 'diskquota_default_site_quota',
            'required' => true,
            'filters' => [
                ['name' => 'ToInt'],
            ],
            'validators' => [
                [
                    'name' => 'GreaterThan',
                    'options' => [
                        'min' => -1,
                        'inclusive' => false,
                    ],
                ],
            ],
        ]);
        
        $inputFilter->add([
            'name' => 'diskquota_default_user_quota',
            'required' => true,
            'filters' => [
                ['name' => 'ToInt'],
            ],
            'validators' => [
                [
                    'name' => 'GreaterThan',
                    'options' => [
                        'min' => -1,
                        'inclusive' => false,
                    ],
                ],
            ],
        ]);
        
        $inputFilter->add([
            'name' => 'diskquota_default_global_quota',
            'required' => true,
            'filters' => [
                ['name' => 'ToInt'],
            ],
            'validators' => [
                [
                    'name' => 'GreaterThan',
                    'options' => [
                        'min' => -1,
                        'inclusive' => false,
                    ],
                ],
            ],
        ]);
        
        $inputFilter->add([
            'name' => 'diskquota_warning_threshold',
            'required' => true,
            'filters' => [
                ['name' => 'ToInt'],
            ],
            'validators' => [
                [
                    'name' => 'GreaterThan',
                    'options' => [
                        'min' => 0,
                        'inclusive' => false,
                    ],
                ],
                [
                    'name' => 'LessThan',
                    'options' => [
                        'max' => 51,
                        'inclusive' => false,
                    ],
                ],
            ],
        ]);
    }
}
