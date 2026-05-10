<?php
declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;

/**
 * @method bool isGranted(mixed $attribute, mixed $subject = null)
 */
trait AdminGuardTrait
{
    private function isAdminForChallenge(Request $request, string $challengeName): bool
    {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return true;
        }
        return in_array($challengeName, $request->getSession()->get('admin_challenges', []), true);
    }
}
