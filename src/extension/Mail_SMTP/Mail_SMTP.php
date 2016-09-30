<?php
// NOTE: Requires Composer Auto Load to have been included somewhere

Class Mail_SMTP implements iLettuceExtension {
    static function ExtGetOptions() {
        return [
            self::OPTION_HAS_CONFIG_FILE => true
        ];
    }

    const EXCEPTION_NOT_CONFIGURED      = 'Mail_SMTPException::NoConnectionConfigured';
    const EXCEPTION_FAILED_CONNECTION   = 'Mail_SMTPException::ConnectionFailed';

    private $is_connected,
            $config,
            $last_send;

    /** @var Swift_Mailer $server */
    private $server;

    public function __construct($params, $config) {
        if ($config != null && Common::arrayKeyExistsAll(['host', 'port', 'security', 'from'], $config)) {
            $this->config = $config;
            $this->is_configured = true;
        } else {
            throw new CodedException(Common::EXCEPTION_INVALID_CONFIG);
        }
    }

    private function connect() {
        if ($this->is_connected) {
            if ((time() - $this->last_send) <= 30) {
                return true;
            }

            print "Forcing disconnect due to long idle time.\n";
            $this->disconnect();
        }

        print "Connecting...\n";

        try {
            $transport = Swift_SmtpTransport::newInstance($this->config['host'], $this->config['port'], $this->config['security']);
            if (isset($this->config['user'])) {
                $transport->setUsername($this->config['user']);
            }

            if (isset($this->config['password'])) {
                $transport->setPassword($this->config['password']);
            }

            /* @var Swift_Mailer */
            $this->server = Swift_Mailer::newInstance($transport);
            $this->last_send = time();
            $this->is_connected = true;
            print "Connected!\n";
        } catch (Exception $e) {
            print "Connection Failed!\n";

            /** @var Swift_SmtpTransport $transport */
            $transport = $this->server->getTransport();
            if ($transport) {
                $transport->stop();
            }
            $this->server = null;
            $this->is_connected = false;
            throw new CodedException(Mail_SMTP::EXCEPTION_FAILED_CONNECTION, $e);
        }
    }

    public function disconnect() {
        if ($this->is_connected) {
            print "Disconnect Requested\n";
            $this->is_connected = false;
            $this->last_send = null;
            $transport = $this->server->getTransport();
            if ($transport) {
                $transport->stop();
            }
            $this->server = null;
        }
    }

    public function send($to, $subject, $plain, $html = null, $from = null, $retry = 1) {
        try {
            $this->connect();
        } catch (Exception $e) {
            die('connection failed');
        }
        $message = Swift_Message::newInstance();
        $message->setSubject($subject)->setTo($to)->setFrom(($from) ? $from : $this->config['from']);

        if ($plain) {
            $message->setBody($plain, 'text/plain');
            if ($html) {
                $message->addPart($html, 'text/html');
            }
        } else {
            $message->setBody($html, 'text/html');
        }

        try {
            $this->server->send($message);
            $this->last_send = time();
            return true;
        } catch (Exception $e) {
            $this->disconnect();
            if ($retry) {
                print "Failed. Retry!\n";
                return $this->send($to, $subject, $plain, $html, $from, $retry - 1);
            } else {
                return false;
            }
        }
    }
}