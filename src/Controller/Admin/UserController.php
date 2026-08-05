<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\Admin\AdminUserType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/users', name: 'admin_users_')]
class UserController extends AbstractController
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {}

    #[Route('/', name: 'index')]
    public function index(Request $request): Response
    {
        $idsParam = $request->query->get('ids');

        if ($idsParam !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $idsParam)))));
            $users = $this->userRepository->findByIds($ids);
        } else {
            $users = $this->userRepository->findBy([], ['createdAt' => 'DESC']);
        }

        if ($request->isXmlHttpRequest()) {
            $html = $this->renderView('admin/users/_rows.html.twig', ['users' => $users]);
            return new JsonResponse([
                'html'  => $html,
                'total' => count($users),
            ]);
        }

        return $this->render('admin/users/index.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = new User();
        $form = $this->createForm(AdminUserType::class, $user, ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $this->addFlash('success', 'admin.users.created');
            return $this->redirectToRoute('admin_users_index');
        }

        return $this->render('admin/users/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'show')]
    public function show(User $user): Response
    {
        return $this->render('admin/users/show.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(User $user, Request $request): Response
    {
        $form = $this->createForm(AdminUserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            if ($plainPassword) {
                $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
            }

            $this->entityManager->flush();

            $this->addFlash('success', 'admin.users.updated');
            return $this->redirectToRoute('admin_users_index');
        }

        return $this->render('admin/users/edit.html.twig', [ 'form' => $form->createView(), 'user' => $user]);
    }

    #[Route('/{id}/reset-password', name: 'reset_password', methods: ['POST'])]
    public function resetPassword(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('reset-password' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'admin.users.csrf_error');
            return $this->redirectToRoute('admin_users_show', ['id' => $user->getId()]);
        }

        $tempPassword = bin2hex(random_bytes(4));
        $user->setPassword($this->passwordHasher->hashPassword($user, $tempPassword));
        $this->entityManager->flush();

        $this->addFlash('success', 'admin.users.password_reset');
        // In production, you would send an email with the temporary password
        $this->addFlash('info', 'admin.users.temp_password');

        return $this->redirectToRoute('admin_users_show', ['id' => $user->getId()]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'admin.users.csrf_error');
            return $this->redirectToRoute('admin_users_index');
        }

        // Prevent admin from deleting themselves
        if ($user->getId() === $this->getUser()?->getId()) {
            $this->addFlash('error', 'admin.users.cannot_delete_self');
            return $this->redirectToRoute('admin_users_show', ['id' => $user->getId()]);
        }

        $this->entityManager->remove($user);
        $this->entityManager->flush();

        $this->addFlash('success', 'admin.users.deleted');
        return $this->redirectToRoute('admin_users_index');
    }
}
