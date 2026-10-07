<?php declare(strict_types=1); ?>
<a class="back-link" href="<?= $escape($url('index.php?page=recurrences')) ?>"><span aria-hidden="true">←</span> Recorrências</a>

<nav class="form-jump" aria-label="Seções do formulário">
    <a href="#identification-title">Modelo e agenda</a>
    <a href="#ticket-title">Ticket e conteúdo</a>
    <a href="#custom-title">Complementos</a>
</nav>

<?php if ($successMessage !== ''): ?><div class="form-message form-message--success" role="status"><?= $escape($successMessage) ?></div><?php endif; ?>
<?php if ($formErrors !== []): ?>
    <div class="form-message form-message--error" role="alert">
        <strong>Revise os dados informados</strong>
        <ul><?php foreach ($formErrors as $formError): ?><li><?= $escape($formError) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>
<?php if (($recurrence['enabled'] ?? false) && $formMode !== 'view'): ?><div class="form-message form-message--info">Pause esta recorrência antes de alterar sua configuração.</div><?php endif; ?>

<?php if ($stateActionAllowed): ?>
    <div class="state-action">
        <div><span class="state-action__label">Situação</span><strong><?= $recurrence['enabled'] ? 'Ativa' : 'Inativa' ?></strong></div>
        <form class="state-action-form" action="<?= $escape($url('index.php?page=recurrence-state&id=' . $recurrenceId)) ?>" method="post">
            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="expected_enabled" value="<?= $recurrence['enabled'] ? '1' : '0' ?>">
            <input type="hidden" name="updated_at" value="<?= $escape($recurrence['updated_at']) ?>">
            <input type="hidden" name="version" value="<?= $escape($recurrence['version']) ?>">
            <input type="hidden" name="return_to" value="form">
            <button class="button <?= $recurrence['enabled'] ? 'button--outline' : 'button--primary' ?>" type="submit"><?= $recurrence['enabled'] ? 'Pausar recorrência' : 'Ativar recorrência' ?></button>
        </form>
    </div>
<?php endif; ?>

<form class="form-layout" action="<?= $escape($formAction) ?>" method="post" novalidate data-hesk-recurrence-form data-editable="<?= $formEditable ? '1' : '0' ?>">
    <?php if ($formEditable): ?>
        <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
        <?php if ($formMode === 'edit'): ?>
            <input type="hidden" name="updated_at" value="<?= $escape($recurrence['updated_at'] ?? '') ?>">
            <input type="hidden" name="version" value="<?= $escape($recurrence['version'] ?? '') ?>">
        <?php endif; ?>
    <?php endif; ?>
    <fieldset class="form-readonly" <?= $formEditable ? '' : 'disabled' ?>><legend class="visually-hidden">Dados da recorrência</legend>
    <section class="form-section" aria-labelledby="identification-title">
        <h2 id="identification-title">Identificação</h2>
        <div class="field-grid">
            <div class="field"><label for="recurrence-name">Nome da recorrência</label><input id="recurrence-name" name="name" type="text" maxlength="255" placeholder="Ex.: Preventiva trimestral" value="<?= $escape($recurrence['name'] ?? '') ?>"></div>
            <div class="field field--switch"><span class="field-label">Situação</span><label class="switch-label" for="recurrence-active"><input id="recurrence-active" type="checkbox" <?= ($recurrence['enabled'] ?? false) ? 'checked' : '' ?> disabled><span class="switch-track" aria-hidden="true"></span><span><?= ($recurrence['enabled'] ?? false) ? 'Ativa' : 'Inativa' ?></span></label></div>
        </div>
    </section>

    <section class="form-section" aria-labelledby="schedule-title">
        <h2 id="schedule-title">Agendamento</h2>
        <div class="field-grid field-grid--three">
            <div class="field"><label for="recurrence-timezone">Fuso horário</label><input id="recurrence-timezone" name="timezone" type="text" value="<?= $escape($recurrence['timezone'] ?? '') ?>"></div>
            <div class="field"><label for="recurrence-interval">Repetir a cada</label><input id="recurrence-interval" name="interval_value" type="number" min="1" step="1" value="<?= $escape($recurrence['interval_value'] ?? '') ?>"></div>
            <div class="field"><label for="recurrence-unit">Unidade</label><select id="recurrence-unit" name="interval_unit">
                <option value="" <?= $recurrence === null ? 'selected' : '' ?>>Selecione</option>
                <?php foreach (['day' => 'Dia', 'week' => 'Semana', 'month' => 'Mês', 'year' => 'Ano'] as $unit => $label): ?>
                    <option value="<?= $unit ?>" <?= ($recurrence['interval_unit'] ?? '') === $unit ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="field field--wide"><label for="recurrence-next">Próxima execução</label><input id="recurrence-next" name="next_run_at" type="datetime-local" value="<?= $escape($recurrence['next_local'] ?? '') ?>"><?php if ($status === 200 && $recurrence !== null && $recurrence['next_local'] === ''): ?><small class="field-note">Data indisponível para o fuso cadastrado.</small><?php endif; ?></div>
        </div>
    </section>

    <section class="form-section" aria-labelledby="ticket-title">
        <h2 id="ticket-title">Tickets</h2>
        <div class="field-grid field-grid--three">
            <div class="field"><label for="ticket-quantity">Quantidade por execução</label><input id="ticket-quantity" name="quantity" type="number" min="1" step="1" value="<?= $escape($recurrence['quantity'] ?? '') ?>"></div>
            <div class="field"><label for="ticket-customer">Solicitante HESK</label><select id="ticket-customer" name="customer_id" required>
                <option value="">Selecione</option>
                <?php foreach ($catalogView['customers'] as $option): ?>
                    <option value="<?= $escape($option['value']) ?>" <?= $option['selected'] ? 'selected' : '' ?> data-reference-available="<?= $option['available'] ? '1' : '0' ?>"><?= $escape($option['label']) ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="field"><label for="ticket-category">Categoria</label><select id="ticket-category" name="category_id" required data-hesk-category>
                <option value="">Selecione</option>
                <?php foreach ($catalogView['categories'] as $option): ?>
                    <option value="<?= $escape($option['value']) ?>" <?= $option['selected'] ? 'selected' : '' ?> data-reference-available="<?= $option['available'] ? '1' : '0' ?>"><?= $escape($option['label']) ?></option>
                <?php endforeach; ?>
            </select><small class="field-help">A categoria define os campos personalizados e a equipe disponível.</small></div>
            <div class="field"><label for="ticket-priority">Prioridade</label><select id="ticket-priority" name="priority_name" required>
                <option value="">Selecione</option>
                <?php foreach ($catalogView['priorities'] as $option): ?>
                    <option value="<?= $escape($option['value']) ?>" <?= $option['selected'] ? 'selected' : '' ?> data-reference-available="<?= $option['available'] ? '1' : '0' ?>"><?= $escape($option['label']) ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="field"><label for="ticket-status">Status inicial</label><select id="ticket-status" name="status_id" required>
                <option value="">Selecione</option>
                <?php foreach ($catalogView['statuses'] as $option): ?>
                    <option value="<?= $escape($option['value']) ?>" <?= $option['selected'] ? 'selected' : '' ?> data-reference-available="<?= $option['available'] ? '1' : '0' ?>"><?= $escape($option['label']) ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="field"><label for="ticket-owner">Responsável</label><select id="ticket-owner" name="owner_id" required data-hesk-staff-select>
                <option value="">Selecione a categoria primeiro</option>
                <?php foreach ($catalogView['owners'] as $option): ?>
                    <option value="<?= $escape($option['value']) ?>" <?= $option['selected'] ? 'selected' : '' ?> <?= $option['available'] || $option['selected'] ? '' : 'disabled hidden' ?> data-reference-available="<?= $option['available'] ? '1' : '0' ?>" data-category-ids="<?= $escape(implode(',', $option['category_ids'])) ?>" data-is-admin="<?= $option['is_admin'] ? '1' : '0' ?>" data-active="<?= $option['active'] ? '1' : '0' ?>"><?= $escape($option['label']) ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="field"><label for="ticket-openedby">Autor interno</label><select id="ticket-openedby" name="openedby_id" required data-hesk-staff-select>
                <option value="">Selecione a categoria primeiro</option>
                <?php foreach ($catalogView['openedby'] as $option): ?>
                    <option value="<?= $escape($option['value']) ?>" <?= $option['selected'] ? 'selected' : '' ?> <?= $option['available'] || $option['selected'] ? '' : 'disabled hidden' ?> data-reference-available="<?= $option['available'] ? '1' : '0' ?>" data-category-ids="<?= $escape(implode(',', $option['category_ids'])) ?>" data-is-admin="<?= $option['is_admin'] ? '1' : '0' ?>" data-active="<?= $option['active'] ? '1' : '0' ?>"><?= $escape($option['label']) ?></option>
                <?php endforeach; ?>
            </select></div>
        </div>
    </section>

    <section class="form-section" aria-labelledby="content-title">
        <h2 id="content-title">Conteúdo</h2>
        <div class="field-grid">
            <div class="field field--wide"><label for="ticket-subject">Assunto</label><input id="ticket-subject" name="subject" type="text" maxlength="255" value="<?= $escape($recurrence['subject'] ?? '') ?>"></div>
            <div class="field field--wide"><label for="ticket-message">Mensagem</label><textarea id="ticket-message" name="message" rows="4"><?= $escape($recurrence['message'] ?? '') ?></textarea></div>
        </div>
    </section>

    <section class="form-section" aria-labelledby="custom-title">
        <h2 id="custom-title">Campos personalizados</h2>
        <p class="form-section__hint">Os campos abaixo acompanham a categoria e a configuração atual do HESK.</p>
        <noscript><p class="form-message form-message--info">Sem JavaScript, salve após trocar a categoria para atualizar estes campos. Nenhum dado será gravado se a configuração ainda estiver incompleta.</p></noscript>
        <div class="field-grid" data-hesk-custom-fields>
            <?php foreach ($catalogView['custom_fields'] as $field): ?>
                <div class="field <?= $field['type'] === 'textarea' ? 'field--wide' : '' ?>" data-hesk-custom-field data-field-key="<?= $escape($field['key']) ?>" data-category-ids="<?= $escape(implode(',', $field['category_ids'])) ?>" <?= $field['applies'] ? '' : 'hidden' ?>>
                    <?php if (!$field['supported']): ?>
                        <span class="field-label"><?= $escape($field['name']) ?><?= $field['required'] ? ' *' : '' ?></span>
                        <div class="reference-unavailable">Tipo de campo ainda não suportado.</div>
                    <?php elseif ($field['type'] === 'textarea'): ?>
                        <label for="field-<?= $escape($field['key']) ?>"><?= $escape($field['name']) ?><?= $field['required'] ? ' *' : '' ?></label>
                        <textarea id="field-<?= $escape($field['key']) ?>" name="custom_fields[<?= $escape($field['key']) ?>]" rows="4" data-hesk-custom-control data-required="<?= $field['required'] ? '1' : '0' ?>" <?= $field['applies'] ? '' : 'disabled' ?> <?= $field['required'] && $field['applies'] ? 'required' : '' ?>><?= $escape($field['value']) ?></textarea>
                    <?php elseif ($field['type'] === 'text'): ?>
                        <label for="field-<?= $escape($field['key']) ?>"><?= $escape($field['name']) ?><?= $field['required'] ? ' *' : '' ?></label>
                        <input id="field-<?= $escape($field['key']) ?>" name="custom_fields[<?= $escape($field['key']) ?>]" type="text" value="<?= $escape($field['value']) ?>" data-hesk-custom-control data-required="<?= $field['required'] ? '1' : '0' ?>" <?= $field['applies'] ? '' : 'disabled' ?> <?= $field['required'] && $field['applies'] ? 'required' : '' ?>>
                    <?php elseif ($field['type'] === 'select'): ?>
                        <label for="field-<?= $escape($field['key']) ?>"><?= $escape($field['name']) ?><?= $field['required'] ? ' *' : '' ?></label>
                        <select id="field-<?= $escape($field['key']) ?>" name="custom_fields[<?= $escape($field['key']) ?>]" data-hesk-custom-control data-required="<?= $field['required'] ? '1' : '0' ?>" <?= $field['applies'] ? '' : 'disabled' ?> <?= $field['required'] && $field['applies'] ? 'required' : '' ?>>
                            <option value="">Selecione</option>
                            <?php foreach ($field['option_items'] as $option): ?>
                                <option value="<?= $escape($option['value']) ?>" <?= $option['selected'] ? 'selected' : '' ?> data-reference-available="<?= $option['available'] ? '1' : '0' ?>"><?= $escape($option['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <span class="field-label"><?= $escape($field['name']) ?><?= $field['required'] ? ' *' : '' ?></span>
                        <div class="choice-group" role="radiogroup" aria-label="<?= $escape($field['name']) ?>">
                            <?php foreach ($field['option_items'] as $optionIndex => $option): ?>
                                <label class="choice-option" for="field-<?= $escape($field['key']) ?>-<?= $escape($optionIndex) ?>"><input id="field-<?= $escape($field['key']) ?>-<?= $escape($optionIndex) ?>" name="custom_fields[<?= $escape($field['key']) ?>]" type="radio" value="<?= $escape($option['value']) ?>" <?= $option['selected'] ? 'checked' : '' ?> data-hesk-custom-control data-required="<?= $field['required'] ? '1' : '0' ?>" <?= $field['applies'] ? '' : 'disabled' ?> <?= $field['required'] && $field['applies'] && $optionIndex === 0 ? 'required' : '' ?>> <span><?= $escape($option['label']) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="obsolete-references" data-obsolete-references <?= $catalogView['obsolete_custom_fields'] === [] ? 'hidden' : '' ?>>
            <?php foreach ($catalogView['obsolete_custom_fields'] as $obsolete): ?>
                <div class="reference-unavailable" data-obsolete-field data-field-key="<?= $escape($obsolete['key']) ?>" data-category-ids="<?= $escape(implode(',', $obsolete['category_ids'])) ?>">
                    <strong>Referência indisponível</strong>
                    <span><?= $escape($obsolete['key']) ?>: <?= $escape($obsolete['value']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="form-section" aria-labelledby="notification-title">
        <h2 id="notification-title">Notificação</h2>
        <div class="disabled-option"><label for="notify-customer"><input id="notify-customer" name="notify_customer" type="checkbox" value="1" <?= ($recurrence['notify_customer'] ?? false) ? 'checked' : '' ?> <?= $formEditable ? '' : 'disabled' ?>> Notificar solicitante</label><p><?= $formMode === 'new' ? 'Novas recorrências começam sem notificação.' : 'Esta opção será usada quando a recorrência for ativada.' ?></p></div>
    </section>
    </fieldset>
    <div class="form-actions"><span><?= $formEditable ? 'A recorrência permanece inativa após salvar.' : 'Formulário somente leitura.' ?></span><a class="button button--secondary" href="<?= $escape($url('index.php?page=recurrences')) ?>">Voltar</a><button class="button button--primary" type="submit" <?= $formEditable ? '' : 'disabled' ?>>Salvar</button></div>
</form>
