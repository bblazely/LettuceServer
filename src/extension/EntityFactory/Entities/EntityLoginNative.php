<?php

class EntityLoginNative extends EntityBase {

    const
            ATTR_PASSWORD                           = 'password',
            ATTR_PASSWORD_HASH                      = 'password_hash',
            ATTR_PASSWORD_HASH_INVERT               = 'password_hash_invert_case',
            ATTR_PASSWORD_HASH_FIRST_UPPER          = 'password_hash_first_upper',
            ATTR_VERIFIED                           = 'verified',

            VALUE_VERIFIED                          = 1,
            VALUE_NOT_VERIFIED                      = 0,

            STORAGE_KEY_SEARCH                        = 'LoginNativeSearch',

            ENTITY_SCHEMA_ID                        = 500,
            ENTITY_TYPE_NAME                        = 'LoginNative',
            ENTITY_SCHEMA_EXTENSION_TABLE           = 'entity_schema_login_native',

            EXCEPTION_USER_CREATION_FAILED        = 'LoginNative::FailedToCreateNewUser',               // 3790798431
            EXCEPTION_USER_ALREADY_EXISTS         = 'LoginNative::UserAlreadyExists';

    public function AssocFieldInvalidates() {}
    public function AssocEnableCounter() {}
    public function AssocEnableDelta() {}
    public function AssocFieldSortable() {
        return [];
    }

    private $password_types = Array(
        self::ATTR_PASSWORD_HASH,
        self::ATTR_PASSWORD_HASH_INVERT,
        self::ATTR_PASSWORD_HASH_FIRST_UPPER
    );

    protected function defineSchema() {
        $this->addSchemaAttributes(EntityFactory::SCOPE_PUBLIC, [
            self::ATTR_VERIFIED => 0
        ]);

        $this->addSchemaAttributes(EntityFactory::SCOPE_PRIVATE, [
            Common::ATTR_EMAIL => null,
            self::ATTR_PASSWORD_HASH => null,
            self::ATTR_PASSWORD_HASH_INVERT => null,
            self::ATTR_PASSWORD_HASH_FIRST_UPPER => null
        ]);
    }

    public function deregister() {
        $this->storage->delete(self::STORAGE_KEY_SEARCH . '.' . hash(Common::DEFAULT_HASH, strtolower($this->getAttribute(Common::ATTR_EMAIL, EntityFactory::SCOPE_PRIVATE))));
        parent::deregister();
    }

    public function getByKey($query) {
        $query = strtolower($query);
        $result = $this->storage->retrieve(
            self::STORAGE_KEY_SEARCH . '.' . hash(Common::DEFAULT_HASH, $query),
            'SELECT * FROM entity JOIN entity_schema_login_native USING (entity_id) WHERE email=:email', [
                Common::ATTR_EMAIL => $query
            ], [
                Storage::OPT_DURABLE_COLLAPSE_SINGLE => true
            ]
        );

        if (is_array($result)) {
            $this->setAttributes($result, false);
            return $this->schema[EntityFactory::ATTR_ENTITY_ID];
        } else {
            return null;
        }
    }

    public function update() {
        parent::update();
        $this->storage->delete(self::STORAGE_KEY_SEARCH . '.' . hash(Common::DEFAULT_HASH, strtolower($this->getAttribute(Common::ATTR_EMAIL, EntityFactory::SCOPE_PRIVATE))));
    }

    public function register(Array $data) {

        if (!Common::arrayKeyExistsAll([Common::ATTR_EMAIL, self::ATTR_PASSWORD], $data)) {
            throw new CodedException(self::EXCEPTION_ENTITY_INVALID_REGISTRATION, null, json_encode($data));
        }
        
        $this->setAttribute(Common::ATTR_EMAIL, strtolower($data[Common::ATTR_EMAIL]));
        $this->changePassword(self::ATTR_PASSWORD);

        $entity_id = $this->baseRegister();
        $this->storage->delete(self::STORAGE_KEY_SEARCH . '.' . hash(Common::DEFAULT_HASH, $data[Common::ATTR_EMAIL]));
        return $entity_id;
    }

    public function changePassword($password) {
        $this->setAttribute(self::ATTR_PASSWORD_HASH, password_hash($password, PASSWORD_DEFAULT));
        $this->setAttribute(self::ATTR_PASSWORD_HASH_INVERT, password_hash((strtolower($password) ^ strtoupper($password) ^ $password), PASSWORD_DEFAULT));
        $this->setAttribute(self::ATTR_PASSWORD_HASH_FIRST_UPPER, password_hash(ucfirst($password), PASSWORD_DEFAULT));
    }

    public function checkPassword($password) {
        foreach ($this->password_types as $password_type) {
            $password_candidate = $this->getAttribute($password_type, EntityFactory::SCOPE_PRIVATE);
            if ($password_candidate && password_verify($password, $password_candidate)) {
                return true;
            }
        }
        return false;
    }

    public function setVerified($state = self::VALUE_VERIFIED) {
        $this->setAttribute(self::ATTR_VERIFIED, $state);
        $this->update();
    }

    public function isVerified() {
        return (int)$this->getAttribute(self::ATTR_VERIFIED, EntityFactory::SCOPE_PUBLIC);
    }
}