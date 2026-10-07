<?php declare(strict_types=1); ?>
<?php if ($successMessage !== ''): ?><div class="form-message form-message--success" role="status"><?= $escape($successMessage) ?></div><?php endif; ?>
<section class="surface-card model-list" aria-labelledby="models-title">
    <div class="surface-card__heading">
        <h2 id="models-title">Modelos cadastrados</h2>
        <span class="count-pill"><?= $escape(count($recurrences)) ?> <?= count($recurrences) === 1 ? 'modelo' : 'modelos' ?></span>
    </div>

    <?php if ($recurrences === []): ?>
        <div class="empty-state">
            <strong>Nenhuma recorrência cadastrada</strong>
            <p>Crie um modelo para começar.</p>
        </div>
    <?php endif; ?>

    <?php foreach ($recurrences as $recurrence): ?>
        <article class="model-row">
            <div class="model-row__main">
                <div class="model-row__title">
                    <h3><?= $escape($recurrence['name']) ?></h3>
                    <span class="badge <?= $recurrence['status'] === 'Ativa' ? 'badge--active' : 'badge--inactive' ?>"><?= $escape($recurrence['status']) ?></span>
                </div>
                <p><?= $escape($recurrence['frequency']) ?> <span aria-hidden="true">·</span> <?= $escape($recurrence['quantity']) ?> <?= $recurrence['quantity'] === 1 ? 'ticket' : 'tickets' ?> por execução</p>
                <p class="model-row__ids"><strong>Solicitante:</strong> <?= $escape($recurrence['customer_label']) ?> <span aria-hidden="true">·</span> <strong>Categoria:</strong> <?= $escape($recurrence['category_label']) ?> <span aria-hidden="true">·</span> <strong>Responsável:</strong> <?= $escape($recurrence['owner_label']) ?></p>
            </div>
            <div class="model-row__next">
                <span>Próxima execução</span>
                <strong><?= $escape($recurrence['next']) ?></strong>
            </div>
            <div class="model-row__actions">
                <a class="button button--outline" href="<?= $escape($url('index.php?page=recurrence-form&id=' . $recurrence['id'])) ?>" aria-label="Editar <?= $escape($recurrence['name']) ?>">Editar</a>
                <a class="text-link" href="<?= $escape($url('index.php?page=recurrence-form&mode=view&id=' . $recurrence['id'])) ?>" aria-label="Visualizar <?= $escape($recurrence['name']) ?>">Visualizar</a>
                <?php if ($csrfToken !== ''): ?>
                    <form class="state-action-form" action="<?= $escape($url('index.php?page=recurrence-state&id=' . $recurrence['id'])) ?>" method="post">
                        <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="expected_enabled" value="<?= $recurrence['enabled'] ? '1' : '0' ?>">
                        <input type="hidden" name="updated_at" value="<?= $escape($recurrence['updated_at']) ?>">
                        <input type="hidden" name="version" value="<?= $escape($recurrence['version']) ?>">
                        <button class="button <?= $recurrence['enabled'] ? 'button--outline' : 'button--primary' ?>" type="submit" aria-label="<?= $recurrence['enabled'] ? 'Pausar' : 'Ativar' ?> <?= $escape($recurrence['name']) ?>"><?= $recurrence['enabled'] ? 'Pausar' : 'Ativar' ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</section>
