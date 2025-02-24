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

        add_action('wp_head', array($this, 'add_custom_styles'));

        // Add verification field to checkout
        // add_action('woocommerce_after_checkout_billing_form', array($this, 'add_otp_field'));

        // Add AJAX handlers
        add_action('wp_ajax_send_whatsapp_otp', array($this, 'send_whatsapp_otp'));
        add_action('wp_ajax_nopriv_send_whatsapp_otp', array($this, 'send_whatsapp_otp'));
        add_action('wp_ajax_verify_whatsapp_otp', array($this, 'verify_whatsapp_otp'));
        add_action('wp_ajax_nopriv_verify_whatsapp_otp', array($this, 'verify_whatsapp_otp'));

        // Validate OTP before order processing
        add_action('woocommerce_checkout_process', array($this, 'validate_otp_submission'));

        // Display verification status in to order and user profile.
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_verified_phone_to_order'));
        add_action('woocommerce_checkout_update_user_meta', array($this, 'save_verified_phone_to_user'));

        // Display verification status in columns
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_new_column_phone_vrf'));
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'phone_verification_column_val'), 10, 2);

        // add_action('woocommerce_before_checkout_billing_form', array($this, 'check_phone_verification'));
        add_action('woocommerce_after_checkout_billing_form', array($this, 'remove_duplicate_verification'));

        // remove_action('woocommerce_after_checkout_billing_form', array($this, 'add_otp_field'));
        add_action('woocommerce_after_checkout_billing_form', array($this, 'add_otp_field_after_phone'), 10);

        add_action('woocommerce_edit_account_form', array($this, 'add_phone_verification_to_account'));
        add_action('woocommerce_save_account_details', array($this, 'save_account_phone_verification'));
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

    // Add this method to your class
    public function add_custom_styles()
    {
?>
        <style>
            #whatsapp-otp-verification {
                margin-bottom: 20px;
            }

            #whatsapp-otp-verification .form-row {
                padding: 3px;
                margin: 0 0 6px;
            }

            #whatsapp-otp-verification.woocommerce-invalid input {
                border-color: #e2401c;
            }

            #whatsapp-otp-verification.woocommerce-validated input {
                border-color: #69bf29;
            }

            #whatsapp-otp {
                width: 100%;
                padding: 8px;
            }

            .woocommerce-message {
                margin-bottom: 20px;
            }
        </style>
    <?php
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

    /*
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
    */

    public function add_otp_field($checkout)
    {
        $verified_phone = WC()->session->get('verified_phone_number');
    ?>
        <div id="whatsapp-otp-verification">
            <!-- <p class="form-row">
                <label>Phone Number</label>
                <input type="tel" id="billing_phone" name="billing_phone"
                    value="<?php echo esc_attr($verified_phone); ?>"
                    <?php echo $verified_phone ? 'readonly' : ''; ?>>
            </p> -->
            <?php if (!$verified_phone): ?>
                <p class="form-row">
                    <button type="button" id="send-otp" class="button">Send OTP</button>
                    <button type="button" id="resend-otp" class="button" style="display:none;">Resend OTP</button>
                </p>
                <p class="form-row">
                    <label>Enter OTP</label>
                    <input type="text" id="whatsapp-otp" name="whatsapp_otp">
                    <button type="button" id="verify-otp" class="button">Verify OTP</button>
                </p>
            <?php endif; ?>
            <input type="hidden" id="phone-verified" name="phone_verified" value="<?php echo $verified_phone ? '1' : '0'; ?>">
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Add resend OTP functionality
                let otpTimer;

                function startOTPTimer() {
                    let seconds = 30;
                    $('#send-otp').hide();
                    $('#resend-otp').show().prop('disabled', true).text(`Resend OTP in ${seconds}s`);

                    otpTimer = setInterval(function() {
                        seconds--;
                        if (seconds <= 0) {
                            clearInterval(otpTimer);
                            $('#resend-otp').prop('disabled', false).text('Resend OTP');
                        } else {
                            $('#resend-otp').text(`Resend OTP in ${seconds}s`);
                        }
                    }, 1000);
                }

                $('#send-otp, #resend-otp').click(function() {
                    // Your existing send OTP code
                    startOTPTimer();

                    var phone = $('#billing_phone').val();
                    var $phoneField = $('#billing_phone_field');
                    var $otpSection = $('#whatsapp-otp-verification');

                    // Remove any existing errors
                    $('.woocommerce-error').remove();
                    $phoneField.removeClass('woocommerce-invalid woocommerce-invalid-required-field');


                    if (!phone) {
                        // Add error to the top of the form
                        $('form.checkout').before('<ul class="woocommerce-error" role="alert"><li data-id="billing_phone"><a href="#billing_phone"><strong>Phone Number</strong> is a required field.</a></li></ul>');

                        // Add error class to the field
                        $phoneField.addClass('woocommerce-invalid woocommerce-invalid-required-field');

                        // Scroll to error
                        $('html, body').animate({
                            scrollTop: $('.woocommerce-error').offset().top - 100
                        }, 500);

                        return;
                    }

                    // var phone = $('#billing_phone').val();
                    // if (!phone) {
                    //     alert('Please enter your phone number first');
                    //     return;
                    // }

                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'send_whatsapp_otp',
                            phone: phone
                        },
                        beforeSend: function() {
                            $otpSection.block({
                                message: null,
                                overlayCSS: {
                                    background: '#fff',
                                    opacity: 0.6
                                }
                            });
                        },
                        success: function(response) {
                            $otpSection.unblock();
                            if (response.success) {
                                // alert('OTP sent to your WhatsApp number');
                                // Show success message
                                $('form.checkout').before('<div class="woocommerce-message" role="alert">' + (response.data.message || 'OTP sent successfully') + '</div>');
                                startOTPTimer();
                            } else {
                                // alert('Failed to send OTP. Please try again.');
                                // Show error at the top
                                $('form.checkout').before('<ul class="woocommerce-error" role="alert"><li data-id="billing_phone"><a href="#billing_phone"><strong>WhatsApp Verification Error:</strong> ' + (response.data.message || 'Failed to send OTP') + '</a></li></ul>');

                                // Add error class to phone field
                                $phoneField.addClass('woocommerce-invalid');

                                // Scroll to error
                                $('html, body').animate({
                                    scrollTop: $('.woocommerce-error').offset().top - 100
                                }, 500);
                            }
                        },
                        error: function(xhr, status, error) {
                            // alert('Network error occurred. Please try again.');
                            $otpSection.unblock();
                            $('form.checkout').before('<ul class="woocommerce-error" role="alert"><li data-id="billing_phone"><a href="#billing_phone"><strong>Network Error:</strong> Please try again</a></li></ul>');

                            // Scroll to error
                            $('html, body').animate({
                                scrollTop: $('.woocommerce-error').offset().top - 100
                            }, 500);
                        }
                    });
                });

                // Your existing verify OTP code
                // $('#verify-otp').click(function() {
                //     // After successful verification
                //     $('#billing_phone').prop('readonly', true);
                //     $('#whatsapp-otp-verification').find('button, input[type="text"]').hide();
                // });




                $('#verify-otp').click(function() {
                    var otp = $('#whatsapp-otp').val();
                    var $otpField = $('#whatsapp-otp').parent();
                    var $otpSection = $('#whatsapp-otp-verification');

                    // Remove any existing errors
                    $('.woocommerce-error').remove();
                    $otpField.removeClass('woocommerce-invalid');

                    if (!otp) {
                        $('form.checkout').before('<ul class="woocommerce-error" role="alert"><li data-id="whatsapp-otp"><a href="#whatsapp-otp"><strong>OTP</strong> is required.</a></li></ul>');
                        $otpField.addClass('woocommerce-invalid');
                        return;
                    }

                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'verify_whatsapp_otp',
                            otp: otp,
                            billing_phone: $('#billing_phone').val(),
                        },
                        beforeSend: function() {
                            $otpSection.block({
                                message: null,
                                overlayCSS: {
                                    background: '#fff',
                                    opacity: 0.6
                                }
                            });
                        },
                        success: function(response) {
                            $otpSection.unblock();

                            if (response.success) {
                                $('#phone-verified').val('1');
                                $('form.checkout').before('<div class="woocommerce-message" role="alert">Phone number verified successfully</div>');
                                $('#billing_phone').prop('readonly', true);
                                $('#whatsapp-otp-verification').find('button, input[type="text"]').hide();

                                // Add verified class
                                $('#billing_phone_field').addClass('woocommerce-validated');
                            } else {
                                $('form.checkout').before('<ul class="woocommerce-error" role="alert"><li data-id="whatsapp-otp"><a href="#whatsapp-otp"><strong>Invalid OTP:</strong> Please try again</a></li></ul>');
                                $otpField.addClass('woocommerce-invalid');

                                // Scroll to error
                                $('html, body').animate({
                                    scrollTop: $('.woocommerce-error').offset().top - 100
                                }, 500);
                            }
                        },
                        error: function() {
                            $otpSection.unblock();
                            $('form.checkout').before('<ul class="woocommerce-error" role="alert"><li data-id="whatsapp-otp"><a href="#whatsapp-otp"><strong>Network Error:</strong> Please try again</a></li></ul>');
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


        // echo "<pre>";
        // print_r($response);exit;


        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'message' => 'Failed to connect to WhatsApp API: ' . $response->get_error_message()
            ));
        }



        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);





        // Check for various error conditions
        if ($status_code !== 200) {
            $error_message = 'WhatsApp API Error';

            if (isset($body['error']['message'])) {
                $error_message = $body['error']['message'];
            }

            // Log error for admin
            error_log('WhatsApp API Error: ' . print_r($body, true));

            wp_send_json_error(array(
                'message' => $error_message
            ));
        }

        // Success response
        wp_send_json_success(array(
            'message' => 'OTP sent successfully'
        ));
    }

    public function verify_whatsapp_otp()
    {
        $submitted_otp = sanitize_text_field($_POST['otp']);
        $billing_phone = sanitize_text_field($_POST['billing_phone']);
        $stored_otp = WC()->session->get('whatsapp_otp');

        if ($submitted_otp == $stored_otp) {
            WC()->session->set('verified_phone_number', $billing_phone);
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

    public function save_verified_phone_to_order($order_id)
    {
        $verified_phone = WC()->session->get('verified_phone_number');
        if ($verified_phone && $verified_phone == $_POST['billing_phone']) {
            // update_post_meta($order_id, '_verified_phone', $verified_phone);
            $order = wc_get_order($order_id);
            $order->update_meta_data('_verified_phone', sanitize_text_field($_POST['billing_phone']));
            $order->save_meta_data();
        }
    }

    public function save_verified_phone_to_user($user_id)
    {
        $verified_phone = WC()->session->get('verified_phone_number');
        if ($verified_phone) {
            update_user_meta($user_id, 'verified_phone', $verified_phone);
        }
    }

    public function add_new_column_phone_vrf($columns)
    {
        // $columns['phone_verified'] = 'Phone Verified';
        // return $columns;
        $new_columns = [];
        foreach ($columns as $column_name => $column_info) {
            $new_columns[$column_name] = $column_info;
            if ('order_number' === $column_name) {    // Change order_status to manage column orders
                $new_columns['phone_verified'] = 'Phone Verified';
            }
        }
        return $new_columns;
    }

    public function phone_verification_column_val($column, $order)
    {
        if ($column == 'phone_verified') {
            $verified_phone = get_post_meta($order->get_id(), '_verified_phone', true);
            echo $verified_phone ? ' ✅ ' . esc_html($verified_phone) : ' ❌ ';
        }
    }

    public function check_phone_verification()
    {
        $user_id = get_current_user_id();
        $verified_phone = get_user_meta($user_id, 'verified_phone', true);

        if (!$verified_phone) {
            wc_add_notice('Please verify your phone number in your account settings before checkout.', 'error');
            wp_safe_redirect(wc_get_page_permalink('myaccount'));
            exit;
        }
    }

    // Add this to prevent multiple verifications
    public function remove_duplicate_verification()
    {
        remove_action('woocommerce_after_checkout_billing_form', array($this, 'add_otp_field'));
    }

    public function add_otp_field_after_phone($checkout)
    {
    ?>
        <script>
            jQuery(document).ready(function($) {
                // Move OTP verification after phone field
                $('#whatsapp-otp-verification').insertAfter($('#billing_phone_field'));
            });
        </script>
<?php
        $this->add_otp_field($checkout);
    }

    public function add_phone_verification_to_account()
    {
        $user_id = get_current_user_id();
        $verified_phone = get_user_meta($user_id, 'verified_phone', true);

        woocommerce_form_field('account_phone', array(
            'type' => 'tel',
            'required' => true,
            'label' => 'Phone Number',
            'class' => array('form-row-wide'),
            'default' => $verified_phone
        ));

        $this->add_otp_field(null);
    }

    public function save_account_phone_verification($user_id)
    {
        if (!empty($_POST['phone_verified'])) {
            update_user_meta($user_id, 'verified_phone', sanitize_text_field($_POST['billing_phone']));
        }
    }
}

// Initialize the plugin
add_action('plugins_loaded', function () {
    new WC_WhatsApp_OTP_Verification();
});
