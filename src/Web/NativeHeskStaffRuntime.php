<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk\Web;

use RuntimeException;

final class NativeHeskStaffRuntime implements HeskStaffRuntime
{
    private const SUPPORTED_HESK_VERSION = '3.7.12';

    public function __construct(private readonly string $heskPath)
    {
    }

    public static function fromEnvironment(): self
    {
        $configuredPath = trim((string) getenv('HESK_PATH'));

        if ($configuredPath === '') {
            throw new RuntimeException('Defina HESK_PATH com o diretório da instalação do HESK.');
        }

        return new self($configuredPath);
    }

    public function openStaffSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Uma sessão PHP já estava ativa antes do bootstrap STAFF.');
        }

        $resolvedPath = realpath($this->heskPath);
        if ($resolvedPath === false || !is_dir($resolvedPath)) {
            throw new RuntimeException('A instalação do HESK não está disponível.');
        }

        $heskPath = rtrim(str_replace('\\', '/', $resolvedPath), '/') . '/';
        $settingsFile = $heskPath . 'hesk_settings.inc.php';
        $commonFile = $heskPath . 'inc/common.inc.php';
        $adminFunctionsFile = $heskPath . 'inc/admin_functions.inc.php';

        foreach ([$settingsFile, $commonFile, $adminFunctionsFile] as $requiredFile) {
            if (!is_file($requiredFile) || !is_readable($requiredFile)) {
                throw new RuntimeException('O bootstrap STAFF do HESK não está disponível.');
            }
        }

        if (defined('HESK_PATH') && HESK_PATH !== $heskPath) {
            throw new RuntimeException('HESK_PATH já foi definido com outro caminho.');
        }

        if (!defined('IN_SCRIPT')) {
            define('IN_SCRIPT', 1);
        }

        if (!defined('HESK_PATH')) {
            define('HESK_PATH', $heskPath);
        }

        global $hesk_settings, $hesklang, $hesk_db_link;

        require $settingsFile;

        $version = trim((string) ($hesk_settings['hesk_version'] ?? ''));
        if ($version !== self::SUPPORTED_HESK_VERSION) {
            throw new RuntimeException('A versão instalada do HESK não é compatível com o painel.');
        }

        require $commonFile;
        require $adminFunctionsFile;

        if (!function_exists('hesk_load_database_functions')) {
            throw new RuntimeException('O bootstrap STAFF do HESK está incompleto.');
        }

        hesk_load_database_functions();

        // hesk_dbConnect() é definida pelo driver carregado acima, não pelo
        // common.inc.php. A ordem desta verificação deve seguir o bootstrap
        // nativo do HESK 3.7.12.
        foreach (['hesk_dbConnect', 'hesk_session_start', 'hesk_isLoggedIn'] as $function) {
            if (!function_exists($function)) {
                throw new RuntimeException('O bootstrap STAFF do HESK está incompleto.');
            }
        }

        hesk_session_start('STAFF');

        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('A sessão STAFF do HESK não foi iniciada.');
        }
    }

    public function sessionData(): array
    {
        return is_array($_SESSION ?? null) ? $_SESSION : [];
    }

    public function validateLoggedIn(): bool
    {
        hesk_dbConnect();

        return hesk_isLoggedIn() !== false;
    }

    public function closeStaffSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // O cookie e os dados persistidos do HESK permanecem intactos. Apenas
        // o contexto em memória é removido antes da sessão própria do painel.
        $_SESSION = [];
        if (session_status() === PHP_SESSION_NONE) {
            session_id('');
        }
    }
}
