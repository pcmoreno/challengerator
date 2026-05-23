<?php
declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Auth\User;
use App\Repository\UserRepositoryInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener(event: LoginSuccessEvent::class)]
class LoginSuccessListener
{
    public function __construct(private UserRepositoryInterface $userRepository) {}

    public function __invoke(LoginSuccessEvent $event): void
    {
        $session = $event->getRequest()->getSession();
        $challengeName = $session->get('pending_challenge_join');
        if ($challengeName === null) {
            return;
        }
        $session->remove('pending_challenge_join');

        $user = $event->getAuthenticatedToken()->getUser();
        if (!$user instanceof User) {
            return;
        }

        try {
            $this->userRepository->addToChallenge($user, $challengeName);
        } catch (\Throwable) {
            // Already a member or challenge gone — don't break the login flow.
        }
    }
}
