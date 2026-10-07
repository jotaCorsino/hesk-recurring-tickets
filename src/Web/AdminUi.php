<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk\Web;

use TicketsRecorrentesHesk\HeskCatalogProvider;
use TicketsRecorrentesHesk\HeskRecurrenceValidator;
use TicketsRecorrentesHesk\NativeHeskCatalogProvider;
use TicketsRecorrentesHesk\RecurrenceRepository;
use Throwable;

final class AdminUi
{
    private ?AuthenticatedStaff $authenticatedStaff = null;
    private ?AdminSession $activeSession = null;
    private ?HeskCatalogProvider $activeCatalogProvider = null;
    private ?AdminUrl $activeAdminUrl = null;

    private const ROUTES = [
        'recurrences' => [
            'view' => 'recurrences.php',
            'nav' => 'recurrences',
            'heading' => 'Recorrências',
            'intro' => 'Modelos de tickets programados.',
        ],
        'executions' => [
            'view' => 'executions.php',
            'nav' => 'executions',
            'heading' => 'Execuções',
            'intro' => 'Acompanhe os lotes processados.',
        ],
        'system' => [
            'view' => 'system.php',
            'nav' => 'system',
            'heading' => 'Sistema',
            'intro' => 'Estado da aplicação e do ambiente.',
        ],
    ];

    public function __construct(
        private readonly bool $enabled,
        private readonly ?AdminDataProvider $dataProvider = null,
        private readonly bool $writeEnabled = false,
        private readonly ?AdminRecurrenceWriter $writer = null,
        private readonly ?AdminSession $session = null,
        private readonly ?AdminAuthenticator $authenticator = null,
        private readonly ?HeskCatalogProvider $catalogProvider = null,
        private readonly ?AdminUrl $adminUrl = null,
    ) {
    }

    /** @param array<string, mixed> $post
     *  @return array{status: int, content_type: string, body: string, location?: string}
     */
    public function respond(string $page, string $mode = 'new', string $method = 'GET', ?string $recurrenceId = null, array $post = [], ?string $categoryId = null): array
    {
        if (!$this->enabled) {
            return $this->unavailable();
        }

        try {
            $this->urls();
        } catch (Throwable $error) {
            error_log('DEP-001A: configuração inválida da URL administrativa: ' . $error->getMessage());
            return [
                'status' => 503,
                'content_type' => 'text/plain; charset=UTF-8',
                'body' => 'Configuração administrativa indisponível.',
            ];
        }

        try {
            $this->authenticatedStaff = ($this->authenticator
                ?? new HeskStaffAuthenticator(NativeHeskStaffRuntime::fromEnvironment()))->authenticate();

            if ($this->authenticatedStaff === null) {
                return $this->authenticationRequired();
            }

            // A sessão STAFF já foi fechada pelo autenticador. A partir daqui,
            // CSRF e flash usam exclusivamente a sessão própria do painel.
            $this->adminSession()->open();
        } catch (Throwable $error) {
            error_log('UI-001F: falha na autenticação STAFF: ' . $error->getMessage());
            return $this->authenticationUnavailable();
        }

        if ($page === 'recurrence-form') {
            return $this->respondForm($mode, $method, $recurrenceId, $post, $categoryId);
        }

        if ($page === 'recurrence-state') {
            return $this->respondState($method, $recurrenceId, $post);
        }

        if ($method !== 'GET') {
            return [
                'status' => 405,
                'content_type' => 'text/plain; charset=UTF-8',
                'body' => 'A gravação ainda não está disponível.',
            ];
        }

        // Preserve old preview links while keeping recurrences as the only landing page.
        if ($page === 'overview') {
            $page = 'recurrences';
        }

        $route = self::ROUTES[$page] ?? null;

        if ($route === null) {
            return $this->unavailable();
        }

        $activeNav = $route['nav'];
        $heading = $route['heading'];
        $intro = $route['intro'];
        $view = __DIR__ . '/views/pages/' . $route['view'];
        $status = 200;
        $recurrences = [];
        $executions = [];
        $systemStatus = [];
        $errorMessage = '';
        $csrfToken = '';
        $successMessage = '';
        $dataNotice = match ($page) {
            'system' => 'Estado atual',
            'executions' => 'Somente leitura',
            default => $this->writeEnabled ? 'Edição habilitada' : 'Somente leitura',
        };

        if ($page === 'system') {
            $provider = $this->dataProvider ?? AdminDataProvider::fromEnvironment();
            $systemStatus = SystemStatusViewModel::present(
                PHP_VERSION,
                $this->enabled,
                $this->writeEnabled,
                $provider->systemStatus(),
            );
        }

        if ($page === 'executions') {
            try {
                $provider = $this->dataProvider ?? AdminDataProvider::fromEnvironment();
                $executions = ExecutionHistoryViewModel::presentAll(
                    $provider->findRecentExecutionHistory()
                );
            } catch (Throwable $error) {
                error_log('UI-001E: falha na leitura do histórico SQLite: ' . $error->getMessage());
                $status = 503;
                $page = 'error';
                $view = __DIR__ . '/views/pages/error.php';
                $heading = 'Dados indisponíveis';
                $intro = 'Não foi possível carregar as execuções.';
                $errorMessage = 'Verifique o banco da aplicação e tente novamente.';
            }
        }

        if ($page === 'recurrences') {
            try {
                $provider = $this->dataProvider ?? AdminDataProvider::fromEnvironment();
                $catalog = $this->catalogProvider()->load();
                $recurrences = array_map(static function (array $item) use ($catalog): array {
                    $presented = RecurrenceViewModel::present($item, $catalog);
                    $presented['version'] = RecurrenceRepository::versionFingerprint($item);
                    return $presented;
                }, $provider->findAll());

                if ($this->writeEnabled) {
                    $session = $this->adminSession();
                    $csrfToken = $session->token();
                    $successMessage = $session->consumeFlash();
                }
            } catch (Throwable $error) {
                error_log('UI-001G2: falha na leitura da lista ou dos catálogos: ' . $error->getMessage());
                $status = 503;
                $page = 'error';
                $view = __DIR__ . '/views/pages/error.php';
                $heading = 'Dados indisponíveis';
                $intro = 'Não foi possível carregar as recorrências.';
                $errorMessage = 'Verifique o banco da aplicação e tente novamente.';
            }
        }

        $escape = static fn (string|int $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $url = fn (string $relative): string => $this->url($relative);
        $authenticatedStaff = $this->authenticatedStaff;

        ob_start();
        require __DIR__ . '/views/layout.php';
        $html = ob_get_clean();

        return [
            'status' => $status,
            'content_type' => 'text/html; charset=UTF-8',
            'body' => $html === false ? '' : $html,
        ];
    }

    /** @param array<string, mixed> $post
     *  @return array{status: int, content_type: string, body: string, location?: string}
     */
    private function respondState(string $method, ?string $recurrenceId, array $post): array
    {
        if ($method !== 'POST') {
            return ['status' => 405, 'content_type' => 'text/plain; charset=UTF-8', 'body' => 'Método não permitido.'];
        }

        if (!$this->writeEnabled) {
            return $this->formError(403, 'Escrita indisponível', 'Esta ação está em modo somente leitura.');
        }

        if ($recurrenceId === null || !ctype_digit($recurrenceId) || (int) $recurrenceId < 1) {
            return $this->recurrenceNotFound();
        }

        try {
            $session = $this->adminSession();

            if (!$session->validToken($post['_csrf'] ?? null)) {
                return $this->formError(403, 'Solicitação recusada', 'Recarregue a página e tente novamente.');
            }

            $expectedState = $post['expected_enabled'] ?? null;
            $expectedUpdatedAt = $post['updated_at'] ?? null;
            $expectedVersion = $post['version'] ?? null;

            if (!in_array($expectedState, ['0', '1'], true)
                || !is_string($expectedUpdatedAt)
                || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $expectedUpdatedAt)
                || !is_string($expectedVersion)
                || !preg_match('/^[a-f0-9]{64}$/D', $expectedVersion)) {
                return $this->stateConflict();
            }

            $result = $this->recurrenceWriter()->transitionEnabled(
                (int) $recurrenceId, $expectedState === '1', $expectedUpdatedAt, $expectedVersion
            );

            if ($result['status'] === 'missing') {
                return $this->recurrenceNotFound();
            }

            if ($result['status'] === 'invalid') {
                return $this->formError(
                    422,
                    'Configuração HESK inválida',
                    implode(' ', $result['errors'] ?? ['Revise as referências antes de ativar.']),
                    $this->url('index.php?page=recurrence-form&mode=edit&id=' . (int) $recurrenceId),
                    'Revisar recorrência',
                );
            }

            if ($result['status'] !== 'updated') {
                return $this->stateConflict();
            }

            $session->flash($expectedState === '1'
                ? 'Recorrência pausada com sucesso.' : 'Recorrência ativada com sucesso.');
            $returnToForm = ($post['return_to'] ?? null) === 'form';

            return [
                'status' => 303,
                'content_type' => 'text/plain; charset=UTF-8',
                'body' => '',
                'location' => $returnToForm
                    ? $this->url('index.php?page=recurrence-form&mode=edit&id=' . (int) $recurrenceId)
                    : $this->url('index.php?page=recurrences'),
            ];
        } catch (Throwable $error) {
            error_log('UI-001G1: falha de infraestrutura na validação/transição: ' . $error->getMessage());
            return $this->formError(503, 'Dados indisponíveis', 'Verifique a integração com o HESK e tente novamente.');
        }
    }

    /** @return array{status: int, content_type: string, body: string} */
    private function stateConflict(): array
    {
        return $this->formError(
            409,
            'Alteração concorrente',
            'Esta recorrência mudou. Recarregue os dados antes de tentar novamente.',
            $this->url('index.php?page=recurrences'),
            'Recarregar recorrências'
        );
    }

    /** @param array<string, mixed> $post
     *  @return array{status: int, content_type: string, body: string, location?: string}
     */
    private function respondForm(string $mode, string $method, ?string $recurrenceId, array $post, ?string $requestedCategoryId): array
    {
        if (!in_array($mode, ['new', 'edit', 'view'], true)) {
            return $this->unavailable();
        }

        $formMode = $recurrenceId !== null && $mode === 'new' ? 'edit' : $mode;

        if (($recurrenceId !== null || $formMode !== 'new')
            && ($recurrenceId === null || !ctype_digit($recurrenceId) || (int) $recurrenceId < 1)) {
            return $this->recurrenceNotFound();
        }

        if ($method !== 'GET' && $method !== 'POST') {
            return ['status' => 405, 'content_type' => 'text/plain; charset=UTF-8', 'body' => 'Método não permitido.'];
        }

        $session = $this->adminSession();

        try {
            if ($method === 'POST') {
                if (!$this->writeEnabled || $formMode === 'view') {
                    return $this->formError(403, 'Escrita indisponível', 'Este formulário está em modo somente leitura.');
                }

                if (!$session->validToken($post['_csrf'] ?? null)) {
                    return $this->formError(403, 'Solicitação recusada', 'Recarregue a página e tente novamente.');
                }

                if ($recurrenceId !== null) {
                    $found = ($this->dataProvider ?? AdminDataProvider::fromEnvironment())->findById((int) $recurrenceId);

                    if ($found === null) {
                        return $this->recurrenceNotFound();
                    }

                    if ($found['enabled']) {
                        return $this->formError(403, 'Edição bloqueada', 'Pause esta recorrência antes de alterar sua configuração.');
                    }
                }

                $result = $this->recurrenceWriter()->submit(
                    $post,
                    $recurrenceId === null ? null : (int) $recurrenceId
                );

                if ($result['status'] === 'created' || $result['status'] === 'updated') {
                    $session->flash($result['status'] === 'created'
                        ? 'Recorrência criada com sucesso.' : 'Recorrência atualizada com sucesso.');
                    return [
                        'status' => 303,
                        'content_type' => 'text/plain; charset=UTF-8',
                        'body' => '',
                        'location' => $this->url('index.php?page=recurrence-form&mode=edit&id=' . $result['id']),
                    ];
                }

                if ($result['status'] === 'missing') {
                    return $this->recurrenceNotFound();
                }

                if ($result['status'] === 'active') {
                    return $this->formError(403, 'Edição bloqueada', 'Pause esta recorrência antes de alterar sua configuração.');
                }

                if ($result['status'] === 'conflict') {
                    return $this->formError(
                        409,
                        'Alteração concorrente',
                        'Esta recorrência mudou. Recarregue a página antes de editar novamente.',
                        $this->url('index.php?page=recurrence-form&mode=edit&id=' . (int) $recurrenceId),
                        'Recarregar formulário'
                    );
                }

                return $this->renderForm($formMode, $recurrenceId, $result['values'], 422, $result['errors'], $session);
            }

            $provider = $this->dataProvider ?? AdminDataProvider::fromEnvironment();
            $recurrence = null;

            if ($recurrenceId !== null) {
                $found = $provider->findById((int) $recurrenceId);

                if ($found === null) {
                    return $this->recurrenceNotFound();
                }

                $recurrence = RecurrenceViewModel::present($found, $this->catalogProvider()->load());
                $recurrence['version'] = RecurrenceRepository::versionFingerprint($found);
            } else {
                $provider->assertReady();
            }

            $categoryId = $formMode === 'view' ? null : $this->positiveCategoryId($requestedCategoryId);
            return $this->renderForm($formMode, $recurrenceId, $recurrence, 200, [], $session, $categoryId);
        } catch (Throwable $error) {
            error_log('UI-001G2: falha de infraestrutura na ficha: ' . $error->getMessage());
            return $this->formError(503, 'Dados indisponíveis', 'Verifique o banco da aplicação e a integração com o HESK.');
        }
    }

    /** @param array<string, mixed>|null $recurrence
     *  @param list<string> $formErrors
     *  @return array{status: int, content_type: string, body: string}
     */
    private function renderForm(string $formMode, ?string $recurrenceId, ?array $recurrence, int $status, array $formErrors, AdminSession $session, ?int $requestedCategoryId = null): array
    {
        $page = 'recurrence-form';
        $activeNav = 'recurrences';
        $heading = $formMode === 'new' ? 'Nova recorrência' : ($formMode === 'view' ? 'Visualizar recorrência' : 'Editar recorrência');
        $formEditable = $this->writeEnabled && $formMode !== 'view' && !($recurrence['enabled'] ?? false);
        $intro = ($recurrence['enabled'] ?? false)
            ? 'Dados em modo somente leitura.'
            : ($formEditable ? 'Preencha os dados do modelo. Ele permanecerá inativo.' : 'Dados em modo somente leitura.');
        $dataNotice = $formEditable ? 'Edição habilitada' : 'Somente leitura';
        $view = __DIR__ . '/views/pages/recurrence-form.php';
        $formAction = $this->url('index.php?page=recurrence-form'
            . ($recurrenceId === null ? '' : '&mode=edit&id=' . (int) $recurrenceId));
        $stateActionAllowed = $this->writeEnabled && $formMode === 'edit' && $recurrenceId !== null && $status === 200;
        $csrfToken = ($formEditable || $stateActionAllowed) ? $session->token() : '';
        $successMessage = $status === 200 && $this->writeEnabled ? $session->consumeFlash() : '';
        $catalogView = HeskCatalogViewModel::form(
            $this->catalogProvider()->load(),
            $recurrence,
            $requestedCategoryId,
        );
        $escape = static fn (string|int $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $url = fn (string $relative): string => $this->url($relative);
        $authenticatedStaff = $this->authenticatedStaff;
        ob_start();
        require __DIR__ . '/views/layout.php';
        $html = ob_get_clean();

        return ['status' => $status, 'content_type' => 'text/html; charset=UTF-8', 'body' => $html === false ? '' : $html];
    }

    /** @return array{status: int, content_type: string, body: string} */
    private function formError(int $status, string $heading, string $errorMessage, ?string $action = null, string $actionLabel = 'Voltar às recorrências'): array
    {
        $page = 'error';
        $activeNav = 'recurrences';
        $intro = 'Não foi possível concluir esta solicitação.';
        $dataNotice = 'Somente leitura';
        $errorActionUrl = $action ?? $this->url('index.php?page=recurrences');
        $errorActionLabel = $actionLabel;
        $view = __DIR__ . '/views/pages/error.php';
        $escape = static fn (string|int $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $url = fn (string $relative): string => $this->url($relative);
        $authenticatedStaff = $this->authenticatedStaff;
        ob_start();
        require __DIR__ . '/views/layout.php';
        $html = ob_get_clean();

        return ['status' => $status, 'content_type' => 'text/html; charset=UTF-8', 'body' => $html === false ? '' : $html];
    }

    /** @return array{status: int, content_type: string, body: string} */
    private function recurrenceNotFound(): array
    {
        $page = 'error';
        $activeNav = 'recurrences';
        $heading = 'Recorrência não encontrada';
        $intro = 'O modelo solicitado não está disponível.';
        $errorMessage = 'Volte à lista para selecionar uma recorrência existente.';
        $dataNotice = 'Somente leitura';
        $view = __DIR__ . '/views/pages/error.php';
        $escape = static fn (string|int $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $url = fn (string $relative): string => $this->url($relative);
        $authenticatedStaff = $this->authenticatedStaff;
        ob_start();
        require __DIR__ . '/views/layout.php';
        $html = ob_get_clean();

        return [
            'status' => 404,
            'content_type' => 'text/html; charset=UTF-8',
            'body' => $html === false ? '' : $html,
        ];
    }

    /** @return array{status: int, content_type: string, body: string} */
    private function unavailable(): array
    {
        return [
            'status' => 404,
            'content_type' => 'text/plain; charset=UTF-8',
            'body' => 'Página indisponível.',
        ];
    }

    private function adminSession(): AdminSession
    {
        return $this->activeSession ??= $this->session ?? new AdminSession($this->urls()->cookiePath());
    }

    private function catalogProvider(): HeskCatalogProvider
    {
        return $this->activeCatalogProvider ??= $this->catalogProvider ?? new NativeHeskCatalogProvider();
    }

    private function recurrenceWriter(): AdminRecurrenceWriter
    {
        return $this->writer ?? AdminRecurrenceWriter::fromEnvironment(
            new HeskRecurrenceValidator($this->catalogProvider()),
        );
    }

    private function positiveCategoryId(?string $categoryId): ?int
    {
        return is_string($categoryId) && ctype_digit($categoryId) && (int) $categoryId > 0
            ? (int) $categoryId
            : null;
    }

    private function urls(): AdminUrl
    {
        return $this->activeAdminUrl ??= $this->adminUrl ?? AdminUrl::fromEnvironment();
    }

    private function url(string $relative): string
    {
        return $this->urls()->to($relative);
    }

    /** @return array{status: int, content_type: string, body: string} */
    private function authenticationRequired(): array
    {
        return $this->renderAuthenticationScreen(
            401,
            'Acesso restrito à equipe',
            'Entre no HESK com sua conta da equipe para acessar o painel de recorrências.'
        );
    }

    /** @return array{status: int, content_type: string, body: string} */
    private function authenticationUnavailable(): array
    {
        return $this->renderAuthenticationScreen(
            503,
            'Acesso temporariamente indisponível',
            'Confirme sua sessão no HESK e tente novamente em instantes.'
        );
    }

    /** @return array{status: int, content_type: string, body: string} */
    private function renderAuthenticationScreen(int $status, string $heading, string $message): array
    {
        $loginUrl = trim((string) getenv('HESK_ADMIN_URL'));
        if ($loginUrl === '') {
            $loginUrl = '/admin/';
        } elseif (!filter_var($loginUrl, FILTER_VALIDATE_URL)
            || !in_array(parse_url($loginUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $loginUrl = '/admin/';
        }
        $url = fn (string $relative): string => $this->url($relative);
        $escape = static fn (string|int $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        ob_start();
        require __DIR__ . '/views/auth.php';
        $html = ob_get_clean();

        return [
            'status' => $status,
            'content_type' => 'text/html; charset=UTF-8',
            'body' => $html === false ? '' : $html,
        ];
    }
}
