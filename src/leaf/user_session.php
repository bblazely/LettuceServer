<?php

class user_session_controller extends LettuceController {
    function root($persist = false) {

        switch($_SERVER['REQUEST_METHOD']) {
            case 'DELETE':  // Delete current user_session (logout)
                $session = $this->getSession(false, true);
                if ($session) {
                    if ($session->remove()) {
                        $this->view->httpResponseCode(Request::CODE_NO_CONTENT);
                    } else {
                        $this->view->httpResponseCode(Request::CODE_NOT_FOUND, new CodedException(UserSession::EXCEPTION_SESSION_NOT_LOGGED_IN));
                    }
                } else {
                    $this->view->httpResponseCode(Request::CODE_NOT_FOUND, new CodedException(UserSession::EXCEPTION_SESSION_NOT_LOGGED_IN));
                }
                break;

            case 'GET':     // Resume Session (if exists)
                $session_data = $this->getSession(false, true)->resume($persist);
                if ($session_data) {
                    $this->view->output($session_data);
                } else {
                    $this->view->exception(Request::CODE_NOT_FOUND, new CodedException(UserSession::EXCEPTION_SESSION_NOT_LOGGED_IN));
                }
                break;

            default:
                $this->view->method(Request::METHOD_GET, Request::METHOD_DELETE);
                break;
        }
    }
}
