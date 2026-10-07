<?php declare(strict_types=1); ?>
<section class="surface-card system-panel" aria-labelledby="system-title">
    <div class="surface-card__heading">
        <h2 id="system-title">Estado nesta requisição</h2>
        <span class="count-pill">Verificação somente leitura</span>
    </div>
    <dl>
        <div><dt>PHP</dt><dd><?= $escape($systemStatus['php_version']) ?></dd></div>
        <div>
            <dt>Painel administrativo</dt>
            <dd><span class="badge badge--<?= $escape($systemStatus['panel']['tone']) ?>"><?= $escape($systemStatus['panel']['label']) ?></span></dd>
        </div>
        <div>
            <dt>Escrita Web</dt>
            <dd><span class="badge badge--<?= $escape($systemStatus['write']['tone']) ?>"><?= $escape($systemStatus['write']['label']) ?></span></dd>
        </div>
        <div>
            <dt>Banco da aplicação</dt>
            <dd><span class="badge badge--<?= $escape($systemStatus['database']['tone']) ?>"><?= $escape($systemStatus['database']['label']) ?></span></dd>
        </div>
        <div>
            <dt>Migrations</dt>
            <dd><span class="badge badge--<?= $escape($systemStatus['migrations']['tone']) ?>"><?= $escape($systemStatus['migrations']['label']) ?></span></dd>
        </div>
    </dl>
    <p class="system-panel__note">Cron, logs, lock, worker e integração HESK não são verificados nesta página. Consulte o manual operacional.</p>
</section>
