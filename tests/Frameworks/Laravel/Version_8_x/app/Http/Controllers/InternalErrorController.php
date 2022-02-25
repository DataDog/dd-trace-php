<?php

namespace App\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;

use Symfony\Component\HttpKernel\Exception\HttpException;

class InternalErrorController extends Controller
{
    public function notImplemented()
    {
        /* This error type is part of the internalDontReport list but this is
         * a 5xx class error, so we should get it anyway.
         */
        throw new HttpException(501, 'Not Implemented');
    }

    public function unauthorized()
    {
        throw new AuthorizationException();
    }
}
