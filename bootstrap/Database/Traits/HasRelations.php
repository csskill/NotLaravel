<?php

namespace Nraa\Database\Traits;

use MongoDB\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use \Nraa\Database\Attributes\{
    HasMany,
    HasOne,
    BelongsToOne,
    BelongsToMany
};

trait HasRelations
{

    protected $belongsTo;
    protected $hasMany;
    protected $hasOne;
    protected $belongsToMany;


    /**
     * Eager load a relation.
     * 
     * @param string $relationName the name of the relation to load
     * @return mixed the relation
     */
    protected function with(string $relationName)
    {
        parent::$relationName();
        return $this;
    }


    /**
     * Finds a related document that has a one-to-one relationship with the current object.
     *
     * @param string $className the name of the class to find the related document
     * @return mixed the related document, or null if not found
     */
    protected function hasOne(string $className)
    {
        $this->hasOne = $className;
        [$primary_key, $foreign_key] = $this->getForeignAndPrimaryKey($className, HasOne::class);
        $instance = new $className();
        $collectionName = $instance->getCollection()->getCollectionName();
        return parent::getDb()->getCollection($collectionName)
            ->findOne([$foreign_key => (string) $this->$primary_key], ['typeMap' => ['root' => $className]]);
    }


    /**
     * Get a collection of related documents that have a one-to-many relationship with the current object.
     * 
     * @param string $className the name of the class to find the related documents
     * @return \Doctrine\Common\Collections\ArrayCollection the related documents
     */
    protected function hasMany(string $className)
    {
        $this->hasMany = $className;
        [$primary_key, $foreign_key] = $this->getForeignAndPrimaryKey($className, HasMany::class);
        $instance = new $className();
        $collectionName = $instance->getCollection()->getCollectionName();
        return new ArrayCollection(
            parent::getDb()->getCollection($collectionName)
                ->find([$foreign_key => (string) $this->$primary_key], ['typeMap' => ['root' => $className]])
                ->toArray()
        );
    }


    /**
     * Get a related document that has a belongs-to relationship with the current object.
     * 
     * @param string $className the name of the class to find the related document
     * @return mixed the related document, or null if not found
     */
    protected function belongsTo(string $className)
    {
        $this->belongsTo = $className;
        [$primary_key, $foreign_key] = $this->getForeignAndPrimaryKey($className, BelongsToOne::class);
        $instance = new $className();
        $collectionName = $instance->getCollection()->getCollectionName();
        //dd($primary_key, $foreign_key, $this->$foreign_key); _id, match_id, some id   
        return parent::getDb()
            ->getCollection($collectionName)
            ->findOne([$primary_key => new \MongoDb\BSON\ObjectId($this->$foreign_key)], ['typeMap' => ['root' => $className]]);
    }


    /**
     * Returns a collection of related documents that have a many-to-many relationship with the current object.
     * 
     * @param string $className the name of the class to find the related documents
     * @return \Doctrine\Common\Collections\ArrayCollection the related documents
     */
    protected function belongsToMany(string $className)
    {
        $this->hasMany = $className;

        // Get the attribute to extract pivot table information
        $reflection = new \ReflectionClass($this);
        $pivotTable = null;
        $pivotForeignKey = null;
        $pivotRelatedKey = null;

        foreach ($reflection->getProperties() as $property) {
            foreach ($property->getAttributes(BelongsToMany::class) as $attr) {
                $args = $attr->getArguments();
                $pivotTable = $args['pivotTable'] ?? $args[3] ?? null;
                $pivotForeignKey = $args['pivotForeignKey'] ?? $args[4] ?? null;
                $pivotRelatedKey = $args['pivotRelatedKey'] ?? $args[5] ?? null;
                break 2;
            }
        }

        if (!$pivotTable || !$pivotForeignKey || !$pivotRelatedKey) {
            // Fallback to direct query if no pivot table specified
            [$primary_key, $foreign_key] = $this->getForeignAndPrimaryKey($className, BelongsToMany::class);
            $instance = new $className();
            $collectionName = $instance->getCollection()->getCollectionName();
            return new ArrayCollection(
                parent::getDb()->getCollection($collectionName)
                    ->find([$foreign_key => (string) $this->$primary_key], ['typeMap' => ['root' => $className]])
                    ->toArray()
            );
        }

        // Query the pivot table to get related IDs
        $pivotRecords = parent::getDb()->getCollection($pivotTable)
            ->find([$pivotForeignKey => $this->id])
            ->toArray();

        if (empty($pivotRecords)) {
            return new ArrayCollection([]);
        }

        // Extract the related IDs from pivot records
        $relatedIds = array_map(function ($record) use ($pivotRelatedKey) {
            return $record->{$pivotRelatedKey};
        }, $pivotRecords);

        // Query the target collection with the related IDs
        $instance = new $className();
        $collectionName = $instance->getCollection()->getCollectionName();

        $results = parent::getDb()->getCollection($collectionName)
            ->find(['_id' => ['$in' => $relatedIds]], ['typeMap' => ['root' => $className]])
            ->toArray();

        return new ArrayCollection($results);
    }


    /**
     * Gets the foreign key and primary key of an attribute.
     *
     * @param string $className the name of the class to find the attribute
     * @param string $attributeClass the class of the attribute to find
     * @return array an array containing the primary key and foreign key
     */
    protected function getForeignAndPrimaryKey($className, $attributeClass)
    {
        $reflection = new \ReflectionClass($this);
        foreach ($reflection->getProperties() as $property) {
            foreach ($property->getAttributes($attributeClass) as $attr) {
                $args = $attr->getArguments();
                // Handle both named and positional arguments
                $primaryKey = $args['primaryKey'] ?? $args[2] ?? null;
                $foreignKey = $args['foreignKey'] ?? $args[1] ?? null;

                if ($primaryKey === null || $foreignKey === null) {
                    throw new \RuntimeException("Missing primaryKey or foreignKey in attribute {$attributeClass}");
                }

                return [$primaryKey, $foreignKey];
            }
        }
    }
}
