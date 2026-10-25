<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\LoginFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Demo login for missing-log Web UI (ROLE_ADMIN).
 */
final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        $form = $this->container->get('form.factory')->createNamed('', LoginFormType::class, null, [
            'action'        => $this->generateUrl('app_login'),
            'last_username' => $authenticationUtils->getLastUsername(),
        ]);

        return $this->render('security/login.html.twig', [
            'form' => $form->createView(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Logout is handled by the security firewall.');
    }
}
