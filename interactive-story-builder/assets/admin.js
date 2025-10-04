(function(){
    const root = document.getElementById('isb-builder-root');
    const dataField = document.getElementById('_isb_data_field');

    function parseInitial() {
        let init = [];
        try {
            init = JSON.parse(dataField.value || 'null');
        } catch(e) {
            init = ISB_ADMIN.initial || { sections: [] };
        }
        if (!init || !init.sections) init = { sections: [] };
        return init;
    }

    function createSectionDOM(section, index) {
        const container = document.createElement('div');
        container.className = 'isb-section-builder';
        container.setAttribute('data-index', index);

        container.innerHTML = `
            <div class="isb-builder-header">
                <input class="isb-builder-title" placeholder="Section title" value="${escapeHtml(section.title)}" />
                <div class="isb-builder-controls">
                    <button class="isb-move-up">↑</button>
                    <button class="isb-move-down">↓</button>
                    <button class="isb-remove">×</button>
                </div>
            </div>
            <textarea class="isb-builder-content">${escapeHtml(section.content)}</textarea>
            <div class="isb-builder-meta">
                <label>BG color: <input type="color" class="isb-bg-color" value="${section.bg_color || '#ffffff'}" /></label>
                <label>Text color: <input type="color" class="isb-text-color" value="${section.text_color || '#111111'}" /></label>
                <label>Pin section: <input type="checkbox" class="isb-pin" ${section.pin ? 'checked' : ''} /></label>
            </div>
        `;

        return container;
    }

    function escapeHtml(unsafe) {
        return (unsafe || '').replace(/&/g, '&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }

    function render() {
        const state = parseInitial();
        root.innerHTML = '';
        state.sections.forEach((s, i) => root.appendChild(createSectionDOM(s, i)));
        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'isb-add-section button';
        addBtn.innerText = ISB_ADMIN.strings.add_section;
        addBtn.addEventListener('click', () => {
            state.sections.push({ id: 'sec-' + Math.random().toString(36).substr(2,6), title: 'New section', content: '<p></p>', bg_color: '#ffffff', text_color: '#111111', pin: false });
            dataField.value = JSON.stringify(state);
            render();
        });
        root.appendChild(addBtn);

        // Attach events
        root.querySelectorAll('.isb-remove').forEach(btn => btn.addEventListener('click', (e) => {
            const idx = parseInt(e.target.closest('.isb-section-builder').dataset.index, 10);
            state.sections.splice(idx,1);
            dataField.value = JSON.stringify(state);
            render();
        }));

        root.querySelectorAll('.isb-move-up').forEach(btn => btn.addEventListener('click', (e) => {
            const idx = parseInt(e.target.closest('.isb-section-builder').dataset.index, 10);
            if (idx === 0) return;
            const tmp = state.sections[idx-1];
            state.sections[idx-1] = state.sections[idx];
            state.sections[idx] = tmp;
            dataField.value = JSON.stringify(state);
            render();
        }));

        root.querySelectorAll('.isb-move-down').forEach(btn => btn.addEventListener('click', (e) => {
            const idx = parseInt(e.target.closest('.isb-section-builder').dataset.index, 10);
            if (idx === state.sections.length - 1) return;
            const tmp = state.sections[idx+1];
            state.sections[idx+1] = state.sections[idx];
            state.sections[idx] = tmp;
            dataField.value = JSON.stringify(state);
            render();
        }));

        // update fields
        root.querySelectorAll('.isb-builder-title').forEach((input, i) => input.addEventListener('input', (e) => {
            const state = parseInitial();
            state.sections[i].title = e.target.value;
            dataField.value = JSON.stringify(state);
        }));

        root.querySelectorAll('.isb-builder-content').forEach((ta, i) => ta.addEventListener('input', (e) => {
            const state = parseInitial();
            state.sections[i].content = e.target.value;
            dataField.value = JSON.stringify(state);
        }));

        root.querySelectorAll('.isb-bg-color').forEach((input, i) => input.addEventListener('change', (e) => {
            const state = parseInitial();
            state.sections[i].bg_color = e.target.value;
            dataField.value = JSON.stringify(state);
        }));

        root.querySelectorAll('.isb-text-color').forEach((input, i) => input.addEventListener('change', (e) => {
            const state = parseInitial();
            state.sections[i].text_color = e.target.value;
            dataField.value = JSON.stringify(state);
        }));

        root.querySelectorAll('.isb-pin').forEach((input, i) => input.addEventListener('change', (e) => {
            const state = parseInitial();
            state.sections[i].pin = e.target.checked;
            dataField.value = JSON.stringify(state);
        }));
    }

    // on DOM ready
    document.addEventListener('DOMContentLoaded', function() {
        // if _isb_data_field doesn't exist yet, try to wait shortly
        if (!document.getElementById('_isb_data_field')) {
            setTimeout(render, 200);
        } else {
            render();
        }

        // ensure data is synced before submit
        const form = document.querySelector('#post');
        if (form) {
            form.addEventListener('submit', () => {
                const state = parseInitial();
                // because we escaped content for textareas we need to read them back
                document.querySelectorAll('.isb-section-builder').forEach((el, i) => {
                    const title = el.querySelector('.isb-builder-title').value;
                    const content = el.querySelector('.isb-builder-content').value;
                    const bg = el.querySelector('.isb-bg-color').value;
                    const tc = el.querySelector('.isb-text-color').value;
                    const pin = el.querySelector('.isb-pin').checked;
                    state.sections[i].title = title;
                    state.sections[i].content = content;
                    state.sections[i].bg_color = bg;
                    state.sections[i].text_color = tc;
                    state.sections[i].pin = pin;
                });
                document.getElementById('_isb_data_field').value = JSON.stringify(state);
            });
        }
    });
})();
