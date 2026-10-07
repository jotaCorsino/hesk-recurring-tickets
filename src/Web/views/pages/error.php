<?php declare(strict_types=1); ?>
<section class="surface-card state-panel" aria-label="Detalhes do erro">
    <div class="state-panel__body">
        <p><?= $escape($errorMessage) ?></p>
        <a class="button button--outline" href="<?= $escape($errorActionUrl ?? $url('index.php?page=recurrences')) ?>"><?= $escape($errorActionLabel ?? 'Voltar às recorrências') ?></a>
    </div>
</section>
