<?php

namespace TCG\Voyager\Tests\Unit\Actions;

use TCG\Voyager\Actions\AbstractAction;
use TCG\Voyager\Facades\Voyager;
use TCG\Voyager\Models\User;
use TCG\Voyager\Tests\TestCase;

class AbstractActionTest extends TestCase
{
    protected $userDataType;
    protected $user;

    public function setUp(): void
    {
        parent::setUp();

        \TCG\Voyager\Models\Role::create(['name' => 'test_role', 'display_name' => 'Test Role']);
        $this->userDataType = Voyager::model('DataType')->where('name', 'users')->first();
        $this->user = User::factory()->create();
    }

    public function testGetRouteWithEmptyKey()
    {
        $stub = new TestAbstractAction($this->userDataType, $this->user);
        $stub->defaultRoute = true;

        $this->assertTrue($stub->getRoute($this->userDataType->name));
    }

    public function testGetRouteWithCustomKey()
    {
        $stub = new TestAbstractAction($this->userDataType, $this->user);
        $stub->customRoute = true;

        $this->assertTrue($stub->getRoute('custom'));
    }

    public function testConvertAttributesToHtml()
    {
        $stub = new TestAbstractAction($this->userDataType, $this->user);
        $stub->attributes = [
            'class' => 'class1 class2',
            'data-id' => 5,
            'id' => 'delete-5',
        ];

        $this->assertEquals('class="class1 class2" data-id="5" id="delete-5"', $stub->convertAttributesToHtml());
    }

    public function testShouldActionDisplayOnDataTypeWithDefaultDataType()
    {
        $stub = new TestAbstractAction($this->userDataType, $this->user);

        $this->assertTrue($stub->shouldActionDisplayOnDataType());
    }

    public function testTrueIsReturnedIfDataTypeMatchesTheOneWhereTheActionWasCreatedFor()
    {
        $stub = new TestAbstractAction($this->userDataType, $this->user);
        $stub->dataTypeName = $this->userDataType->name;

        $this->assertTrue($stub->shouldActionDisplayOnDataType());
    }

    public function testFalseIsReturnedIfDataTypeDoesNotMatchesTheOneWhereTheActionWasCreatedFor()
    {
        $stub = new TestAbstractAction($this->userDataType, $this->user);
        $stub->dataTypeName = 'not users';

        $this->assertFalse($stub->shouldActionDisplayOnDataType());
    }
}

class TestAbstractAction extends AbstractAction
{
    public $defaultRoute = null;
    public $customRoute = null;
    public $attributes = [];
    public $dataTypeName = null;

    public function getTitle()
    {
        return 'Test';
    }

    public function getIcon()
    {
        return 'voyager-test';
    }

    public function getPolicy()
    {
        return null;
    }

    public function getAttributes()
    {
        return $this->attributes;
    }

    public function getDefaultRoute()
    {
        return $this->defaultRoute;
    }

    public function getCustomRoute()
    {
        return $this->customRoute;
    }

    public function getDataType()
    {
        return $this->dataTypeName;
    }
}
