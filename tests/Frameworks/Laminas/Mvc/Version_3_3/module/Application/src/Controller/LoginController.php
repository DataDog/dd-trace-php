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

    public function signupAction()
    {
        $email = $this->params()->fromQuery('email');
        $name = $this->params()->fromQuery('name');
        $password = $this->params()->fromQuery('password', 'password');

        if (!$email || !$name || !$password) {
            $response = $this->getResponse();
            $response->setStatusCode(400);
            $response->setContent('Email, name, and password are required');
            return $response;
        }

        // Insert new user
        $connection = $this->dbAdapter->getDriver()->getConnection();
        $statement = $this->dbAdapter->query(
            "INSERT INTO users (name, email, password) VALUES (?, ?, MD5(?))"
        );
        $statement->execute([$name, $email, $password]);

        $userId = $this->dbAdapter->getDriver()->getLastGeneratedValue();

        // Track signup event manually since we're not using an event system
        if (function_exists('\datadog\appsec\track_user_signup_event_automated')) {
            \datadog\appsec\track_user_signup_event_automated($email, (string)$userId, []);
        }

        // Auto-login after signup
        $userData = (object)[
            'id' => $userId,
            'name' => $name,
            'email' => $email
        ];
        $this->authService->getStorage()->write($userData);

        $response = $this->getResponse();
        $response->setStatusCode(200);
        $response->setContent('Signup successful');
        return $response;
    }

    public function behindAuthAction()
    {
        // Check if user is authenticated
        if (!$this->authService->hasIdentity()) {
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
