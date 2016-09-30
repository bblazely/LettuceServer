<?php

class entity_controller extends LettuceController {
    /** @var  entity_model */
    protected $model;

    function root($entity_id = null) {
        /* ACL_START */
        $session = $this->getSession();
        /* ACL_END */

        switch ($_SERVER['REQUEST_METHOD']) {
            case 'GET':
                // EntityFactory Getter (Type agnostic)
                try {
                    $this->view->output($this->model->getEntity($entity_id));
                } catch (CodedException $e) {
                    $this->view->exception(Request::CODE_NOT_FOUND, $e, $entity_id);
                }
                break;

            case 'POST':
                // EntityFactory Creation (Non User Entities)
                $schema_id       = $this->getRequestValue('schema_id');
                $display_name    = $this->getRequestValue('display_name');
                $assoc_entity_id = $this->getRequestValue('assoc_entity_id');

                // Handle the specific case per entity type
                switch ($schema_id) {
                    case 200:
                        // If an explicit parent/owner wasn't passed in, tie it to the currently logged in user session entity ID

                        if (!$assoc_entity_id) {
                            $assoc_entity_id = $session->getUserId();
                        }

                        try {
                            $this->view->output($this->model->createGroup($display_name, $assoc_entity_id), Request::CODE_CREATED, $this->model->getChangeset());
                        } catch (CodedException $e) {
                            $this->view->exception(Request::CODE_UNPROCESSABLE, $e);
                        }
                        break;

                    case 300:
                        // Ghost Entities require an explicit entity (group) to own them. Check for it here and bail if it's not present.
                        if (!$assoc_entity_id) {
                            $this->view->exception(Request::CODE_BAD_REQUEST, new CodedException(EntityFactory::EXCEPTION_ENTITY_OWNER_INVALID));
                        }

                        try {
                            $this->view->output($this->model->createGhost($display_name, $assoc_entity_id), Request::CODE_CREATED, $this->model->getChangeset());
                        } catch (CodedException $e) {
                            $this->view->exception(Request::CODE_UNPROCESSABLE, $e);
                        }

                        break;

                    default:
                        $this->view->exception(Request::CODE_BAD_REQUEST, new CodedException(EntityFactory::EXCEPTION_ENTITY_SCHEMA_INVALID));
                        break;
                }

                break;
        }
    }

    function permission($entity_id) {
        /* ACL_START */
        $session = $this->getSession();
        /* ACL_END */

        // TODO Extend with custom permissions at a later date

        // Is this the user viewing their profile (quick easy case)
        if ($entity_id == $session->getUserId() || $entity_id == $session->getPublicId()) {
            $this->view->output(Array(0));
            die();
        } else {
            if ($this->requireAssoc($entity_id, [Association::GROUP__OWNER_OF], true, false)) {
                $this->view->output(Array(0));
                die();
            } else if ($this->requireAssoc($entity_id, [Association::GROUP__MEMBER_OF], true, false)) {
                $this->view->output(Array(202));
                die();
            }
        }

        $this->view->httpResponseCode(Request::CODE_FORBIDDEN);
    }

    function search($query_string, $limit = 5, $offset = 0, $entity_type = false) {
        /* ACL_START */
        $this->getSession();
        /* ACL_END */

        if (!$entity_type) {
            $entity_type = '100 200';
        }

        try {
            $this->view->output($this->model->searchPublicId($query_string, $limit, $offset, $entity_type));
        } catch (CodedException $e) {
            $this->view->exception(Request::CODE_INTERNAL_ERROR, $e);
        }
    }
}

class entity_model extends LettuceModel {

    public function createGhost($display_name, $group_id) {

        /** @var EntityGhost $entity_ghost */
        $ghost = $this->entity(null, EntityFactory::SCHEMA_GHOST);
        $ghost_id = $ghost->register($display_name);

        $this->assoc()->add($group_id, Association::GROUP__HAS_GHOST_MEMBER, $ghost_id, null, true, true, false);  // Delta HAS_GHOST_MEMBER, No delta for GHOST_MEMBER_OF

        $output = $ghost->getAttributes(EntityFactory::SCOPE_ENTITY, true);
        $output['display_name'] = $display_name;

        return Array(
            $ghost_id => $output
        );

    }

    public function createGroup($display_name, $assoc_entity_id) {
        /** @var EntityGroup $group */
        $group = $this->entity(null, 'Group');
        $group_id = $group->register($display_name);

        //   /** @var EntityGhost $ghost */
        //    $ghost = $entity->growByType('Ghost');
        //    $ghost_id = $ghost->register(null);  // Register a new ghost for this user

        /** @var EntityPublicId $public_record */
        $public_record = $this->entity(null, EntityFactory::SCHEMA_PUBLIC_ID);
        $public_record_id = $public_record->register($group_id, $first_name = null, $last_name = null, $display_name, 1);

        $this->assoc()->add($assoc_entity_id, Association::GROUP__OWNER_OF, $group_id);
        $this->assoc()->add($public_record_id, Association::PR__FOR, $group_id);
        //    $entity->addAssoc($ghost_id, EntityGhost::ASSOC_GHOSTS, $ref_entity_id);  // Ghost the owner
        //    $entity->addAssoc($ghost_id, EntityGroup::ASSOC_GHOST_MEMBER_OF, $group_id);  // Add a ghost link to the group with an owner role

        return Array(
            $group_id => array_merge(
                $group->getAttributes(EntityFactory::SCOPE_ENTITY | EntityFactory::SCOPE_PUBLIC, true),
                $public_record->getAttributes(EntityFactory::SCOPE_PUBLIC, true)
            )
        );
    }

    public function searchPublicId($query_string, $limit, $offset, $entity_type) {
        /** @var EntityPublicId $public_record */

        $public_record = $this->entity(null, EntityFactory::SCHEMA_PUBLIC_ID);
        $output = Array();
        $results = $public_record->search($query_string, $limit, $offset, $entity_type);

        foreach ($results as $entity_type => $entity_type_data) {
            if (!isset($output[$entity_type])) {
                $output[$entity_type] = Array('el' => Array(), 'tc' => $entity_type_data['tc']);
            }

            foreach ($entity_type_data['el'] as $entity) {
               $e = $this->entity($entity[EntityFactory::ATTR_ENTITY_ID], $entity[EntityFactory::ATTR_SCHEMA_ID]);
               $output[$entity_type]['el'][$entity[EntityFactory::ATTR_ENTITY_ID]] = array_merge($entity, $e->getAttributes(EntityFactory::SCOPE_ENTITY | EntityFactory::SCOPE_PUBLIC, true));
            }
        }
        return $output;
    }

    // EntityFactory lookups can be by public id, entity id or ghost id. All return the entity and a public id (if present)
    public function getEntity($mixed_id) {
        $public_record = null;
        $entity_id = null;

        if (is_numeric($mixed_id)) {
            $entity = $this->entity($mixed_id);
            if ($entity::ENTITY_SCHEMA_ID == EntityFactory::SCHEMA_PUBLIC_ID) {
                $public_record = $entity;
                $entity_id_list = $this->assoc()->getList($mixed_id, Association::PR__FOR, false);
                if (count($entity_id_list) > 0) {
                    $entity_id = $entity_id_list[0];    // SET NUMERIC ID OF ENTITY (FROM PR)
                } else {
                    throw new CodedException(EntityFactory::EXCEPTION_ENTITY_ID_NOT_FOUND, null, 'PR2E');
                }
            } else {
                $entity_id = $mixed_id;
                $public_record_id_list = $this->assoc()->getList($mixed_id, Association::PR__HAS, false);
                if (count($public_record_id_list) > 0) {
                    $public_record_id = $public_record_id_list[0];
                    $public_record = $this->entity($public_record_id, EntityFactory::SCHEMA_PUBLIC_ID);
                } else {
                    throw new CodedException(EntityFactory::EXCEPTION_ENTITY_ID_NOT_FOUND, null, 'E2PR');
                }
            }
        } else {
            /** @var EntityPublicId $public_record */
            $public_record = $this->entity($mixed_id, EntityFactory::SCHEMA_PUBLIC_ID);
            //$public_record_id = $public_record->get($mixed_id);
            $entity_id_list = $this->assoc()->getList($public_record->getId(), Association::PR__FOR, false);
            if (count($entity_id_list) > 0) {
                $entity_id = $entity_id_list[0];        // SET NUMERIC ID
            } else {
                throw new CodedException(EntityFactory::EXCEPTION_ENTITY_ID_NOT_FOUND, null, EntityFactory::SCHEMA_PUBLIC_ID . '|' . $mixed_id);
            }
        }

        // TODO This will throw an error if entity_id isn't found or is null. fix it.
        $blah = $this->entity($entity_id);

        return Array(
            $mixed_id => array_merge(
                $public_record->getAttributes(EntityFactory::SCOPE_PUBLIC, true),
                $blah->getAttributes(EntityFactory::SCOPE_ENTITY | EntityFactory::SCOPE_PUBLIC, true)
            )
        );
    }
}