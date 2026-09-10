(() => {
    document.querySelectorAll('[data-record-search-url]').forEach((input, index) => {
        const form = input.closest('form');
        if (!form) return;
        const wrapper = document.createElement('div');
        wrapper.className = 'record-filter-search';
        input.before(wrapper);
        wrapper.append(input);
        const list = document.createElement('div');
        list.id = 'record-filter-suggestions-' + index;
        list.className = 'personal-note-record-suggestions';
        list.setAttribute('role', 'listbox');
        list.setAttribute('aria-label', 'Suggested records');
        list.hidden = true;
        wrapper.append(list);
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-controls', list.id);
        input.setAttribute('aria-expanded', 'false');
        input.autocomplete = 'off';
        let timer;
        let request;
        let generation = 0;
        let active = -1;
        let choices = [];
        const close = () => {
            clearTimeout(timer);
            request?.abort();
            generation++;
            active = -1;
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        };
        const show = () => {
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        };
        const status = (message) => {
            choices = [];
            active = -1;
            input.removeAttribute('aria-activedescendant');
            const row = document.createElement('div');
            row.className = 'personal-note-record-search-status';
            row.setAttribute('role', 'status');
            row.textContent = message;
            list.replaceChildren(row);
            show();
        };
        const select = (record) => {
            input.value = record.control_number;
            close();
            form.requestSubmit();
        };
        const render = (records) => {
            list.replaceChildren();
            choices = [];
            active = -1;
            if (!records.length) {
                status('No matching records. You can still search using this keyword.');
                return;
            }
            records.forEach((record, optionIndex) => {
                const option = document.createElement('button');
                option.type = 'button';
                option.tabIndex = -1;
                option.className = 'personal-note-record-option';
                option.id = list.id + '-' + optionIndex;
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', 'false');
                const number = document.createElement('strong');
                number.textContent = record.control_number;
                const title = document.createElement('span');
                title.className = 'personal-note-record-option-title';
                title.textContent = record.title;
                const meta = document.createElement('span');
                meta.className = 'personal-note-record-option-meta';
                meta.textContent = [record.party_name, record.committee_name, record.status].filter(Boolean).join(' · ');
                option.append(number, title, meta);
                option.addEventListener('mousedown', (event) => event.preventDefault());
                option.addEventListener('click', () => select(record));
                list.append(option);
                choices.push({ option, record });
            });
            show();
        };
        const search = async () => {
            const query = input.value.trim();
            if (query.length < 2) { close(); return; }
            request?.abort();
            request = new AbortController();
            const sequence = ++generation;
            status('Searching records…');
            try {
                const endpoint = new URL(input.dataset.recordSearchUrl, window.location.origin);
                endpoint.searchParams.set('q', query);
                const response = await fetch(endpoint, { headers: { Accept: 'application/json' }, signal: request.signal });
                if (!response.ok) throw new Error('Search unavailable');
                const payload = await response.json();
                if (sequence !== generation || input.value.trim() !== query) return;
                render(Array.isArray(payload.records) ? payload.records : []);
            } catch (error) {
                if (error.name !== 'AbortError' && sequence === generation) {
                    status('Suggestions are unavailable. Press Enter or use Filter to search.');
                }
            }
        };
        input.addEventListener('input', () => {
            close();
            if (input.value.trim().length >= 2) timer = setTimeout(search, 250);
        });
        input.addEventListener('focus', () => {
            if (input.value.trim().length >= 2) { clearTimeout(timer); timer = setTimeout(search, 250); }
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') { close(); return; }
            if (list.hidden || !choices.length) return;
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                active = (active + (event.key === 'ArrowDown' ? 1 : (active < 0 ? 0 : -1)) + choices.length) % choices.length;
                choices.forEach(({ option }, i) => {
                    option.classList.toggle('is-active', i === active);
                    option.setAttribute('aria-selected', String(i === active));
                });
                input.setAttribute('aria-activedescendant', choices[active].option.id);
                choices[active].option.scrollIntoView({ block: 'nearest' });
            } else if (event.key === 'Enter' && active >= 0) {
                event.preventDefault();
                select(choices[active].record);
            }
        });
        wrapper.addEventListener('focusout', (event) => {
            if (!wrapper.contains(event.relatedTarget)) close();
        });
        document.addEventListener('mousedown', (event) => { if (!wrapper.contains(event.target)) close(); });
        form.addEventListener('submit', close);
        form.addEventListener('reset', close);
    });
})();
