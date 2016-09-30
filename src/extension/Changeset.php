<?php

class Changeset implements iLettuceExtension {
    static function ExtGetOptions() {
        return [
            self::OPTION_INSTANTIATE_AS => self::INSTANTIATE_SINGLETON,
            self::OPTION_INSTANTIATE_AS_LOCK => true    // Always instantiate as a singleton - changeset is 1 per request lifecycle
        ];
    }

    const
        ATTR_OP                         = 'op',
        ATTR_TIMESTAMP                  = 't',
        ATTR_ENTITY_ID                  = 'eid',
        ATTR_ENTITY_ID_APPLIED          = 'eida',
        ATTR_ASSOC_ID                   = 'aid',

        OP_UPDATE                       = 'u',
        OP_REMOVE                       = 'r',
        OP_ADD                          = 'a',

        INDEX_ENTITY                    = 'e',
        INDEX_ASSOC_LIST                = 'l',

        KEY_QUEUE_CHANGESET             = 'ChangeSet',
        KEY_STORAGE_DELTA               = 'Delta';

    private
        $queue, $storage,
        $defined = false,
        $changeset = [],
        $deltaset = [];

    public function __construct($params, $config) {
        $this->queue = LettuceGrow::extension('Queue');
        $this->storage = LettuceGrow::extension('Storage');
    }

    public function clear($broadcast = false) {
        $delta = $this->get($broadcast);
        $this->changeset = [];
        return $delta;
    }

    public function get($commit = false) {
        if ($commit) {
            $this->commitDelta();
        }
        return $this->changeset;
    }

    public function trackChange($entity_id, $change_type, $assoc_id = null, $entity_id_applied = null, $time_updated = null) {
        if (!$time_updated) {
            $time_updated = floor(microtime(true) * 1000);
        }

        if ($assoc_id) {
            // Association Change (Add/Remove)
            Common::addNode($this->changeset, [$entity_id, self::INDEX_ASSOC_LIST, $assoc_id, $change_type], [$entity_id_applied => $time_updated]);   // EntityId->l->[assoc_id]->[a|r]->[EntityId_Applied]->update.timestamp
        } else {
            // EntityFactory Change (Update) - All Adds/Removes should be captured in association delta's, so only track entity updates where attributes have changed.
            if ($change_type === self::OP_UPDATE) {
                Common::addNode($this->changeset, [$entity_id, self::INDEX_ENTITY], $time_updated);       // EntityId->e->update.timestamp
            }
        }
    }

    // Private
    private function commitDelta() {
        if (!$this->defined) {
            $this->queue->defineExchange(self::KEY_QUEUE_CHANGESET, 'topic', false, false);
        }

        // This doesn't have to be super accurate as long as it's > last poll time (which it should always be)
        foreach ($this->changeset as $entity_id => $delta) {
            $this->queue->send(self::KEY_QUEUE_CHANGESET, $delta, $entity_id, false, null, ['x-expires' => 5000]);
            $this->storage->update(
                self::KEY_STORAGE_DELTA . '.' . $entity_id,
                json_encode($delta),
                null,
                null, [
                    Storage::OPT_VOLATILE_EXPIRATION => 60,
                    Storage::OPT_VOLATILE_UPDATE_STYLE => Storage::VOLATILE_UPDATE_APPEND
                ]
            );
        }
    }
}