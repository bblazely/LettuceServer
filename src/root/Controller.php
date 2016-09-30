<?php

class LettuceController {

    protected $model, $request, $view;
    private $grow, $root;//, $entity;

    public final function __construct(LettuceRoot $di_root) {
        $this->root = $di_root;
        $this->request = &$di_root->request;  // Shortcut to GET/POST/URL data
        $this->grow = $di_root->grow;        // Shortcut to grower
        $this->view = $di_root->view;
//        $this->mapped_uri_params = Array();
        $this->model = $this->getModel();      // Grab the model for this class if it exists
    }

    protected function getModel() {
        $controller_class = get_called_class();
        $leaf_name = substr($controller_class, 0, strlen($controller_class) - 11);
        $model_class = $leaf_name . '_model';

        if (class_exists($model_class)) {
            return new $model_class($this->root);
        } else {
            return new LettuceModel($this->root);   // Return a model that will raise not-implemented errors if addressed.
        }
    }
    
    public function root() {
        $this->view->headers(null, Request::CODE_NOT_FOUND);
    }

    protected function getPathValue($key, $trim = true) {
        $data = Common::getIfSet($this->request['mapped'][$key], null);
        return $trim ? trim($data) : $data;
    }

    protected function getRequestValue($key, $trim = true) {
        $data = Common::getIfSet($this->request['data'][$key], null);
        return ($trim && is_string($data)) ? trim($data) : $data;
    }

    protected function mapPathValue(Array $params) {
        $this->request['mapped'] = $params;    // pull in the defaults
        reset($this->request['path']);
        foreach ($this->request['mapped'] as $key => $val) {         // load params from URL into array, overriding defaults.
            if ($pair = each($this->request['path'])) {
                $this->request['mapped'][$key] = $pair['value'];
            }
        }
    }

    /**
     * @param bool $fatal
     *
     * @return UserSession | bool
     * @throws CodedException
     */
    protected function getSession($fatal = true, $handle_only = false) {
        /** @var UserSession $session */
        $session = LettuceGrow::extension('UserSession');
        if ($handle_only) {
            return $session;
        }

        if (!$session->getSessionData()) {
            if ($fatal) {
                $this->view->exception(Request::CODE_UNAUTHORIZED, new CodedException(UserSession::EXCEPTION_SESSION_NOT_LOGGED_IN));
            } else {
                return false;
            }
        }
        return $session;
    }


    protected function requireAssoc($entity_id, $assoc_list, $require_all = false, $fatal = true) {
        if (!($session = $this->getSession($fatal))) {
            return false;
        }

        $assoc = $this->grow->module('Association');
        foreach ($assoc_list as $assoc_id) {
            $result = $assoc->getList($session->getUserId(), $assoc_id, $entity_id);
            if ($result) {
                if (!$require_all) {
                    return $session;
                }
            } else {
                if ($require_all) {
                    if ($fatal) {
                        $this->view->exception(Request::CODE_UNAUTHORIZED, new CodedException(UserSession::EXCEPTION_INSUFFICIENT_PERMISSION));
                    } else {
                        return false;
                    }
                }
            }
        }

        // Require All and a Result was found for each assoc, so return the session;
        return $session;
    }
}
