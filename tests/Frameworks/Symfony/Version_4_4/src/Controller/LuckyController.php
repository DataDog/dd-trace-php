<?php

namespace App\Controller;

use App\Message\LuckyNumberNotification;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

class LuckyController extends AbstractController
{
    /**
     * @Route("/lucky/number", name="lucky_number")
     */
    public function number(MessageBusInterface $bus): Response
    {
        $number = \random_int(0, 100);

        $bus->dispatch(new LuckyNumberNotification($number));

        return new Response("$number");
    }

    /**
     * @Route("/lucky/fail", name="lucky_fail")

     */
    public function fail(MessageBusInterface $bus): Response
    {
        $bus->dispatch(new LuckyNumberNotification(101));

        return new Response("101");
    }
}
