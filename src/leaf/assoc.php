<?php

Class assoc_controller extends LettuceController {
    public function root($user_id, $assoc_id) {
        /* ACL_PUBLIC */
        $this->getSession(false);

        var_dump($this->model->get($user_id, $assoc_id));
    }
}


Class assoc_model extends LettuceModel {
    public function get($user_id, $assoc_id) {
        return $this->assoc()->getSingle($user_id, $assoc_id);
    }
}
