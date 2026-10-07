(() => {
    const rowsPerPage = 10;

    const visiblePageNumbers = (currentPage, totalPages) => {
        const pages = new Set([1, totalPages]);
        for (
            let page = Math.max(1, currentPage - 2);
            page <= Math.min(totalPages, currentPage + 2);
            page += 1
        ) {
            pages.add(page);
        }
        return [...pages].sort((left, right) => left - right);
    };

    const placePagination = (table, pagination) => {
        const tableWrap = table.parentElement;
        if (
            tableWrap
            && tableWrap.classList.contains('table-wrap')
            && tableWrap.tagName === 'DIV'
            && tableWrap.querySelectorAll(':scope > table').length === 1
        ) {
            tableWrap.insertAdjacentElement('afterend', pagination);
            return;
        }

        table.insertAdjacentElement('afterend', pagination);
    };

    document.querySelectorAll('table:not([data-auto-pagination="off"])').forEach((table, tableIndex) => {
        const rows = Array.from(table.tBodies).flatMap((body) => Array.from(body.rows));
        if (rows.length <= rowsPerPage) {
            return;
        }

        const totalPages = Math.ceil(rows.length / rowsPerPage);
        const pagination = document.createElement('nav');
        const labelSource = table.closest('.panel')?.querySelector('h1, h2, h3')?.textContent?.trim();
        pagination.className = 'table-pagination auto-table-pagination';
        pagination.setAttribute('aria-label', (labelSource || ('Table ' + (tableIndex + 1))) + ' pages');

        let currentPage = 1;

        const pageButton = (page, label = String(page), className = '') => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = ('pagination-link ' + className).trim();
            button.dataset.page = String(page);
            button.textContent = label;
            button.setAttribute('aria-label', label === String(page) ? ('Go to page ' + page) : (label + ' page'));
            return button;
        };

        const renderPagination = () => {
            pagination.replaceChildren();

            if (currentPage > 1) {
                pagination.append(pageButton(currentPage - 1, 'Previous', 'pagination-direction'));
            }

            let lastPage = 0;
            visiblePageNumbers(currentPage, totalPages).forEach((page) => {
                if (lastPage > 0 && page > lastPage + 1) {
                    const ellipsis = document.createElement('span');
                    ellipsis.className = 'pagination-ellipsis';
                    ellipsis.setAttribute('aria-hidden', 'true');
                    ellipsis.innerHTML = '&hellip;';
                    pagination.append(ellipsis);
                }

                if (page === currentPage) {
                    const activePage = document.createElement('span');
                    activePage.className = 'pagination-link active';
                    activePage.setAttribute('aria-current', 'page');
                    activePage.textContent = String(page);
                    pagination.append(activePage);
                } else {
                    pagination.append(pageButton(page));
                }
                lastPage = page;
            });

            if (currentPage < totalPages) {
                pagination.append(pageButton(currentPage + 1, 'Next', 'pagination-direction'));
            }
        };

        const showPage = (page) => {
            currentPage = Math.min(totalPages, Math.max(1, page));
            const start = (currentPage - 1) * rowsPerPage;
            const end = start + rowsPerPage;

            rows.forEach((row, index) => {
                row.hidden = index < start || index >= end;
            });
            renderPagination();

        };

        pagination.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-page]');
            if (!button) {
                return;
            }
            showPage(Number.parseInt(button.dataset.page || '1', 10));
        });

        table.dataset.autoPaginated = 'true';
        placePagination(table, pagination);
        showPage(1);
    });
})();
