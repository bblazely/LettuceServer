<?php

class StorageVolatileMemcached extends Memcached implements iStorageVolatile, iLettuceExtension {
    static function ExtGetDependencies() { /* No Requirements */ }
    static function ExtGetOptions() {
        return [self::OPTION_HAS_CONFIG_FILE => true, self::OPTION_INSTANTIATE_AS => self::INSTANTIATE_SINGLETON];
    }

    const NO_RESULT             = -1;
    const LOG_PATH              = '/tmp/dev_lettuce_cache.log';
    const LOG_MASK              = "%-14s | %5s | %-100s | %4d | %4d | %1.5fs\n";
    const LOG_STORAGE_HIT         = '< HIT';
    const LOG_STORAGE_MISS        = '? MISS';
    const LOG_STORAGE_HIT_EMPTY   = '< HIT EMPTY';
    const LOG_STORAGE_EXTEND      = '+ EXTEND';
    const LOG_STORAGE_EXTEND_ERROR= 'X EXTEND ERROR';
    const LOG_STORAGE_PUT         = '> PUT';
    const LOG_STORAGE_PUT_EMPTY   = '> PUT EMPTY';
    const LOG_STORAGE_PUT_EXISTS  = '> EXISTS';
    const LOG_STORAGE_ERROR       = 'X ERROR';
    const LOG_STORAGE_DELETE      = '! DELETE';
    const LOG_STORAGE_DELETE_MISS = '? DELETE MISS';
    const LOG_STORAGE_CAS_UPDATE  = '# CAS UPDATE';
    const LOG_STORAGE_CAS_PREEMPT = '# CAS PREEMPT';
    const LOG_STORAGE_CAS_PUT     = '> PUT CAS';
    const LOG_STORAGE_CAS_ERROR   = 'X CAS ERROR';
    const LOG_STORAGE_TOUCH       = '^ TOUCH';
    const LOG_STORAGE_INC         = '+ INC';
    const LOG_STORAGE_INC_FAIL    = 'X INC FAIL';
    const LOG_STORAGE_DEC         = '- DEC';
    const LOG_STORAGE_DEC_FAIL    = 'X DEC FAIL';
    const LOG_STORAGE_REHASH      = '* RE-HASH';
    const COLLECTION_ID         = 'CID';
    const DEBUG                 = 1;

    const APPEND                = 1;
    const PREPEND               = 2;

    public function __construct($params, $config) {
        if ($config != null && Common::arrayKeyExistsAll(Array('servers', 'instance'), $config)) {
            parent::__construct($config['instance']);
            if ($this->addServers($config['servers'])) {
                $this->setOptions([
                    //self::OPT_SERIALIZER => self::SERIALIZER_JSON     // Disabled, it does odd things (like adding extra array indexes) and encodes unicode. json_encode/decode used instead.
                    self::OPT_NO_BLOCK => true,
                    self::OPT_TCP_NODELAY => true,              // Without this option, cache misses in binary mode take >30ms (leave it on for ascii too)
                    self::OPT_BINARY_PROTOCOL => false,         // Disable binary for Javascript compatibility
                    self::OPT_COMPRESSION => false,             // Disable compression for Javascript compatibility
                    self::OPT_LIBKETAMA_COMPATIBLE => true,      // Enable Ketama Mode
                    self::OPT_REMOVE_FAILED_SERVERS => true,
                    self::OPT_SERVER_FAILURE_LIMIT => 2,
                    self::OPT_RETRY_TIMEOUT => 30
                ]);
            }
        } else {
            throw new CodedException(Common::EXCEPTION_INVALID_CONFIG, null, __CLASS__);
        }
    }

    public function addServer($host, $port, $weight = 1) {
        $servers = $this->getServerList();
        foreach ($servers as $server) {
            if ($server['host'] == $host && $server['port'] == $port) {
                return false;   // Already in the pool
            }
        }
        return parent::addServer($host, $port, $weight);
    }

    public function addServers(array $servers) {
        $added = false;
        foreach ($servers as $server) {
            $added |= $this->addServer($server[0], $server[1], Common::getIfSet($server[2], 0));
        }
        return $added;
    }

    public function touch($key, $expiration = 0) {
        $start_time = $this->debugStartTimer();

        $cas = null;
        $content = $this->get($key, $cas, null);
        $this->safeSet($key, $cas, $content, $expiration);

        return $this->handleResult(
            self::LOG_STORAGE_TOUCH,
            self::LOG_STORAGE_ERROR,
            $key,
            null,
            $start_time,
            $content
        );
    }

    public function increment($key, $offset = 1, $initial_value = 0, $expiration = 0) {
        $start_time = $this->debugStartTimer();
        $result = parent::increment($key, $offset, $initial_value, $expiration);
        error_log($key."|". $offset."|".$result ."|".$this->getResultCode()."|".$this->getResultMessage());;
        $this->handleResult(
            self::LOG_STORAGE_INC,
            self::LOG_STORAGE_INC_FAIL,
            $key,
            $expiration,
            $start_time
        );
        return $result;
    }

    public function decrement($key, $offset = 1, $initial_value = 0, $expiration = 0) {
        $start_time = $this->debugStartTimer();
        $result = parent::decrement($key, $offset, $initial_value, $expiration);
        $this->handleResult(
            self::LOG_STORAGE_DEC,
            self::LOG_STORAGE_DEC_FAIL,
            $key,
            $expiration,
            $start_time
        );
        return $result;
    }

    public function delete($key, $time = 0) {
        $start_time = $this->debugStartTimer();
        parent::delete($key, $time);

        return $this->handleResult(
            self::LOG_STORAGE_DELETE,
            self::LOG_STORAGE_DELETE_MISS,
            $key,
            $time,
            $start_time
        );
    }

    public function get($key, $cache_cb = null, $get_flags = null, &$cas_token = null, $server_key = null) {
        $start_time = $this->debugStartTimer();

        if ($server_key) {
            $cache_data = parent::getByKey($server_key, $key, null, $cas_token);
        } else {
            $cache_data = parent::get($key, null, $cas_token);
        }
        $data = json_decode($cache_data, true);
        if ($data === null) {
            $data = $cache_data;
        }

        $this->handleResult(
            ($data === -1) ? self::LOG_STORAGE_HIT_EMPTY : self::LOG_STORAGE_HIT,
            self::LOG_STORAGE_MISS,
            $key,
            null,
            $start_time,
            $cache_data
        );

        return $data;
    }

    public function randomServerKey() {
        // No native implementation or anything for memcached, just return something 'random'
        return rand(0, 100000);
    }

    public function set($key, $value, $expiration = 0, $server_key = null) {
        $start_time = $this->debugStartTimer();

        if (is_array($value)) {
            $value   = json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if ($server_key) {
            parent::setByKey($server_key, $key, $value, $expiration);
        } else {
            parent::set($key, $value, $expiration);
        }

        return $this->handleResult(
            ($value === -1) ? self::LOG_STORAGE_PUT_EMPTY : self::LOG_STORAGE_PUT,
            self::LOG_STORAGE_ERROR,
            $key,
            $expiration,
            $start_time,
            $value
        );
    }

    public function replace($key, $value, $expiration = 0) {
        $start_time = $this->debugStartTimer();

        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        parent::replace($key, $value, $expiration);
        return $this->handleResult(
            (($value === -1) ? self::LOG_STORAGE_PUT_EMPTY : self::LOG_STORAGE_PUT) . '[R]',
            self::LOG_STORAGE_ERROR . '[R]',
            $key,
            $expiration,
            $start_time,
            $value
        );
    }

    public function add($key, $value, $expiration = 0, $server_key = null) {
        $start_time = $this->debugStartTimer();

        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if ($server_key) {
            parent::addByKey($server_key, $key, $value, $expiration);
        } else {
            parent::add($key, $value, $expiration);
        }

        return $this->handleResult(
            ($value === -1) ? self::LOG_STORAGE_PUT_EMPTY : self::LOG_STORAGE_PUT,
            self::LOG_STORAGE_PUT_EXISTS,
            $key,
            $expiration,
            $start_time,
            $value
        );
    }

    public function append($key, $value, $expiration = 0, $delimiter = "\n") {
        $this->extend(self::APPEND, $key, $value, $expiration, $delimiter);
    }

    public function prepend($key, $value, $expiration = 0, $delimiter = "\n") {
        $this->extend(self::PREPEND, $key, $value, $expiration, $delimiter);
    }

    private function extend($direction = self::APPEND, $key, $value, $expiration = 0, $delimiter = "\n") {
        $start_time = $this->debugStartTimer();

        $result = parent::add($key, $value, $expiration);
        if (!$result) {
            if ($direction === self::APPEND) {
                parent::append($key, $delimiter . $value);
            } else {
                parent::prepend($key, $value . $delimiter);
            }

            if ($result && $expiration !== 0) {
                parent::touch($key, $expiration);
            }
        }
        return $this->handleResult(
            self::LOG_STORAGE_EXTEND,
            self::LOG_STORAGE_EXTEND_ERROR,
            $key,
            $expiration,
            $start_time,
            $value
        );
    }

    // TODO add a param to count retries, fail if > xx
    public function safeSet($key, $token, $value, $expiration = 0) {
        $start_time = $this->debugStartTimer();

        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        if ($token == NULL) {   // No CAS Token specified
            if ($this->add($key, $value, $expiration) === false) {    // Try to push it
                if ($this->get($key, $token, null)) {       // On fail, see if it exists
                    return $this->safeSet($key, $token, $value, $expiration); // Resubmit the value with a cas token this time.
                }

                // This will only ever be an error, but check for a cache failure anyway by passing it through 'handleResult'
                return $this->handleResult(
                    null,
                    self::LOG_STORAGE_CAS_ERROR,
                    $key,
                    $expiration,
                    $start_time,
                    $value
                );
            }
            return true;
        } else {
            // CAS
            $this->cas($token, $key, $value, $expiration);

            return $this->handleResult(
                self::LOG_STORAGE_CAS_PUT,
                ($this->getResultCode() == Memcached::RES_DATA_EXISTS ? self::LOG_STORAGE_CAS_PREEMPT : self::LOG_STORAGE_CAS_ERROR),
                $key,
                $expiration,
                $start_time,
                $value
            );
        }
    }

    public function getCollectionId($key, $expiration = 0, $refresh_key = true) {
        $cid = $this->get($key . '.' . self::COLLECTION_ID);
        if (!$cid && $refresh_key) {
            $cid = $this->resetCollectionId($key, $expiration);
        }
        return $cid;
    }

    public function resetCollectionId($key, $expiration = 0) {
        $key .= '.' . self::COLLECTION_ID;
        $cid = uniqid();

        // Try and replace the key
        if (!$this->safeSet($key, null, $cid, $expiration)) {
            return null;
        }
        return $cid;
    }

    // Private Methods

    private function debugStartTimer() {
        if (self::DEBUG) {
            return microtime(true);
        } else {
            return null;
        }
    }

    private function handleResult($success_msg, $fail_msg, $key, $expiration = null, $start_time = null, &$value = null) {
        $code = $this->getResultCode();
        switch ($code) {
            case 0:
                $result = true;
                break;

            // Errors that require a remap of the server hash keys
            case 2:      // HOST_LOOKUP_FAILURE
            case 3:      // CONNECTION_FAILURE
            case 4:      // CONNECTION_BIND_FAILURE
            case 11:     // ERROR
            case 26:     // ERRNO ??
            case 31:     // TIMEOUT
            case 35:     // SERVER_MARKED_DEAD
            case 47:     // SERVER_TEMPORARILY_DISABLED
                $this->setOption(self::OPT_LIBKETAMA_COMPATIBLE, true); // rehash, in case there's a dead server
                $result = false;

                if (self::DEBUG) {
                    $server_list = [];
                    foreach ($this->getStats() as $server => $stats) {
                        if ($stats['pid'] === -1) {
                            array_push($server_list, $server);
                        }
                    }
                    $this->log(self::LOG_STORAGE_REHASH, implode(' | ', $server_list), null, $code);
                }
                break;

            default:
                $result = false;
                break;
        }

        $this->log(
            ($result) ? $success_msg : $fail_msg,
            $key,
            $expiration,
            $code,
            $start_time,
            $value
        );

        return $result;
    }

    private function log($msg, $key, $expiration = null, $code = null, $started = null, &$data = null) {
        if (self::DEBUG) {
            error_log(sprintf(self::LOG_MASK, $msg, ($expiration !== null) ? $expiration : '', $key, strlen($data), $code, ($started) ? (microtime(true) - $started) : null), 3, self::LOG_PATH);
        }
    }

}