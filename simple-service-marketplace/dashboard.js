document.addEventListener('DOMContentLoaded', async () => {

    const app = document.getElementById('sso-app');

    if (!app) return;

    const orderId = app.dataset.order;

    async function loadMessages() {

        const response = await fetch(
            `/wp-json/sso/v1/messages/${orderId}`
        );

        const messages = await response.json();

        render(messages);
    }

    function render(messages) {

        app.innerHTML = `
            <div class="sso-dashboard">

                <div class="sso-sidebar">
                    <h2>Order #${orderId}</h2>
                    <div class="sso-status">
                        In Progress
                    </div>
                </div>

                <div class="sso-chat">

                    <div class="sso-messages">
                        ${messages.map(msg => `
                            <div class="sso-msg ${msg.sender}">
                                <div class="bubble">
                                    ${msg.content}
                                </div>
                            </div>
                        `).join('')}
                    </div>

                    <form id="sso-send">
                        <textarea name="message"></textarea>
                        <button>Send</button>
                    </form>

                </div>

            </div>
        `;

        bindForm();
    }

    function bindForm() {

        const form = document.getElementById('sso-send');

        form.addEventListener('submit', async (e) => {

            e.preventDefault();

            const textarea = form.querySelector('textarea');

            await fetch('/wp-json/sso/v1/messages/send', {

                method: 'POST',

                headers: {
                    'Content-Type': 'application/json'
                },

                body: JSON.stringify({
                    order_id: orderId,
                    message: textarea.value
                })
            });

            textarea.value = '';

            loadMessages();
        });
    }

    loadMessages();

    setInterval(loadMessages, 3000);
});
