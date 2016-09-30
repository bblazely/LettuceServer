<?php

class EntityPublicId extends EntityBase {
    const   STORAGE_KEY_PUBLIC_ID_RESERVED_WORDS          = 'PublicIdReservedWords',
            STORAGE_KEY_SEARCH                            = 'PublicIdSearch',

            ATTR_PUBLIC_ID                              = 'public_id',
            ATTR_PUBLIC_ID_COUNT                        = 'public_id_count',
            ATTR_REF_ENTITY_ID                          = 'ref_entity_id',
            ATTR_VISIBLE                                = 'visible',

            EXCEPTION_PUBLIC_ID_INVALID                 = 'PublicId::PublicIdInvalid',
            EXCEPTION_PUBLIC_ID_CREATE_FAILED           = 'PublicId::CouldNotCreatePublicId',

            ENTITY_SCHEMA_ID                            = 400,
            ENTITY_SCHEMA_EXTENSION_TABLE               = 'entity_schema_public_id',
            ENTITY_TYPE_NAME                            = 'PublicId';

    private $public_id_ref = null;

    // Schema Definition for PublicId
    public function defineSchema() {
        $this->addSchemaAttributes(EntityFactory::SCOPE_PUBLIC, [
            Common::ATTR_DISPLAY_NAME   => null,
            self::ATTR_PUBLIC_ID        => null,
            self::ATTR_PUBLIC_ID_COUNT  => null,
            self::ATTR_REF_ENTITY_ID    => null,
            self::ATTR_VISIBLE          => 0
        ]);
    }

    public function deregister() {
        $id = $this->splitPublicId($this->getAttribute(self::ATTR_PUBLIC_ID));
        $this->storage->delete(
            self::STORAGE_KEY_SEARCH . '.' . hash('sha256', $id[self::ATTR_PUBLIC_ID] . '.' . ($id[self::ATTR_PUBLIC_ID_COUNT] ? $id[self::ATTR_PUBLIC_ID_COUNT] : 0)) // PublicIdSearch.SHA1(public_id.public_id_count)
        );
        parent::deregister();
    }

    // 0 = public id string
    protected function getByKey($query) {
        $id = $this->splitPublicId($query);
        $id_count = ($id[self::ATTR_PUBLIC_ID_COUNT] ? $id[self::ATTR_PUBLIC_ID_COUNT] : 0);
        $result = $this->storage->retrieve(
            self::STORAGE_KEY_SEARCH . '.' . hash('sha256', $id[self::ATTR_PUBLIC_ID] . '.' . $id_count), 'SELECT * FROM entity JOIN entity_schema_public_id USING (entity_id) WHERE public_id=:public_id AND public_id_count=:public_id_count', [
                self::ATTR_PUBLIC_ID => $id[self::ATTR_PUBLIC_ID],
                self::ATTR_PUBLIC_ID_COUNT => $id_count
            ], [
                Storage::OPT_DURABLE_COLLAPSE_SINGLE => true
            ]
        );

        if (is_array($result)) {
            $this->public_id_ref = $this->getAttribute(self::ATTR_PUBLIC_ID);  // Save a reference to the original public ID so we can test if it has changed or not at update.
            $this->setAttributes($result);
            return $this->schema[EntityFactory::ATTR_ENTITY_ID];
        } else {
            return null;
        }
    }

    public function getAttribute($attr, $scope = EntityFactory::SCOPE_PUBLIC) {
        $value = parent::getAttribute($attr, $scope);
        if ($attr == self::ATTR_PUBLIC_ID) {
            $public_id_count = parent::getAttribute(self::ATTR_PUBLIC_ID_COUNT, EntityFactory::SCOPE_PUBLIC);
            if ($public_id_count) {
                $value .= '.' . $public_id_count;
            }
        }

        return $value;
    }

    public function search($fulltext_query, $limit = 5, $offset = 0, $entity_type = false) {
        $result = Array();

        // Manipulate the query string into something more useful
        $parts = explode(' ', $fulltext_query);
        $fulltext_query = Array();
        foreach($parts as $val) {
            if (strlen($val) >= 3) {
                array_push($fulltext_query, '+' . trim($val) . '*');
            }
        }
        $fulltext_query = implode($fulltext_query);
        $entity_types = explode(' ', $entity_type);

        // TODO: Do we really NEEEEEEED to know how many results were returned, or just allow paging until nothing else comes back?

        foreach ($entity_types as $entity_type) {
            $r = $this->storage->retrieve(
                null, '
                SELECT SQL_CALC_FOUND_ROWS
                  entity.entity_id,
                  entity.schema_id,
                  IF(public_id_count = 0, public_id, CONCAT_WS(\'.\', public_id, public_id_count)) AS public_id
                FROM entity_schema_public_id
                JOIN entity ON (ref_entity_id = entity.entity_id AND schema_id = ?)
                WHERE
                  visible = 1 AND
                  MATCH(display_name) AGAINST (? IN BOOLEAN MODE)
                LIMIT ?, ?', [
                $entity_type, $fulltext_query, $offset, $limit
            ], null, 0, false
            );

            if (count($r) > 0) {
                $result[$entity_type]['el'] = $r;
                $result[$entity_type]['tc'] = $this->storage->retrieve(null, 'SELECT FOUND_ROWS() as tc', null, null, 0, false)['tc'];
            }
        }

        return $result;
    }


    /**
     * @param bool $ref_entity_id
     * @param null $first_name
     * @param null $last_name
     * @param null $display_name
     * @param int  $visible
     *
     * @returns int
     *
     * @throws CodedException
     */
    public function register($ref_entity_id, $first_name = null, $last_name = null, $display_name = null, $visible = 0) {
        $display_name = ($display_name) ? $display_name : trim($first_name . ' ' . $last_name);
        $public_id = $this->generatePublicId($display_name);

        $this->setAttributes([
            self::ATTR_VISIBLE          => $visible,
            self::ATTR_PUBLIC_ID        => $public_id,
            Common::ATTR_DISPLAY_NAME   => $display_name,
            self::ATTR_REF_ENTITY_ID    => $ref_entity_id
        ]);

        try {
            parent::register(true); // Register to get an initial entity id.

            // Re-Read the public ID in case a suffix ID was added. ie: benblazely + .1
            $public_id_count = $this->getPublicIdCount();  // Save a reference to the original public ID so we can test if it has changed or not at update.
            if ($public_id_count != $public_id) {
                $this->setAttribute(self::ATTR_PUBLIC_ID_COUNT, $public_id_count, false);
            }

            call_user_func($this->change_callback, $this->schema[EntityFactory::ATTR_ENTITY_ID], Changeset::OP_ADD);
            return $this->schema[EntityFactory::ATTR_ENTITY_ID];
        } catch (Exception $e) {
            $this->storage->exitTransaction(false);
            throw new CodedException(self::EXCEPTION_PUBLIC_ID_CREATE_FAILED, $e);
        }
    }

    public function update() {
        $public_id = $this->getAttribute(self::ATTR_PUBLIC_ID);

        try {
            if ($this->public_id_ref != $public_id) {
                // Need to resubmit the public id for renumbering (it's new)
                $q = $this->storage->prepare('
                    UPDATE entity_schema_public_id SET
                        public_id_count = (SELECT IFNULL(MAX(PR.public_id_count), -1) + 1 FROM entity_schema_public_id PR WHERE PR.public_id = :public_id_sub),
                        visible=:visible,
                        display_name=:display_name,
                        public_id=:public_id
                    WHERE entity_id=:entity_id
                ');

                $q->execute(Array(
                    'entity_id'     => $this->schema[EntityFactory::ATTR_ENTITY_ID],
                    'visible'       => $this->getAttribute(self::ATTR_VISIBLE),
                    'display_name'  => $this->getAttribute(Common::ATTR_DISPLAY_NAME),
                    'public_id'     => $public_id,
                    'public_id_sub' => $public_id
                ));

                // Re-Read the public ID in case a suffix ID was added. ie: benblazely.1
                $this->public_id_ref = $this->getPublicIdCount();  // Save a reference to the original public ID so we can test if it has changed or not at update.

                // If the public ID did change, update the entity record with the new value.
                if ($this->public_id_ref != $public_id) {
                    $this->setAttribute(self::ATTR_PUBLIC_ID, $this->public_id_ref);
                }
            } else {
                // No change to the public ID was made, so just leave it alone and update the other fields
                $this->storage->prepare('
                    UPDATE entity_schema_public_id SET
                        visible=:visible,
                        display_name=:display_name
                    WHERE entity_id=:entity_id
                ')->execute(Array(
                    EntityFactory::ATTR_ENTITY_ID     => $this->schema[EntityFactory::ATTR_ENTITY_ID],
                    self::ATTR_VISIBLE         => $this->getAttribute(self::ATTR_VISIBLE),
                    Common::ATTR_DISPLAY_NAME  => $this->getAttribute(Common::ATTR_DISPLAY_NAME)
                ));
            }

            parent::update(false);
        } catch (Exception $e) {
            throw new CodedException(EntityBase::EXCEPTION_ENTITY_UPDATE_FAILED, $e, self::ATTR_PUBLIC_ID);
        }
    }

    private function getPublicIdCount() {
        return $this->storage->retrieve(
            null, // Don't cache this?
            'SELECT public_id_count FROM entity_schema_public_id WHERE entity_id=:entity_id', [
                EntityFactory::ATTR_ENTITY_ID => $this->schema[EntityFactory::ATTR_ENTITY_ID]
            ], [
                Storage::OPT_DURABLE_COLLAPSE_SINGLE => true,
                Storage::OPT_DURABLE_FETCH_STYLE => Storage::DURABLE_FETCH_STYLE_COL,
                Storage::OPT_DURABLE_FETCH_ARGUMENT => 0
            ]
        );
    }

    /**
     * @param $type
     * @param $display_name
     *
     * @return string
     */
    private function generatePublicId($display_name = null) {
        if ($display_name) {
            $display_name = preg_replace('/[\s]+/u', '', mb_strtolower($display_name)); // This may screw some languages up. Removal of spaces from arabic for instance seems to be a bit odd.
        }

        // Check the public id is usable, generate a new one if it isn't
        if (!$display_name || $this->isPublicIdReserved($display_name)) {
            $display_name = uniqid();
        }
        // Remove anything other than letters/numbers and convert to lower case
        return $display_name;
    }

    private function isPublicIdReserved($public_id) {
        // All ID's less than 3 characters long are reserved.
        if (strlen($public_id) < 3) {
            return true;
        }

        // Check the reserved word list from the DB.
        $reserved_words = $this->storage->retrieve(
            self::STORAGE_KEY_PUBLIC_ID_RESERVED_WORDS,
            'SELECT word FROM reserved_words',
            null,
            [
                Storage::OPT_DURABLE_FETCH_STYLE => Storage::DURABLE_FETCH_STYLE_COL,
                Storage::OPT_DURABLE_FETCH_ARGUMENT => 'word'
            ], 0, true
        );

        if (in_array($public_id, $reserved_words)) {
            return true;
        } else {
            return false;
        }
    }

    protected function getById($entity_id) {
        parent::getById($entity_id);
        $this->public_id_ref = $this->getAttribute(self::ATTR_PUBLIC_ID);      // Save a ref to the public ID (see getByKey)

        return $this->schema[EntityFactory::ATTR_ENTITY_ID];
    }

    private function splitPublicId($public_id) {
        @list($id, $id_count) = explode('.', $public_id);
        if (!$id) {
            throw new CodedException(self::EXCEPTION_PUBLIC_ID_INVALID);
        } else {
            return Array(
                self::ATTR_PUBLIC_ID         => $id,
                self::ATTR_PUBLIC_ID_COUNT   => $id_count
            );
        }
    }
}