<?php

class EntityGhost extends EntityBase {

    const
        ENTITY_SCHEMA_ID                = 300,
        ENTITY_TYPE_NAME                = 'Ghost',
        ENTITY_SCHEMA_EXTENSION_TABLE   = 'entity_schema_ghost',

        X_GHOST_FOR                     = -100,
        X_GHOST_MEMBER_OF               = -205;

    protected function defineSchema() {
        $this->addSchemaAttributes(EntityFactory::SCOPE_PUBLIC, [
            Common::ATTR_DISPLAY_NAME => null,
            'dob' => null
        ]);
    }

    protected function AssocEnableCounter() {}
    protected function AssocEnableDelta() {}
    protected function AssocFieldInvalidates() {}
    protected function AssocFieldSortable() {}

    /**
     * @param $display_name
     *
     * @return int
     * @throws CodedException
     *
     */

    public function register($display_name = null) {
        if ($display_name) {
            $this->setAttribute(Common::ATTR_DISPLAY_NAME, $display_name);
        }
        return parent::register();
    }

    protected function getByKey($query) {
        throw new CodedException(self::EXCEPTION_ENTITY_CANNOT_GET_BY_KEY, null, !is_array($query) ?? json_encode($query));
    }
}

