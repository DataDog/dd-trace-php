<?php

namespace app\controllers;

use yii\web\Controller;

class HomesController extends Controller
{

    /**
     * @return string
     */
    public function actionView($state, $city, $neighborhood)
    {
        return "Hit {$state}/{$city}/{$neighborhood}";
    }
}
