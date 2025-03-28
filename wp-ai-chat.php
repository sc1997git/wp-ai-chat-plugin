<?php
/**
 * Plugin Name: WP AI Chat
 * Plugin URI: https://github.com/sc1997git/wp-ai-chat-plugin
 * Description: A WordPress plugin that provides an OpenAI-compatible chat interface.
 * Version: 1.0.0
 * Author: sc1997git
 * Author URI: https://github.com/sc1997git
 * Text Domain: wp-ai-chat
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class WP_Ai_Chat {
    private $api_key;
    private $api_url = 'https://api.openai.com/v1/chat/completions';
    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wp_ai_chat_logs';
        
        // Create database table on plugin activation
        register_activation_hook(__FILE__, array($this, 'create_database_table'));
        // Initialize settings
        add_action('admin_init', array($this, 'init_settings'));
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Register shortcode
        add_shortcode('wp_ai_chat', array($this, 'render_chat_interface'));

        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function create_database_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_name varchar(100) NOT NULL,
            user_email varchar(100) NOT NULL,
            message text NOT NULL,
            response text NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function init_settings() {
        register_setting('wp_ai_chat_settings', 'wp_ai_chat_api_key');
        register_setting('wp_ai_chat_settings', 'wp_ai_chat_api_url');
        register_setting('wp_ai_chat_settings', 'wp_ai_chat_model');
        register_setting('wp_ai_chat_settings', 'wp_ai_chat_float_enabled', array(
            'type' => 'boolean',
            'default' => true
        ));
        
        add_settings_section(
            'wp_ai_chat_settings_section',
            __('API Settings', 'wp-ai-chat'),
            array($this, 'settings_section_callback'),
            'wp_ai_chat_settings'
        );

        add_settings_field(
            'wp_ai_chat_api_key',
            __('OpenAI API Key', 'wp-ai-chat'),
            array($this, 'api_key_field_callback'),
            'wp_ai_chat_settings',
            'wp_ai_chat_settings_section'
        );

        add_settings_field(
            'wp_ai_chat_api_url',
            __('API Endpoint URL', 'wp-ai-chat'),
            array($this, 'api_url_field_callback'),
            'wp_ai_chat_settings',
            'wp_ai_chat_settings_section'
        );

        add_settings_field(
            'wp_ai_chat_model',
            __('Model Name', 'wp-ai-chat'),
            array($this, 'model_field_callback'),
            'wp_ai_chat_settings',
            'wp_ai_chat_settings_section'
        );

        add_settings_field(
            'wp_ai_chat_float_enabled',
            __('Floating Chat', 'wp-ai-chat'),
            array($this, 'float_enabled_field_callback'),
            'wp_ai_chat_settings',
            'wp_ai_chat_settings_section'
        );
    }

    public function add_admin_menu() {
        add_options_page(
            __('WP AI Chat Settings', 'wp-ai-chat'),
            __('WP AI Chat', 'wp-ai-chat'),
            'manage_options',
            'wp_ai_chat_settings',
            array($this, 'settings_page_callback')
        );
    }

    public function settings_section_callback() {
        echo __('Enter your OpenAI API settings below.', 'wp-ai-chat');
    }

    public function api_key_field_callback() {
        $api_key = get_option('wp_ai_chat_api_key');
        echo '<input type="password" name="wp_ai_chat_api_key" value="' . esc_attr($api_key) . '" class="regular-text">';
    }

    public function api_url_field_callback() {
        $api_url = get_option('wp_ai_chat_api_url', 'https://api.openai.com/v1/chat/completions');
        echo '<input type="text" name="wp_ai_chat_api_url" value="' . esc_attr($api_url) . '" class="regular-text">';
    }

    public function model_field_callback() {
        $model = get_option('wp_ai_chat_model', 'gpt-3.5-turbo');
        echo '<input type="text" name="wp_ai_chat_model" value="' . esc_attr($model) . '" class="regular-text">';
    }

    public function float_enabled_field_callback() {
        $enabled = get_option('wp_ai_chat_float_enabled', true);
        echo '<label><input type="checkbox" name="wp_ai_chat_float_enabled" value="1" ' . checked($enabled, true, false) . '> ' . __('Enable floating chat window', 'wp-ai-chat') . '</label>';
    }

    public function settings_page_callback() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('wp_ai_chat_settings');
                do_settings_sections('wp_ai_chat_settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function enqueue_scripts() {
        wp_enqueue_style(
            'wp-ai-chat-style',
            plugin_dir_url(__FILE__) . 'assets/css/style.css'
        );

        wp_enqueue_script(
            'wp-ai-chat-script',
            plugin_dir_url(__FILE__) . 'assets/js/script.js',
            array('jquery'),
            '1.0.0',
            true
        );

        // Only enqueue floating chat on frontend if enabled
        if (!is_admin() && get_option('wp_ai_chat_float_enabled', true)) {
            wp_enqueue_script(
                'wp-ai-chat-float',
                plugin_dir_url(__FILE__) . 'assets/js/float.js',
                array('jquery'),
                '1.0.0',
                true
            );
        }

        wp_localize_script(
            'wp-ai-chat-script',
            'wpAiChat',
            array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_ai_chat_nonce'),
            'welcome_message' => __('Hello! How can I help you today?', 'wp-ai-chat'),
            'error_messages' => array(
                'api_connection' => __('Connection to AI service failed. Please check your internet connection.', 'wp-ai-chat'),
                'api_response' => __('Invalid response from AI service. Please try again.', 'wp-ai-chat'), 
                'server_error' => __('Server error occurred. Please contact support.', 'wp-ai-chat'),
                'timeout' => __('Request timed out. Please try again.', 'wp-ai-chat')
            )
            )
        );
    }

    public function render_chat_interface() {
        ob_start();
        ?>
        <div class="wp-ai-chat-container">
            <div class="wp-ai-chat-user-info" style="display: block;">
                <h3><?php esc_html_e('Please enter your information', 'wp-ai-chat'); ?></h3>
                <div class="wp-ai-chat-input-group">
                    <input type="text" class="wp-ai-chat-name" placeholder="<?php esc_attr_e('Your Name', 'wp-ai-chat'); ?>">
                </div>
                <div class="wp-ai-chat-input-group">
                    <input type="email" class="wp-ai-chat-email" placeholder="<?php esc_attr_e('Your Email', 'wp-ai-chat'); ?>">
                </div>
                <button class="wp-ai-chat-start"><?php esc_html_e('Start Chat', 'wp-ai-chat'); ?></button>
            </div>
            <div class="wp-ai-chat-messages" style="display: none;"></div>
            <div class="wp-ai-chat-input" style="display: none;">
                <textarea placeholder="<?php esc_attr_e('Type your message...', 'wp-ai-chat'); ?>"></textarea>
                <button><?php esc_html_e('Send', 'wp-ai-chat'); ?></button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_chat_request() {
        check_ajax_referer('wp_ai_chat_nonce', 'nonce');

        $message = sanitize_text_field($_POST['message']);
        $name = sanitize_text_field($_POST['name']);
        $email = sanitize_email($_POST['email']);
        $response_content = '';
        $api_key = get_option('wp_ai_chat_api_key');
        $api_url = get_option('wp_ai_chat_api_url', 'https://api.openai.com/v1/chat/completions');
        $model = get_option('wp_ai_chat_model', 'gpt-3.5-turbo');

        // Disable WordPress output buffering
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Set proper headers for streaming
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        // Make direct API request with streaming
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        ));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
            'model' => $model,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $message
                )
            ),
            'temperature' => 0.7,
            'stream' => true
        )));
        // Initialize response buffer
        $response_buffer = '';
        
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$response_buffer) {
            echo $data;
            ob_flush();
            flush();
            $response_buffer .= $data;
            return strlen($data);
        });

        $curl_result = curl_exec($ch);
        if (curl_errno($ch)) {
            error_log('OpenAI API Error: ' . curl_error($ch));
            wp_send_json_error(array(
                'message' => __('API connection error', 'wp-ai-chat')
            ), 500);
        }
        curl_close($ch);

        // Process response to extract actual content
        $response_content = '';
        $lines = explode("\n", $response_buffer);
        foreach ($lines as $line) {
            if (strpos($line, 'data:') === 0 && $line !== 'data: [DONE]') {
                $data = json_decode(trim(substr($line, 5)), true);
                if (isset($data['choices'][0]['delta']['content'])) {
                    $response_content .= $data['choices'][0]['delta']['content'];
                }
            }
        }

        // Save conversation to database if we have valid data
        if (!empty($response_content)) {
            global $wpdb;
            
            // Check if table exists
            if ($wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name) {
                $result = $wpdb->insert(
                    $this->table_name,
                    array(
                        'user_name' => $name,
                        'user_email' => $email,
                        'message' => $message,
                        'response' => $response_content
                    ),
                    array('%s', '%s', '%s', '%s')
                );
                
                if ($result === false) {
                    error_log('Failed to save chat log: ' . $wpdb->last_error);
                }
            } else {
                error_log('Database table does not exist: ' . $this->table_name);
            }
        }
        
        exit;
    }
}


// Add admin menu for viewing chat logs
add_action('admin_menu', function() {
    add_menu_page(
        'Chat Logs',
        'Chat Logs',
        'manage_options',
        'wp-ai-chat-logs',
        function() {
            global $wpdb;
            $table_name = $wpdb->prefix . 'wp_ai_chat_logs';
            
            // Paging processing
            $per_page = 10;
            $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
            $offset = ($current_page - 1) * $per_page;
            
            // Obtain the total items of records
            $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
            $total_pages = ceil($total_items / $per_page);
            
            // Retrieve the current page record
            $logs = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ));
            
            echo '<div class="wrap">';
            echo '<h1>Chat Logs</h1>';
            
            // Pagination navigation
            echo '<div class="tablenav top">';
            echo '<div class="tablenav-pages">';
            echo paginate_links(array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => __('&laquo;'),
                'next_text' => __('&raquo;'),
                'total' => $total_pages,
                'current' => $current_page
            ));
            echo '</div>';
            echo '</div>';
            
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>ID</th><th>User</th><th>Email</th><th>Message</th><th>Date</th><th>Actions</th></tr></thead>';
            echo '<tbody>';
            foreach ($logs as $log) {
                echo '<tr>';
                echo '<td>' . esc_html($log->id) . '</td>';
                echo '<td>' . esc_html($log->user_name) . '</td>';
                echo '<td>' . esc_html($log->user_email) . '</td>';
                echo '<td>' . esc_html(substr($log->message, 0, 50)) . (strlen($log->message) > 50 ? '...' : '') . '</td>';
                echo '<td>' . esc_html($log->created_at) . '</td>';
                echo '<td><button class="button view-response" data-id="' . esc_attr($log->id) . '">View Response</button></td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            
            // Add modal for full response
            echo '<div id="response-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:9999;">';
            echo '<div style="background:#fff;margin:5% auto;padding:20px;width:80%;max-height:80%;overflow:auto;">';
            echo '<h2>Full Response <span id="response-id"></span></h2>';
            echo '<pre id="response-content" style="white-space:pre-wrap;word-wrap:break-word;"></pre>';
            echo '<button class="button" onclick="document.getElementById(\'response-modal\').style.display=\'none\'">Close</button>';
            echo '</div></div>';
            
            // Add JavaScript for modal
            echo '<script>
            jQuery(document).ready(function($) {
                $(".view-response").click(function() {
                    var id = $(this).data("id");
                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "get_chat_response",
                            id: id,
                            nonce: "' . esc_js(wp_create_nonce('get_chat_response')) . '"
                        },
                        success: function(response) {
                            $("#response-id").text("#" + id);
                            $("#response-content").text(response);
                            $("#response-modal").show();
                        },
                        error: function(xhr) {
                            $("#response-content").text("Error loading response: " + xhr.statusText);
                            $("#response-modal").show();
                        }
                    });
                });
            });
            </script>';
            echo '</div>';
        },
        'dashicons-format-chat',
        30
    );
});

// Register AJAX handlers
$wp_ai_chat = new WP_Ai_Chat();
add_action('wp_ajax_wp_ai_chat_send_message', array($wp_ai_chat, 'handle_chat_request'));
add_action('wp_ajax_nopriv_wp_ai_chat_send_message', array($wp_ai_chat, 'handle_chat_request'));
add_action('wp_ajax_get_chat_response', function() {
    check_ajax_referer('get_chat_response', 'nonce');
    global $wpdb;
    $id = intval($_POST['id']);
    $table_name = $wpdb->prefix . 'wp_ai_chat_logs';
    $response = $wpdb->get_var($wpdb->prepare(
        "SELECT response FROM $table_name WHERE id = %d", 
        $id
    ));
    echo $response;
    wp_die();
});
