<?php

namespace App\Controller;

use App\Entity\Conference;
use App\Entity\Comment;
use App\Form\CommentFormType;
use App\Message\CommentMessage;
use App\Repository\ConferenceRepository;
use App\Repository\CommentRepository;
use App\SpamChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ConferenceController extends AbstractController
{
    private ConferenceRepository $conferenceRepository;

    private CommentRepository $commentRepository;

    private EntityManagerInterface $entityManager;

    private SpamChecker $spamChecker;

    private MessageBusInterface $bus;

    public function __construct(
        ConferenceRepository $conferenceRepository,
        CommentRepository $commentRepository,
        EntityManagerInterface $entityManager,
        SpamChecker $spamChecker,
        MessageBusInterface $bus
    ) {
        $this->conferenceRepository = $conferenceRepository;
        $this->commentRepository = $commentRepository;
        $this->entityManager = $entityManager;
        $this->spamChecker = $spamChecker;
        $this->bus = $bus;
    }

    #[Route('/', name: 'homepage')]
    public function index(): Response
    {
        return $this->render('conference/index.html.twig');
    }

    #[Route('/conference/{slug}', name: 'conference')]
    public function show(Conference $conference, Request $request, string $photoDir): Response
    {
        $offset    = max(0, $request->query->getInt('offset', 0));
        $paginator = $this->commentRepository->getCommentPaginator($conference, $offset);
        $comment   = new Comment();
        $form      = $this->createForm(CommentFormType::class, $comment);
        
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $comment->setConference($conference);
            /** @var UploadedFile $photo */
            $photo = $form->get('photo')->getData();

            if ($photo) {
                $filename = bin2hex(random_bytes(6)) . '.' . $photo->guessExtension();

                try {
                    $photo->move($photoDir, $filename);
                } catch (FileException $e) {
                    // unable to upload the photo, give up
                    throw $this->createNotFoundException($e->getMessage());
                }

                $comment->setPhotoFilename($filename);
            }

            $this->entityManager->persist($comment);
            $this->entityManager->flush();

            $context = [
                'user_ip' => $request->getClientIp(),
                'user_agent' => $request->headers->get('user-agent'),
                'referrer' => $request->headers->get('referer'),
                'permalink' => $request->getUri(),
            ];

            $this->bus->dispatch(new CommentMessage($comment->getId(), $context));

            return $this->redirectToRoute('conference', ['slug' => $conference->getSlug()]);
        }

        return $this->renderForm('conference/show.html.twig', [
            'conference' => $conference,
            'comments' => $paginator,
            'previous' => $offset - CommentRepository::PAGINATOR_PER_PAGE,
            'next' => min(count($paginator), $offset + CommentRepository::PAGINATOR_PER_PAGE),
            'comment_form' => $form,
        ]);
    }
}
