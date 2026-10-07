<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk\Web;

final class HeskStaffAuthenticator implements AdminAuthenticator
{
    public function __construct(private readonly HeskStaffRuntime $runtime)
    {
    }

    public function authenticate(): ?AuthenticatedStaff
    {
        try {
            $this->runtime->openStaffSession();
            $session = $this->runtime->sessionData();

            // Contextos incompletos (inclusive MFA intermediário) não devem
            // acionar fluxos automáticos de login do HESK.
            if (!$this->hasCompleteStaffContext($session)) {
                return null;
            }

            if (!$this->runtime->validateLoggedIn()) {
                return null;
            }

            $session = $this->runtime->sessionData();
            if (!$this->hasCompleteStaffContext($session)) {
                return null;
            }

            $id = (int) $session['id'];
            $name = trim((string) ($session['name'] ?? ''));
            $username = trim((string) ($session['user'] ?? ''));
            $displayName = $name !== '' ? $name : $username;

            if ($displayName === '') {
                return null;
            }

            return new AuthenticatedStaff(
                id: $id,
                displayName: $displayName,
                username: $name === '' && $username !== '' ? $username : null,
            );
        } finally {
            $this->runtime->closeStaffSession();
        }
    }

    /**
     * @param array<string, mixed> $session
     */
    private function hasCompleteStaffContext(array $session): bool
    {
        $id = filter_var($session['id'] ?? null, FILTER_VALIDATE_INT);
        $verification = trim((string) ($session['session_verify'] ?? ''));

        if ($id === false || $id <= 0 || $verification === '') {
            return false;
        }

        return empty($session['password_reset']);
    }
}
