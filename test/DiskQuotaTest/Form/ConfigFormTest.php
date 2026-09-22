<?php
declare(strict_types=1);

namespace DiskQuotaTest\Form;

use DiskQuota\Form\ConfigForm;
use Laminas\Form\Form;
use PHPUnit\Framework\TestCase;

class ConfigFormTest extends TestCase
{
    protected $form;
    
    protected function setUp(): void
    {
        // Check if we have access to the real ConfigForm class
        if (class_exists(ConfigForm::class)) {
            $this->form = new ConfigForm();
            $this->form->init();
        } else {
            $this->markTestSkipped('The ConfigForm class is not available.');
        }
    }
    
    public function testFormHasExpectedElements(): void
    {
        $this->assertTrue($this->form->has('diskquota_default_global_quota'));
        $this->assertTrue($this->form->has('diskquota_default_site_quota'));
        $this->assertTrue($this->form->has('diskquota_default_user_quota'));
        $this->assertTrue($this->form->has('diskquota_warning_threshold'));
    }
    
    public function testFormValidatesValidData(): void
    {
        $this->form->setData([
            'diskquota_default_global_quota' => 10000,
            'diskquota_default_site_quota' => 1000,
            'diskquota_default_user_quota' => 500,
            'diskquota_warning_threshold' => 15,
        ]);
        
        $this->assertTrue($this->form->isValid());
    }
    
    public function testFormRejectsNegativeQuotas(): void
    {
        $this->form->setData([
            'diskquota_default_global_quota' => -10,
            'diskquota_default_site_quota' => 1000,
            'diskquota_default_user_quota' => 500,
            'diskquota_warning_threshold' => 15,
        ]);
        
        $this->assertFalse($this->form->isValid());
    }
    /** @dataProvider validationBoundaries */
    public function testValidationBoundaries(string $name, int $value, bool $valid): void
    {
        $data = [
            'diskquota_default_global_quota' => 10000,
            'diskquota_default_site_quota' => 1000,
            'diskquota_default_user_quota' => 500,
            'diskquota_warning_threshold' => 15,
        ];
        $data[$name] = $value;
        $this->form->setData($data);
        $this->assertSame($valid, $this->form->isValid());
    }

    public function validationBoundaries(): array
    {
        $cases = [];
        foreach (['site', 'user', 'global'] as $scope) {
            $cases[] = ['diskquota_default_' . $scope . '_quota', -1, false];
            $cases[] = ['diskquota_default_' . $scope . '_quota', 0, true];
        }
        foreach ([0 => false, 1 => true, 50 => true, 51 => false] as $value => $valid) {
            $cases[] = ['diskquota_warning_threshold', $value, $valid];
        }
        return $cases;
    }
}
