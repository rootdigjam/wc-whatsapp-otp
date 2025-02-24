<?php

/**
 * Plugin Name: WooCommerce WhatsApp OTP Verification
 * Description: Verifies customer phone numbers via WhatsApp OTP during checkout
 * Version: 1.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WhatsApp_OTP_Verification
{
    private $whatsapp_business_id;
    private $whatsapp_token;
    private $phone_number_id;

    public function __construct()
    {
        // Initialize settings
        $this->whatsapp_business_id = get_option('whatsapp_business_id');
        $this->whatsapp_token = get_option('whatsapp_token');
        $this->phone_number_id = get_option('whatsapp_phone_number_id');

        // Add settings page
        add_action('admin_menu', array($this, 'add_settings_page'));

        // Add verification field to checkout
        add_action('woocommerce_after_checkout_billing_form', array($this, 'add_otp_field'));

        // Add AJAX handlers
        add_action('wp_ajax_send_whatsapp_otp', array($this, 'send_whatsapp_otp'));
        add_action('wp_ajax_nopriv_send_whatsapp_otp', array($this, 'send_whatsapp_otp'));
        add_action('wp_ajax_verify_whatsapp_otp', array($this, 'verify_whatsapp_otp'));
        add_action('wp_ajax_nopriv_verify_whatsapp_otp', array($this, 'verify_whatsapp_otp'));

        // Validate OTP before order processing
        add_action('woocommerce_checkout_process', array($this, 'validate_otp_submission'));
    }

    public function add_settings_page()
    {
        add_submenu_page(
            'woocommerce',
            'WhatsApp OTP Settings',
            'WhatsApp OTP',
            'manage_options',
            'whatsapp-otp-settings',
            array($this, 'settings_page_html')
        );
    }

    public function settings_page_html()
    {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }

        // Save settings if form is submitted
        if (isset($_POST['save_settings'])) {
            update_option('whatsapp_business_id', sanitize_text_field($_POST['whatsapp_business_id']));
            update_option('whatsapp_token', sanitize_text_field($_POST['whatsapp_token']));
            update_option('whatsapp_phone_number_id', sanitize_text_field($_POST['whatsapp_phone_number_id']));
        }
?>
        <div class="wrap">
            <h1>WhatsApp OTP Settings</h1>
            <form method="post">
                <table class="form-table">
                    <tr>
                        <th>WhatsApp Business ID</th>
                        <td>
                            <input type="text" name="whatsapp_business_id"
                                value="<?php echo esc_attr($this->whatsapp_business_id); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th>WhatsApp Token</th>
                        <td>
                            <input type="password" name="whatsapp_token"
                                value="<?php echo esc_attr($this->whatsapp_token); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th>Phone Number ID</th>
                        <td>
                            <input type="text" name="whatsapp_phone_number_id"
                                value="<?php echo esc_attr($this->phone_number_id); ?>" class="regular-text">
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="save_settings" class="button-primary" value="Save Settings">
                </p>
            </form>
        </div>
    <?php
    }

    public function add_otp_field($checkout)
    {
    ?>
        <div id="whatsapp-otp-verification">
            <h3>Phone Verification</h3>
            <p>
                <button type="button" id="send-otp" class="button">Send OTP via WhatsApp</button>
            </p>
            <p class="form-row">
                <label>Enter OTP</label>
                <input type="text" id="whatsapp-otp" name="whatsapp_otp">
                <button type="button" id="verify-otp" class="button">Verify OTP</button>
            </p>
            <input type="hidden" id="phone-verified" name="phone_verified" value="0">
        </div>

        <script>
            jQuery(document).ready(function($) {
                $('#send-otp').click(function() {
                    var phone = $('#billing_phone').val();
                    if (!phone) {
                        alert('Please enter your phone number first');
                        return;
                    }

                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'send_whatsapp_otp',
                            phone: phone
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('OTP sent to your WhatsApp number');
                            } else {
                                alert('Failed to send OTP. Please try again.');
                            }
                        }
                    });
                });

                $('#verify-otp').click(function() {
                    var otp = $('#whatsapp-otp').val();
                    if (!otp) {
                        alert('Please enter OTP');
                        return;
                    }

                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'verify_whatsapp_otp',
                            otp: otp
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#phone-verified').val('1');
                                alert('Phone number verified successfully');
                            } else {
                                alert('Invalid OTP. Please try again.');
                            }
                        }
                    });
                });
            });
        </script>
<?php
    }

    public function send_whatsapp_otp()
    {
        $phone = sanitize_text_field($_POST['phone']);

        // Generate OTP
        $otp = wp_rand(100000, 999999);

        // Store OTP in session
        WC()->session->set('whatsapp_otp', $otp);

        // Send WhatsApp message using Meta API
        $response = wp_remote_post(
            "https://graph.facebook.com/v22.0/{$this->phone_number_id}/messages",
            array(
                'headers' => array(
                    'Authorization' => "Bearer {$this->whatsapp_token}",
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode(array(
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $phone,
                    'type' => 'template',
                    'template' => array(
                        // 'name' => 'otp_verification',
                        'name' => 'woocom_phone_verification',
                        'language' => array(
                            'code' => 'en'
                        ),
                        'components' => array(
                            array(
                                'type' => 'body',
                                'parameters' => array(
                                    array(
                                        'type' => 'text',
                                        'text' => $otp
                                    )
                                )
                            ),
                            array(
                                'type' => 'button',
                                'sub_type' => 'url',
                                'index' => '0',
                                'parameters' => array(
                                    array(
                                        'type' => 'text',
                                        'text' => $otp,
                                    )
                                )
                            )
                        )
                    )
                ))
            )
        );

        if (is_wp_error($response)) {
            wp_send_json_error();
        } else {
            wp_send_json_success();
        }
    }

    public function verify_whatsapp_otp()
    {
        $submitted_otp = sanitize_text_field($_POST['otp']);
        $stored_otp = WC()->session->get('whatsapp_otp');

        if ($submitted_otp == $stored_otp) {
            wp_send_json_success();
        } else {
            wp_send_json_error();
        }
    }

    public function validate_otp_submission()
    {
        if (!isset($_POST['phone_verified']) || $_POST['phone_verified'] != '1') {
            wc_add_notice('Please verify your phone number via WhatsApp OTP', 'error');
        }
    }
}

// Initialize the plugin
add_action('plugins_loaded', function () {
    new WC_WhatsApp_OTP_Verification();
});
