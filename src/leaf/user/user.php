<?php

class user_controller extends LettuceController {
    /** @var user_model $model */
    protected $model;

    public function register($credential_module, ...$request_path) {
        $credential_module = strtolower($credential_module);
        try {
            // NB all failure states are raised as exceptions and caught below.
            $this->model->register($credential_module, $request_path, $this->request['data']);
            $this->view->httpResponseCode(Request::CODE_NO_CONTENT);
        } catch (CodedException $e) {
            switch ($e->getMessage()) {
                case CredentialsFactory::EXCEPTION_ALREADY_REGISTERED:
                    $this->view->exception(Request::CODE_CONFLICT, $e, true);
                    break;
                case Common::EXCEPTION_NOT_IMPLEMENTED:
                    $this->view->exception(Request::CODE_NOT_IMPLEMENTED, $e, true);
                    break;
                default:
                    $this->view->exception(Request::CODE_UNAUTHORIZED, $e, true);
                    break;
            }
        }
    }

    public function remove_login($target_login_id) {
        try {
            $result = $this->model->userRemoveLogin($target_login_id);
            $this->view->httpResponseCode(Request::CODE_NO_CONTENT);
        } catch (CodedException $ce) {
            switch ($ce->getMessage()) {
                case UserSession::EXCEPTION_SESSION_NOT_LOGGED_IN:
                    $this->view->exception(Request::CODE_FORBIDDEN, $ce);
                    break;

                case (user_model::EXCEPTION_REMOVE_FAIL_LAST_LOGIN):
                    $this->view->exception(Request::CODE_UNPROCESSABLE, $ce);
                    break;

                default:
                    $this->view->exception(Request::CODE_INTERNAL_ERROR, $ce);
                    break;
            }
        }
    }

    public function add_login($target_login_id = null, $hmac = null) {
        try {
            $login_response = $this->model->userAddLogin($target_login_id, $hmac);
            if ($login_response->getCode() == LoginResponse::RESULT_OK) {
                $this->view->httpResponseCode(Request::CODE_NO_CONTENT);
            } else {
                $this->view->exception(Request::CODE_FORBIDDEN, $login_response->getException());
            }
        } catch (CodedException $ce) {
            $this->view->exception(Request::CODE_FORBIDDEN, $ce);
        }
    }

    public function switch($target_user_id = null, $hmac = null) {
        try {
            $login_response = $this->model->switchUserSession($target_user_id, $hmac);
            if ($login_response->getCode() == LoginResponse::RESULT_OK) {
                $this->view->httpResponseCode(Request::CODE_NO_CONTENT);
            } else {
                $this->view->exception(Request::CODE_FORBIDDEN, $login_response->getException());
            }
        } catch (CodedException $ce) {
            $this->view->exception(Request::CODE_FORBIDDEN, $ce);
        }
    }

    public function login($credential_module, ...$request_path) {
        $credential_module = strtolower($credential_module);
        try {
            /** @var LoginResponse $login_response */
            $login_response = $this->model->processLoginRequest($credential_module, $request_path, $this->request['data']);
            switch ($credential_module) {

                /*
                 * Handle responses from an External Login Request
                 */
                case CredentialsFactory::MODULE_EXTERNAL:
                    switch ($login_response->getCode()) {
                        case LoginResponse::RESULT_OK:
                            if ($login_response->isSSO()) { // consider moving this outside of the response code switch
                                $this->view->redirect(Common::getServerURL('/', Common::CONTEXT_PREFIX_UI));
                            } else {
                                $this->view->template('leaf/user/templates/login_external/response', $login_response);
                            }
                            break;

                        case LoginResponse::RESULT_JOIN_LOGIN:  // Intentional Fall Through
                        case LoginResponse::RESULT_SWITCH_LOGIN:

                            $this->view->template('leaf/user/templates/login_external/response', $login_response);
                            break;

                        case LoginResponse::RESULT_PRE_AUTH:
                            $this->view->template('leaf/user/templates/login_external/'.$login_response->getPayload()[CredentialsFactory::ATTR_PROVIDER_ID].'/preauth', $login_response);
                            break;

                        default:
                            $this->view->template('leaf/user/templates/login_external/response', $login_response, false, $login_response->getException());
                            break;
                    }
                    break;

                /*
                 * Handle responses from a Native Login Request
                */
                case CredentialsFactory::MODULE_NATIVE:
                    $this->view->httpResponseCode(Request::CODE_NO_CONTENT);
                    break;
            }
        } catch (RedirectException $r) {
            $this->view->redirect($r->getRedirectUrl(), $r->getCode()); // Redirection issued from a CredentialsFactory Module
            die();
        } catch (CodedException $e) {
            switch ($credential_module) {
                /*
                 * Handle error responses from an External Login Request
                 */
                case CredentialsFactory::MODULE_EXTERNAL:
                    $this->view->template('leaf/user/templates/login_external/response', null, false, $e);
                    break;

                /*
                 * Handle error responses from a Native Login Request
                 */
                case CredentialsFactory::MODULE_NATIVE:
                    $this->view->exception(Request::CODE_UNAUTHORIZED, $e);
                    break;
            }
        }
    }

    public function verification($credential_module, $action, ...$request_path) {
        try {
            switch ($action) {
                case 'resend':
                    switch ($_SERVER['REQUEST_METHOD']) {
                        case Request::METHOD_POST:
                            $this->model->resendVerification($credential_module, $this->request['data']);
                            $this->view->httpResponseCode(Request::CODE_ACCEPTED);
                            break;
                        default:
                            $this->view->method(Request::METHOD_POST);
                            die();
                    }
                    break;
                case 'verify':
                    switch($_SERVER['REQUEST_METHOD']) {
                        case Request::METHOD_GET:
                            $verification_request = $request_path;
                            break;
                        case Request::METHOD_POST:
                            $verification_request = $this->request['data'];
                            break;
                        default:
                            $this->view->method(Request::METHOD_GET, Request::METHOD_POST);
                            die();
                    }

                    $this->model->verify($credential_module, $verification_request);
                    break;
                default:
                    $this->view->exception(Request::CODE_NOT_IMPLEMENTED, new CodedException(Common::EXCEPTION_NOT_IMPLEMENTED, null, __FUNCTION__ . '|' . $action));
                    break;
            }
        } catch (CodedException $e) {
            $this->view->exception(Request::CODE_UNAUTHORIZED, $e);
        } catch (RedirectException $e) {
            $this->view->redirect($e->getRedirectUrl());
        }
    }

//    public function groups($scope) {//Association::GROUP__MEMBER_OF) {
//        /* ACL_START */
//        $session = $this->getSession();
//        /* ACL_END */
//
//        try {
//            $this->view->output($this->model->getGroupMembership($session->getUserId(), $scope));
//        } catch (CodedException $e) {
//            $this->view->exception(Request::CODE_NOT_FOUND, $e);
//        }
//    }
}

class LoginResponse {
        const
            RESULT_FAILED = 0,
            RESULT_OK = 1,
            RESULT_JOIN_LOGIN = 2,
            RESULT_SWITCH_LOGIN = 3,
            RESULT_PRE_AUTH = 4;

        private $login_entity_id, $user_entity_id, $is_sso, $code, $exception, $payload;

        public function __construct(int $code, bool $is_sso = false, int $login_entity_id = null, int $user_entity_id = null, array $payload = [], CodedException $exception = null) {
            $this->login_entity_id = $login_entity_id;
            $this->code = $code;
            $this->is_sso = $is_sso;
            $this->user_entity_id = $user_entity_id;
            $this->payload = $payload;
            $this->exception = $exception;
        }

        public function getException() {
            return $this->exception;
        }

        public function getPayload() {
            return $this->payload;
        }

        public function isSSO() {
            return $this->is_sso;
        }

        public function getCode() {
            return $this->code;
        }

        public function getLoginEntityId() {
            return $this->login_entity_id;
        }

        public function getUserEntityId() {
            return $this->user_entity_id;
        }
}

class user_model extends LettuceModel {

    const
        HMAC_VERIFICATION_SWITCH_USER   = 'UserModel::HMACVerificationSwitchUser',
        HMAC_VERIFICATION_ADD_LOGIN     = 'UserModel::HMACVerificationAddLogin',
        HMAC_SECRET_KEY                 = 'AnotherSecretK3yForHMACValues!',

        EXCEPTION_USER_CREATE_FAILED        = 'UserModel::UserEntityCreationFailed',
        EXCEPTION_INVALID_REGISTRATION      = 'UserModel::InvalidRegistration',
        EXCEPTION_HMAC_VERIFICATION_FAILED  = 'UserModel::HMACVerificationFailed',
        EXCEPTION_REMOVE_FAIL_LAST_LOGIN    = 'UserModel::CannotRemoveLastLogin';

      //  EXCEPTION_NOT_FOUND             = 'UserModel::LoginNotFound';

    public function verify($module, $request) {
        /** @var CredentialsModuleBase $cred */
        $cred = LettuceGrow::extension('CredentialsFactory')->get($module);
        $cred->processVerificationRequest($request);
        $this->createUserSession($cred->resolveToUserId());
    }

    public function resendVerification($module, $request) {
        /** @var CredentialsModuleBase $cred */
        $cred = LettuceGrow::extension('CredentialsFactory')->get($module);
        return $cred->issueVerificationRequest($request);
    }

    public function register($module, $request_path, $profile) {
        /** @var CredentialsModuleBase $cred */
        $cred = LettuceGrow::extension('CredentialsFactory')->get($module, $request_path);

        if($cred->isRegistered($profile)) {
            throw new CodedException(CredentialsFactory::EXCEPTION_ALREADY_REGISTERED);
        } else {
            $this->requireTransaction();
            $cred->register($profile);
            $user_entity = $this->createUserEntity($profile);
            if ($user_entity) {
                $user_entity_id = $user_entity->getId();
                $cred->associateToUserEntity($user_entity_id);
                $this->endTransaction();

                $cred->issueVerificationRequest();
                return true;
            } else {
                $this->endTransaction(false);
                throw new CodedException(self::EXCEPTION_USER_CREATE_FAILED);
            }
        }
    }

    private function createUserEntity($profile) {
        /** @var EntityUser $entity_user */
        $entity_user = $this->entity()->get(null, EntityFactory::SCHEMA_USER);
        $entity_user->register($profile);

        return $entity_user;
    }

    private function createUserSession($user_entity_id) {
        $user_entity = $this->entity()->get($user_entity_id, EntityFactory::SCHEMA_USER);
        if ($user_entity) {
            $this->session()->create($user_entity->getAttributes(EntityFactory::SCOPE_PUBLIC | EntityFactory::SCOPE_ENTITY, true));
            return true;
        } else {
            return false;
        }
    }
    
    /**
     * @param $module
     * @param $request_path
     * @param $request_data
     * @return LoginResponse
     * @throws CodedException
     * @throws RedirectException
     */
    public function processLoginRequest($module, $request_path, $request_data) {
        /** @var CredentialsModuleBase $cred */
        $cred = LettuceGrow::extension('CredentialsFactory')->get($module, $request_path);
        $user_entity_id = null;

        try {
            switch ($cred->authenticate($request_path, $request_data)) {
                case CredentialsFactory::RESULT_AUTH_DO_PRE_OP:
                    return new LoginResponse(LoginResponse::RESULT_PRE_AUTH, false, null, null, [CredentialsFactory::ATTR_PROVIDER_ID => $cred->getProviderId()]);
                //case CredentialsFactory::REQUIRE_POST_AUTH_STEP:
                 //   return;
                default:
                    if (!$cred->isRegistered($request_data)) {
                        $cred->register($request_data);
                    } else {
                        $user_entity_id = $cred->resolveToUserId();
                    }
                    break;
            }
        } catch (CodedException $e) {
            return new LoginResponse(LoginResponse::RESULT_FAILED, $cred->isSSO(), null, null, [], $e);
        }

        $session_user_id = $this->session()->getUserId();

        if (!$user_entity_id) { // No User Entity Found?
            if ($session_user_id) { // Active User Session?
                $hmac = Common::generateVerificationCode(
                    self::HMAC_VERIFICATION_ADD_LOGIN, [      // TODO: add support for creating a new user here as well?
                    $cred->getEntityId(),
                    $session_user_id
                ],
                    self::HMAC_SECRET_KEY,
                    Common::generateExpirationTimeWindow(Common::TIME_PERIOD_5MIN)  // 5 Minutes for a yes/no response here.
                );
                return new LoginResponse(LoginResponse::RESULT_JOIN_LOGIN, $cred->isSSO(), $cred->getEntityId(), $session_user_id, ['leid' => $cred->getEntityId(), 'vc' => $hmac]);
//                throw new RedirectException(LETTUCE_CLIENT_PATH . '#?action=login_add&eid='.$cred->getEntityId().'&response='.$hmac);
            } else {
                // No
                $user_profile = $cred->getProfile();
                if ($user_profile) {                        // Registration Data Provided?
                    $user_entity = $this->createUserEntity($user_profile); // Create User Entity
                    if ($user_entity) {
                        $user_entity_id = $user_entity->getId();
                        $cred->associateToUserEntity($user_entity_id);  // Associate User Entity
                    } else {
                        throw new CodedException(self::EXCEPTION_USER_CREATE_FAILED);
                    }
                } else {
                    throw new CodedException(self::EXCEPTION_INVALID_REGISTRATION);  // Registration Failed
                }
            }
        }

        // User Entity ID should always exist by this point... (See Diagram)
        if (!$cred->isVerified()) {
            throw new CodedException($cred::EXCEPTION_NOT_VERIFIED);
        }

        // Session Management
        if ($session_user_id) { // A session exists
            if ($user_entity_id != $session_user_id) { // And it's not for the current ID
                $hmac = Common::generateVerificationCode(
                    self::HMAC_VERIFICATION_SWITCH_USER, [
                    $user_entity_id,
                    $session_user_id
                ],
                    self::HMAC_SECRET_KEY,
                    Common::generateExpirationTimeWindow(Common::TIME_PERIOD_5MIN)  // 5 Minutes for a yes/no response here.
                );
                return new LoginResponse(LoginResponse::RESULT_SWITCH_LOGIN, $cred->isSSO(), $cred->getEntityId(), $user_entity_id, ['ueid' => $user_entity_id, 'vc' => $hmac]);

//                throw new RedirectException(LETTUCE_CLIENT_PATH . '#?action=login_switch&eid='.$user_entity_id.'&response='.$hmac);
            }
        } else {    // No session, so create one
            $this->createUserSession($user_entity_id);
        }

        return new LoginResponse(LoginResponse::RESULT_OK, $cred->isSSO(), $cred->getEntityId(), $user_entity_id);
    }

    public function switchUserSession($target_user_id, $hmac) {
        $session_user_id = $this->session()->getUserId();
        if (!$session_user_id) {
            return new LoginResponse(LoginResponse::RESULT_FAILED, false, null, $target_user_id, null, new CodedException(UserSession::EXCEPTION_SESSION_NOT_LOGGED_IN));
        }

        $result = Common::checkVerificationCode(
            $hmac,
            self::HMAC_VERIFICATION_SWITCH_USER,
            [$target_user_id, $session_user_id],
            self::HMAC_SECRET_KEY
        );

        if ($result === true) {
            /** @var EntityUser $target_user_entity */
            $target_user_entity = $this->entity()->get($target_user_id, EntityFactory::SCHEMA_USER);
            $this->session()->create($target_user_entity->getAttributes(EntityFactory::SCOPE_ENTITY  | EntityFactory::SCOPE_PUBLIC, true));
            return new LoginResponse(LoginResponse::RESULT_OK, false, null, $target_user_id);
        } else {
            return new LoginResponse(LoginResponse::RESULT_FAILED, false, null, $target_user_id, null, new CodedException(self::EXCEPTION_HMAC_VERIFICATION_FAILED));
        }
    }

    public function userRemoveLogin($target_login_id) {
        $session_user_id = $this->session()->getUserId();
        if (!$session_user_id) {
            throw new CodedException(UserSession::EXCEPTION_SESSION_NOT_LOGGED_IN);
        }

        /** @var EntityUser $user_entity */
        $user_entity = $this->entity()->get($session_user_id, EntityFactory::SCHEMA_USER);
        $user_login_list = $user_entity->assoc()->getList(EntityUser::X_R_HAS_LOGIN, null, null, null, null, true); // Don't cache this result

        if (count($user_login_list) === 1) {
            throw new CodedException(self::EXCEPTION_REMOVE_FAIL_LAST_LOGIN, null, $target_login_id);
        } else {
            $user_entity->assoc()->delete(EntityUser::X_R_HAS_LOGIN, $target_login_id);
            return true;
        }
    }

    public function userAddLogin($target_login_id, $hmac) {
        $session_user_id = $this->session()->getUserId();
        if (!$session_user_id) {
            return new LoginResponse(LoginResponse::RESULT_FAILED, false, $target_login_id, null, null, new CodedException(UserSession::EXCEPTION_SESSION_NOT_LOGGED_IN));
        }

        $result = Common::checkVerificationCode(
            $hmac,
            self::HMAC_VERIFICATION_ADD_LOGIN,
            [$target_login_id, $session_user_id],
            self::HMAC_SECRET_KEY
        );

        if ($result === true) {
            /** @var EntityUser $user_entity */
            $user_entity = $this->entity()->get($session_user_id, EntityFactory::SCHEMA_USER);
            $user_entity->assoc()->add(EntityUser::X_R_HAS_LOGIN, $target_login_id);
            return new LoginResponse(LoginResponse::RESULT_OK, false, $target_login_id, $session_user_id);
        } else {
            return new LoginResponse(LoginResponse::RESULT_FAILED, false, $target_login_id, $session_user_id, [], new CodedException(self::EXCEPTION_HMAC_VERIFICATION_FAILED, null, $hmac));
        }
    }

//    public function getGroupMembership($entity_id, $scope = Association::GROUP__MEMBER_OF) {
//        $group_id_list = $this->assoc()->getList($entity_id, $scope, null, Common::ATTR_DISPLAY_NAME);
//        /** @var EntityPublicId $public_record */
//        $public_record = $this->entity(null, EntityFactory::SCHEMA_PUBLIC_ID);
//
//        /** @var EntityGroup $group */
//        $group = $this->entity(null, 'Group');
//        $groups = Array();
//        foreach($group_id_list as $group_id) {
//            $group->get($group_id);
//            $groups[$group_id] = $group->getAttributes(EntityFactory::SCOPE_PUBLIC | EntityFactory::SCOPE_ENTITY, true);
//
//            $public_record_id = $this->assoc()->getSingle($group_id, Association::PR__HAS);
//            if ($public_record_id) {
//                $public_record->get($public_record_id);
//                $groups[$group_id] = array_merge($groups[$group_id], $public_record->getAttributes(EntityFactory::SCOPE_PUBLIC, true));
//            }
//        }
//
//        return $groups;
//    }
}