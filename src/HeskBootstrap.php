<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk;

use RuntimeException;

final class HeskBootstrap
{
    private const SUPPORTED_VERSION = '3.7.12';

    public function __construct(
        private readonly string $heskPath,
        private readonly ?string $serverName = null,
    ) {
    }

    /**
     * @return array{path: string, version: string, server_name: string}
     */
    public function boot(): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Esta POC só pode ser executada pelo PHP CLI.');
        }

        $resolvedPath = realpath($this->heskPath);

        if ($resolvedPath === false || !is_dir($resolvedPath)) {
            throw new RuntimeException("Diretório HESK inválido ou inexistente: {$this->heskPath}");
        }

        $resolvedPath = rtrim(str_replace('\\', '/', $resolvedPath), '/') . '/';
        $requiredFiles = [
            'hesk_settings.inc.php',
            'inc/common.inc.php',
            'inc/email_functions.inc.php',
            'inc/posting_functions.inc.php',
        ];

        foreach ($requiredFiles as $requiredFile) {
            if (!is_file($resolvedPath . $requiredFile)) {
                throw new RuntimeException("Arquivo obrigatório do HESK não encontrado: {$requiredFile}");
            }
        }

        if (defined('IN_SCRIPT') || defined('HESK_PATH')) {
            throw new RuntimeException('O ambiente HESK já foi inicializado neste processo.');
        }

        // common.inc.php verifica o contexto HTTPS antes de disponibilizar as funções.
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $serverName = $this->serverName ?? trim((string) getenv('HESK_SERVER_NAME'));
        if ($serverName === '' || !preg_match('/^[a-z0-9.-]+$/i', $serverName)) {
            throw new RuntimeException('Defina HESK_SERVER_NAME com o host público do HESK.');
        }
        $_SERVER['SERVER_NAME'] = $serverName;
        $_SERVER['REQUEST_URI'] = '/';

        define('IN_SCRIPT', 1);
        define('HESK_PATH', $resolvedPath);
        define('NO_HTTP_HEADER', 1);

        // Os includes do HESK esperam estas variáveis no escopo global.
        global $hesk_settings, $hesklang, $hesk_db_link;

        require HESK_PATH . 'hesk_settings.inc.php';

        $version = (string) ($hesk_settings['hesk_version'] ?? 'desconhecida');

        if ($version !== self::SUPPORTED_VERSION) {
            throw new RuntimeException(
                sprintf('Versão HESK incompatível: encontrada %s; esperada %s.', $version, self::SUPPORTED_VERSION)
            );
        }

        require HESK_PATH . 'inc/common.inc.php';

        if (!function_exists('hesk_load_database_functions')) {
            throw new RuntimeException('HESK não disponibilizou hesk_load_database_functions().');
        }

        \hesk_load_database_functions();

        if (!function_exists('hesk_dbConnect')) {
            throw new RuntimeException('HESK não disponibilizou hesk_dbConnect().');
        }

        \hesk_dbConnect();

        // Este include carrega as estruturas nativas de clientes, campos,
        // prioridades e status utilizadas por hesk_newTicket(). Nenhuma rotina
        // de notificação é chamada pela POC.
        require HESK_PATH . 'inc/email_functions.inc.php';
        require HESK_PATH . 'inc/posting_functions.inc.php';

        foreach (
            [
                'hesk_newTicket',
                'hesk_createID',
                'hesk_get_selectable_customer_by_id',
                'hesk_is_valid_priority_id',
                'hesk_is_custom_field_in_category',
                'hesk_dbEscape',
                'hesk_dbQuery',
                'hesk_dbFetchAssoc',
                'hesk_dbNumRows',
            ] as $requiredFunction
        ) {
            if (!function_exists($requiredFunction)) {
                throw new RuntimeException("Função obrigatória do HESK indisponível: {$requiredFunction}()");
            }
        }

        return [
            'path' => HESK_PATH,
            'version' => $version,
            'server_name' => $serverName,
        ];
    }
}
