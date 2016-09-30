<?php

class message_controller extends LettuceController {

    public function root($message_id = null) {
        /* ACL_START */
        $session = $this->getSession();
        // TODO is there any security on this at all? Review.
        /* ACL_END */

        switch ($_SERVER['REQUEST_METHOD']) {
            case Request::METHOD_GET:    // Get all requests for the currently logged in user (hmm - extend this to specified entity id?)
                if (strtolower($message_id) == 'count') {
               //     $this->view->output($this->model->getPendingMessageCount($session->getUserId()));
                } else {
                    $this->view->output($this->model->getPendingMessage($session->getUserId(), $message_id));
                }
                break;
            /*case 'POST':
                try {
                    $to_entity_id = $this->getRequestValue('to_entity_id');
                    $for_entity_id = $this->getRequestValue('for_entity_id');
                    $socialnetwork = $this->getRequestValue('socialnetwork');

                    $request = $this->model->inviteEntityById($for_entity_id, $to_entity_id, $session->getUserId(), $socialnetwork);
                    if ($request) {
                        $this->view->output(
                            $request,
                            Response::CODE_CREATED,
                            $this->model->getChangeset()
                        );
                        die();
                    } else {
                        $this->view->responseCode(Response::CODE_CONFLICT);
                        die();
                    }
                } catch (CodedException $e) {
                    if ($e->getMessage() == EntityFactory::EXCEPTION_ENTITY_ID_NOT_FOUND) {
                        $this->view->exception(Response::CODE_NOT_FOUND, $e);
                    } else {
                        $this->view->exception(Response::CODE_INTERNAL_ERROR, $e);
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
                        $this->view->exception(Response::CODE_BAD_REQUEST, new CodedException(self::EXCEPTION_INVALID_ACTION, null, $action));
                }

                $this->view->output($output, Response::CODE_OK, $this->model->getChangeset());
                break;*/

            case 'DELETE':
                if ($message_id != null) {
                   // $this->model->declineInviteRequest($message_id);
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

class message_model extends LettuceModel {

    function getPendingMessageCount($entity_id) {
        // TODO Extend this to return read/unread count;
        return $this->entity_factory->getAssocCount($entity_id, Association::MSG__HAS_UNREAD); // Needs to accept HAS_MESSAGE too
    }

    function getPendingMessage($entity_id, $message_id = null) {
        /** @var EntityPublicId $from_public_record */
        $from_public_record = $this->entity(null, EntityFactory::SCHEMA_PUBLIC_ID);
       // $for_public_record = $this->entity->growByType(EntityFactory::SCHEMA_PUBLIC_ID);

        $data = array();

        $message_id_list = $this->assoc()->getList($entity_id, Association::MSG__RECIPIENT_OF, $message_id);
        foreach ($message_id_list as $message_id) {

            $message = $this->entity($message_id);
            $from_id = $this->assoc()->getSingle($message_id, Association::MSG__SENT_BY);

            $from_public_record_id = $this->assoc()->getSingle($from_id, Association::PR__HAS);
            if ($from_public_record_id) {
                $from_public_record->setId($from_public_record_id);
            }

            $data[$message_id] = Array(
                EntityFactory::ATTR_ENTITY_ID => $message->getAttribute(EntityFactory::ATTR_ENTITY_ID, EntityFactory::SCOPE_ENTITY),
                EntityBase::ATTR_TIME_CREATED => $message->getAttribute(EntityBase::ATTR_TIME_CREATED, EntityFactory::SCOPE_ENTITY),
                'type' => $message->getSchemaId(),
                //'message' => $message->getMessage(),
                //EntityMessageRequest::REQUEST_TYPE => $message->getDataElement(EntityMessageRequest::REQUEST_TYPE, EntityFactory::DATA_SCOPE_PUBLIC),
    /*            'for' => array_merge(
                    $for->getData(EntityFactory::DATA_SCOPE_OBJ | EntityFactory::DATA_SCOPE_PUBLIC, true),
                    $for_public_record->getData(EntityFactory::DATA_SCOPE_PUBLIC, true)
                ),*/
                'payload' => $message->payload(), // TODO: make this included optionally IF there is data to load. Rename to "ExtPayload" or something?
                'from' => ($from) ? array_merge(
                    $from->getAll(EntityFactory::SCOPE_ENTITY | EntityFactory::SCOPE_PUBLIC, true),
                    $from_public_record->getAttributes(EntityFactory::SCOPE_PUBLIC, true)
                ) : []
            );
        }

        return $data;

    }
}