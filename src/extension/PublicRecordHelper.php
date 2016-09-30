<?php

class PublicIdHelperCore extends PublicIdHelper Implements iLettuceCore {
    public function __construct(LettuceRoot $di_root) {
        parent::__construct($di_root->grow->module('EntityFactory'), $di_root->grow->module('Assoc'));
    }
}

class PublicIdHelper {
    /** @var  EntityFactory $entity */
    private $entity;
    private $assoc;

    public function __construct(EntityFactory $di_entity, Association $di_assoc) {
        $this->entity = $di_entity;
        $this->assoc = $di_assoc;
    }

    /**
     * @param $entity_id
     *
     * @return EntityPublicId
     *
     * @throws CodedException
     */
    public function getPublicIdForEntity($entity_id) {
        if (!is_numeric($entity_id)) {
            throw new CodedException(EntityFactory::EXCEPTION_ENTITY_ID_INVALID, null, $entity_id);
        }

        $public_id_list = $this->assoc->getList($entity_id, Association::PR__HAS, false);
        if (count($public_id_list) > 0) {
            return $this->entity->get($public_id_list[0], EntityFactory::SCHEMA_PUBLIC_ID);
        } else {
            throw new CodedException(EntityFactory::EXCEPTION_ENTITY_ID_NOT_FOUND, null, 'PublicId.'.$entity_id);
        }
    }
}