<?php

namespace TCG\Voyager\Database;

use TCG\Voyager\Database\Schema\SchemaManager;
use TCG\Voyager\Database\Schema\Table;
use TCG\Voyager\Database\Types\Type;

class DatabaseUpdater
{
    protected $tableArr;
    protected $table;
    protected $originalTable;

    public function __construct(array $tableArr)
    {
        Type::registerCustomPlatformTypes();

        $this->table = Table::make($tableArr);
        $this->tableArr = $tableArr;
        $this->originalTable = SchemaManager::listTableDetails($tableArr['oldName']);
    }

    /**
     * Update the table.
     *
     * @return void
     */
    public static function update($table)
    {
        if (!is_array($table)) {
            $table = json_decode($table, true);
        }

        if (!SchemaManager::tableExists($table['oldName'])) {
            throw static::tableDoesNotExist($table['oldName']);
        }

        $updater = new self($table);

        $updater->updateTable();
    }

    /**
     * Updates the table.
     *
     * @return void
     */
    public function updateTable()
    {
        $originalName = $this->originalTable->getName();
        $newName = $this->table->getName();

        if ($newName != $originalName) {
            if (SchemaManager::tableExists($newName)) {
                throw static::tableAlreadyExists($newName);
            }
        }

        $comparisonTableArr = $this->tableArr;

        if ($newName != $originalName) {
            $comparisonTableArr['name'] = $originalName;
        }

        $comparisonTable = Table::make($comparisonTableArr);
        $tableDiff = $this->originalTable->diff($comparisonTable);

        if ($tableDiff && !$tableDiff->isEmpty()) {
            SchemaManager::alterTable($tableDiff);
        }

        if ($newName != $originalName) {
            SchemaManager::renameTable($originalName, $newName);
        }
    }

    /**
     * Get columns that were renamed.
     *
     * @return array
     */
    protected function getRenamedColumns()
    {
        $renamedColumns = [];

        foreach ($this->tableArr['columns'] as $column) {
            $oldName = $column['oldName'];

            // make sure this is an existing column and not a new one
            if ($this->originalTable->hasColumn($oldName)) {
                $name = $column['name'];

                if ($name != $oldName) {
                    $renamedColumns[$oldName] = $name;
                }
            }
        }

        return $renamedColumns;
    }

    /**
     * Get indexes that were renamed.
     *
     * @return array
     */
    protected function getRenamedIndexes()
    {
        $renamedIndexes = [];

        foreach ($this->tableArr['indexes'] as $index) {
            $oldName = $index['oldName'];

            // make sure this is an existing index and not a new one
            if ($this->originalTable->hasIndex($oldName)) {
                $name = $index['name'];

                if ($name != $oldName) {
                    $renamedIndexes[$oldName] = $name;
                }
            }
        }

        return $renamedIndexes;
    }

    protected static function tableDoesNotExist($table)
    {
        if (class_exists('Doctrine\\DBAL\\Schema\\SchemaException')
            && method_exists('Doctrine\\DBAL\\Schema\\SchemaException', 'tableDoesNotExist')) {
            return \Doctrine\DBAL\Schema\SchemaException::tableDoesNotExist($table);
        }

        return new \RuntimeException("Table {$table} does not exist");
    }

    protected static function tableAlreadyExists($table)
    {
        if (class_exists('Doctrine\\DBAL\\Schema\\SchemaException')
            && method_exists('Doctrine\\DBAL\\Schema\\SchemaException', 'tableAlreadyExists')) {
            return \Doctrine\DBAL\Schema\SchemaException::tableAlreadyExists($table);
        }

        return new \RuntimeException("Table {$table} already exists");
    }
}
