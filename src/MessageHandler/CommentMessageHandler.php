<?php

namespace App\MessageHandler;

use App\SpamChecker;
use App\ImageOptimizer;
use App\Message\CommentMessage;
use App\Repository\CommentRepository;
use App\Notification\CommentReviewNotification;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\NotificationEmail;

class CommentMessageHandler implements MessageHandlerInterface
{
    private $spamChecker;

    private $entityManager;

    private $commentRepository;

    private LoggerInterface $logger;

    private MessageBusInterface $bus;

    private WorkflowInterface $workflow;

    private NotifierInterface $notifier;

    private ImageOptimizer $imageOptimizer;

    private MailerInterface $mailer;

    private string $photoDir;

    public function __construct(
        EntityManagerInterface $entityManager,
        SpamChecker $spamChecker,
        CommentRepository $commentRepository,
        LoggerInterface $logger,
        MessageBusInterface $bus,
        WorkflowInterface $commentStateMachine,
        NotifierInterface $notifier,
        ImageOptimizer $imageOptimizer,
        MailerInterface $mailer,
        string $photoDir
    ) {
        $this->entityManager     = $entityManager;
        $this->spamChecker       = $spamChecker;
        $this->commentRepository = $commentRepository;
        $this->logger            = $logger;
        $this->bus               = $bus;
        $this->workflow          = $commentStateMachine;
        $this->notifier          = $notifier;
        $this->imageOptimizer    = $imageOptimizer;
        $this->mailer            = $mailer;
        $this->photoDir          = $photoDir;
    }

    public function __invoke(CommentMessage $message)
    {
        $comment = $this->commentRepository->find($message->getId());

        if (!$comment) {
            return;
        }

        if ($this->workflow->can($comment, 'accept')) {
            $score      = $this->spamChecker->getSpamScore($comment, $message->getContext());
            $transition = 'accept';

            if (2 === $score) {
                $transition = 'reject_spam';
            } elseif (1 === $score) {
                $transition = 'might_be_spam';
            }

            $this->workflow->apply($comment, $transition);
            $this->entityManager->flush();

            $this->bus->dispatch($message);
        } elseif ($this->workflow->can($comment, 'publish') || $this->workflow->can($comment, 'publish_ham')) {

            $notification = new CommentReviewNotification($comment, $message->getReviewUrl());
            $this->notifier->send($notification, ...$this->notifier->getAdminRecipients());

        } elseif ($this->workflow->can($comment, 'optimize')) {
            if ($comment->getPhotoFilename()) {
                $this->imageOptimizer->resize($this->photoDir.'/'.$comment->getPhotoFilename());
            }

            // there a code to send an email to users that their comments are published
            $this->mailer->send((new NotificationEmail())
                ->subject('Your comment already published')
                ->htmlTemplate('emails/comment_published.html.twig')
                ->from('bot@guestbook.symfony')
                ->to($comment->getEmail())
                ->context(['comment' => $comment])
            );

            $this->workflow->apply($comment, 'optimize');
            $this->entityManager->flush();
        } elseif ($this->logger) {
            $this->logger->debug('Dropping comment message', ['comment' => $comment->getId(), 'state' => $comment->getState()]);
        }
    }
}