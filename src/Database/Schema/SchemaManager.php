<?php

namespace TCG\Voyager\Database\Schema;

use Doctrine\DBAL\Connection as DoctrineConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Table as DoctrineTable;
use Doctrine\DBAL\Schema\TableDiff;
use Illuminate\Database\Connection as LaravelConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use TCG\Voyager\Database\Types\Type;

abstract class SchemaManager
{
    protected static $doctrineConnections = [];

    public static function __callStatic($method, $args)
    {
        return static::manager()->$method(...$args);
    }

    public static function manager()
    {
        return DB::connection();
    }

    public static function getDatabaseConnection()
    {
        return DB::connection();
    }

    public static function getDoctrineConnection()
    {
        $connection = static::getDatabaseConnection();
        $pdo = $connection->getPdo();
        $name = $connection->getName().':'.spl_object_id($pdo);

        if (isset(static::$doctrineConnections[$name])) {
            return static::$doctrineConnections[$name];
        }

        if (method_exists($connection, 'getDoctrineConnection')) {
            return static::$doctrineConnections[$name] = $connection->getDoctrineConnection();
        }

        $params = static::getDoctrineConnectionParams($connection, $pdo);

        return static::$doctrineConnections[$name] = DriverManager::getConnection($params);
    }

    public static function getDoctrineSchemaManager()
    {
        $connection = static::getDoctrineConnection();

        if (method_exists($connection, 'createSchemaManager')) {
            return $connection->createSchemaManager();
        }

        if (method_exists($connection, 'getSchemaManager')) {
            return $connection->getSchemaManager();
        }

        throw new \RuntimeException('Doctrine schema manager is unavailable for the current database connection.');
    }

    public static function createComparator()
    {
        $reflection = new ReflectionClass(Comparator::class);
        $constructor = $reflection->getConstructor();

        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            return new Comparator(static::getDoctrineConnection()->getDatabasePlatform());
        }

        return new Comparator();
    }

    public static function getDoctrineTable($tableName)
    {
        $schemaManager = static::getDoctrineSchemaManager();

        try {
            return $schemaManager->introspectTable($tableName);
        } catch (\Throwable $e) {
            foreach ($schemaManager->listTables() as $table) {
                if (static::normalizeTableName($table->getName()) === static::normalizeTableName($tableName)) {
                    return $table;
                }
            }

            throw $e;
        }
    }

    public static function tableExists($table)
    {
        if (!is_array($table)) {
            $table = [$table];
        }

        return Schema::hasTable($table[0]);
    }

    public static function listTables()
    {
        $tables = [];

        foreach (static::listTableNames() as $tableName) {
            $tables[$tableName] = static::listTableDetails($tableName);
        }

        return $tables;
    }

    public static function listTableDetails($tableName)
    {
        if (!static::tableExists($tableName)) {
            return new Table($tableName, [], [], [], [], []);
        }

        $schemaBuilder = Schema::getConnection()->getSchemaBuilder();

        if (method_exists($schemaBuilder, 'getColumns')) {
            $columns = [];

            foreach ($schemaBuilder->getColumns($tableName) as $column) {
                $columnName = $column['name'];
                $columns[$columnName] = Column::make([
                    'name' => $columnName,
                    'type' => ['name' => $schemaBuilder->getColumnType($tableName, $columnName)],
                    'notnull' => !($column['nullable'] ?? false),
                    'default' => $column['default'] ?? null,
                    'autoincrement' => $column['auto_increment'] ?? false,
                    'length' => $column['length'] ?? null,
                    'precision' => $column['precision'] ?? null,
                    'scale' => $column['scale'] ?? null,
                    'unsigned' => $column['unsigned'] ?? false,
                    'fixed' => $column['fixed'] ?? false,
                ], $tableName);
            }

            $indexes = [];
            if (method_exists($schemaBuilder, 'getIndexes')) {
                foreach ($schemaBuilder->getIndexes($tableName) as $index) {
                    $doctrineIndex = Index::make($index);
                    $indexes[$doctrineIndex->getName()] = $doctrineIndex;
                }
            }

            $foreignKeys = [];
            if (method_exists($schemaBuilder, 'getForeignKeys')) {
                foreach ($schemaBuilder->getForeignKeys($tableName) as $foreignKey) {
                    if (!isset($foreignKey['localColumns'], $foreignKey['foreignTable'], $foreignKey['foreignColumns'])) {
                        continue;
                    }

                    $doctrineForeignKey = ForeignKey::make($foreignKey);
                    $foreignKeys[$doctrineForeignKey->getName()] = $doctrineForeignKey;
                }
            }

            return new Table($tableName, $columns, $indexes, [], $foreignKeys, []);
        }

        $table = static::getDoctrineTable($tableName);

        return new Table(
            $table->getName(),
            $table->getColumns(),
            $table->getIndexes(),
            [],
            $table->getForeignKeys(),
            $table->getOptions()
        );
    }

    public static function describeTable($tableName)
    {
        $table = static::listTableDetails($tableName);

        return collect($table->getColumns())->map(function ($column) use ($table) {
            $columnDetails = static::getColumnDetails($column);
            $indexes = array_values($table->getColumnsIndexes($column->getName(), true));

            if (!empty($indexes) && isset($indexes[1])) {
                $indexes = [$indexes[1]];
            }

            $indexes = array_map(function ($index) {
                return Index::toArray($index);
            }, $indexes);

            return [
                'field' => $column->getName(),
                'type' => $columnDetails['type'],
                'null' => $columnDetails['nullable'],
                'key' => !empty($indexes) ? substr($indexes[0]['type'], 0, 3) : null,
                'default' => $columnDetails['default'],
                'extra' => $columnDetails['auto_increment'] ? 'auto_increment' : '',
                'indexes' => $indexes,
            ];
        })->values();
    }

    public static function listTableColumnNames($tableName)
    {
        return array_map(function ($column) {
            return $column->getName();
        }, static::listTableDetails($tableName)->getColumns());
    }

    public static function createTable($table)
    {
        if ($table instanceof Blueprint) {
            Schema::create($table->getTable(), function (Blueprint $blueprint) use ($table) {
                foreach ($table->getColumns() as $column) {
                    $blueprint->addColumn(
                        Type::resolveName($column->getType()),
                        $column->getName(),
                        $column->toArray()
                    );
                }
            });

            return;
        }

        if (!$table instanceof DoctrineTable) {
            throw new \InvalidArgumentException('Table must be an instance of Blueprint or Doctrine table');
        }

        static::executeStatements((array) static::getDoctrineConnection()->getDatabasePlatform()->getCreateTableSQL($table));
    }

    public static function alterTable($tableDiff)
    {
        if (!$tableDiff instanceof TableDiff) {
            throw new \InvalidArgumentException('Table diff must be an instance of Doctrine table diff');
        }

        static::executeStatements((array) static::getDoctrineConnection()->getDatabasePlatform()->getAlterTableSQL($tableDiff));
    }

    public static function renameTable($oldName, $newName)
    {
        static::executeStatements((array) static::getDoctrineConnection()->getDatabasePlatform()->getRenameTableSQL($oldName, $newName));
    }

    public static function dropTable($table)
    {
        Schema::drop($table);
    }

    protected static function getColumnDetails($column)
    {
        return [
            'type' => Type::resolveName($column->getType()),
            'nullable' => !$column->getNotnull(),
            'default' => $column->getDefault(),
            'auto_increment' => $column->getAutoincrement(),
        ];
    }

    public static function listTableNames()
    {
        $connection = Schema::getConnection();
        $schemaBuilder = $connection->getSchemaBuilder();

        if (method_exists($schemaBuilder, 'getTables')) {
            $tables = $schemaBuilder->getTables();

            return collect($tables)
                ->map(function ($table) {
                    return static::normalizeTableName($table);
                })
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return array_values(array_unique(array_map(function ($table) {
            return static::normalizeTableName($table);
        }, static::getDoctrineSchemaManager()->listTableNames())));
    }

    protected static function executeStatements(array $statements)
    {
        $connection = static::getDatabaseConnection();

        foreach ($statements as $statement) {
            if (!empty($statement)) {
                $connection->statement($statement);
            }
        }
    }

    protected static function normalizeTableName($table)
    {
        if (is_array($table)) {
            foreach (['name', 'table_name'] as $key) {
                if (!empty($table[$key])) {
                    $table = $table[$key];
                    break;
                }
            }
        }

        if (!is_string($table) || $table === '') {
            return null;
        }

        $segments = explode('.', $table);

        return end($segments) ?: $table;
    }

    protected static function getDoctrineConnectionParams(LaravelConnection $connection, $pdo = null)
    {
        $config = $connection->getConfig();
        $driver = $connection->getDriverName();
        $params = [
            'driver' => static::mapDoctrineDriver($driver),
            'pdo' => $pdo ?: $connection->getPdo(),
        ];

        foreach (['host', 'port', 'user', 'password', 'dbname', 'unix_socket', 'charset'] as $key) {
            $value = $config[$key] ?? null;

            if ($value !== null && $value !== '') {
                $params[$key] = $value;
            }
        }

        if (!isset($params['dbname']) && isset($config['database']) && $driver !== 'sqlite') {
            $params['dbname'] = $config['database'];
        }

        if ($driver === 'sqlite') {
            $params['path'] = $config['database'] ?? database_path('database.sqlite');
        }

        if (isset($config['server_version'])) {
            $params['serverVersion'] = $config['server_version'];
        }

        return $params;
    }

    protected static function mapDoctrineDriver($driver)
    {
        switch ($driver) {
            case 'mysql':
                return 'pdo_mysql';
            case 'pgsql':
                return 'pdo_pgsql';
            case 'sqlite':
                return 'pdo_sqlite';
            case 'sqlsrv':
                return 'pdo_sqlsrv';
            default:
                throw new \RuntimeException("Unsupported database driver [{$driver}] for Doctrine schema operations.");
        }
    }
}
