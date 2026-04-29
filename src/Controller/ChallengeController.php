<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Auth\Role;
use App\Entity\Challenge\Car;
use App\Entity\Challenge\Voter;
use App\Form\AdminDeleteCarType;
use App\Form\AdminDeleteVoterType;
use App\Form\CarType;
use App\Form\LoginType;
use App\Form\SignUpType;
use App\Form\VoterType;
use App\Services\ChallengeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ChallengeController extends AbstractController
{
    private ChallengeService $challengeService;

    public function __construct(ChallengeService $challengeService)
    {
        $this->challengeService = $challengeService;
    }

    public function deleteVoterFromChallenge(Request $request, $challengeName, $voterId)
    {
        $this->challengeService->deleteVoterFromChallenge($challengeName, $voterId);

        return $this->redirectToRoute('addVoterToChallengeFormPage', [
            'challengeName' => $challengeName
    ]);
    }

    public function startChallenge(string $challengeName, string $token): Response
    {
        $response =  $this->challengeService->initializeChallenge($challengeName, $token);
        if ($response->getStatusCode() === 200) {
            return $this->redirectToRoute('addVoterToChallengeFormPage', [
                'challengeName' => $challengeName,
                'token' => $token
            ]);
        } else {
            return $response;
        }
    }

    public function carsDashboardPage(Request $request, $challengeName, $token): Response
    {
        if (!$this->challengeService->isAdminTokenValid($token, $challengeName)) {
            return $this->redirectToRoute('loginMenu',
                [
                    'challengeName' => $challengeName,
                    'request' => $request
                ]);
        }
        $car = Car::empty();
        $form = $this->createForm(CarType::class, $car);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->challengeService->addCar($challengeName, $car, $token);
        }

        $adminDeleteCar = new \stdClass();
        $adminDeleteCar->carToDelete = '';
        $adminDeleteCar->adminPass = '';

        $adminDeleteCarForm = $this->createForm(AdminDeleteCarType::class, $adminDeleteCar);
        $adminDeleteCarForm->handleRequest($request);
        if ($adminDeleteCarForm->isSubmitted() && $adminDeleteCarForm->isValid()) {
            if ($this->challengeService->verifyAdmin($challengeName, $adminDeleteCar->adminPass)) {
                $this->challengeService->deleteCarFromChallenge($challengeName, $adminDeleteCar->carToDelete);
            }
        }

        $allCarsInChallenge = $this->challengeService->getCarsForChallenge($challengeName, false);
        return $this->render('car/carDashboard.html.twig', [
            'form' => $form->createView(),
            'adminDeleteCarForm' => $adminDeleteCarForm->createView(),
            'allCarsInChallenge' => $allCarsInChallenge,
            'challengeName' => $challengeName,
            'token' => $token
        ]);
    }

    public function votersDashboardPage(Request $request, $challengeName, $token): Response
    {
        if (!$this->challengeService->isAdminTokenValid($token, $challengeName)) {
            return $this->redirectToRoute('loginMenu',
                [
                    'challengeName' => $challengeName,
                    'request' => $request
                ]);
        }
        $voter = Voter::createForChallenge('', '', $challengeName);
        $voterForm = $this->createForm(VoterType::class, $voter);

        $voterForm->handleRequest($request);
        if ($voterForm->isSubmitted() && $voterForm->isValid()) {

            $voter = Voter::createForChallenge($voter->getName(), $voter->getAuthKey(), $challengeName);
            $this->challengeService->addVoter($challengeName, $voter, $token);
        }
        $adminDeleteVoter = new \stdClass();
        $adminDeleteVoter->voterToDelete = '';
        $adminDeleteVoter->adminPass = '';

        $adminDeleteVoterForm = $this->createForm(AdminDeleteVoterType::class, $adminDeleteVoter);
        $adminDeleteVoterForm->handleRequest($request);
        if ($adminDeleteVoterForm->isSubmitted() && $adminDeleteVoterForm->isValid()) {
            if ($this->challengeService->verifyAdmin($challengeName, $adminDeleteVoter->adminPass)) {
                $this->challengeService->deleteVoterFromChallenge($challengeName, $adminDeleteVoter->voterToDelete);
            }
        }

        $allUsersInTheChallenge = $this->challengeService->getAllVotersForTheChallenge($challengeName);
        return $this->render('/voter/voterDashboard.html.twig', [
            'form' => $voterForm->createView(),
            'adminDeleteForm' => $adminDeleteVoterForm->createView(),
            'allUsersInTheChallenge' => $allUsersInTheChallenge,
            'challengeName' => $challengeName,
            'token' => $token,
            'selfRegistration' => $this->challengeService->isChallengeOpenToSelfRegistration($challengeName),
            'selfRegistrationCode' => $this->challengeService->getSelfRegistrationCodeForChallenge($challengeName)
        ]);
    }

    public function loginFormPage(Request $request, $challengeName): Response
    {
        $login = new \stdClass();
        $login->user = '';
        $login->pass = '';
        $form = $this->createForm(LoginType::class, $login);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            [$role, $userId, $token] = $this->challengeService->verifyLogin($login, $challengeName);

            switch ($role) {
                case Role::ADMIN:
                    return $this->redirectToRoute('addVoterToChallengeFormPage', [
                        'challengeName' => $challengeName,
                        'token' => $token
                    ]);
                case Role::VOTER:
                    return $this->redirectToRoute('voteDashboardForUser',[
                        'challengeName' => $challengeName,
                        'userId' => $userId,
                        'token' => $token
                    ]);
                default:
                    return $this->redirectToRoute('listChallengesMenu');
            }
        }
        return $this->render('default/login.html.twig', [
            'form' => $form->createView(),
            'challengeName' => $challengeName
    ]);
    }

    public function resetVotesForVoterOnChallenge(Request $request): Response
    {
        $challengeName = $request->get('challengeName');
        $token = $request->get('token');

        $isAdminTokenValid = $this->challengeService->isAdminTokenValid(
            $token,
            $challengeName
        );

        if ($isAdminTokenValid) {
            $response = $this->challengeService->resetRoundOfVoteForUserOfChallenge(
                $challengeName,
                $request->get('voterId')
            );
            if ($response->getStatusCode() === 200) {
                return $this->redirectToRoute('addVoterToChallengeFormPage', [
                    'challengeName' => $challengeName,
                    'token' => $token
                ]);
            } else {
                return $response;
            }
        }
        return new JsonResponse('unauthorized', 403);
    }

    public function addSelfRegisteredVoterForChallengePage(Request $request, $challengeName, $selfRegistrationCode) {
        $availableChallenges = json_decode($this->challengeService->listChallenges()->getContent(), true);
        if (!in_array($challengeName, $availableChallenges)) {
            return new JsonResponse('invalid challenge', 401);
        }

        if (!$this->challengeService->isTheSelfRegistrationCodeCorrect($challengeName, $selfRegistrationCode)) {
            return new JsonResponse('self-registration not allowed or URL is incorrect', 401);
        }

        $newVoter = new \stdClass();
        $newVoter->username = '';
        $newVoter->password = '';
        $form = $this->createForm(SignUpType::class, $newVoter);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $success = $this->challengeService->AddVoterToChallengeFromIp(
                $challengeName,
                $newVoter->username,
                $newVoter->password
            );
            return $success ?
                $this->redirectToRoute('loginMenu', ['challengeName' => $challengeName]) :
                new JsonResponse('already signed up or self-registration not allowed', 401);
        }

        return $this->render('default/signup.html.twig', [
            'form' => $form->createView(),
            'challengeName' => $challengeName
        ]);
    }

    public function toggleSelfRegistrationForChallenge($challengeName, $token): Response
    {
        $isAdminTokenValid = $this->challengeService->isAdminTokenValid(
            $token,
            $challengeName
        );

        if ($isAdminTokenValid) {
            $success = $this->challengeService->toggleSelfRegistrationForChallenge(
                $challengeName,
            );
            if ($success) {
                return $this->redirectToRoute('addVoterToChallengeFormPage', [
                    'challengeName' => $challengeName,
                    'token' => $token,
                    'selfRegistrationCode' => $success
                ]);
            } else {
                return new JsonResponse('ERROR', 500);
            }
        } else {
            return new JsonResponse('Unauthorized', 403);
        }
    }
}
