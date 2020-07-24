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
//        if(!isset($this->request->get['order_id'])) {
//            return $this->response->redirect($this->url->link('common/home'));
//        }

        $this->load->language('extension/payment/econt_payment');
        $this->load->model('extension/shipping/econt_delivery');
        $aData = $this->model_extension_shipping_econt_delivery->prepareOrder();
        $order = $aData['order'];
        $aReqData = json_encode(['order' => $order, 'shopName' => $this->config->get('config_name')]);

        $url = "/services/PaymentsService.createPayment.json";

        $response =  json_decode($this->sendRequest($url, $aReqData), true);

        if (isset($response['paymentIdentifier'])) {
            $this->session->data['econt_payment_paymentIdentifier'] = $response['paymentIdentifier'];
        }

        $sUrlSuccess = urlencode($this->config->get('site_url') . 'index.php?route=extension/payment/econt_payment/success');
        $sUrlReject = urlencode($this->config->get('site_url') . 'index.php?route=extension/payment/econt_payment/error');

        $this->session->data['econt_payment_paymentURI'] = $response['paymentURI'] . '&successUrl=' . $sUrlSuccess . '&failUrl=' . $sUrlReject;

		return $this->load->view('extension/payment/econt_payment', [
		    'econt_payment_paymentURI' => $this->session->data['econt_payment_paymentURI'],
            'buttonText' => $this->language->get('text_title')
        ]);

	}

    /**
     * @todo clear code
     * @throws Exception
     */
    public function confirm() {
		if (isset($this->session->data['payment_method']) && $this->session->data['payment_method']['code'] == 'econt_payment') {
			$this->load->model('checkout/order');
			$this->load->model('extension/payment/econt_payment');

            $aReqData = json_encode(['paymentIdentifier' => $this->session->data['econt_payment_paymentIdentifier']]);
            $url = "/services/PaymentsService.confirmPayment.json";
            $response =  json_decode($this->sendRequest($url, $aReqData), true);

            $this->session->data['econt_payment_paymentToken'] = $response['paymentToken'];

            $order_id = $this->session->data['order_id'];
            $status = $this->config->get('payment_econt_payment_order_status_id');
            $this->model_checkout_order->addOrderHistory($order_id, $status);

            $this->model_extension_payment_econt_payment->updateOrder($order_id, $response['paymentToken']);
            unset($this->session->data['econt_payment_paymentIdentifier']);
		} else {
		    $this->response->redirect($this->url->link('extension/payment/econt_payment/error'));
        }

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

    public function success()
    {
        try {
            $this->confirm();
        } catch (Exception $exception) {
            $logger = new Log('econt_delivery.log');
            $logger->write(sprintf('Curl failed with error [%d] %s', $exception->getCode(), $exception->getMessage()));
        }

        if (isset($this->session->data['order_id'])) {
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
        }

        $this->response->setOutput($this->load->view('extension/payment/econt_payment', $this->getViewData(true)));
    }

    public function error()
    {
        $this->response->setOutput($this->load->view('extension/payment/econt_payment', $this->getViewData(false)));
    }

    /**
     * @param $data
     * @return mixed
     */
    public function getViewData($success = false)
    {
        // Optional. This calls for your language file
        $this->load->language('extension/payment/econt_payment');
        $this->document->setTitle($this->language->get('heading_title'));

        // All the necessary page elements
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');
        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');
        // Get "heading_title" from language file
        $data['heading_title'] = $this->language->get('heading_title');
        $data['loadView'] = true;
        $data['success'] = $success;
        $data['home'] = $this->url->link('common/home');

        if ($success) {
            $data['status'] = 'Success';
            $data['statusMessage'] = $this->language->get('status_message_success');
            $data['button']['text'] = $this->language->get('button_text_success');
            $data['button']['href'] = $this->url->link('common/home');
        } else {
            $data['status'] = 'Error';
            $data['statusMessage'] = $this->language->get('status_message_error');
            $data['button']['text'] = $this->language->get('button_text_error');
            $data['button']['href'] = $this->session->data['econt_payment_paymentURI'];
            $data['link']['text'] = $this->language->get('button_text_success');
            $data['link']['href'] = $this->url->link('common/home');
        }

        return $data;
    }
}
