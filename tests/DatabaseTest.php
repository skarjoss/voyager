<?php

namespace TCG\Voyager\Tests;

use Illuminate\Support\Facades\Auth;
use TCG\Voyager\Database\DatabaseUpdater;
use TCG\Voyager\Database\Schema\SchemaManager;
use TCG\Voyager\Database\Schema\Table;
use TCG\Voyager\Database\Types\Type;
use TCG\Voyager\Traits\AlertsMessages;

class DatabaseTest extends TestCase
{
    use AlertsMessages;

    protected array $table;

    public function setUp(): void
    {
        parent::setUp();

        Type::registerCustomPlatformTypes(true);
        Auth::loginUsingId(1);

        $this->table = [
            'name' => 'test_table_new',
            'oldName' => 'test_table_new',
            'columns' => [
                [
                    'name' => 'id',
                    'oldName' => 'id',
                    'autoincrement' => true,
                    'type' => [
                        'name' => 'integer',
                    ],
                ],
                [
                    'name' => 'details',
                    'oldName' => 'details',
                    'notnull' => true,
                    'type' => [
                        'name' => 'json',
                    ],
                ],
            ],
            'indexes' => [[
                'name' => 'primary',
                'columns' => ['id'],
                'type' => 'PRIMARY',
                'isPrimary' => true,
                'isUnique' => true,
            ]],
            'foreignKeys' => [],
            'options' => [],
        ];

        $this->post(route('voyager.database.store'), [
            'table' => json_encode($this->table),
        ]);
    }

    public function tearDown(): void
    {
        if (isset($this->table['name']) && SchemaManager::tableExists($this->table['name'])) {
            SchemaManager::dropTable($this->table['name']);
        }

        if (isset($this->table['oldName'])
            && $this->table['oldName'] !== $this->table['name']
            && SchemaManager::tableExists($this->table['oldName'])) {
            SchemaManager::dropTable($this->table['oldName']);
        }

        parent::tearDown();
    }

    public function test_table_created_successfully()
    {
        $this->assertSessionHasAll($this->alertSuccess(__('voyager::database.success_create_table', ['table' => $this->table['name']])));
        $this->assertRedirectedToRoute('voyager.database.index');
        $this->assertTrue(SchemaManager::tableExists($this->table['name']));

        $dbTable = SchemaManager::listTableDetails($this->table['name']);
        $id = $dbTable->getColumn('id');
        $details = $dbTable->getColumn('details');

        $this->assertEquals('integer', Type::resolveName($id->getType()));
        $this->assertEquals('json', Type::resolveName($details->getType()));
        $this->assertTrue($id->getAutoincrement());
        $this->assertTrue($details->getNotnull());

        $primary = $dbTable->getPrimaryKey();
        $this->assertNotNull($primary);
        $this->assertEquals('primary', strtolower($primary->getName()));

        $this->post(route('voyager.database.store'), [
            'table' => json_encode($this->table),
        ]);

        $this->assertTrue(SchemaManager::tableExists($this->table['name']));
        $dbTable = SchemaManager::listTableDetails($this->table['name']);
        $this->assertTrue($dbTable->hasColumn('id'));
        $this->assertTrue($dbTable->hasColumn('details'));
    }

    public function test_can_update_table()
    {
        $this->update_table_that_not_exist();
        $this->can_add_column();
        $this->can_change_column_type();
        $this->can_change_column_options();
        $this->can_add_index();
        $this->can_rename_column();
        $this->can_drop_column();
        $this->can_rename_table();
    }

    public function test_can_drop_table()
    {
        $this->assertTrue(SchemaManager::tableExists($this->table['name']));

        $this->delete(route('voyager.database.destroy', $this->table['name']));

        $this->assertSessionHasAll($this->alertSuccess(__('voyager::database.success_delete_table', ['table' => $this->table['name']])));
        $this->assertRedirectedToRoute('voyager.database.index');
        $this->assertFalse(SchemaManager::tableExists($this->table['name']));
    }

    protected function update_table_that_not_exist(): void
    {
        $table = (new Table('i_dont_exist_please_create_me_first'))->toArray();

        try {
            DatabaseUpdater::update($table);
            $this->fail('Expected missing table update to throw an exception.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('does not exist', $e->getMessage());
        }

        $this->assertFalse(SchemaManager::tableExists($table['name']));
        $this->assertTrue(SchemaManager::tableExists($this->table['name']));
    }

    protected function can_rename_table(): void
    {
        $this->table['name'] = 'table_new_name_test';

        $this->update_table($this->table);

        $this->assertFalse(SchemaManager::tableExists($this->table['oldName']));
        $this->assertTrue(SchemaManager::tableExists($this->table['name']));
    }

    protected function can_add_column(): void
    {
        $dbTable = SchemaManager::listTableDetails($this->table['name']);
        $column = 'new_voyager_column';

        $dbTable->addColumn($column, 'text', [
            'notnull' => false,
        ]);

        $dbTable = $this->update_table($dbTable->toArray());

        $this->assertTrue($dbTable->hasColumn($column));
        $this->assertEquals('text', Type::resolveName($dbTable->getColumn($column)->getType()));
    }

    protected function can_rename_column(): void
    {
        $column = 1;
        $oldColumn = $this->table['columns'][$column]['oldName'];
        $newColumn = 'details_renamed_test';
        $this->table['columns'][$column]['name'] = $newColumn;

        foreach ($this->table['indexes'] as &$index) {
            if (!isset($index['columns']) || !is_array($index['columns'])) {
                continue;
            }

            foreach ($index['columns'] as &$indexedColumn) {
                if ($indexedColumn === $oldColumn) {
                    $indexedColumn = $newColumn;
                }
            }
            unset($indexedColumn);
        }
        unset($index);

        $dbTable = $this->update_table($this->table);

        $this->assertFalse($dbTable->hasColumn($oldColumn));
        $this->assertTrue($dbTable->hasColumn($newColumn));
    }

    protected function can_change_column_type(): void
    {
        $column = 1;
        $columnName = $this->table['columns'][$column]['name'];
        $newType = 'text';
        $oldType = $this->table['columns'][$column]['type']['name'];

        $this->assertNotEquals($oldType, $newType);

        $this->table['columns'][$column]['type']['name'] = $newType;

        $dbTable = $this->update_table($this->table);

        $this->assertEquals($newType, Type::resolveName($dbTable->getColumn($columnName)->getType()));
    }

    protected function can_change_column_options(): void
    {
        $column = 1;
        $columnName = $this->table['columns'][$column]['name'];

        $notnull = false;
        $default = 'voyager admin';

        $this->table['columns'][$column]['notnull'] = $notnull;
        $this->table['columns'][$column]['default'] = $default;

        $dbTable = $this->update_table($this->table);
        $column = $dbTable->getColumn($columnName);

        $this->assertEquals($notnull, $column->getNotnull());
        $this->assertEquals($default, trim((string) $column->getDefault(), "'\"") );
    }

    protected function can_drop_column(): void
    {
        $column = 1;
        $columnName = $this->table['columns'][$column]['name'];

        $dbTable = SchemaManager::listTableDetails($this->table['name']);
        $this->assertTrue($dbTable->hasColumn($columnName));

        unset($this->table['columns'][$column]);
        $this->table['indexes'] = array_values(array_filter($this->table['indexes'], function ($index) use ($columnName) {
            return !isset($index['columns']) || !in_array($columnName, $index['columns'], true);
        }));

        $dbTable = $this->update_table($this->table);

        $this->assertFalse($dbTable->hasColumn($columnName));
    }

    protected function can_add_index(): void
    {
        $dbTable = SchemaManager::listTableDetails($this->table['name']);
        $indexName = 'details_unique';

        $dbTable->addUniqueIndex(['details'], $indexName);

        $dbTable = $this->update_table($dbTable->toArray());

        $this->assertTrue($dbTable->hasIndex($indexName));
        $this->assertTrue($dbTable->getIndex($indexName)->isUnique());
    }

    protected function update_table(array $table): Table
    {
        DatabaseUpdater::update($table);

        $this->table = $table;

        return SchemaManager::listTableDetails($table['name']);
    }
}
