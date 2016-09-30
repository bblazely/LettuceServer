<?php
/**
 * Created by PhpStorm.
 * User: bdb
 * Date: 19/11/13
 * Time: 1:51 PM
 */

class ProviderMicrosoftLive extends ExternalProviderBase {

    const SCOPE_PROFILE = 'wl.basic';
    const SCOPE_BIRTHDAY= 'wl.birthday';

    const STYLE_DEFAULT = 'popup';

    public function __construct($params, $config) {

        $this->enableFeature(self::FEATURE_XSRF);
        $this->enableFeature(self::FEATURE_REQUEST_PROOF);

        parent::__construct($params, $config);
    }

    public function getLoginURL($response_url, $style, $base_url, $scope = []) {
        return parent::getLoginURL(
            $response_url,
            $style,
            $base_url ?? 'https://login.live.com/oauth20_authorize.srf?response_type=code&client_id='.$this->config['key_id'],
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
            'https://apis.live.net/v5.0/' . $id,
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
            $this->setProfileValue(ExternalProvider::ATTR_PROVIDER_USER_ID,    $profile->id);
            $this->setProfileValue(Common::ATTR_DISPLAY_NAME, Common::getIfSet($profile->name));
            $this->setProfileValue(Common::ATTR_IMAGE_URL,        'https://apis.live.net/v5.0/' . $profile->id . '/picture');
            $this->setProfileValue(Common::ATTR_EMAIL,        Common::getIfSet($profile->emails->preferred));
            $this->setProfileValue(Common::ATTR_FIRST_NAME,   Common::getIfSet($profile->first_name));
            $this->setProfileValue(Common::ATTR_LAST_NAME,    Common::getIfSet($profile->last_name));
            $this->setProfileValue(Common::ATTR_DATE_OF_BIRTH,     Common::getIfSet($profile->birth_year));    // TODO: Change this to support creation of an actual dob from year/mon/day vars
            //$this->setProfileValue(Common::HOME_URL,     Common::getIfSet($profile->link));

            // Not Implemented
            //$this->setCommon(self::COMMON_NICKNAME,     Common::getIfSet($profile->name));
            //$this->setCommon(self::COMMON_LOCATION,     Common::getIfSet($profile->location->name));


            return $this->profile;
        } else {
            $this->profile = [];
            throw new CodedException(self::EXCEPTION_PROVIDER_FAIL_PROFILE);
        }
    }

    protected function exchangeCodeForToken($code, $response_url) {

        $response_string = Common::httpPost(
            'https://login.live.com/oauth20_token.srf',[
                'client_id'         =>      $this->config['key_id'],
                'client_secret'     =>      $this->config['key_secret'],
                'code'              =>      $code,
                'grant_type'        =>      'authorization_code',
                'redirect_uri'      =>      $response_url
            ],[
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
            throw new CodedException(self::EXCEPTION_PROVIDER_FAIL_TOKEN);
        }
    }
}