<?php

/*Class UserSessionCore extends UserSession implements iLettuceCore {
    public function __construct(LettuceRoot $di_root) {
        if (isset($di_root->config['UserSession'])) {
            parent::configure($di_root->config['UserSession']);     // Attempt default auto config from global root config
        }
        parent::__construct($di_root->grow->module('Storage'));
    }
}*/

Class UserSession implements iLettuceExtension {
    static function ExtGetOptions() {
        return [
            self::OPTION_INSTANTIATE_AS => self::INSTANTIATE_SINGLETON,
            self::OPTION_INSTANTIATE_AS_LOCK => true,
            self::OPTION_HAS_CONFIG_FILE => true
        ];
    }

    const EXCEPTION_NOT_CONFIGURED          = 'UserSession::NoConnectionConfigured';    // f122aae6
    const EXCEPTION_SESSION_NOT_LOGGED_IN   = 'UserSession::NotLoggedIn';               // 163a7837
    const EXCEPTION_COOKIE_CREATE_FAILED    = 'UserSession::CouldNotCreateCookie';      // 9a74bb7d
    const EXCEPTION_SESSION_CREATE_FAILED   = 'UserSession::CouldNotCreateSession';     // 5a318839
    const EXCEPTION_INSUFFICIENT_PERMISSION = 'UserSession::InsufficientPermission';    // 9fc738ae

    const USER_COOKIE_KEY_PRIMARY           = 'primary_session_cookie';
    const USER_COOKIE_AUTH_VERIFY           = 'auth_verification_cookie';
    const USER_SESSION_KEY                  = 'UserSession';

    const STORAGE                           = 'Storage';

    const LOGIN_TIME                        = 'login_time';
    const AUTH_VERIFIED                     = 'auth_verified';
    const SESSION_PERSIST                   = 'session_persist';

    const STORAGE_KEY_SESSION               = 'UserSession';
    
    const VERIFICATION_TYPE_AUTHENTICATED   = 'UserSession::Authenticated';

    private $session_id = null,
            $config = null,
            $session_data = null;

    /** @var Storage storage */
    private $storage = null;

    public function __construct($params, $config) {
        $this->storage = LettuceGrow::extension('Storage');

        if ($config != null && Common::arrayKeyExistsAll(['primary_session_cookie', 'auth_verification_cookie'], $config)) {
            $this->config = $config;
        } else {
            throw new CodedException(Common::EXCEPTION_INVALID_CONFIG);
        }
    }

    public function remove() {
        // Note: Don't worry about the auth_verify key, it will disappear by itself or be re-created

        $cookie_id = Common::GetIfSet($this->config[self::USER_COOKIE_KEY_PRIMARY]['name'], self::USER_COOKIE_KEY_PRIMARY);
        $session_id = Common::getIfSet($_COOKIE[$cookie_id]);

        if ($session_id) {
            unset($_COOKIE[$cookie_id]);
            $this->storage->delete(self::STORAGE_KEY_SESSION . '.' . $session_id);
            setcookie($cookie_id, null, -1, '/', $_SERVER['COOKIE_DOMAIN'] ?? null);
            return true;
        } else {
            return false;
        }
    }

    // Create a new session and session_auth'd key,
    // this should only be called if user authentication actually occurred.
    public function create($session_data) {
        $session_data[self::AUTH_VERIFIED] = true;      // Set auth verified = true for this session
        $session_data[self::SESSION_PERSIST] = false;   // Set initial session persistence tracking to false (because it's not persistent at this stage)
        $session_data[self::LOGIN_TIME] = Common::generateExpirationTimeWindow(0);
        $session_data[self::STORAGE] = Array();

        if(setcookie(           // This cookie is for auth verification only, it doesn't need to be stored
            Common::GetIfSet($this->config[self::USER_COOKIE_AUTH_VERIFY]['name'], self::USER_COOKIE_AUTH_VERIFY),
            Common::generateVerificationCode(
                self::VERIFICATION_TYPE_AUTHENTICATED,
                Array (
                 //   $data[Common::EMAIL],  // TODO: Replace this with the internal entity id? (and below)
                    $session_data[UserSession::LOGIN_TIME]
                ),
                $this->config['secret_key'],
                Common::GetIfSet($this->config[self::USER_COOKIE_AUTH_VERIFY]['ttl'], 0)
            ),
            0,  // Session only, or max 12 hours if the hmac above expires first.
            '/',
            $_SERVER['COOKIE_DOMAIN'] ?? null,
            Common::isConnectionSecure(),
            true
        )) {
            // Session Authentication Cookie Created, create the session proper.
            $session_id = Common::generateSessionId(64);
            if(setcookie(       // This cookie is stored in the session cache
                Common::GetIfSet($this->config[self::USER_COOKIE_KEY_PRIMARY]['name'], self::USER_COOKIE_KEY_PRIMARY),
                $session_id,
                0,  // Session Only at this stage. Session-get can override it later if need be.
                '/',
                $_SERVER['COOKIE_DOMAIN'] ?? null,
                Common::isConnectionSecure(),
                true
            )) {

                if ($this->storage->createVolatile(
                    self::STORAGE_KEY_SESSION . '.' . $session_id,
                    $session_data
                )) {
                    $this->session_id = $session_id;
                    $this->session_data = $session_data;
                    return true;
                } else {
                    throw new CodedException(self::EXCEPTION_SESSION_CREATE_FAILED);
                }
            } else {
                throw new CodedException(self::EXCEPTION_COOKIE_CREATE_FAILED);
            }
        } else {
            throw new CodedException(self::EXCEPTION_COOKIE_CREATE_FAILED);
        }
    }

    public function getSessionData() {
        return $this->resume(null, true);
    }

    public function getUserId() {
        if ($this->session_data == null) {
            $this->resume(null, true);
        }
        return $this->session_data['entity_id'];
    }

    public function getPublicId() {
        if ($this->session_data == null) {
            $this->resume(null, true);
        }

        return $this->session_data['public_id'];
    }

    /*  Having the persist value here may seem counter intuitive, so an explanation is needed.
     *  At creation time, the login components don't know if the user wanted a persistent session or not.
     *  It only finds out from the UserSession client_web control when it tries to grab the current session.
     *  At this point, if the persistent value is set, the session is recreated, if not, the volatile one is kept.
     *
     *  NOTE: Changing from non-persistent to persistent can only be done if auth_verification is present.
     *
     *  Persist = -1:   Remove Persist
     *             1:   Add Persist
     *             0:   Don't change anything.
     */
    public function resume($persist = 'session', $simple = false) {
        $session_id = Common::getIfSet($_COOKIE[Common::GetIfSet($this->config[self::USER_COOKIE_KEY_PRIMARY]['name'], self::USER_COOKIE_KEY_PRIMARY)]);
        $session_update = false;

        if ($session_id) {
            $session_data = $this->storage->retrieve(self::STORAGE_KEY_SESSION . '.' . $session_id);
            if ($session_data) {
                if ($simple) {
                    $this->session_id = $session_id;
                    $this->session_data = $session_data;
                } else {
                    // Check if this auth verification cookie is still verified or not
                    if ($session_data[UserSession::AUTH_VERIFIED] == true) {
                        if (!Common::checkVerificationCode(
                            Common::getIfSet($_COOKIE[Common::GetIfSet($this->config[self::USER_COOKIE_AUTH_VERIFY]['name'], self::USER_COOKIE_AUTH_VERIFY)]),
                            self::VERIFICATION_TYPE_AUTHENTICATED,
                            Array(
                               // $user_profile[Common::EMAIL],
                                $session_data[UserSession::LOGIN_TIME]
                            ),
                            $this->config['secret_key']
                        )
                        ) {
                            $session_data[UserSession::AUTH_VERIFIED] = false;
                            $session_update                      = true;
                        }
                    }

                    // Is Persistence Requested (and authorised to switch to persistent mode)
                    if ($persist == 'persist' && $session_data[UserSession::AUTH_VERIFIED] === true && $session_data[UserSession::SESSION_PERSIST] === false) {
                        setcookie(
                            Common::GetIfSet($this->config[self::USER_COOKIE_KEY_PRIMARY]['name'], self::USER_COOKIE_KEY_PRIMARY),
                            $session_id,
                            Common::GetIfSet($this->config[self::USER_COOKIE_KEY_PRIMARY]['ttl'], 0), // Extend cookie to ttl
                            '/',
                            $_SERVER['COOKIE_DOMAIN'] ?? null,
                            Common::isConnectionSecure(),
                            true
                        );
                        $session_data[UserSession::SESSION_PERSIST] = true;
                        $session_update                        = true;
                    }

                    $this->session_id = $session_id;
                    $this->session_data = $session_data;

                    // If the session has changed, update the cache
                    if ($session_update) {
                        $this->commit();
                    }
                }

                return $session_data;
            } else {
                return null;
            }
        } else {
            return null;
        }
    }

    private function commit() {
        if (!$this->session_id || !$this->session_data) {
            throw new Exception(self::EXCEPTION_SESSION_NOT_LOGGED_IN);
        }

        $this->storage->createVolatile(
            self::STORAGE_KEY_SESSION . '.' . $this->session_id,
            $this->session_data,
            [
                Storage::OPT_VOLATILE_ALLOW_OVERWRITE => true
            ]
        );
    }

    public function store($key, $data, $commit = true) {
        if ($this->session_data == null) {
            $this->resume(null, true);
        }

        $this->session_data[$key] = $data;

        if ($commit) {
            $this->commit();
        }
    }

    public function retrieve($key) {
        if ($this->session_data == null) {
            $this->resume(null, true);
        }

        if (array_key_exists($key, $this->session_data)) {
            return $this->session_data[$key];
        } else {
            return null;
        }
    }
}

