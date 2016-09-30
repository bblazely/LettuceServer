<?php
require_once LETTUCE_SERVER_PATH . '/composer/vendor/autoload.php';  // This module requires the composer auto loader
use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Message\AMQPMessage;

Class QueueEntry {
    private $msg_object;

    function __construct($msg_object) {
        $this->msg_object = $msg_object;
    }

    public function getMessage() {
        return json_decode($this->msg_object->body, true);
    }

    public function ack($reply_msg = null) {
        // TODO: Implement reply messages.
        $this->msg_object->delivery_info['channel']->basic_ack($this->msg_object->delivery_info['delivery_tag']);
    }
}


/*Class QueueCore extends Queue Implements iLettuceCore {
    public function __construct(LettuceRoot $di_root) {
        parent::configure($di_root->config['Queue']);     // Attempt default auto config from global root config
    }
}*/

Class Queue implements iLettuceExtension {
    static function ExtGetDependencies() { /* No Requirements */ }
    static function ExtGetOptions() {
        return [
            self::OPTION_INSTANTIATE_AS => self::INSTANTIATE_SINGLETON,
            self::OPTION_HAS_CONFIG_FILE => true
        ];
    }

    public function __construct($params, $config) {
        if ($config != null && Common::arrayKeyExistsAll(Array('host', 'port', 'user', 'password'), $config)) {
            $this->config = $config;
        } else {
            throw new CodedException(Common::EXCEPTION_INVALID_CONFIG);
        }

    }

    const QUEUE_SYSTEM_MAILER           =   'WorkQueue::System.Mailer';
    const CHANNEL_SYSTEM                =   1;  // Don't start at 0, it breaks :-/ BB
    const CHANNEL_MAIN                  =   2;

    const EXCEPTION_NOT_CONFIGURED       =  'QueueException::NoConnectionConfigured';
    const EXCEPTION_FAILED_CONNECTION   =   'QueueException::ConnectionFailed';
    const EXCEPTION_ALREADY_CONNECTED   =   'QueueException::QueueAlreadyConnected';

    private $config,
            $is_connected = false,
            $channel_id = Queue::CHANNEL_MAIN;

    /** @var PhpAmqpLib\Connection\AMQPConnection */
    private $connection;

    /* @var PhpAmqpLib\Channel\AMQPChannel */
    protected $channel;

    function __destruct() {
        try {
            if ($this->connection) {
                if ($this->channel) {
                    $this->channel->close();
                }
                $this->connection->close();
            }
        } catch (Exception $e) {
            // This can generate an error for some reason if an exchange wasn't created (ie: queue only)
        }
    }

    private function connect() {
        try {
            $this->connection = new AMQPConnection(
                $this->config['host'],
                $this->config['port'],
                $this->config['user'],
                $this->config['password']
            );
            $this->channel = $this->connection->channel();
            $this->is_connected = true;
        } catch (Exception $e) {
            throw new CodedException(Queue::EXCEPTION_FAILED_CONNECTION, $e);
        }
    }

    public function send($exchange_name, $payload, $routing_key = null, $persist = false, $reply_queue = null, $msg_options = Array()) {
        if (!$this->is_connected) {
            $this->connect();
        }

        $msg_options['delivery_mode'] = ($persist) ? 2 : 1;
        if ($reply_queue !== null) {
            $msg_options['reply_to'] = $reply_queue;
        }

        $msg = new AMQPMessage(json_encode($payload, JSON_UNESCAPED_UNICODE), $msg_options);
        $this->channel->basic_publish($msg, $exchange_name, $routing_key);
        error_log('Queue::Sent'. json_encode($payload). ' '. $routing_key);
    }

    public function defineExchange($exchange_name, $exchange_type = 'fanout', $persist = false, $auto_delete = false) {
        if (!$this->is_connected) {
            $this->connect();
        }
        $this->channel->exchange_declare($exchange_name, $exchange_type, false, $persist, $auto_delete);
    }

    public function defineQueue($queue_name, $persist = false, $auto_delete = false) {
        if (!$this->is_connected) {
            $this->connect();
        }
        // TODO: determine how to incorporate other queue_options...
        $this->channel->queue_declare($queue_name, false, $persist, false, $auto_delete);
    }

    public function switchDefaultChannel($channel_id) {
        if ($this->is_connected) {
            throw new CodedException(Queue::EXCEPTION_ALREADY_CONNECTED);
        }

        $this->channel_id = $channel_id;
    }

    public function prefetchSize($count) {
        if (!$this->is_connected) {
            $this->connect();
        }
        $this->channel->basic_qos(null, $count, null);
    }

    public function fetch($queue_name, $callback, $expect_ack) {
        $this->channel->basic_consume($queue_name, '', false, ($expect_ack) ? false : true, false, false,
            function($msg) use ($callback) {
                $callback(new QueueEntry($msg));
            }
        );
    }

    public function wait($block = true, $timeout = 0) {
        $this->channel->wait(null, $block, $timeout);
    }
}
