(() => {
    const data = JSON.parse(document.getElementById('historical-legislation-options').textContent);
    const term = document.getElementById('historical-term');
    const kind = document.getElementById('historical-kind');
    const fill = (select, options) => select.replaceChildren(...options.map(([value, label]) => new Option(label, value)));
    const fillChoices = (id, options) => {
        const group = document.getElementById(id);
        group.replaceChildren(...options.map(([value, text]) => {
            const label = document.createElement('label');
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.name = group.dataset.name + '[]';
            input.value = value;
            const span = document.createElement('span');
            span.textContent = text;
            label.append(input, span);
            return label;
        }));
        if (!options.length) {
            const message = document.createElement('p');
            message.className = 'muted';
            message.textContent = 'No choices available for this term.';
            group.append(message);
        }
        document.querySelector('[data-choices="' + id + '"]').value = '';
    };
    document.querySelectorAll('.historical-choice-search').forEach(search => {
        search.addEventListener('input', () => {
            document.getElementById(search.dataset.choices).querySelectorAll('label').forEach(label => {
                label.hidden = !label.textContent.toLowerCase().includes(search.value.toLowerCase());
            });
        });
    });
    document.querySelectorAll('.historical-choices').forEach(group => {
        group.addEventListener('change', event => {
            if (event.target.type !== 'checkbox' || !event.target.checked) return;
            group.querySelectorAll('input').forEach(input => {
                if (input !== event.target && (input.value === 'N/A' || event.target.value === 'N/A')) input.checked = false;
            });
        });
    });
    term.addEventListener('change', () => {
        const options = data.terms[term.value] || {councilors: [], committees: []};
        const names = [['N/A', 'N/A'], ...options.councilors.map(name => [name, name])];
        fillChoices('historical-authors', names);
        fillChoices('historical-co-authors', names);
        fillChoices('historical-committees', options.committees.map(committee => [committee.id, committee.name]));
    });
    kind.addEventListener('change', () => {
        fill(document.getElementById('historical-category'), [['', 'Select category'], ...(data.categories[kind.value] || []).map(name => [name, name])]);
    });
})();
