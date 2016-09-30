<?php

Class StorageDurablePDO extends PDO implements iStorageDurable, iLettuceExtension {
    static function ExtGetDependencies() { /* No Requirements */ }
    static function ExtGetOptions() { return [self::OPTION_HAS_CONFIG_FILE => true, self::OPTION_INSTANTIATE_AS => self::INSTANTIATE_SINGLETON]; }

    const
        EXCEPTION_NO_STORAGE_IN_TRANSACTION = 'DurableException::CannotCacheQueryInTransaction',
        EXCEPTION_NO_STORAGE_AVAILABLE      = 'DurableException::CacheNotSpecified',                //
        EXCEPTION_NOT_CONFIGURED            = 'DurableException::NoConnectionConfigured',           // f3ad4172
        EXCEPTION_COULD_NOT_EXECUTE         = 'DurableException::CouldNotExecuteStatement';         // 33900686

    //    ERROR_DUPLICATE_ENTRY               = '1062 Duplicate entry',
    //    ERROR_CONSTRAINT_VIOLATION          = '1452 Constraint violation';

    private
        $transaction_counter = 0;

    /** @var PDOStatement[]  */
        private $ps_cache = [];

    public function __construct($params, $config) {
        if ($config != null && Common::arrayKeyExistsAll(Array('type', 'dbname', 'user', 'password'), $config)) {
            $dsn = $config['type'] . ':';
            if (array_key_exists('socket', $config)) {
                $dsn .= 'unix_socket=' . $config['socket'] . ';'; // Connect via Unix Socket
            } else if (Common::arrayKeyExistsAll(['host', 'port'], $config)) {
                $dsn .= 'host=' . $config['host'] . ';port=' . $config['port'] . ';';   // Connect via TCP
            } else {
                throw new CodedException(Common::EXCEPTION_INVALID_CONFIG);
            }

            $dsn .= 'dbname='   . $config['dbname'] . ';' .
                    'user='     . $config['user'] . ';' .
                    'password=' . $config['password']. ';' .
                    'charset=utf8';

            try {
                parent::__construct($dsn, $config['user'], $config['password'], Array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,        // FULLTEXT DOESNT SEEM TO WORK WITHOUT THIS = FALSE (at least the LIMIT doesn't)
                    PDO::ATTR_PERSISTENT => Common::getIfSet($config['persist'], true)
                    // PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
                ));
            } catch (Exception $e) {
                throw new CodedException(self::EXCEPTION_NOT_CONFIGURED, null, $e->getMessage());
            }
        } else {
            throw new CodedException(Common::EXCEPTION_INVALID_CONFIG);
        }

    }

    public function __destruct() {
        if ($this->transaction_counter != 0) {
            error_log('WARNING: Database object removed with open transaction. Depth: ' . $this->transaction_counter);
        }
    }

    public function insert($table, $bindings, $return_id = false) {
        $cols = array_keys($bindings);
        $result = $this->prepare(
            'INSERT INTO ' . $table . '(' . implode(',', $cols) . ') VALUES (' .
                implode(',', array_map(
                    function ($col) {
                        return ':' . $col;
                    }, $cols
                )) .
            ')'
        )->execute($bindings);

        return ($return_id) ? $this->lastInsertId() : $result;
    }

    public function update($update_query, $bindings) {
        // TODO: Introduce the query cache and options here
        $this->prepare($update_query)->execute($bindings);
    }

    public function delete($delete_query, $bindings) {
        // TODO: Introduce the query cache and options here
        $this->prepare($delete_query)->execute($bindings);
    }

    public function select($select_query, $bindings = null, $options = null) {
        $hash = hash(Common::DEFAULT_HASH, $select_query);
        if (!Common::getIfSet($options[Storage::OPT_DURABLE_DYNAMIC_RESULT_SIZE])) {
            if (!isset($this->ps_cache[$hash])) {
                $this->ps_cache[$hash] = $this->prepare($select_query);
            }
            $query = $this->ps_cache[$hash];
        } else {
            $query = $this->prepare($select_query);
        }

        $fetch_style = null;
        if ($options) {
            switch ($options[Storage::OPT_DURABLE_FETCH_STYLE] ?? -1) {
                case Storage::DURABLE_FETCH_STYLE_COL:
                    $fetch_style = PDO::FETCH_COLUMN;
                    break;
                default:
                    $fetch_style = PDO::FETCH_ASSOC;
                    break;
            }
        }

        if ($query->execute($bindings)) {
            if ($options[Storage::OPT_DURABLE_FETCH_ARGUMENT] ?? null) {
                $result = $query->fetchAll($fetch_style, $options[Storage::OPT_DURABLE_FETCH_ARGUMENT] ?? null);
            } else {
                $result = $query->fetchAll($fetch_style);
            }
            $query->nextRowset();
            $query->closeCursor();

            return $result;
        } else {
            return null;
        }
    }

    // Transaction Support

    public function enterTransaction() {
        if(!$this->transaction_counter++) {
            return parent::beginTransaction();
        }
        return $this->transaction_counter >= 0;
    }

    public function exitTransaction($commit = true) {
        if ($commit) {
            if (!--$this->transaction_counter) {
                return parent::commit();
            }
            return $this->transaction_counter >= 0;
        } else {
            if($this->transaction_counter > 0) {
                $this->transaction_counter = 0;
                return parent::rollback();
            }
            $this->transaction_counter = 0;
            return false;
        }
    }

    public function inTransaction() {
        return parent::inTransaction();
    }
}
