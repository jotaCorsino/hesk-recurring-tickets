(() => {
    'use strict';

    const form = document.querySelector('[data-hesk-recurrence-form]');
    const category = form?.querySelector('[data-hesk-category]');

    if (!form || !category || form.dataset.editable !== '1') {
        return;
    }

    const categoryIds = (element) => (element.dataset.categoryIds || '')
        .split(',')
        .filter(Boolean);

    const appliesTo = (element, categoryId) => categoryId !== ''
        && categoryIds(element).includes(categoryId);

    const refresh = () => {
        const selectedCategory = category.value;

        form.querySelectorAll('[data-hesk-staff-select]').forEach((select) => {
            select.querySelectorAll('option[value]').forEach((option) => {
                if (option.value === '') {
                    return;
                }

                const eligible = option.dataset.active === '1'
                    && (option.dataset.isAdmin === '1' || appliesTo(option, selectedCategory));
                const keepSelectedReference = option.selected && !eligible;
                const baseLabel = option.textContent.replace(/ — Referência indisponível$/, '');

                option.hidden = !eligible && !keepSelectedReference;
                option.disabled = !eligible && !keepSelectedReference;
                option.textContent = keepSelectedReference
                    ? `${baseLabel} — Referência indisponível`
                    : baseLabel;
                option.dataset.referenceAvailable = eligible ? '1' : '0';
            });
        });

        form.querySelectorAll('[data-hesk-custom-field]').forEach((field) => {
            const applies = appliesTo(field, selectedCategory);
            field.hidden = !applies;
            field.querySelectorAll('[data-hesk-custom-control]').forEach((control) => {
                control.disabled = !applies;
                control.required = applies && control.dataset.required === '1';
            });
        });

        let visibleObsolete = 0;
        form.querySelectorAll('[data-obsolete-field]').forEach((field) => {
            const restored = categoryIds(field).length > 0
                && appliesTo(field, selectedCategory);
            field.hidden = restored;
            if (!restored) {
                visibleObsolete++;
            }
        });

        const obsoleteContainer = form.querySelector('[data-obsolete-references]');
        if (obsoleteContainer) {
            obsoleteContainer.hidden = visibleObsolete === 0;
        }
    };

    category.addEventListener('change', refresh);
    refresh();
})();
