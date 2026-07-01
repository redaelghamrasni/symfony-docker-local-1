<?php

namespace App\Controller\Api;

use App\Entity\Article;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/articles', name: 'api_articles_')]
class ArticleController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SerializerInterface $serializer,
        private ValidatorInterface $validator,
    ) {}

    // GET /api/articles
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $articles = $this->em->getRepository(Article::class)->findAll();

        return $this->json([
            'data' => array_map(fn(Article $a) => $this->serialize($a), $articles),
            'total' => count($articles),
        ]);
    }

    // GET /api/articles/{id}
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(Article $article): JsonResponse
    {
        return $this->json(['data' => $this->serialize($article)]);
    }

    // POST /api/articles
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $article = new Article();
        $article->setTitle($data['title'] ?? '');
        $article->setContent($data['content'] ?? '');
        $article->setPrice((float) ($data['price'] ?? 0));
        $article->setCreatedAt(new \DateTime());

        $errors = $this->validator->validate($article);
        if (count($errors) > 0) {
            return $this->json([
                'error' => 'Validation failed',
                'details' => array_map(fn($e) => [
                    'field' => $e->getPropertyPath(),
                    'message' => $e->getMessage(),
                ], iterator_to_array($errors)),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->em->persist($article);
        $this->em->flush();

        return $this->json(
            ['data' => $this->serialize($article), 'message' => 'Article created'],
            Response::HTTP_CREATED
        );
    }

    // PUT /api/articles/{id}
    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(Article $article, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        if (isset($data['title']))   $article->setTitle($data['title']);
        if (isset($data['content'])) $article->setContent($data['content']);
        if (isset($data['price']))   $article->setPrice((float) $data['price']);

        $errors = $this->validator->validate($article);
        if (count($errors) > 0) {
            return $this->json(['error' => 'Validation failed'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->em->flush();

        return $this->json(['data' => $this->serialize($article), 'message' => 'Article updated']);
    }

    // DELETE /api/articles/{id}
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(Article $article): JsonResponse
    {
        $this->em->remove($article);
        $this->em->flush();

        return $this->json(['message' => 'Article deleted'], Response::HTTP_OK);
    }

    private function serialize(Article $article): array
    {
        return [
            'id'         => $article->getId(),
            'title'      => $article->getTitle(),
            'content'    => $article->getContent(),
            'price'      => $article->getPrice(),
            'created_at' => $article->getCreatedAt()?->format('Y-m-d H:i:s'),
        ];
    }
}