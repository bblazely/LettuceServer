<?php
/**
 * Created by PhpStorm.
 * User: bdb
 * Date: 19/11/13
 * Time: 1:51 PM
 */

class ProviderLinkedIn extends ExternalProviderBase {

    const SCOPE_PROFILE = 'r_basicprofile';
    const SCOPE_EMAIL   = 'r_emailaddress';

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
            $base_url ?? 'https://www.linkedin.com/uas/oauth2/authorization?response_type=code&client_id='.$this->config['key_id'],
            $scope
        );
    }

    public function loadProfile($id = '~') {
        // Try and use the user access token first, if one isn't present, use the app id/secret
        if (!$this->credentials) {
            $data = [
                // Note: This format works for FB, may not work for LinkedIn... Not tested. (And may never be needed...)
                'oauth2_access_token' => $this->config['key_id'] . '|' . $this->config['key_secret'],
                'format' => 'json'
            ];
            //    throw new CodedException(self::EXCEPTION_PROVIDER_NO_TOKEN);
        } else {
            $data = [
                'oauth2_access_token' => $this->credentials[self::ATTR_TOKEN],
                'format' => 'json'
            ];
        }

        if ($this->isFeatureEnabled(self::FEATURE_REQUEST_PROOF)) {
            $data['appsecret_proof'] = $this->getAppSecretProof();
        }

        $profile = Common::httpGet(
            'https://api.linkedin.com/v1/people/'.$id.':(id,first-name,last-name,formatted-name,site-standard-profile-request,email-address,picture-url,date-of-birth)',
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
            $this->setProfileValue(Common::ATTR_DISPLAY_NAME, Common::getIfSet($profile->formattedName, ''));
            $this->setProfileValue(Common::ATTR_IMAGE_URL,        Common::getIfSet($profile->pictureUrl));
            $this->setProfileValue(Common::ATTR_EMAIL,        Common::getIfSet($profile->emailAddress));
            $this->setProfileValue(Common::ATTR_FIRST_NAME,   Common::getIfSet($profile->firstName));
            $this->setProfileValue(Common::ATTR_LAST_NAME,    Common::getIfSet($profile->lastName));
            $this->setProfileValue(Common::ATTR_DATE_OF_BIRTH,     Common::getIfSet($profile->dateOfBirth));    // TODO: Change this to support creation of an actual dob from year/mon/day vars
            $this->setProfileValue(Common::ATTR_EXTERNAL_URL, Common::getIfSet($profile->siteStandardProfileRequest->url));
            // Not Implemented
            //
            //$this->setCommon(self::COMMON_NICKNAME,     Common::getIfSet($profile->name));
            //$this->setCommon(self::COMMON_LOCATION,     Common::getIfSet($profi

            return $this->profile;
        } else {
            $this->profile = [];
            throw new CodedException(self::EXCEPTION_PROVIDER_FAIL_PROFILE);
        }
    }


    public function updateUser($id = false) {
        if (!$this->credentials) {
            throw new CodedException(self::EXCEPTION_PROVIDER_NO_TOKEN);
        }

        $profile = Common::httpGet(
            'https://api.linkedin.com/v1/people/~:(id,first-name,last-name,formatted-name,email-address,picture-url,date-of-birth)',
            [
                'oauth2_access_token' => $this->credentials['token'],
                'format' => 'json'
            ],
            null,
            $headers
        );

        if (!empty($profile)){
            $profile = json_decode($profile);

            // Map compulsory values to

            $this->profile = [
                'common' => [],
                'source' => $profile
            ];

            // Map attributes to common profile values
            $this->setProfileValue(SocialNetwork::ATTR_PROVIDER_USER_ID,    $profile->id);
            $this->setProfileValue(Common::ATTR_DISPLAY_NAME, Common::getIfSet($profile->formattedName));
            $this->setProfileValue(Common::ATTR_IMAGE_URL,        Common::getIfSet($profile->pictureUrl));
            $this->setProfileValue(Common::ATTR_EMAIL,        Common::getIfSet($profile->emailAddress));
            $this->setProfileValue(Common::ATTR_FIRST_NAME,   Common::getIfSet($profile->firstName));
            $this->setProfileValue(Common::ATTR_LAST_NAME,    Common::getIfSet($profile->lastName));
            $this->setProfileValue(Common::ATTR_DATE_OF_BIRTH,     Common::getIfSet($profile->dateOfBirth));    // TODO: Change this to support creation of an actual dob from year/mon/day vars

            // Not Implemented
            //$this->setProfileValue(Common::HOME_URL,     Common::getIfSet($profile->link));
            //$this->setCommon(self::COMMON_NICKNAME,     Common::getIfSet($profile->name));
            //$this->setCommon(self::COMMON_LOCATION,     Common::getIfSet($profile->location->name));

            return $this->profile;
        } else {
            $this->profile = [];
            throw new CodedException(self::EXCEPTION_SOCIALNETWORK_FAIL_PROFILE);
        }
    }

    /**
     * Live Provider Specific
     */

    protected function exchangeCodeForToken($code, $response_url) {

        $response_string = Common::httpPost(
            'https://www.linkedin.com/uas/oauth2/accessToken',[
                'grant_type'        =>      'authorization_code',
                'client_id'         =>      $this->config['key_id'],
                'client_secret'     =>      $this->config['key_secret'],
                'code'              =>      $code,
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