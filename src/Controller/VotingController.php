<?php
declare(strict_types=1);

namespace App\Controller;

use App\Services\ChallengeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class VotingController extends AbstractController
{
    private ChallengeService $challengeService;

    public function __construct(ChallengeService $challengeService)
    {
        $this->challengeService = $challengeService;
    }

    public function votingDashBoardForUser($challengeName, $userId, ?string $token): Response
    {
        [$carsToVote, $carsNotVoted] = $this->challengeService->getTwoCarsToBeVotedByUser($challengeName, $userId);
        if ($carsToVote === []) {
            return $this->render('default/votingComplete.html.twig', ['challengeName' => $challengeName]);
        }
        return $this->render('default/votingCarsForUser.html.twig', [
            'carsToVote' => $carsToVote,
            'carsNotVoted' => $carsNotVoted,
            'user' => $userId,
            'challengeName' => $challengeName,
            'token' => $token
        ]);
    }

    public function voteForCarForUser(Request $request, $challengeName, $userId, $cars, $result, $token): Response
    {
        if (!$this->challengeService->isVoterTokenValid($token, $userId)) {
            return $this->redirectToRoute('loginMenu', ['challengeName' => $challengeName]);
        }
        try {
            [$carsToVote, $carsNotVoted] = $this->challengeService->voteOnCars($cars, $result, $challengeName, $userId);
        } catch (\Exception $exception) {
            return $this->votingDashBoardForUser($challengeName, $userId, $token);
        }

        if ($carsToVote === []) {
            return $this->render('default/votingComplete.html.twig', ['challengeName' => $challengeName]);
        }
        return $this->redirectToRoute('voteDashboardForUser', [
            'userId' => $userId,
            'challengeName' => $challengeName,
            'token' => $token
        ]);
    }
}
