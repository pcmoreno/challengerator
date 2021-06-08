<?php
declare(strict_types=1);

namespace App\Controller;

use App\Form\CreateChallengeType;
use App\Services\ChallengeService;
use Exception;
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
                ['username' => 'nothing'],
                ['username' => 'to'],
                ['username' => 'see'],
                ['username' => 'here'],

            ],
            'notifications' => 'no',
        ]);
    }

    public function votingDashBoardForUser($challengeName, $userId, ?string $token): Response
    {
        [$carsToVote, $carsNotVoted] = $this->challengeService->getTwoCarsToBeVotedByUser($challengeName, $userId);
        if ($carsToVote === []) {
            return new JsonResponse('Voting Complete', 200);
        }
        return $this->render(
            'default/votingCarsForUser.html.twig',
            [
                'carsToVote' => $carsToVote,
                'carsNotVoted' => $carsNotVoted,
                'user' => $userId,
                'challengeName' => $challengeName,
                'token' => $token
            ]
        );
    }

    public function voteForCarForUser(Request $request, $challengeName, $userId, $cars, $result, $token): Response
    {
        if (!$this->challengeService->isVoterTokenValid($token, $userId)) {
            return $this->redirectToRoute('loginMenu',
                [
                    'challengeName' => $challengeName,
                    'request' => $request
                ]);
        }
        try {
            [$carsToVote, $carsNotVoted] = $this->challengeService->voteOnCars($cars, $result, $challengeName, $userId);
        } catch (\Exception $exception) {
            return $this->votingDashBoardForUser($challengeName, $userId, $token);
        }

        if ($carsToVote === []) {
            return new JsonResponse('Voting Complete', 200);
        }
        return $this->redirectToRoute('voteDashboardForUser', [
            'userId' => $userId,
            'carsToVote' => $carsToVote,
            'carsNotVoted' => $carsNotVoted,
            'user' => $userId,
            'challengeName' => $challengeName,
            'token' => $token
        ]);
    }

    public function listChallengesMenu(): Response
    {
        $challenges = json_decode($this->challengeService->listChallenges()->getContent(), true);
        return $this->render(
            'default/challengeMenu.html.twig',
            [
                'challenges' => $challenges,
            ]
        );
    }

    public function createChallenge(Request $request): Response
    {
        $createChallenge = new \stdClass();
        $createChallenge->challengeName = 'name for challenge';
        $createChallenge->adminPass = 'password for the admin panel of challenge';
        $createChallenge->creationToken = '';

        $createChallengeForm = $this->createForm(CreateChallengeType::class, $createChallenge);
        $createChallengeForm->handleRequest($request);
        if ($createChallengeForm->isSubmitted() && $createChallengeForm->isValid()) {
            try {
                $response = $this->challengeService->createNewChallenge(
                    $createChallenge->challengeName,
                    $createChallenge->adminPass,
                    $createChallenge->creationToken
                );

                return $response->getStatusCode() === 200 ? $this->redirectToRoute('loginMenu', ['challengeName' => $createChallenge->challengeName]) : new JsonResponse($response->getContent(), 400);
            } catch (Exception $exception) {
                return new JsonResponse($exception->getMessage(), 400);
            }
        }

        return $this->render(
            'default/adminCreateChallenge.html.twig',
            [
                'adminCreateChallengeForm' => $createChallengeForm->createView()
            ]
        );
    }
}
