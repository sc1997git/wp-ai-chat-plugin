jQuery(document).ready(function($) {
    // Create floating button and container
    $('body').append(`
        <div class="wp-ai-chat-float">
            <button class="wp-ai-chat-float-button">💬</button>
        </div>
        <div class="wp-ai-chat-float-container">
            <div class="wp-ai-chat-container">
                <div class="wp-ai-chat-user-info" style="display: block;">
                    <h3>Please enter your information</h3>
                    <div class="wp-ai-chat-input-group">
                        <input type="text" class="wp-ai-chat-name" placeholder="Your Name">
                    </div>
                    <div class="wp-ai-chat-input-group">
                        <input type="email" class="wp-ai-chat-email" placeholder="Your Email">
                    </div>
                    <button class="wp-ai-chat-start">Start Chat</button>
                </div>
                <div class="wp-ai-chat-messages" style="display: none;"></div>
                <div class="wp-ai-chat-input" style="display: none;">
                    <textarea placeholder="Type your message..."></textarea>
                    <button>Send</button>
                </div>
            </div>
        </div>
    `);

    // Toggle chat container
    $('.wp-ai-chat-float-button').click(function() {
        $('.wp-ai-chat-float-container').toggle();
    });

    // Initialize chat functionality
    const container = $('.wp-ai-chat-float-container');
    const messages = container.find('.wp-ai-chat-messages');
    const input = container.find('.wp-ai-chat-input textarea');
    const sendBtn = container.find('.wp-ai-chat-input button');
    const userInfo = container.find('.wp-ai-chat-user-info');
    const startBtn = container.find('.wp-ai-chat-start');
    const nameInput = container.find('.wp-ai-chat-name');
    const emailInput = container.find('.wp-ai-chat-email');

    // Start chat handler
    startBtn.click(function() {
        const name = nameInput.val().trim();
        const email = emailInput.val().trim();

        if (!name || !email) {
            alert('Please enter both name and email');
            return;
        }

        userInfo.hide();
        messages.show();
        input.parent().show();
        
        // Show welcome message
        messages.append(`
            <div class="wp-ai-chat-message bot">
                ${wpAiChat.welcome_message}
            </div>
        `);
        messages.scrollTop(messages[0].scrollHeight);
    });

    // Send message handler
    sendBtn.click(function() {
        sendMessage().catch(error => {
            console.error('Error sending message:', error);
            messages.append(`
                <div class="wp-ai-chat-message bot">
                    Error: ${error.message}
                </div>
            `);
            messages.scrollTop(messages[0].scrollHeight);
        });
    });
    input.keypress(function(e) {
        if (e.which === 13 && !e.shiftKey) {
            e.preventDefault();
            sendMessage().catch(error => {
                console.error('Error sending message:', error);
                messages.append(`
                    <div class="wp-ai-chat-message bot">
                        Error: ${error.message}
                    </div>
                `);
                messages.scrollTop(messages[0].scrollHeight);
            });
        }
    });

    async function sendMessage() {
        const message = input.val().trim();
        if (!message) return;

        // Add user message
        messages.append(`
            <div class="wp-ai-chat-message user">
                ${message}
            </div>
        `);
        input.val('');
        messages.scrollTop(messages[0].scrollHeight);

        // Create bot message element for streaming
        const botMessage = $('<div>').addClass('wp-ai-chat-message bot');
        messages.append(botMessage);
        messages.scrollTop(messages[0].scrollHeight);

        try {
            const response = await fetch(wpAiChat.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'wp_ai_chat_send_message',
                    message: message,
                    name: nameInput.val(),
                    email: emailInput.val(),
                    nonce: wpAiChat.nonce
                })
            });

            if (!response.ok) {
                throw new Error('Network response was not ok');
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let botResponse = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                const chunk = decoder.decode(value, { stream: true });
                // Process SSE format
                const lines = chunk.split('\n');
                for (const line of lines) {
                    if (line.startsWith('data:') && line !== 'data: [DONE]') {
                        try {
                            const data = JSON.parse(line.substring(5));
                            if (data.choices && data.choices[0].delta && data.choices[0].delta.content) {
                                botResponse += data.choices[0].delta.content;
                                botMessage.html(botResponse.replace(/\n/g, '<br>'));
                                messages.scrollTop(messages[0].scrollHeight);
                            }
                        } catch (e) {
                            console.error('Error parsing SSE data:', e);
                        }
                    }
                }
            }
        } catch (error) {
            botMessage.html('Error: ' + error.message.replace(/\n/g, '<br>'));
            messages.scrollTop(messages[0].scrollHeight);
        }
    }
});
