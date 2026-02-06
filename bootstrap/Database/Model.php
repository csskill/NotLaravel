<?php

namespace Nraa\Database;

use DateTime;
use DateTimeImmutable;
use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Document;
use Nraa\Database\Helpers\DoctrineBridge;
use Doctrine\ODM\MongoDB\Configuration;
use MongoDB\Collection;
use Nraa\Database\Traits\MongoDBTransactionalTrait;
use MongoDB\BSON\Unserializable;
use Nraa\Database\Drivers\MongoDBDriver;
use MongoDB\BSON\Persistable;

class Model implements Persistable
{

    use MongoDBTransactionalTrait;

    public ?\MongoDB\BSON\ObjectId $id = null;
    public ?\MongoDB\BSON\UTCDateTime $createdAt = null;
    public ?\MongoDB\BSON\UTCDateTime $updatedAt = null;
    protected static $collection = '';
    protected $db;

    // new: hold an instance of MongoDB\Collection
    protected Collection $collectionInstance;

    /**
     * Initialize the model with the database manager and collection name.
     *
     * The collection name is taken from the static variable $collection of the
     * class that extends this class.
     *
     * @see \MongoDB\Collection
     * @see \Nraa\Database\Drivers\MongoDBDriver
     */
    function __construct()
    {
        $this->db = MongoDBDriver::getInstance();

        // create a Collection instance rather than extending it
        $this->collectionInstance = new Collection(
            MongoDBDriver::getManager(),
            MongoDBDriver::getInstance()->getDatabaseName(),
            static::$collection,
            ['typeMap' => ['root' => static::class]]
        );
    }

    /**
     * Returns the MongoDB\Collection object associated with this model.
     * Lazily initializes the collection instance if not already set.
     *
     * @return MongoDB\Collection
     */
    public function getCollection()
    {
        if (!isset($this->collectionInstance)) {
            $this->collectionInstance = new Collection(
                MongoDBDriver::getManager(),
                MongoDBDriver::getInstance()->getDatabaseName(),
                static::$collection,
                ['typeMap' => ['root' => static::class]]
            );
        }
        return $this->collectionInstance;
    }

    /**
     * Return the database manager.
     *
     * If the model has been initialized with a database manager, this will
     * return that manager. Otherwise, it will return the result of calling
     * MongoDBDriver::getInstance().
     *
     * @return MongoDBDriver
     */
    public function getDb()
    {
        return ($this->db instanceof MongoDBDriver) ? $this->db : MongoDBDriver::getInstance();
    }


    /**
     * Magic getter for properties and relational attributes.
     * 
     * Checks if a property with the given name exists. If it does, returns the value of the property.
     * If the property does not exist, checks if a method with the given name exists and has a relational attribute.
     * If the method exists, calls the method and returns the result.
     * If neither the property nor the method exists, returns null.
     * 
     * @param string $name the name of the property or relational attribute to retrieve
     * @return mixed the value of the property or relational attribute, or null if not found
     */
    public function __get($name)
    {
        if (property_exists($this, $name)) {
            return $this->$name;
        }

        //check if a method with corresponding exists and has a relational attribute 
        if (method_exists($this, $name)) {
            return $this->$name();
        }

        if ($name === 'id') {
            return $this->_id;
        }

        return null;
    }


    /**
     * Populate the public properties of the object from an associative array.
     *
     * This method is used by the MongoDB driver to convert a document retrieved
     * from the database into an object.
     *
     * @param array $data an associative array of the public properties of the object.
     */
    public function bsonUnserialize(array $data): void
    {
        $this->setPublicPropertiesFromArray($this::class, $data);
    }


    /**
     * Return an associative array of the public properties of the object.
     *
     * This method is used by the MongoDB driver to convert the object to a
     * document that can be inserted into the database.
     *
     * @return array An associative array of the public properties of the object.
     */
    public function bsonSerialize(): array
    {
        return $this->getPublicPropertiesAsArrayOfObjects();
    }


    /**
     * Create a new instance of the model and populate its public properties
     * from the given associative array.
     *
     * @param array $data an associative array of the public properties of the object.
     * @return static a new instance of the model with its public properties populated.
     */
    public static function create(array $data)
    {
        $instance = new static();
        $instance->setPublicPropertiesFromArray(static::class, $data);
        $instance->createdAt = new \MongoDB\BSON\UTCDateTime();
        $instance->updatedAt = new \MongoDB\BSON\UTCDateTime();
        return $instance;
    }
}
