<?php

declare(strict_types=1);

namespace Application\Controller;

use Laminas\Authentication\Adapter\DbTable\CredentialTreatmentAdapter;
use Laminas\Authentication\AuthenticationService;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Mvc\Controller\AbstractActionController;

class LoginController extends AbstractActionController
{
    /** @var Adapter */
    private $dbAdapter;

    /** @var AuthenticationService */
    private $authService;

    /** @var TableGateway */
    private $usersTable;

    public function __construct(Adapter $dbAdapter, AuthenticationService $authService, TableGateway $usersTable)
    {
        $this->dbAdapter = $dbAdapter;
        $this->authService = $authService;
        $this->usersTable = $usersTable;
    }

    public function authAction()
    {
        $email = $this->params()->fromQuery('email');
        $password = $this->params()->fromQuery('password', 'password');
        $mode = $this->params()->fromQuery('mode', 'a');
        if (!$email) {
            $response = $this->getResponse();
            $response->setStatusCode(400);
            $response->setContent('Email is required');
            return $response;
        }

        // SQLite-compatible: passwords stored as MD5 hex; compare with bound value
        $authAdapter = new CredentialTreatmentAdapter(
            $this->dbAdapter,
            'users',
            'email',
            'password',
            '?'
        );

        $authAdapter->setIdentity($email);
        $authAdapter->setCredential(md5($password));

        if ($mode == 'a') {
            $result = $this->authService->authenticate($authAdapter);
        } else {
            $this->authService->setAdapter($authAdapter);
            $result = $this->authService->authenticate();
        }

        if ($result->isValid()) {
            $userData = $authAdapter->getResultRowObject(null, 'password');
            $this->authService->getStorage()->write($userData);

            $response = $this->getResponse();
            $response->setStatusCode(200);
            $response->setContent('Login successful');
            return $response;
        }

        $response = $this->getResponse();
        $response->setStatusCode(403);
        $response->setContent('Invalid credentials');
        return $response;
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
        $response->setContent('Authenticated page');
        return $response;
    }
}
