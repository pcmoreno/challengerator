<?php
declare(strict_types=1);

namespace App\Controller;

use App\Services\ChallengeService;
use phpDocumentor\Reflection\Types\This;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

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

    public function createChallenge($challengeName, $owner): JsonResponse
    {
        return $this->challengeService->createNewChallenge($challengeName, $owner);
    }

    public function addCarToChallenge(Request $request, $challengeName): JsonResponse
    {
        $carData = json_decode($request->get('carData'), true);
//        dump($carData); die;
        return $this->challengeService->addCar($challengeName, $carData);
    }

    public function AddVoterToChallenge(Request $request, $challengeName): JsonResponse
    {
        $voterData = json_decode($request->get('voterData'), true);
//        dump($voterDate); die;
        return $this->challengeService->addVoter($challengeName, $voterData);
    }

    public function getCarsForChallenge($challengeName): JsonResponse
    {
        return $this->challengeService->getCarsForChallenge($challengeName);
    }

    public function startChallenge($challengeName): JsonResponse
    {
        return $this->challengeService->initializeChallenge($challengeName);
    }

}