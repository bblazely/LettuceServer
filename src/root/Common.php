<?php
final class Common {
    const
    // Global Common Time Values
        TIME_PERIOD_1MIN    = 60,
        TIME_PERIOD_5MIN    = 300,
        TIME_PERIOD_HOUR    = 3600,
        TIME_PERIOD_DAY     = 86400,
        TIME_PERIOD_WEEK    = 604800,
        TIME_PERIOD_NEVER   = 0,

        CONTEXT_PREFIX_API         = 'API_PREFIX',
        CONTEXT_PREFIX_UI          = 'UI_PREFIX',
        CONTEXT_PREFIX_REQUEST_URI = 'REQUEST_URI',
        CONTEXT_PREFIX_DOCUMENT_URI= 'DOCUMENT_URI',

    // Global Common Attributes
        ATTR_EMAIL          = 'email',
        ATTR_NAME           = 'name',
        ATTR_FIRST_NAME     = 'first_name',
        ATTR_LAST_NAME      = 'last_name',
        ATTR_DATE_OF_BIRTH  = 'dob',
        ATTR_DISPLAY_NAME   = 'display_name',
        ATTR_IMAGE_URL      = 'image_url',
        ATTR_EXTERNAL_URL   = 'external_url',

        DEFAULT_HASH        = 'sha256',
        DEFAULT_SHORT_HASH  = 'sha1',

        //DESCRIPTION         = 'description',
        //PUBLIC_ID           = 'public_id',
        //HOME_URL            = 'home_url',
        //NICKNAME            = 'nickname',
        //LOCATION            = 'location',

    // Global Configuration Settings
        DEBUG_MODE          = true,

    // Global Exceptions
        EXCEPTION_INVALID_CONFIG    = 'Common::InvalidConfiguration',
        EXCEPTION_NOT_IMPLEMENTED   = 'Common::NotImplemented',
        EXCEPTION_UNEXPECTED        = 'Common::UnexpectedException',
        EXCEPTION_NOT_CONFIGURED    = 'Common::NotConfigured';

    // Fast (Deep Node) Array Node Addition - Results can be counter intuitive when mixing array/key nodes - All nested arrays must be explicitly referenced.
    public static function addNode(&$target, $node_map, $value, $allow_duplicate = false) {
        $node_count = count($node_map) -1;
        $t = &$target;
        foreach ($node_map as $node) {
            if (!isset($t[$node])) {
                if ($node_count != 0) {
                    $t[$node] = [];
                } else {
                    $t[$node] = $value;
                    return true;
                }
            } else if ($node_count == 0) {
                if (!is_array($t[$node])) { // Existing node is not an array, convert it
                    if ($allow_duplicate || $t[$node] != $value) {
                        $value[] = $t[$node];
                        $t[$node] = $value;
                    }
                } else {
                    if (is_array($value)) {
                        foreach($value as $key => $val) {
                            if (is_array($val)) {
                                Common::addNode($t[$node], [$key], $val, $allow_duplicate); // Nest value as array with recursive call
                            } else {
                                if ($allow_duplicate || !in_array($val, $t[$node])) {
                                    if (is_numeric($key)) {
                                        $t[$node][] = $val;
                                    } else {
                                        $t[$node][$key] = $val;
                                    }
                                }
                            }
                        }
                    } else if ($allow_duplicate || !in_array($value, $t[$node])) {
                        $t[$node][] = $value;
                    }
                }
            }
            $t = &$t[$node];
            $node_count--;
        }
    }

    // Fast (Deep Node) Array Node Removal
    public static function removeNode(&$target, $node_map, $value = null, $remove_empty = true) {
        if (count($node_map) > 0) {
            foreach ($node_map as $node) {
                if (!isset($target[$node])) {
                    return true;
                }

                if (is_array($target[$node])) {
                    Common::removeNode($target[$node], array_slice($node_map, 1), $value, $remove_empty);
                    if ($remove_empty && count($target[$node]) === 0) {
                        unset($target[$node]);
                    }
                } else {
                    unset($target[$node]);
                }
            }
            return true;
        } else {
            if (is_array($target)) {
                if ($value === null) {          // Value is 'null', so remove the whole branch
                    $target = null;
                } else if (is_array($value)) {  // Value is an array, traverse and remove items
                    foreach ($value as $val) {
                        if (($key = array_search($val, $target)) !== false) {  // Value is scalar, search for it and remove it.
                            if (is_integer($key)) {
                                array_splice($target, $key, 1);  // Key is an integer, splice it.
                            } else {
                                unset($target[$key]);   // Key is a string (associative array), unset it.
                            }
                        }
                    }
                    //var_dump($value);
                } else if (($key = array_search($value, $target)) !== false) {  // Value is scalar, search for it and remove it.
                    if (is_integer($key)) {
                        return array_splice($target, $key, 1);  // Key is an integer, splice it.
                    } else {
                        unset($target[$key]);   // Key is a string (associative array), unset it.
                    }
                } else if (isset($target[$value])) {
                    unset($target[$value]); // late addition... may cause problems (Generally only reached when the value being removed is an index)
                }
            }
        }
    }

    public static function isConnectionSecure() {
        return ($_SERVER['HTTPS'] ?? null || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null == 'https'));
    }

    public static function getServerURL($relative_path, $context = self::CONTEXT_PREFIX_API) {
        return 'http' . (self::isConnectionSecure() ? 's' : '') . '://' . preg_replace('/\/+/', '/', $_SERVER['SERVER_NAME'] . $_SERVER[$context] . $relative_path);
    }

    public static function httpGet($url, $data, $options = null, &$responseHeaders = null) {
        return self::httpRequest($url.'?'.http_build_query($data, '', '&'), $options, $responseHeaders);
    }

    private static $collator;
    public static function getLocaleCollator() {
        if (self::$collator === null) {
            $locale = Common::getIfSet($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            if (!$locale) {
                $locale = 'en';
            } else {
                $locale = explode(",", $locale)[0]; // Get preferred locale
            }
            print "Found locale: ".$locale."\n";

            self::$collator = collator_create($locale);
            print collator_get_locale(self::$collator, Locale::VALID_LOCALE);
            print collator_get_locale(self::$collator, Locale::ACTUAL_LOCALE);
        }
        return self::$collator;
    }

    public static function httpPost($url, $data, $options = array(), &$responseHeaders = null) {
        if (!is_array($options)) $options = array();

        $query = http_build_query($data, '', '&');

        $stream = array('http' => array(
            'method' => 'POST',
            'header' => "Content-type: application/x-www-form-urlencoded",
            'content' => $query
        ));

        $stream = array_merge($options, $stream);

        return self::httpRequest($url, $stream, $responseHeaders);
    }

    public static function httpDelete($url, $data, $options = null, &$responseHeaders = null) {
        if (!is_array($options)) $options = array();

        $stream = array('http' => array(
            'method' => 'DELETE'
        ));

        $stream = array_merge($options, $stream);

        $result = self::httpRequest($url.'?'.http_build_query($data, '', '&'), $stream, $responseHeaders);
        return $result;
    }

    public static function httpRequest($url, $options = null, &$responseHeaders = null) {
        $context = null;
        if (!empty($options) && is_array($options)){
            $context = stream_context_create($options);
        }

        $content = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header; // copy magic var content into pass-by-ref var
        return $content;
    }

    public static function getIfSet(&$value, $default = null, $trim = true) {
        if (isset($value)) {
            switch (gettype($value)) {
                case 'string':
                    if ($trim) {
                        $value = trim(urldecode($value));
                        return ($value != "") ? $value : $default;
                    } else {
                        return $value;
                    }
                    break;

                default:
                    return $value;
            }
        } else {
            return $default;
        }
    }

    public static function strRepeatSep($string, $sep, $multiplier) {
        $ret = "";
        for ($i = 0; $i < $multiplier; $i++) {
            if ($i) $ret .= $sep;
            $ret .= $string;
        }
        return $ret;
    }

    public static function base64UrlEncode($data) {
        return str_replace( ['+','/'], ['-','_'], base64_encode($data));
    }

    public static function base64UrlDecode($data) {
        return base64_decode(str_replace(['-','_'], ['+','/'], $data));
    }

    // Non crypto variable length code. (use in conjunction with hash_hmac)
    // Used to generate a human friendly code (no 1, l, I, o, 0 or O to avoid any ambiguity)
    public static function generateRandomHumanCode($size) {
        $charset = "23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz";
        $length = strlen($charset) -1;
        $code = '';
        for ($i = 0; $i < $size; $i++) {
            $code .= $charset[mt_rand(0, $length)];
        }
        return $code;
    }

    public static function generateSessionId($size = 32) {
        return Common::base64UrlEncode(openssl_random_pseudo_bytes($size));
    }

    public static function checkVerificationCode($code, $purpose, $data_set, $key, $hash_func = Common::DEFAULT_SHORT_HASH) {
        $parts = explode('.', $code);
        if (count($parts) == 2) {
            $expires = hexdec($parts[1]);
            if (is_numeric($expires)) {
                if (time() >= $expires) {
                    return false;
                }
            } else {
                return false;
            }
        } else {
            $expires = null;
        }

        return hash_equals(Common::generateVerificationCode($purpose, $data_set, $key, $expires, $hash_func), $code);
    }

    public static function generateVerificationCode($purpose, $data_set, $key, $expires = null, $hash_func = Common::DEFAULT_SHORT_HASH) {
        if (is_array($data_set)) {
            $data_set = implode($data_set);
        }

        if ($expires) {
            $expires = dechex($expires);
            return hash_hmac($hash_func, $purpose.$data_set.$expires, $key).'.'.$expires;
        } else {
            return hash_hmac($hash_func, $purpose.$data_set, $key);
        }
    }

    public static function generateExpirationTimeWindow($time_limit = Common::TIME_PERIOD_HOUR, $time_shift = Common::TIME_PERIOD_5MIN) {
        return (time() + mt_rand($time_shift * -1, $time_shift)) + $time_limit;  // +/- 5 minutes by default to help make it less guessable
    }

    public static function arrayKeyExistsAll($keys, $data) {
        if(count(array_intersect_key(array_flip($keys), $data)) === count($keys)) {
            return true;
        }
        return false;
    }
}

class RedirectException extends Exception {
    private $redirect_url;

    public function __construct($redirect_url, $response_code = Request::CODE_SEE_OTHER, Exception $previous = null) {
        $this->redirect_url = $redirect_url;
        parent::__construct($redirect_url, $response_code, $previous);
    }

    public function getRedirectUrl() {
        return $this->redirect_url;
    }
}

class CodedException extends Exception {
    const EXCEPTION_UNKNOWN =   'CodedException::UnknownException';
    /**
     * @var null
     */

    private $response_body = null, $context = null;

    public function __construct($error_string, Exception $previous = null, $error_context = null, $response_body = null) {
        $error_code = crc32($error_string);     // This is a long here, not hex as Exception in php7 enforces a 'long' for the error code.
        $this->context = $error_context;
        $this->response_body = $response_body;
        parent::__construct($error_string, $error_code, $previous);
    }

    public function getLocation() {
        return $this->getFile() . ':' . $this->getLine();
    }

    public function getResponseBody() {
        return $this->response_body;
    }

    public function getContext() {
        return $this->context;
    }
}

