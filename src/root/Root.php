<?php
/**
 * Root Class for Lettuce Lightweight MVC Engine
 */

define('LETTUCE_CLIENT_PATH', $_SERVER['UI_PREFIX'] ?? '/');   // Root path for the client_web side data. Grab from web server ENV VAR or default to '/'

require(LETTUCE_SERVER_PATH . '/root/Common.php');
require(LETTUCE_SERVER_PATH . '/root/Grow.php');
require(LETTUCE_SERVER_PATH . '/root/Model.php');
require(LETTUCE_SERVER_PATH . '/root/View.php');
require(LETTUCE_SERVER_PATH . '/root/Controller.php');
require(LETTUCE_SERVER_PATH . '/root/Extension.php');

class LettuceRoot
{
    /** @var  LettuceGrow $grow */
    public          $grow;

    /** @var  LettuceView $view */
    public          $view;

    public          $request;
    public          $config = Array();


    public function __construct() {
        $this->view = new LettuceView();
        $this->grow = new LettuceGrow($this);

        // CLI will take over here. If it's not CLI, build and process the request
        if (php_sapi_name() !== 'cli') {
            $this->buildRequest();
            $this->execRequest();
        } else {
            return $this;
        }
    }

    private function buildRequest() {
        $this->request = Array(
            'data' => $_REQUEST,
            'path' => Array()
        );

        $parts = explode("/", urldecode($_SERVER['PATH_INFO']));
        foreach($parts as $p) {
            if (trim($p) != '') {
                array_push($this->request['path'], $p);
            }
        }

        if ($_SERVER['REQUEST_METHOD'] == 'POST' || $_SERVER['REQUEST_METHOD'] == 'PUT') {     // Special handler for JSON POST/PUT data.
            $post_data = json_decode(file_get_contents("php://input"), true);
            if (is_array($post_data)) {
                $this->request['data'] = array_merge($this->request['data'], $post_data); // Inject JSON posted data into 'request' super global if request method was POST
            }
        }
    }
    
    private function execRequest() {
        try {
            $this->grow->controller(strtolower(Common::getIfSet($this->request['path'][0])));
        } catch (CodedException $e) {
            $this->view->exception(Request::CODE_NOT_FOUND, $e);
        }
    }
}
