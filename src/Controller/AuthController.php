<?php
declare(strict_types=1);

namespace App\Controller;

use App\Exception\AccountExistsException;
use App\Form\AcceptInviteType;
use App\Form\ChangePasswordType;
use App\Form\ConfirmRegistrationType;
use App\Form\SignUpType;
use App\Services\ChallengeService;
use App\Services\InviteService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;

class AuthController extends AbstractController
{
    public function __construct(
        private ChallengeService $challengeService,
        private RateLimiterFactory $selfRegistrationLimiter,
        private RateLimiterFactory $changePasswordLimiter,
        private RateLimiterFactory $adminLoginLimiter,
    ) {}

    public function loginFormPage(Request $request, string $challengeName): Response
    {
        return $this->render('default/login.html.twig', [
            'challengeName'        => $challengeName,
            'challengeDisplayName' => $this->challengeService->getDisplayNameForChallenge($challengeName),
        ]);
    }

    public function voterLogin(): Response
    {
        return $this->redirectToRoute('listChallengesMenu');
    }

    public function adminLogin(Request $request, string $challengeName): Response
    {
        if (!$this->isCsrfTokenValid('admin_login_' . $challengeName, $request->request->get('_csrf_token'))) {
            return new JsonResponse('Invalid CSRF token', Response::HTTP_FORBIDDEN);
        }

        $limiter = $this->adminLoginLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return new Response('Too many login attempts. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $adminPass = $request->request->get('admin_pass', '');

        if ($this->challengeService->verifyAdmin($challengeName, $adminPass)) {
            $session = $request->getSession();
            $session->migrate(true);
            $adminChallenges = $session->get('admin_challenges', []);
            $adminChallenges[] = $challengeName;
            $session->set('admin_challenges', array_unique($adminChallenges));
            return $this->redirectToRoute('addVoterToChallengeFormPage', ['challengeName' => $challengeName]);
        }

        return $this->redirectToRoute('loginMenu', ['challengeName' => $challengeName]);
    }

    public function addSelfRegisteredVoterForChallengePage(Request $request, string $challengeName, string $selfRegistrationCode, InviteService $inviteService): Response
    {
        $availableChallenges = $this->challengeService->listChallenges();
        if (!in_array($challengeName, $availableChallenges, true)) {
            return new JsonResponse('invalid challenge', Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->challengeService->isTheSelfRegistrationCodeCorrect($challengeName, $selfRegistrationCode)) {
            return new JsonResponse('self-registration not allowed or URL is incorrect', Response::HTTP_UNAUTHORIZED);
        }

        $newVoter = new \stdClass();
        $newVoter->email = '';
        $newVoter->username = '';
        $form = $this->createForm(SignUpType::class, $newVoter);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->selfRegistrationLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                return new JsonResponse('Too many registration attempts. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS);
            }

            try {
                $inviteService->requestSelfRegistration($newVoter->email, $newVoter->username, $challengeName);
            } catch (AccountExistsException $e) {
                $request->getSession()->set('pending_challenge_join', $e->challengeName);
                $this->addFlash('warning', 'An account with this email already exists. Please log in to join the challenge.');
                return $this->redirectToRoute('loginMenu', ['challengeName' => $e->challengeName]);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
                return $this->redirectToRoute('addSelfRegisteredVoterToChallengeFormPage', [
                    'challengeName'        => $challengeName,
                    'selfRegistrationCode' => $selfRegistrationCode,
                ]);
            }

            return $this->render('default/selfRegistrationPending.html.twig', [
                'challengeName'        => $challengeName,
                'challengeDisplayName' => $this->challengeService->getDisplayNameForChallenge($challengeName),
                'email'                => $newVoter->email,
            ]);
        }

        return $this->render('default/signup.html.twig', [
            'form'                 => $form->createView(),
            'challengeName'        => $challengeName,
            'challengeDisplayName' => $this->challengeService->getDisplayNameForChallenge($challengeName),
        ]);
    }

    public function confirmSelfRegistration(Request $request, string $token, InviteService $inviteService): Response
    {
        $verification = $inviteService->findValidSelfRegistrationVerification($token);

        if ($verification === null) {
            return $this->render('default/confirmRegistration.html.twig', [
                'invalid'       => true,
                'challengeName' => '',
                'form'          => null,
            ]);
        }

        $form = $this->createForm(ConfirmRegistrationType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $challengeName = $inviteService->confirmSelfRegistration(
                    $verification,
                    $form->get('password')->getData(),
                );
                $this->addFlash('success', 'Registration confirmed. You can now log in.');
                return $this->redirectToRoute('loginMenu', ['challengeName' => $challengeName]);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
                return $this->redirectToRoute('voter_confirm_registration', ['token' => $token]);
            }
        }

        return $this->render('default/confirmRegistration.html.twig', [
            'invalid'              => false,
            'challengeName'        => $verification->getChallengeId(),
            'challengeDisplayName' => $this->challengeService->getDisplayNameForChallenge($verification->getChallengeId()),
            'username'             => $verification->getPendingUsername(),
            'form'                 => $form->createView(),
        ]);
    }

    public function acceptInvite(Request $request, string $token, InviteService $inviteService): Response
    {
        $verification = $inviteService->findValidVerification($token);
        if ($verification === null) {
            return $this->render('default/acceptInvite.html.twig', [
                'invalid'       => true,
                'challengeName' => '',
                'form'          => null,
            ]);
        }

        // Verified-user invite: credentials already set up, just confirm joining.
        if ($verification->getUser() !== null) {
            if ($request->isMethod('POST') && $this->isCsrfTokenValid('accept_invite_' . $token, $request->request->get('_csrf_token'))) {
                try {
                    $inviteService->acceptVerifiedUserInvite($verification);
                    $this->addFlash('success', 'You have been added to the challenge. Please log in.');
                    return $this->redirectToRoute('loginMenu', ['challengeName' => $verification->getChallengeId()]);
                } catch (\DomainException $e) {
                    $this->addFlash('error', $e->getMessage());
                    return $this->redirectToRoute('voter_accept_invite', ['token' => $token]);
                }
            }
            return $this->render('default/acceptInvite.html.twig', [
                'verifiedUser'         => true,
                'invalid'              => false,
                'token'                => $token,
                'challengeName'        => $verification->getChallengeId(),
                'challengeDisplayName' => $this->challengeService->getDisplayNameForChallenge($verification->getChallengeId()),
            ]);
        }

        $form = $this->createForm(AcceptInviteType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $inviteService->acceptInvite(
                    $verification,
                    $form->get('username')->getData(),
                    $form->get('password')->getData(),
                );
                return $this->redirectToRoute('loginMenu', ['challengeName' => $verification->getChallengeId()]);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
                return $this->redirectToRoute('voter_accept_invite', ['token' => $token]);
            }
        }

        return $this->render('default/acceptInvite.html.twig', [
            'verifiedUser'         => false,
            'form'                 => $form->createView(),
            'challengeName'        => $verification->getChallengeId(),
            'challengeDisplayName' => $this->challengeService->getDisplayNameForChallenge($verification->getChallengeId()),
            'invalid'              => false,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    public function changePasswordForVoter(Request $request): Response
    {
        $limiter = $this->changePasswordLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return new Response('Too many password change attempts. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $message = '';
        $changePass = new \stdClass();
        $changePass->username = '';
        $changePass->currentPass = '';
        $changePass->newPass = '';

        $changePassForm = $this->createForm(ChangePasswordType::class, $changePass);
        $changePassForm->handleRequest($request);
        if ($changePassForm->isSubmitted() && $changePassForm->isValid()) {
            $data = $request->get('change_password');
            if ($this->challengeService->verifyVoterCredentials($data['username'], $data['currentPass'])) {
                $success = $this->challengeService->changePassForVoter($data['username'], $data['newPass']);
                if ($success) {
                    return $this->redirectToRoute('listChallengesMenu');
                }
                return new JsonResponse('something went wrong', Response::HTTP_INTERNAL_SERVER_ERROR);
            }
            $message = 'not a valid login';
        }

        return $this->render('/voter/changePassword.html.twig', [
            'changePasswordForm' => $changePassForm->createView(),
            'message' => $message,
        ]);
    }
}
