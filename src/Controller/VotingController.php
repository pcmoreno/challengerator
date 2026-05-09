<?php
declare(strict_types=1);

namespace App\Controller;

use App\Services\ChallengeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class VotingController extends AbstractController
{
    public function __construct(private ChallengeService $challengeService) {}

    public function votingDashBoardForUser(string $challengeName): Response
    {
        $userId = (string)$this->getUser()->getId();
        [$carsToVote, $carsNotVoted] = $this->challengeService->getTwoCarsToBeVotedByUser($challengeName, $userId);
        if ($carsToVote === []) {
            return $this->render('default/votingComplete.html.twig', ['challengeName' => $challengeName]);
        }
        return $this->render('default/votingCarsForUser.html.twig', [
            'carsToVote' => $carsToVote,
            'carsNotVoted' => $carsNotVoted,
            'challengeName' => $challengeName,
        ]);
    }

    public function voteForCarForUser(string $challengeName, string $cars, string $result): Response
    {
        $userId = (string)$this->getUser()->getId();
        try {
            [$carsToVote,] = $this->challengeService->voteOnCars($cars, $result, $challengeName, $userId);
        } catch (\Exception) {
            return $this->votingDashBoardForUser($challengeName);
        }

        if ($carsToVote === []) {
            return $this->render('default/votingComplete.html.twig', ['challengeName' => $challengeName]);
        }
        return $this->redirectToRoute('voteDashboardForUser', ['challengeName' => $challengeName]);
    }
}
