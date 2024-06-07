<?php
require 'vendor/autoload.php';


use mervick\aesEverywhere\AES256;

use OTPHP\TOTP;


class WC_Gateway_Barneys extends WC_Payment_Gateway {

    /**
     * Gateway instructions that will be added to the thank you page and emails.
     *
     * @var string
     */
    public $instructions;

    /**
     * Enable for shipping methods.
     *
     * @var array
     */
    public $enable_for_methods;

    /**
     * Enable for virtual products.
     *
     * @var bool
     */
    public $enable_for_virtual;

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        // Setup general properties.
        $this->setup_properties();

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Get settings.
        $this->title              = $this->get_option( 'title' );
        $this->description        = $this->get_option( 'description' );
        $this->default_secret_key = $this->get_option( 'default_secret_key' );
        $this->api_username       = $this->get_option( 'api_username' );
        $this->api_key            = $this->get_option( 'api_key' );
        $this->instructions       = $this->get_option( 'instructions' );
        $this->enable_for_methods = $this->get_option( 'enable_for_methods', array() );
        $this->enable_for_virtual = $this->get_option( 'enable_for_virtual', 'yes' ) === 'yes';

        // Actions.
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
        add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'change_payment_complete_order_status' ), 10, 3 );

        // Customer Emails.
        add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
        

    }

    /**
     * Setup general properties for the gateway.
     */
    protected function setup_properties() {
        $this->id                 = 'barneys';
        $this->icon               = apply_filters( 'woocommerce_barneys_icon', '' );
        $this->method_title       = __( 'Barneys Payment Gateway', 'woocommerce' );
        $this->default_secret_key = __( 'Add Default Secret Key', 'woocommerce' );
        $this->api_username       = __( 'Add API username', 'woocommerce' );
        $this->api_key            = __( 'Add API Key', 'woocommerce' );
        $this->method_description = __( 'Barneys local content payment systems.', 'woocommerce' );
        $this->has_fields         = false;
    }

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled'            => array(
                'title'       => __( 'Enable/Disable', 'woocommerce' ),
                'label'       => __( 'Enable or Disable Barneys Payments', 'woocommerce' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
            ),
            'title'              => array(
                'title'       => __( 'Title', 'woocommerce' ),
                'type'        => 'safe_text',
                'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce' ),
                'default'     => __( 'Barneys Payments Gateway', 'woocommerce' ),
                'desc_tip'    => true,
            ),
            'api_username' => array(
                'title'       => __( 'Add API Username', 'woocommerce' ),
                'type'        => 'safe_text',
                'description' => __( 'Get this information in payment gateway provider', 'woocommerce' ),
                // 'default'     => __( 'ZJGAXJIPORDSMGQE', 'woocommerce' ),
                'desc_tip'    => true,
            ),
            'api_key' => array(
                'title'       => __( 'Add API Key', 'woocommerce' ),
                'type'        => 'safe_text',
                'description' => __( 'Get this information in payment gateway provider', 'woocommerce' ),
                // 'default'     => __( 'ZJGAXJIPORDSMGQE', 'woocommerce' ),
                'desc_tip'    => true,
            ),
            'default_secret_key' => array(
                'title'       => __( 'Add Default Secret Key', 'woocommerce' ),
                'type'        => 'safe_text',
                'description' => __( 'Get this information in payment gateway provider', 'woocommerce' ),
                'default'     => __( 'ZJGAXJIPORDSMGQE', 'woocommerce' ),
                'desc_tip'    => true,
            ),
            'description'        => array(
                'title'       => __( 'Description', 'woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description that the customer will see on your website.', 'woocommerce' ),
                'default'     => __( 'Pay with E-wallet', 'woocommerce' ),
                'desc_tip'    => true,
            ),
            'instructions'       => array(
                'title'       => __( 'Instructions', 'woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Instructions that will be added to the thank you page.', 'woocommerce' ),
                'default'     => __( 'Pay with E-wallet.', 'woocommerce' ),
                'desc_tip'    => true,
            ),
            'enable_for_methods' => array(
                'title'             => __( 'Enable for shipping methods', 'woocommerce' ),
                'type'              => 'multiselect',
                'class'             => 'wc-enhanced-select',
                'css'               => 'width: 400px;',
                'default'           => '',
                'description'       => __( 'If Barneys is only available for certain methods, set it up here. Leave blank to enable for all methods.', 'woocommerce' ),
                'options'           => $this->load_shipping_method_options(),
                'desc_tip'          => true,
                'custom_attributes' => array(
                    'data-placeholder' => __( 'Select shipping methods', 'woocommerce' ),
                ),
            ),
            'enable_for_virtual' => array(
                'title'   => __( 'Accept for virtual orders', 'woocommerce' ),
                'label'   => __( 'Accept Barneys if the order is virtual', 'woocommerce' ),
                'type'    => 'checkbox',
                'default' => 'yes',
            ),
        );
    }

    /**
     * Check If The Gateway Is Available For Use.
     *
     * @return bool
     */
    public function is_available() {
        $order          = null;
        $needs_shipping = false;

        // Test if shipping is needed first.
        if ( WC()->cart && WC()->cart->needs_shipping() ) {
            $needs_shipping = true;
        } elseif ( is_page( wc_get_page_id( 'checkout' ) ) && 0 < get_query_var( 'order-pay' ) ) {
            $order_id = absint( get_query_var( 'order-pay' ) );
            $order    = wc_get_order( $order_id );

            // Test if order needs shipping.
            if ( $order && 0 < count( $order->get_items() ) ) {
                foreach ( $order->get_items() as $item ) {
                    $_product = $item->get_product();
                    if ( $_product && $_product->needs_shipping() ) {
                        $needs_shipping = true;
                        break;
                    }
                }
            }
        }

        $needs_shipping = apply_filters( 'woocommerce_cart_needs_shipping', $needs_shipping );

        // Virtual order, with virtual disabled.
        if ( ! $this->enable_for_virtual && ! $needs_shipping ) {
            return false;
        }

        // Only apply if all packages are being shipped via chosen method, or order is virtual.
        if ( ! empty( $this->enable_for_methods ) && $needs_shipping ) {
            $order_shipping_items            = is_object( $order ) ? $order->get_shipping_methods() : false;
            $chosen_shipping_methods_session = WC()->session->get( 'chosen_shipping_methods' );

            if ( $order_shipping_items ) {
                $canonical_rate_ids = $this->get_canonical_order_shipping_item_rate_ids( $order_shipping_items );
            } else {
                $canonical_rate_ids = $this->get_canonical_package_rate_ids( $chosen_shipping_methods_session );
            }

            if ( ! count( $this->get_matching_rates( $canonical_rate_ids ) ) ) {
                return false;
            }
        }

        return parent::is_available();
    }

    /**
     * Checks to see whether or not the admin settings are being accessed by the current request.
     *
     * @return bool
     */
    private function is_accessing_settings() {
        if ( is_admin() ) {
            // phpcs:disable WordPress.Security.NonceVerification
            if ( ! isset( $_REQUEST['page'] ) || 'wc-settings' !== $_REQUEST['page'] ) {
                return false;
            }
            if ( ! isset( $_REQUEST['tab'] ) || 'checkout' !== $_REQUEST['tab'] ) {
                return false;
            }
            if ( ! isset( $_REQUEST['section'] ) || 'barneys' !== $_REQUEST['section'] ) {
                return false;
            }
            // phpcs:enable WordPress.Security.NonceVerification

            return true;
        }

        return false;
    }

    /**
     * Loads all of the shipping method options for the enable_for_methods field.
     *
     * @return array
     */
    private function load_shipping_method_options() {
        // Since this is expensive, we only want to do it if we're actually on the settings page.
        if ( ! $this->is_accessing_settings() ) {
            return array();
        }

        $data_store = WC_Data_Store::load( 'shipping-zone' );
        $raw_zones  = $data_store->get_zones();

        foreach ( $raw_zones as $raw_zone ) {
            $zones[] = new WC_Shipping_Zone( $raw_zone );
        }

        $zones[] = new WC_Shipping_Zone( 0 );

        $options = array();
        foreach ( WC()->shipping()->load_shipping_methods() as $method ) {

            $options[ $method->get_method_title() ] = array();

            // Translators: %1$s shipping method name.
            $options[ $method->get_method_title() ][ $method->id ] = sprintf( __( 'Any &quot;%1$s&quot; method', 'woocommerce' ), $method->get_method_title() );

            foreach ( $zones as $zone ) {

                $shipping_method_instances = $zone->get_shipping_methods();

                foreach ( $shipping_method_instances as $shipping_method_instance_id => $shipping_method_instance ) {

                    if ( $shipping_method_instance->id !== $method->id ) {
                        continue;
                    }

                    $option_id = $shipping_method_instance->get_rate_id();

                    // Translators: %1$s shipping method title, %2$s shipping method id.
                    $option_instance_title = sprintf( __( '%1$s (#%2$s)', 'woocommerce' ), $shipping_method_instance->get_title(), $shipping_method_instance_id );

                    // Translators: %1$s zone name, %2$s shipping method instance name.
                    $option_title = sprintf( __( '%1$s &ndash; %2$s', 'woocommerce' ), $zone->get_id() ? $zone->get_zone_name() : __( 'Other locations', 'woocommerce' ), $option_instance_title );

                    $options[ $method->get_method_title() ][ $option_id ] = $option_title;
                }
            }
        }

        return $options;
    }

    /**
     * Converts the chosen rate IDs generated by Shipping Methods to a canonical 'method_id:instance_id' format.
     *
     * @since  3.4.0
     *
     * @param  array $order_shipping_items  Array of WC_Order_Item_Shipping objects.
     * @return array $canonical_rate_ids    Rate IDs in a canonical format.
     */
    private function get_canonical_order_shipping_item_rate_ids( $order_shipping_items ) {

        $canonical_rate_ids = array();

        foreach ( $order_shipping_items as $order_shipping_item ) {
            $canonical_rate_ids[] = $order_shipping_item->get_method_id() . ':' . $order_shipping_item->get_instance_id();
        }

        return $canonical_rate_ids;
    }

    /**
     * Converts the chosen rate IDs generated by Shipping Methods to a canonical 'method_id:instance_id' format.
     *
     * @since  3.4.0
     *
     * @param  array $chosen_package_rate_ids Rate IDs as generated by shipping methods. Can be anything if a shipping method doesn't honor WC conventions.
     * @return array $canonical_rate_ids  Rate IDs in a canonical format.
     */
    private function get_canonical_package_rate_ids( $chosen_package_rate_ids ) {

        $shipping_packages  = WC()->shipping()->get_packages();
        $canonical_rate_ids = array();

        if ( ! empty( $chosen_package_rate_ids ) && is_array( $chosen_package_rate_ids ) ) {
            foreach ( $chosen_package_rate_ids as $package_key => $chosen_package_rate_id ) {
                if ( ! empty( $shipping_packages[ $package_key ]['rates'][ $chosen_package_rate_id ] ) ) {
                    $chosen_rate          = $shipping_packages[ $package_key ]['rates'][ $chosen_package_rate_id ];
                    $canonical_rate_ids[] = $chosen_rate->get_method_id() . ':' . $chosen_rate->get_instance_id();
                }
            }
        }

        return $canonical_rate_ids;
    }

    /**
     * Indicates whether a rate exists in an array of canonically-formatted rate IDs that activates this gateway.
     *
     * @since  3.4.0
     *
     * @param array $rate_ids Rate ids to check.
     * @return boolean
     */
    private function get_matching_rates( $rate_ids ) {
        // First, match entries in 'method_id:instance_id' format. Then, match entries in 'method_id' format by stripping off the instance ID from the candidates.
        return array_unique( array_merge( array_intersect( $this->enable_for_methods, $rate_ids ), array_intersect( $this->enable_for_methods, array_unique( array_map( 'wc_get_string_before_colon', $rate_ids ) ) ) ) );
    }

    /**
     * Process the payment and return the result.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        
        if ( $order->get_total() > 0 ) {
            $this->barneys_payment_processing();
            // if ('Error' !== $payment_URL) {
            //         return array(
            //             'result'   => 'success',
            //             'redirect' => home_url('/complete-payment?order_id='.$order_id.'&payment_url=' . $payment_url)
            //         );
            // }
            // Mark as processing or on-hold (payment won't be taken until delivery).
            // $totp = $this->generateTOTP();
            // $loginPayload = $this->login_payload();
            // $encryptedKey = $this->aesencrypt($loginPayload, $totp);
            // $login_result = $this->login_api_request($encryptedKey);
            // if (isset($login_result['data'])) {
            //     $paymentPayload = json_encode(array(
            //         "personCode" => $login_result['data']['personCode'],
            //         "walletCode" => $login_result['data']['walletCode'],
            //         "transactionDetails" => array(
            //             array(
            //                 "paymentMethod"=> "GCSB",
            //                 "paymentMethodID" => 131,
            //                 "institutionAggregatorID" => 28644,
            //                 "amount" => 1,
            //                 "description" => "This is a test transaction",
            //                 "email" => "evangeliojohnkevin@gmail.com",
            //                 "firstName" => "John",
            //                 "lastName" => "Kevin",
            //                 "paymentCategoryID" => 3,
            //                 "institutionID" => 36837,
            //                 "aggregatorID" => 11
            //             )
            //         )
            //     ));
            //     $paymentAccessToken = $login_result['data']['accessToken'];
            //     $paymentTotp = $this->generateTOTP($login_result['data']['secretKey']);
            //     $paymentEncryptedKey = $this->aesencrypt($paymentPayload, $paymentTotp);
            //     $payment_result = $this->gcash_payment_api_request($paymentEncryptedKey, $paymentAccessToken);
            //     if (isset($payment_result['data'])) {
            //         $payment_URL = $payment_result['data']['url'];
            //         return array(
            //             'result'   => 'success',
            //             'redirect' => $payment_URL,
            //         );
            //     }
            // }

        } else {
            $order->payment_complete();
        }

        // Remove cart.
        // WC()->cart->empty_cart();

        // Return thankyou redirect.
        // return array(
        //     'result'   => 'success',
        //     'redirect' => $this->get_return_url( $order ),
        // );
    }
    private function login_payload () {
        $jsonData = json_encode(array(
            "username" => $this->api_username,
            "password" => $this->api_key,
            "applicationId" => 1
        ));
        return $jsonData;
    }

    private function barneys_payment_processing () {
        $totp = $this->generateTOTP();
        $loginPayload = $this->login_payload();
        $encryptedKey = $this->aesencrypt($loginPayload, $totp);
        $login_result = $this->login_api_request2($encryptedKey);
        
        if (isset($login_result['data'])) {
            $transactionPayload = json_encode(array(
                "referenceNumber" => "DRT202406061486792"
            ));
            $paymentAccessToken = $login_result['data']['accessToken'];
            echo $paymentAccessToken;
            $paymentTotp = $this->generateTOTP($login_result['data']['secretKey']);
            $paymentEncryptedKey = $this->aesencrypt($transactionPayload, $paymentTotp);
            echo '\n'. $paymentEncryptedKey;
            // $transactAPIURL = "https://sitapi2.traxionpay.com/api/v1/transactions/details/aggregator";
            // $payment_result = $this->getAPIRequest($transactAPIURL, $paymentEncryptedKey, $paymentAccessToken);
            // print_r($payment_result['data']);
        }
        // if (isset($login_result['data'])) {
        //     $paymentPayload = json_encode(array(
        //         "personCode" => $login_result['data']['personCode'],
        //         "walletCode" => $login_result['data']['walletCode'],
        //         "transactionDetails" => array(
        //             array(
        //                 "paymentMethod"=> "GCSB",
        //                 "paymentMethodID" => 131,
        //                 "institutionAggregatorID" => 28644,
        //                 "amount" => 1,
        //                 "description" => "This is a test transaction",
        //                 "email" => "evangeliojohnkevin@gmail.com",
        //                 "firstName" => "John",
        //                 "lastName" => "Kevin",
        //                 "paymentCategoryID" => 3,
        //                 "institutionID" => 36837,
        //                 "aggregatorID" => 11
        //             )
        //         )
        //     ));
        //     $paymentAccessToken = $login_result['data']['accessToken'];
        //     $paymentTotp = $this->generateTOTP($login_result['data']['secretKey']);
        //     $paymentEncryptedKey = $this->aesencrypt($paymentPayload, $paymentTotp);
        //     $payment_result = $this->gcash_payment_api_request($paymentEncryptedKey, $paymentAccessToken);
        //     if (isset($payment_result['data'])) {
        //         $payment_URL = $payment_result['data']['url'];
        //         return $payment_URL;
        //     } else {
        //         return 'Error';
        //     }
        // }




        // if (isset($login_result['data']['accessToken'])) {
        //     echo 'walletCode: ' . $login_result['data']['walletCode'];
        // } else {
        //     echo 'walletCode not found';
        // }


        // add_action('woocommerce_checkout_order_processed', 'customize_checkout_error_message', 10);
        // $order->update_status( apply_filters( 'woocommerce_barneys_process_payment_order_status', $order->has_downloadable_item() ? 'on-hold' : 'processing', $order ), __( 'Payment to be made upon delivery.', 'woocommerce' ) );
    }

    private function generateTOTP ($secret_key = null) {
        $algorithm = 'sha1'; // Assuming this.algorithm is 'sha1'
        $digits = 6; // Assuming this.digits is 6
        $period = 43200; // Assuming this.period is 30
        $secret_key = $secret_key ?: $this->default_secret_key;
        $totp = TOTP::createFromSecret($secret_key);
        $totp->setPeriod($period);
        $totp->setDigest($algorithm);
        $totp->setDigits($digits);
        $token = $totp->now();
        return $token;
    }
    private function aesencrypt ($jsonData, $encryptionKey) {
        // Encryption
        $encrypted = AES256::encrypt($jsonData, $encryptionKey);
        return $encrypted;
    }
    private function getAPIRequest ($url, $encryptedKey, $authToken) {
        $endpoint_url = $url;  // Replace with the API URL
        $payload = array(
            'data' => $encryptedKey
        );
        $json_payload = json_encode($payload);
        $args = array(
            'method'      => 'POST',
            'body'        => $json_payload,
            'headers'     => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $authToken
            ),
            'timeout'     => 45,
            'redirection' => 5,
            'blocking'    => true,
            'sslverify'   => false,  // Set to true in a production environment
        );
        $response = wp_remote_post($endpoint_url, $args);
        $response_body = wp_remote_retrieve_body($response);
        error_log('API Response: ' . $response_body);

        // Try to decode the response body
        $decoded_response = json_decode($response_body, true);

        // Check for JSON errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            $json_error = json_last_error_msg();
            return "JSON decoding error: $json_error";
        }
        return $decoded_response;
        // if (is_wp_error($response)) {
        //     $error_message = $response->get_error_message();
        //     echo "Something went wrong: $error_message";
        // }
        // if ( 200 != wp_remote_retrieve_response_code($response)) {
        //     $error_message = $response->get_error_message();
        //     return "Something went wrong: $error_message";
        // }
        // if ( 200 == wp_remote_retrieve_response_code($response)) {
        //     $response_body = wp_remote_retrieve_body($response);

        //     return json_decode($response_body, true);
        // }

    }
    private function login_api_request2 ($encryptedKey) {
        $endpoint_url = 'https://sitapi2.traxionpay.com/api/v1/auth/login/thirdparty';  // Replace with the API URL
        $payload = array(
            'data' => $encryptedKey
        );

        $json_payload = wp_json_encode($payload);

        $args = array(
            'method'      => 'POST',
            'body'        => $json_payload,
            'headers'     => array(
                'Content-Type' => 'application/json',
            ),
            'timeout'     => 45,
            'redirection' => 5,
            'blocking'    => true,
            'sslverify'   => false,  // Set to true in a production environment
        );
        $response = wp_remote_post($endpoint_url, $args);
        // Check for errors
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            echo "Something went wrong: $error_message";
        }
        if ( 200 != wp_remote_retrieve_response_code($response)) {
            $error_message = $response->get_error_message();
            return "Something went wrong: $error_message";
        }
        if ( 200 == wp_remote_retrieve_response_code($response)) {
            $response_body = wp_remote_retrieve_body($response);
            return json_decode($response_body, true);
        }

    }
    private function login_api_request ($encryptedKey) {
        $url = 'https://sitapi2.traxionpay.com/api/v1/auth/login/thirdparty';  // Replace with the API URL
        $data = array(
            'data' => $encryptedKey
        );
        $payload = json_encode($data);
        
        $ch = curl_init($url);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        
        $response = curl_exec($ch);
        
        if ($response === false) {
            $error = curl_error($ch);
            echo 'Error: ' . $error . '\n Contact your developer.';
        } else {
            return json_decode($response, true);
        }
        curl_close($ch);
    }
    private function gcash_payment_api_request2 ($encryptedKey, $authToken) {
        $endpoint_url = 'https://sitapi2.traxionpay.com/api/v1/transactions/external/funds/cash-in/gcash';  // Replace with the API URL
        $payload = array(
            'data' => $encryptedKey
        );

        $json_payload = wp_json_encode($payload);
        $args = array(
            'method'      => 'POST',
            'body'        => $json_payload,
            'headers'     => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $authToken
            ),
            'timeout'     => 45,
            'redirection' => 5,
            'blocking'    => true,
            'sslverify'   => true,  // Set to true in a production environment
        );
        $response = wp_remote_post($endpoint_url, $args);
        // Check for errors
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            echo "Something went wrong: $error_message";
        }
        if ( 200 != wp_remote_retrieve_response_code($response)) {
            $error_message = $response->get_error_message();
            return "Something went wrong: $error_message";
        }
        if ( 200 == wp_remote_retrieve_response_code($response)) {
            $response_body = wp_remote_retrieve_body($response);
            return json_decode($response_body, true);
        }
    }
    private function gcash_payment_api_request ($encryptedKey, $authToken) {
        $url = 'https://sitapi2.traxionpay.com/api/v1/transactions/external/funds/cash-in/gcash';  // Replace with the API URL
        $data = array(
            'data' => $encryptedKey
        );
        $payload = json_encode($data);
        $ch = curl_init($url);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/json',
            'Authorization: Bearer ' . $authToken
        ));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        
        $response = curl_exec($ch);
        
        if ($response === false) {
            $error = curl_error($ch);
            echo 'Error: ' . $error . '\n Contact your developer.';
        } else {
            return json_decode($response, true);
        }
        curl_close($ch);
    }
    private function gcash_actual_payment ($apiUrl) {
        $ch = curl_init();

        // Set the URL
        curl_setopt($ch, CURLOPT_URL, $apiUrl);

        // Return the response instead of printing
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Execute the request
        $response = curl_exec($ch);

        // Close cURL session
        curl_close($ch);

        // Set the CORS header
        header("Access-Control-Allow-Origin: *");

        // Output the response
        echo $response;

    }
    /**
     * Output for the order received page.
     */
    public function thankyou_page() {
        if ( $this->instructions ) {
            echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) );
        }
    }

    /**
     * Change payment complete order status to completed for Barneys orders.
     *
     * @since  3.1.0
     * @param  string         $status Current order status.
     * @param  int            $order_id Order ID.
     * @param  WC_Order|false $order Order object.
     * @return string
     */
    public function change_payment_complete_order_status( $status, $order_id = 0, $order = false ) {
        if ( $order && 'barneys' === $order->get_payment_method() ) {
            $status = 'completed';
        }
        return $status;
    }

    /**
     * Add content to the WC emails.
     *
     * @param WC_Order $order Order object.
     * @param bool     $sent_to_admin  Sent to admin.
     * @param bool     $plain_text Email format: plain text or HTML.
     */
    public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
        if ( $this->instructions && ! $sent_to_admin && $this->id === $order->get_payment_method() ) {
            echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) . PHP_EOL );
        }
    }
}



?>