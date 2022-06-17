<?php

/**
 * SMARTy Pay Payment Gateway
 *
 * Provides SMARTy Pay Payment Gateway
 *
 * @class  woocommerce_smartypayio
 * @package WooCommerce
 * @category Payment Gateways
 * @author SmartyPay.io
 * @license GPLv2
 */
class WC_SmartyPayIo_Payment_Gateway extends WC_Payment_Gateway
{

    public $version;
    public $adm_notice;
    private $webhook_url;
    private $nCurrencies;
    //private $ncheck = false;

    /**
     * Constructor
     */
    public function __construct()
    {

        $this->nCurrencies = require(plugin_basename('currencies.php'));
        $nCurrencies = array_keys($this->nCurrencies);

        $this->version = WC_GATEWAY_SMARTYPAYIO_VERSION;
        $this->id = 'smartypayio';
        $this->method_title = __('SMARTy Pay', 'smartypayio-payment-gateway');
        $this->method_description = sprintf(__('SMARTy Pay works by sending the user to %1$sSMARTy Pay%2$s to enter their payment information.', 'smartypayio-payment-gateway'), '<a href="https://smartypay.io/">', '</a>');
        $this->available_currencies = (array)apply_filters('woocommerce_gateway_smartypayio_available_currencies', $nCurrencies);

        $this->supports = array(
            'products'
        );

        $this->init_form_fields();
        $this->init_settings();

        $this->merchant_key = $this->get_option('merchant_key');
        $this->merchant_secret = $this->get_option('merchant_secret');
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->is_valid_for_use() ? 'yes' : 'no';
        $this->adm_notice = $this->is_valid_adm_notice() ? 'show' : 'no';

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        if ($this->adm_notice === 'show' && !empty($_REQUEST['save'])) {
            add_action('admin_notices', array($this, 'smartypayio_admin_notices'));
        }

        add_action('woocommerce_api_smartypayio_webhook', array($this, 'smartypayio_webhook'));

        /*if($this->ncheck === false){
            add_filter( 'woocommerce_currencies', array( $this, 'smartypayio_add_currencies' ) );
            add_filter('woocommerce_currency_symbol',  array( $this, 'smartypayio_add_symbol'), 10, 2 );
        }*/

        $this->webhook_url = get_bloginfo('url') . "/wc-api/smartypayio_webhook/";
    }

    /**
     * Initialise Gateway Settings Form Fields
     *
     * @since 1.0.0
     */
    public function init_form_fields()
    {
        $this->form_fields = array(
            'title' => array(
                'title' => __('Title', 'smartypayio-payment-gateway'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'smartypayio-payment-gateway'),
                'default' => __('SmartyPay', 'smartypayio-payment-gateway'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'smartypayio-payment-gateway'),
                'type' => 'text',
                'description' => __('This controls the description which the user sees during checkout.', 'smartypayio-payment-gateway'),
                'default' => '',
                'desc_tip' => true,
            ),
            'merchant_key' => array(
                'title' => __('Merchant Key', 'smartypayio-payment-gateway'),
                'type' => 'text',
                'description' => __('* Required. This is the merchant key, received from SMARTy Pay', 'smartypayio-payment-gateway'),
                'default' => '',
            ),
            'merchant_secret' => array(
                'title' => __('Merchant Secret', 'smartypayio-payment-gateway'),
                'type' => 'password',
                'description' => __('* Required. This is the merchant secret, received from SMARTy Pay', 'smartypayio-payment-gateway'),
                'default' => '',
            ),
        );
    }

    /**
     * Process the payment and return the result.
     *
     * @since 1.0.0
     */
    public function process_payment($order_id)
    {

        $order = wc_get_order($order_id);
        $processResult = ['result' => 'success', 'redirect' => ''];

        $amount = $order->get_total();

        $apiPublicKey = $this->merchant_key;
        $apiBackendSecret = $this->merchant_secret;

        $currency = get_woocommerce_currency();

        $nowInSec = strtotime("now");
        $sdate = (new \DateTime('UTC'))->add(new DateInterval('PT24H'))->format('Y-m-d\TH:i:s\+03:00');

        $body['amount'] = $amount . ' ' . trim($currency);
        $body['expiresAt'] = $sdate;
        $body['metadata'] = "$order_id";
        $body = json_encode($body);

        $messageToSign = $nowInSec . 'POST/integration/invoices' . $body;

        $signature = hash_hmac('sha256', $messageToSign, $apiBackendSecret);

        $url = 'https://api.smartypay.io/integration/invoices';

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'x-api-key: ' . $apiPublicKey,
            'x-api-sig: ' . $signature,
            'x-api-ts: ' . $nowInSec
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $result = json_decode($result, 1);

        if ($result['invoice']['id']) {
            $name = $order_id;
            $successUrl = $this->get_return_url($order);
            $failUrl = wc_get_cart_url();
            $lang = 'en';

            if (get_locale() == 'ru_RU') {
                $lang = 'ru';
            }

            $redirect = 'https://checkout.smartypay.io/invoice?invoice-id=' . $result['invoice']['id'] . '&name=' . $name . '&success-url=' . $successUrl . '&fail-url=' . $failUrl;

            $processResult['redirect'] = $redirect;
        } else {
            $processResult['result'] = 'fail';
        }

        return apply_filters('wc_gateway_smartypayio_process_payment_complete', $processResult, $order);
    }

    /**
     * Check SmartyPay ITN response.
     *
     * @since 1.0.0
     */
    public function smartypayio_webhook()
    {

        if (file_get_contents("php://input")) {
            $requestBody = !empty($_POST) ? $_POST : file_get_contents('php://input');
            $headers = getallheaders();
            $hash = $_SERVER['HTTP_X_SP_DIGEST'];

            if ($hash) {

                $signature = hash_hmac('sha256', $requestBody, $this->merchant_secret);

                if ($hash == $signature) {
                    $data = json_decode($requestBody, 1);

                    $order_id = (int)$data['metadata'];
                    $order = wc_get_order($order_id);

                    if ($data['status'] == 'Paid') {
                        $order->add_order_note(__('SMARTy Pay Invoice id: ' . $data['invoiceId'], 'smartypayio-payment-gateway'));
                        $order->payment_complete();
                        wc_reduce_stock_levels($order_id);
                    }

                    if ($data['status'] == 'Created') {
                        $order->update_status('pending');
                    }

                    if ($data['status'] == 'UnderPaid' || $data['status'] == 'OverPaid') {
                        $order->update_status('on-hold');
                    }

                    if ($data['status'] == 'Expired') {
                        $order->update_status('cancelled');
                    }

                    if ($data['status'] == 'Invalid') {
                        $order->update_status('failed');
                    }

                }
            }
        }

        header('HTTP/1.0 200 OK');
        flush();
    }

    /**
     * Admin Panel Options
     * - Options for bits like 'title' and availability on a country-by-country basis
     *
     * @since 1.0.0
     */
    public function admin_options()
    {
        if (in_array(get_woocommerce_currency(), $this->available_currencies)) {
            ?>

            <h3>
                <?php
                echo (!empty($this->method_title)) ? $this->method_title : __('Settings', 'woocommerce');
                ?>
            </h3>
            <?php
            echo (!empty($this->method_description)) ? wpautop($this->method_description) : '';
            ?>
            <table class="form-table">
                <tbody>
                <tr valign="top">
                    <th scope="row" class="titledesc">
                        <label for="woocommerce_smartypayio_enabled">You webhook url:</label>
                    </th>
                    <td class="forminp">
                        <fieldset>
                            <span><strong><?php echo $this->webhook_url; ?></strong></span>
                        </fieldset>
                    </td>
                </tr>
                </tbody>
            </table>
            <table class="form-table">
            <?php
            $this->generate_settings_html(); ?>
            </table><?php
        } else {
            $this->ncheck = true;
            ?>
            <h3><?php _e('SmartyPay', 'smartypayio-payment-gateway'); ?></h3>
            <div class="inline error"><p>
                    <strong><?php _e('Gateway Disabled', 'smartypayio-payment-gateway'); ?></strong> <?php echo sprintf(__('Choose USDT, BUSD, USDT, USDC or MNXe as your store currency in %1$sGeneral Settings%2$s to enable the SMARTy Pay Gateway.', 'smartypayio-payment-gateway'), '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=general')) . '">', '</a>'); ?>
                </p></div>
            <?php
        }
    }

    /**
     * is_valid_for_use()
     *
     * Check if this gateway is enabled and available in the base currency being traded with.
     *
     * @return bool
     * @since 1.0.0
     */
    public function is_valid_for_use()
    {
        $is_available = false;
        $is_available_currency = in_array(get_woocommerce_currency(), $this->available_currencies);

        if ($is_available_currency && $this->merchant_key && $this->merchant_secret) {
            $is_available = true;
        }

        return $is_available;
    }

    /**
     * is_valid_adm_notice()
     *
     * @return bool
     * @since 1.0.0
     */
    public function is_valid_adm_notice()
    {
        $is_available = false;
        $is_available_currency = in_array(get_woocommerce_currency(), $this->available_currencies);

        if ($is_available_currency && $this->merchant_key == 0 && $this->merchant_secret == 0 && ($this->enabled == 'yes' || $this->enabled == 'no')) {
            $is_available = true;
        }

        return $is_available;
    }

    /**
     *  Show possible admin notices
     */
    public function smartypayio_admin_notices()
    {
        if (empty($this->merchant_key)) {
            echo '<div class="error smartypayio-passphrase-message is-dismissible"><p>'
                . __('SMARTy Pay requires a Merchant Key to work.', 'smartypayio-payment-gateway')
                . '</p></div>';
        }
        if (empty($this->merchant_secret)) {
            echo '<div class="error smartypayio-passphrase-message is-dismissible"><p>'
                . __('SMARTy Pay required a Merchant Secret to work.', 'smartypayio-payment-gateway')
                . '</p></div>';
        }
        echo ob_get_clean();
    }

}
