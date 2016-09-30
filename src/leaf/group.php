<?php

class group_controller extends LettuceController {

    const   TYPE_INVITE_MEMBER      = 0,
            TYPE_INVITE_VIEWER      = 1,

            EXCEPTION_INVITE_PENDING = 'Group::ExceptionInviteAlreadyIssued';

    public function invite($to_entity_id, $for_entity_id, $type) {
        /* ACL_START */
        $session = $this->requireAssoc($to_entity_id, [Association::GROUP__OWNER_OF]);
        /* ACL_END */

        switch ($_SERVER['REQUEST_METHOD']) {
            case 'POST':    // Create Request (Invite User as Member)
                try {
                    $user_id = $this->getRequestValue('user_id');

                    $this->view->httpResponseCode(Request::CODE_CREATED);
                } catch (CodedException $e) {
                    $this->view->exception(Request::CODE_FORBIDDEN, $e);
                }
                break;

            case 'DELETE':
                // NYI
                break;

            default:
                $this->view->method(Request::METHOD_POST, Request::METHOD_DELETE);
                die();
        }
    }

    public function member($entity_id, $member_id = null) {
        /* START_ACL */
        $this->requireAssoc($entity_id, [Association::GROUP__OWNER_OF, Association::GROUP__CAN_VIEW, Association::GROUP__MEMBER_OF]);
        /* END_ACL */

        $this->view->output($this->model->getMemberList($entity_id, $member_id));
    }

    public function member_count($entity_id) {
        /* ACL_START */
        $this->requireAssoc($entity_id, [Association::GROUP__OWNER_OF, Association::GROUP__CAN_VIEW, Association::GROUP__MEMBER_OF]);
        /* ACL_END */

        $this->view->output($this->model->getMemberCount($entity_id));
    }

}

class group_model extends LettuceModel {

    public function getMemberCount($entity_id) {
        return $this->entity_factory->getAssocCount($entity_id, Association::GROUP__HAS_GHOST_MEMBER);
    }

    public function getMemberList($entity_id, $member_id = null) {
        $output = Array();
        /** @var Association $assoc */
        $assoc = $this->grow->module('Association');
        $ghost_id_list = $assoc->getList($entity_id, Association::GROUP__HAS_GHOST_MEMBER, $member_id, Common::ATTR_DISPLAY_NAME);

        $ghost = $this->entity(null, EntityFactory::SCHEMA_GHOST);
        $user = $this->entity(null, EntityFactory::SCHEMA_USER);

        foreach ($ghost_id_list as $ghost_id) {
            $linked_data = Array();
            $ghost->setId($ghost_id);
            $user_id = $assoc->getSingle($ghost_id, Association::GHOST__FOR);
            if ($user_id) {
                /** @var EntityPerson $user */
                $user->setId($user_id);

                $linked_data['ghosts_id'] = $user_id;
                $request_id_list = $assoc->getList($ghost_id, Association::REQUIRED_BY, false);
                if ($request_id_list) {
                    $linked_data['pending'] = 1;
                    $linked_data[Common::ATTR_DISPLAY_NAME] = $user->getAttribute(Common::ATTR_DISPLAY_NAME);     // Pending, only return limited user data
                } else {
                    $linked_data = array_merge($linked_data, $user->getAttributes(EntityFactory::SCOPE_PUBLIC, true));  // Return the entire user public data
                    $public_record_id_list = $assoc->getSingle($user_id, Association::PR__HAS);
                    if ($public_record_id_list) {
                        $public_record = $this->entity($public_record_id_list, EntityFactory::SCHEMA_PUBLIC_ID);
                        $linked_data = array_merge($linked_data, $public_record->getAttributes(EntityFactory::SCOPE_PUBLIC, true));
                    }
                }
            }

            $output[$ghost_id] = array_merge(
                $ghost->getAttributes(EntityFactory::SCOPE_ENTITY | EntityFactory::SCOPE_PUBLIC, true),
                $linked_data
            );
        }

        return $output;
    }
}