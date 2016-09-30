<?php

class LettuceModel
{
    const EXCEPTION_MODEL_NOT_IMPLEMENTED   =   'LettuceModel::NotImplemented';

    /** @var  LettuceGrow grow */
//    protected $grow;

    /** @var  EntityFactory entity */
    private $entity_factory;

    /** @var  Association assoc */
    protected $association_extension;

    /** @var  Storage storage */
    private $storage_extension;

    /** @var  UserSession user_session_extension */
    private $user_session_extension;

    // EntityFactory Factory Interface Bootstrap
    public function entity() {
        if (!$this->entity_factory) {
            $this->entity_factory = LettuceGrow::extension('EntityFactory');
        }
        return $this->entity_factory;
    }

    public function session() {
        if (!$this->user_session_extension) {
            $this->user_session_extension = LettuceGrow::extension('UserSession');
        }
        return $this->user_session_extension;
    }

   /* public function assoc() {
        if (!$this->association_extension) {
            $this->association_extension = LettuceGrow::extension('Association');
        }
        return $this->association_extension;
    }*/

    function __call($method, $args) {
        throw new CodedException(self::EXCEPTION_MODEL_NOT_IMPLEMENTED, null, $method);
    }

    function requireTransaction() {
        if (!$this->storage_extension) {
            $this->storage_extension = LettuceGrow::extension('Storage');
        }
        return $this->storage_extension->enterTransaction();
    }

    function endTransaction($commit = true) {
        if ($this->storage_extension) {
            return $this->storage_extension->exitTransaction($commit);
        }
        return false;
    }

    /** Maybe this should be exposed in the controller or view instead? */
    public function getChangeset($broadcast = true) {
        return LettuceGrow::extension('Changeset')->get($broadcast);
    }
}

