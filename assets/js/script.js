jQuery(document).ready(function($) {
    const chatContainer = $('.wp-ai-chat-container');
    const messagesContainer = $('.wp-ai-chat-messages');
    const inputTextarea = $('.wp-ai-chat-input textarea');
    const sendButton = $('.wp-ai-chat-input button');
    const userInfoSection = $('.wp-ai-chat-user-info');
    const startButton = $('.wp-ai-chat-start');
    const nameInput = $('.wp-ai-chat-name');
    const emailInput = $('.wp-ai-chat-email');

    let userInfo = {
        name: '',
        email: ''
    };

    // Add a message to the chat
    function addMessage(role, content) {
        const messageClass = role === 'user' ? 'user' : 'bot';
        const messageElement = $('<div>').addClass('wp-ai-chat-message ' + messageClass).text(content);
        messagesContainer.append(messageElement);
        messagesContainer.scrollTop(messagesContainer[0].scrollHeight);
    }

    // Handle start button click
    startButton.on('click', function() {
        const name = nameInput.val().trim();
        const email = emailInput.val().trim();

        if (!name) {
            alert('Please enter your name');
            return;
        }

        if (!email || !isValidEmail(email)) {
            alert('Please enter a valid email address');
            return;
        }

        userInfo = { name, email };
        userInfoSection.hide();
        messagesContainer.show();
        inputTextarea.parent().show();
        inputTextarea.focus();
        
        // Show welcome message
        addMessage('assistant', `Hello ${name}! I'm your AI assistant. How can I help you today?`);
    });

    // Validate email format
    function isValidEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }

    // Handle send button click
    sendButton.on('click', function() {
        sendMessage();
    });

    // Handle Enter key press
    inputTextarea.on('keypress', function(e) {
        if (e.which === 13 && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    // Send message to server with streaming support
    async function sendMessage() {
        const message = inputTextarea.val().trim();
        if (!message) return;

        // Add user message to chat
        addMessage('user', message);
        inputTextarea.val('');

        // Create bot message element for streaming
        const botMessage = $('<div>').addClass('wp-ai-chat-message bot');
        messagesContainer.append(botMessage);
        messagesContainer.scrollTop(messagesContainer[0].scrollHeight);

        try {
            const response = await fetch(wpAiChat.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'wp_ai_chat_send_message',
                    message: message,
                    name: userInfo.name,
                    email: userInfo.email,
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
                                messagesContainer.scrollTop(messagesContainer[0].scrollHeight);
                            }
                        } catch (e) {
                            console.error('Error parsing SSE data:', e);
                        }
                    }
                }
            }
        } catch (error) {
            botMessage.html('Error: ' + error.message.replace(/\n/g, '<br>'));
        }
    }
});
