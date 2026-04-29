<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Challenge\Car;
use App\Entity\Challenge\Voter;
use App\Form\AdminDeleteCarType;
use App\Form\AdminDeleteVoterType;
use App\Form\CarType;
use App\Form\CreateChallengeType;
use App\Form\VoterType;
use App\Services\ChallengeService;
use Exception;
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

    public function index(): Response
    {
        return $this->render('default/introMenu.html.twig');
    }

    public function listChallengesMenu(): Response
    {
        $challenges = json_decode($this->challengeService->listChallenges()->getContent(), true);
        return $this->render('default/challengeMenu.html.twig', [
            'challenges' => $challenges,
        ]);
    }

    public function createChallenge(Request $request): Response
    {
        $createChallenge = new \stdClass();
        $createChallenge->challengeName = '';
        $createChallenge->adminPass = '';
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
                return $response->getStatusCode() === 200
                    ? $this->redirectToRoute('loginMenu', ['challengeName' => $createChallenge->challengeName])
                    : new JsonResponse($response->getContent(), 400);
            } catch (Exception $exception) {
                return new JsonResponse($exception->getMessage(), 400);
            }
        }

        return $this->render('default/adminCreateChallenge.html.twig', [
            'adminCreateChallengeForm' => $createChallengeForm->createView()
        ]);
    }

    public function carsDashboardPage(Request $request, $challengeName, $token): Response
    {
        if (!$this->challengeService->isAdminTokenValid($token, $challengeName)) {
            return $this->redirectToRoute('loginMenu', ['challengeName' => $challengeName]);
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

        return $this->render('car/carDashboard.html.twig', [
            'form' => $form->createView(),
            'adminDeleteCarForm' => $adminDeleteCarForm->createView(),
            'allCarsInChallenge' => $this->challengeService->getCarsForChallenge($challengeName, false),
            'challengeName' => $challengeName,
            'token' => $token
        ]);
    }

    public function votersDashboardPage(Request $request, $challengeName, $token): Response
    {
        if (!$this->challengeService->isAdminTokenValid($token, $challengeName)) {
            return $this->redirectToRoute('loginMenu', ['challengeName' => $challengeName]);
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

        return $this->render('/voter/voterDashboard.html.twig', [
            'form' => $voterForm->createView(),
            'adminDeleteForm' => $adminDeleteVoterForm->createView(),
            'allUsersInTheChallenge' => $this->challengeService->getAllVotersForTheChallenge($challengeName),
            'challengeName' => $challengeName,
            'token' => $token,
            'selfRegistration' => $this->challengeService->isChallengeOpenToSelfRegistration($challengeName),
            'selfRegistrationCode' => $this->challengeService->getSelfRegistrationCodeForChallenge($challengeName)
        ]);
    }

    public function startChallenge(string $challengeName, string $token): Response
    {
        $response = $this->challengeService->initializeChallenge($challengeName, $token);
        if ($response->getStatusCode() === 200) {
            return $this->redirectToRoute('addVoterToChallengeFormPage', [
                'challengeName' => $challengeName,
                'token' => $token
            ]);
        }
        return $response;
    }

    public function toggleSelfRegistrationForChallenge($challengeName, $token): Response
    {
        if (!$this->challengeService->isAdminTokenValid($token, $challengeName)) {
            return new JsonResponse('Unauthorized', 403);
        }

        $code = $this->challengeService->toggleSelfRegistrationForChallenge($challengeName);
        if ($code) {
            return $this->redirectToRoute('addVoterToChallengeFormPage', [
                'challengeName' => $challengeName,
                'token' => $token,
                'selfRegistrationCode' => $code
            ]);
        }
        return new JsonResponse('ERROR', 500);
    }

    public function resetVotesForVoterOnChallenge(Request $request): Response
    {
        $challengeName = $request->get('challengeName');
        $token = $request->get('token');

        if (!$this->challengeService->isAdminTokenValid($token, $challengeName)) {
            return new JsonResponse('unauthorized', 403);
        }

        $response = $this->challengeService->resetRoundOfVoteForUserOfChallenge(
            $challengeName,
            $request->get('voterId')
        );
        if ($response->getStatusCode() === 200) {
            return $this->redirectToRoute('addVoterToChallengeFormPage', [
                'challengeName' => $challengeName,
                'token' => $token
            ]);
        }
        return $response;
    }

    public function deleteVoterFromChallenge(Request $request, $challengeName, $voterId): Response
    {
        $this->challengeService->deleteVoterFromChallenge($challengeName, $voterId);
        return $this->redirectToRoute('addVoterToChallengeFormPage', ['challengeName' => $challengeName]);
    }
}
