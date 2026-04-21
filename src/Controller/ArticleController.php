<?php

namespace App\Controller;

use App\Entity\Article;
use App\Traits\FlashMessageTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;
use App\Form\ArticleType;

final class ArticleController extends AbstractController
{
    use FlashMessageTrait;
    
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/{locale}/articles/', name: 'app_article_list_locale', requirements: ['_locale' => 'fr|en'], defaults: ['locale' => 'fr'])]
    public function index(): Response {
        return $this->render('article/index.html.twig');
    }
    
    #[Route('/article/new', name: 'app_article_new', requirements: ['_locale' => 'fr|en'])]
    public function new(Request $request,TranslatorInterface $translator): Response
    {
        $article = new Article();
        $form = $this->createForm(ArticleType::class,$article);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            $article->setCreatedAt(new \DateTime());
            $this->entityManager->persist($article);
            $this->entityManager->flush();
            
            $this->addSuccessMessage('✅ Article créé avec succès !');
            
            return $this->redirectToRoute('app_article_show', ['id' => $article->getId()]);
        }
        
        return $this->render('article/new.html.twig', [
            'form' => $form,
        ]);
    }
    
    #[Route('/articles', name: 'app_article_list', requirements: ['_locale' => 'fr|en'])]
    public function list(): Response
    {
        $articles = $this->entityManager
            ->getRepository(Article::class)
            ->findAll();
        
        return $this->render('article/list.html.twig', [
            'articles' => $articles,
        ]);
    }
    
    
    #[Route('/article/{id}', name: 'app_article_show', requirements: ['_locale' => 'fr|en', 'id' => '\d+'])]
    /**
     * @param int $id
     * 
     * @return Response
     */
    public function show(Article $article): Response
    {
        if (!$article) {
            throw $this->createNotFoundException('Article non trouvé');
        }
        
        return $this->render('article/show.html.twig', [
            'article' => $article,
        ]);
    }

    #[Route('/article/create', name: 'app_article_create', requirements: ['_locale' => 'fr|en'], methods: ['POST'])]
public function create(Request $request): Response
{
    $title = $request->request->get('title');
    $content = $request->request->get('content');
    
    if (empty($title) || empty($content)) {
        $this->addErrorMessage('Le titre et le contenu sont obligatoires !');
        return $this->redirectToRoute('app_article_new');
    }
    
    $article = new Article();
    $article->setTitle($title);
    $article->setContent($content);
    $article->setCreatedAt(new \DateTime());
    
    $this->entityManager->persist($article);
    $this->entityManager->flush();
    
    $this->addSuccessMessage('✅ Article créé avec succès !');
    
    return $this->redirectToRoute('app_article_show', ['id' => $article->getId()]);
}

#[Route('/article/{id}/edit', name: 'app_article_edit', requirements: ['_locale' => 'fr|en', 'id' => '\d+'], methods: ['GET', 'POST'])]
public function edit(Article $article, Request $request): Response
{
    if (!$article) {

        return $this->render('bundles/error404.html.twig', [
            'error' => 'Article non trouvé',
        ]);
    }
    $form = $this->createForm(ArticleType::class, $article);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        $this->entityManager->flush();

        $this->addSuccessMessage('✅ Article modifié avec succès !');

        return $this->redirectToRoute('app_article_show', ['id' => $article->getId()]);
    }

    return $this->render('article/edit.html.twig', [
        'article' => $article,
        'form' => $form,
    
    ]);
}

#[Route('/article/{id}/delete', name: 'app_article_delete', requirements: ['_locale' => 'fr|en', 'id' => '\d+'], methods: ['POST'])]
public function delete(Article $article, Request $request): Response
{
    if (!$article) {

        return $this->render('bundles/error404.html.twig', [
            'error' => 'Article non trouvé',
        ]);
    }
    $this->entityManager->remove($article);
    $this->entityManager->flush();
    
    $this->addSuccessMessage('✅ Article supprimé avec succès !');
    
    return $this->redirectToRoute('app_article_list');
}

}