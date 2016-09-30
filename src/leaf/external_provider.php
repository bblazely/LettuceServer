<?php

class external_provider_controller extends LettuceController {
    /** @var external_provider_model model */
    protected $model;

    public function list() {
        $this->view->output($this->model->getExternalProviderList());
    }

    public function resolve() {
        print "NYI - Resolve user to ID, requires valid session and FB assoc";
    }
}

class external_provider_model extends LettuceModel {
    public function getExternalProviderList() {
        return LettuceGrow::extension('ExternalProvider')->getList();
    }

    public function get() {}
}