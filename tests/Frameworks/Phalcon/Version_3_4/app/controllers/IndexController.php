<?php

use Phalcon\Mvc\Controller;

class IndexController extends Controller
{
    public function indexAction()
    {
        echo '<h1>Hello!</h1>';
        $this->sampleCurlRequest();
    }

    private function sampleCurlRequest()
    {
        // create curl resource
        $ch = curl_init();

        // set url
        curl_setopt($ch, CURLOPT_URL, "https://www.google.com");

        //return the transfer as a string
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // $output contains the output string
        curl_exec($ch);
        echo "<p>Request ended</p>";

        // close curl resource to free up system resources
        curl_close($ch);
    }
}
