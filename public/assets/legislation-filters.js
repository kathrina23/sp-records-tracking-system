(() => {
    const dialog = document.getElementById('legislation-filter-window');
    const form = document.getElementById('legislation-filter-form');
    const searchForm = document.querySelector('.legislation-search-form');
    const open = document.getElementById('open-legislation-filters');
    let position;
    open.addEventListener('click', () => {
        for (const name of ['search', 'term_id', 'kind', 'category', 'author', 'co_author']) {
            form.elements.namedItem(name).value = searchForm.elements.namedItem(name).value;
        }
        position = {left: window.scrollX, top: window.scrollY, behavior: 'instant'};
        dialog.showModal();
    });
    document.getElementById('close-legislation-filters').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        open.focus({preventScroll: true});
        if (position) window.scrollTo(position);
    });
    document.getElementById('reset-legislation-filters').addEventListener('click', () => {
        for (const name of ['search', 'kind', 'category', 'author', 'co_author']) form.elements.namedItem(name).value = '';
        form.elements.namedItem('term_id').value = '0';
    });
})();
