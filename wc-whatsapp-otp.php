<?php

/**
 * Plugin Name: WooCommerce WhatsApp OTP Verification
 * Description: Verifies customer phone numbers via WhatsApp OTP during checkout
 * Version: 1.0
 * Author: DigvijaySinh Rathod
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

        /**
         * Add country code to phone field and validate phone fields have country codes
         */

        add_action('wp_footer', array($this, 'add_country_code_to_phone'));


        add_action('wp_footer', array($this, 'add_country_code_to_checkout'));



        // add_action('wp_ajax_generate_long_lived_token', array($this, 'generate_long_lived_token'));

        // add_action('init', array($this, 'check_token_expiry'));
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






            /* Custom widths for OTP verification fields */
            .woocommerce-checkout .form-row.form-row-first.otp-field {
                width: 70%;
                /* Make input field take more space */
                float: left;
                clear: both;
            }

            .woocommerce-checkout .form-row.form-row-last.verify-button {
                width: 28%;
                /* Make button column narrower */
                float: right;
            }

            /* Add some margin between elements */
            .woocommerce-checkout #whatsapp-otp {
                width: 100%;
                height: 40px;
                /* Match button height */
            }

            .woocommerce-checkout #verify-otp {
                width: 100%;
                height: 40px;
            }

            /* Clear the float after these elements */
            .woocommerce-checkout .otp-verification-container::after {
                content: "";
                display: table;
                clear: both;
            }

            button#resend-otp[disabled] {
                cursor: not-allowed;
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

            // update_option('whatsapp_system_user_token', sanitize_text_field($_POST['whatsapp_system_user_token']));
            // update_option('whatsapp_long_lived_token', sanitize_text_field($_POST['whatsapp_long_lived_token']));
        }

        // $system_user_token = get_option('whatsapp_system_user_token');
        // $long_lived_token = get_option('whatsapp_long_lived_token');
        // $token_expiry = get_option('whatsapp_token_expiry');
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
                        <th>WhatsApp Access Token Short load</th>
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
                    <!-- <tr>
                        <th>App Secret</th>
                        <td>
                            <input type="password" name="whatsapp_system_user_token"
                                value="<?php echo esc_attr($system_user_token); ?>" class="regular-text">
                            <p class="description">Your Meta System User Access Token</p>
                        </td>
                    </tr> -->
                    <!-- <tr>
                        <th>Long-Lived Access Token</th>
                        <td>
                            <input type="password" name="whatsapp_long_lived_token"
                                value="<?php echo esc_attr($long_lived_token); ?>" class="regular-text" readonly>
                            <button type="button" id="generate_long_lived_token" class="button">Generate Long-Lived Token</button>
                            <?php if ($token_expiry): ?>
                                <p class="description">Expires: <?php echo date('Y-m-d H:i:s', $token_expiry); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr> -->
                </table>
                <p class="submit">
                    <input type="submit" name="save_settings" class="button-primary" value="Save Settings">
                </p>
            </form>
        </div>
        <script>
            /*
            jQuery(document).ready(function($) {
                $('#generate_long_lived_token').click(function() {
                    var button = $(this);
                    button.prop('disabled', true).text('Generating...');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'generate_long_lived_token'
                        },
                        success: function(response) {
                            if (response.success) {
                                $('input[name="whatsapp_long_lived_token"]').val(response.data.token);
                                button.closest('td').find('.description').text('Expires: ' + response.data.expiry);
                                alert('Token generated successfully!');
                            } else {
                                alert('Error: ' + response.data);
                            }
                        },
                        error: function() {
                            alert('Network error occurred');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('Generate Long-Lived Token');
                        }
                    });
                });
            });
            */
        </script>
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
                    <button type="button" id="send-otp" class="button">Send OTP to Whatsapp</button>
                    <button type="button" id="resend-otp" class="button" style="display:none;">Resend OTP</button>
                </p>
                <p class="form-row form-row-first otp-field">
                    <!-- <label>Enter OTP</label> -->
                    <input type="text" id="whatsapp-otp" name="whatsapp_otp" placeholder="Enter code received in Whatsapp">
                </p>
                <p class="form-row form-row-last verify-button">
                    <button type="button" id="verify-otp" class="button">Verify OTP</button>
                </p>
            <?php endif; ?>
            <input type="hidden" id="phone-verified" name="phone_verified" value="<?php echo $verified_phone ? '1' : '0'; ?>">
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Add resend OTP functionality
                let otpTimer;



                // Function to validate Indian phone numbers with required +91 prefix
                function validateIndianPhoneNumber(phone) {
                    // Remove spaces, dashes, and parentheses
                    phone = phone.replace(/[\s\-()]/g, '');

                    // Check if the number starts with +91 followed by 10 digits
                    // Indian mobile numbers are 10 digits and typically start with 6, 7, 8, or 9
                    const indianPhoneRegex = /^\+91[6-9]\d{9}$/;
                    return indianPhoneRegex.test(phone);
                }


                // Add custom validation to the checkout process
                $(document).on('checkout_error', function() {
                    // Clear previous error messages
                    $('.phone-validation-error').remove();
                });



                // Real-time validation as user types
                $('#billing_phone').on('input', function() {
                    const phoneNumber = $(this).val();
                    const userCountry = window.wc_checkout_params && window.wc_checkout_params.user_country ?
                        window.wc_checkout_params.user_country :
                        null;

                    // Only validate for Indian users
                    if (userCountry === 'IN') {
                        if (phoneNumber && !validateIndianPhoneNumber(phoneNumber)) {
                            // Show inline validation message
                            if (!$(this).next('.phone-field-error').length) {
                                $(this).after('<div class="woocommerce-error phone-field-error">Please enter a valid Indian phone number with +91 prefix</div>');
                            }
                        } else {
                            // Remove error message if valid
                            $(this).next('.phone-field-error').remove();
                        }
                    }
                });


                // Optional: Add placeholder to indicate required format
                if (window.wc_checkout_params && window.wc_checkout_params.user_country === 'IN') {
                    $('#billing_phone').attr('placeholder', '+91XXXXXXXXXX');
                }

                // Declare a global variable for the timer to avoid scope issues
                // let otpTimer;

                function startOTPTimer() {
                    // Clear any existing timer to prevent multiple timers running
                    if (otpTimer) {
                        clearInterval(otpTimer);
                    }

                    let seconds = 30;

                    // Make sure elements exist before trying to manipulate them
                    if ($('#send-otp').length) {
                        $('#send-otp').hide();
                    }

                    if ($('#resend-otp').length) {
                        $('#resend-otp')
                            .show()
                            .prop('disabled', true)
                            .text(`Resend OTP in ${seconds}s`);
                    } else {
                        console.error("Element with ID 'resend-otp' not found");
                        return; // Exit the function if the element doesn't exist
                    }

                    // Create the timer
                    otpTimer = setInterval(function() {
                        seconds--;
                        console.log("Timer: " + seconds); // Debug logging

                        if (seconds <= 0) {
                            clearInterval(otpTimer);
                            $('#resend-otp').prop('disabled', false).text('Resend OTP');
                        } else {
                            $('#resend-otp').text(`Resend OTP in ${seconds}s`);
                        }
                    }, 1000);
                }

                function stopOTPTimer() {
                    // Always clear the interval when stopping the timer
                    if (otpTimer) {
                        clearInterval(otpTimer);
                    }

                    if ($('#resend-otp').length) {
                        $('#resend-otp').prop('disabled', false).text('Resend OTP');
                    }

                    // Uncomment these if you want to show the send-otp button again
                    // if ($('#resend-otp').length) $('#resend-otp').hide();
                    // if ($('#send-otp').length) $('#send-otp').show();
                }

                $('#send-otp, #resend-otp').click(function() {

                    if (!validatePhoneCountryCode()) {
                        return;
                    }
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
                                $('form.checkout .form-row.otp-field').before('<div class="woocommerce-message" role="alert">' + (response.data.message || 'OTP sent on WhatsApp successfully') + '</div>');
                                // startOTPTimer();
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





                // Validation for country code
                // $('form.checkout').on('checkout_place_order', function() {
                //     return validatePhoneCountryCode();
                // });

                // // Also validate on input change
                // $('#billing_phone').on('change', function() {
                //     validatePhoneCountryCode(true);
                // });

                function validatePhoneCountryCode(isChange = false) {
                    var phone = $('#billing_phone').val().trim();

                    // Check if phone starts with a plus sign
                    if (phone === '' || phone.charAt(0) !== '+') {
                        // if (!isChange) {
                        // Show error message
                        if (!$('.phone-country-code-error').length) {
                            $('#billing_phone_field').append('<div class="phone-country-code-error woocommerce-error">Please include a country code starting with + in your phone number.</div>');
                        }
                        // }
                        return false; // Return false to prevent form submission, true if just checking
                    }
                    //  else {
                    //     // Remove error message if it exists
                    //     $('.phone-country-code-error').remove();
                    //     return true;
                    // }


                    // Check if the user's country is India (using PHP GEOIP data)
                    // Note: This part requires server-side support to inject the country code
                    const userCountry = window.wc_checkout_params && window.wc_checkout_params.user_country ?
                        window.wc_checkout_params.user_country :
                        null;


                    if (userCountry === 'IN') {
                        // Validate the phone number for Indian format with +91 prefix
                        if (!validateIndianPhoneNumber(phone)) {
                            // Display error and prevent form submission
                            $('.woocommerce-notices-wrapper').prepend(
                                '<div class="woocommerce-error phone-validation-error">' +
                                'Please enter a valid Indian phone number with +91 prefix (e.g., +91XXXXXXXXXX)' +
                                '</div>'
                            );

                            // Scroll to the error message
                            $('html, body').animate({
                                scrollTop: $('.woocommerce-notices-wrapper').offset().top - 100
                            }, 500);

                            return false;
                        }
                    }

                    return true;
                }

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
                                $('form.checkout .form-row.verify-button').after('<div class="woocommerce-message" role="alert">Phone number verified successfully</div>');
                                $('#billing_phone').prop('readonly', true);
                                $('#whatsapp-otp-verification').find('button, input[type="text"]').hide();

                                // Add verified class
                                $('#billing_phone_field').addClass('woocommerce-validated');
                            } else {
                                $('form.checkout p.verify-button').after('<ul class="woocommerce-error" role="alert"><li data-id="whatsapp-otp"><a href="#whatsapp-otp"><strong>Invalid OTP:</strong> Please try again</a></li></ul>');
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

        // $token = get_option('whatsapp_long_lived_token');
        // if (!$token) {
        //     $token = $this->whatsapp_token; // fallback to regular token
        // }

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
            'message' => 'OTP sent on WhatsApp successfully'
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
        // Get the phone number
        $phone = isset($_POST['billing_phone']) ? sanitize_text_field($_POST['billing_phone']) : '';

        // Check if phone starts with a plus sign
        if ($phone === '' || $phone[0] !== '+') {
            wc_add_notice('Please include a country code starting with + in your phone number.', 'error');
        }

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

    function add_country_code_to_phone()
    {
        // Only run on checkout page
        if (!is_checkout()) {
            return;
        }

        // Get country code from server
        $country_code = isset($_SERVER['GEOIP_COUNTRY_CODE']) ? $_SERVER['GEOIP_COUNTRY_CODE'] : 'IN';

        // Map of country codes to phone prefixes (all countries)
        $phone_prefixes = array(
            'AF' => '+93',  // Afghanistan
            'AL' => '+355', // Albania
            'DZ' => '+213', // Algeria
            'AS' => '+1',   // American Samoa
            'AD' => '+376', // Andorra
            'AO' => '+244', // Angola
            'AI' => '+1',   // Anguilla
            'AQ' => '+672', // Antarctica
            'AG' => '+1',   // Antigua and Barbuda
            'AR' => '+54',  // Argentina
            'AM' => '+374', // Armenia
            'AW' => '+297', // Aruba
            'AU' => '+61',  // Australia
            'AT' => '+43',  // Austria
            'AZ' => '+994', // Azerbaijan
            'BS' => '+1',   // Bahamas
            'BH' => '+973', // Bahrain
            'BD' => '+880', // Bangladesh
            'BB' => '+1',   // Barbados
            'BY' => '+375', // Belarus
            'BE' => '+32',  // Belgium
            'BZ' => '+501', // Belize
            'BJ' => '+229', // Benin
            'BM' => '+1',   // Bermuda
            'BT' => '+975', // Bhutan
            'BO' => '+591', // Bolivia
            'BA' => '+387', // Bosnia and Herzegovina
            'BW' => '+267', // Botswana
            'BV' => '+47',  // Bouvet Island
            'BR' => '+55',  // Brazil
            'IO' => '+246', // British Indian Ocean Territory
            'BN' => '+673', // Brunei
            'BG' => '+359', // Bulgaria
            'BF' => '+226', // Burkina Faso
            'BI' => '+257', // Burundi
            'KH' => '+855', // Cambodia
            'CM' => '+237', // Cameroon
            'CA' => '+1',   // Canada
            'CV' => '+238', // Cape Verde
            'KY' => '+1',   // Cayman Islands
            'CF' => '+236', // Central African Republic
            'TD' => '+235', // Chad
            'CL' => '+56',  // Chile
            'CN' => '+86',  // China
            'CX' => '+61',  // Christmas Island
            'CC' => '+61',  // Cocos (Keeling) Islands
            'CO' => '+57',  // Colombia
            'KM' => '+269', // Comoros
            'CG' => '+242', // Congo
            'CD' => '+243', // Congo, Democratic Republic of the
            'CK' => '+682', // Cook Islands
            'CR' => '+506', // Costa Rica
            'CI' => '+225', // Côte d'Ivoire
            'HR' => '+385', // Croatia
            'CU' => '+53',  // Cuba
            'CY' => '+357', // Cyprus
            'CZ' => '+420', // Czech Republic
            'DK' => '+45',  // Denmark
            'DJ' => '+253', // Djibouti
            'DM' => '+1',   // Dominica
            'DO' => '+1',   // Dominican Republic
            'EC' => '+593', // Ecuador
            'EG' => '+20',  // Egypt
            'SV' => '+503', // El Salvador
            'GQ' => '+240', // Equatorial Guinea
            'ER' => '+291', // Eritrea
            'EE' => '+372', // Estonia
            'ET' => '+251', // Ethiopia
            'FK' => '+500', // Falkland Islands
            'FO' => '+298', // Faroe Islands
            'FJ' => '+679', // Fiji
            'FI' => '+358', // Finland
            'FR' => '+33',  // France
            'GF' => '+594', // French Guiana
            'PF' => '+689', // French Polynesia
            'TF' => '+262', // French Southern Territories
            'GA' => '+241', // Gabon
            'GM' => '+220', // Gambia
            'GE' => '+995', // Georgia
            'DE' => '+49',  // Germany
            'GH' => '+233', // Ghana
            'GI' => '+350', // Gibraltar
            'GR' => '+30',  // Greece
            'GL' => '+299', // Greenland
            'GD' => '+1',   // Grenada
            'GP' => '+590', // Guadeloupe
            'GU' => '+1',   // Guam
            'GT' => '+502', // Guatemala
            'GG' => '+44',  // Guernsey
            'GN' => '+224', // Guinea
            'GW' => '+245', // Guinea-Bissau
            'GY' => '+592', // Guyana
            'HT' => '+509', // Haiti
            'HM' => '+672', // Heard Island and McDonald Islands
            'VA' => '+39',  // Holy See (Vatican City)
            'HN' => '+504', // Honduras
            'HK' => '+852', // Hong Kong
            'HU' => '+36',  // Hungary
            'IS' => '+354', // Iceland
            'IN' => '+91',  // India
            'ID' => '+62',  // Indonesia
            'IR' => '+98',  // Iran
            'IQ' => '+964', // Iraq
            'IE' => '+353', // Ireland
            'IM' => '+44',  // Isle of Man
            'IL' => '+972', // Israel
            'IT' => '+39',  // Italy
            'JM' => '+1',   // Jamaica
            'JP' => '+81',  // Japan
            'JE' => '+44',  // Jersey
            'JO' => '+962', // Jordan
            'KZ' => '+7',   // Kazakhstan
            'KE' => '+254', // Kenya
            'KI' => '+686', // Kiribati
            'KP' => '+850', // North Korea
            'KR' => '+82',  // South Korea
            'KW' => '+965', // Kuwait
            'KG' => '+996', // Kyrgyzstan
            'LA' => '+856', // Laos
            'LV' => '+371', // Latvia
            'LB' => '+961', // Lebanon
            'LS' => '+266', // Lesotho
            'LR' => '+231', // Liberia
            'LY' => '+218', // Libya
            'LI' => '+423', // Liechtenstein
            'LT' => '+370', // Lithuania
            'LU' => '+352', // Luxembourg
            'MO' => '+853', // Macao
            'MK' => '+389', // North Macedonia
            'MG' => '+261', // Madagascar
            'MW' => '+265', // Malawi
            'MY' => '+60',  // Malaysia
            'MV' => '+960', // Maldives
            'ML' => '+223', // Mali
            'MT' => '+356', // Malta
            'MH' => '+692', // Marshall Islands
            'MQ' => '+596', // Martinique
            'MR' => '+222', // Mauritania
            'MU' => '+230', // Mauritius
            'YT' => '+262', // Mayotte
            'MX' => '+52',  // Mexico
            'FM' => '+691', // Micronesia
            'MD' => '+373', // Moldova
            'MC' => '+377', // Monaco
            'MN' => '+976', // Mongolia
            'ME' => '+382', // Montenegro
            'MS' => '+1',   // Montserrat
            'MA' => '+212', // Morocco
            'MZ' => '+258', // Mozambique
            'MM' => '+95',  // Myanmar
            'NA' => '+264', // Namibia
            'NR' => '+674', // Nauru
            'NP' => '+977', // Nepal
            'NL' => '+31',  // Netherlands
            'NC' => '+687', // New Caledonia
            'NZ' => '+64',  // New Zealand
            'NI' => '+505', // Nicaragua
            'NE' => '+227', // Niger
            'NG' => '+234', // Nigeria
            'NU' => '+683', // Niue
            'NF' => '+672', // Norfolk Island
            'MP' => '+1',   // Northern Mariana Islands
            'NO' => '+47',  // Norway
            'OM' => '+968', // Oman
            'PK' => '+92',  // Pakistan
            'PW' => '+680', // Palau
            'PS' => '+970', // Palestine
            'PA' => '+507', // Panama
            'PG' => '+675', // Papua New Guinea
            'PY' => '+595', // Paraguay
            'PE' => '+51',  // Peru
            'PH' => '+63',  // Philippines
            'PN' => '+64',  // Pitcairn
            'PL' => '+48',  // Poland
            'PT' => '+351', // Portugal
            'PR' => '+1',   // Puerto Rico
            'QA' => '+974', // Qatar
            'RE' => '+262', // Réunion
            'RO' => '+40',  // Romania
            'RU' => '+7',   // Russia
            'RW' => '+250', // Rwanda
            'BL' => '+590', // Saint Barthélemy
            'SH' => '+290', // Saint Helena
            'KN' => '+1',   // Saint Kitts and Nevis
            'LC' => '+1',   // Saint Lucia
            'MF' => '+590', // Saint Martin
            'PM' => '+508', // Saint Pierre and Miquelon
            'VC' => '+1',   // Saint Vincent and the Grenadines
            'WS' => '+685', // Samoa
            'SM' => '+378', // San Marino
            'ST' => '+239', // Sao Tome and Principe
            'SA' => '+966', // Saudi Arabia
            'SN' => '+221', // Senegal
            'RS' => '+381', // Serbia
            'SC' => '+248', // Seychelles
            'SL' => '+232', // Sierra Leone
            'SG' => '+65',  // Singapore
            'SX' => '+1',   // Sint Maarten
            'SK' => '+421', // Slovakia
            'SI' => '+386', // Slovenia
            'SB' => '+677', // Solomon Islands
            'SO' => '+252', // Somalia
            'ZA' => '+27',  // South Africa
            'GS' => '+500', // South Georgia and the South Sandwich Islands
            'SS' => '+211', // South Sudan
            'ES' => '+34',  // Spain
            'LK' => '+94',  // Sri Lanka
            'SD' => '+249', // Sudan
            'SR' => '+597', // Suriname
            'SJ' => '+47',  // Svalbard and Jan Mayen
            'SZ' => '+268', // Eswatini
            'SE' => '+46',  // Sweden
            'CH' => '+41',  // Switzerland
            'SY' => '+963', // Syria
            'TW' => '+886', // Taiwan
            'TJ' => '+992', // Tajikistan
            'TZ' => '+255', // Tanzania
            'TH' => '+66',  // Thailand
            'TL' => '+670', // Timor-Leste
            'TG' => '+228', // Togo
            'TK' => '+690', // Tokelau
            'TO' => '+676', // Tonga
            'TT' => '+1',   // Trinidad and Tobago
            'TN' => '+216', // Tunisia
            'TR' => '+90',  // Turkey
            'TM' => '+993', // Turkmenistan
            'TC' => '+1',   // Turks and Caicos Islands
            'TV' => '+688', // Tuvalu
            'UG' => '+256', // Uganda
            'UA' => '+380', // Ukraine
            'AE' => '+971', // United Arab Emirates
            'GB' => '+44',  // United Kingdom
            'US' => '+1',   // United States
            'UM' => '+1',   // United States Minor Outlying Islands
            'UY' => '+598', // Uruguay
            'UZ' => '+998', // Uzbekistan
            'VU' => '+678', // Vanuatu
            'VE' => '+58',  // Venezuela
            'VN' => '+84',  // Vietnam
            'VG' => '+1',   // Virgin Islands, British
            'VI' => '+1',   // Virgin Islands, U.S.
            'WF' => '+681', // Wallis and Futuna
            'EH' => '+212', // Western Sahara
            'YE' => '+967', // Yemen
            'ZM' => '+260', // Zambia
            'ZW' => '+263', // Zimbabwe
        );

        // Get the appropriate phone prefix
        $prefix = isset($phone_prefixes[$country_code]) ? $phone_prefixes[$country_code] : '';

    ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Only add prefix if field is empty
                if ($('#billing_phone').val() === '') {
                    $('#billing_phone').val('<?php echo $prefix; ?>');
                }

                // Focus cursor at the end of the input
                // var input = document.getElementById('billing_phone');
                // if (input) {
                //     input.focus();
                //     var val = input.value;
                //     input.value = '';
                //     input.value = val;
                // }

                // // Validation for country code
                // $('form.checkout').on('checkout_place_order', function() {
                //     return validatePhoneCountryCode();
                // });

                // // Also validate on input change
                // $('#billing_phone').on('change', function() {
                //     validatePhoneCountryCode(true);
                // });

                // function validatePhoneCountryCode(isChange = false) {
                //     var phone = $('#billing_phone').val().trim();

                //     // Check if phone starts with a plus sign
                //     if (phone === '' || phone.charAt(0) !== '+') {
                //         if (!isChange) {
                //             // Show error message
                //             if (!$('.phone-country-code-error').length) {
                //                 $('#billing_phone_field').append('<div class="phone-country-code-error woocommerce-error" style="color:red;">Please include a country code starting with + in your phone number.</div>');
                //             }
                //         }
                //         return isChange; // Return false to prevent form submission, true if just checking
                //     } else {
                //         // Remove error message if it exists
                //         $('.phone-country-code-error').remove();
                //         return true;
                //     }
                // }
            });
        </script>
<?php
    }


    function add_country_code_to_checkout()
    {
        if (is_checkout()) {
            $country_code = isset($_SERVER['GEOIP_COUNTRY_CODE']) ? $_SERVER['GEOIP_COUNTRY_CODE'] : 'IN';

            echo '<script type="text/javascript">
                window.wc_checkout_params = window.wc_checkout_params || {};
                window.wc_checkout_params.user_country = "' . esc_js($country_code) . '";
            </script>';
        }
    }

    // public function generate_long_lived_token()
    // {
    //     if (!current_user_can('manage_options')) {
    //         wp_send_json_error('Unauthorized');
    //     }

    //     $system_user_token = get_option('whatsapp_system_user_token');

    //     $response = wp_remote_get(
    //         'https://graph.facebook.com/v22.0/oauth/access_token?' . http_build_query([
    //             'grant_type' => 'fb_exchange_token',
    //             'client_id' => $this->whatsapp_business_id, // Your app ID
    //             'client_secret' => get_option('whatsapp_app_secret'), // Your app secret
    //             'fb_exchange_token' => $system_user_token
    //         ])
    //     );

    //     if (is_wp_error($response)) {
    //         wp_send_json_error($response->get_error_message());
    //     }

    //     $body = json_decode(wp_remote_retrieve_body($response), true);

    //     if (!empty($body['access_token'])) {
    //         update_option('whatsapp_long_lived_token', $body['access_token']);
    //         update_option('whatsapp_token_expiry', time() + (intval($body['expires_in']) ?? 5184000)); // Default 60 days

    //         wp_send_json_success([
    //             'message' => 'Long-lived token generated successfully',
    //             'expiry' => date('Y-m-d H:i:s', time() + (intval($body['expires_in']) ?? 5184000))
    //         ]);
    //     } else {
    //         wp_send_json_error('Failed to generate token: ' . ($body['error']['message'] ?? 'Unknown error'));
    //     }
    // }

    // public function check_token_expiry()
    // {
    //     $expiry = get_option('whatsapp_token_expiry');

    //     // Refresh token if it expires in less than 7 days
    //     if ($expiry && ($expiry - time()) < (7 * 24 * 60 * 60)) {
    //         $this->refresh_long_lived_token();
    //     }
    // }

    // private function refresh_long_lived_token()
    // {
    //     $current_token = get_option('whatsapp_long_lived_token');

    //     $response = wp_remote_get(
    //         'https://graph.facebook.com/v22.0/oauth/access_token?' . http_build_query([
    //             'grant_type' => 'fb_exchange_token',
    //             'client_id' => $this->whatsapp_business_id,
    //             'client_secret' => get_option('whatsapp_app_secret'),
    //             'fb_exchange_token' => $current_token
    //         ])
    //     );

    //     if (!is_wp_error($response)) {
    //         $body = json_decode(wp_remote_retrieve_body($response), true);

    //         if (!empty($body['access_token'])) {
    //             update_option('whatsapp_long_lived_token', $body['access_token']);
    //             update_option('whatsapp_token_expiry', time() + (intval($body['expires_in']) ?? 5184000));
    //         }
    //     }
    // }
}

// Initialize the plugin
add_action('plugins_loaded', function () {
    new WC_WhatsApp_OTP_Verification();
});
