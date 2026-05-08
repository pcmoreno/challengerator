<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Auth\Role;
use App\Form\ChangePasswordType;
use App\Form\LoginType;
use App\Form\SignUpType;
use App\Services\ChallengeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends AbstractController
{
    private ChallengeService $challengeService;

    public function __construct(ChallengeService $challengeService)
    {
        $this->challengeService = $challengeService;
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
                    return $this->redirectToRoute('voteDashboardForUser', [
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

    public function addSelfRegisteredVoterForChallengePage(Request $request, $challengeName, $selfRegistrationCode): Response
    {
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
                $newVoter->password,
                $request->getClientIp() ?? ''
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

    public function changePasswordForVoter(Request $request): Response
    {
        $message = '';
        $changePass = new \stdClass();
        $changePass->username = '';
        $changePass->currentPass = '';
        $changePass->newPass = '';

        $changePassForm = $this->createForm(ChangePasswordType::class, $changePass);
        $changePassForm->handleRequest($request);
        if ($changePassForm->isSubmitted() && $changePassForm->isValid()) {
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

        return $this->render('/voter/changePassword.html.twig', [
            'changePasswordForm' => $changePassForm->createView(),
            'message' => $message
        ]);
    }
}
