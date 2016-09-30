<?php
class api_helper_controller extends LettuceController {

    // Returns hex code for a CodedException error string
    public function get_error_code($input) {
        print "Error Code for '{$input}' is: " . dechex(crc32($input));
    }

}