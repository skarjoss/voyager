<?php

namespace TCG\Voyager\Database\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform as DoctrineAbstractPlatform;
use Doctrine\DBAL\Types\Type as DoctrineType;
use TCG\Voyager\Database\Platforms\Platform;
use TCG\Voyager\Database\Schema\SchemaManager;

abstract class Type extends DoctrineType
{
    public $tableName;

    protected static $customTypesRegistered = false;
    protected static $platformTypeMapping = [];
    protected static $allTypes = [];
    protected static $platformTypes = [];
    protected static $customTypeOptions = [];
    protected static $resolvedCustomTypeOptions = [];
    protected static $typeCategories = [];
    protected static $registeredTypes = [];
    protected static $resolvedTypeNames = [];

    public const NAME = 'UNDEFINED_TYPE_NAME';
    public const NOT_SUPPORTED = 'notSupported';
    public const NOT_SUPPORT_INDEX = 'notSupportIndex';

    // todo: make sure this is not overwrting DoctrineType properties

    // Note: length, precision and scale need default values manually

    public function getName(): string
    {
        return static::NAME;
    }

    public static function toArray(DoctrineType $type)
    {
        $name = static::resolveName($type);
        $customTypeOptions = static::$resolvedCustomTypeOptions[$name] ?? [];

        return array_merge([
            'name' => $name,
        ], $customTypeOptions);
    }

    public static function getPlatformTypes()
    {
        if (static::$platformTypes) {
            return static::$platformTypes;
        }

        if (!static::$customTypesRegistered) {
            static::registerCustomPlatformTypes();
        }

        $platform = SchemaManager::getDatabaseConnection()->getDriverName();

        static::$platformTypes = Platform::getPlatformTypes($platform, static::getPlatformTypeMapping());

        static::$platformTypes = static::$platformTypes->map(function ($type) {
            return static::toArray(DoctrineType::getType($type));
        })->groupBy('category');

        return static::$platformTypes;
    }

    public static function getPlatformTypeMapping(DoctrineAbstractPlatform $platform = null)
    {
        if (static::$platformTypeMapping) {
            return static::$platformTypeMapping;
        }

        $types = array_keys(DoctrineType::getTypesMap());

        if (!empty(static::$registeredTypes)) {
            $types = array_merge($types, array_keys(static::$registeredTypes));
        }

        static::$platformTypeMapping = collect($types)->unique()->values();

        return static::$platformTypeMapping;
    }

    public static function registerCustomPlatformTypes($force = false)
    {
        if (static::$customTypesRegistered && !$force) {
            return;
        }

        $platform = SchemaManager::getDatabaseConnection()->getDriverName();
        $platformName = ucfirst($platform);

        $customTypes = array_merge(
            static::getPlatformCustomTypes('Common'),
            static::getPlatformCustomTypes($platformName)
        );

        foreach ($customTypes as $type) {
            $name = $type::NAME;
            static::registerType($name, $type);
        }

        static::addCustomTypeOptions($platformName);

        static::$customTypesRegistered = true;
    }

    protected static function addCustomTypeOptions($platformName)
    {
        static::registerCommonCustomTypeOptions();

        Platform::registerPlatformCustomTypeOptions($platformName);

        // Add the custom options to the types
        foreach (static::$customTypeOptions as $option) {
            foreach ($option['types'] as $type) {
                if (static::hasType($type)) {
                    if (!isset(static::$resolvedCustomTypeOptions[$type])) {
                        static::$resolvedCustomTypeOptions[$type] = [];
                    }

                    static::$resolvedCustomTypeOptions[$type][$option['name']] = $option['value'];
                }
            }
        }
    }

    protected static function getPlatformCustomTypes($platformName)
    {
        $typesPath = __DIR__.DIRECTORY_SEPARATOR.$platformName.DIRECTORY_SEPARATOR;
        $namespace = __NAMESPACE__.'\\'.$platformName.'\\';
        $types = [];

        foreach (glob($typesPath.'*.php') as $classFile) {
            $types[] = $namespace.str_replace(
                '.php',
                '',
                str_replace($typesPath, '', $classFile)
            );
        }

        return $types;
    }

    public static function registerCustomOption($name, $value, $types)
    {
        if (is_string($types)) {
            $types = trim($types);

            if ($types == '*') {
                $types = static::getAllTypes()->toArray();
            } elseif (strpos($types, '*') !== false) {
                $searchType = str_replace('*', '', $types);
                $types = static::getAllTypes()->filter(function ($type) use ($searchType) {
                    return strpos($type, $searchType) !== false;
                })->toArray();
            } else {
                $types = [$types];
            }
        }

        static::$customTypeOptions[] = [
            'name'  => $name,
            'value' => $value,
            'types' => $types,
        ];
    }

    protected static function registerCommonCustomTypeOptions()
    {
        static::registerTypeCategories();
        static::registerTypeDefaultOptions();
    }

    protected static function registerTypeDefaultOptions()
    {
        $types = static::getTypeCategories();

        // Numbers
        static::registerCustomOption('default', [
            'type' => 'number',
            'step' => 'any',
        ], $types['numbers']);

        // Date and Time
        static::registerCustomOption('default', [
            'type' => 'date',
        ], 'date');
        static::registerCustomOption('default', [
            'type' => 'time',
            'step' => '1',
        ], 'time');
        static::registerCustomOption('default', [
            'type' => 'number',
            'min'  => '0',
        ], 'year');
    }

    protected static function registerTypeCategories()
    {
        $types = static::getTypeCategories();

        static::registerCustomOption('category', 'Numbers', $types['numbers']);
        static::registerCustomOption('category', 'Strings', $types['strings']);
        static::registerCustomOption('category', 'Date and Time', $types['datetime']);
        static::registerCustomOption('category', 'Lists', $types['lists']);
        static::registerCustomOption('category', 'Binary', $types['binary']);
        static::registerCustomOption('category', 'Geometry', $types['geometry']);
        static::registerCustomOption('category', 'Network', $types['network']);
        static::registerCustomOption('category', 'Objects', $types['objects']);
    }

    public static function getAllTypes()
    {
        if (static::$allTypes) {
            return static::$allTypes;
        }

        static::$allTypes = collect(static::getTypeCategories())->flatten();

        return static::$allTypes;
    }

    public static function getTypeCategories()
    {
        if (static::$typeCategories) {
            return static::$typeCategories;
        }

        $numbers = [
            'boolean',
            'tinyint',
            'smallint',
            'mediumint',
            'integer',
            'int',
            'bigint',
            'decimal',
            'numeric',
            'money',
            'float',
            'real',
            'double',
            'double precision',
        ];

        $strings = [
            'char',
            'character',
            'varchar',
            'character varying',
            'string',
            'guid',
            'uuid',
            'tinytext',
            'text',
            'mediumtext',
            'longtext',
            'tsquery',
            'tsvector',
            'xml',
        ];

        $datetime = [
            'date',
            'datetime',
            'year',
            'time',
            'timetz',
            'timestamp',
            'timestamptz',
            'datetimetz',
            'dateinterval',
            'interval',
        ];

        $lists = [
            'enum',
            'set',
            'simple_array',
            'array',
            'json',
            'jsonb',
            'json_array',
        ];

        $binary = [
            'bit',
            'bit varying',
            'binary',
            'varbinary',
            'tinyblob',
            'blob',
            'mediumblob',
            'longblob',
            'bytea',
        ];

        $network = [
            'cidr',
            'inet',
            'macaddr',
            'txid_snapshot',
        ];

        $geometry = [
            'geometry',
            'point',
            'linestring',
            'polygon',
            'multipoint',
            'multilinestring',
            'multipolygon',
            'geometrycollection',
        ];

        $objects = [
            'object',
        ];

        static::$typeCategories = [
            'numbers'  => $numbers,
            'strings'  => $strings,
            'datetime' => $datetime,
            'lists'    => $lists,
            'binary'   => $binary,
            'network'  => $network,
            'geometry' => $geometry,
            'objects'  => $objects,
        ];

        return static::$typeCategories;
    }

    public static function registerType($name, $typeClass)
    {
        if (DoctrineType::hasType($name)) {
            DoctrineType::overrideType($name, $typeClass);
        } else {
            DoctrineType::addType($name, $typeClass);
        }

        static::$registeredTypes[$name] = $typeClass;
    }

    public static function resolveDoctrineTypeName($name)
    {
        $name = trim(strtolower($name));

        if (DoctrineType::hasType($name)) {
            return $name;
        }

        $aliases = [
            'varchar' => 'string',
            'character varying' => 'string',
            'char' => 'string',
            'character' => 'string',
            'int' => 'integer',
            'tinyint' => 'boolean',
            'mediumint' => 'integer',
            'bigserial' => 'bigint',
            'serial' => 'integer',
            'jsonb' => 'json',
            'timestamp' => 'datetime',
            'timestamp without time zone' => 'datetime',
            'timestamp with time zone' => 'datetimetz',
        ];

        if (isset($aliases[$name]) && DoctrineType::hasType($aliases[$name])) {
            return $aliases[$name];
        }

        try {
            $platform = SchemaManager::getDoctrineConnection()->getDatabasePlatform();

            if (method_exists($platform, 'getDoctrineTypeMapping')) {
                $mapped = $platform->getDoctrineTypeMapping($name);

                if (DoctrineType::hasType($mapped)) {
                    return $mapped;
                }
            }
        } catch (\Throwable $e) {
        }

        return DoctrineType::hasType('string') ? 'string' : $name;
    }

    public static function resolveName(DoctrineType $type)
    {
        $class = get_class($type);

        if (isset(static::$resolvedTypeNames[$class])) {
            return static::$resolvedTypeNames[$class];
        }

        if (method_exists(DoctrineType::class, 'lookupName')) {
            try {
                return static::$resolvedTypeNames[$class] = DoctrineType::lookupName($type);
            } catch (\Throwable $e) {
            }
        }

        if (defined($class.'::NAME')) {
            return static::$resolvedTypeNames[$class] = $class::NAME;
        }

        if (method_exists($type, 'getName')) {
            try {
                return static::$resolvedTypeNames[$class] = $type->getName();
            } catch (\Throwable $e) {
            }
        }

        foreach (DoctrineType::getTypesMap() as $name => $registeredClass) {
            if ($registeredClass === $class) {
                return static::$resolvedTypeNames[$class] = $name;
            }
        }

        $reflection = new \ReflectionClass($type);

        return static::$resolvedTypeNames[$class] = strtolower($reflection->getShortName());
    }
}
