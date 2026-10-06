<?php

namespace App\Logging;

use App\Entity\User;
use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Adds who was acting, without adding who they are.
 *
 * Deliberately records the user id and roles only — never the email, name or
 * any other identifying field. Those belong in the database, not in a log
 * stream that gets shipped to a collector and retained for weeks; the id is
 * enough to look a customer up when there is a real reason to.
 *
 * Guest checkout is the common case on this site, so "anonymous" is normal and
 * not a sign of anything wrong.
 */
#[AsMonologProcessor]
final class UserContextProcessor
{
    public function __construct(private readonly Security $security)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $user = $this->security->getUser();

        if ($user === null) {
            $record->extra['user_id'] = null;

            return $record;
        }

        $record->extra['user_id'] = $user instanceof User ? $user->getId() : $user->getUserIdentifier();

        // Only the elevated role is interesting: knowing an action was taken by
        // an admin is what matters when reading an audit trail.
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $record->extra['is_admin'] = true;
        }

        return $record;
    }
}
