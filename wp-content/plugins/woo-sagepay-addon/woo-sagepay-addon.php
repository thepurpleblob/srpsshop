<?php

/**
 * Plugin Name:         Addon for sagepay and WooCommerce
 * Plugin URL:          Addon for sagepay and WooCommerce
 * Description:         Addon for sagepay and WooCommerce allows you to accept payments on your Woocommerce store. It accpets credit card payments and processes them securely with your merchant account.
 * Version:             2.0.1
 * WC requires at least:2.3
 * WC tested up to:     3.8.1
 * Requires at least:   4.0+
 * Tested up to:        5.3.2
 * Contributors:        wp_estatic
 * Author:              Estatic Infotech Pvt Ltd
 * Author URI:          http://estatic-infotech.com/
 * License:             GPLv3
 * @package WooCommerce
 * @category Woocommerce Payment Gateway
 */

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$logs_message = "";

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

    deactivate_plugins(plugin_basename(__FILE__));
    add_action('load-plugins.php', function () {
        add_filter('gettext', 'wsa_change_text', 99, 3);
    });

    function wsa_change_text($translated_text, $untranslated_text, $domain)
    {
        echo '111';
        echo '<pre>';
        print_r($_REQUEST);die;
        $old = array(
            "Plugin <strong>activated</strong>.",
            "Selected plugins <strong>activated</strong>.",
        );

        $new = "Please activate <b>Woocommerce</b> Plugin to use WooCommerce Sage Pay Addon plugin";

        if (in_array($untranslated_text, $old, true)) {
            $translated_text = $new;
            remove_filter(current_filter(), __FUNCTION__, 99);
        }
        return $translated_text;
    }

    return false;
}

add_action('plugins_loaded', 'wsa_init_woocommerce_sagepay_4', 0);

function wsa_init_woocommerce_sagepay_4()
{

    if (!class_exists('WC_Payment_Gateway_CC')) {
        return;
    }

    load_plugin_textdomain('woocommerce', false, dirname(plugin_basename(__FILE__)) . '/lang');

    function psdv2_add_sagepay_gateway($methods)
    {
        $methods[] = 'woocommerce_sagepay_psdv2';

        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'psdv2_add_sagepay_gateway');

    class woocommerce_sagepay_psdv2 extends WC_Payment_Gateway_CC
    {

        public function __construct()
        {
            global $woocommerce;

            $this->id = 'sagepay_psdv2';
            $this->method_title = __('Sagepay psdv2', 'woocommerce');
            $this->icon = apply_filters('woocommerce_sagepay_icon', '');
            $this->has_fields = true;
            $this->notify_url = add_query_arg('wc-api', 'woocommerce_sagepay_psdv2', home_url('/'));

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->method_description = sprintf(__('sagepay allows you to accept payments on your Woocommerce store. It accpets credit card payments and processes them securely with your merchant account.Please dont forget to test with sandbox account first. <li style="color: red;"><span id="message">Please Add Currency Which Is Provided By Your Sagepay Merchant Account</span></li> ', 'woocommerce'));

            $this->description = $this->get_option('description');
            $this->vendor_name = $this->get_option('vendorname');
            $this->mode = $this->get_option('mode');
            $this->transtype = $this->get_option('transtype');

            $this->send_shipping = $this->get_option('send_shipping');
            $this->sagepay_cardtypes = $this->get_option('sagepay_cardtypes');

            $this->sagepay_zerocurrency = array("BIF", "CLP", "DJF", "GNF", "JPY", "KMF", "KRW", "MGA", "PYG", "RWF", "VND", "VUV", "XAF", "XOF", "XPF", "GBP");

            add_action('init', array($this, 'wsa_auth_success'));

            add_action('woocommerce_api_woocommerce_' . $this->id, array($this, 'wsa_auth_success'));

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            add_action('woocommerce_order_status_processing_to_cancelled', array($this, 'wsa_restore_order_stock'), 10, 1);

            add_action('woocommerce_order_status_completed_to_cancelled', array($this, 'wsa_restore_order_stock'), 10, 1);

            add_action('woocommerce_order_status_on-hold_to_cancelled', array($this, 'wsa_restore_order_stock'), 10, 1);

            add_action('woocommerce_order_status_processing_to_refunded', array($this, 'wsa_restore_order_stock'), 10, 1);

            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

            add_action('woocommerce_order_status_completed_to_refunded', array($this, 'wsa_restore_order_stock'), 10, 1);

            add_action('woocommerce_order_status_on-hold_to_refunded', array($this, 'wsa_restore_order_stock'), 10, 1);

            add_filter('woocommerce_credit_card_form_fields', array($this, 'sagepay_card_type'), 10, 2);

        }

        function get_the_user_ip()
        {
            if (!empty($_SERVER['HTTP_CLIENT_IP'])) {

                $ip = $_SERVER['HTTP_CLIENT_IP'];
            } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {

                $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
            } else {
                $ip = $_SERVER['REMOTE_ADDR'];
            }
            return apply_filters('wpb_get_ip', $ip);
        }

        function sagepay_card_type($args, $payment_id)
        {
            $new_cards_type = "";
            if ($payment_id == $this->id) {

                foreach ($this->sagepay_cardtypes as $value) {
                    //
                    $new_cards_type .= '<option value="' . $value . '">' . $value . '</option>';
                }

                $args = array_merge(array(
                    'card-type' => '<p class="form-row" style="width:200px;">
                    <label>' . __('Card Type', 'woocommerce') . ' <span class="required">*</span></label>
          	        <select name="' . esc_attr($payment_id) . '-card-type">' . $new_cards_type . '</select>
          			</p>',
                    'card-name' => '<p class="form-row form-row-wide">
                    <label for="' . esc_attr($payment_id) . '-card-name">' . __('Card Holder Name', 'woocommerce') . ' <span class="required">*</span></label>
                    <input id="' . esc_attr($payment_id) . '-card-name" class="input-text wc-credit-card-form-card-name" type="text" maxlength="20" autocomplete="off" placeholder="Name on Card" ' . $this->field_name('card-name') . ' />
                </p>',
                ), $args
                );
            }

            return $args;
        }

        public function get_icon()
        {
            if ($this->get_option('show_accepted') == 'yes') {
                $get_cardtypes = $this->get_option('sagepay_cardtypes');

                $icons = "";
                foreach ($get_cardtypes as $val) {
                    $cardimage = plugins_url('images/' . $val . '.png', __FILE__);
                    $icons .= '<img src="' . $cardimage . '" alt="' . $val . '" />';
                }
            } else {
                $icons = "";
            }
            return apply_filters('woocommerce_gateway_icon', $icons, $this->id);
        }

        private function sagepay_ssl($url)
        {
            if ('yes' == get_option('woocommerce_sagepay_ssl_checkout')) {
                $url = str_replace('http:', 'https:', $url);
                echo $url;die;
            }
            return $url;
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable Sagepay', 'woocommerce'),
                    'default' => 'yes',
                ),
                'title' => array(
                    'title' => __('Title', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Display this title on checkout page.', 'woocommerce'),
                    'default' => __('Sagepay', 'woocommerce'),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Description', 'woocommerce'),
                    'type' => 'textarea',
                    'desc_tip' => __('user sees during checkout.', 'woocommerce'),
                    'default' => __("Payment via SagePay, Please enter your credit or debit card below.", 'woocommerce'),
                ),
                'vendorname' => array(
                    'title' => __('Vendor Name', 'woocommerce'),
                    'type' => 'text',
                    'desc_tip' => __('Please enter your vendor name which is provided by your sagepay account.', 'woocommerce'),
                    'default' => '',
                ),
                'mode' => array(
                    'title' => __('Mode Type', 'woocommerce'),
                    'type' => 'select',
                    'options' => array(
                        'test' => 'Test',
                        'live' => 'Live',
                    ),
                    'desc_tip' => __('Select the mode to accept.', 'woocommerce'),
                ),
                'send_shipping' => array(
                    'title' => __('Select Shipping Address', 'woocommerce'),
                    'type' => 'select',
                    'options' => array(
                        'auto' => 'Auto',
                        'yes' => 'Billing Address',
                    ),
                    'desc_tip' => __('Slect your send shipping address.', 'woocommerce'),
                    'default' => 'auto',
                ),
                'transtype' => array(
                    'title' => __('Transition Type', 'woocommerce'),
                    'type' => 'select',
                    'options' => array(
                        'PAYMENT' => __('Payment', 'woocommerce'),
                        'DEFERRED' => __('Deferred', 'woocommerce'),
                        'AUTHENTICATE' => __('Authenticate', 'woocommerce'),
                    ),
                    'desc_tip' => __('Select Payment, Deferred or Authenticate.', 'woocommerce'),
                ),
                'show_accepted' => array(
                    'title' => __('Show Accepted Card Icons', 'woocommerce'),
                    'type' => 'select',
                    'class' => 'chosen_select',
                    'css' => 'width: 350px;',
                    'desc_tip' => __('Select the mode to accept.', 'woocommerce'),
                    'options' => array(
                        'yes' => 'Yes',
                        'no' => 'No',
                    ),
                    'default' => 'yes',
                ),
                'sagepay_cardtypes' => array(
                    'title' => __('Accepted Card Types', 'woocommerce'),
                    'type' => 'multiselect',
                    'class' => 'chosen_select',
                    'css' => 'width: 350px;',
                    'desc_tip' => __('Add/Remove credit card types to accept.', 'woocommerce'),
                    'options' => array(
                        'MC' => 'Mastercard',
			'VISA' => 'Visa',
			'MCDEBIT' => 'Debit MasterCard',
			'DELTA' => 'Visa Debit',
			'MAESTRO' => 'Maestro',
			'UKE' => 'Visa Electron',
			'AMEX' => 'American Express',
			'DC' => 'Diners Club and Discover',
			'JCB' => 'Japan Credit Bureau',
                    ),
                    'default' => array('MC' => 'mastercard',
                        'VISA' => 'Visa',
                    ),
                ),
            );
        }

        function validate_fields()
        {
            global $woocommerce;

            if (empty(sanitize_text_field($_POST['sagepay_psdv2-card-name']))) {
                wc_add_notice('<strong>Card Name</strong> ' . __('is required.', 'woocommerce'), 'error');
            }

            if (!$this->wsa_if_credit_card_is_empty(sanitize_text_field($_POST['sagepay_psdv2-card-number']))) {
                wc_add_notice('<strong>Credit Card Number</strong> ' . __('is required.', 'woocommerce'), 'error');
            } elseif (!$this->wsa_is_valid_credit_card(sanitize_text_field($_POST['sagepay_psdv2-card-number']))) {
                wc_add_notice('<strong>Credit Card Number</strong> ' . __('is not a valid credit card number.', 'woocommerce'), 'error');
            }

            if (!$this->wsa_if_expire_date_is_empty(sanitize_text_field($_POST['sagepay_psdv2-card-expiry']))) {
                wc_add_notice('<strong>Card Expiry Date</strong> ' . __('is required.', 'woocommerce'), 'error');
            } elseif (!$this->wsa_is_valid_expire_date(sanitize_text_field($_POST['sagepay_psdv2-card-expiry']))) {
                wc_add_notice('<strong>Card Expiry Date</strong> ' . __('is not a valid expiry date.', 'woocommerce'), 'error');
            }

            if (!$this->wsa_if_cvv_number_is_empty(sanitize_text_field($_POST['sagepay_psdv2-card-cvc']))) {
                wc_add_notice('<strong>CVV Number</strong> ' . __('is required.', 'woocommerce'), 'error');
            }
        }

        private function wsa_if_credit_card_is_empty($credit_card)
        {
            if (empty($credit_card)) {
                return false;
            }
            return true;
        }

        public function field_name($name)
        {
            return $this->supports('tokenization') ? '' : ' name="' . esc_attr($this->id . '-' . $name) . '" ';
        }

        private function wsa_if_expire_date_is_empty($expiry_date)
        {
            $expiry_date = str_replace(' / ', '', $expiry_date);

            if (is_numeric($expiry_date) && (strlen($expiry_date) == 4)) {
                return true;
            }
            return false;
        }

        private function wsa_if_cvv_number_is_empty($ccv_number)
        {
            $length = strlen($ccv_number);
            return is_numeric($ccv_number) and $length > 2 and $length < 5;
        }

        function get_card_type($number)
        {
            $number = preg_replace('/[^\d]/', '', $number);

            if (preg_match('/^3[47][0-9]{13}$/', $number)) {
                $card = 'amex';
            } elseif (preg_match('/^3(?:0[0-5]|[68][0-9])[0-9]{11}$/', $number)) {
                $card = 'dinersclub';
            } elseif (preg_match('/^6(?:011|5[0-9][0-9])[0-9]{12}$/', $number)) {
                $card = 'discover';
            } elseif (preg_match('/^(?:2131|1800|35\d{3})\d{11}$/', $number)) {
                $card = 'jcb';
            } elseif (preg_match('/^5[1-5][0-9]{14}$/', $number)) {
                $card = 'mastercard';
            } elseif (preg_match('/^4[0-9]{12}(?:[0-9]{3})?$/', $number)) {
                $card = 'visa';
            } else {
                $card = 'unknown card';
            }

            return $card;
        }

        function receipt_page($order)
        {
            global $woocommerce;

            echo '<p>' . __('Thank you for your order, Please click button below to Authenticate your card.', 'woocommerce') . '</p>';
            echo $this->generate_sagepay_form($order);
        }

        public function generate_sagepay_form($order_id)
        {
            global $woocommerce;

            $order = new WC_Order($order_id);

            if (!empty(WC()->session->get('set_pareq'))) {
                $sagepay_args = array(
                    'PaReq' => WC()->session->get('set_pareq'),
                    'MD' => WC()->session->get('set_md'),
                    'TermUrl' => $this->notify_url,
                );
            } else if (!empty(WC()->session->get('set_creq'))) {
                $VPSTxId = WC()->session->get('set_vpstxid');
                $VPSTxId = str_replace('{', '', $VPSTxId);
                $VPSTxId = str_replace('}', '', $VPSTxId);
                $sagepay_args = array(
                    'creq' => WC()->session->get('set_creq'),
                    'threeDSSessionData' => $VPSTxId,
                );
            }
            $sagepay_args_array = array();

            foreach ($sagepay_args as $key => $value) {
                $sagepay_args_array[] = '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
            }

            wc_enqueue_js('
            /* jQuery("body").block({
                message: "<img src=\"' . esc_url($woocommerce->plugin_url()) . '/images/ajax-loader.gif\" alt=\"Redirecting...\" style=\"float:left; margin-right: 10px;\" />' . __('Thank you for your order. We are now redirecting you to verify your card.', 'woocommerce') . '",
                          overlayCSS:
                {
                  background: "#fff",
                              opacity: 0.6
                },

              }); */
        jQuery("#submit_sagepay_payment_form").click();
      ');
            echo '<form style="display:none" action="' . esc_url(WC()->session->get('set_acsurl')) . '" target="3Diframe" method="post" id="sagepay_payment_form">
            ' . implode('', $sagepay_args_array) . '
            <input type="submit" class="button-alt" id="submit_sagepay_payment_form" value="' . __('Submit', 'woocommerce') . '" />
            <a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Cancel order &amp; restore cart', 'woocommerce') . '</a>
            </form>';
            return '<iframe width="800" margin-top="30px" height="300" name="3Diframe" ></iframe>';
        }

        function process_payment($order_id)
        {
            global $woocommerce;

            $order = new WC_Order($order_id);

            $credit_card = preg_replace('/(?<=\d)\s+(?=\d)/', '', trim(sanitize_text_field($_POST['sagepay_psdv2-card-number'])));

            $expiry_date = sanitize_text_field($_POST['sagepay_psdv2-card-expiry']);
            $month = substr($expiry_date, 0, 2);
            $year = substr($expiry_date, 5, 7);

            $time_stamp = date("ymdHis");
            $orderid = $this->vendor_name . "-" . $time_stamp . "-" . $order_id;

            WC()->session->set('sagepay_set', $orderid);
            WC()->session->set('sagepay_oid', $order_id);

            $product_item['BillingSurname'] = $order->get_billing_last_name();
            $product_item['BillingFirstnames'] = $order->get_billing_first_name();
            $product_item['BillingAddress1'] = $order->get_billing_address_1();
            $product_item['BillingAddress2'] = $order->get_billing_address_2();
            $product_item['BillingCity'] = $order->get_billing_city();
            $product_item['BillingPostCode'] = $order->get_billing_postcode();
            $product_item['BillingCountry'] = $order->get_billing_country();
            if ($order->get_billing_country() == 'US') {
                $product_item['BillingState'] = $order->get_billing_state();
            } else {
                $product_item['BillingState'] = '';
            }
            $product_item['BillingPhone'] = $order->get_billing_phone();

            if ($this->wsa_if_meta_product() == true || $this->send_shipping == 'yes') {
                $product_item['DeliverySurname'] = $order->get_billing_last_name();
                $product_item['DeliveryFirstnames'] = $order->get_billing_first_name();
                $product_item['DeliveryAddress1'] = $order->get_billing_address_1();
                $product_item['DeliveryAddress2'] = $order->get_billing_address_2();
                $product_item['DeliveryCity'] = $order->get_billing_city();
                $product_item['DeliveryPostCode'] = $order->get_billing_postcode();
                $product_item['DeliveryCountry'] = $order->get_billing_country();
                $product_item['product_categories'] = $this->get_categories;
                if ($order->get_billing_country() == 'US') {
                    $product_item['DeliveryState'] = $order->billing_state();
                } else {
                    $product_item['DeliveryState'] = '';
                }
                $product_item['DeliveryPhone'] = $order->get_billing_phone();
                $product_item['CustomerEMail'] = $order->get_billing_email();
            } else if ($order->get_shipping_country() != '' && $this->send_shipping == 'auto') {
                $product_item['DeliverySurname'] = $order->get_shipping_last_name();
                $product_item['DeliveryFirstnames'] = $order->get_shipping_first_name();
                $product_item['DeliveryAddress1'] = $order->get_shipping_address_1();
                $product_item['DeliveryAddress2'] = $order->get_shipping_address_2();
                $product_item['DeliveryCity'] = $order->get_shipping_city();

                $product_item['DeliveryState'] = $order->get_shipping_state();
                $product_item['DeliveryPostCode'] = $order->get_shipping_postcode();
                $product_item['DeliveryCountry'] = $order->get_shipping_country();
            } else {
                $product_item['DeliverySurname'] = $order->get_billing_last_name();
                $product_item['DeliveryFirstnames'] = $order->get_billing_first_name();
                $product_item['DeliveryAddress1'] = $order->get_billing_address_1();
                $product_item['DeliveryAddress2'] = $order->get_billing_address_2();
                $product_item['DeliveryCity'] = $order->get_billing_city();
                $product_item['DeliveryPostCode'] = $order->get_billing_postcode();
                $product_item['DeliveryCountry'] = $order->get_billing_country();
                $product_item['product_categories'] = $this->get_categories;
                if ($order->billing_country == 'US') {
                    $product_item['DeliveryState'] = $order->get_billing_state();
                } else {
                    $product_item['DeliveryState'] = '';
                }
                $product_item['DeliveryPhone'] = $order->get_billing_phone();
                $product_item['CustomerEMail'] = $order->get_billing_email();
            }

            // TODO: Bodge... we don't want this
            $product_item['BillingState'] = '';
            $product_item['DeliveryState'] = '';
            

            $product_item['VPSProtocol'] = "4.00";
            $product_item['TxType'] = $this->transtype;
            $product_item['Vendor'] = $this->vendor_name;
            $product_item['VendorTxCode'] = $orderid;
            $product_item['Amount'] = $order->get_total();
            $product_item['Currency'] = get_woocommerce_currency();
            $product_item['Description'] = sprintf(__('Order #%s', 'woocommerce'), ltrim($order->get_order_number(), '#'));
            $product_item['DeliveryPhone'] = $order->get_billing_phone();
            // $product_item['CardHolder'] = sanitize_text_field($_POST['sagepay_psdv2-card-name']);
            $product_item['CardHolder'] = 'CHALLENGE';
            $product_item['CardNumber'] = $credit_card;
            $product_item['StartDate'] = '';
            $product_item['ExpiryDate'] = $month . $year;
            $product_item['CV2'] = sanitize_text_field($_POST['sagepay_psdv2-card-cvc']);
            $product_item['CardType'] = sanitize_text_field($_POST['sagepay_psdv2-card-type']);
            $product_item['CustomerEMail'] = $order->get_billing_email();
            $product_item['clientipaddress'] = $this->get_the_user_ip();
            $product_item['product_categories'] = $this->get_categories;
            $product_item['customer_email'] = $order->get_billing_email();
            // $product_item['VPSTxID'] = //   $product_item['CreateToken'] = 1;
            $product_item['ThreeDSNotificationURL'] = $this->notify_url;
            $product_item['BrowserAcceptHeader'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3';
            $product_item['BrowserUserAgent'] = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.103 Safari/537.36';

            $product_item['BrowserLanguage'] = 'en-GB';
            $product_item['BrowserJavascriptEnabled'] = true;
            $product_item['BrowserJavaEnabled'] = true;
            $product_item['BrowserColorDepth'] = 16;
            $product_item['BrowserScreenHeight'] = '300';
            $product_item['BrowserScreenWidth'] = '300';
            $product_item['BrowserTZ'] = '-3180';
            $product_item['ChallengeWindowSize'] = '05';
            $product_item['Apply3DSecure'] = 0; // TODO - make this an option somewherefbu

            $post_values = "";
            foreach ($product_item as $key => $value) {
                $post_values .= "$key=" . urlencode($value) . "&";
            }
            $post_values = rtrim($post_values, "& ");

            if ($this->mode == 'test') {
                $redirect_for_pay__url = 'https://test.sagepay.com/gateway/service/vspdirect-register.vsp';
            } else if ($this->mode == 'live') {
                $redirect_for_pay__url = 'https://live.sagepay.com/gateway/service/vspdirect-register.vsp';
	    }

            $response = wp_remote_post($redirect_for_pay__url, array(
                'body' => $post_values,
                'method' => 'POST',
                'sslverify' => false,
            ));

            if (!is_wp_error($response) && $response['response']['code'] >= 200 && $response['response']['code'] < 300) {

                $responce_update = array();
                $lines = preg_split('/\r\n|\r|\n/', $response['body']);
                foreach ($lines as $line) {
                    $key_value = preg_split('/=/', $line, 2);
                    if (count($key_value) > 1) {
                        $responce_update[trim($key_value[0])] = trim($key_value[1]);
                    }
                }

                if (isset($responce_update['Status'])) {
                    update_post_meta($order->get_id(), 'Status', $responce_update['Status']);
                }

                if (isset($responce_update['AVSCV2'])) {
                    update_post_meta($order->get_id(), 'AVSCV2', $responce_update['AVSCV2']);
                }

                if (isset($responce_update['TxAuthNo'])) {
                    update_post_meta($order->get_id(), 'TxAuthNo', $responce_update['TxAuthNo']);
                }

                if (isset($responce_update['PostCodeResult'])) {
                    update_post_meta($order->get_id(), 'PostCodeResult', $responce_update['PostCodeResult']);
                }

                if (isset($responce_update['VPSTxId'])) {
                    update_post_meta($order->get_id(), 'VPSTxId', $responce_update['VPSTxId']);
                    WC()->session->set('set_vpstxid', $responce_update['VPSTxId']);
                }

                if (isset($responce_update['StatusDetail'])) {
			update_post_meta($order->get_id(), 'StatusDetail', $responce_update['StatusDetail']);
		    error_log($responce_update['StatusDetail']);	
                }

                if (isset($responce_update['SecurityKey'])) {
                    update_post_meta($order->get_id(), 'SecurityKey', $responce_update['SecurityKey']);
                }

                if (isset($responce_update['CV2Result'])) {
                    update_post_meta($order->get_id(), 'CV2Result', $responce_update['CV2Result']);
                }

                if (isset($responce_update['3DSecureStatus'])) {
                    update_post_meta($order->get_id(), '3DSecureStatus', $responce_update['3DSecureStatus']);
                }

                if (WC()->session->get('sagepay_set') != '') {
                    update_post_meta($order->get_id(), 'VendorTxCode', WC()->session->get('sagepay_set'));
                }

                if (isset($responce_update['expiry_date'])) {
                    update_post_meta($order->get_id(), 'expiry_date', $responce_update['expiry_date']);
                }

                if ($responce_update['Status'] == "OK" || $responce_update['Status'] == "REGISTERED" || $responce_update['Status'] == "AUTHENTICATED") {

                    $order->add_order_note(__('Sagepay Payment Completed.', 'woocommerce'));
                    $order->payment_complete();
                    $redirect_url = $this->get_return_url($order);

                    return array(
                        'result' => 'success',
                        'redirect' => $redirect_url,
                    );

                } else if ($responce_update['Status'] == "3DAUTH") {

                    if ($responce_update['3DSecureStatus'] == 'OK') {
                        // if (isset($responce_update['ACSURL']) && (isset($responce_update['PAReq']))) {
                        if (isset($responce_update['ACSURL']) && (isset($responce_update['PAReq']) || isset($responce_update['CReq']))) {
                            WC()->session->set('set_acsurl', $responce_update['ACSURL']);

                            if (isset($responce_update['PAReq']) && !empty($responce_update['PAReq'])) {
                                WC()->session->set('set_pareq', $responce_update['PAReq']);
                            }

                            if (isset($responce_update['CReq']) && !empty($responce_update['CReq'])) {
                                WC()->session->set('set_pareq', "");
                                WC()->session->set('set_creq', $responce_update['CReq']);
                            }

                            WC()->session->set('set_md', $responce_update['MD']);

                            $redirect = $order->get_checkout_payment_url(true);

                            return array(
                                'result' => 'success',
                                'redirect' => $redirect,
                            );
                        }
                    }
                }
            } else {
                wc_add_notice(__('Error. Please Contact Us.', 'woocommerce'), 'error');
            }
        }

        static public function setStore($key, $value)
        {
          
            if (gettype($value) == "object") {
                $_SESSION[$key] = serialize($value);
            } else {
                $_SESSION[$key] = $value;
            }
        }

        public function getSharedUrl($method, $env = '')
        {
          
            $env = $this->_validEnvironment($env);
            if (isset($this->_sharedUrls[$env][$method])) {
                return $this->_sharedUrls[$env][$method];
            }
            return '';
        }

        function wsa_update_logs($script_name, $msg, $file_name = 'logs.txt')
        {
          
            $file = fopen($file_name, 'r+');
            $message_old = fread($file, filesize($file_name));
            $date = date('Y-m-d H:i:s');
            $message = "\n[$date]script name: " . $script_name . " Message generated :" . $msg;
            fwrite($file, $message);
            fclose($file);
        }

        public function wsa_restore_order_stock($order_id)
        {
           
            $order = new WC_Order($order_id);

            $custom = get_post_custom();
            $VPSTxId = substr($custom['VPSTxId'][0], 1, -1);

            $rand_no = wp_generate_password(12, false, false);
            $caracter = 'Estatic';

            $generat = $caracter . '-' . $rand_no . '-' . $order_id;

            if (!get_option('woocommerce_manage_stock') == 'yes' && !sizeof($order->get_items()) > 0) {
                //  wc_add_notice(__('Please Manage Your Stock.', 'woocommerce'), 'error');
                return;
            }
            $i = 0;
            foreach ($order->get_items() as $item) {

                if ($item['product_id'] > 0) {

                    if ($this->mode == 'test') {
                        $refund__url = 'https://test.sagepay.com/gateway/service/refund.vsp';
                    } else if ($this->mode == 'live') {
                        $refund__url = 'https://live.sagepay.com/gateway/service/refund.vsp';
                    }
                    $url = $refund__url;
                    $params = array();
                    $params['VPSProtocol'] = urlencode('3.00');
                    $params['TxType'] = urlencode('REFUND');
                    $params['Vendor'] = urlencode('chestnutregistr');
                    $params['VendorTxCode'] = urlencode($generat); //Sample value given by me
                    $params['Amount'] = urlencode($custom['_order_total'][$i]);
                    $params['Currency'] = urlencode($custom['_order_currency'][$i]);
                    $params['Description'] = urlencode('Testing Refunds');
                    $params['RelatedVPSTxId'] = urlencode($VPSTxId); //VPSTxId of main transaction /* '210C00C7-8B04-CF68-6BE0-7AE59C18F5A8' */
                    $params['RelatedVendorTxCode'] = urlencode($custom['VendorTxCode'][$i]); //VendorTxCode of main transaction
                    $params['RelatedSecurityKey'] = urlencode($custom['SecurityKey'][$i]); //securitykey of main transaction
                    $params['RelatedTxAuthNo'] = urlencode($custom['TxAuthNo'][$i]);

                    $args = array(
                        'body' => $params,
                        'method' => 'POST',
                        'sslverify' => false,
                        'timeout' => '5',
                        'headers' => array(),
                    );

                    $response = wp_remote_post($url, $args);

                    if (strpos($response, 'INVALID') == false) {

                        // $qty = apply_filters('woocommerce_order_item_quantity', $item['qty'], $this, $item);

                        //  $new_quantity = $_product->increase_stock($qty);

                        //do_action('woocommerce_auto_stock_restored', $_product, $item);

                        // $order->add_order_note(__('Item stock incremented from ' . $old_stock . ' to ' . $new_quantity . ' product id ' . $item['product_id'], 'woocommerce'), $item['product_id'], $old_stock, $new_quantity);

                        // $order->send_stock_notifications($_product, $new_quantity, $item['qty']);
                    }

                }
                $i++;
            }
            wp_redirect(admin_url() . 'post.php?post=' . $order_id . '&action=edit');
        }

        private function wsa_is_valid_credit_card($credit_card)
        {
            $credit_card = preg_replace('/(?<=\d)\s+(?=\d)/', '', trim($credit_card));
            $number = preg_replace('/[^0-9]+/', '', $credit_card);
            $strlen = strlen($number);
            $sum = 0;
            if ($strlen < 13) {
                return false;
            }
            for ($i = 0; $i < $strlen; $i++) {
                $digit = substr($number, $strlen - $i - 1, 1);

                if ($i % 2 == 1) {

                    $sub_total = $digit * 2;

                    if ($sub_total > 9) {
                        $sub_total = 1 + ($sub_total - 10);
                    }
                } else {
                    $sub_total = $digit;
                }
                $sum += $sub_total;
            }

            if ($sum > 0 and $sum % 10 == 0) {
                return true;
            }

            return false;
        }

        public function wsa_auth_success()
        {
            global $woocommerce;

            if ((!empty(WC()->session->get('set_vpstxid')) || isset($_REQUEST['MD'])) && (isset($_REQUEST['PaRes']) || isset($_REQUEST['cres']))) {

                $order = new WC_Order(WC()->session->get('sagepay_oid'));
                $VPSTxId = WC()->session->get('set_vpstxid');
                $VPSTxId = str_replace('{', '', $VPSTxId);
                $VPSTxId = str_replace('}', '', $VPSTxId);

                $request_psdv1_array = array(
                    'MD' => sanitize_text_field(isset($_REQUEST['MD'])),
                    'PaRes' => sanitize_text_field(isset($_REQUEST['PaRes'])),
                    'VendorTxCode' => WC()->session->get('sagepay_set'),
                    'VPSTxId' => $VPSTxId,
                );
                $request_psdv2_array = array(
                    'CRes' => sanitize_text_field($_REQUEST['cres']),
                    'VPSTxId' => sanitize_text_field($VPSTxId),
                );

                $request = '';
                if (isset($_REQUEST['PaRes'])) {
                    $request = http_build_query($request_psdv1_array);
                } else {
                    $request = http_build_query($request_psdv2_array);
                }

                if ($this->mode == 'test') {
                    $redirect_for_pay__url = 'https://test.sagepay.com/gateway/service/direct3dcallback.vsp';
                } else if ($this->mode == 'live') {
                    $redirect_for_pay__url = 'https://live.sagepay.com/gateway/service/direct3dcallback.vsp';
                }

                $response = wp_remote_post($redirect_for_pay__url, array(
                    'body' => $request,
                    'method' => 'POST',
                    'sslverify' => false,
                ));

                if (!is_wp_error($response) && $response['response']['code'] >= 200 && $response['response']['code'] < 300) {

                    $responce_update = array();

                    $lines = preg_split('/\r\n|\r|\n/', $response['body']);
                    foreach ($lines as $line) {
                        $key_value = preg_split('/=/', $line, 2);
                        if (count($key_value) > 1) {
                            $responce_update[trim($key_value[0])] = trim($key_value[1]);
                        }
                    }

                    if (isset($responce_update['Status'])) {
                        update_post_meta($order->id, 'Status', $responce_update['Status']);
                    }

                    if (isset($responce_update['AVSCV2'])) {
                        update_post_meta($order->id, 'AVSCV2', $responce_update['AVSCV2']);
                    }

                    if (isset($responce_update['TxAuthNo'])) {
                        update_post_meta($order->id, 'TxAuthNo', $responce_update['TxAuthNo']);
                    }

                    if (isset($responce_update['PostCodeResult'])) {
                        update_post_meta($order->id, 'PostCodeResult', $responce_update['PostCodeResult']);
                    }

                    if (isset($responce_update['VPSTxId'])) {
                        update_post_meta($order->id, 'VPSTxId', $responce_update['VPSTxId']);
                        WC()->session->set('set_vpstxid', $responce_update['VPSTxId']);
                    }

                    if (isset($responce_update['StatusDetail'])) {
                        update_post_meta($order->id, 'StatusDetail', $responce_update['StatusDetail']);
                    }

                    if (isset($responce_update['SecurityKey'])) {
                        update_post_meta($order->id, 'SecurityKey', $responce_update['SecurityKey']);
                    }

                    if (isset($responce_update['CV2Result'])) {
                        update_post_meta($order->id, 'CV2Result', $responce_update['CV2Result']);
                    }

                    if (isset($responce_update['3DSecureStatus'])) {
                        update_post_meta($order->id, '3DSecureStatus', $responce_update['3DSecureStatus']);
                    }

                    if (WC()->session->get('sagepay_set') != '') {
                        update_post_meta($order->id, 'VendorTxCode', WC()->session->get('sagepay_set'));
                    }

                    if (isset($responce_update['expiry_date'])) {
                        update_post_meta($order->id, 'expiry_date', $responce_update['expiry_date']);
                    }

                    /* In the real world, the bank will either authorise the transaction (an OK response) or fail it (a
                    NOTATUHED response). The Sage Pay gateway prepares either an OK response
                    with an authorisation code, a NOTAUTHED response if the bank declined the transaction,  */

                    if ($responce_update['Status'] == "OK" || $responce_update['Status'] == "REGISTERED" || $responce_update['Status'] == "AUTHENTICATED") {

                        // $order->add_order_note(__('SagePay Payment Completed.', 'woocommerce'));
                        $order->add_order_note(__('Sage Pay unique ID.' . $responce_update['VPSTxId'], $responce_update['VPSTxId'], 'woocommerce'));
                        $order->payment_complete();
                        $redirect_url = $this->get_return_url($order);
                        ?>
                        <script type="text/javascript">
                            top.location = '<?=$redirect_url?>';
                        </script>
                        <?php
                        exit();

                    } else if ($responce_update['Status'] == "3DAUTH") {

                        // if (isset($responce_update['ACSURL']) && (isset($responce_update['PAReq']))) {
                        if (isset($responce_update['ACSURL']) && (isset($responce_update['PAReq']) || isset($responce_update['CReq']))) {
                            WC()->session->set('set_acsurl', $responce_update['ACSURL']);

                            if (isset($responce_update['PAReq']) && !empty($responce_update['PAReq'])) {
                                WC()->session->set('set_pareq', $responce_update['PAReq']);
                            }

                            if (isset($responce_update['CReq']) && !empty($responce_update['CReq'])) {
                                WC()->session->set('set_pareq', "");
                                WC()->session->set('set_creq', $responce_update['CReq']);
                            }

                            WC()->session->set('set_md', $responce_update['MD']);

                            $redirect = $order->get_checkout_payment_url(true);

                            return array(
                                'result' => 'success',
                                'redirect' => $redirect,
                            );
                        }
                    }
                } else {

                    wc_add_notice(__('Error. Please Contact Us.', 'woocommerce'), 'error');
                    $get_checkout_url = apply_filters('woocommerce_get_checkout_url', WC()->cart->get_checkout_url());
                    wp_redirect($get_checkout_url);
                    exit();
                }
            }
        }

        private function wsa_is_valid_expire_date($expiry_date)
        {

            $month = $year = '';
            $month = substr($expiry_date, 0, 2);
            $year = substr($expiry_date, 5, 7);
            $year = '20' . $year;

            if ($month > 12) {
                return false;
            }

            if (date("Y-m-d", strtotime($year . "-" . $month . "-01")) > date("Y-m-d")) {
                return true;
            }

            return false;
        }

        private function wsa_if_meta_product()
        {
            global $woocommerce;

            $has_virtual_products = false;
            $virtual_products = 0;
            $products = $woocommerce->cart->get_cart();

            foreach ($products as $product) {

                $product_id = $product['product_id'];
                $is_virtual = get_post_meta($product_id, '_virtual', true);

                // Update $has_virtual_product if product is virtual
                if ($is_virtual == 'yes') {
                    $virtual_products += 1;
                }
            }
            if (count($products) == $virtual_products) {
                $has_virtual_products = true;
            }
            return $has_virtual_products;
        }

    }
}
