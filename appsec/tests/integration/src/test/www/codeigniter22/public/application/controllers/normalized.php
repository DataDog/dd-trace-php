<?php if (! defined('BASEPATH')) exit('No direct script access allowed');

class Normalized extends CI_Controller
{
    public function archive($year, $month)
    {
        echo $year . '-' . $month;
    }

    public function item($id)
    {
        echo $id;
    }

    public function literal()
    {
        echo 'literal';
    }

    public function optional()
    {
        echo 'optional';
    }
}

/* End of file normalized.php */
/* Location: ./application/controllers/normalized.php */
