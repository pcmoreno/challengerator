<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Auth\Role;
use App\Form\ChangePasswordType;
use App\Form\CreateChallengeType;
use App\Services\ChallengeService;
use App\Services\GoogleDriveService;
use Exception;
use Google\Service\Drive;
use Google_Service_Drive_DriveFile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DefaultController extends AbstractController
{
    private ChallengeService $challengeService;
    private GoogleDriveService $googleDriveService;

    public function __construct(ChallengeService $challengeService, GoogleDriveService $googleDriveService)
    {
        $this->challengeService = $challengeService;
        $this->googleDriveService = $googleDriveService;
    }

    public function index(): Response
    {
        return $this->render('default/introMenu.html.twig');
    }

    public function votingDashBoardForUser($challengeName, $userId, ?string $token): Response
    {
        [$carsToVote, $carsNotVoted] = $this->challengeService->getTwoCarsToBeVotedByUser($challengeName, $userId);
        if ($carsToVote === []) {
            return $this->render('default/votingComplete.html.twig', ['challengeName' => $challengeName]);
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
            return $this->render('default/votingComplete.html.twig', ['challengeName' => $challengeName]);
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
        $message = '';
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
                $message = 'not a valid login';
            }
        }
        return $this->render('/voter/changePassword.html.twig',
            [
                'changePasswordForm' => $changePassForm->createView(),
                'message' => $message
            ]);
    }

    public function listFilesFromGoogleDrive($folderId)
    {
        $returnArray = [];
        foreach ($this->googleDriveService->listFilesInFolder($folderId) as $file) {
            /** @var Drive\DriveFile $file */
            $returnArray[$file->getName()] = $file->getId();
        }
        return new JsonResponse($returnArray);
    }

    public function uploadFileToMyDriveForm()
    {
        return $this->render('/default/uploadFileForm.html.twig');
    }

    public function uploadFileToDrive()
    {
        if (!empty($_FILES["fileToUpload"]["name"])) {
            $fileToUpload = $_FILES["fileToUpload"];
            $googleDriveFolderId = $_POST['folderId'];
            $fileId = $this->googleDriveService->uploadFileToGoogleDrive($fileToUpload, $googleDriveFolderId);
                return $this->render('/default/uploadFileForm.html.twig',
                [
                    'message' => $fileId,
                    'folderId' => $googleDriveFolderId
                ]);
            }
        return $this->render('/default/uploadFileForm.html.twig');
    }
}
