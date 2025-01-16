<?php

declare(strict_types=1);

namespace App\Presenters;

use Nette;


final class HomepagePresenter extends Nette\Application\UI\Presenter
{

    public function actionSimple()
    {
        echo 'Simple action';
        $this->terminate();
    }

    public function renderSimpleView()
    {
        $this->template->name = 'simple view';
    }

    public function renderErrorView()
    {
        throw new \Exception('An exception occurred');
    }
}
