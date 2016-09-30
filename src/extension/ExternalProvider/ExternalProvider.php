<?php

// Social Provider Interaction Interface
interface iExternalProviderExtras {
   // const ATTR_PROVIDER_REQUEST_ID = 'socialnetwork_request_id';

    public function removeRequest($provider_user_id, $request_id);
    public function processLoginSSO($request_data);
}

abstract class ExternalProviderBase implements iLettuceExtension {
    static function ExtGetOptions() { /* No Options */ }

    const
    // Exceptions
        EXCEPTION_PROVIDER_AUTH_REQUIRED       = 'ExternalProviderModule::AuthorisatonRequired',            // 16de28f6 - Social network requires app to be authorised (ie: Facebook)
        EXCEPTION_PROVIDER_NO_TOKEN            = 'ExternalProviderModule::ProviderNoToken',                 // b4c55a38
        EXCEPTION_PROVIDER_FAIL_TOKEN          = 'ExternalProviderModule::ProviderFailedTokenExchange',     // e29eec61
        EXCEPTION_PROVIDER_FAIL_PROFILE        = 'ExternalProviderModule::ProviderFailedProfileFetch',      // 54e1357b
        EXCEPTION_PROVIDER_XSRF_CHECK_FAILED   = 'ExternalProviderModule::ProviderFailedXSRFCheck',         // 4e04ae8d
        EXCEPTION_PROVIDER_SSO_FAILED          = 'ExternalProviderModule::SSOFailed',                       // 78c5ffbc
        EXCEPTION_PROVIDER_NO_BASE_URL         = 'ExternalProviderModule::NoBaseURLSpecified',

        STYLE_DEFAULT = 'popup',

    // Feature ID's
        FEATURE_REQUEST_PROOF               = 1,
        FEATURE_XSRF                        = 2,
        __NYI_FEATURE_TIME_LIMIT            = 4,

    // XSRF Values
        XSRF_COOKIE_ID                      = 'SociableNinja',
        XSRF_SECRET                         = 'It is So Much Fun Being a Sociable Ninja in the Wild',

    // Cache Keys
        STORAGE_KEY_PROVIDER_ID        = 'ExternalProviderID',
        STORAGE_KEY_PROVIDER_NAME      = 'ExternalProviderName',

    // Scopes
        /**
         * Classes extending ExternalProvider Module *can* redefine these constants to suit their implementation as needed.
         * Therefore these should not be used directly via ExternalProviderModule::CONSTANT but as $provider::CONSTANT
         * TODO: These should probably be defaulted to 'null' so that new ones don't cause scope issues... Very little gained by defining defaults here.
         */
        SCOPE_PROFILE                       = 'profile',
        SCOPE_EMAIL                         = 'email',
        SCOPE_BIRTHDAY                      = 'birthday',
        SCOPE_FRIENDS                       = 'user_friends',

    // Login Styles (Frontend window)
        LOGIN_STYLE_POPUP                   = 'popup',
        LOGIN_STYLE_PAGE                    = 'page',
        LOGIN_STYLE_MOBILE                  = 'wap',
        LOGIN_STYLE_TOUCH                   = 'touch',

    // Attribute Labels
        ATTR_EXPIRES                        = 'expires',
        ATTR_TOKEN                          = 'token';

    protected $config, $credentials = null, $error = null, $profile = null, $state, $features = 0;
    /** @var Storage */
    private $storage;

    public function __construct($params, $config) {
        $this->config = $config;
        $this->storage = LettuceGrow::extension('Storage');
    }

    public function getDisplayName() {
        return Common::getIfSet($this->config['display_name']);
    }

    public function getId($numeric = false) {
        if ($numeric) {
            return Common::getIfSet($this->config['provider_id']);
        } else {
            return Common::getIfSet($this->config['provider_id_str']);
        }
    }

    public function getUserCredentials() {
        return Common::getIfSet($this->credentials);
    }

    // Return a pre-loaded copy of the user's details from the provider
    public function getProfile() {
        if (isset($this->profile) && isset($this->profile['common'])) {
            $profile = $this->profile['common'];
            $profile[ExternalProvider::ATTR_PROVIDER_ID] = $this->getId(true);
            $profile['providers'] = Array(
                $this->getId(true) => $this->credentials
            );
            return $profile;
        } else {
            return null;
        }
    }

    // ExternalProviders will want to implement this to enable custom features
    public function enableFeature($feature) {
        $this->features |= $feature;
    }

    public function disableFeature($feature) {
        $this->features &= ~$feature;
    }

    public function setCredentials($credentials) {
        // Todo: check for a token / expiration field
        $this->credentials = $credentials;
    }

    // Private/Protected Methods

    protected function isFeatureEnabled($feature) {
        return $this->features & $feature;
    }

    protected function setProfileValue($field, $value) {
        if ($value !== null) {
            $this->profile['common'][$field] = $value;
        }
    }

    protected function setXSRFToken() {

        $token = Common::base64UrlEncode(openssl_random_pseudo_bytes(32));
        $token_id = hash_hmac(Common::DEFAULT_SHORT_HASH, $token, self::XSRF_SECRET);
        $expiration = Common::TIME_PERIOD_5MIN * 3;

        $this->storage->createVolatile(
            'XSRF.' . $token_id,
            $token,
            [Storage::OPT_VOLATILE_EXPIRATION => $expiration]
        );

        setcookie(self::XSRF_COOKIE_ID, $token_id, (time() + $expiration), '/', $_SERVER['COOKIE_DOMAIN'] ?? null, Common::isConnectionSecure(), true);
        return $token;
    }

    protected function verifyXSRFToken($token) {
        // Get token contents
        $token_id = Common::getIfSet($_COOKIE[self::XSRF_COOKIE_ID]);
        if ($token_id) {
            $result = $this->storage->retrieve('XSRF.' . $token_id);
            if ($result == $token) {
                return true;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    protected function clearXSRFToken() {
        $token_id = Common::getIfSet($_COOKIE[self::XSRF_COOKIE_ID]);
        if ($token_id) {
            $this->storage->delete('XSRF.'.$token_id);
        }
        setcookie(self::XSRF_COOKIE_ID, null, -1, '/', $_SERVER['COOKIE_DOMAIN'] ?? null);
    }

    public function processLogin($response, $response_url) {
        $this->error = null;

        if ($this->isFeatureEnabled(self::FEATURE_XSRF)) {
            if ($this->verifyXSRFToken(Common::getIfSet($response['state']))) {
                $this->clearXSRFToken();
            } else {
                throw new CodedException(self::EXCEPTION_PROVIDER_XSRF_CHECK_FAILED);
            }
        }

        if (isset($response['code'])) {
            $this->exchangeCodeForToken($response['code'], $response_url);
            return true;
        } else {
            // Should never get processed, a bad request generally stalls on the provider's side.
            $this->error = [
                'message' => $_REQUEST['error_description'],
                'code'    => $_REQUEST['error']
            ];
            return false;
        }
    }

    public function getLoginURL($response_url, $style, $base_url, $scope = []) {
        if (!$base_url) {
            throw new CodedException(self::EXCEPTION_PROVIDER_NO_BASE_URL);
        }

        if (!strpos($base_url, '?')) {
            $base_url .= '?';
        }
        
        $url = $base_url . ($style ? '&display=' . $style : '');

        if (!is_array($scope)) {
            $scope = [$this::SCOPE_PROFILE];
        } else if (!in_array($this::SCOPE_PROFILE, $scope)) {
            array_push($scope, $this::SCOPE_PROFILE);
        }

        if ($this->isFeatureEnabled($this::FEATURE_XSRF)) {
            $url .= '&state=' . $this->setXSRFToken();
        }

        if (count($scope) !== 0) {
            $url .= '&scope=' . implode(',', $scope);
        }

        $url .= '&redirect_uri='.urlencode($response_url);

        return $url;
    }

    protected function getAppSecretProof() {
        return hash_hmac(Common::DEFAULT_HASH, $this->credentials[self::ATTR_TOKEN], $this->config['key_secret']);
    }

    public function requirePreAuthStep() {
        return false;
    }

    public function requirePostAuthStep() {
        return false;
    }

    // Abstract Methods
    abstract public function loadProfile();
    abstract protected function exchangeCodeForToken($code, $response_url);
}

// TODO refactor this to indicate it's a factory (ExternalProviderFactory)
class ExternalProvider implements iLettuceExtension {
    static function ExtGetOptions() { /* Default */ }

    const EXCEPTION_USER_INITIAL_LOOKUP_FAILED  = 'ExternalProvider::FailedInitialUserQuery';
    const EXCEPTION_USER_CREATION_FAILED        = 'ExternalProvider::FailedToCreateNewUser';
    const EXCEPTION_USER_REMOTE_LOOKUP_FAILED   = 'ExternalProvider::FailedRemoteUserQuery';

    const STORAGE_KEY_EXTERNAL_PROVIDERS        = 'ExternalProviders';

    const ATTR_PROVIDER_ID                      = 'provider_id';
    const ATTR_PROVIDER_ID_STR                  = 'provider_id_str';
    const ATTR_PROVIDER_USER_ID                 = 'provider_user_id';
    const CREDENTIALS                           = 'credentials';

    /** @var Storage $storage */
    protected $storage;

    // Public

    public function __construct($params, $config) {
        $this->storage = LettuceGrow::extension('Storage');
    }

    public function getList() {
        return $this->storage->retrieve(
            self::STORAGE_KEY_EXTERNAL_PROVIDERS,
            'SELECT provider_id, provider_id_str, class_name, display_name, popup_width, popup_height FROM external_providers WHERE enabled=1',
            null, [
                Storage::OPT_VOLATILE_SPREAD_LOOKUP => true
            ]
        );
    }

    /**
     * @param       $provider_id
     * @param array $extra_config
     *
     * @returns ExternalProviderBase
     *
     * @throws CodedException
     */
    public function get($provider_id, $extra_config = []) { /* TODO examine whether extra_config is needed anymore, or what it was used for */
        $config = $this->getConfig($provider_id);
        if ($config) {
            if ($config['enabled'] == 1) {
                $network_class = 'Provider' . $provider_id;
                return LettuceGrow::extension($network_class, $extra_config, [
                    self::OPTION_BASE_PATH => __DIR__ . '/Providers/'
                ], $config);
            }
        }
        return false;
    }

    /* Private Methods */

    private function getConfig($provider_id) {
        $result = $this->storage->retrieve(
            ExternalProviderBase::STORAGE_KEY_PROVIDER_ID . '.' . $provider_id,
            'SELECT * FROM external_providers WHERE '.(is_numeric($provider_id) ? self::ATTR_PROVIDER_ID : self::ATTR_PROVIDER_ID_STR).'=:provider_id',
            ['provider_id' => $provider_id],
            [Storage::OPT_DURABLE_COLLAPSE_SINGLE => true]
        );
        
        if (!$result) {
            return null;
        } else {
            return $result;
        }
    }
}