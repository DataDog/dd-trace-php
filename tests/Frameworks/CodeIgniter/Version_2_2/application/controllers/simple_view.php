<?php

class Simple_View extends CI_Controller {
    function index() {
        $this->load->view('simple_view', array('message' => 'Hi'));
    }
}
