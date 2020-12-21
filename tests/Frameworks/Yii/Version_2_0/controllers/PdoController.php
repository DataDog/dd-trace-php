<?php

namespace app\controllers;

use yii\web\Controller;

class PdoController extends Controller
{
    /**
     * @return string
     */
    public function actionIndex()
    {
        $pdo = new \PDO(
            'mysql:host=mysql_integration;dbname=test',
            'test',
            'test',
            [\PDO::ATTR_EMULATE_PREPARES => false]
        );
        $stmt = $pdo->prepare('SELECT 1');
        $stmt->execute();
        error_log('Everything is done!');
        return 'Hello Pdo';
    }
}
