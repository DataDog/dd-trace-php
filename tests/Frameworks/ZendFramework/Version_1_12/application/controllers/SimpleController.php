<?php

class SimpleController extends Zend_Controller_Action
{
    public function indexAction()
    {
        // Don't auto render this action
        $this->_helper->viewRenderer->setNoRender();
        echo 'This is a string.';
    }

    public function viewAction()
    {
        // Empty
    }
}
