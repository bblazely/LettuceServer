<?php

class EntityFactory implements iLettuceExtension {
    static function ExtGetDependencies() {
        return ['Storage', 'Changeset'];
    }
    static function ExtGetOptions() {
        return [
            iLettuceExtension::OPTION_INSTANTIATE_AS => iLettuceExtension::INSTANTIATE_SINGLETON
        ];
    }

    const
        EXCEPTION_ENTITY_SCHEMA_INVALID                 = 'EntityFactory::SchemaInvalid',
        EXCEPTION_ENTITY_SCHEMA_NOT_FOUND               = 'EntityFactory::SchemaNotFound',
        EXCEPTION_ENTITY_OWNER_INVALID                  = 'EntityFactory::OwnerInvalid',
        EXCEPTION_ENTITY_MODULE_NOT_FOUND               = 'EntityFactory::ModuleNotFound',
        EXCEPTION_ENTITY_ID_NOT_FOUND                   = 'EntityFactory::NotFound',
        EXCEPTION_ENTITY_ID_INVALID                     = 'EntityFactory::InvalidId',
        EXCEPTION_ENTITY_SCHEMA_ID_MISMATCH             = 'EntityFactory::EntityTypeMismatch',
        EXCEPTION_ENTITY_ASSOC_ALREADY_EXISTS           = 'EntityFactory::AssociationAlreadyExists',
        EXCEPTION_ENTITY_DETECT_SCHEMA_NO_ID            = 'EntityFactory::DetectSchemaWithoutId',
        EXCEPTION_ENTITY_DETECT_SCHEMA_NO_NUMERIC_ID    = 'EntityFactory::DetectSchemaWithoutNumericId',

        SCOPE_ENTITY            = 1,
        SCOPE_PUBLIC            = 2,
        SCOPE_PRIVATE           = 4,
        SCOPE_ALL               = -1,

        ATTR_ENTITY_ID          = 'entity_id',
        ATTR_SCHEMA_ID          = 'schema_id',
        ATTR_SCHEMA_NAME        = 'schema_name',
        ATTR_ENTITY_SCHEMA_EXTENSION_TABLE  = 'schema_table',

        SCHEMA_DETECT           = 0,
        SCHEMA_USER             = 100,
        SCHEMA_GROUP            = 200,
        SCHEMA_GHOST            = 300,
        SCHEMA_PUBLIC_ID        = 400,
        SCHEMA_LOGIN_NATIVE     = 500,
        SCHEMA_LOGIN_EXTERNAL   = 600,
        SCHEMA_MESSAGE          = 700,
        SCHEMA_MESSAGE_REQUEST  = 701;

    private static
        $SCHEMA_NAMES = Array(
            self::SCHEMA_USER            => 'User',
            self::SCHEMA_GROUP           => 'Group',
            self::SCHEMA_GHOST           => 'Ghost',
            self::SCHEMA_PUBLIC_ID       => 'PublicId',
            self::SCHEMA_LOGIN_NATIVE    => 'LoginNative',
            self::SCHEMA_LOGIN_EXTERNAL  => 'LoginExternal',
            self::SCHEMA_MESSAGE         => 'Message',
            self::SCHEMA_MESSAGE_REQUEST => 'MessageRequest'
        );/*,
        $SCHEMA_TABLES = Array(
            self::SCHEMA_PERSON          => 'entity_schema_person',
            self::SCHEMA_GROUP           => 'entity_schema_group',
            self::SCHEMA_GHOST           => 'entity_schema_ghost',
            self::SCHEMA_PUBLIC_ID       => 'entity_schema_public_id',
            self::SCHEMA_LOGIN_NATIVE    => 'entity_schema_login_native',
            self::SCHEMA_LOGIN_SOCIAL    => 'entity_schema_social_login',
            self::SCHEMA_MESSAGE         => 'entity_schema_message',
            self::SCHEMA_MESSAGE_REQUEST => 'entity_schema_message_request'
        );*/

    private
        $storage, $assoc,
        $entity_change_callback;

    /** @var  Changeset changeset */
    private $changeset;

    /** @var  Association association */
    private $association;

    /**
     * @param $params
     * @param $config
     * @internal param $di
     * @internal param Storage $di_storage
     * @internal param Association $di_assoc
     * @internal param Changeset $di_changeset
     */
    public function __construct($params, $config) {

        $this->storage = LettuceGrow::extension('Storage');
        $this->changeset = LettuceGrow::extension('Changeset');

       /* $this->entity_change_callback = function($entity_id, $change = Changeset::OP_UPDATE, $schema_id = null, $changed_fields = null, $time_updated = null) {
            $this->changeset->trackChange($entity_id, $change, null, null, $time_updated);

            // For Updates - How to handle deletes? Assume all fields changed?
            $this->assoc->entityUpdateCascade($entity_id, $schema_id, $changed_fields);

            // Check for linked attribute updates and pass on the update.
            $links = $this->assoc->getList($entity_id, Association::LINK_PROVIDES);
            foreach($links as $link) {

            }

            // TODO Pass the field list to the association engine to see if any assoc's need to be invalidated.
            // Remember on an UPDATE invalidation only occurs if there are changed_fields.
            // If it's an ADD it happens regardless of what changed
        };*/
    }

    /*public static function schemaTable($schema_id) {
        return Common::getIfSet(self::$SCHEMA_TABLES[$schema_id]);
    }*/

    /**
     * @param int  $entity_id
     *
     * @param int  $schema_id
     *
     * @return EntityBase
     * @throws CodedException
     */
    public function get($entity_id = null, $schema_id = self::SCHEMA_DETECT, $entity_data = null) {
        if ($entity_data && !is_array($entity_data)) {
            $entity_data = json_decode($entity_data, true);
        }
        if ($schema_id === self::SCHEMA_DETECT || !$schema_id) {
            if ($entity_data === null) {
                if ($entity_id === null) {
                    throw new CodedException(self::EXCEPTION_ENTITY_DETECT_SCHEMA_NO_ID);
                } else {
                    if (!is_numeric($entity_id)) {
                        throw new CodedException(self::EXCEPTION_ENTITY_DETECT_SCHEMA_NO_NUMERIC_ID);
                    }
                }

                // At this point the entity_id is numeric and auto-detect schema is enabled
                $entity_data = $this->loadSchemaClass('EntityBaseDiscover', $entity_id)->getAttributes(EntityFactory::SCOPE_ALL, true);
            }

            if (is_array($entity_data) && array_key_exists(EntityFactory::ATTR_SCHEMA_ID, $entity_data)) {
                $schema_id = $entity_data[EntityFactory::ATTR_SCHEMA_ID];
                $schema_name = $this->getSchemaName($schema_id);
            } else {
                throw new CodedException(self::EXCEPTION_ENTITY_ID_NOT_FOUND, null, 'ID: '.$entity_id);
            }
        } else {
            if (!is_numeric($schema_id)) {
                $schema_name = $schema_id;
                $schema_id = $this->getSchemaId($schema_name);
            } else {
                $schema_name = $this->getSchemaName($schema_id);
            }
        }

        $schema_class = 'Entity' . $schema_name;
        $entity = $this->loadSchemaClass($schema_class, $entity_id);
        // Schema type has been specified and validated. Instantiate it.

        /** @var EntityBase $entity */


//        $entity = new $schema_class($this->storage, $this->assoc, $this->entity_change_callback, $entity_id);
        if ($entity_data) {
            $entity->setAttributes($entity_data, false);
        }

        if ($entity_id && $entity::ENTITY_SCHEMA_ID != $schema_id) {
            throw new CodedException(self::EXCEPTION_ENTITY_SCHEMA_ID_MISMATCH, null, $schema_id . '!=' . $entity::ENTITY_SCHEMA_ID);
        } else {
            return $entity;
        }
    }

    public function deleteEntity($entity_id) {
        // Harvest all associations from the DB (they won't be around much longer)

        // TODO This should really be an EntityAssoc:: function getAll($entity_id)
        $assoc_list = $this->storage->retrieve(
            null,
            'SELECT entity_id1, assoc_type, entity_id2 FROM association WHERE entity_id1=:entity_id',
            [EntityFactory::ATTR_ENTITY_ID => $entity_id]
        );

        // Delete the entity (will also remove all linked associations)
        $this->get($entity_id)->deregister();

        $this->changeset->trackChange($entity_id, Changeset::OP_REMOVE);  // Todo: move this to the deregister function, it can call the standard callback that add/update do

        // Todo : everything below here should also move to the assoc manager, it shouldn't be called here. Executed via the callback upon entity deregistration.
        // Remove REVERSE associations from the STORAGE. Forward associations won't matter now that the entity is gone. The DB is cleaned up automatically upon entity removal.
        foreach ($assoc_list as $assoc) {
            $this->assoc->delete($assoc['entity_id2'], $assoc['assoc_type'], $assoc['entity_id1'], false, false);
        }
    }

    private function getSchemaId($schema_name) {
        $schema_id = array_search($schema_name, self::$SCHEMA_NAMES);
        if (!$schema_id) {
            throw new CodedException(self::EXCEPTION_ENTITY_SCHEMA_NOT_FOUND, null, $schema_name);
        }
        return $schema_id;
    }

    private function getSchemaName($schema_id) {
        if (is_numeric($schema_id)) {
            if (!array_key_exists($schema_id, self::$SCHEMA_NAMES)) {
                throw new CodedException(self::EXCEPTION_ENTITY_SCHEMA_NOT_FOUND, null, $schema_id);
            }
            return self::$SCHEMA_NAMES[$schema_id];
        } else {
            if (!in_array($schema_id, self::$SCHEMA_NAMES)) {
                throw new CodedException(self::EXCEPTION_ENTITY_SCHEMA_NOT_FOUND, null, $schema_id);
            }
            return $schema_id;  // It's already a string... and valid... so just return it
        }
    }

    private function loadSchemaClass($schema_class, $entity_id = null) {
        if (!class_exists('EntityBase')) {
            LettuceGrow::extension('EntityBase', null, [
                iLettuceExtension::OPTION_BASE_PATH => __DIR__ . '/Lib/',
                iLettuceExtension::OPTION_INSTANTIATE_AS => iLettuceExtension::INSTANTIATE_NONE
            ]);
        }

        return LettuceGrow::extension($schema_class, [EntityFactory::ATTR_ENTITY_ID => $entity_id], [
            iLettuceExtension::OPTION_BASE_PATH => __DIR__ . '/Entities/'
        ]);
    }

    public function getChangeset() {
        return $this->changeset->get();
    }
}