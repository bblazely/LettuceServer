<?php
class CredentialsNative extends CredentialsModuleBase {
    const
        EXCEPTION_INVALID_EMAIL             = 'CredentialsNative::InvalidEmail',
        EXCEPTION_INVALID_PASSWORD          = 'CredentialsNative::InvalidPassword',

        HMAC_ID_CODE                        = 'CredentialsNative::VerificationByCode',
        HMAC_ID_EMAIL                       = 'CredentialsNative::VerificationByEmail',
        HMAC_SECRET_KEY                     = 'TheSecretKey!ForUserActionsIs_F@#%ing_Secret',
        VERIFICATION_COOKIE_NAME            = 'SuspiciousNinja',

        ATTR_VERIFICATION_DATA                   = 'verification_data';

    /** @var EntityLoginNative entity_login */
    protected $entity_login;

    /** @var  Queue queue */
    private $queue;

    private $config;

    public function __construct($params, $config) {
        parent::__construct($params, $config);
        $this->queue = LettuceGrow::extension('Queue');
    }

    private function entityLogin($entity_id = null) {
        if (!$this->entity_login) {
            $this->entity_login = $this->entity_factory->get($entity_id, EntityFactory::SCHEMA_LOGIN_NATIVE);
        }
        return $this->entity_login;
    }

    public function processVerificationRequest($request) {
        if (Common::arrayKeyExistsAll([
            Common::ATTR_EMAIL,
            self::ATTR_VERIFICATION_DATA
        ], $request)) {
            // POST based verification using 8 character code
            $redirect = false;
            $email = $request[Common::ATTR_EMAIL];
            $code = null;
            $data = $request[self::ATTR_VERIFICATION_DATA];
        } else {
            $redirect = true;
            $email = $request[0];
            $data = $request[1];    // swap?
            $code = $request[2];
        }

        if ($this->isRegistered($email)) {
            if ($this->isVerified()) {
                if ($redirect) {
                    throw new RedirectException(LETTUCE_CLIENT_PATH . '#?action=login&ln_r='.dechex(crc32(self::EXCEPTION_ALREADY_VERIFIED)).'&ln_e='.$email);
                }
                throw new CodedException(self::EXCEPTION_ALREADY_VERIFIED);
            } else {
                try {
                    $this->processVerification($data, $code);
                } catch (CodedException $e) {
                    if ($redirect) {
                        throw new RedirectException(LETTUCE_CLIENT_PATH . '#?action=login&ln_r='.dechex(crc32(self::EXCEPTION_VERIFICATION_FAILED)).'&ln_e='.$email);
                    } else {
                        throw $e;
                    }
                }

                if ($redirect) {
                    throw new RedirectException(LETTUCE_CLIENT_PATH . '#?action=verifiedlogin');
                }
            }
        } else {
            throw new CodedException(self::EXCEPTION_NOT_FOUND);
        }
    }

    public function issueVerificationRequest($request = null) {
        if ($request && !$this->isRegistered($request)) {
            throw new CodedException(self::EXCEPTION_NOT_FOUND);
        }

        if (!$this->entityLogin()->getAttribute(Common::ATTR_EMAIL, EntityFactory::SCOPE_PRIVATE)) {
            if (($email = Common::getIfSet($request[Common::ATTR_EMAIL]))) {
                $this->entityLogin($request[Common::ATTR_EMAIL]);
            } else {
                throw new CodedException(self::EXCEPTION_NOT_REGISTERED);
            }
        }

        if ($this->entityLogin()->isVerified()) {
            throw new CodedException(self::EXCEPTION_ALREADY_VERIFIED);
        }

        $this->generateVerification();
        return true;
    }

    public function isVerified() {
        $el = $this->entityLogin();
        if (!$el->getId()) {
            return false;
        }
        return (int)$el->getAttribute(EntityLoginNative::ATTR_VERIFIED, EntityFactory::SCOPE_PUBLIC);
    }

    // If request_data is null, attempt to use the profile loaded from the external provider during authentication
    public function isRegistered($request_data = null) {
        if ($request_data) {
            if (is_string($request_data)) {
                $email = $request_data;
            } else {
                $email = Common::getIfSet($request_data[Common::ATTR_EMAIL]);
            }

            if ($email) {
                if ($this->entityLogin()->getAttribute(Common::ATTR_EMAIL, EntityFactory::SCOPE_PRIVATE)) {
                    return $this->entityLogin()->getId();
                } else {
                    return $this->entityLogin()->getByKey($email);
                }
            }
        } else if ($this->authenticated) {
            return $this->entityLogin()->getId();
        }

        throw new CodedException(self::EXCEPTION_INVALID_CREDENTIALS);
    }

    public function register($request_data = null) {
        if ($this->authenticated) {
            throw new CodedException(self::EXCEPTION_ALREADY_AUTHENTICATED);
        }
        return $this->entityLogin()->register($request_data);
    }

    public function authenticate($request, $request_data) {
        if ($this->authenticated) {
            throw new CodedException(self::EXCEPTION_ALREADY_AUTHENTICATED);
        }

        if (($email = Common::getIfSet($request_data[Common::ATTR_EMAIL]))) {
            $el = $this->entityLogin($request_data[Common::ATTR_EMAIL]);
            if ($el->getId() && ($password = Common::getIfSet($request_data[EntityLoginNative::ATTR_PASSWORD]))) {
                if ($el->checkPassword($password)) {
                    return true;
                } else {
                    throw new CodedException(self::EXCEPTION_INVALID_PASSWORD);
                }
            } else {
                throw new CodedException(self::EXCEPTION_INVALID_EMAIL);
            }
        } else {
            throw new CodedException(self::EXCEPTION_INCOMPLETE_CREDENTIALS);
        }
    }

    private function processVerification($verification_data, $verification_code = null) {
        $email = $this->entityLogin()->getAttribute(Common::ATTR_EMAIL, EntityFactory::SCOPE_PRIVATE);
        if ($verification_code !== null) {
            // Validate the long code (from the email link)
            if (!Common::checkVerificationCode(
                $verification_code,
                self::HMAC_ID_EMAIL,
                Array(
                    $email,
                    $this->entityLogin()->getId(),
                    $verification_data
                ),
                self::HMAC_SECRET_KEY)
            ) {
                throw new CodedException(self::EXCEPTION_VERIFICATION_FAILED);
            }
        } else {
            // Try validating the short-code submitted via the verification form instead
            $verification_code = Common::GetIfSet($_COOKIE[self::VERIFICATION_COOKIE_NAME]);
            if ($verification_code) {
                if (!Common::checkVerificationCode(
                    $verification_code,
                    self::HMAC_ID_CODE,
                    Array(
                        $email,
                        $this->entityLogin()->getId(),
                        $verification_data
                    ),
                    self::HMAC_SECRET_KEY)
                ) {
                    throw new CodedException(self::EXCEPTION_VERIFICATION_FAILED);
                }
            } else {
                throw new CodedException(self::EXCEPTION_VERIFICATION_FAILED);
            }
        }

        // Clear Suspicious Ninja so a short code can no longer be used.
        setcookie(self::VERIFICATION_COOKIE_NAME, null, -1, '/', $_SERVER['COOKIE_DOMAIN'] ?? null);
        $this->entityLogin()->setVerified(EntityLoginNative::VALUE_VERIFIED);
        return true;
    }

    private function generateVerification() {
        $verification_data = Common::generateSessionId(32);
        $el = $this->entityLogin();
        $email = $el->getAttribute(Common::ATTR_EMAIL, EntityFactory::SCOPE_PRIVATE);

        // Create the Email Verification ID / Human Code / Cookie
        $verification_human = Common::generateRandomHumanCode(8);
        setcookie(           // This cookie is for verification only, it doesn't need to be stored
        // Cookie ID
            self::VERIFICATION_COOKIE_NAME,
            // HMAC Code
            Common::generateVerificationCode(
                self::HMAC_ID_CODE, [
                    $email,
                    $el->getId(),
                    $verification_human
                ],
                self::HMAC_SECRET_KEY,
                Common::generateExpirationTimeWindow(Common::TIME_PERIOD_HOUR * 3)
            ),
            // TTL Etc
            0,
            '/',
            $_SERVER['COOKIE_DOMAIN'] ?? null,
            Common::isConnectionSecure(),
            false
        );

        // Create the Email Verification Link and send it.
        $verification_code = Common::generateVerificationCode(
            self::HMAC_ID_EMAIL,
            Array(
                $email,
                $el->getId(),
                $verification_data
            ),
            self::HMAC_SECRET_KEY,
            Common::generateExpirationTimeWindow(Common::TIME_PERIOD_DAY)
        );

        /** @var Queue $queue */
        $queue = LettuceGrow::extension('Queue');
        $queue->defineQueue(Queue::QUEUE_SYSTEM_MAILER, true);
        $queue->send('', [
            'to'                => $email,
            'template'          => 'bin/mailer/templates/verify_account',
            'template_html'     => 'bin/mailer/templates/verify_account_html',
            'template_subject'  => 'bin/mailer/templates/verify_account_subject',
            'scope' => [
                'verification_code'       => $verification_code,
                'verification_human'      => $verification_human,
                'email_address'           => $email,
                'verification_data'       => $verification_data
            ]
        ], Queue::QUEUE_SYSTEM_MAILER, true);
    }
}