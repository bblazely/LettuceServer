<?php

class request_controller extends LettuceController {

    const   ACTION_INVITE_ACCEPT = 'accept',
            ACTION_INVITE_DECLINE = 'decline',
            ACTION_INVITE_CANCEL = 'cancel',

            EXCEPTION_INVALID_ACTION = 'Request::InvalidAction';

    public function root($message_id = null) {
        /* ACL_START */
        $session = $this->getSession();
        // TODO is there any security on this at all? Review.
        /* ACL_END */

        switch ($_SERVER['REQUEST_METHOD']) {
            case 'GET':    // Get all requests for the currently logged in user (hmm - extend this to specified entity id?)
                if (strtolower($message_id) == 'count') {
                    $this->view->output($this->model->getPendingRequestCount($session->getUserId()));
                } else {
                    $this->view->output($this->model->getPendingRequest($session->getUserId(), $message_id));
                }
                break;
            case 'POST':
                try {
                    $to_entity_id = $this->getRequestValue('to_entity_id');
                    $for_entity_id = $this->getRequestValue('for_entity_id');
                    $socialnetwork = $this->getRequestValue('socialnetwork');

                    $request = $this->model->inviteEntityById($for_entity_id, $to_entity_id, $session->getUserId(), $socialnetwork);
                    if ($request) {
                        $this->view->output(
                            $request,
                            Request::CODE_CREATED,
                            $this->model->getChangeset()
                        );
                        die();
                    } else {
                        $this->view->httpResponseCode(Request::CODE_CONFLICT);
                        die();
                    }
                } catch (CodedException $e) {
                    if ($e->getMessage() == EntityFactory::EXCEPTION_ENTITY_ID_NOT_FOUND) {
                        $this->view->exception(Request::CODE_NOT_FOUND, $e);
                    } else {
                        $this->view->exception(Request::CODE_INTERNAL_ERROR, $e);
                    }
                }
                break;

            case 'PUT':
                $message_id = $this->getRequestValue('request_id');
                $action = $this->getRequestValue('action');
                $output = null;
                switch ($action) {
                    case self::ACTION_INVITE_ACCEPT:
                        $output = $this->model->acceptInviteRequest($message_id);
                        break;
                    case self::ACTION_INVITE_DECLINE:
                        $this->model->declineInviteRequest($message_id);
                        break;
                    default:
                        $this->view->exception(Request::CODE_BAD_REQUEST, new CodedException(self::EXCEPTION_INVALID_ACTION, null, $action));
                }

                $this->view->output($output, Request::CODE_OK, $this->model->getChangeset());
                break;

            case 'DELETE':
                if ($message_id != null) {
                    $this->model->declineInviteRequest($message_id);
                    $this->view->output(null, Request::CODE_OK, $this->model->getChangeset());
                } else {
                    $this->view->httpResponseCode(Request::CODE_BAD_REQUEST);
                }
                break;

            default:
                $this->view->method(Request::METHOD_POST, Request::METHOD_DELETE, Request::METHOD_GET, Request::METHOD_PUT);
                die();
        }
    }
}

class request_model extends LettuceModel {

    private function getSocialRequest($request_id) {
        $request = $this->entity($request_id, 'MessageRequest');
        $social_invite = $request->getAttribute('socialnetwork', EntityFactory::SCOPE_PRIVATE);

        if ($social_invite) {
            return $social_invite;
        }
        return null;
    }

    private function removeSocialRequest($social_invite) {
        // TODO Move this to a backend processing queue as it could delay the front end, isn't critical and it adds about 750ms to the request
        if (is_array($social_invite)) {
            /** @var SocialNetwork $sp */
            $sp = $this->grow->module('SocialNetwork');

            /** @var iSocialNetworkExtras $provider */
            $provider = $sp->getNetwork($social_invite[SocialNetwork::ATTR_PROVIDER_ID]);
            if ($provider instanceof iSocialNetworkExtras) {
                $provider->removeRequest($social_invite[SocialNetwork::ATTR_PROVIDER_USER_ID], $social_invite[iSocialNetworkExtras::ATTR_PROVIDER_REQUEST_ID]);
            }
        }

    }

    function acceptInviteRequest($request_id) {
        $for_entity_id = null;
        $for_entity = null;
        $public_record = null;

        /** @var PublicIdHelper $prh */
        $prh = $this->grow->module('PublicRecordHelper');
        $this->getSocialRequest($request_id);
        $this->removeSocialRequest($this->getSocialRequest($request_id));
        $this->requireTransaction();

        $for_entity_id_list = $this->entity_factory->getAssocList($request_id, Association::REQUEST_FOR);    // Determine which entity was the invitation target
        if (count($for_entity_id_list) > 0) {
            $for_entity_id = $for_entity_id_list[0];
            $for_entity = $this->entity($for_entity_id);
            $public_record = $prh->getPublicIdForEntity($for_entity_id);

            $to_entity_id_list = $this->entity_factory->getAssocList($for_entity_id_list[0], Association::GROUP__INVITES_MEMBER);  // Get the entity which is being invited
            if (count($to_entity_id_list) > 0) {
                $this->assoc()->alter($for_entity_id_list[0], Association::GROUP__INVITES_MEMBER, $to_entity_id_list[0], Association::GROUP__HAS_MEMBER);  // Change the For->Invite->To assoc to For->Member->To
            }
        }

        // Delete the request ID (and all it's associations)
        $this->entity()->delete($request_id);
        $this->endTransaction();

        if ($for_entity_id) {
            return Array(
                $for_entity_id => array_merge(
                    $for_entity->getAttributes(EntityFactory::SCOPE_ENTITY | EntityFactory::SCOPE_PUBLIC, true),
                    $public_record->getAttributes(EntityFactory::SCOPE_PUBLIC, true)
                )
            );
        }

        return null;
    }

    function declineInviteRequest($request_id) {
        $this->removeSocialRequest($this->getSocialRequest($request_id));

        $this->requireTransaction();
        $for_entity_id_list = $this->entity_factory->getAssocList($request_id, Association::REQUEST_FOR);    // Determine the inviting entity
        if (count($for_entity_id_list) > 0) {
            $to_entity_id_list = $this->entity_factory->getAssocList($for_entity_id_list[0], Association::GROUP__INVITES_MEMBER); // Determine the invited entity
            if (count($to_entity_id_list) > 0) {
                $ghost_id_list = $this->entity_factory->getAssocList($request_id, Association::REQUIRES);
                if (count($ghost_id_list) > 0) {
                    $ghost = $this->entity($ghost_id_list[0], 'Ghost');
                    $to = $this->entity($to_entity_id_list[0]);
                    $ghost->setAttribute(Common::ATTR_DISPLAY_NAME, $to->getAttribute(Common::ATTR_DISPLAY_NAME));
                    $ghost->update();
                    $this->entity_factory->deleteAssoc($ghost_id_list[0], Association::GHOST__FOR, $to_entity_id_list[0]);
                }

                $this->entity_factory->deleteAssoc($for_entity_id_list[0], Association::GROUP__INVITES_MEMBER, $to_entity_id_list[0]); // Delete the invitation association For->Invite->To
            }
        }

        $this->entity_factory->deleteEntity($request_id);
        $this->endTransaction();
    }

    function getPendingRequestCount($entity_id) {
        // TODO Extend this to return read/unread count;
        return $this->entity_factory->getAssocCount($entity_id, Association::HAS_PENDING_REQUEST);
    }

    function getPendingRequest($entity_id, $request_id = null) {
        /** @var EntityPublicId $from_public_record */
        $from_public_record = $this->entity(null, EntityFactory::SCHEMA_PUBLIC_ID);
        $for_public_record = $this->entity(null, EntityFactory::SCHEMA_PUBLIC_ID);

        $data = array();

        $request_id_list = $this->entity_factory->getAssocList($entity_id, Association::HAS_PENDING_REQUEST, $request_id);
        foreach ($request_id_list as $request_id) {

            $request = $this->entity($request_id, 'MessageRequest');

            $from_id = $this->entity_factory->getAssocList($request_id, Association::MESSAGE_SENT_FROM);
            $from = $this->entity($from_id[0]);

            $for_id = $this->entity_factory->getAssocList($request_id, Association::REQUEST_FOR);
            $for = $this->entity($for_id[0]);

            $by_public_record_id = $this->entity_factory->getAssocList($from_id[0], Association::PR__HAS);
            if ($by_public_record_id) {
                $from_public_record->setId($by_public_record_id[0]);
            }

            $for_public_record_id = $this->entity_factory->getAssocList($for_id[0], Association::PR__HAS);
            if ($for_public_record_id) {
                $for_public_record->setId($for_public_record_id[0]);
            }

            $data[$request_id] = Array(
                EntityFactory::ATTR_ENTITY_ID => $request->getAttribute(EntityFactory::ATTR_ENTITY_ID, EntityFactory::SCOPE_ENTITY),
                EntityBase::ATTR_TIME_CREATED => $request->getAttribute(EntityBase::ATTR_TIME_CREATED, EntityFactory::SCOPE_ENTITY),
                EntityMessageRequest::REQUEST_TYPE => $request->getAttribute(EntityMessageRequest::REQUEST_TYPE, EntityFactory::SCOPE_PUBLIC),
                'for' => array_merge(
                    $for->getAttributes(EntityFactory::SCOPE_ENTITY | EntityFactory::SCOPE_PUBLIC, true),
                    $for_public_record->getAttributes(EntityFactory::SCOPE_PUBLIC, true)
                ),
                'by' => array_merge(
                    $from->getAttributes(EntityFactory::SCOPE_ENTITY | EntityFactory::SCOPE_PUBLIC, true),
                    $from_public_record->getAttributes(EntityFactory::SCOPE_PUBLIC, true)
                )
            );
        }

        return $data;

    }


    // Todo: This may be better in a central 'request library' rather than a model and get the various entities to talk to it...
    // Todo: Consider how the request structure will work for various entity types.
    function inviteEntityById($for_entity_id, $to_entity_id, $from_entity_id, $socialnetwork = null) {

        $is_member = $this->assoc()->getList($for_entity_id, Association::GROUP__HAS_MEMBER, $to_entity_id);
        if (count($is_member) === 0) {
            if ($this->assoc()->add($for_entity_id, Association::GROUP__INVITES_MEMBER, $to_entity_id)) {       // Try and invite the entity
                /** @var EntityGhost $ghost */
                $ghost = $this->entity(null, EntityFactory::SCHEMA_GHOST);

                /** @var EntityMessageRequest $request */
                $request = $this->entity(null, EntityFactory::SCHEMA_MESSAGE_REQUEST);

                /** @var EntityBase $to_entity */
                $to_entity = $this->entity($to_entity_id);
                $display_name = $to_entity->getAttribute(Common::ATTR_DISPLAY_NAME); // Get Display Name of EntityFactory to Replicate onto Ghost

                $this->requireTransaction();
                // TRANSACTION START ------------------------------------------

                $ghost_id = $ghost->register($display_name);     // Register Ghost
                $request_id = $request->register($from_entity_id, $to_entity_id, $to_entity_id, $ghost_id, [Association::GHOST__FOR], 'Test. You are invited!');

                // Set up the Ghost (Move this into the ghost entity now that it is assoc aware?)
                $this->assoc()->add($ghost_id, Association::GHOST__FOR,                 $to_entity_id);        // Ghost <-> User
                $this->assoc()->add($ghost_id, Association::GROUP__GHOST_MEMBER_OF,     $for_entity_id);       // Ghost <-> Group Member

                // TRANSACTION END ------------------------------------------
                $this->endTransaction();

                $return_data = $ghost->getAttributes(EntityFactory::SCOPE_ENTITY | EntityFactory::SCOPE_PUBLIC, true);
                $return_data['display_name'] = $display_name;
                $return_data['pending'] = 1;
                $return_data['ghosts_id'] = $to_entity_id;

                return Array(
                    $ghost_id => $return_data
                );
            }
        }

        // Fail
        if ($socialnetwork) {
            $this->removeSocialRequest($socialnetwork);
        }
        return false;
    }
}