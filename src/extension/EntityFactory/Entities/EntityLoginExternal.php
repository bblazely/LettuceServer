<?php

class EntityLoginExternal extends EntityBase {

    const
        ATTR_PROVIDER_ID                        = 'provider_id',
        ATTR_PROVIDER_USER_ID                   = 'provider_user_id',

        ENTITY_SCHEMA_ID                        = 600,
        ENTITY_TYPE_NAME                        = 'LoginExternal',
        ENTITY_SCHEMA_EXTENSION_TABLE           = 'entity_schema_login_external',

        STORAGE_KEY_SEARCH                      = 'LoginExternalSearch',

        EXCEPTION_USER_CREATION_FAILED          = 'LoginExternal::FailedToCreateNewUser',               // 3790798431
        EXCEPTION_USER_ALREADY_EXISTS           = 'LoginExternal::UserAlreadyExists',
        EXCEPTION_QUERY_INVALID                 = 'LoginExternal::QueryInvalid',
        EXCEPTION_INVALID_EXTERNAL_ID           = 'LoginExternal::InvalidExternalId',

        X_VERIFICATION_FOR                      = -802;

    public function AssocFieldInvalidates() {}
    public function AssocEnableCounter() {}
    public function AssocEnableDelta() {}
    public function AssocFieldSortable() {
        return [];
    }

    // Schema Definition for PublicId
    public function defineSchema() {
        $this->addSchemaAttributes(EntityFactory::SCOPE_PRIVATE, [
            self::ATTR_PROVIDER_ID => null,
            self::ATTR_PROVIDER_USER_ID => null
        ]);

        $this->addSchemaAttributes(EntityFactory::SCOPE_PUBLIC, [
            Common::ATTR_DISPLAY_NAME => null,
            Common::ATTR_IMAGE_URL => null,
            Common::ATTR_EXTERNAL_URL => null
        ]);
    }

    public function getByKey($query) {
        $provider_id = Common::getIfSet($query[self::ATTR_PROVIDER_ID]);
        $provider_user_id = Common::getIfSet($query[self::ATTR_PROVIDER_USER_ID]);

        if (!is_numeric($provider_id) || !$provider_user_id) {
            throw new CodedException(self::EXCEPTION_QUERY_INVALID, null, $provider_id.'|'.$provider_user_id);
        }

        $result = $this->storage->retrieve(
            self::STORAGE_KEY_SEARCH . '.' . hash(Common::DEFAULT_HASH, $provider_id . '.' . $provider_user_id),
            'SELECT * FROM entity JOIN '.self::ENTITY_SCHEMA_EXTENSION_TABLE.' USING (entity_id) WHERE provider_id=:provider_id AND provider_user_id=:provider_user_id', [
                self::ATTR_PROVIDER_ID => $provider_id,
                self::ATTR_PROVIDER_USER_ID => $provider_user_id
            ],[
                Storage::OPT_DURABLE_COLLAPSE_SINGLE => true
            ]
        );

        if (is_array($result)) {
            $this->setAttributes($result);
            return $this->schema[EntityFactory::ATTR_ENTITY_ID];
        } else {
            return null;
        }
    }

    public function deregister($delete_volatile = true) {
        parent::deregister($delete_volatile);
        $this->storage->delete(self::STORAGE_KEY_SEARCH . '.' . hash(Common::DEFAULT_HASH, $this->getAttribute(self::ATTR_PROVIDER_ID, EntityFactory::SCOPE_PRIVATE) . '.' . $this->getAttribute(self::ATTR_PROVIDER_USER_ID, EntityFactory::SCOPE_PRIVATE)));
    }

    public function update($update_schema_extension = true, $full_update = false) {
        parent::update($update_schema_extension, $full_update);
        $this->storage->delete(self::STORAGE_KEY_SEARCH . '.' . hash(Common::DEFAULT_HASH, $this->getAttribute(self::ATTR_PROVIDER_ID, EntityFactory::SCOPE_PRIVATE) . '.' . $this->getAttribute(self::ATTR_PROVIDER_USER_ID, EntityFactory::SCOPE_PRIVATE)));
    }

    public function register(Array $data) {
        $provider_id = $data[self::ATTR_PROVIDER_ID] ?? null;
        $provider_user_id = $data[self::ATTR_PROVIDER_USER_ID] ?? null;

        if (!$provider_id || !$provider_user_id) {
            throw new CodedException(self::EXCEPTION_INVALID_EXTERNAL_ID, null, implode('|', [$provider_id, $provider_user_id]));
        }

        $this->setAttributes([
            self::ATTR_PROVIDER_ID        => $provider_id,
            self::ATTR_PROVIDER_USER_ID   => $provider_user_id,
            Common::ATTR_DISPLAY_NAME     => $data[Common::ATTR_DISPLAY_NAME] ?? null,
            Common::ATTR_IMAGE_URL        => $data[Common::ATTR_IMAGE_URL] ?? null,
            Common::ATTR_EXTERNAL_URL     => $data[Common::ATTR_EXTERNAL_URL] ?? null
        ], false);

        $entity_id = $this->baseRegister();
        $this->storage->delete(self::STORAGE_KEY_SEARCH . '.' . hash(Common::DEFAULT_HASH, $provider_id . '.' . $provider_user_id));
        return $entity_id;
    }
}