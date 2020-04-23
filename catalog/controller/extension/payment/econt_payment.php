<?php

/**
 * Class ControllerExtensionPaymentEcontPayment
 * @property Request $request
 * @property Response $response
 * @property Session $session
 * @property Loader $load
 * @property Url $url
 * @property ModelCheckoutOrder $model_checkout_order
 * @property ModelExtensionShippingEcontDelivery model_extension_shipping_econt_delivery
 */
class ControllerExtensionPaymentEcontPayment extends Controller {

    /**
     * @return mixed
     * @throws Exception
     */
    public function index() {
        $this->load->model('extension/shipping/econt_delivery');
        $aData = $this->model_extension_shipping_econt_delivery->prepareOrder();
        $order = $aData['order'];
        $aReqData = json_encode(['order' => $order, 'shopName' => $this->config->get('config_name')]);

        $url = "/services/PaymentsService.createPayment.json";

        $response =  json_decode($this->sendRequest($url, $aReqData), true);

        if (array_key_exists('paymentIdentifier', $response)) {
            $this->session->data['econt_payment_paymentIdentifier'] = $response['paymentIdentifier'];
        }

		return $this->load->view('extension/payment/econt_payment', ['econt_payment_paymentURI' => $response['paymentURI']]);
	}

    /**
     *
     * @throws Exception
     */
    public function confirm() {
		$json = array();

		if ($this->session->data['payment_method']['code'] == 'econt_payment') {

            $aReqData = json_encode(['paymentIdentifier' => $this->session->data['econt_payment_paymentIdentifier']]);
//            var_dump($aReqData); die();
            $url = "/services/PaymentsService.confirmPayment.json";
            $response =  json_decode($this->sendRequest($url, $aReqData), true);
            $this->session->data['econt_payment_paymentToken'] = $response['paymentToken'];

            unset($this->session->data['econt_payment_paymentIdentifier']);

			$this->load->model('checkout/order');

			$this->model_checkout_order->addOrderHistory($this->session->data['order_id'], $this->config->get('payment_econt_payment_order_status_id'));
		
			$json['redirect'] = $this->url->link('checkout/success');
		}
		
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
//        $this->response->redirect($this->url->link('checkout/success'));
	}

    /**
     * @param $url
     * @param $aData
     * @return bool|string
     * @throws Exception
     */
    public function sendRequest($url, $aData)
    {
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
            curl_setopt($curl, CURLOPT_POSTFIELDS, $aData);
            curl_setopt($curl, CURLOPT_TIMEOUT, 6);
            $response = curl_exec($curl);
            curl_close($curl);

            return $response;

        } catch (Exception $exception) {
            $logger = new Log('econt_delivery.log');
            $logger->write(sprintf('Curl failed with error [%d] %s', $exception->getCode(), $exception->getMessage()));
        }
    }
}
