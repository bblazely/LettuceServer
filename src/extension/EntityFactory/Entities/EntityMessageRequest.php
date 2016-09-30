<?php

require(__DIR__ . '/EntityMessage.php');

class EntityMessageRequest extends EntityMessage {

    const
        ENTITY_SCHEMA_ID                    = 701,
        ENTITY_TYPE_NAME                    = 'MessageRequest',
        ENTITY_SCHEMA_EXTENSION_TABLE       = 'entity_schema_message_request',

        ATTR_REQUESTED_ASSOC    = 'requested_assoc';

    public function defineSchema() {
        parent::defineSchema();
        $this->addSchemaAttributes(EntityFactory::SCOPE_PUBLIC, [
            self::ATTR_REQUESTED_ASSOC => null
        ]);
    }

    public function register($from_entity_id, $to_entity_id, $recipient_entity_id, $target_recipient_id, Array $assoc_list, $message) {
        $this->setAttribute(self::ATTR_REQUESTED_ASSOC, implode("\n", $assoc_list));

        // Require a transaction so that the message isn't prematurely sent to the user prior to the request portion being set up.
        $this->storage->enterTransaction();
        $message_id = parent::register($from_entity_id, $to_entity_id, $message);

        // Add Recipient / Target associations
        // Note: The "new" recipient message object should be the only thing ever looking up these associations, so
        // we don't need to do cache invalidations.
        $this->assoc->add($message_id, Association::MRQ__TARGET, $target_recipient_id, true, false);
        $this->assoc->add($message_id, Association::MRQ__RECIPIENT, $recipient_entity_id, true, false);

        $this->storage->exitTransaction();
        return $message_id;
    }

    public function processResponse($response) {

    }

    public function extensions() {
        return [
            Association::MRQ__TARGET_OF,
            Association::MRQ__RECIPIENT_OF
        ];
    }

    public function payload() {
        var_dump($this->schema);
        $target_id = $this->assoc->getSingle($this->getId(), Association::MRQ__TARGET);
        return ['target'=>$this->$target_id];
    }

    protected function getByKey($query) {
        throw new CodedException(self::EXCEPTION_ENTITY_CANNOT_GET_BY_KEY, null, !is_array($query) ?? json_encode($query));
    }
}

