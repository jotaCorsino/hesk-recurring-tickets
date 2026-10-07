<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk\Web;

use RuntimeException;

final class AdminSession
{
    public const SESSION_NAME = 'TICKETS_RECORRENTES_ADMIN';

    private const MARKER_KEY = 'admin_ui_session_marker';
    private const MARKER_VALUE = 'tickets-recorrentes-hesk-admin-v1';
    private const TOKEN_KEY = 'admin_ui_csrf';
    private const FLASH_KEY = 'admin_ui_flash';

    public function __construct(private readonly string $cookiePath = '/')
    {
        if (!str_starts_with($this->cookiePath, '/') || !str_ends_with($this->cookiePath, '/')) {
            throw new RuntimeException('O path do cookie administrativo deve ser absoluto e terminar com barra.');
        }
    }

    public function token(): string
    {
        $this->open();

        if (!isset($_SESSION[self::TOKEN_KEY]) || !is_string($_SESSION[self::TOKEN_KEY])) {
            $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::TOKEN_KEY];
    }

    public function validToken(mixed $submitted): bool
    {
        return is_string($submitted) && hash_equals($this->token(), $submitted);
    }

    public function flash(string $message): void
    {
        $this->open();
        $_SESSION[self::FLASH_KEY] = $message;
    }

    public function consumeFlash(): string
    {
        $this->open();
        $message = $_SESSION[self::FLASH_KEY] ?? '';
        unset($_SESSION[self::FLASH_KEY]);

        return is_string($message) ? $message : '';
    }

    public function open(): void
    {
        $rejectedSessionId = null;
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (session_name() !== self::SESSION_NAME) {
                throw new RuntimeException('Uma sessão PHP diferente já está ativa.');
            }

            if ($this->hasMarker()) {
                return;
            }

            $rejectedSessionId = session_id();
            $this->abortActiveSession();
        }

        if (session_status() === PHP_SESSION_DISABLED) {
            throw new RuntimeException('Sessões PHP indisponíveis.');
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? null) === '443';
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'httponly' => true,
            'secure' => $https,
            'samesite' => 'Lax',
            'path' => $this->cookiePath,
        ]);

        if ($rejectedSessionId !== null) {
            $this->startFreshSession($rejectedSessionId);

            return;
        }

        // A sessão STAFF do HESK usa outro session_name() antes deste ponto.
        // Depois que ela é fechada, o PHP não reaplica automaticamente o
        // cookie correspondente ao novo nome de sessão na mesma requisição.
        // Se o navegador enviou um ID administrativo válido, selecione-o
        // explicitamente; strict mode ainda verifica se ele existe no storage.
        $cookieSessionId = $_COOKIE[self::SESSION_NAME] ?? null;
        if (is_string($cookieSessionId)
            && preg_match('/\A[A-Za-z0-9,-]{1,256}\z/D', $cookieSessionId) === 1) {
            session_id($cookieSessionId);
        }

        if (!@session_start()) {
            throw new RuntimeException('Não foi possível iniciar a sessão administrativa.');
        }

        if ($this->hasMarker()) {
            return;
        }

        if ($cookieSessionId !== null && hash_equals($cookieSessionId, session_id())) {
            $rejectedSessionId = session_id();
            $this->abortActiveSession();
            $this->startFreshSession($rejectedSessionId);

            return;
        }

        $_SESSION = [self::MARKER_KEY => self::MARKER_VALUE];
    }

    private function hasMarker(): bool
    {
        $marker = $_SESSION[self::MARKER_KEY] ?? null;

        return is_string($marker) && hash_equals(self::MARKER_VALUE, $marker);
    }

    private function abortActiveSession(): void
    {
        if (!session_abort()) {
            throw new RuntimeException('Não foi possível descartar uma sessão administrativa inválida.');
        }

        $_SESSION = [];
        session_id('');
    }

    private function startFreshSession(string $rejectedSessionId): void
    {
        $cookieExists = array_key_exists(self::SESSION_NAME, $_COOKIE);
        $cookieValue = $_COOKIE[self::SESSION_NAME] ?? null;
        unset($_COOKIE[self::SESSION_NAME]);

        try {
            session_id('');
            if (!@session_start()) {
                throw new RuntimeException('Não foi possível iniciar uma nova sessão administrativa.');
            }
        } finally {
            if ($cookieExists) {
                $_COOKIE[self::SESSION_NAME] = $cookieValue;
            }
        }

        if (hash_equals($rejectedSessionId, session_id())) {
            session_abort();
            $_SESSION = [];
            session_id('');

            throw new RuntimeException('Não foi possível isolar a sessão administrativa.');
        }

        $_SESSION = [self::MARKER_KEY => self::MARKER_VALUE];
    }
}
