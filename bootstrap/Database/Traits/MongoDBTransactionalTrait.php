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

    /**
     * Summary of applySoftDeleteToFilter
     * @param array $filter
     * @return array
     */
    protected function applySoftDeleteToFilter(array $filter = []): array
    {
        if (in_array(SoftDeletable::class, class_uses($this))) {
            // If the caller asked to include trashed records, skip adding the filter.
            if (!($this->includeDeleted ?? false)) {
                if (!array_key_exists('deleted', $filter)) {
                    $filter['deleted'] = false;
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
            $doc = $this->toDocument();
            unset($doc['_id']);
            unset($doc['id']);
            $this->createdAt = new \MongoDB\BSON\UTCDateTime();
            $this->updatedAt = new \MongoDB\BSON\UTCDateTime();
            $result = $this->getCollection()->insertOne($doc);
            $this->id = $result->getInsertedId();
        } else {
            $this->touch();
            $doc = $this->toDocument();
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
        $cursor = $this->getCollection()->find($this->filter, $this->options);

        // After running the query, reset includeDeleted to default false so subsequent queries won't unexpectedly include trashed items
        if (in_array(SoftDeletable::class, class_uses($this))) {
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
        if (in_array(SoftDeletable::class, class_uses($this))) {
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
        $instance = new static();
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
        $instance = new static();

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
        if (in_array(SoftDeletable::class, class_uses($this))) {
            $this->includeDeleted = false;
        }

        if (!$result) {
            return null;
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
        $instance = new static();

        // If caller passed an array filter, apply soft-delete via the instance
        if (is_array($filter)) {
            $filter = $instance->applySoftDeleteToFilter($filter);
        } else {
            // if object filter and instance has an accumulated filter, merge into array form
            if (empty($filter) && !empty($instance->filter)) {
                $filter = $instance->filter;
            }
        }

        $result = $instance->findOneInstance($filter, $options);

        // ensure includeDeleted flag reset on instance (defensive)
        if (in_array(SoftDeletable::class, class_uses($instance))) {
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
            $optionsSignature = json_encode($index['options']);
            $signature        = $keysSignature . '::' . $optionsSignature;

            $uniqueDesired[$signature] = $index;
        }

        // Fetch existing indexes from MongoDB
        $existingIndexes = iterator_to_array($this->getCollection()->listIndexes());

        $existingSignatures = [];
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
            $optionsSignature = json_encode($relevantOptions);

            $signature = $keysSignature . '::' . $optionsSignature;

            $existingSignatures[$signature] = $name;
        }

        // Create missing indexes
        foreach ($uniqueDesired as $signature => $index) {
            if (!isset($existingSignatures[$signature])) {
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
        if (in_array(SoftDeletable::class, class_uses($this)) && empty($this->force)) {
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
        if (in_array(SoftDeletable::class, class_uses($this))) {
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
        $reflection = new \ReflectionClass(static::class);
        $stdObj = new \stdClass();
        foreach ($reflection->getProperties() as $property) {
            foreach ($property->getAttributes() as $attr) {
                $attrClass = $attr->getName(); // e.g. "Nraa\\Database\\Attributes\\BelongsToOne"

                if (in_array($attrClass, [
                    \Nraa\Database\Attributes\BelongsToOne::class,
                    \Nraa\Database\Attributes\BelongsToMany::class,
                    \Nraa\Database\Attributes\HasOne::class,
                    \Nraa\Database\Attributes\HasMany::class,
                ])) {
                    // skip relational attributes
                    continue 2;
                }
            }
            if ($property->isPublic()) {
                $stdObj->{$property->getName()} = $property->getValue($this) ?? null;
            }
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
        $properties =  array_filter(
            (new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC),
            function ($property) {
                return !$property->isStatic() && !$property->isProtected() && $property->isPublic();
            }
        );

        foreach ($array as $key => $value) {
            if ($key == "_id") {
                $this->id = $array[$key];
                continue;
            }
            foreach ($properties as $property) {
                if (strtolower($property->getName()) == strtolower($key)) {
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
            $array = $value instanceof \MongoDB\Model\BSONDocument ? $value->getArrayCopy() : (array) $value;
            return array_map(function ($item) {
                return $this->convertObjectsToArrays($item);
            }, $array);
        } elseif ($value instanceof \MongoDB\Model\BSONArray) {
            return array_map(function ($item) {
                return $this->convertObjectsToArrays($item);
            }, iterator_to_array($value));
        } elseif (is_array($value)) {
            return array_map(function ($item) {
                return $this->convertObjectsToArrays($item);
            }, $value);
        }
        return $value;
    }

    /**
     * Summary of getIndexAttributesFromClass
     * @return array
     */
    protected function getIndexAttributesFromClass(): array
    {
        $reflection = new \ReflectionClass($this);

        $indexes = [];

        // 1. Collect class-level indexes (#[Index])
        foreach ($reflection->getAttributes(\Nraa\Database\Attributes\Index::class) as $attr) {
            /** @var \Nraa\Database\Attributes\Index $instance */
            $instance = $attr->newInstance();

            $indexes[] = [
                'keys'    => $instance->keys,
                'options' => $instance->options,
            ];
        }

        // 2. Collect property-level relation indexes (BelongsToOne, BelongsToMany, HasOne, HasMany)
        foreach ($reflection->getProperties() as $property) {
            foreach ($property->getAttributes() as $attr) {
                $attrClass = $attr->getName(); // e.g. "Nraa\\Database\\Attributes\\BelongsToOne"

                if (in_array($attrClass, [
                    \Nraa\Database\Attributes\BelongsToOne::class,
                    \Nraa\Database\Attributes\BelongsToMany::class,
                    \Nraa\Database\Attributes\HasOne::class,
                    \Nraa\Database\Attributes\HasMany::class,
                ])) {
                    $instance = $attr->newInstance();

                    // All your relation attribute constructors include a foreignKey
                    if (property_exists($instance, 'foreignKey')) {
                        $indexes[] = [
                            'keys'    => [$instance->foreignKey => 1],
                            'options' => [],
                        ];
                    }
                }
            }
        }

        return $indexes;
    }
}
