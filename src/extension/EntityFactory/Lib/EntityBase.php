<?php

abstract class EntityBase implements iLettuceExtension {
    static function ExtGetOptions() {
    }

    protected abstract function AssocEnableCounter();
    protected abstract function AssocEnableDelta();
    protected abstract function AssocFieldInvalidates();
    protected abstract function AssocFieldSortable();
    protected abstract function getByKey($query);  // Entities that do not support this should throw self::EXCEPTION_ENTITY_CANNOT_GET_BY_KEY
    public abstract function register(Array $data);
    
    const
        EXCEPTION_ENTITY_ID_INVALID = 'EntityFactory::IdInvalid',
        EXCEPTION_ENTITY_CONSTRAINT = 'EntityFactory::ConstraintViolation',
        EXCEPTION_ENTITY_SCHEMA_NOT_CONFIGURED = 'EntityFactory::EntitySchemaConstantNotConfigured',      // 8d50840f
        EXCEPTION_ENTITY_INVALID_SCOPE = 'EntityFactory::InvalidScope',
        EXCEPTION_ENTITY_INVALID_SCHEMA_ATTRIBUTE = 'EntityFactory::InvalidSchemaAttribute',
        EXCEPTION_ENTITY_UPDATE_FAILED = 'EntityFactory::UpdateFailed',
        EXCEPTION_ENTITY_CANNOT_REGISTER = 'EntityFactory::RegistrationNotAvailable',
        EXCEPTION_ENTITY_CANNOT_GET_BY_KEY = 'EntityFactory::CannotGetByKey',
        EXCEPTION_ENTITY_INVALID_REGISTRATION = 'EntityFactory::InvalidRegistration',

        STORAGE_TABLE = 'entity',
        STORAGE_KEY = 'Entity',
        STORAGE_KEY_SCHEMA = 'EntitySchema',

        ATTR_SCHEMA_ID = 'schema_id',
        COL_SCHEMA_EXTENSION = 'schema_extension',
        ATTR_TIME_CREATED = 'time_created',
        ATTR_TIME_UPDATED = 'time_updated',

        ENTITY_SCHEMA_ID = 0,
        ENTITY_SCHEMA_EXTENSION_TABLE = null,
        ENTITY_TYPE_NAME = null;

    /** @var  Storage storage */
    protected $storage;

    protected
        $assoc, $changeset, $schema, $schema_changes, $schema_extension, $scopes, $scope_types, $change_callback, $init = false;

    private final function createSchema() {
        $this->init = false;
        $this->schema           = [
            EntityFactory::ATTR_ENTITY_ID        => null,
            EntityBase::ATTR_SCHEMA_ID    => $this::ENTITY_SCHEMA_ID,
            EntityBase::ATTR_TIME_CREATED => time(),
            EntityBase::ATTR_TIME_UPDATED => null
        ];
        $this->schema_changes = [];
        $this->schema_extension = [];
        $this->defineSchema();
    }

    private final function createScope() {
        $this->scope_types = [
            EntityFactory::SCOPE_ENTITY,
            EntityFactory::SCOPE_PRIVATE,
            EntityFactory::SCOPE_PUBLIC
        ];

        $this->scopes = [
            EntityFactory::SCOPE_ENTITY  => [        // EntityFactory ID, Schema ID and Timestamps
                EntityFactory::ATTR_ENTITY_ID,
                EntityBase::ATTR_SCHEMA_ID,
                EntityBase::ATTR_TIME_CREATED,
                EntityBase::ATTR_TIME_UPDATED
            ],
            EntityFactory::SCOPE_PRIVATE => [],      // Data used on the backend, not transmitted (generally) to the front end
            EntityFactory::SCOPE_PUBLIC  => []       // Data that is safe to send to the front end for user presentation/consumption
        ];
    }

    protected final function addSchemaAttributes($scope, $extension) {
        if (!isset($scope, $this->scopes)) {
            throw new CodedException(self::EXCEPTION_ENTITY_INVALID_SCOPE);
        }

        foreach ($extension as $attr => $default_value) {
            $this->schema[$attr] = $default_value;      // Push the value into the schema array
            array_push($this->scopes[$scope], $attr);   // Push the attribute into the scope protection array
            array_push($this->schema_extension, $attr);
        }
    }

    protected function defineSchema() {
    }

    public function requiredPermissions() {

        // TODO: Should this all be stored in the DB / Cache (spread)?
        $perm_template = [
            'e' => [
                Association::UNRESTRICTED => [      // If requesting entity has this association against the target entity
                    EntityFactory::ATTR_ENTITY_ID,         // It can load these attributes.
                    EntityFactory::ATTR_SCHEMA_ID,
                    EntityFactory::ATTR_SCHEMA_NAME,
                    EntityBase::ATTR_TIME_CREATED,
                    EntityBase::ATTR_TIME_UPDATED
                ],
                Association::GROUP__MEMBER_OF => [ // Alternatively, if it has this association
                    Common::ATTR_IMAGE_URL              // It can see this attribute
                ],
                //... Other associations could be created? TODO: Think about this scenario
            ],
            'l' => [
                Association::UNRESTRICTED => [
                    // Nothing available?
                ],
                Association::GROUP__MEMBER_OF => [  // If the requesting entity has the 'member of' association for this entity
                    Association::GROUP__CAN_VIEW    // It is granted the ability to view the group membership association
                ]

            ]
        ];

        return [
            'e' => [
                EntityFactory::SCOPE_PUBLIC => [
                    Association::UNRESTRICTED
                ],
                EntityFactory::SCOPE_PRIVATE => []
            ],
            'l' => [        // TODO: need read / modify [/delete?] perms
                Association::GROUP__HAS_MEMBER => [
                    Association::GROUP__MEMBER_OF,
                    Association::GROUP__CAN_VIEW,
                    Association::GROUP__OWNER_OF
                ]
            ]
        ];
    }

    public function __construct($params, $config) {
        $this->storage = LettuceGrow::extension('Storage');

        if ($this::ENTITY_SCHEMA_ID === 0) {
            throw new CodedException(EntityBase::EXCEPTION_ENTITY_SCHEMA_NOT_CONFIGURED);
        }

        $this->createScope();
        $this->createSchema();

//        $this->change_callback = $change_callback;
        $entity_id = Common::getIfSet($params[EntityFactory::ATTR_ENTITY_ID]);
        if ($entity_id !== null) {
            if (is_numeric($entity_id)) {
                $this->schema[EntityFactory::ATTR_ENTITY_ID] = $entity_id;
            } else {
                $this->getByKey($entity_id);
            }
        }

    }


    public function getId() {
        return $this->schema[EntityFactory::ATTR_ENTITY_ID];
    }

    public function setId($entity_id) {
        $this->createScope();
        $this->createSchema();
        $this->schema[EntityFactory::ATTR_ENTITY_ID] = $entity_id;
        return $this;
    }

    // Schema Value Get/Set

    public function setAttributes($attributes, $track_changes = true) {
        $this->init();

        if (is_string($attributes)) {
            $attributes = (array)json_decode($attributes, true);
        }

        foreach ($attributes as $attr => $value) {
            $this->setAttribute($attr, $value, $track_changes);
        }
    }

    public function getSchemaId() {
        return $this::ENTITY_SCHEMA_ID;
    }

    public function getAttributes($scopes = EntityFactory::SCOPE_PUBLIC, $flatten = false) {
        $this->init();
        $data = Array();

        foreach ($this->scope_types as $scope_type) {
            if ($scopes & $scope_type || $scopes == EntityFactory::SCOPE_ALL) {
                if ($flatten) {
                    $data += $this->getScope($scope_type);
                } else {
                    $data[$scope_type] = $this->getScope($scope_type);
                }
            }
        }
        return $data;
    }

    public function setAttribute($attr, $value, $track_changes = true) {
        $this->init();

        if ($this::ENTITY_SCHEMA_ID === 0 || array_key_exists($attr, $this->schema)) {
            $this->schema[$attr] = $value;
            if ($track_changes && !in_array($attr, $this->schema_changes)) {
                array_push($this->schema_changes, $attr);
            }
        } else {
            throw new CodedException(EntityBase::EXCEPTION_ENTITY_INVALID_SCHEMA_ATTRIBUTE, null, $this::ENTITY_SCHEMA_ID . '|' . $attr);
        }
    }

    /**
     * @param string $attr
     * @param int    $scope
     *
     * @return int
     *
     * @throws CodedException
     */
    public function getAttribute($attr, $scope = EntityFactory::SCOPE_PUBLIC) {
        if (!isset($scope, $this->scopes)) {
            throw new CodedException(self::EXCEPTION_ENTITY_INVALID_SCOPE);
        }

        $this->init();

        if (in_array($attr, $this->scopes[$scope]) && array_key_exists($attr, $this->schema)) {
            return $this->schema[$attr];
        }

        throw new CodedException(self::EXCEPTION_ENTITY_INVALID_SCHEMA_ATTRIBUTE, null, $attr);
    }

    public function update($update_schema_extension = true, $full_update = false) {
        $update = [
            EntityBase::ATTR_TIME_UPDATED => floor(microtime(true) * 1000)
        ];

        if ($update_schema_extension && $this::ENTITY_SCHEMA_EXTENSION_TABLE != null && count($this->schema_extension) > 0) {
            foreach ($this->schema_extension as $attr) {
                if ($full_update || in_array($attr, $this->schema_changes)) {
                    $update[$attr] = $this->schema[$attr];
                }
            }

            $query = 'UPDATE entity JOIN ' . $this::ENTITY_SCHEMA_EXTENSION_TABLE . ' USING (entity_id) SET ' .
                implode(',', array_map(
                        function ($value) {
                            return $value . '=:' . $value;
                        }, array_keys($update))
                ) . ' WHERE entity_id=:entity_id';
        } else {
            $query = 'UPDATE entity SET time_updated=:time_updated WHERE entity_id=:entity_id';
        }

        $update[EntityFactory::ATTR_ENTITY_ID] = $this->schema[EntityFactory::ATTR_ENTITY_ID];

        try {
            $this->storage->update(
                $this::STORAGE_KEY . '.' . $this->schema[EntityFactory::ATTR_ENTITY_ID],
                null,
                $query,
                $update
            );
//            $this->db->prepare($query)->execute($update);
//            $this->db->queryCacheInvalidate($this::STORAGE_KEY . '.' . $this->schema[EntityFactory::ATTR_ENTITY_ID]);

/*
 * Frustration! - This is were we need to refactor again to give the entity schema's access to the EntityAssociation engine. Sigh.
 *
 * NOPE NOPE NOPE, see comment below, modify the change tracking callback instead. Handle it all in EntityFactory :-)
             $assoc_update_list = EntityAssoc::assocSortAttrList(self::ENTITY_SCHEMA_ID);
            if ($assoc_update_list != null) {
                foreach ($assoc_update_list as $attr => $assoc_list) {
                    if (in_array($attr, $$this->schema_changes)) {
                        $list = $
                    }
                }
            }
*/
/* TODO change to use changeset locally */
            call_user_func($this->change_callback, $this->schema[EntityFactory::ATTR_ENTITY_ID], Changeset::OP_UPDATE, $this::ENTITY_SCHEMA_ID, $this->schema_changes, $update[EntityBase::ATTR_TIME_UPDATED]); // Disregard the above, send the changed fields and the schema_id back in this callback. Done! :-)
            $this->schema_changes = [];
        } catch (Exception $e) {
            throw new CodedException(EntityBase::EXCEPTION_ENTITY_UPDATE_FAILED, $e, $this->schema[EntityFactory::ATTR_ENTITY_ID]);
        }
    }

    public function deregister($delete_volatile = true) {
        // Todo: execute the on change callback here so that associations etc can be cleaned up.

        $this->storage->delete(
            ($delete_volatile) ? $this::STORAGE_KEY . '.' . $this->schema[EntityFactory::ATTR_ENTITY_ID] : null,
            'DELETE FROM entity WHERE entity_id=:entity_id',
            [EntityFactory::ATTR_ENTITY_ID => $this->schema[EntityFactory::ATTR_ENTITY_ID]]
        );
    }

    /**
     * @param null $schema
     *
     * @returns int
     *
     * @throws CodedException
     */
    protected function baseRegister($insert_schema_extension = true, $do_callback = true) {
        $now = microtime(true);
        $this->schema[EntityBase::ATTR_TIME_UPDATED] = floor($now * 1000);
        $this->schema[EntityBase::ATTR_TIME_CREATED] = floor($now);

        try {
            $this->storage->enterTransaction();

            $this->schema[EntityFactory::ATTR_ENTITY_ID] = $this->storage->createDurable(
                self::STORAGE_TABLE, [
                    EntityBase::ATTR_SCHEMA_ID    => $this::ENTITY_SCHEMA_ID,
                    EntityBase::ATTR_TIME_CREATED => $this->schema[EntityBase::ATTR_TIME_CREATED],
                    EntityBase::ATTR_TIME_UPDATED => $this->schema[EntityBase::ATTR_TIME_UPDATED]
                ], [
                    Storage::OPT_DURABLE_RETURN_ID => true
                ]
            );

            if ($insert_schema_extension && $this::ENTITY_SCHEMA_EXTENSION_TABLE !== null && count($this->schema_extension) > 0) {
                $extension = [
                    EntityFactory::ATTR_ENTITY_ID => $this->schema[EntityFactory::ATTR_ENTITY_ID]
                ];

                foreach ($this->schema_extension as $attr) {
                    $extension[$attr] = $this->schema[$attr];
                }

                $this->storage->createDurable(
                    $this::ENTITY_SCHEMA_EXTENSION_TABLE,
                    $extension
                );
            }

            $this->storage->exitTransaction();
            $this->schema_changes = [];
            if ($do_callback) {
// TODO needed?                call_user_func($this->change_callback, $this->schema[EntityFactory::ATTR_ENTITY_ID], Changeset::OP_ADD, $this::ENTITY_SCHEMA_ID);
            }
            return $this->schema[EntityFactory::ATTR_ENTITY_ID];
        } catch (PDOException $e) {
            $this->storage->exitTransaction(false);
            switch ($e->getCode()) {
                case 23000: // Integrity constraint violation: 1062 Duplicate entry
                    throw new CodedException(EntityBase::EXCEPTION_ENTITY_CONSTRAINT, $e);
                    break;
                default:
                    throw new CodedException(CodedException::EXCEPTION_UNKNOWN, $e);
                    break;
            }
        }
    }

    final public function get($query) {
        $this->init = true;

        if (!is_numeric($query)) {
            return $this->getByKey($query);
        }

        return $this->getById($query);
    }

    /**
     * @param $entity_id
     *
     * @returns int entity_id
     *
     * @throws CodedException
     */
    protected function getById($entity_id) {
        if ($this::ENTITY_SCHEMA_EXTENSION_TABLE !== null) {    // Schema is known
            $result = $this->storage->retrieve(
                self::STORAGE_KEY . '.' . $entity_id,
                'SELECT * FROM entity JOIN ' . $this::ENTITY_SCHEMA_EXTENSION_TABLE . ' USING (entity_id) WHERE entity_id = :entity_id',
                ['entity_id' => $entity_id],
                [Storage::OPT_DURABLE_COLLAPSE_SINGLE => true]
            );

            if ($result) {
                $this->setAttributes($result, false);
                return $this->schema[EntityFactory::ATTR_ENTITY_ID];
            } else {
                throw new CodedException(EntityFactory::EXCEPTION_ENTITY_ID_NOT_FOUND, null, $entity_id);
            }
        } else {
            throw new CodedException(self::EXCEPTION_ENTITY_SCHEMA_NOT_CONFIGURED, null, $entity_id);
        }
    }

    final private function getScope($scope) {
        $data = [];
        foreach ($this->scopes[$scope] as $attr) {
            $data[$attr] = $this->getAttribute($attr, $scope);
        }
        return $data;
    }

    protected function init() {
        if (!$this->init) {
            $this->init = true;
            if (Common::getIfSet($this->schema[EntityFactory::ATTR_ENTITY_ID])) {
                $this->getById($this->schema[EntityFactory::ATTR_ENTITY_ID]);
            }
        }
    }

    // Association Library On-Demand Loader
    /**
     * @return Association
     */
    public function assoc() {
        if (!$this->assoc) {
            $this->assoc = LettuceGrow::extension('Association', [
                EntityFactory::ATTR_ENTITY_ID => $this->getId(),
                EntityFactory::ATTR_ENTITY_SCHEMA_EXTENSION_TABLE => $this::ENTITY_SCHEMA_EXTENSION_TABLE
            ], [iLettuceExtension::OPTION_BASE_PATH => __DIR__ . 'EntityBase.php/']);
        }
        return $this->assoc;
    }

}

class EntityBaseDiscover extends EntityBase {
    const
        ENTITY_SCHEMA_ID = -1;

    protected function getById($entity_id)
    {
        $result = $this->storage->retrieve(
            self::STORAGE_KEY . '.' . $entity_id,
            'CALL _getEntityDetectSchema(:entity_id)',
            ['entity_id' => $entity_id], [
                Storage::OPT_DURABLE_COLLAPSE_SINGLE => true,
                Storage::OPT_DURABLE_DYNAMIC_RESULT_SIZE => true
            ]
        );

        if ($result) {
            return $result;
        } else {
            throw new CodedException(EntityFactory::EXCEPTION_ENTITY_ID_NOT_FOUND, null, $entity_id);
        }
    }

    protected function AssocEnableCounter() {}
    protected function AssocEnableDelta() {}
    protected function AssocFieldInvalidates() {}
    protected function AssocFieldSortable() {}

    protected function getByKey($query) {
        throw new CodedException(self::EXCEPTION_ENTITY_CANNOT_GET_BY_KEY, null, (!is_array($query) ?? json_encode($query)));
    }

    public function register(Array $data) {
        throw new CodedException(self::EXCEPTION_ENTITY_CANNOT_REGISTER, null, json_encode($data));
    }
}

