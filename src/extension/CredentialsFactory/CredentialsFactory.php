<?php
abstract class CredentialsModuleBase implements iLettuceExtension {
    static function ExtGetOptions() { /* No Options */ }

    const
        EXCEPTION_INCOMPLETE_PROFILE =  'CredentialsModule::IncompleteProfile',      // 5562b672
        EXCEPTION_INVALID_CREDENTIALS = 'CredentialsModule::InvalidCredentials',
        EXCEPTION_NOT_AUTHENTICATED =   'CredentialsModule::NotAuthenticated',
        EXCEPTION_ALREADY_VERIFIED =    'CredentialsModule::AlreadyVerified',
        EXCEPTION_INCOMPLETE_CREDENTIALS    = 'CredentialsModule::IncompleteCredentials',
        EXCEPTION_ALREADY_AUTHENTICATED     = 'CredentialsModule::AlreadyAuthenticated',
        EXCEPTION_NOT_FOUND                 = 'CredentialsModule::NotFound',
        EXCEPTION_NOT_VERIFIED              = 'CredentialsModule::NotVerified',
        EXCEPTION_NOT_REGISTERED            = 'CredentialsModule::NotRegistered',
        EXCEPTION_VERIFICATION_FAILED       = 'CredentialsModule::VerificationFailed';

    /** @var  EntityFactory entity_factory */
    protected $entity_factory;

    /** @var  EntityBase entity_login */
    protected $entity_login;

    protected $profile, $credentials, $authenticated = false, $sso = false;


    // returns true, false or either a Coded or Redirect Exception
    abstract public function authenticate($request, $request_data);
    abstract public function register($request_data = null);
    abstract public function processVerificationRequest($request);
    abstract public function issueVerificationRequest($request = null);
    abstract public function isVerified();
    abstract public function isRegistered($request_data = null);

    public function isSSO() {
        return $this->sso;
    }

    public function getProfile() {
        return $this->profile;
    }

    public function resolveToUserEntity() {

    }

    public function associateToUserEntity($user_entity_id) {
        if ($this->entity_login) {
            $this->entity_login->assoc()->add(CredentialsFactory::X_LOGIN_FOR, $user_entity_id);
        } else {
            throw new CodedException(CredentialsFactory::EXCEPTION_NO_LOGIN_ENTITY, null, $user_entity_id);
        }
    }

    public function resolveToUserId() {
        return ($this->entity_login) ? $this->entity_login->assoc()->getSingle(CredentialsFactory::X_LOGIN_FOR) : null;
    }


    public function getEntityId() {
        if (!$this->entity_login) {
            return null;
        } else {
            return $this->entity_login->getId();
        }
    }

    public function getProviderId($numeric = false) {
        throw new CodedException(Common::EXCEPTION_NOT_IMPLEMENTED);
    }

    public function __construct($params, $config) {
        $this->entity_factory = LettuceGrow::extension('EntityFactory');
    }


}

class CredentialsFactory implements iLettuceExtension {

    const
        EXCEPTION_MODULE_NOT_FOUND      =   'CredentialsFactory::ModuleNotFound',               // d0d07151
        EXCEPTION_ALREADY_REGISTERED    =   'CredentialsFactory::CredentialsAlreadyRegistered', // 20e94230
        EXCEPTION_NO_LOGIN_ENTITY       =   'CredentialsFactory::NoLoginEntityLoaded',

        RESULT_AUTH_OK                  =   1,
        RESULT_AUTH_DO_PRE_OP           =   10,
        RESULT_AUTH_DO_POST_OP          =   15,

        ATTR_PROVIDER_ID                =   'provider_id',

        MODULE_EXTERNAL                 =   'external',
        MODULE_NATIVE                   =   'native',

        X_LOGIN_FOR                     =   50;

    static function ExtGetDependencies() {}
    static function ExtGetOptions() {
        // Factory, treat as singleton
        return [
            self::OPTION_INSTANTIATE_AS => self::INSTANTIATE_SINGLETON,
            self::OPTION_INSTANTIATE_AS_LOCK => true
        ];
    }

    public function __construct($params, $config) {}

    public function get($module_id, $params = null) {
        try {
            return LettuceGrow::extension('Credentials' . ucwords(basename($module_id)), $params, [self::OPTION_BASE_PATH => __DIR__ . '/Modules/']);
        } catch (CodedException $e) {
            throw new CodedException(self::EXCEPTION_MODULE_NOT_FOUND, $e, 'Credentials' . ucwords(basename($module_id)));
        }
    }
}