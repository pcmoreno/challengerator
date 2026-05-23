<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Challenge\Car;
use App\Entity\Doctrine\DbChallenge;
use App\Entity\StorageType;
use App\Exception\ImpossibleVotedCarsAmountException;
use App\Repository\StorageResourceRepositoryInterface;
use App\Services\InviteService;
use App\Form\AdminDeleteVoterType;
use App\Form\CarType;
use App\Form\CreateChallengeType;
use App\Form\VoterType;
use App\Services\ChallengeService;
use App\Services\GoogleDriveService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ChallengeController extends AbstractController
{
    use AdminGuardTrait;

    public function __construct(
        private ChallengeService $challengeService,
        private EntityManagerInterface $em,
        private StorageResourceRepositoryInterface $storageRepository,
    ) {}

    public function index(): Response
    {
        return $this->render('default/introMenu.html.twig');
    }

    public function listChallengesMenu(): Response
    {
        $challenges = $this->challengeService->listChallengesWithDisplayNames();
        return $this->render('default/challengeMenu.html.twig', [
            'challenges' => $challenges,
        ]);
    }

    public function createChallenge(Request $request): Response
    {
        $createChallenge = new \stdClass();
        $createChallenge->displayName = '';
        $createChallenge->challengeName = '';
        $createChallenge->adminPass = '';
        $createChallenge->creationToken = '';

        $createChallengeForm = $this->createForm(CreateChallengeType::class, $createChallenge);
        $createChallengeForm->handleRequest($request);
        if ($createChallengeForm->isSubmitted() && $createChallengeForm->isValid()) {
            try {
                $this->challengeService->createNewChallenge(
                    $createChallenge->challengeName,
                    $createChallenge->displayName,
                    $createChallenge->adminPass,
                    $createChallenge->creationToken
                );
                return $this->redirectToRoute('loginMenu', ['challengeName' => $createChallenge->challengeName]);
            } catch (\Exception $exception) {
                return new JsonResponse($exception->getMessage(), Response::HTTP_BAD_REQUEST);
            }
        }

        return $this->render('default/adminCreateChallenge.html.twig', [
            'adminCreateChallengeForm' => $createChallengeForm->createView(),
        ]);
    }

    public function carsDashboardPage(Request $request, string $challengeName, GoogleDriveService $driveService): Response
    {
        if (!$this->isAdminForChallenge($request, $challengeName)) {
            return $this->redirectToRoute('loginMenu', ['challengeName' => $challengeName]);
        }

        $dbChallenge    = $this->em->getRepository(DbChallenge::class)->findOneBy(['name' => $challengeName]);
        $driveResource  = $dbChallenge !== null
            ? $this->storageRepository->findForChallenge($dbChallenge->getId(), StorageType::GoogleDrive)
            : null;
        $driveConnected = $driveResource !== null;
        $driveEmail     = $driveResource?->getCredentials()['cached_email'] ?? null;

        $car  = Car::empty();
        $form = $this->createForm(CarType::class, $car);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($driveResource === null) {
                $this->addFlash('warning', 'Connect Google Drive before adding cars.');
                return $this->redirectToRoute('drive_oauth_connect', ['challengeName' => $challengeName]);
            }

            try {
                $credentials = $driveResource->getCredentials();
                $folderId    = $credentials['drive_folder_id'];
                $onRefresh   = function (array $newCredentials) use ($driveResource): void {
                    $driveResource->setCredentials($newCredentials);
                    $this->storageRepository->save($driveResource);
                };

                /** @var \Symfony\Component\HttpFoundation\File\UploadedFile $imageA */
                $imageA = $form->get('imageA')->getData();
                /** @var \Symfony\Component\HttpFoundation\File\UploadedFile $imageB */
                $imageB = $form->get('imageB')->getData();

                $car->setImageUrlA($driveService->uploadFile(
                    ['name' => $imageA->getClientOriginalName(), 'tmp_name' => $imageA->getPathname()],
                    $folderId, $credentials, $onRefresh
                ));
                $car->setImageUrlB($driveService->uploadFile(
                    ['name' => $imageB->getClientOriginalName(), 'tmp_name' => $imageB->getPathname()],
                    $folderId, $credentials, $onRefresh
                ));

                $this->challengeService->addCar($challengeName, $car);
                return $this->redirectToRoute('addCarToChallengeFormPage', ['challengeName' => $challengeName]);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Could not add car: ' . $e->getMessage());
            }
        }

        return $this->render('car/carDashboard.html.twig', [
            'form'                 => $form->createView(),
            'allCarsInChallenge'   => $this->challengeService->getCarsForChallenge($challengeName),
            'challengeName'        => $challengeName,
            'challengeDisplayName' => $this->challengeService->getDisplayNameForChallenge($challengeName),
            'driveConnected'       => $driveConnected,
            'driveEmail'           => $driveEmail,
        ]);
    }

    public function deleteCar(Request $request, string $challengeName, string $carId): Response
    {
        if (!$this->isAdminForChallenge($request, $challengeName)) {
            return new JsonResponse('Unauthorized', Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('delete_car_' . $carId, $request->request->get('_token'))) {
            return new JsonResponse('Invalid CSRF token', Response::HTTP_FORBIDDEN);
        }

        try {
            $this->challengeService->deleteCarFromChallenge($challengeName, $carId);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Could not delete car: ' . $e->getMessage());
        }
        return $this->redirectToRoute('addCarToChallengeFormPage', ['challengeName' => $challengeName]);
    }

    public function votersDashboardPage(Request $request, string $challengeName, InviteService $inviteService): Response
    {
        if (!$this->isAdminForChallenge($request, $challengeName)) {
            return $this->redirectToRoute('loginMenu', ['challengeName' => $challengeName]);
        }

        $voterForm = $this->createForm(VoterType::class);
        $voterForm->handleRequest($request);
        if ($voterForm->isSubmitted() && $voterForm->isValid()) {
            $email = $voterForm->get('email')->getData();
            try {
                $inviteService->invite($email, $challengeName);
                $this->addFlash('success', "Invitation sent to $email.");
            } catch (\Exception $e) {
                $this->addFlash('error', 'Could not send invitation: ' . $e->getMessage());
            }
            return $this->redirectToRoute('addVoterToChallengeFormPage', ['challengeName' => $challengeName]);
        }

        $adminDeleteVoter             = new \stdClass();
        $adminDeleteVoter->voterToDelete = '';
        $adminDeleteVoter->adminPass     = '';
        $adminDeleteVoterForm = $this->createForm(AdminDeleteVoterType::class, $adminDeleteVoter);
        $adminDeleteVoterForm->handleRequest($request);
        if ($adminDeleteVoterForm->isSubmitted() && $adminDeleteVoterForm->isValid()) {
            if ($this->challengeService->verifyAdmin($challengeName, $adminDeleteVoter->adminPass)) {
                $this->challengeService->deleteVoterFromChallenge($challengeName, $adminDeleteVoter->voterToDelete);
            }
            return $this->redirectToRoute('addVoterToChallengeFormPage', ['challengeName' => $challengeName]);
        }

        $allUsersInTheChallenge = [];
        $corruptVoters = [];
        foreach ($this->challengeService->getAllVotersForTheChallenge($challengeName) as $voter) {
            try {
                $comparisons = (string) $voter->countComparisonsMadeForChallenge($challengeName);
            } catch (ImpossibleVotedCarsAmountException) {
                $comparisons = 'Exception';
                $corruptVoters[] = $voter->getName();
            }
            $allUsersInTheChallenge[] = [
                'name'        => $voter->getName(),
                'id'          => $voter->getId(),
                'ipAddress'   => $voter->getIpAddress(),
                'comparisons' => $comparisons,
                'carsLeft'    => $voter->countCarsLeftToCompareForChallenge($challengeName),
            ];
        }
        if ($corruptVoters !== []) {
            $this->addFlash('error', 'Data corruption detected — odd vote count for: ' . implode(', ', $corruptVoters));
        }

        return $this->render('/voter/voterDashboard.html.twig', [
            'form'                   => $voterForm->createView(),
            'adminDeleteForm'         => $adminDeleteVoterForm->createView(),
            'allUsersInTheChallenge'  => $allUsersInTheChallenge,
            'challengeName'           => $challengeName,
            'challengeDisplayName'    => $this->challengeService->getDisplayNameForChallenge($challengeName),
            'selfRegistration'        => $this->challengeService->isChallengeOpenToSelfRegistration($challengeName),
            'selfRegistrationCode'    => $this->challengeService->getSelfRegistrationCodeForChallenge($challengeName),
        ]);
    }

    public function startChallenge(Request $request, string $challengeName): Response
    {
        if (!$this->isAdminForChallenge($request, $challengeName)) {
            return $this->redirectToRoute('loginMenu', ['challengeName' => $challengeName]);
        }

        if (!$this->isCsrfTokenValid('start_challenge_' . $challengeName, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('addVoterToChallengeFormPage', ['challengeName' => $challengeName]);
        }

        try {
            $this->challengeService->initializeChallenge($challengeName);
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }
        return $this->redirectToRoute('addVoterToChallengeFormPage', ['challengeName' => $challengeName]);
    }

    public function toggleSelfRegistrationForChallenge(Request $request, string $challengeName): Response
    {
        if (!$this->isAdminForChallenge($request, $challengeName)) {
            return new JsonResponse('Unauthorized', Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('toggle_self_reg_' . $challengeName, $request->request->get('_token'))) {
            return new JsonResponse('Invalid CSRF token', Response::HTTP_FORBIDDEN);
        }

        $code = $this->challengeService->toggleSelfRegistrationForChallenge($challengeName);
        if ($code) {
            return $this->redirectToRoute('addVoterToChallengeFormPage', ['challengeName' => $challengeName]);
        }
        return new JsonResponse('ERROR', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    public function resetVotesForVoterOnChallenge(Request $request): Response
    {
        $challengeName = $request->get('challengeName');
        $voterId = $request->get('voterId');

        if (!$this->isAdminForChallenge($request, $challengeName)) {
            return new JsonResponse('unauthorized', Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('reset_voter_' . $voterId, $request->request->get('_token'))) {
            return new JsonResponse('Invalid CSRF token', Response::HTTP_FORBIDDEN);
        }

        try {
            $this->challengeService->resetRoundOfVoteForUserOfChallenge(
                $challengeName,
                $voterId
            );
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }
        return $this->redirectToRoute('addVoterToChallengeFormPage', ['challengeName' => $challengeName]);
    }
}
