<?php

namespace app\modules\forum\controllers;

use yii\web\Controller;

class ModuleController extends Controller
{
    /**
     * @return string
     */
    public function actionView($state, $city, $neighborhood)
    {
        return "Hit {$state}/{$city}/{$neighborhood}";
    }
}
