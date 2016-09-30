<?php
/**
 * Created by PhpStorm.
 * User: bdb
 * Date: 19/11/13
 * Time: 1:51 PM
 */

class ProviderFacebook extends ExternalProviderBase implements iExternalProviderExtras {

    const SCOPE_PROFILE = 'public_profile';
    const SCOPE_BIRTHDAY= '';

    const STYLE_DEFAULT = 'popup';

    public function __construct($params, $config) {

        $this->enableFeature(self::FEATURE_XSRF);
        $this->enableFeature(self::FEATURE_REQUEST_PROOF);

        parent::__construct($params, $config);
    }

    public function getLoginURL($response_url, $style, $base_url = null, $scope = []) {
        return parent::getLoginURL(
            $response_url,
            $style,
            $base_url ?? 'https://www.facebook.com/dialog/oauth?client_id='.$this->config['key_id'],
            $scope
        );
    }

    public function loadProfile($id = 'me') {
        // Try and use the user access token first, if one isn't present, use the app id/secret
        if (!$this->credentials) {
            $data = [
                'access_token' => $this->config['key_id'] . '|' . $this->config['key_secret']
            ];
        //    throw new CodedException(self::EXCEPTION_PROVIDER_NO_TOKEN);
        } else {
            $data = [
                'access_token' => $this->credentials[self::ATTR_TOKEN]
            ];
        }

        if ($this->isFeatureEnabled(self::FEATURE_REQUEST_PROOF)) {
            $data['appsecret_proof'] = $this->getAppSecretProof();
        }

        $profile = Common::httpGet(
            'https://graph.facebook.com/v2.6/' . $id,
            $data,
            null,
            $headers
        );

        if (!empty($profile)){
            $profile = json_decode($profile);
            $this->profile = [
                'common' => [],
                'source' => $profile
            ];

            // Map attributes to common profile values
            $this->setProfileValue(ExternalProvider::ATTR_PROVIDER_USER_ID, $profile->id);
            $this->setProfileValue(Common::ATTR_IMAGE_URL,        'https://graph.facebook.com/' . $profile->id . '/picture?type=square');
            $this->setProfileValue(Common::ATTR_DISPLAY_NAME, Common::getIfSet($profile->name));
            $this->setProfileValue(Common::ATTR_EMAIL,        Common::getIfSet($profile->email));
            $this->setProfileValue(Common::ATTR_FIRST_NAME,   Common::getIfSet($profile->first_name));
            $this->setProfileValue(Common::ATTR_LAST_NAME,    Common::getIfSet($profile->last_name));
            $this->setProfileValue(Common::ATTR_DATE_OF_BIRTH,     Common::getIfSet($profile->birthday));
            $this->setProfileValue(Common::ATTR_EXTERNAL_URL,     Common::getIfSet($profile->link));
            //$this->setProfileValue(Common::NICKNAME,     Common::getIfSet($profile->username));
            //$this->setProfileValue(Common::LOCATION,     Common::getIfSet($profile->location->name));
            //$this->setProfileValue(self::TOKEN,        $this->credentials[self::TOKEN]);

            return $this->profile;
        } else {
            $this->profile = [];
            throw new CodedException(self::EXCEPTION_PROVIDER_FAIL_PROFILE);
        }
    }
    
    protected function exchangeCodeForToken($code, $response_url) {

        $response_string = Common::httpGet(
            'https://graph.facebook.com/v2.6/oauth/access_token',
            [
                // 'format'            =>      'json', // *** See the TD note below ***
                'client_id'         =>      $this->config['key_id'],
                'client_secret'     =>      $this->config['key_secret'],
                'code'              =>      $code,
                'redirect_uri'      =>      $response_url
            ],
            [
                'http' => [
                    'ignore_errors' => true     // file_get_contents throws a 400 error otherwise
                ]
            ],
            $headers
        );

        /**
         * Have to search for an self::TOKEN here and if found, parse_str for the time being
         * as due to a Graph API bug, FB is returning the oauth access token response as a string
         * instead of a json object regardless of the requested format. So can't do a simple one
         * size fits all json parse. :(
         *
         * TODO: Switch this to json_decode once FB have fixed the problem. 
         * NOTE: (Marked as 'wont fix' by FB as of Oct'14)
         *
         * See https://developers.facebook.com/x/bugs/162050973983689/ for more information.
         */
        if (stristr($response_string, self::ATTR_TOKEN)) {
            parse_str(
                $response_string,
                $response
            );

            $this->error = null;
            $this->credentials = [
                self::ATTR_TOKEN   => $response['access_token'],
                self::ATTR_EXPIRES => $response['expires'] + time()
            ];
        } else {
            if (stristr($response_string, 'error')) {
                $this->error = json_decode($response_string, true)['error'];
            }
            throw new CodedException(self::EXCEPTION_PROVIDER_FAIL_TOKEN);
        }
    }

    /** ExternalProviderExtraInterface Implementation */

    public function removeRequest($socialnetwork_user_id, $request_id) {
        $result = Common::httpDelete(
            'https://graph.facebook.com/v2.6/' . $request_id . '_' . $socialnetwork_user_id,
            Array(
                'access_token' => $this->config['key_id'] . '|' . $this->config['key_secret']
            )
        );
        return $result;
    }

    public function processLoginSSO($request) {
        $signed_request = Common::getIfSet($request['signed_request'], null);
        if ($signed_request) {
            list($encoded_sig, $payload) = explode('.', $signed_request, 2);

            // Decode the signature
            $sig  = Common::base64UrlDecode($encoded_sig);

            // Confirm the signature
            $expected_sig = hash_hmac(Common::DEFAULT_HASH, $payload, $this->config['key_secret'], $raw = true);
            if ($sig !== $expected_sig) {
                throw new CodedException(ExternalProviderBase::EXCEPTION_PROVIDER_SSO_FAILED);
            }

            // Decode the payload
            $data = json_decode(Common::base64UrlDecode($payload), true);

            if (!Common::getIfSet($data['user_id'], null)) {
                throw new CodedException(ExternalProviderBase::EXCEPTION_PROVIDER_AUTH_REQUIRED, null, $this->getId());
            } else {
                $this->credentials[self::ATTR_TOKEN] = (isset($data['oauth_token'])) ? $data['oauth_token'] : $request['oauth_token'];

                $profile = $this->loadProfile();
                if ($profile['common'][ExternalProvider::ATTR_PROVIDER_USER_ID] == $data['user_id']) {
                    return true;
                } else {
                    throw new CodedException(ExternalProviderBase::EXCEPTION_PROVIDER_XSRF_CHECK_FAILED);
                }
            }
        } else {
            throw new CodedException(ExternalProviderBase::EXCEPTION_PROVIDER_SSO_FAILED);
        }
    }

} 