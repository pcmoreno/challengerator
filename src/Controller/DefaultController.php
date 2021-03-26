<?php
declare(strict_types=1);

namespace App\Controller;

use App\Services\ChallengeService;
use phpDocumentor\Reflection\Types\This;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DefaultController extends AbstractController
{
    private ChallengeService $challengeService;

    public function __construct(ChallengeService $challengeService)
    {
        $this->challengeService = $challengeService;
    }

    public function index(): Response
    {
        return $this->render('default/default.html.twig', [
            // this array defines the variables passed to the template,
            // where the key is the variable name and the value is the variable value
            // (Twig recommends using snake_case variable names: 'foo_bar' instead of 'fooBar')
            'users' => [
                ['username' => 'pcmoreno'],
                ['username' => 'that'],
                ['username' => 'this'],
                ['username' => 'nope'],

            ],
            'notifications' => 'no',
        ]);
    }

    public function challengeIndex($challengeName): Response
    {
        $cars = $this->challengeService->getCarsForChallenge($challengeName, false);
        return $this->render(
            'default/challengeIndex.twig', ['cars' => $cars]
        );
    }

    public function votingDashBoardForUser($challengeName, $userId): Response
    {
        [$carsToVote, $carsNotVoted] = $this->challengeService->getTwoCarsToBeVotedByUser($challengeName, $userId);
        if ($carsToVote === []) {
            return new JsonResponse('Voting Complete', 200);
        }
        return $this->render(
            'default/challengeIndex.twig',
            [
                'carsToVote' => $carsToVote,
                'carsNotVoted' => $carsNotVoted,
                'user' => $userId,
            ]
        );
    }

    public function voteForCarForUser(Request $request, $challengeName, $userId, $cars, $result): Response
    {
        try {
            [$carsToVote, $carsNotVoted] = $this->challengeService->voteOnCars($cars, $result, $challengeName, $userId);
        } catch (\Exception $exception) {
            return $this->votingDashBoardForUser($challengeName, $userId);
        }

        if ($carsToVote === []) {
            return new JsonResponse('Voting Complete', 200);
        }
        return $this->render(
            'default/challengeIndex.twig',
            [
                'carsToVote' => $carsToVote,
                'carsNotVoted' => $carsNotVoted,
                'user' => $userId,
            ]
        );
    }
}
