<?php

declare(strict_types=1);
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
</head>
<body class="auth-page">
    <main class="auth-shell">
        <section class="auth-card" aria-labelledby="auth-heading">
            <img class="auth-card__logo" src="<?= $escape($url('assets/logo-recurring-tickets.svg')) ?>" alt="Recurring Tickets for HESK" width="243" height="129">
            <div class="auth-card__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg>
            </div>
            <h1 id="auth-heading"><?= $escape($heading) ?></h1>
            <p><?= $escape($message) ?></p>
            <a class="button button--primary auth-card__action" href="<?= $escape($loginUrl) ?>">Entrar pelo HESK</a>
            <small>A autenticação é feita no ambiente seguro do suporte.</small>
        </section>
    </main>
</body>
</html>
