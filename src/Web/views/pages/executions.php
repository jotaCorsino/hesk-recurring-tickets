<?php

declare(strict_types=1);

?>
<section class="surface-card execution-list" aria-labelledby="executions-title">
    <div class="surface-card__heading">
        <div>
            <h2 id="executions-title">Histórico de execuções</h2>
            <p class="execution-list__hint">50 execuções mais recentes · mais recentes primeiro</p>
        </div>
        <span class="count-pill"><?= $escape(count($executions)) ?> <?= count($executions) === 1 ? 'execução' : 'execuções' ?></span>
    </div>
    <?php if ($executions === []): ?>
        <div class="empty-state">
            <h3>Nenhuma execução registrada até o momento.</h3>
            <p>Os lotes processados aparecerão aqui.</p>
        </div>
    <?php else: ?>
        <ol class="execution-list__entries">
        <?php foreach ($executions as $execution): ?>
            <li class="execution-entry">
                <div class="execution-entry__overview">
                    <div class="execution-entry__identity">
                        <h3><?= $escape($execution['recurrence']) ?></h3>
                        <p>Execução #<?= $escape($execution['id']) ?> <span aria-hidden="true">·</span> Recorrência #<?= $escape($execution['recurrence_id']) ?></p>
                        <p class="execution-entry__date">Competência: <?= $escape($execution['scheduled_for']) ?></p>
                        <?php if ($execution['error'] !== null): ?><p class="execution-entry__error"><?= $escape($execution['error']['reason']) ?></p><?php endif; ?>
                    </div>
                    <div class="execution-entry__result">
                        <span class="badge badge--<?= $escape($execution['status']) ?>"><?= $escape($execution['status_label']) ?></span>
                        <div class="execution-entry__progress"><strong><?= $escape($execution['created_count']) ?> / <?= $escape($execution['expected_count']) ?></strong><span>tickets criados</span></div>
                    </div>
                </div>
                <details class="execution-details">
                    <summary>Ver detalhes</summary>
                    <div class="execution-details__content">
                        <dl class="execution-meta">
                            <div><dt>Tentativas</dt><dd><?= $escape($execution['attempt_count']) ?></dd></div>
                            <div><dt>Início</dt><dd><?= $escape($execution['started_at']) ?></dd></div>
                            <div><dt>Última tentativa</dt><dd><?= $escape($execution['last_attempt_at']) ?></dd></div>
                            <div><dt>Fim</dt><dd><?= $escape($execution['finished_at']) ?></dd></div>
                        </dl>
                        <?php if ($execution['error'] !== null): ?>
                            <div class="execution-error" role="note">
                                <p><strong>Motivo:</strong> <?= $escape($execution['error']['reason']) ?></p>
                                <p><strong>Próximo passo:</strong> <?= $escape($execution['error']['next_step']) ?></p>
                            </div>
                        <?php endif; ?>
                        <div class="execution-items__heading">
                            <h4>Itens do lote</h4>
                            <?php if ($execution['items'] !== []): ?><span><?= $escape(count($execution['items'])) ?> itens</span><?php endif; ?>
                        </div>
                        <?php if ($execution['items'] === []): ?>
                            <p class="execution-items__empty">Os itens deste lote ainda não foram materializados.</p>
                        <?php else: ?>
                            <ol class="execution-items">
                                <?php foreach ($execution['items'] as $item): ?>
                                    <li class="execution-item">
                                        <div class="execution-item__top">
                                            <strong>Item <?= $escape($item['item_index']) ?></strong>
                                            <span class="badge badge--<?= $escape($item['status']) ?>"><?= $escape($item['status_label']) ?></span>
                                        </div>
                                        <div class="execution-item__facts">
                                            <?php if ($item['hesk_ticket_id'] !== null): ?><span>Ticket HESK #<?= $escape($item['hesk_ticket_id']) ?></span><?php endif; ?>
                                            <?php if ($item['hesk_trackid'] !== null): ?><span>Tracking <?= $escape($item['hesk_trackid']) ?></span><?php endif; ?>
                                            <span><?= $escape($item['creation_attempts']) ?> <?= $item['creation_attempts'] === 1 ? 'tentativa' : 'tentativas' ?></span>
                                            <span>Última tentativa: <?= $escape($item['last_attempt_at']) ?></span>
                                        </div>
                                        <?php if ($item['error'] !== null): ?>
                                            <div class="execution-item__error">
                                                <p><strong>Motivo:</strong> <?= $escape($item['error']['reason']) ?></p>
                                                <p><strong>Próximo passo:</strong> <?= $escape($item['error']['next_step']) ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </div>
                </details>
            </li>
        <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>
