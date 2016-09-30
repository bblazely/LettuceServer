<?php

class EntityUser extends EntityBase {

    const   EXCEPTION_INVALID_COMMON_PROFILE    = 'User::InvalidCommonProfile',
            EXCEPTION_USER_NOT_FOUND            = 'User::NotFound',

            ENTITY_SCHEMA_ID                    = 100,
            ENTITY_TYPE_NAME                    = 'User',
            ENTITY_SCHEMA_EXTENSION_TABLE       = 'entity_schema_user',

            X_R_HAS_LOGIN                       = -50;  // Reverse of CredentialFactory::X_LOGIN_FOR

    /** @var  NameParser name_parser */
    private $name_parser;

    public function AssocFieldInvalidates() {}
    public function AssocEnableCounter() {}
    public function AssocEnableDelta() {}
    public function AssocFieldSortable() {
        return [];
    }

    public function __construct($params, $config){
        parent::__construct($params, $config);
    }

    // Schema Definition for PublicId
    public function defineSchema() {
        $this->addSchemaAttributes(EntityFactory::SCOPE_PUBLIC, [
            Common::ATTR_DISPLAY_NAME => null,
            Common::ATTR_FIRST_NAME => null,
            Common::ATTR_LAST_NAME => null,
            Common::ATTR_IMAGE_URL => null
        ]);

        $this->addSchemaAttributes(EntityFactory::SCOPE_PRIVATE, [
            Common::ATTR_EMAIL => null
        ]);
    }

    /**
     * @param      $data
     *
     * @return int
     * @throws CodedException
     *
     */

    public function register(Array $data) {
        $first_name = $data[Common::ATTR_FIRST_NAME] ?? null;
        $last_name = $data[Common::ATTR_LAST_NAME] ?? null;
        $display_name = $data[Common::ATTR_DISPLAY_NAME] ?? null;

        if (!$first_name || !$last_name) {
            if (!$display_name) {
                $display_name = implode(' ', [$first_name, $last_name]);    // Best we can do at this point...
            } else {
                if (!$this->name_parser) {
                    $this->name_parser = LettuceGrow::extension('NameParser');
                }

                $name_parts = $this->name_parser->parse($display_name);
                $first_name = $name_parts['first'];
                $last_name = $name_parts['last'];
            }
        }

        $this->setAttributes([
            Common::ATTR_EMAIL => $data[Common::ATTR_EMAIL] ?? null,
            Common::ATTR_DISPLAY_NAME => $display_name,
            Common::ATTR_FIRST_NAME => $first_name,
            Common::ATTR_LAST_NAME => $last_name,
            Common::ATTR_IMAGE_URL => $data[Common::ATTR_IMAGE_URL] ?? null
        ]);

        return parent::baseRegister();
    }

    protected function getByKey($query) {
        throw new CodedException(self::EXCEPTION_ENTITY_CANNOT_GET_BY_KEY, null, !is_array($query) ?? json_encode($query));
    }
}

