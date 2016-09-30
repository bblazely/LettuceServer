<?php

interface iStorageDurable {
    public function select($select_query, $bindings, $options);
    public function update($update_query, $bindings);
    public function delete($delete_query, $bindings);
    public function insert($table, $bindings, $return_id);

    // Transaction Support
    public function enterTransaction();
    public function exitTransaction($commit = true);
    public function inTransaction();
}

interface iStorageVolatile {
}

Class Storage implements iLettuceExtension {
    static function ExtGetDependencies() { }
    static function ExtGetOptions() {
        return [
            self::OPTION_INSTANTIATE_AS => self::INSTANTIATE_SINGLETON
        ];
    }

    const
        MODULE_VOLATILE                     = 'VolatileMemcached',      // Key/Value In Memory Storage
        MODULE_DURABLE                      = 'DurablePDO',            // SQL On Disk Storage

        EXCEPTION_STORAGE_TYPE_NOT_SPECIFIED = 'Storage::StorageTypeNotSpecified',              // b26a1c79
        EXCEPTION_INVALID_COUNTER_DIRECTION  = 'Storage::InvalidCounterDirectionSpecified',     // a6ca3d7c

        OPT_STORAGE_NO_CACHE                = 55,     // Do not cache the result from durable into volatile. Generally used when we know that the result we're looking for is about to be invalidated

        OPT_DURABLE_FETCH_STYLE             = 100,    // Fetch Style. (ie: Column vs Assoc)
        OPT_DURABLE_FETCH_ARGUMENT          = 101,    // Fetch Style Argument (ie: name of a column for FETCH_STYLE_COL)
        OPT_DURABLE_COLLAPSE_SINGLE         = 102,    // Return single row responses directly rather than in a single entry array
        OPT_DURABLE_RETURN_ID               = 103,    // True/False - attempt to get / return auto-auto assigned ID field value on insert.
        OPT_DURABLE_DYNAMIC_RESULT_SIZE     = 104,    // Generally a call to a stored procedure whose output columns vary given different input. libmysql doesn't like re-using queries in this case,,,

        OPT_VOLATILE_SPREAD_LOOKUP          = 200,    // Spread cache entries randomly across cache nodes
        OPT_VOLATILE_EXPIRATION             = 201,    // Specify a cache expiration timer. default = 0
        OPT_VOLATILE_PATH_CREATE_MISSING    = 202,    // If the collection path is missing, create it.
        OPT_VOLATILE_PATH_REFRESH           = 203,    // If the collection is already present, refresh it with a new ID (invalidate the tree).
        OPT_VOLATILE_UPDATE_STYLE           = 204,    // How an update to volatile storage is handled
        OPT_VOLATILE_DELETE_CID             = 205,    // Also remove the CID for this key if it exists
        OPT_VOLATILE_ALLOW_OVERWRITE        = 206,    // Allow a storage::create op to overwrite an existing key (set vs add)

        PARAM_COUNTER_INCREMENT               = 'increment',
        PARAM_COUNTER_DECREMENT               = 'decrement',

        DURABLE_FETCH_STYLE_COL             = 1,

        VOLATILE_UPDATE_APPEND              = 'append',


        STR_VOLATILE_COLLECTION_ID          = 'CID',
        NO_RESULT                           = -1;

    private
        $engines,
        $config;
    /*
    !!! NOTE: Private functions assume that the public facing methods have connected the providers.
    */

    public function __construct($params, $config) { }

    public function getVolatileKey($base_key, $collection, $collection_key = null, $options = null) {
        if ($collection) {
            $this->connectModule(self::MODULE_VOLATILE);

            $collection_id = $this->getCollectionId(
                $base_key . '.' . $collection,
                Common::getIfSet($options[self::OPT_VOLATILE_EXPIRATION], 0),
                Common::getIfSet($options[self::OPT_VOLATILE_PATH_CREATE_MISSING], true),
                Common::getIfSet($options[self::OPT_VOLATILE_PATH_REFRESH], false)
            );

            if (!$collection_id) {  // No CID found.
                return null;
            }

            $base_key .= '.' . $collection . ':' . $collection_id;
        }

        return ($collection_key) ? $base_key . '.' . $collection_key : $base_key;
    }

    public function update($volatile_key, $volatile_value, $update_query = null, $update_bindings = null, $options = null) {
        error_log('UPDATE');
        error_log($volatile_key.'|'.serialize($volatile_value));
        error_log($update_query.'|'.serialize($update_bindings));

        if ($update_query) {
            $this->connectModule(self::MODULE_DURABLE);
            $this->engines[self::MODULE_DURABLE]->update($update_query, $update_bindings);
        }

        if ($volatile_key) {
            $this->connectModule(self::MODULE_VOLATILE);

            if (Common::getIfSet($options[self::OPT_VOLATILE_UPDATE_STYLE]) == self::VOLATILE_UPDATE_APPEND) {
                $this->engines[self::MODULE_VOLATILE]->append(
                    $volatile_key,
                    $volatile_value,
                    Common::getIfSet($options[self::OPT_VOLATILE_EXPIRATION], 0)
                );
            } else {
                $this->engines[self::MODULE_VOLATILE]->delete($volatile_key);
            }
        }
    }

    public function delete($volatile_key, $durable_query = null, $durable_query_bindings = null, $options = null) {
        if ($durable_query) {
            $this->connectModule(self::MODULE_DURABLE);
            $this->engines[self::MODULE_DURABLE]->delete($durable_query, $durable_query_bindings);
        }

        if ($volatile_key) {
            $this->connectModule(self::MODULE_VOLATILE);
            $this->engines[self::MODULE_VOLATILE]->delete($volatile_key);
            if (Common::getIfSet($options[self::OPT_VOLATILE_DELETE_CID])) {
                $this->engines[self::MODULE_VOLATILE]->delete($volatile_key . '.' . self::STR_VOLATILE_COLLECTION_ID);
            }
        }
    }

    public function createVolatile($key, $value, $options = null) {
        $this->connectModule(self::MODULE_VOLATILE);

        $op = (Common::getIfSet($options[self::OPT_VOLATILE_ALLOW_OVERWRITE])) ? 'set' : 'add';

        return $this->engines[self::MODULE_VOLATILE]->$op(
            $key,
            $value,
            Common::getIfSet($options[self::OPT_VOLATILE_EXPIRATION], 0),
            Common::getIfSet($options[self::OPT_VOLATILE_SPREAD_LOOKUP], false)
        );
    }

    public function updateCounter($key, $direction, $step = 1, $initial_value = 0, $expiration = 0) {
        if ($direction != self::PARAM_COUNTER_DECREMENT && $direction != self::PARAM_COUNTER_INCREMENT) {
            throw new CodedException(Storage::EXCEPTION_INVALID_COUNTER_DIRECTION, null, $direction);
        }

        $this->connectModule(self::MODULE_VOLATILE);
        return $this->engines[self::MODULE_VOLATILE]->$direction($key, (int)$step, (int)$initial_value, $expiration);
    }

    public function createDurable($table, $bindings, $options = null) {
        $this->connectModule(self::MODULE_DURABLE);
        return $this->engines[self::MODULE_DURABLE]->insert($table, $bindings, (Common::getIfSet($options[self::OPT_DURABLE_RETURN_ID])));
    }

    public function retrieve($volatile_key = null, $durable_query = null, $durable_query_bindings = null, $options = null) {
        if ($volatile_key) {
            $this->connectModule(self::MODULE_VOLATILE);
            $result = $this->engines[self::MODULE_VOLATILE]->get($volatile_key, (Common::getIfSet($options[self::OPT_VOLATILE_SPREAD_LOOKUP])) ? $this->engines[self::MODULE_VOLATILE]->randomServerKey() : false);
            if ($result) {
                return ($result == self::NO_RESULT) ? null : $result;  // Result was either valid, or known to be empty from a previous query.
            }
        }

        // Wasn't found in the cache, or no cache key was specified.
        if ($durable_query) {
            $this->connectModule(self::MODULE_DURABLE);
            $result = $this->engines[self::MODULE_DURABLE]->select($durable_query, $durable_query_bindings, $options);
            $result = (Common::getIfSet($options[self::OPT_DURABLE_COLLAPSE_SINGLE], false) && count($result) == 1) ? $result[0] : $result;

            // Cache this response for next time unless the no_cache storage option has been set to true
            if ($volatile_key && !($options[self::OPT_STORAGE_NO_CACHE] ?? true)) {
                $this->engines[self::MODULE_VOLATILE]->set(
                    $volatile_key,
                    ($result) ? $result : self::NO_RESULT,
                    Common::getIfSet($options[self::OPT_VOLATILE_EXPIRATION], 0),
                    Common::getIfSet($options[self::OPT_VOLATILE_SPREAD_LOOKUP]) ? $this->engines[self::MODULE_VOLATILE]->randomServerKey() : false);
            }

            return ($result == self::NO_RESULT) ? null : $result;
        } else {
            return null;   // No Cache result and no DB Query string. Return null.
        }
    }

    public function enterTransaction() {
        $this->connectModule(self::MODULE_DURABLE);
        return $this->engines[self::MODULE_DURABLE]->enterTransaction();
    }

    public function exitTransaction($commit = true) {
        $this->connectModule(self::MODULE_DURABLE);
        return $this->engines[self::MODULE_DURABLE]->exitTransaction($commit);
    }

    // Private Methods

    private function refreshCollectionId($key, $expiration = 0) {
        $key .= '.' . self::STR_VOLATILE_COLLECTION_ID;
        $cid = uniqid();

        // Try and replace the key
        if (!$this->engines[self::MODULE_VOLATILE]->safeSet($key, null, $cid, $expiration, null, null)) {
            return null;
        }
        return $cid;
    }

    private function getCollectionId($key, $expiration = 0, $create_if_missing = true, $refresh_if_present = false) {
        $cid = $this->engines[self::MODULE_VOLATILE]->getObject($key . '.' . self::STR_VOLATILE_COLLECTION_ID);
        if ((!$cid && $create_if_missing) || ($cid && $refresh_if_present)) {
            $cid = $this->refreshCollectionId($key, $expiration);
        }
        return $cid;
    }

    private function connectModule($engine) {
        if (!isset($this->engines[$engine])) {
            $engine_class = 'Storage'.$engine;
            $this->engines[$engine] = LettuceGrow::extension($engine_class, null, [
                self::OPTION_BASE_PATH => __DIR__ . '/Engines/'
            ]);
        }
        return $this->engines[$engine];
    }
}
