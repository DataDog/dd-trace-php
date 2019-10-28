<?php

namespace app\controllers;

use yii\web\Controller;

class SimpleController extends Controller
{

    /**
     * @return string
     */
    public function actionIndex()
    {
        return 'Hello world';
    }

    /**
     * @return string
     */
    public function actionView()
    {
        return $this->render('index');
    }

    /**
     * @return string
     */
    public function actionError()
    {
        throw new \Exception('datadog');
    }
}
