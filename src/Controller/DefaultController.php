<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Auth\Role;
use App\Form\ChangePasswordType;
use App\Form\CreateChallengeType;
use App\Services\ChallengeService;
use Exception;
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
        return $this->render('default/introMenu.html.twig');
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

    public function changePasswordForVoter(Request $request): Response
    {
        $changePass = new \stdClass();
        $changePass->username = '';
        $changePass->currentPass = '';
        $changePass->newPass = '';

        $changePassForm = $this->createForm(ChangePasswordType::class, $changePass);
        $changePassForm->handleRequest($request);
        if($changePassForm->isSubmitted() && $changePassForm->isValid()) {
            $login = new \stdClass();
            $login->user = $request->get('change_password')['username'];
            $login->pass = $request->get('change_password')['currentPass'];
            $isValidLogin = $this->challengeService->verifyLogin($login, 'reset password')[0] !== Role::NONE;
            if ($isValidLogin) {
                $success = $this->challengeService->changePassForVoter(
                    $request->get('change_password')['username'],
                    $request->get('change_password')['newPass']
                );
                if ($success) {
                    return $this->redirectToRoute('listChallengesMenu');
                } else {
                    return new JsonResponse('something went wrong', 500);
                }
            } else {
                return $this->redirectToRoute('changePasswordMenu');
            }
        }
        return $this->render('/voter/changePassword.html.twig',
            [
                'changePasswordForm' => $changePassForm->createView()
            ]);
    }
}
