<?php

namespace Nraa\Database\Traits;

use Doctrine\Common\Collections\ArrayCollection;
use MongoDB\InsertManyResult;
use \Nraa\Database\Traits\HasEvents;

trait MongoDBTransactionalTrait
{

    use HasEvents;

    protected $options = [];
    protected $filter = [];
    private static array $hydratablePropertyMapCache = [];
    private static array $serializablePublicPropertyNamesCache = [];
    private static array $indexAttributesCache = [];
    private static array $usesSoftDeleteCache = [];

    /**
     * Summary of applySoftDeleteToFilter
     * @param array $filter
     * @return array
     */
    protected function applySoftDeleteToFilter(array $filter = []): array
    {
        if ($this->usesSoftDelete()) {
            // If the caller asked to include trashed records, skip adding the filter.
            if (!($this->includeDeleted ?? false)) {
                if (!array_key_exists('deleted', $filter)) {
                    $notDeletedCondition = [
                        '$or' => [
                            ['deleted' => false],
                            ['deleted' => ['$exists' => false]],
                        ],
                    ];

                    if (empty($filter)) {
                        $filter = $notDeletedCondition;
                    } elseif (isset($filter['$and']) && is_array($filter['$and'])) {
                        $filter['$and'][] = $notDeletedCondition;
                    } else {
                        $filter = [
                            '$and' => [
                                $filter,
                                $notDeletedCondition,
                            ],
                        ];
                    }
                }
            }
        }
        return $filter;
    }

    /**
     * Summary of save
     * @return bool
     */
    public function save()
    {
        //$this->validate();

        $this->notifyEvent('saving');

        if (!$this->fireEvent('save')) {
            return false;
        }

        if (!isset($this->id) || $this->id === null) {
            $now = new \MongoDB\BSON\UTCDateTime();
            if (!$this->createdAt) {
                $this->createdAt = $now;
            }
            if (!$this->updatedAt) {
                $this->updatedAt = $now;
            }
            $doc = $this->toDocument();
            // Ensure timestamps are always persisted even if toDocument misses them.
            $doc['createdAt'] = $this->createdAt ?? $now;
            $doc['updatedAt'] = $this->updatedAt ?? $now;
            unset($doc['_id']);
            unset($doc['id']);
            $result = $this->getCollection()->insertOne($doc);
            $this->id = $result->getInsertedId();
        } else {
            $now = new \MongoDB\BSON\UTCDateTime();
            if (!$this->createdAt) {
                $this->createdAt = $now;
            }
            $this->updatedAt = $now;
            $doc = $this->toDocument();
            $doc['createdAt'] = $this->createdAt ?? $now;
            $doc['updatedAt'] = $this->updatedAt ?? $now;
            $this->getCollection()->updateOne(['_id' =>  $this->id], ['$set' => $doc]);
        }
    }

    /**
     * Summary of get
     * @return iterable
     */
    public function get()
    {
        // Ensure soft-delete filter is applied to the accumulated filter
        $this->filter = $this->applySoftDeleteToFilter($this->filter);
        $options = $this->options;
        $connectionName = \Nraa\Database\Drivers\MongoDBDriver::extractConnectionName($options);
        $this->connectionName = $connectionName;
        $cursor = $this->getCollection()->find($this->filter, $options);

        // After running the query, reset includeDeleted to default false so subsequent queries won't unexpectedly include trashed items
        if ($this->usesSoftDelete()) {
            $this->includeDeleted = false;
        }

        return $cursor;
    }

    /**
     * Summary of where
     * @param array $filter
     * @param array $options
     * @return self
     */
    public function where($filter = [], $options = []): self
    {
        if (!empty($filter)) {
            $this->filter = \array_merge($this->filter, $filter);
        }
        if (!empty($options)) {
            $this->options = \array_merge($this->options, $options);
        }
        return $this;
    }


    /**
     * Summary of insertMany
     * @param array $documents
     * @param array $options
     * @return InsertManyResult
     */
    public function insertMany(array $documents, array $options = []): InsertManyResult
    {
        return $this->getCollection()->insertMany($documents, $options);
    }

    /**
     * Summary of findInstance
     * @param array|object $filter
     * @param array $options
     * @return iterable
     */
    private function findInstance(array|object $filter = [], array $options = []): iterable
    {
        $connectionName = \Nraa\Database\Drivers\MongoDBDriver::extractConnectionName($options);
        $this->connectionName = $connectionName;

        // merge instance-level filter if not empty
        if (empty($filter) && !empty($this->filter)) {
            $filter = $this->filter;
        } elseif (is_array($filter) && !empty($this->filter)) {
            $filter = array_merge($this->filter, $filter);
        }

        // apply soft-delete restrictions for array filters
        if (is_array($filter)) {
            $filter = $this->applySoftDeleteToFilter($filter);
        }

        $cursor = $this->getCollection()->find($filter, $options);

        // reset includeDeleted after query
        if ($this->usesSoftDelete()) {
            $this->includeDeleted = false;
        }

        return $cursor;
    }

    /**
     * Summary of find all documents
     * @param array|object $filter
     * @param array $options
     * @return \MongoDB\Driver\Cursor
     */
    public static function find(array|object $filter = [], array $options = []): \MongoDB\Driver\Cursor
    {
        $connectionName = \Nraa\Database\Drivers\MongoDBDriver::extractConnectionName($options);
        $instance = new static($connectionName);
        return $instance->findInstance($filter, $options);
    }

    /**
     * Summary of findOneById
     * @param string $id
     * @return array|object|null
     */
    public static function findOneById(string $id): array|object|null
    {
        return static::findOne(['_id' => new \MongoDB\BSON\ObjectId($id)]);
    }

    /**
     * Summary of findOneById
     * @param string $id
     * @return array|object|null
     */
    public static function findById(string $id): array|object|null
    {
        return static::findOne(['_id' => new \MongoDB\BSON\ObjectId($id)]);
    }

    /**
     * Summary of count
     * @param array|object $filter
     * @param array $options
     * @return int
     */
    public static function count(array|object $filter = [], array $options = []): int
    {
        $connectionName = \Nraa\Database\Drivers\MongoDBDriver::extractConnectionName($options);
        $instance = new static($connectionName);

        // apply soft-delete restrictions for array filters
        if (is_array($filter)) {
            $filter = $instance->applySoftDeleteToFilter($filter);
        }

        return $instance->getCollection()->countDocuments($filter, $options);
    }

    /**
     * Summary of findOneAndUpdate
     * @param array|object $filter
     * @param array|object $update
     * @param array $options
     * @return array|object|null
     */
    public function findOneAndUpdate(array|object $filter, array|object $update, array $options = []): array|object|null
    {
        // If filter is array apply soft-delete when applicable
        if (is_array($filter)) {
            $filter = $this->applySoftDeleteToFilter($filter);
        }
        return $this->getCollection()->findOneAndUpdate($filter, $update, $options);
    }

    /**
     * Summary of findOneInstance
     * @param array|object $filter
     * @param array $options
     * @return array|object|null
     */
    private function findOneInstance(array|object $filter = [], array $options = []): array|object|null
    {
        $connectionName = \Nraa\Database\Drivers\MongoDBDriver::extractConnectionName($options);
        $this->connectionName = $connectionName;

        // apply instance-level filters
        if (empty($filter) && !empty($this->filter)) {
            $filter = $this->filter;
        } elseif (is_array($filter) && !empty($this->filter)) {
            $filter = array_merge($this->filter, $filter);
        }

        // apply soft-delete filter when filter is an array
        if (is_array($filter)) {
            $filter = $this->applySoftDeleteToFilter($filter);
        }

        $result = $this->getCollection()->findOne($filter, $options);

        // reset includeDeleted after query
        if ($this->usesSoftDelete()) {
            $this->includeDeleted = false;
        }

        if (!$result) {
            return null;
        }

        if ($result instanceof static) {
            return $result;
        }

        // Create new instance and hydrate it with the data
        $modelInstance = new static();
        foreach ($result as $key => $value) {
            $modelInstance->$key = $value;
        }

        return $modelInstance;
    }

    /**
     * Summary of findOne
     * @param array|object $filter
     * @param array $options
     * @return array|object|null
     */
    public static function findOne(array|object $filter = [], array $options = []): array|object|null
    {
        $connectionName = \Nraa\Database\Drivers\MongoDBDriver::extractConnectionName($options);
        $instance = new static($connectionName);

        // if object filter and instance has an accumulated filter, merge into array form
        if (!is_array($filter) && empty($filter) && !empty($instance->filter)) {
            $filter = $instance->filter;
        }

        $result = $instance->findOneInstance($filter, $options);

        // ensure includeDeleted flag reset on instance (defensive)
        if ($instance->usesSoftDelete()) {
            $instance->includeDeleted = false;
        }

        return $result;
    }

    /**
     * Summary of all
     * @return ArrayCollection
     */
    public static function all()
    {
        $static = new static();
        return new ArrayCollection($static->find()->toArray() ?? []);
    }

    /**
     * Summary of touch
     * @return void
     */
    public function touch()
    {
        $this->updatedAt = new \MongoDB\BSON\UTCDateTime();
    }

    /**
     * Summary of ensureIndexes
     * @return void
     */
    public function ensureIndexes(): void
    {
        $desiredIndexes = $this->getIndexAttributesFromClass();
        $normalizeIndexOption = static function ($value) use (&$normalizeIndexOption) {
            if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
                $value = $value->getArrayCopy();
            } elseif ($value instanceof \stdClass) {
                $value = (array)$value;
            }

            if (!is_array($value)) {
                return $value;
            }

            foreach ($value as $key => $nested) {
                $value[$key] = $normalizeIndexOption($nested);
            }
            ksort($value);

            return $value;
        };
        $deriveIndexName = static function (array $keys): string {
            $parts = [];
            foreach ($keys as $field => $direction) {
                $parts[] = $field . '_' . $direction;
            }

            return implode('_', $parts);
        };

        // Always ensure updatedAt index
        $desiredIndexes[] = [
            'keys'    => ['updatedAt' => -1],
            'options' => [],
        ];

        // Always ensure createdAt index
        $desiredIndexes[] = [
            'keys'    => ['createdAt' => -1],
            'options' => [],
        ];

        // Deduplicate desired indexes
        $uniqueDesired = [];
        foreach ($desiredIndexes as $index) {
            $keysSignature    = json_encode($index['keys']);
            $optionsSignature = json_encode($normalizeIndexOption($index['options']));
            $signature        = $keysSignature . '::' . $optionsSignature;

            $uniqueDesired[$signature] = $index;
        }

        // Fetch existing indexes from MongoDB
        $existingIndexes = iterator_to_array($this->getCollection()->listIndexes());

        $existingSignatures = [];
        $existingByName = [];
        foreach ($existingIndexes as $existing) {
            // Get name and key from IndexInfo object
            $name = $existing->getName();
            $key = $existing->getKey();

            // Skip the default _id index
            if ($name === '_id_') {
                continue;
            }

            $keysSignature = json_encode($key);

            // Extract only relevant options for comparison
            $relevantOptions = [];
            if ($existing->isUnique()) {
                $relevantOptions['unique'] = true;
            }
            if ($existing->isSparse()) {
                $relevantOptions['sparse'] = true;
            }
            if (isset($existing['partialFilterExpression'])) {
                $relevantOptions['partialFilterExpression'] = $normalizeIndexOption($existing['partialFilterExpression']);
            }
            if (isset($existing['expireAfterSeconds'])) {
                $relevantOptions['expireAfterSeconds'] = (int)$existing['expireAfterSeconds'];
            }
            $optionsSignature = json_encode($normalizeIndexOption($relevantOptions));

            $signature = $keysSignature . '::' . $optionsSignature;

            $existingSignatures[$signature] = $name;
            $existingByName[$name] = $signature;
        }

        // Create missing indexes
        foreach ($uniqueDesired as $signature => $index) {
            if (!isset($existingSignatures[$signature])) {
                $desiredName = (string)($index['options']['name'] ?? $deriveIndexName($index['keys']));
                if (isset($existingByName[$desiredName]) && $existingByName[$desiredName] !== $signature) {
                    $this->getCollection()->dropIndex($desiredName);
                    unset($existingSignatures[$existingByName[$desiredName]], $existingByName[$desiredName]);
                }

                $this->getCollection()->createIndex($index['keys'], $index['options']);
            }
        }

        // Drop stale indexes
        foreach ($existingSignatures as $signature => $name) {
            if (!isset($uniqueDesired[$signature])) {
                $this->getCollection()->dropIndex($name);
            }
        }
    }

    /**
     * Summary of delete
     * @return void
     */
    public function delete()
    {

        $reflection = new \ReflectionClass($this);

        foreach ($reflection->getProperties() as $property) {
            foreach ($property->getAttributes() as $attr) {
                $attrClass = $attr->getName();

                // Handle HasMany
                if ($attrClass === \Nraa\Database\Attributes\HasMany::class) {
                    $relation = $attr->newInstance();
                    $relatedClass = $relation->className;

                    if ($relation->onDelete === 'cascade') {
                        $related = $relatedClass::find([
                            $relation->foreignKey => $this->{$relation->primaryKey}
                        ]);
                        foreach ($related as $doc) {
                            $doc->delete();
                        }
                    }

                    if ($relation->onDelete === 'setNull') {
                        $relatedClass::updateMany(
                            [$relation->foreignKey => $this->{$relation->primaryKey}],
                            ['$set' => [$relation->foreignKey => null]]
                        );
                    }
                }

                // Handle BelongsToOne
                if ($attrClass === \Nraa\Database\Attributes\BelongsToOne::class) {
                    $relation = $attr->newInstance();
                    $parentClass = $relation->className;

                    // Check if parent still exists
                    $parent = $parentClass::findOne([
                        $relation->primaryKey => $this->{$relation->foreignKey}
                    ]);

                    if (!$parent) {
                        if ($relation->onDelete === 'cascade') {
                            // If parent is gone, delete this child
                            $this->getCollection()->deleteOne(['_id' => $this->id]);
                            return; // stop here since this record is already deleted
                        }

                        if ($relation->onDelete === 'setNull') {
                            $this->{$relation->foreignKey} = null;
                            $this->save();
                        }

                        if ($relation->onDelete === 'restrict') {
                            throw new \RuntimeException(
                                static::class . " cannot be deleted because parent is missing"
                            );
                        }
                    }
                }
            }
        }

        // If model uses SoftDeletable and force flag is not set, perform a soft delete
        if ($this->usesSoftDelete() && empty($this->force)) {
            $this->deleted = true;
            $this->deletedAt = new \MongoDB\BSON\UTCDateTime();
            $this->save();
            // ensure force flag is cleared
            $this->force = false;
            return;
        }

        // Otherwise perform a hard delete
        $this->getCollection()->deleteOne(['_id' => $this->id]);

        // ensure force flag is cleared
        if ($this->usesSoftDelete()) {
            $this->force = false;
        }
    }

    /**
     * Summary of update
     * @return void
     */
    public function update()
    {
        $this->getCollection()->updateOne(['_id' => $this->id], $this->toDocument());
    }

    /**
     * Summary of isBSON
     * @param mixed $value
     * @return bool
     */
    protected function isBSON(mixed $value): bool
    {
        return $value instanceof \MongoDB\Model\BSONDocument;
    }

    /**
     * Summary of toDocument
     * @return array
     */
    public function toDocument()
    {
        return $this->getPublicPropertiesAsArrayOfObjects();
    }

    /**
     * Summary of getPublicPropertiesAsArrayOfObjects
     * @return array
     */
    public function getPublicPropertiesAsArrayOfObjects()
    {
        $stdObj = new \stdClass();
        foreach ($this->getSerializablePublicPropertyNames(static::class) as $propertyName) {
            $stdObj->{$propertyName} = $this->{$propertyName} ?? null;
        }
        return (array) $stdObj;
    }

    /**
     * Summary of setPublicPropertiesFromArray
     * @param mixed $class
     * @param mixed $array
     * @return void
     */
    public function setPublicPropertiesFromArray($class, $array)
    {
        $propertyMap = $this->getHydratablePropertyMap($class);

        foreach ($array as $key => $value) {
            if ($key == "_id") {
                $idValue = $array[$key];
                if ($idValue instanceof \MongoDB\BSON\ObjectId || $idValue === null) {
                    $this->id = $idValue;
                }
                continue;
            }
            $normalizedKey = strtolower((string) $key);
            if (!isset($propertyMap[$normalizedKey])) {
                continue;
            }

            $property = $propertyMap[$normalizedKey];
            $propertyName = $property->getName();
            $value = $array[$key];

            $type = $property->getType();

            if ($type instanceof \ReflectionNamedType) {
                $typeName = $type->getName();

                if (
                    $type instanceof \ReflectionNamedType
                    && !$type->isBuiltin()
                    && is_subclass_of($typeName, \BackedEnum::class)
                ) {
                    if ($value !== null) {
                        $value = $typeName::from($value);
                    }
                }
                // Existing array handling
                if ($typeName === 'array') {
                    if ($value instanceof \stdClass || $value instanceof \MongoDB\Model\BSONDocument) {
                        $value = (array) $value;
                    } elseif ($value instanceof \MongoDB\Model\BSONArray) {
                        $value = iterator_to_array($value);
                    }

                    if (is_array($value)) {
                        $value = $this->convertObjectsToArrays($value);
                    }
                }
            }

            $this->$propertyName = $value;
        }
    }

    /**
     * Recursively converts stdClass objects and BSONDocuments to arrays.
     * 
     * @param mixed $value The value to convert
     * @return mixed The converted value
     */
    private function convertObjectsToArrays($value)
    {
        if ($value instanceof \stdClass || $value instanceof \MongoDB\Model\BSONDocument) {
            $value = $value instanceof \MongoDB\Model\BSONDocument ? $value->getArrayCopy() : (array) $value;
        } elseif ($value instanceof \MongoDB\Model\BSONArray) {
            $value = iterator_to_array($value);
        } elseif (!is_array($value)) {
            return $value;
        }

        // Convert in-place to avoid array_map deep-copy amplification on large nested payloads.
        foreach ($value as $key => $item) {
            $value[$key] = $this->convertObjectsToArrays($item);
        }

        return $value;
    }

    /**
     * Summary of getIndexAttributesFromClass
     * @return array
     */
    protected function getIndexAttributesFromClass(): array
    {
        $class = static::class;
        if (isset(self::$indexAttributesCache[$class])) {
            return self::$indexAttributesCache[$class];
        }

        $reflection = new \ReflectionClass($this);
        $indexesByKey = [];
        $relationAttributeClasses = $this->getRelationAttributeClasses();

        // 1. Collect class-level indexes (#[Index])
        foreach ($reflection->getAttributes(\Nraa\Database\Attributes\Index::class) as $attr) {
            /** @var \Nraa\Database\Attributes\Index $instance */
            $instance = $attr->newInstance();

            $index = [
                'keys'    => $instance->keys,
                'options' => $instance->options,
            ];
            $indexesByKey[json_encode($index['keys'])] = $index;
        }

        // 2. Collect property-level relation indexes (BelongsToOne, BelongsToMany, HasOne, HasMany)
        foreach ($reflection->getProperties() as $property) {
            foreach ($property->getAttributes() as $attr) {
                $attrClass = $attr->getName(); // e.g. "Nraa\\Database\\Attributes\\BelongsToOne"
                if (!in_array($attrClass, $relationAttributeClasses, true)) {
                    continue;
                }

                $instance = $attr->newInstance();
                if (property_exists($instance, 'foreignKey')) {
                    $index = [
                        'keys'    => [$instance->foreignKey => 1],
                        'options' => [],
                    ];
                    $signature = json_encode($index['keys']);
                    if (!isset($indexesByKey[$signature])) {
                        $indexesByKey[$signature] = $index;
                    }
                }
            }
        }

        self::$indexAttributesCache[$class] = array_values($indexesByKey);
        return self::$indexAttributesCache[$class];
    }

    private function usesSoftDelete(): bool
    {
        $class = static::class;
        if (!array_key_exists($class, self::$usesSoftDeleteCache)) {
            self::$usesSoftDeleteCache[$class] = in_array(SoftDeletable::class, class_uses($this), true);
        }
        return self::$usesSoftDeleteCache[$class];
    }

    private function getHydratablePropertyMap(string $class): array
    {
        if (isset(self::$hydratablePropertyMapCache[$class])) {
            return self::$hydratablePropertyMapCache[$class];
        }

        $properties = (new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC);
        $propertyMap = [];

        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $propertyMap[strtolower($property->getName())] = $property;
        }

        self::$hydratablePropertyMapCache[$class] = $propertyMap;
        return $propertyMap;
    }

    private function getSerializablePublicPropertyNames(string $class): array
    {
        if (isset(self::$serializablePublicPropertyNamesCache[$class])) {
            return self::$serializablePublicPropertyNamesCache[$class];
        }

        $reflection = new \ReflectionClass($class);
        $propertyNames = [];
        $relationAttributeClasses = $this->getRelationAttributeClasses();

        foreach ($reflection->getProperties() as $property) {
            if (!$property->isPublic() || $property->isStatic()) {
                continue;
            }

            $hasRelationAttribute = false;
            foreach ($property->getAttributes() as $attr) {
                if (in_array($attr->getName(), $relationAttributeClasses, true)) {
                    $hasRelationAttribute = true;
                    break;
                }
            }

            if ($hasRelationAttribute) {
                continue;
            }

            $propertyNames[] = $property->getName();
        }

        self::$serializablePublicPropertyNamesCache[$class] = $propertyNames;
        return $propertyNames;
    }

    private function getRelationAttributeClasses(): array
    {
        return [
            \Nraa\Database\Attributes\BelongsToOne::class,
            \Nraa\Database\Attributes\BelongsToMany::class,
            \Nraa\Database\Attributes\HasOne::class,
            \Nraa\Database\Attributes\HasMany::class,
        ];
    }
}
