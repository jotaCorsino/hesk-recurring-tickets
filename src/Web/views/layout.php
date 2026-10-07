<?php

declare(strict_types=1);

$navigation = [
    ['key' => 'recurrences', 'label' => 'Recorrências'],
    ['key' => 'executions', 'label' => 'Execuções'],
    ['key' => 'system', 'label' => 'Sistema'],
];
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#071a52">
    <title><?= $escape($heading) ?> · Tickets Recorrentes</title>
    <link rel="icon" type="image/svg+xml" href="<?= $escape($url('assets/favicon-recurring-tickets.svg')) ?>">
    <link rel="stylesheet" href="<?= $escape($url('assets/css/admin.css')) ?>">
    <?php if ($page === 'recurrence-form'): ?><script defer src="<?= $escape($url('assets/js/recurrence-form.js')) ?>"></script><?php endif; ?>
</head>
<body>
    <a class="skip-link" href="#content">Ir para o conteúdo</a>
    <div class="app-shell">
        <aside class="sidebar">
            <div class="sidebar__brand">
                <span class="sidebar__logo"><img src="<?= $escape($url('assets/logo-recurring-tickets.svg')) ?>" alt="Recurring Tickets for HESK" width="243" height="129"></span>
            </div>
            <nav class="sidebar__nav" aria-label="Navegação principal">
                <span class="sidebar__heading">Painel</span>
                <?php foreach ($navigation as $item): ?>
                    <a class="nav-link<?= $activeNav === $item['key'] ? ' nav-link--active' : '' ?>"
                       href="<?= $escape($url('index.php?page=' . $item['key'])) ?>"
                       <?= $activeNav === $item['key'] ? 'aria-current="page"' : '' ?>>
                        <?php if ($item['key'] === 'recurrences'): ?>
                            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 6h16M7 12h10M9 18h6"></path></svg>
                        <?php elseif ($item['key'] === 'executions'): ?>
                            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 3a9 9 0 1 0 9 9M12 7v5l3 2M17 3h4v4"></path></svg>
                        <?php else: ?>
                            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10M8 5v4m8 1v4m-6 1v4"></path></svg>
                        <?php endif; ?>
                        <span><?= $escape($item['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <?php if ($authenticatedStaff instanceof \TicketsRecorrentesHesk\Web\AuthenticatedStaff): ?>
                <div class="sidebar__staff" aria-label="Sessão autenticada">
                    <span class="sidebar__staff-avatar" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3"></circle><path d="M6 19c.7-3.1 2.7-5 6-5s5.3 1.9 6 5"></path></svg>
                    </span>
                    <span>
                        <strong><?= $escape($authenticatedStaff->displayName) ?></strong>
                        <small>Equipe HESK</small>
                    </span>
                </div>
            <?php endif; ?>
            <div class="sidebar__footer">
                <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 11v5m0-8h.01"></path></svg>
                <span><?= $escape($dataNotice) ?></span>
            </div>
        </aside>

        <div class="workspace">
            <main class="content" id="content">
                <div class="page-heading">
                    <div>
                        <h1><?= $escape($heading) ?></h1>
                        <p><?= $escape($intro) ?></p>
                        <span class="preview-note"><?= $escape($dataNotice) ?></span>
                    </div>
                    <?php if ($page === 'recurrences'): ?>
                        <a class="button button--primary page-heading__action" href="<?= $escape($url('index.php?page=recurrence-form')) ?>">Nova recorrência</a>
                    <?php endif; ?>
                </div>
                <?php require $view; ?>
            </main>
        </div>
    </div>
</body>
</html>
