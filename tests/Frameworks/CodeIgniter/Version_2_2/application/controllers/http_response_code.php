<?php if (!defined('BASEPATH')) { exit('No direct script access allowed'); }


class Http_Response_Code extends CI_Controller {
    public function error() {
        http_response_code(500);
    }

    public function success() {
        http_response_code(200);
    }

}
