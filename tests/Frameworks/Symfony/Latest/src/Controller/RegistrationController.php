<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class RegistrationController extends AbstractController
{
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    #[Route('/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager): Response
    {
        $this->logger->debug('Registering a new user');
        $user = new User();
        $this->logger->debug('Created a new user');
        $form = $this->createForm(RegistrationFormType::class, $user);
        $this->logger->debug('Created a new form');
        $form->handleRequest($request);
        $this->logger->debug('Handled a new form');

        $this->logger->debug('Form is submitted: ' . $form->isSubmitted());
        $this->logger->debug('Form is valid: ' . $form->isValid());
        // Print errors
        foreach ($form->getErrors(true) as $error) {
            $this->logger->debug('Error: ' . $error->getMessage());
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $this->logger->debug('Form is valid');
            // encode the plain password
            $user->setPassword(
                $userPasswordHasher->hashPassword(
                    $user,
                    $form->get('plainPassword')->getData()
                )
            );

            $entityManager->persist($user);
            $entityManager->flush();
            // do anything else you need here, like send an email

            return $this->redirectToRoute('simple');
        }
        $this->logger->debug('Form is not valid');

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }
}
