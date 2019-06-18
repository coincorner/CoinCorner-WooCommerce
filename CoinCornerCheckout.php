<?php
/*
Plugin Name: CoinCorner Checkout
Description: Checkout using Bitcoin
Version: 1.2
Author: CoinCorner Ltd
Author URI: http://www.coincorner.com
Requires at least: 3.2
*/

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins'))))
    return;

add_action('plugins_loaded', 'CoinCornerCheckout', 0);

function CoinCornerCheckout()
{
    
    class WC_Gateway_CoinCornerCheckout extends WC_Payment_Gateway
    {
        
        public function __construct()
        {
            $this->id                 = 'ccheckout';
            $this->method_title       = __('CoinCorner Checkout', 'woocommerce');
            $this->method_description = __('Bitcoin Payment Gateway for eCommerce', 'woocommerce');
            $this->notify_url         = add_query_arg('wc-api', 'WC_Gateway_CoinCornerCheckout', home_url('/'));
            
            // Shown on checkout page
            $this->title  = "Bitcoin Checkout";
            $this->icon   = plugins_url('', __FILE__) . '/images/' . 'BTC.png';
            $this->banner = plugins_url('', __FILE__) . '/images/' . 'CoinCornerLogo.png';
            // Load the form fields
            $this->init_form_fields();
            $this->has_fields         = false;
            // Load the settings.
            $this->init_settings();

            // Get setting values
            $this->public_key  = $this->get_option('api_public_key');
            $this->private_key = $this->get_option('api_secret_key');
            $this->account_id  = $this->get_option('CoinCornerAccountId');
            $this->CoinCornerInvoiceCurrency  = $this->get_option('CoinCornerInvoiceCurrency');
            $this->CoinCornerSettleCurrency  = $this->get_option('CoinCornerSettleCurrency');

            // Hooks
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
                $this,
                'process_admin_options'
            ));
            add_action('woocommerce_api_wc_gateway_coincornercheckout', array(
                $this,
                'payment_callback'
            ));

        }

        public function process_admin_options() {
            parent::process_admin_options();
            // Validate currencies.
            $this->validate_currencies();
            $this->display_errors();
        }

     /**
	   * Validate the provided credentials.
	   */
	protected function validate_currencies() {
        global $woocommerce;

		$invoice_currency = trim($this->CoinCornerInvoiceCurrency);
		$settlement_currency = trim($this->CoinCornerSettleCurrency);

		if (!ctype_alpha($invoice_currency) ) {
            WC_Admin_Settings::add_error('Error: Invoice Currency must be set to a valid Currency such as GBP');
				return false;
        }
        
		if (!ctype_alpha($settlement_currency) ) {
            WC_Admin_Settings::add_error('Error: Settlement Currency must be set to a valid Currency such as GBP');
            return false;
    }

	}

        public function Generate_Sig() {
            $api_secret = strtolower($this->private_key);
            $account_id = $this->account_id;
            $date  = date_create();
            $nonce = date_timestamp_get($date);
            $api_public = strtolower($this->public_key);

            return strtolower(hash_hmac('sha256', $nonce . $account_id . $api_public, $api_secret));
        }

        public function payment_callback()
        {
            global $woocommerce;
            $raw_post = file_get_contents('php://input');
            $decoded  = json_decode( $raw_post );
            $Order_Id = $decoded->OrderId;
 
            $order      = wc_get_order($Order_Id);
            $api_public = strtolower($this->public_key);
            try {
                if (!$order || !$order->get_id()) {
                    throw new Exception('Order #' . $Order_Id . ' does not exists');
                }
                if (strcmp($decoded->APIKey, strtolower($api_public)) !== 0) {
                    throw new Exception('API Keys Mismatch' . $decoded->APIKey . " : " . $api_public );
                }
                
                $callurl = 'https://checkout.coincorner.com/api/CheckOrder';
                
                $date  = date_create();
                $nonce = date_timestamp_get($date);
                
                $sig = $this->Generate_Sig();
                $body = array(
                    'APIKey' => $api_public,
                    'Signature' => $sig,
                    'Nonce' => $nonce,
                    'OrderId' => $Order_Id
                );

                $args = array(
                    'body' => $body,
                    'timeout' => '60',
                    'redirection' => '5',
                    'httpversion' => '1.1',
                    'blocking' => true,
                );
                $request = wp_remote_post( $callurl, $args );
    
                if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
                    return false;
                }
    
                $response = json_decode(wp_remote_retrieve_body( $request ), true);

                switch ($response["OrderStatusText"]) {
                    case 'Complete':
                        $order->add_order_note(__('Payment is confirmed on the network, and has been credited to the merchant. Purchased goods/services can be securely delivered to the buyer.', 'coincorner'));
                        $order->payment_complete();
                        break;
                    case 'Pending Confirmation':
                        $order->add_order_note(__('Payment Authorising.', 'coincorner'));
                        break;
					case 'Expired':
					case 'N/A':
                        $order->update_status('failed', 'Buyer did not pay within the required time and the invoice expired.');
                    case 'Cancelled':
                        $order->update_status('cancelled', 'Buyer canceled the invoice');
                        break;
                    case 'Refunded':
                        $order->update_status('refunded', 'Payment was refunded to the buyer.');
                        break;
                }
                http_response_code('200');
            }
            catch (Exception $e) {
                error_log('Caught exception: '. $e->getMessage());
                http_response_code('400');
            }
        }
        
        public function admin_options()
        {
            echo "    <div style=\"margin: 3px 0 3px 0; padding: 7px 0 12px 0; width: 100%; border-style: none none solid none; border-width: 1px; border-color: #ccc\">
                        <div style=\"margin: 0 25px 0 0\"><img src=\"{$this->banner}\"></div>
                    </div>
                    <b>Let your customers checkout using cryptocurrency. Powered by <a href=\"https://www.coincorner.com\" target=\"_blank\" title=\"Bitcoin Payment Gateway for eCommerce\">CoinCorner</a>.</b><br />
                    <br />
                    <table class=\"form-table\">";
            $this->generate_settings_html();
            echo "</table>";
        }
        
        function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woocommerce'),
                    'label' => __('Enable CoinCorner Payments', 'woocommerce'),
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no'
                ),
                'api_public_key' => array(
                    'title' => __('API Key', 'CoinCornerCheckout'),
                    'type' => 'text',
                    'description' => __('Your <a href="https://www.coincorner.com/" target="_blank">CoinCorner</a> v2 API (public) key. You can copy this from the API settings page under <a href="http://www.coincorner.com" target="_blank">Merchant Services &gt; API</a>.<br />'),
                    'default' => '',
                    'desc_tip' => false,
                    'placeholder' => 'API public key'
                ),
                'api_secret_key' => array(
                    'title' => __('API Secret Key', 'CoinCornerCheckout'),
                    'type' => 'text',
                    'description' => __('Your <a href="https://www.coincorner.com" target="_blank">CoinCorner.com</a> API secret key. You can copy this from the API settings page under <a href="http://www.coincorner.com" target="_blank">Merchant Services &gt; API</a>.<br />'),
                    'default' => '',
                    'desc_tip' => false,
                    'placeholder' => 'API secret key'
                ),
                'CoinCornerAccountId' => array(
                    'title' => __('User Id', 'CoinCornerCheckout'),
                    'type' => 'text',
                    'description' => __('Your <a href="https://www.coincorner.com" target="_blank">CoinCorner.com</a> Account Id. You can copy this from the API settings page under <a href="http://www.coincorner.com" target="_blank">Merchant Services &gt; API</a>.<br />'),
                    'default' => '',
                    'desc_tip' => false,
                    'placeholder' => 'CoinCorner User Id'
                ),
                'CoinCornerSettleCurrency' => array(
                    'title' => __('Settle Currency', 'CoinCornerCheckout'),
                    'type' => 'text',
                    'description' => __('The currency you want your orders to be settled in on your CoinCorner Account. Example: GBP <br />'),
                    'default' => 'GBP',
                    'desc_tip' => false,
                    'placeholder' => 'GBP'
                ), 
                'CoinCornerInvoiceCurrency' => array(
                    'title' => __('Invoice Currency', 'CoinCornerCheckout'),
                    'type' => 'text',
                    'description' => __('The currency you want your invoices to be displayed in. Example: GBP <br />'),
                    'default' => 'GBP',
                    'desc_tip' => false,
                    'placeholder' => 'GBP'
                )
            );
        }
        
        public function process_payment($order_id)
        {
            global $woocommerce;
            
            $order   = new WC_Order($order_id);
            $orderID = $order->get_id();
            
            $date  = date_create();
            $nonce = date_timestamp_get($date);
            
            $sig = $this->Generate_Sig();
            
            $amount      = floatval(number_format($order->get_total(), 8, '.', ''));

            $description = array();
            foreach ($order->get_items('line_item') as $item) {
                $description[] = $item['qty'] . ' x ' . $item['name'];
            }

            $ReturnUrl   = $this->get_return_url($order);
            $FailURL   = $order->get_cancel_order_url_raw();
            $callurl = 'https://checkout.coincorner.com/api/CreateOrder';
            $body  = array(
                'APIKey' => strtolower($this->public_key),
                'Signature' => $sig,
                'InvoiceCurrency' => $this->CoinCornerInvoiceCurrency,
                'SettleCurrency' => $this->CoinCornerSettleCurrency,
                'Nonce' => $nonce,
                'InvoiceAmount' => $amount,
                'NotificationURL' => $this->notify_url,
                'ItemDescription' => implode($description, ', '),
                'ItemCode' => '',
                'SuccessRedirectURL' => $ReturnUrl,
                'FailRedirectURL' => $FailURL,
                'OrderId' => $orderID
            );
            
            $args = array(
                'body' => $body,
                'timeout' => '60',
                'redirection' => '5',
                'httpversion' => '1.1',
                'blocking' => true,
            );
            $request = wp_remote_post( $callurl, $args );

            if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
                return false;
            }

            $response = json_decode(wp_remote_retrieve_body( $request ));
            $invoice = explode("/Checkout/", $response);
            if (count($invoice) < 2) {
                $message = "CoinCorner returned an error. Error: {$response}";
                wc_add_notice(" There was an error, please try again. If this happens again, please contact us.", 'error');
                $order = new WC_Order($orderID);
                $order->add_order_note("Payment could not be started: {$message}");
                return false;
            } else {
                $order = new WC_Order($orderID);
                $order->add_order_note("Customer redirected to CoinCorner.com. InvoiceID : " . $invoice[1]);
                return array(
                    'result' => 'success',
                    'redirect' => $response
                );
            }
        }
    }
    
    function addGateway($methods)
    {
        $methods[] = 'WC_Gateway_CoinCornerCheckout';
        return $methods;
    }
    
    // Payment method: Bitcoin
    add_filter('woocommerce_payment_gateways', 'addGateway');
}

?>