<?php

namespace Directus\Database\Schema;

use Directus\Database\Exception\CollectionNotFoundException;
use Directus\Database\Schema\Object\Field;
use Directus\Database\Schema\Object\Collection;
use Directus\Database\Schema\Sources\SchemaInterface;
use Directus\Exception\Exception;
use Directus\Util\ArrayUtils;

class SchemaManager
{
    // Tables
    const COLLECTION_ACTIVITY            = 'directus_activity';
    const COLLECTION_COLLECTIONS         = 'directus_collections';
    const COLLECTION_COLLECTION_PRESETS  = 'directus_collection_presets';
    const COLLECTION_FIELDS              = 'directus_fields';
    const COLLECTION_FILES               = 'directus_files';
    const COLLECTION_ROLES               = 'directus_roles';
    const COLLECTION_PERMISSIONS         = 'directus_permissions';
    const COLLECTION_RELATIONS           = 'directus_relations';
    const COLLECTION_REVISIONS           = 'directus_revisions';
    const COLLECTION_SETTINGS            = 'directus_settings';
    const COLLECTION_USER_ROLES          = 'directus_user_roles';
    const COLLECTION_USERS               = 'directus_users';

    /**
     * Schema source instance
     *
     * @var \Directus\Database\Schema\Sources\SchemaInterface
     */
    protected $source;

    /**
     * Schema data information
     *
     * @var array
     */
    protected $data = [];

    /**
     * System table prefix
     *
     * @var string
     */
    protected $prefix = 'directus_';

    /**
     * Directus System tables
     *
     * @var array
     */
    protected $directusTables = [
        // FIXME: Use constant value instead (one place)
        'activity',
        'activity_read',
        'collection_presets',
        'collections',
        'fields',
        'files',
        'folders',
        'migrations',
        'permissions',
        'relations',
        'revisions',
        'roles',
        'settings',
        'user_roles',
        'users'
    ];

    public function __construct(SchemaInterface $source)
    {
        $this->source = $source;
    }

    /**
     * Adds a primary key to the given column
     *
     * @param $table
     * @param $column
     *
     * @return bool
     */
    public function addPrimaryKey($table, $column)
    {
        return $this->source->addPrimaryKey($table, $column);
    }

    /**
     * Removes the primary key of the given column
     *
     * @param $table
     * @param $column
     *
     * @return bool
     */
    public function dropPrimaryKey($table, $column)
    {
        return $this->source->dropPrimaryKey($table, $column);
    }

    /**
     * Get the table schema information
     *
     * @param string $tableName
     * @param array  $params
     * @param bool   $skipCache
     *
     * @throws CollectionNotFoundException
     *
     * @return \Directus\Database\Schema\Object\Collection
     */
    public function getCollection($collectionName, $params = [], $skipCache = false)
    {
        $collection = ArrayUtils::get($this->data, 'collections.' . $collectionName, null);
        if (!$collection || $skipCache) {
            // Get the table schema data from the source
            $collectionResult = $this->source->getCollection($collectionName);
            $collectionData = $collectionResult->current();

            if (!$collectionData) {
                throw new CollectionNotFoundException($collectionName);
            }

            // Create a table object based of the table schema data
            $collection = $this->createCollectionFromArray(array_merge($collectionData, [
                'schema' => $this->source->getSchemaName()
            ]));
            $this->addCollection($collectionName, $collection);
        }

        // =============================================================================
        // Set table columns
        // -----------------------------------------------------------------------------
        // @TODO: Do not allow to add duplicate column names
        // =============================================================================
        if (empty($collection->getFields())) {
            $fields = $this->getFields($collectionName);
            $collection->setFields($fields);
        }

        return $collection;
    }

    /**
     * Gets column schema
     *
     * @param $tableName
     * @param $columnName
     * @param bool $skipCache
     *
     * @return Field
     */
    public function getField($tableName, $columnName, $skipCache = false)
    {
        $columnSchema = ArrayUtils::get($this->data, 'fields.' . $tableName . '.' . $columnName, null);

        if (!$columnSchema || $skipCache) {
            // Get the column schema data from the source
            $columnResult = $this->source->getFields($tableName, ['column_name' => $columnName]);
            $columnData = $columnResult->current();

            // Create a column object based of the table schema data
            $columnSchema = $this->createFieldFromArray($columnData);
            $this->addField($columnSchema);
        }

        return $columnSchema;
    }

    /**
     * Add the system table prefix to to a table name.
     *
     * @param string|array $names
     *
     * @return array
     */
    public function addSystemCollectionPrefix($names)
    {
        if (!is_array($names)) {
            $names = [$names];
        }

        return array_map(function ($name) {
            // TODO: Directus tables prefix _probably_ will be dynamic
            return $this->prefix . $name;
        }, $names);
    }

    /**
     * Get Directus System tables name
     *
     * @return array
     */
    public function getSystemCollections()
    {
        return $this->addSystemCollectionPrefix($this->directusTables);
    }

    /**
     * Check if the given name is a system table
     *
     * @param $name
     *
     * @return bool
     */
    public function isSystemCollection($name)
    {
        return in_array($name, $this->getSystemCollections());
    }

    /**
     * Check if a table name exists
     *
     * @param $tableName
     * @return bool
     */
    public function tableExists($tableName)
    {
        return $this->source->collectionExists($tableName);
    }

    /**
     * Gets list of table
     *
     * @param array $params
     *
     * @return Collection[]
     */
    public function getCollections(array $params = [])
    {
        // TODO: Filter should be outsite
        // $schema = Bootstrap::get('schema');
        // $config = Bootstrap::get('config');

        // $ignoredTables = static::getDirectusTables(DirectusPreferencesTableGateway::$IGNORED_TABLES);
        // $blacklistedTable = $config['tableBlacklist'];
        // array_merge($ignoredTables, $blacklistedTable)
        $collections = $this->source->getCollections();

        $tables = [];
        foreach ($collections as $collection) {
            // Create a table object based of the table schema data
            $tableSchema = $this->createCollectionFromArray(array_merge($collection, [
                'schema' => $this->source->getSchemaName()
            ]));
            $tableName = $tableSchema->getName();
            $this->addCollection($tableName, $tableSchema);

            $tables[$tableName] = $tableSchema;
        }

        return $tables;
    }

    /**
     * Get all columns in the given table name
     *
     * @param $tableName
     * @param array $params
     *
     * @return \Directus\Database\Schema\Object\Field[]
     */
    public function getFields($tableName, $params = [])
    {
        // TODO: filter black listed fields on services level

        $columnsSchema = ArrayUtils::get($this->data, 'columns.' . $tableName, null);
        if (!$columnsSchema) {
            $columnsResult = $this->source->getFields($tableName, $params);
            $relationsResult = $this->source->getRelations($tableName);

            // TODO: Improve this logic
            $relationsA = [];
            $relationsB = [];
            foreach ($relationsResult as $relation) {
                $relationsA[$relation['field_a']] = $relation;

                if (isset($relation['field_b'])) {
                    $relationsB[$relation['field_b']] = $relation;
                }
            }

            $columnsSchema = [];
            foreach ($columnsResult as $column) {
                $field = $this->createFieldFromArray($column);

                // Set all FILE data type related to directus files (M2O)
                if (DataTypes::isFilesType($field->getType())) {
                    $field->setRelationship([
                        'collection_a' => $field->getCollectionName(),
                        'field_a' => $field->getName(),
                        'collection_b' => static::COLLECTION_FILES,
                        'field_b' => 'id'
                    ]);
                } else if (array_key_exists($field->getName(), $relationsA)) {
                    $field->setRelationship($relationsA[$field->getName()]);
                } else if (array_key_exists($field->getName(), $relationsB)) {
                    $field->setRelationship($relationsB[$field->getName()]);
                }

                $columnsSchema[] = $field;
            }

            $this->data['columns'][$tableName] = $columnsSchema;
        }

        return $columnsSchema;
    }

    public function getFieldsName($tableName)
    {
        $columns = $this->getFields($tableName);

        $columnNames = [];
        foreach ($columns as $column) {
            $columnNames[] = $column->getName();
        }

        return $columnNames;
    }

    /**
     * Get all the columns
     *
     * @return Field[]
     */
    public function getAllFields()
    {
        $allColumns = $this->source->getAllFields();

        $columns = [];
        foreach($allColumns as $column) {
            $columns[] = $this->createFieldFromArray($column);
        }

        return $columns;
    }

    /**
     * Get a list of columns table grouped by table name
     *
     * @return array
     */
    public function getAllFieldsByCollection()
    {
        $fields = [];
        foreach ($this->getAllFields() as $field) {
            $collectionName = $field->getCollectionName();
            if (!isset($fields[$collectionName])) {
                $fields[$collectionName] = [];
            }

            $columns[$collectionName][] = $field;
        }

        return $fields;
    }

    public function getPrimaryKey($tableName)
    {
        $collection = $this->getCollection($tableName);
        if ($collection) {
            return $collection->getPrimaryKeyName();
        }

        return false;
    }

    public function hasSystemDateField($tableName)
    {
        $tableObject = $this->getCollection($tableName);

        return $tableObject->getDateCreatedField() || $tableObject->getDateModifiedField();
    }

    public function castRecordValues($records, $columns)
    {
        return $this->source->castRecordValues($records, $columns);
    }

    /**
     * Cast value against a database type
     *
     * NOTE: it only works with MySQL data types
     *
     * @param $value
     * @param $type
     * @param $length
     *
     * @return mixed
     */
    public function castValue($value, $type = null, $length = false)
    {
        return $this->source->castValue($value, $type, $length);
    }

    /**
     * Checks whether the given type is numeric type
     *
     * @param $type
     *
     * @return bool
     */
    public function isNumericType($type)
    {
        return DataTypes::isNumericType($type);
    }

    /**
     * Checks whether the given type is string type
     *
     * @param $type
     *
     * @return bool
     */
    public function isStringType($type)
    {
        return DataTypes::isStringType($type);
    }

    /**
     * Checks whether the given type is integer type
     *
     * @param $type
     *
     * @return bool
     */
    public function isIntegerType($type)
    {
        return DataTypes::isIntegerType($type);
    }

    /**
     * Checks whether the given type is decimal type
     *
     * @param $type
     *
     * @return bool
     */
    public function isFloatingPointType($type)
    {
        return static::isFloatingPointType($type);
    }

    /**
     * Cast default value
     *
     * @param $value
     * @param $type
     * @param $length
     *
     * @return mixed
     */
    public function castDefaultValue($value, $type, $length = null)
    {
        if (strtolower($value) === 'null') {
            $value = null;
        } else {
            $value = $this->castValue($value, $type, $length);
        }

        return $value;
    }

    /**
     * Get all Directus system tables name
     *
     * @param array $filterNames
     *
     * @return array
     */
    public function getDirectusCollections(array $filterNames = [])
    {
        $tables = $this->directusTables;
        if ($filterNames) {
            foreach ($tables as $i => $table) {
                if (!in_array($table, $filterNames)) {
                    unset($tables[$i]);
                }
            }
        }

        return $this->addSystemCollectionPrefix($tables);
    }

    /**
     * Check if a given table is a directus system table name
     *
     * @param $tableName
     *
     * @return bool
     */
    public function isDirectusCollection($tableName)
    {
        return in_array($tableName, $this->getDirectusCollections());
    }

    /**
     * Get the schema adapter
     *
     * @return SchemaInterface
     */
    public function getSchema()
    {
        return $this->source;
    }

    /**
     * List of supported databases
     *
     * @return array
     */
    public static function getSupportedDatabases()
    {
        return [
            'mysql' => [
                'id' => 'mysql',
                'name' => 'MySQL/Percona'
            ],
        ];
    }

    public static function getTemplates()
    {
        // @TODO: SchemaManager shouldn't be a class with static methods anymore
        // the UI templates list will be provided by a container or bootstrap.
        $path = implode(DIRECTORY_SEPARATOR, [
            base_path(),
            'api',
            'migrations',
            'templates',
            '*'
        ]);

        $templatesDirs = glob($path, GLOB_ONLYDIR);
        $templatesData = [];
        foreach ($templatesDirs as $dir) {
            $key = basename($dir);
            $templatesData[$key] = [
                'id' => $key,
                'name' => uc_convert($key)
            ];
        }

        return $templatesData;
    }

    /**
     * Gets a collection object from an array attributes data
     * @param $data
     *
     * @return Collection
     */
    public function createCollectionFromArray($data)
    {
        return new Collection($data);
    }

    /**
     * Creates a column object from the given array
     *
     * @param array $column
     *
     * @return Field
     */
    public function createFieldFromArray($column)
    {
        // PRIMARY KEY must be required
        if ($column['key'] === 'PRI') {
            $column['required'] = true;
        }

        $options = json_decode(isset($column['options']) ? $column['options'] : '', true);
        $column['options'] = $options ? $options : null;

        // NOTE: Alias column must are nullable
        if (strtoupper($column['type']) === 'ALIAS') {
            $column['nullable'] = 1;
        }

        // NOTE: MariaDB store "NULL" as a string on some data types such as VARCHAR.
        // We reserved the word "NULL" on nullable data type to be actually null
        if ($column['nullable'] === 1 && $column['default_value'] == 'NULL') {
            $column['default_value'] = null;
        }

        return new Field($column);
    }

    /**
     * Checks whether the given type is a unique type
     *
     * @param $type
     *
     * @return bool
     */
    public function isUniqueFieldType($type)
    {
        return DataTypes::isUniqueType($type);
    }

    protected function addCollection($name, $schema)
    {
        // save the column into the data
        // @NOTE: this is the early implementation of cache
        // soon this will be change to cache
        $this->data['tables'][$name] = $schema;
    }

    protected function addField(Field $column)
    {
        $tableName = $column->getCollectionName();
        $columnName = $column->getName();
        $this->data['fields'][$tableName][$columnName] = $column;
    }

    /**
     *
     *
     * @param $type
     *
     * @return integer
     */
    public function getFieldDefaultLength($type)
    {
        return $this->source->getColumnDefaultLength($type);
    }

    /**
     * Gets the column type based the schema adapter
     *
     * @param string $type
     *
     * @return string
     */
    public function getDataType($type)
    {
        return $this->source->getDataType($type);
    }

    /**
     * Gets the source schema adapter
     *
     * @return SchemaInterface
     */
    public function getSource()
    {
        return $this->source;
    }
}
