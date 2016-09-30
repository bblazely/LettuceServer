<?php

class Request {
    const CODE_OK                 = 200;
    const CODE_CREATED            = 201;
    const CODE_ACCEPTED           = 202;
    const CODE_NO_CONTENT            = 204;
    const CODE_RESET_CONTENT      = 205;
    const CODE_MOVED              = 301;
    const CODE_FOUND              = 302;
    const CODE_SEE_OTHER          = 303;
    const CODE_TEMP_REDIRECT      = 307;
    const CODE_BAD_REQUEST        = 400;
    const CODE_UNAUTHORIZED       = 401;
    const CODE_FORBIDDEN          = 403;
    const CODE_NOT_FOUND          = 404;
    const CODE_METHOD_NOT_ALLOWED = 405;
    const CODE_REQUEST_TIMEOUT    = 408;
    const CODE_CONFLICT           = 409;
    const CODE_UNPROCESSABLE      = 422;
    const CODE_INTERNAL_ERROR     = 500;
    const CODE_NOT_IMPLEMENTED    = 501;

    // Response Formats
    const TYPE_JSON  = 'application/json';
    const TYPE_JSONP = 'application/javascript';
    const TYPE_XML   = 'text/xml';
    const TYPE_TEXT  = 'text/plain';
    const TYPE_HTML  = 'text/html';

    // Headers
    const HEADER_ERROR_CODE   = 'Error-Code';
    const HEADER_REQUEST_TIME = 'Request-Time';
    const HEADER_ERROR_MSG    = 'Error-Message';
    const HEADER_ERROR_LOCATION   = 'Error-Location';
    const HEADER_ERROR_CONTEXT  = 'Error-Context';
    const HEADER_LOCATION     = 'Location';
    const HEADER_CONTENT_TYPE = 'Content-Type';
    const HEADER_ALLOW        = 'Allow';
    const HEADER_TIME_TAKEN   = 'Timer-Build';
    const HEADER_TIME_TAKEN_SR= 'Timer-Serialize';

    // Methods
    const METHOD_POST   = 'POST';
    const METHOD_PUT    = 'PUT';
    const METHOD_DELETE = 'DELETE';
    const METHOD_GET    = 'GET';

    const EXCEPTION_INVALID_METHOD = 'Request::InvalidMethod';
}

class LettuceView {
    private $start_time;

    // JSON Specific

    private $json_protection= false;
    private $jsonp_callback = 'callback';

    public function setJsonProtection($val) {
        $this->json_protection = $val;
        return $this;
    }
    public function setJsonpCallback($val) {
        $this->jsonp_callback = $val;
        return $this;
    }

    // XML Specific
    private $xml_root       = 'root';

    public function setXmlRoot($val) {
        $this->xml_root = $val;
        return $this;
    }

    // View Class

    public final function __construct() {
        $this->start_time = microtime(true);
    }

    public function httpResponseCode($response_code) {
        http_response_code($response_code);
    }

    public function headers($headers = Array(), $response_code = null) {
        if ($response_code) {
            http_response_code($response_code);
        }
        if (is_array($headers)) {
            foreach ($headers as $header_name => $header_value) {
                header($header_name . ': ' . $header_value);
            }
        }
        return $this;
    }

    /**
     * @param                $code
     * @param CodedException $e
     * @param bool           $fatal
     * @param null           $redirect
     * @param string         $type
     */
    public function exception($code, CodedException $e, $fatal = true, $redirect = null, $type = Request::TYPE_JSON) {
        $headers =  Array(
            Request::HEADER_ERROR_CODE => dechex($e->getCode()) // Changed to dechex conversion here instead of in CodedException to support php7
        );

        if (Common::DEBUG_MODE) {
            $headers[Request::HEADER_ERROR_MSG] = $e->getMessage();
            $headers[Request::HEADER_ERROR_LOCATION] = $e->getLocation();
            $headers[Request::HEADER_ERROR_CONTEXT] = $e->getContext();
        }

        if ($redirect) {
            $headers[Request::HEADER_LOCATION] = $redirect;
        }

        $this->headers(
            $headers, $code
        );

        if ($fatal) {
            die();
        }
    }

    public function method(...$allowed_methods) {
        $this->headers(
            Array(
                Request::HEADER_ALLOW => implode(', ', $allowed_methods)
            ), Request::CODE_METHOD_NOT_ALLOWED
        );

    }

    public function redirect($location, $response_code = Request::CODE_SEE_OTHER) {
        $this->httpResponseCode($response_code);
        header(Request::HEADER_LOCATION . ': ' . $location);
    }

    public function output($data, $response_code = Request::CODE_OK, $change_set = null, $type = Request::TYPE_JSON, $return = false) {
        http_response_code($response_code);

        $output = Array('r' => $data);
        if ($change_set != null) {
            $output['c'] = $change_set;
        }

        header(Request::HEADER_REQUEST_TIME.':'.(floor($this->start_time * 1000)));

        if (!$return) {
            $now = microtime(true);
            header(Request::HEADER_TIME_TAKEN. ': '. round(($now - $this->start_time) * 1000, 2) . 'ms');
            header(Request::HEADER_CONTENT_TYPE . ': ' . $type);
            switch ($type) {
                case Request::TYPE_HTML:
                    $output = $data;
                    break;
                case Request::TYPE_JSONP:
                    $output = Common::getIfSet($this->jsonp_callback, 'callback') . '(' . json_encode($output, JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE) . ');';
                    break;
                case Request::TYPE_JSON:
                    $output = Common::getIfSet($this->json_protection, ")]}',\n") . json_encode($output, JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
                    break;
                case Request::TYPE_XML:
                    $xml = new SimpleXMLElement('<' . Common::getIfSet($this->xml_root, 'root') . '/>'); // Extra is the XML root
                    $this->arrayToXml($output, $xml);
                    $output = $xml->asXML();
                    break;
                case Request::TYPE_TEXT:
                    $output = http_build_query($output);
                    break;
            }

            header(Request::HEADER_TIME_TAKEN_SR. ': '. round( (microtime(true) - $now) * 1000, 2) . 'ms');
            print $output;
        }
        return $output;
    }

    public function template($path, $scope = null, $buffer = false, Exception $error = null) {
        if ($buffer) {
            ob_start();
        }

        if (!include(LETTUCE_SERVER_PATH . '/' . $path . '.tpl.php')) {
            ob_end_clean();
            return false;
        }

        if ($buffer) {
            $contents = ob_get_contents();
            ob_end_clean();
            return $contents;
        }

        return null;
    }

    private function arrayToXml($data, SimpleXMLElement &$xml_data) {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (!is_numeric($key)) {
                    $sub_node = $xml_data->addChild($key);
                    $this->arrayToXml($value, $sub_node);
                } else {
                    $sub_node = $xml_data->addChild('id'.$key);
                    $this->arrayToXml($value, $sub_node);
                }
            } else {
                $xml_data->addChild($key, $value);
            }
        }
    }
} 