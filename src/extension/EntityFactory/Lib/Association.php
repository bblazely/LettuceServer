<?php

class Association implements iLettuceExtension {
    static function ExtGetOptions() {
        return [
            self::OPTION_INSTANTIATE_AS => self::INSTANTIATE_INSTANCE,
            self::OPTION_INSTANTIATE_AS_LOCK => true
        ];
    }

    const
        OPT_INVALIDATE                  = 'invalidate',
        OPT_UPDATE_REVERSE              = 'update_reverse',

        UNRESTRICTED                    = -1,

        X_LURQ_FROM                     = 10,   // Get automatic updates from the target entity
        X_LURQ_TO                       = 11,   // Send automatic updates to the target entity


        // Generic Associations
      /*  REQUIRES                        = 31,   // Todo: Deprecated?
        REQUIRED_BY                     = 32,
        LINK_HAS_PROVIDER               = 40,   // EntityFactory is linked for attr updates to another entity (assoc_data stored on reverse link LINK_PROVIDES)
        LINK_PROVIDES                   = 41,   // EntityFactory provides attr updates to another entity for attr's in this assoc_data list.

        // Ghost Associations
        GHOST__HAS                      = 301,
        GHOST__FOR                      = 302,

        // Group Associations
        GROUP__OWNER_OF                 = 260,
        GROUP__HAS_OWNER                = 261,      // TODO: Wipe the DB and make these consistent in order.
        GROUP__HAS_MEMBER               = 201,
        GROUP__MEMBER_OF                = 202,
        GROUP__HAS_GHOST_MEMBER         = 203,
        GROUP__GHOST_MEMBER_OF          = 204,
        GROUP__HAS_SUB_GROUP            = 205,
        GROUP__SUB_GROUP_OF             = 206,
        GROUP__INVITES_MEMBER           = 207,
        GROUP__INVITED_AS_MEMBER        = 208,
        GROUP__HAS_VIEWER               = 209,
        GROUP__CAN_VIEW                 = 210,
        GROUP__INVITES_VIEWER           = 211,
        GROUP__INVITED_AS_VIEWER        = 212,

        // Public Record Associations
        PR__HAS                         = 401,
        PR__FOR                         = 402,

        // Native Login Associations
        LN__HAS                         = 501,
        LN__FOR                         = 502,

        // Social Login Associations
        LS__HAS                         = 601,
        LS__FOR                         = 602,

        // Message Associations
        MSG__RECIPIENT                  = 720,      // Message -> Recipient
        MSG__RECIPIENT_OF               = 721,      // Recipient -> Message
        MSG__UNREAD                     = 724,      // Message -> Unread by Recipient
        MSG__HAS_UNREAD                 = 725,      // Recipient -> Has not read message.
        MSG__SENT                       = 728,      // Sender Sends -> Message
        MSG__SENT_BY                    = 729,      // Message -> Sent by Sender

        // MessageRequest Associations
        MRQ__TARGET                     = 750,
        MRQ__TARGET_OF                  = 751,
        MRQ__RECIPIENT                  = 754,
        MRQ__RECIPIENT_OF               = 755,*/

        // Exceptions
//        EXCEPTION_ENTITY_REVERSE_AMBIGUOUS  = 'Association::ReverseAssociationAmbiguous',
        EXCEPTION_ASSOC_ATTR_INVALID        = 'Association::AttributeInvalid',
        EXCEPTION_ASSOC_MORE_THAN_ONE       = 'Association::MoreThanOneAssociationReturned',
        EXCEPTION_ASSOC_NO_ENTITY_ANCHOR    = 'Association::NoEntityAnchorSpecified',

        // Volatile Storage Keys
        STORAGE_KEY_ASSOC                   = 'Assoc',
        STORAGE_KEY_ASSOC_LIST              = 'AssocList',
        STORAGE_KEY_ASSOC_MAP               = 'AssocMap',
        STORAGE_KEY_ASSOC_COUNT             = 'AssocCount';

    private $entity_id, $schema_extension_table;

    // List of associations that are tracked for delta changes in memcache and request changesets
/*    private static
        $DELTA_ENABLED = Array(
            self::GROUP__OWNER_OF,
            self::GROUP__MEMBER_OF,
            self::GROUP__HAS_MEMBER,
            self::GROUP__HAS_GHOST_MEMBER,
            self::GROUP__INVITES_MEMBER,   // ???
            self::MSG__HAS_UNREAD,   // Need both new and existing messages in delta to cope with deletes etc
            self::MSG__RECIPIENT_OF
        ),
        $COUNTER_ENABLED = Array(
            self::GROUP__HAS_GHOST_MEMBER,
            self::GROUP__OWNER_OF
        )/*,
        $ASSOC_REVERSE_MAP = Array(
            // Generic Associations
            self::GROUP__OWNER_OF               => self::GROUP__HAS_OWNER,
            self::REQUIRES                  => self::REQUIRED_BY,

            // Ghost Associations
            self::GHOST__FOR                    => self::GHOST__HAS,

            // Group Associations
            self::GROUP__MEMBER_OF          => self::GROUP__HAS_MEMBER,
            self::GROUP__GHOST_MEMBER_OF    => self::GROUP__HAS_GHOST_MEMBER,
            self::GROUP__SUB_GROUP_OF       => self::GROUP__HAS_SUB_GROUP,
            self::GROUP__INVITES_MEMBER     => self::GROUP__INVITED_AS_MEMBER,
            self::GROUP__CAN_VIEW           => self::GROUP__HAS_VIEWER,
            self::GROUP__INVITES_VIEWER     => self::GROUP__INVITED_AS_VIEWER,

            // Native Login Associations
            self::LN__FOR                   => self::LN__HAS,

            // Social Login Associations
            self::LS__FOR                   => self::LS__HAS,

            // Public Record Associations
            self::PR__FOR                   => self::PR__HAS,

            // Message Associations
            self::MSG__RECIPIENT            => self::MSG__RECIPIENT_OF,
            self::MSG__UNREAD               => self::MSG__HAS_UNREAD,
            self::MSG__SENT                 => self::MSG__SENT_BY,

            // MessageRequest Associations
            self::MRQ__RECIPIENT            => self::MRQ__RECIPIENT_OF,
            self::MRQ__TARGET               => self::MRQ__TARGET_OF
        );*/

    // SORTED ASSOCIATIONS ------------------------------------------------------------

    // EntityFactory Field Update causes Assoc Invalidation Map.
   /* private static $ASSOC_ENTITY_ATTR_INVALIDATES = Array(
        EntityFactory::SCHEMA_GHOST => [                                       // Where Altered EntityFactory is of type SCHEMA_GHOST
            Common::ATTR_DISPLAY_NAME => [                                  // If the attribute DISPLAY_NAME was changed
                self::GROUP__GHOST_MEMBER_OF => self::GROUP__HAS_GHOST_MEMBER              // For each GHOST_IS_MEMBER_OF, invalidate Entity2->HAS_GHOST_MEMBER.CID.display_name.CID
                //    GHOST__IS_MEMBER_OF =>       GHOST__HAS_GROUP
            ],
            Common::ATTR_DATE_OF_BIRTH => [                                   // If the attribute DATE_OF_BIRTH was changed
                self::GROUP__GHOST_MEMBER_OF => self::GROUP__HAS_GHOST_MEMBER              // For each GHOST_IS_MEMBER_OF, invalidate Entity2->HAS_GHOST_MEMBER.CID.dob.CID
            ]
        ],
        EntityFactory::SCHEMA_GROUP => [                                       // Where Altered EntityFactory is of type SCHEMA_GROUP
            Common::ATTR_DISPLAY_NAME => [                                  // If the attribute DISPLAY_NAME was changed
                self::GROUP__HAS_MEMBER => self::GROUP__MEMBER_OF,                         // For each HAS_MEMBER, invalidate Entity2->IS_MEMBER_OF.CID.display_name.CID
                self::GROUP__HAS_OWNER => self::GROUP__OWNER_OF                            // For each HAS_OWNER, invalidate Entity2->IS_OWNER_OF.CID.display_name.CID
            ]
        ]
    );*/

    // Determines support for organising an association and which entity_schema[column] to use for the sort/range query
   /* private static
        $ASSOC_HAS_ATTR = Array(
            self::GROUP__HAS_GHOST_MEMBER => [
                Common::ATTR_DISPLAY_NAME   => EntityFactory::SCHEMA_GHOST,        // Group Ghost Member List sorted by entity_schema_ghost.display_name
                Common::ATTR_DATE_OF_BIRTH  => EntityFactory::SCHEMA_GHOST         // Group Ghost Member List sorted by entity_schema_ghost.date_of_birth
            ],
            self::GROUP__HAS_MEMBER => [
                Common::ATTR_DISPLAY_NAME   => EntityFactory::SCHEMA_PERSON,       // Group Member List sorted by entity_schema_person.display_name
                Common::ATTR_DATE_OF_BIRTH  => EntityFactory::SCHEMA_PERSON        // Group Member List sorted by entity_schema_person.date_of_birth
            ],
            self::GROUP__MEMBER_OF => [
                Common::ATTR_DISPLAY_NAME   => EntityFactory::SCHEMA_GROUP         // User Group List sorted by entity_schema_group.display_name
            ],
            self::GROUP__OWNER_OF => [
                Common::ATTR_DISPLAY_NAME   => EntityFactory::SCHEMA_GROUP         // User Group List sorted by entity_schema_group.display_name
            ]
        );*/

    public static function assocSortAttrList($schema_id) {
 /*       if (array_key_exists($schema_id, self::$ASSOC_ENTITY_ATTR_INVALIDATES)) {
            return self::$ASSOC_ENTITY_ATTR_INVALIDATES[$schema_id];
        }
        return null;*/
    }

    public static function assocAttrJoinTable($assoc_id, $attr = null) {

        /*if (array_key_exists($assoc_id, self::$ASSOC_HAS_ATTR)) {
            if (array_key_exists($attr, self::$ASSOC_HAS_ATTR[$assoc_id])) {
                // Return the join table target for this attribute
                return EntityFactory::schemaTable(self::$ASSOC_HAS_ATTR[$assoc_id][$attr]);
            }
            // Return available sortable attribute mappings
            return self::$ASSOC_HAS_ATTR[$assoc_id];
        }*/
        return null;
    }

    // Potentially may need to move this to a backend queue operation at some point if the
    // update sets get too huge for the typical user... it will be consistent eventually...
    public function entityUpdateCascade($entity_id, $schema_id, $changed_attr_list) {
        if (is_array($changed_attr_list)) {
            $attr_list = self::assocSortAttrList($schema_id);
            foreach ($changed_attr_list as $attr) {
                if (array_key_exists($attr, $attr_list)) {
                    // For each assoc_id, look up it's inverse and invalidate that off of the target entity
                    foreach ($attr_list[$attr] as $assoc_id => $invalidated_assoc_id) {
                        $list = $this->getList($entity_id, $assoc_id, false);
                        foreach ($list as $entity_id2) {
                            $key = $this->storage->getVolatileKey(
                                self::STORAGE_KEY_ASSOC_LIST . '.' . $entity_id2,
                                $invalidated_assoc_id,
                                null, [
                                    Storage::OPT_VOLATILE_PATH_CREATE_MISSING => false
                                ]
                            );
                            // If a key was found, delete the CID node
                            if ($key) {
                                $this->storage->delete($key . '.' . $attr . '.' . Storage::STR_VOLATILE_COLLECTION_ID);
                            }
                        }
                    }
                }
            }
        }
    }

    /*
      On Edit
      -------

        1. Alter ATTR on EntityFactory
        2. Lookup EntityFactory Schema Entry in assoc_sort_map
        3. Lookup altered ATTR in assoc_sort_map[entity]
        4. Foreach assoc: get AssocList.entity_id.assoc_id (unsorted is fine)
        5  Foreach assoc_entity_id: Invalidate AssocListSort.entity_id.lookup(assoc_id)

    eg:
        1. Alter D.O.B on EntityFactory (User)
        2. Lookup User Schema in assoc_sort_map
        3. Lookup D.O.B in assoc_sort_map[User]
        4. Get AssocList.USER_IS_MEMBER_OF
        5. Foreach: invalidate AssocListSort.entity_id.GROUP_HAS_USER_MEMBER.dob

      On Assoc Create
      ---------------
        1. Create Group_id.GROUP_HAS_USER_MEMBER.User_id
        2. Invalidate:
            - AssocList.Group_id.GROUP_HAS_USER_MEMBER
        3. Lookup Assoc_sort_map2.GROUP_HAS_USER_MEMBER. Foreach attr:
            - AssocListSort.Group_id.GROUP_HAS_USER_MEMBER.attr_id  (display_name, dob)



     */
        // END SORTED ASSOCIATIONS -------------------------------------------------------------


    // STATICS

    private function delta($assoc_type) {
  //      return (in_array($assoc_type, self::$DELTA_ENABLED)) ? true : false;
    }

    private function counter($assoc_type) {
//        return (in_array($assoc_type, self::$COUNTER_ENABLED)) ? true : false;
    }

    /**
     * @param $assoc_type
     * @returns int|bool
     * @throws CodedException
     */
    public static function reverse($assoc_type) {
        return $assoc_type * -1;
    }

    // END STATICS

    /** @var  Storage storage */
    private $storage;

    /** @var  Changeset changeset */
    private $changeset;

    public function __construct($params, $config) {
        if (!($this->entity_id = Common::getIfSet($params[EntityFactory::ATTR_ENTITY_ID], false))) {
            throw new CodedException(self::EXCEPTION_ASSOC_NO_ENTITY_ANCHOR);
        }

        $this->schema_extension_table = Common::getIfSet($params[EntityFactory::ATTR_ENTITY_SCHEMA_EXTENSION_TABLE]);

        $this->storage = LettuceGrow::extension('Storage');
        $this->changeset = LettuceGrow::extension('Changeset');
    }

    public function alterType($entity_id1, $assoc_type, $entity_id2, $new_assoc_type, $update_reverse = true) {

        // REVIEW PRIOR TO USE
        throw new CodedException('NYR: Function requires review');
/*
        $q = $this->db->prepare('UPDATE association SET assoc_type=:new_assoc_type WHERE entity_id1=:entity_id1 AND assoc_type=:assoc_type AND entity_id2=:entity_id2');
        $q->execute(Array(
            'entity_id1' => $entity_id1,
            'assoc_type' => $assoc_type,
            'entity_id2' => $entity_id2,
            'new_assoc_type' => $new_assoc_type
        ));

        $this->cacheInvalidate($entity_id1, $assoc_type, $entity_id2);
        $this->cacheInvalidate($entity_id1, $new_assoc_type, $entity_id2);

        //$this->setAssocLastUpdated($entity_id1, $assoc_type, $entity_id2);

        if (self::delta($assoc_type)) {
            $this->changeset->trackChangeAssoc($entity_id1, $assoc_type, $entity_id2, Changeset::CHANGE_REMOVE);
        }

        if (self::delta($new_assoc_type)) {
            $this->changeset->trackChangeAssoc($entity_id1, $new_assoc_type, $entity_id2, Changeset::CHANGE_ADD);
        }

        // Handle reverse association
        if ($update_reverse !== false) {
            $reverse_assoc_type = self::reverse($assoc_type);
            $new_reverse_assoc_type = self::reverse($new_assoc_type);
            return $this->alterType($entity_id2, $reverse_assoc_type, $entity_id1, $new_reverse_assoc_type, false);
        } else {
            return true;        // Association altered.
        }
*/
    }

    public function addLurq($field_list, $watched_entity_id) {
        $lurq = $this->getSingle(self::X_LURQ_TO, $watched_entity_id);
        if ($lurq) {

        }

        $this->add(self::X_LURQ_TO, $watched_entity_id, true, false);
    }

    public function removeLurq($field_list, $watched_entity_id) {

    }

    public function delete($assoc_type, $to_entity_id, $update_reverse = true) {
        $this->doDelete($this->entity_id, $assoc_type, $to_entity_id);
        if ($update_reverse) {
            $this->doDelete($to_entity_id, $assoc_type * -1, $this->entity_id);
        }
    }

    private function doDelete($from_entity_id, $assoc_type, $to_entity_id) {

        $this->storage->delete(
            null,   // All derived association keys are invalidated below
            'DELETE FROM association WHERE entity_id1=:entity_id1 AND assoc_type=:assoc_type AND entity_id2=:entity_id2',
            [
                'entity_id1' => $from_entity_id,
                'assoc_type' => $assoc_type,
                'entity_id2' => $to_entity_id
            ]
        );

        $this->updateCount($assoc_type, false);
        $this->invalidateAssoc($from_entity_id, $assoc_type, $to_entity_id);
        if (self::delta($assoc_type)) {
            $this->changeset->trackChange($from_entity_id, Changeset::OP_REMOVE, $assoc_type, $to_entity_id);
        }
    }

    public function getCount($assoc_type) {
        if ($this->entity_id == null) {
            return false;
        }

        $counter_key = self::STORAGE_KEY_ASSOC_COUNT . '.' . $this->entity_id .'.' . $assoc_type;

        $result = $this->storage->retrieve(
            $counter_key,
            'SELECT COUNT(*) FROM association WHERE entity_id1=:entity_id AND assoc_type=:assoc_type', [
                'entity_id' => $this->entity_id,
                'assoc_type' => $assoc_type
            ], [
                Storage::OPT_DURABLE_FETCH_STYLE => Storage::DURABLE_FETCH_STYLE_COL,
                Storage::OPT_DURABLE_FETCH_ARGUMENT => 0,
                Storage::OPT_DURABLE_COLLAPSE_SINGLE => true
            ]
        );

        return $result;
    }

    private function updateCount($assoc_type, $increment = true) {
        if ($this->counter($assoc_type)) {
            $counter_key = self::STORAGE_KEY_ASSOC_COUNT . '.' . $this->entity_id . '.' . $assoc_type;

            // If key exists, increment/decrement it and return the result
            $result = $this->storage->updateCounter($counter_key, ($increment) ? Storage::PARAM_COUNTER_INCREMENT : Storage::PARAM_COUNTER_DECREMENT);

            // If there was no result, get the current value from the database and return that
            if (!$result) {
                $result = $this->getCount($this->entity_id, $assoc_type);    // Load the Key (DB or Memcache, it doesn't matter)
            }
            return $result;
        }
        return null;
    }



    public function getAssocMap($to_entity_id) {
        return $this->storage->retrieve(
            self::STORAGE_KEY_ASSOC_MAP . '.' . $this->entity_id . '.' . $to_entity_id,
            'SELECT assoc_type FROM association WHERE entity_id1=:entity_id1 AND entity_id2=:entity_id2', [
                'entity_id1' => $this->entity_id,
                'entity_id2' => $to_entity_id
            ], [
                Storage::OPT_DURABLE_FETCH_STYLE => Storage::DURABLE_FETCH_STYLE_COL,
                Storage::OPT_DURABLE_FETCH_ARGUMENT => 0
            ]
        );
    }

    public function getSingle($assoc_type) {
        $result = $this->getList($assoc_type);
        $size = count($result);
        if ($size > 0) {
            if ($size > 1) {
                throw new CodedException(self::EXCEPTION_ASSOC_MORE_THAN_ONE, null, $this->entity_id. '|'.$assoc_type);
            } else {
                return $result[0];
            }
        } else {
            return null;
        }
    }

    public function getList($assoc_type, $entity_id2 = null, $by_attribute = null, $range_start = null, $range_end = null, $no_cache = false) { // Do we need an attribute filter range? Ie >B <E or >16 <18? LIMIT isn't appropriate for this, it should only be used for pageing
        if ($entity_id2) {
            $cache_key = self::STORAGE_KEY_ASSOC . '.' . $this->entity_id . '.' . $assoc_type . '.' . $entity_id2;
            $result = $this->storage->retrieve(
                $cache_key, 'SELECT entity_id2 as entity_id, time_added FROM association WHERE entity_id1=:entity_id1 AND assoc_type=:assoc_type AND entity_id2=:entity_id2', [
                'entity_id1' => $this->entity_id,
                'assoc_type' => $assoc_type,
                'entity_id2' => $entity_id2
            ], [
                Storage::OPT_DURABLE_FETCH_STYLE => Storage::DURABLE_FETCH_STYLE_COL,
                Storage::OPT_DURABLE_FETCH_ARGUMENT => 0,
                Storage::OPT_STORAGE_NO_CACHE => $no_cache
            ]);
        } else {
            if ($by_attribute) {
                $join = self::assocAttrJoinTable($assoc_type, $by_attribute);
                if ($join) {
                    $range = ($range_start) ? $range_start . ($range_end ? '.' . $range_end : null) : null;

                    $cache_key = $this->storage->getVolatileKey(
                        $this->storage->getVolatileKey(
                            self::STORAGE_KEY_ASSOC_LIST . '.' . $this->entity_id,
                            $assoc_type
                        ),
                        $by_attribute, $range  // TODO Range doesn't actually do anything?? Broken!
                    );

                    $result = $this->storage->retrieve(
                        $cache_key
                    );

                    if (!$result) {
                        $unsorted_result = $this->storage->retrieve(
                            null,
                            'SELECT entity_id2 AS entity_id, time_added, ' . $by_attribute . ' FROM association JOIN ' . $join . ' ON entity_id2=entity_id WHERE entity_id1=:entity_id AND assoc_type=:assoc_type',
                            [
                                'entity_id' => $this->entity_id,
                                'assoc_type' => $assoc_type
                            ]
                        );

                        // TODO: this isn't needed. Oops. Just use the correct index. How much time wasted on this?
                        // REVIEW: Not so fast, maybe it is... sigh

                        // No automatic caching on this query, we'll cache it manually in a modified format
                        $result = [];
                        foreach($unsorted_result as $row) {
                            $result[$row[EntityFactory::ATTR_ENTITY_ID]] = $row[$by_attribute];
                        }
                        natcasesort($result);

                        if (!$no_cache) {
                            $this->storage->createVolatile($cache_key, (count($result) > 0) ? $result : Storage::NO_RESULT);
                        }
                    }

                    $result = ($result === -1) ? [] : array_keys($result);
                } else {
                    throw new CodedException(self::EXCEPTION_ASSOC_ATTR_INVALID, null, $assoc_type.'|'.$by_attribute);
                }
            } else {
                // TODO Range doesn't even apply here. Well Broken!
                $cache_key = self::STORAGE_KEY_ASSOC_LIST . '.' . $this->entity_id;
                $collection = null;

                // Determine if advanced collection support is enabled for this association type
                if (self::assocAttrJoinTable($assoc_type)) {
                    $cache_key = $this->storage->getVolatileKey($cache_key, $assoc_type);
                } else {
                    $cache_key .= '.' . $assoc_type;
                }

                $result = $this->storage->retrieve(
                    $cache_key, 'SELECT entity_id2 as entity_id, time_added FROM association WHERE entity_id1=:entity_id AND assoc_type=:assoc_type', [
                        'entity_id' => $this->entity_id,
                        'assoc_type' => $assoc_type
                    ], [
                        Storage::OPT_DURABLE_FETCH_STYLE => Storage::DURABLE_FETCH_STYLE_COL,
                        Storage::OPT_DURABLE_FETCH_ARGUMENT => 0,
                        Storage::OPT_STORAGE_NO_CACHE => $no_cache
                    ], 0, false
                );
            }
        }

        return $result;
    }

    /**
     * @param      $entity_id1
     * @param      $assoc_id
     * @param      $to_entity_id
     * @param bool $update_reverse
     * @param bool $invalidate
     *
     * @returns bool
     *
     * @throws CodedException
     */

    // Change this to $invalidate = true, $update_reverse = true, $invalidate_reverse = true <- merge into options array? [ OPT_UPDATE_REVERSE, OPT_INVALIDATE, OPT_INVALIDATE_REVERSE] ?? see Storage Classes
    // public function add($assoc_id, $entity_id, $options = [OPT_UPDATE_REVERSE => true, OPT_INVALIDATE => true, OPT_INVALIDATE_REVERSE => true])

    public function add($assoc_id, $to_entity_id, $data = null, $update_reverse = true, $invalidate = true) {
        // TODO transactionalise this, rollback both directions if either forward OR reverse fail.
        $forward_result = $this->doAdd($this->entity_id, $assoc_id, $to_entity_id, $data, $invalidate);
        if ($update_reverse && $forward_result) {   // Only process reverse if forward is successful
            $reverse_result = $this->doAdd($to_entity_id, $assoc_id * -1, $this->entity_id, $data, $invalidate);
            return $forward_result & $reverse_result;
        }
        return $forward_result;
    }

    private function doAdd($from_entity_id, $assoc_id, $to_entity_id, $data = null, $invalidate = false) { //$update_reverse = true, $invalidate = true) {
        try {
            $time = floor(microtime(true) * 1000);
            $this->storage->createDurable(
                'association', [
                    'entity_id1'    => $from_entity_id,
                    'assoc_type'    => $assoc_id,
                    'entity_id2'    => $to_entity_id,
                    'time_added'    => $time,
                    'data'          => $data
                ]
            );

            $this->updateCount($assoc_id, true);

            if ($invalidate) {
                $this->invalidateAssoc($from_entity_id, $assoc_id, $to_entity_id);
            }

            if (self::delta($assoc_id)) {
                $this->changeset->trackChange($from_entity_id, Changeset::OP_ADD, $assoc_id, $to_entity_id, $time);
            }

            return true;

        } catch (PDOException $e) { // TODO: Move PDOException constraint voilation to the storage provider and issue a storage exception instead
            if (strpos($e->getMessage(), Storage::ERROR_DUPLICATE_ENTRY)) {
                return false;       // Association already exists
            } else if (strpos($e->getMessage(), Storage::ERROR_CONSTRAINT_VIOLATION)) {
                throw new CodedException(EntityFactory::EXCEPTION_ENTITY_ID_NOT_FOUND, $e);
            } else {
                throw new CodedException(Common::EXCEPTION_UNEXPECTED, $e);
            }
        } catch (Exception $e) {
            throw new CodedException(Common::EXCEPTION_UNEXPECTED, $e);
        }
    }

    private function invalidateAssoc($from_entity_id, $assoc_id, $to_entity_id) {
        $this->doInvalidateAssoc($from_entity_id, $assoc_id, $to_entity_id);
/*        if ($update_reverse) {
            $this->doInvalidateAssoc($to_entity_id, $assoc_id * -1, $from_entity_id);
        }*/
    }

    private function doInvalidateAssoc($from_entity_id, $assoc_id, $to_entity_id) {
        $key = self::STORAGE_KEY_ASSOC_LIST . '.' . $from_entity_id . '.' . $assoc_id;

        // Remove the key and it's CID (if needed)
        $this->storage->delete($key, null, null, [Storage::OPT_VOLATILE_DELETE_CID => $this->assocAttrJoinTable($assoc_id)]);

        // Remove the directed cache entry id1->type->id2
        $this->storage->delete(self::STORAGE_KEY_ASSOC . '.' . $from_entity_id . '.' . $assoc_id . '.' . $to_entity_id);

        // Remove the Assoc Map for the entity
        $this->storage->delete(self::STORAGE_KEY_ASSOC_MAP . '.' . $from_entity_id . '.' . $to_entity_id);
    }
}