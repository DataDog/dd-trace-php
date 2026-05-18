<?php

declare(strict_types=1);

namespace Application\Controller;

use Laminas\Authentication\Adapter\DbTable\CredentialTreatmentAdapter;
use Laminas\Authentication\AuthenticationService;
use Laminas\Db\Adapter\Adapter;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;

class LoginController extends AbstractActionController
{
    private $dbAdapter;
    private $authService;

    public function __construct(Adapter $dbAdapter, AuthenticationService $authService)
    {
        $this->dbAdapter = $dbAdapter;
        $this->authService = $authService;
    }

    public function authAction()
    {
        $email = $this->params()->fromQuery('email');
        $password = $this->params()->fromQuery('password', 'password');

        if (!$email) {
            $response = $this->getResponse();
            $response->setStatusCode(400);
            $response->setContent('Email is required');
            return $response;
        }

        // Set up authentication adapter
        $authAdapter = new CredentialTreatmentAdapter(
            $this->dbAdapter,
            'users',
            'email',
            'password',
            'MD5(?)'
        );

        $authAdapter->setIdentity($email);
        $authAdapter->setCredential($password);

        // Perform authentication
        $result = $this->authService->authenticate($authAdapter);

        if ($result->isValid()) {
            // Get the user data from the database
            $userData = $authAdapter->getResultRowObject(null, 'password');

            // Store user identity
            $this->authService->getStorage()->write($userData);

            $response = $this->getResponse();
            $response->setStatusCode(200);
            $response->setContent('Login successful');
            return $response;
        } else {
            $response = $this->getResponse();
            $response->setStatusCode(403);
            $response->setContent('Invalid credentials');
            return $response;
        }
    }

    public function behindAuthAction()
    {
        $identity = $this->authService->getIdentity();
        if ($identity === null) {
            $response = $this->getResponse();
            $response->setStatusCode(401);
            $response->setContent('Unauthorized');
            return $response;
        }

        $response = $this->getResponse();
        $response->setStatusCode(200);
        $response->setContent('page behind auth');
        return $response;
    }
}
