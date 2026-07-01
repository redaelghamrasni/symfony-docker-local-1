<?php

namespace App\Controller;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Form\ForgotPasswordType;
use App\Form\ResetPasswordType;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class PasswordResetController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private PasswordResetTokenRepository $tokenRepository,
        private MailerInterface $mailer,
        private UserPasswordHasherInterface $passwordHasher
    ) {}

    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(Request $request): Response
    {
        $form = $this->createForm(ForgotPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = $form->get('email')->getData();
            $user = $this->userRepository->findOneBy(['email' => $email]);

            // Always show success message for security (don't reveal if email exists)
            $this->addFlash('info', 'auth.forgot_password.info_message');

            if ($user) {
                // Remove old reset tokens for this user
                $oldTokens = $this->entityManager->getRepository(PasswordResetToken::class)
                    ->findBy(['user' => $user]);
                foreach ($oldTokens as $oldToken) {
                    $this->entityManager->remove($oldToken);
                }
                $this->entityManager->flush();

                // Create new reset token
                $token = bin2hex(random_bytes(32));
                $resetToken = new PasswordResetToken();
                $resetToken->setToken($token);
                $resetToken->setUser($user);

                $this->entityManager->persist($resetToken);
                $this->entityManager->flush();

                // Send email with reset link
                $resetLink = $this->generateUrl('app_reset_password', ['token' => $token], 0); // 0 = absolute URL
                $this->sendPasswordResetEmail($user, $resetLink);
            }

            return $this->redirectToRoute('app_forgot_password');
        }

        return $this->render('security/forgot_password.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function resetPassword(string $token, Request $request): Response
    {
        $resetToken = $this->tokenRepository->findByToken($token);

        if (!$resetToken || $resetToken->isExpired()) {
            $this->addFlash('error', 'auth.reset_password.error_message');
            return $this->redirectToRoute('app_forgot_password');
        }

        $form = $this->createForm(ResetPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $resetToken->getUser();
            $newPassword = $form->get('password')->getData();

            // Hash and set new password
            $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
            
            // Remove the reset token
            $this->entityManager->remove($resetToken);
            $this->entityManager->flush();

            $this->addFlash('success', 'auth.reset_password.success_message');
            return $this->redirectToRoute('app_auth_login');
        }

        return $this->render('security/reset_password.html.twig', [
            'form' => $form->createView(),
            'token' => $token,
        ]);
    }

    private function sendPasswordResetEmail(User $user, string $resetLink): void
    {
        $message = (new TemplatedEmail())
            ->from(new Address('no-reply@monapp.local', 'MonApp'))
            ->to($user->getEmail())
            ->subject('Réinitialisez votre mot de passe')
            ->htmlTemplate('emails/password_reset.html.twig')
            ->context([
                'user' => $user,
                'resetLink' => $resetLink,
            ]);

        $this->mailer->send($message);
    }
}
