<?php

/** @noinspection PhpUnused */

/**
 * Class ControllerExtensionPaymentEcontPayment
 *
 * @property Request $request
 * @property Response $response
 * @property Session $session
 * @property Loader $load
 * @property Url $url
 * @property Config $config
 * @property Language $language
 * @property DB $db
 *
 * @property ModelCheckoutOrder $model_checkout_order
 * @property \Cart\Cart $cart
 * @property ModelExtensionShippingEcontDelivery $model_extension_shipping_econt_delivery
 * @property ModelSettingSetting $model_setting_setting
 */
class ControllerExtensionPaymentEcontPayment extends Controller {

    private function getProtectionHash($params) {
        $this->load->model('setting/setting');

        $econtShippingSettings = $this->model_setting_setting->getSetting('shipping_econt_delivery');
        return hash_hmac('sha1', serialize($params), $econtShippingSettings['shipping_econt_delivery_private_key']);
    }

    public function sendRequest($url, $data) {
        $this->load->model('setting/setting');
        $settings = $this->model_setting_setting->getSetting('shipping_econt_delivery');

        try {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $settings['shipping_econt_delivery_system_url'] . $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                "Authorization: {$settings['shipping_econt_delivery_private_key']}"
            ]);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            curl_setopt($curl, CURLOPT_TIMEOUT, 6);
            $response = curl_exec($curl);
            curl_close($curl);

            return $response;
        } catch (Exception $exception) {
            $logger = new Log('econt_delivery.log');
            $logger->write(sprintf('Curl failed with error [%d] %s', $exception->getCode(), $exception->getMessage()));
        }

        return array();
    }

    public function index() {
        $this->load->language('extension/payment/econt_payment');
        $this->load->model('extension/shipping/econt_delivery');

        $templateData = array(
            'button_confirm' => $this->language->get('button_confirm'),
            'button_confirm_loading' => $this->language->get('button_confirm_loading'),
        );
        try {
            $shopOrderData = $this->model_extension_shipping_econt_delivery->prepareOrder();
            $response =  json_decode($this->sendRequest('/services/PaymentsService.createPayment.json', json_encode(array(
                'order' => $shopOrderData['order'],
                'shopName' => $this->config->get('config_name')
            ))), true);

            $paymentIdentifier = @trim($response['paymentIdentifier']);
            $paymentURL = @trim($response['paymentURI']);
            if (empty($paymentIdentifier) || empty($paymentURL)) {
                $errorMessage = @trim($response['message']);
                throw new Exception(($errorMessage ? $errorMessage : $this->language->get('error_payment_generation_error')));
            }

            $this->session->data['econt_delivery']['payment'] = array(
                'paymentIdentifier' => $paymentIdentifier,
                'paymentURI' => $paymentURL
            );
        } catch (Exception $exception) {
            $templateData['econtPaymentError'] = $exception->getMessage();
        }

        return $this->load->view('extension/payment/econt_payment', $templateData);
	}

	public function confirm() {
        $this->load->model('setting/setting');
        $this->load->model('checkout/order');
        $this->load->language('extension/payment/econt_payment');

        $response = array();
        try {
            $orderID = @intval($this->session->data['order_id']);
            if ($orderID <= 0) throw new Exception($this->language->get('error_payment_error'));

            $paymentIdentifier = @trim($this->session->data['econt_delivery']['payment']['paymentIdentifier']);
            $paymentURL = @trim($this->session->data['econt_delivery']['payment']['paymentURI']);
            if (empty($paymentIdentifier) || empty($paymentURL)) throw new Exception($this->language->get('error_payment_error'));

            $econtPaymentSettings = $this->model_setting_setting->getSetting('payment_econt_payment');
            if (empty($econtPaymentSettings) || !is_array($econtPaymentSettings)) throw new Exception($this->language->get('error_payment_error'));

            $paymentEMail = @trim($this->session->data['econt_delivery']['customer_info']['email']);

            $this->model_checkout_order->addOrderHistory($orderID, $econtPaymentSettings['payment_econt_payment_order_status_payment_processing_id'], 'GECD: Payment Processing / ГЕНП: Очаква се плащане');

            $this->cart->clear();
            unset($this->session->data['shipping_method']);
            unset($this->session->data['shipping_methods']);
            unset($this->session->data['payment_method']);
            unset($this->session->data['payment_methods']);
            unset($this->session->data['guest']);
            unset($this->session->data['comment']);
            unset($this->session->data['order_id']);
            unset($this->session->data['coupon']);
            unset($this->session->data['reward']);
            unset($this->session->data['voucher']);
            unset($this->session->data['vouchers']);
            unset($this->session->data['totals']);
            unset($this->session->data['econt_delivery']);

            $parsetPaymentURI = @parse_url($paymentURL);
            $parsetPaymentURIParams = array();
            @parse_str($parsetPaymentURI['query'], $parsetPaymentURIParams);
            $currentTimestamp = time();
            $boricaResponseURL = $this->config->get('site_url') . 'index.php?' . @http_build_query(array(
                'route' => 'extension/payment/econt_payment/paymentResponse',
                'order_id' => $orderID,
                'GECDPaymentIdentifier' => $paymentIdentifier,
                'GECDTime' => $currentTimestamp,
                'GECDHash' => $this->getProtectionHash(array(
                    'order_id' => $orderID,
                    'GECDPaymentIdentifier' => (string)$paymentIdentifier,
                    'GECDTime' => (string)$currentTimestamp,
                ))
            ));

            $response['redirect'] = str_replace($parsetPaymentURI['query'], http_build_query(array_merge($parsetPaymentURIParams, array(
                'successUrl' => $boricaResponseURL,
                'failUrl' => $boricaResponseURL,
                'eMail' => $paymentEMail
            ))), $paymentURL);
        } catch (Exception $exception) {
            $response['error'] = $exception->getMessage();
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($response));
    }

    public function paymentResponse() {
        $this->load->model('setting/setting');
        $this->load->model('checkout/order');
        $this->load->language('extension/payment/econt_payment');

        $orderID = @intval($this->request->get['order_id']);
        $paymentIdentifier = @trim($this->request->get['GECDPaymentIdentifier']);
        if ($orderID <= 0 || empty($paymentIdentifier) || $this->getProtectionHash(array(
            'order_id' => $orderID,
            'GECDPaymentIdentifier' => (string)$paymentIdentifier,
            'GECDTime' => (string)@trim($this->request->get['GECDTime'])
        )) !== @trim($this->request->get['GECDHash'])) {
            header(sprintf('Location: %s', $this->url->link('error/not_found')));
            die();
        }

        $econtPaymentSettings = $this->model_setting_setting->getSetting('payment_econt_payment');
        if (empty($econtPaymentSettings) || !is_array($econtPaymentSettings)) {
            header(sprintf('Location: %s', $this->url->link('error/not_found')));
            die();
        }

        $response =  json_decode($this->sendRequest('/services/PaymentsService.confirmPayment.json', json_encode(array(
            'paymentIdentifier' => $paymentIdentifier
        ))), true);

        if (empty($response['paymentToken'])) {
            header(sprintf('Location: %s', (!empty($response['message']) ? $this->url->link('checkout/failure') : $this->url->link('error/not_found'))));
            die();
        }

        $this->db->query(sprintf("
            UPDATE %s.%secont_delivery_customer_info AS ci
            SET ci.payment_token = '%s'
            WHERE TRUE
                AND ci.id_order = {$orderID}
        ",
            DB_DATABASE,
            DB_PREFIX,
            $this->db->escape($response['paymentToken'])
        ));
        $this->model_checkout_order->addOrderHistory($orderID, $econtPaymentSettings['payment_econt_payment_order_status_payment_completed_id'], 'GECD: Payment Completed / ГЕНП: Успешно плащане');

        header(sprintf('Location: %s', $this->url->link('checkout/success')));
        die();
    }

}
