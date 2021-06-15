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

    public function challengeApiTest(): JsonResponse
    {
        return $this->challengeService->test();
    }

    public function listChallenges(): JsonResponse
    {
        return $this->challengeService->listChallenges();
    }

    // NOT UP TO DATE
    public function addCarToChallenge(Request $request, $challengeName): JsonResponse
    {
        $carData = json_decode($request->get('carData'), true);
//        dump($carData); die;
        return $this->challengeService->addCarFromDataArray($challengeName, $carData);
    }

    public function AddVoterToChallenge(Request $request, $challengeName): JsonResponse
    {
        $voterData = json_decode($request->get('voterData'), true);
//        dump($voterDate); die;
        return $this->challengeService->addVoterFromDataArray($challengeName, $voterData);
    }

    public function deleteVoterFromChallenge(Request $request, $challengeName, $voterId)
    {
        $this->challengeService->deleteVoterFromChallenge($challengeName, $voterId);

        return $this->redirectToRoute('addVoterToChallengeFormPage', [
            'challengeName' => $challengeName
    ]);
    }

    public function getCarsForChallenge($challengeName): JsonResponse
    {
        return $this->challengeService->getCarsForChallenge($challengeName);
    }

    public function startChallenge($challengeName): JsonResponse
    {
        return $this->challengeService->initializeChallenge($challengeName);
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
        $adminDeleteCar->carToDelete = 'car Id to delete';
        $adminDeleteCar->adminPass = 'type admin password';

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
        $voter = Voter::createForChallenge('Fill the user name here', 'give it a password', $challengeName);
        $voterForm = $this->createForm(VoterType::class, $voter);

        $voterForm->handleRequest($request);
        if ($voterForm->isSubmitted() && $voterForm->isValid()) {

            $voter = Voter::createForChallenge($voter->getName(), $voter->getAuthKey(), $challengeName);
            $this->challengeService->addVoter($challengeName, $voter, $token);
        }
        $adminDeleteVoter = new \stdClass();
        $adminDeleteVoter->voterToDelete = 'id of voter';
        $adminDeleteVoter->adminPass = 'type in admin password';

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
            'token' => $token
        ]);
    }

    public function loginFormPage(Request $request, $challengeName): Response
    {
        $login = new \stdClass();
        $login->user = 'username';
        $login->pass = 'pass';
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

    public function addSelfRegisteredVoterForChallenge($challengeName) {
        return $this->challengeService->AddVoterToChallengeFromIp($challengeName);
    }
}