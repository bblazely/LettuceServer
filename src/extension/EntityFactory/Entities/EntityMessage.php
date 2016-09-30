<?php

class EntityMessage extends EntityBase {

    const
        ENTITY_SCHEMA_ID                = 700,
        ENTITY_TYPE_NAME                = 'Message',
        ENTITY_SCHEMA_EXTENSION_TABLE   = 'entity_schema_message',

        RESPONSE_DELETE         = 0,
        ATTR_MESSAGE            = 'message';

    public function defineSchema() {
        $this->addSchemaAttributes(EntityFactory::SCOPE_PUBLIC, [
            self::ATTR_MESSAGE => null
        ]);
    }

// TODO Add support for invalidating external messages (ala facebook)

    public function register($from_entity_id, $to_entity_id, $message, $external_provider = null) {
        $this->setAttribute(self::ATTR_MESSAGE, $message);
        $message_id = parent::register();

        // Sender
        $this->assoc->add($message_id, Association::MSG__SENT_BY, $from_entity_id, false, false); // No Reverse, No Invalidate
        $this->assoc->add($from_entity_id, Association::MSG__SENT, $message_id, false);   // No Reverse

        // Recipient
        $this->assoc->add($message_id, Association::MSG__RECIPIENT, $to_entity_id, false, false); // No Reverse, No Invalidate
        $this->assoc->add($to_entity_id, Association::MSG__RECIPIENT_OF, $message_id, false);   // No Reverse

        return $message_id;
    }

    public function getMessage() {
        return $this->getAttribute(self::ATTR_MESSAGE);
    }

    public function processResponse($response) {

    }

    public function payload() {
        return null;
    }

    protected function getByKey($query) {
        throw new CodedException(self::EXCEPTION_ENTITY_CANNOT_GET_BY_KEY, null, !is_array($query) ?? json_encode($query));
    }
}

