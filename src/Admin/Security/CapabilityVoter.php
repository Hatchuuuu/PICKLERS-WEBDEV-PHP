<?php
declare(strict_types=1);

namespace Picklers\Admin\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Grants a Capability when the current, freshly loaded user's reachable roles
 * include the capability's minimum role. While an administrator is impersonating
 * someone, every admin capability is denied (the console is only usable again
 * after returning to the admin identity).
 *
 * @extends Voter<string, mixed>
 */
final class CapabilityVoter extends Voter
{
    public function __construct(private readonly RoleHierarchyInterface $roleHierarchy)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return isset(Capability::MATRIX[$attribute]);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof AdminUser) {
            return false;
        }
        if ($user->impersonating() !== null) {
            $vote?->addReason('Return to your administrator account first.');

            return false;
        }

        return in_array(
            Capability::MATRIX[$attribute],
            $this->roleHierarchy->getReachableRoleNames($user->getRoles()),
            true
        );
    }
}
