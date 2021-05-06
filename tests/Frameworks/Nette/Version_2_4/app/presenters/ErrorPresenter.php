<?php

namespace App\Presenters;

use Nette;
use Nette\Application\Responses;
use Nette\Http;
use Tracy\ILogger;


final class ErrorPresenter implements Nette\Application\IPresenter
{
    use Nette\SmartObject;

    /** @var ILogger */
    private $logger;


    public function __construct(ILogger $logger)
    {
        $this->logger = $logger;
    }


    public function run(Nette\Application\Request $request)
    {
        $this->logger->log($exception, ILogger::EXCEPTION);
        return new Responses\CallbackResponse(function (Http\IRequest $httpRequest, Http\IResponse $httpResponse) {
            require __DIR__ . '/templates/Error/500.phtml';
        });
    }
}
