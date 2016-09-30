<?php

class EntityPersona extends EntityBase {

    const
        ENTITY_SCHEMA_ID                = 800,
        ENTITY_TYPE_NAME                = 'Persona',
        ENTITY_SCHEMA_EXTENSION_TABLE   = 'entity_schema_persona',

        X_PERSONA_FOR                   = 801,
        X_HAS_VERIFICATION              = 802;

    protected function defineSchema() {
        $this->addSchemaAttributes(EntityFactory::SCOPE_PUBLIC, [
            Common::ATTR_DISPLAY_NAME => null
        ]);
    }

    protected function AssocEnableCounter() {}
    protected function AssocEnableDelta() {}
    protected function AssocFieldInvalidates() {}
    protected function AssocFieldSortable() {}

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