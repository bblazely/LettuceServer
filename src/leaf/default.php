<?php

Class default_controller extends LettuceController {
    const EXCEPTION_INVALID_API_CALL    = 'LettuceController::InvalidAPICall';
    public function root() {
        /* ACL_PUBLIC */
        $this->view->exception(Request::CODE_BAD_REQUEST, new CodedException(self::EXCEPTION_INVALID_API_CALL));
    }
}

