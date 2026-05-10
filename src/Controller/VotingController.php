<?php
declare(strict_types=1);

namespace App\Controller;

use App\Services\ChallengeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\Turbo\TurboBundle;

class VotingController extends AbstractController
{
    public function __construct(private ChallengeService $challengeService) {}

    public function votingDashBoardForUser(string $challengeName): Response
    {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('index');
        }

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

    public function voteForCarForUser(Request $request, string $challengeName): Response
    {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('index');
        }

        $userId = (string)$this->getUser()->getId();
        $cars   = $request->request->getString('cars');
        $result = $request->request->getString('result');

        try {
            [$carsToVote, $carsNotVoted] = $this->challengeService->voteOnCars($cars, $result, $challengeName, $userId);
        } catch (\InvalidArgumentException|\DomainException) {
            return $this->redirectToRoute('voteDashboardForUser', ['challengeName' => $challengeName]);
        }

        if ($carsToVote === []) {
            return $this->redirectToRoute('voteDashboardForUser', ['challengeName' => $challengeName]);
        }

        if ($request->getPreferredFormat() === TurboBundle::STREAM_FORMAT) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
            return $this->render('voting/_stream.html.twig', [
                'carsToVote'    => $carsToVote,
                'carsNotVoted'  => $carsNotVoted,
                'challengeName' => $challengeName,
            ]);
        }

        return $this->redirectToRoute('voteDashboardForUser', ['challengeName' => $challengeName]);
    }
}
