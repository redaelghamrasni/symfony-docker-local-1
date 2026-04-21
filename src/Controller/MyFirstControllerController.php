<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\GreetingService;
use App\Traits\FlashMessageTrait;

final class MyFirstControllerController extends AbstractController
{
    use FlashMessageTrait;

    protected $greetingService;
    

    public function __construct(GreetingService $greetingService)
    {
        $this->greetingService = $greetingService;
    }

    #[Route('/myfirstcontroller', name: 'app_my_first_controller')]
    public function index(): Response
    {
        return $this->render('my_first_controller/index.html.twig', [
            'controller_name' => self::class,
        ]);
    }

    #[Route('/myfirstcontroller/hello/{name}', name: 'app_my_first_controller_hello')]
    public function sayHello(
        Request $request,
        string $name = 'World'
        ): Response
    {
        $lang = $request->query->get('lang', 'en');

         if (!in_array($lang, ['en', 'fr'], true)) {
            $lang = 'en';
        }

        $message = $this->greetingService->greet($name, $lang);
        return $this->render('my_first_controller/hello.html.twig', [
            'controller_name' => self::class,
            'name' => $name,
            'lang' => $lang,
        ]);
    }

    #[Route('/myfirstcontroller/profile/{id}',
        name:'app_my_first_controller_profile',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )] 
    public function profile(int $id): Response
    {
        return $this->render('my_first_controller/profile.html.twig', [
            'controller_name' => self::class,
            'id' => $id,
        ]);

    }

    #[Route('/myfirstcontroller/contact',
         name: 'app_contact_form',
         methods: ['GET']
    )]
    public function contactForm(): Response
    {
        return $this->render('my_first_controller/contact.html.twig');
    }

    #[Route('/myfirstcontroller/contact',
         name: 'app_form_submit',
         methods: ['POST']
    )]
    public function formSubmit(Request $request): Response
    {
        $email = trim((string) $request->request->get('email', ''));
        $message = trim((string) $request->request->get('message', ''));

        $email = trim($email);
        $message = trim($message);

        if ($email === '' || $message === '') {
            $this->addErrorMessage('This field is required.');
            return $this->redirectToRoute('app_contact_form');
        }

        return $this->redirectToRoute('app_contact_form');
    }
}