<?php

class CredentialsExternal extends CredentialsModuleBase {
    const
        EXCEPTION_NO_PROVIDER =         'CredentialsExternal::NoProviderSpecified',
        EXCEPTION_NO_SSO      =         'CredentialsExternal::SSONotSupportedByProvider';

    /** @var ExternalProvider external_provider */
    private $external_provider;

    /** @var  ExternalProviderBase provider */
    protected $provider;

    /** @var  EntityLoginExternal entity_login */
    protected $entity_login;

    public function __construct($params, $config) {
        parent::__construct($params, $config);

        $provider_id = Common::getIfSet($params[0]);
        if (is_string($provider_id)) {
            $this->setProvider($provider_id);
        }
    }

    public function issueVerificationRequest($request = null) {
        throw new CodedException(Common::EXCEPTION_NOT_IMPLEMENTED, null, __FUNCTION__);
    }

    public function processVerificationRequest($request) {
        throw new CodedException(Common::EXCEPTION_NOT_IMPLEMENTED, null, __FUNCTION__);
    }

    public function isVerified() {
        return true;    // Verification not required for this credential type
    }

    // If request_data is null, attempt to use the profile loaded from the external provider during authentication
    public function isRegistered($request_data = null) {
        // Ignores request_data (auth/register workflow)
        if ($this->authenticated) {
            if (!$this->entity_login) {
                $this->entity_login = $this->entity_factory->get(null, EntityFactory::SCHEMA_LOGIN_EXTERNAL);
            }

            return $this->entity_login->getByKey([
                ExternalProvider::ATTR_PROVIDER_ID => $this->provider->getId(true),
                ExternalProvider::ATTR_PROVIDER_USER_ID => $this->profile[ExternalProvider::ATTR_PROVIDER_USER_ID]
            ]);
        } else {
            throw new CodedException(self::EXCEPTION_INVALID_CREDENTIALS);
        }
    }

    public function register($request_data = null) {
        // Ignores request_data (auth/register workflow)

        if ($this->authenticated) {
            if (!Common::arrayKeyExistsAll([
                ExternalProvider::ATTR_PROVIDER_USER_ID,
/*                Common::ATTR_DISPLAY_NAME,
                Common::ATTR_IMAGE_URL,
                Common::ATTR_EXTERNAL_URL*/
            ], $this->profile)
            ) {
                throw new CodedException(self::EXCEPTION_INCOMPLETE_PROFILE);
            }

            if (!$this->entity_login) {
                $this->entity_login = $this->entity_factory->get(null, EntityFactory::SCHEMA_LOGIN_EXTERNAL);
            }

            $this->profile[EntityLoginExternal::ATTR_PROVIDER_ID] = $this->provider->getId(true);
            return $this->entity_login->register($this->profile);
        } else {
            throw new CodedException(self::EXCEPTION_INVALID_CREDENTIALS);
        }
    }
    
    public function authenticate($request, $request_data) {
        if (!$this->provider) {
            throw new CodedException(self::EXCEPTION_NO_PROVIDER);
        }

        if (in_array('response', $request)) {
            if ($this->provider->processLogin($request_data, Common::getServerURL('', Common::CONTEXT_PREFIX_DOCUMENT_URI))) {
                $this->provider->loadProfile();
                $this->profile = $this->provider->getProfile();
                $this->authenticated = true;
            } else {
                return false;
            }
        } else if (in_array('sso', $request)) {
            if (method_exists($this->provider, 'processLoginSSO')) {
                if ($this->provider->processLoginSSO($request_data)) {
                    $this->provider->loadProfile();
                    $this->profile = $this->provider->getProfile();
                    $this->sso = true;
                    $this->authenticated = true;
                } else {
                    return false;
                }
            } else {
                throw new CodedException(self::EXCEPTION_NO_SSO);
            }
        } else {
            // No login information, so redirect to the login page of the external provider

            if ($this->provider->requirePreAuthStep()) {
                return CredentialsFactory::RESULT_AUTH_DO_PRE_OP;
            }
            
            throw new RedirectException(
                $this->provider->getLoginURL(
                    Common::getServerURL('/response', Common::CONTEXT_PREFIX_DOCUMENT_URI),
                    Common::getIfSet($request_data['style'], null),
                    null,
                    Common::getIfSet($request_data['scope'], [])
                ),
                Request::CODE_SEE_OTHER
            );
        }
        
        return CredentialsFactory::RESULT_AUTH_OK;
    }

    private function setProvider($provider_id) {
        if (!$this->external_provider) {
            $this->external_provider = LettuceGrow::extension('ExternalProvider');
        }
        $this->entity_login = null;
        $this->provider = $this->external_provider->get($provider_id);
    }

    public function getProviderId($numeric = false) {
        return $this->provider->getId($numeric);
    }
}