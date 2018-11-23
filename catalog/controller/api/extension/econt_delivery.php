<?php

/** @noinspection PhpUndefinedClassInspection */

/**
 * @property Session $session
 * @property \Cart\Cart $cart
 * @property Loader $load
 * @property Language $language
 * @property Response $response
 * @property ModelSettingSetting $model_setting_setting
 */
class ControllerApiExtensionEcontDelivery extends Controller {

    public function getCustomerInfoParams() {
        $response = array();
        try {
            $this->load->language('extension/shipping/econt_delivery');

            if (!isset($this->session->data['api_id'])) throw new Exception($this->language->get('catalog_controller_api_extension_econt_delivery_permission_error'));

            $this->load->model('setting/setting');
            $econtDeliverySettings = $this->model_setting_setting->getSetting('shipping_econt_delivery');

            $separatorPos = strpos($econtDeliverySettings['shipping_econt_delivery_private_key'], '@');
            if ($separatorPos === false) throw new Exception($this->language->get('catalog_controller_api_extension_econt_delivery_shop_id_error'));
            $shopId = substr($econtDeliverySettings['shipping_econt_delivery_private_key'], 0, $separatorPos);
            if (intval($shopId) <= 0) throw new Exception($this->language->get('catalog_controller_api_extension_econt_delivery_shop_id_error'));

            $totalPrice = 0;
            $totalWeight = 0;
            foreach ($this->cart->getProducts() as $product) {
                $totalPrice += floatval($product['total']);
                $totalWeight += floatval($product['weight']);
            }

            $response['customer_info'] = array(
                'id_shop' => $shopId,
                'order_total' => $totalPrice,
                'order_weight' => $totalWeight,
                'order_currency' => $this->session->data['currency'],
                'customer_company' => $this->session->data['shipping_address']['company'],
                'customer_name' => "{$this->session->data['shipping_address']['firstname']} {$this->session->data['shipping_address']['lastname']}",
                'customer_phone' => $this->session->data['customer']['telephone'],
                'customer_email' => $this->session->data['customer']['email'],
                'customer_country' => $this->session->data['shipping_address']['iso_code_3'],
                'customer_city_name' => $this->session->data['shipping_address']['city'],
                'customer_post_code' => $this->session->data['shipping_address']['postcode'],
                'customer_address' => $this->session->data['shipping_address']['address_1']
            );
            $response['customer_info_url'] = $econtDeliverySettings['shipping_econt_delivery_system_url'] . '/customer_info.php?' . http_build_query($response['customer_info'], null, '&');
        } catch (Exception $exception) {
            $response = array('error' => $exception->getMessage());
        }
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($response));
    }

}