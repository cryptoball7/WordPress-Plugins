(function () {
    if (!window.AI_SHOPPING_ASSISTANT) return;

    const root = document.getElementById('ai-shopping-assistant-root');
    if (!root) return;

    const toggle = root.querySelector('.asa-toggle');
    const panel = root.querySelector('.asa-panel');
    const form = root.querySelector('.asa-form');
    const messages = root.querySelector('.asa-messages');
    const input = form.querySelector('input[name="message"]');

    function appendMessage(who, text, meta) {
        const div = document.createElement('div');
        div.className = 'asa-message ' + (who === 'user' ? 'asa-user' : 'asa-assistant');
        div.innerHTML = '<div class="asa-who">' + (who === 'user' ? 'You' : 'Assistant') + '</div><div class="asa-text">' + escapeHtml(text).replace(/\n/g, '<br>') + '</div>';
        if (meta && meta.cached) {
            const badge = document.createElement('div');
            badge.className = 'asa-meta';
            badge.textContent = 'cached result';
            div.appendChild(badge);
        }
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
    }

    function escapeHtml(unsafe) {
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;");
    }

    toggle.addEventListener('click', function () {
        const open = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', !open);
        if (open) {
            panel.hidden = true;
        } else {
            panel.hidden = false;
            input.focus();
        }
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const text = input.value.trim();
        if (!text) return;
        appendMessage('user', text);
        input.value = '';
        sendToServer(text);
    });

    async function sendToServer(message) {
        appendMessage('assistant', 'Thinking...');
        try {
            const payload = {
                message: message,
                nonce: AI_SHOPPING_ASSISTANT.nonce
            };
            const r = await fetch(AI_SHOPPING_ASSISTANT.rest_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            });
            const json = await r.json();
            // remove last 'Thinking...' message
            const last = messages.querySelector('.asa-message.asa-assistant:last-child');
            if (last && last.textContent.indexOf('Thinking') !== -1) {
                last.remove();
            }
            if (json.error) {
                appendMessage('assistant', 'Error: ' + json.error);
                return;
            }
            appendMessage('assistant', json.response || '(no response)', { cached: json.cached });
        } catch (err) {
            appendMessage('assistant', 'Network error: ' + err.message);
        }
    }

})();
