<?php
/**
 * Created by PhpStorm.
 * User: bdb
 * Date: 19/11/13
 * Time: 1:51 PM
 */

class ProviderBlizzard extends ExternalProviderBase {
    const SCOPE_PROFILE = null;
    const SCOPE_EMAIL   = null;
    const SCOPE_FRIENDS = null;         // SCOPE_FRIENDS is failing at the moment, investigation needed?
    const SCOPE_BIRTHDAY= null;         // DOB isn't in a seperate scope

    public function __construct($params, $config) {

        $this->enableFeature(self::FEATURE_XSRF);
        $this->enableFeature(self::FEATURE_REQUEST_PROOF);

        parent::__construct($params, $config);
    }

    public function getLoginURL($response_url, $style, $base_url = null, $scope = []) {
        return parent::getLoginURL(
            $response_url,
            $style,
            $base_url ?? 'https://us.battle.net/oauth/authorize?response_type=code&client_id='.$this->config['key_id'],
            $scope
        );
    }

    public function loadProfile($id = '') {
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
            'https://us.api.battle.net/account/user/',
            $data,
            null,
            $headers
        );

        if (!empty($profile)) {
            $profile = json_decode($profile);
            $this->profile = [
                'common' => [],
                'source' => $profile
            ];

            // Map attributes to common profile values
            $this->setProfileValue(ExternalProvider::ATTR_PROVIDER_USER_ID, $profile->id);
            //$this->setProfileValue(Common::ATTR_IMAGE_URL,      Common::getIfSet($profile_tag->picture));
            $this->setProfileValue(Common::ATTR_DISPLAY_NAME,   Common::getIfSet($profile->battletag));
//              $this->setProfileValue(Common::EMAIL,           Common::getIfSet($profile->email));
//            $this->setProfileValue(Common::NICKNAME,        Common::getIfSet($profile->name));
  //          $this->setProfileValue(Common::FIRST_NAME,      Common::getIfSet($profile->given_name));   // Character Name?
    //        $this->setProfileValue(Common::LAST_NAME,       Common::getIfSet($profile->family_name));  // Realm?
            //$this->setCommon(self::COMMON_BIRTHDAY,         Common::getIfSet($profile->birthday));
            //$this->setCommon(self::COMMON_LOCATION,         Common::getIfSet($profile->location->name));
              //$this->setProfileValue(Common::ATTR_EXTERNAL_URL,        Common::getIfSet($profile_tag->link));

            return $this->profile;
        } else {
            $this->profile = [];
            throw new CodedException(self::EXCEPTION_PROVIDER_FAIL_PROFILE);
        }
    }

    /**
     * Blizzard Provider Specific
     */

    protected function exchangeCodeForToken($code, $response_url) {

        $response_string = Common::httpPost(
            'https://us.battle.net/oauth/token',
            [
                'client_id'         =>      $this->config['key_id'],
                'client_secret'     =>      $this->config['key_secret'],
                'code'              =>      $code,
                'grant_type'        =>      'authorization_code',
                'redirect_uri'      =>      $response_url
            ],
            [
                'http' => [
                    'ignore_errors' => true     // file_get_contents throws a 400 error otherwise
                ]
            ],
            $headers
        );

        $response = json_decode($response_string, true);
        if (is_array($response)) {
            $this->error = null;
            $this->credentials = [
                self::ATTR_TOKEN   => $response['access_token'],
                self::ATTR_EXPIRES => $response['expires_in'] + time()
            ];
        } else {
            throw new CodedException(self::EXCEPTION_SOCIALNETWORK_FAIL_TOKEN);
        }
    }
    
    public function requirePreAuthStep() {
        return true;
    }
}