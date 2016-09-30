<?php

Class EntityGroup extends EntityBase {

    protected function AssocFieldInvalidates() {}
    protected function AssocEnableCounter() {}
    protected function AssocFieldSortable() {}
    protected function AssocEnableDelta() {}

    const
        ENTITY_SCHEMA_ID                    = 200,
        ENTITY_TYPE_NAME                    = 'Group',
        ENTITY_SCHEMA_EXTENSION_TABLE       = 'entity_schema_group';

    public function defineSchema() {
        $this->addSchemaAttributes(EntityFactory::SCOPE_PUBLIC, [
            Common::ATTR_DISPLAY_NAME => null,
            Common::ATTR_IMAGE_URL => null
        ]);
    }

    /**
     * @param null $display_name
     *
     * @returns int
     */

    public function register($display_name) {
        $this->setAttribute(Common::ATTR_DISPLAY_NAME, $display_name);
        return parent::register();
    }

    protected function getByKey($query) {
        throw new CodedException(self::EXCEPTION_ENTITY_CANNOT_GET_BY_KEY, null, !is_array($query) ?? json_encode($query));
    }
}

