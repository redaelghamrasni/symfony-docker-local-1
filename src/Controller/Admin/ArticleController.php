<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\ArticleImage;
use App\Form\ArticleType;
use App\Repository\ArticleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/articles', name: 'admin_articles_')]
class ArticleController extends AbstractController
{
    private const PAGE_SIZE = 12;

    private const UPLOAD_DIR = 'uploads/articles';

    public function __construct(
        private ArticleRepository $articleRepository,
        private EntityManagerInterface $entityManager,
        private SluggerInterface $slugger,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {}


    #[Route('/', name: 'index')]
    public function index(Request $request): Response
    {
        $offset = max(0, (int) $request->query->get('offset', 0));
        $total  = $this->articleRepository->countAll();
        $articles = $this->articleRepository->findPaginated(self::PAGE_SIZE, $offset);
        $nextOffset = $offset + self::PAGE_SIZE;
        $hasMore = $nextOffset < $total;

        if ($request->isXmlHttpRequest()) {
            $html = $this->renderView('admin/articles/_rows.html.twig', ['articles' => $articles]);
            return new JsonResponse([
                'html'       => $html,
                'hasMore'    => $hasMore,
                'nextOffset' => $nextOffset,
            ]);
        }

        return $this->render('admin/articles/index.html.twig', [
            'articles'   => $articles,
            'total'      => $total,
            'hasMore'    => $hasMore,
            'nextOffset' => $nextOffset,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $article = new Article();
        $article->getOrCreateTranslation('fr');
        $article->getOrCreateTranslation('en');

        $form = $this->createForm(ArticleType::class, $article);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->syncTitleFromTranslation($article);
            $this->entityManager->persist($article);
            $this->entityManager->flush();

            $this->addFlash('success', 'admin.articles.created');
            return $this->redirectToRoute('admin_articles_index');
        }

        return $this->render('admin/articles/form.html.twig', [
            'form' => $form->createView(),
            'article' => $article,
            'isEdit' => false,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Article $article, Request $request): Response
    {
        $article->getOrCreateTranslation('fr');
        $article->getOrCreateTranslation('en');

        $form = $this->createForm(ArticleType::class, $article);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->syncTitleFromTranslation($article);
            $this->handleImageUpload($form, $article);
            $this->entityManager->flush();

            $this->addFlash('success', 'admin.articles.updated');
            return $this->redirectToRoute('admin_articles_index');
        }

        return $this->render('admin/articles/form.html.twig', [
            'form' => $form->createView(),
            'article' => $article,
            'isEdit' => true,
        ]);
    }

    #[Route('/{id}/images', name: 'images_add', methods: ['POST'])]
    public function imagesAdd(Article $article, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('add_images' . $article->getId(), $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_articles_edit', ['id' => $article->getId()]);
        }

        $uploadDir = $this->getUploadDir();
        $files = $request->files->get('images') ?? [];
        if (!is_array($files)) {
            $files = [$files];
        }

        $position = $this->nextImagePosition($article);
        foreach ($files as $file) {
            if ($file === null) {
                continue;
            }
            $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $safeFilename = $this->slugger->slug($originalFilename);
            $newFilename = $safeFilename . '-' . uniqid() . '.' . $file->guessExtension();
            $file->move($uploadDir, $newFilename);

            $image = new ArticleImage();
            $image->setPath('/' . self::UPLOAD_DIR . '/' . $newFilename);
            $image->setPosition($position++);
            $article->addImage($image);
            $this->entityManager->persist($image);
        }
        $this->entityManager->flush();

        $this->addFlash('success', 'admin.articles.form.gallery_added');
        return $this->redirectToRoute('admin_articles_edit', ['id' => $article->getId()]);
    }

    #[Route('/{id}/images/{imageId}/delete', name: 'images_delete', methods: ['POST'], requirements: ['imageId' => '\d+'])]
    public function imagesDelete(Article $article, int $imageId, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delete_image' . $imageId, $request->request->get('_token'))) {
            foreach ($article->getImages() as $image) {
                if ($image->getId() === $imageId) {
                    $this->removeImageFile($image->getPath(), $this->getUploadDir());
                    $article->removeImage($image);
                    $this->entityManager->remove($image);
                    break;
                }
            }
            $this->entityManager->flush();

            $this->addFlash('success', 'admin.articles.form.gallery_deleted');
        }

        return $this->redirectToRoute('admin_articles_edit', ['id' => $article->getId()]);
    }

    #[Route('/{id}/images/{imageId}/move', name: 'images_move', methods: ['POST'], requirements: ['imageId' => '\d+'])]
    public function imagesMove(Article $article, int $imageId, Request $request): Response
    {
        if ($this->isCsrfTokenValid('move_image' . $imageId, $request->request->get('_token'))) {
            $direction = $request->request->get('direction');
            $images = $article->getImages()->toArray();

            $index = null;
            foreach ($images as $i => $image) {
                if ($image->getId() === $imageId) {
                    $index = $i;
                    break;
                }
            }

            $targetIndex = $index === null ? null : ($direction === 'up' ? $index - 1 : $index + 1);
            if ($index !== null && $targetIndex !== null && $targetIndex >= 0 && $targetIndex < count($images)) {
                $current = $images[$index];
                $target = $images[$targetIndex];
                $currentPosition = $current->getPosition();
                $current->setPosition($target->getPosition());
                $target->setPosition($currentPosition);
                $this->entityManager->flush();
            }
        }

        return $this->redirectToRoute('admin_articles_edit', ['id' => $article->getId()]);
    }

    private function nextImagePosition(Article $article): int
    {
        $max = -1;
        foreach ($article->getImages() as $image) {
            $max = max($max, $image->getPosition());
        }
        return $max + 1;
    }

    private function getUploadDir(): string
    {
        $uploadDir = $this->projectDir . '/public/' . self::UPLOAD_DIR;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        return $uploadDir;
    }

    private function handleImageUpload(FormInterface $form, Article $article): void
    {
        $uploadDir = $this->getUploadDir();

        if ($form->get('deleteImage')->getData()) {
            $this->removeImageFile($article->getImageUrl(), $uploadDir);
            $article->setImageUrl(null);
        }

        $imageFile = $form->get('imageFile')->getData();
        if ($imageFile !== null) {
            $this->removeImageFile($article->getImageUrl(), $uploadDir);
            $originalFilename = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
            $safeFilename = $this->slugger->slug($originalFilename);
            $newFilename = $safeFilename . '-' . uniqid() . '.' . $imageFile->guessExtension();
            $imageFile->move($uploadDir, $newFilename);
            $article->setImageUrl('/' . self::UPLOAD_DIR . '/' . $newFilename);
        }
    }

    private function removeImageFile(?string $imageUrl, string $uploadDir): void
    {
        if ($imageUrl === null || !str_starts_with($imageUrl, '/' . self::UPLOAD_DIR . '/')) {
            return;
        }
        $filename = basename($imageUrl);
        $path = $uploadDir . '/' . $filename;
        if (file_exists($path)) {
            unlink($path);
        }
    }

    private function syncTitleFromTranslation(Article $article): void
    {
        $fr = $article->getTranslation('fr');
        if ($fr !== null && $fr->getTitle() !== '') {
            $article->setTitle($fr->getTitle());
            $article->setContent($fr->getContent());
        }
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Article $article, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delete' . $article->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($article);
            $this->entityManager->flush();

            $this->addFlash('success', 'admin.articles.deleted');
        }

        return $this->redirectToRoute('admin_articles_index');
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Article $article): Response
    {
        return $this->render('admin/articles/show.html.twig', [
            'article' => $article,
        ]);
    }
}
