<?php

/** @noinspection PhpUndefinedClassInspection */

/**
 * @property Session $session
 * @property \Cart\Cart $cart
 * @property Loader $load
 * @property Language $language
 * @property Response $response
 * @property ModelSettingSetting $model_setting_setting
 * @property DB $db
 * @property Request $request
 * @property ModelExtensionShippingEcontDelivery $model_extension_shipping_econt_delivery
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
                'customer_address' => $this->session->data['shipping_address']['address_1'].' '.$this->session->data['shipping_address']['address_2'],
                'ignore_history' => true,
                'default_css' => true
            );
            $response['customer_info_url'] = $econtDeliverySettings['shipping_econt_delivery_system_url'] . '/customer_info.php?' . http_build_query($response['customer_info'], null, '&');
        } catch (Exception $exception) {
            $response = array('error' => $exception->getMessage());
        }
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($response));
    }

    public function beforeApi() {
        $orderId = intval($this->request->get['order_id']);
        if ($this->request->get['action'] === 'updateCustomerInfo') {
            if ($orderId > 0) {
                $this->db->query(sprintf("
                    INSERT INTO `%s`.`%secont_delivery_customer_info`
                    SET id_order = {$orderId},
                        customer_info = '%s'
                    ON DUPLICATE KEY UPDATE
                        customer_info = VALUES(customer_info)
                ",
                    DB_DATABASE,
                    DB_PREFIX,
                    json_encode($this->request->post)
                ));
            }
            $this->session->data['econt_delivery']['customer_info'] = $this->request->post;
        } else {
            if (empty($this->session->data['econt_delivery']['customer_info']) && $orderId > 0) {
                $customerInfo = $this->db->query(sprintf("
                    SELECT
                        ci.customer_info AS customerInfo
                    FROM `%s`.`%secont_delivery_customer_info` AS ci
                    WHERE TRUE
                        AND ci.id_order = {$orderId}
                    LIMIT 1
                ",
                    DB_DATABASE,
                    DB_PREFIX
                ));
                $customerInfo = json_decode($customerInfo->row['customerInfo'], true);
                if ($customerInfo) $this->session->data['econt_delivery']['customer_info'] = $customerInfo;
            }
        }

        if (!isset($this->session->data['payment_method']) && in_array($this->request->post['payment_method'], $this->session->data['payment_methods'])) $this->session->data['payment_method'] = $this->session->data['payment_methods'][$this->request->post['payment_method']];
        if (!isset($this->session->data['shipping_method']) && in_array($this->request->post['shipping_method'], $this->session->data['shipping_methods'])) $this->session->data['shipping_method'] = $this->session->data['shipping_methods'][$this->request->post['shipping_method']];

        if ($this->session->data['payment_method']['code'] === 'cod') $shippingCost = $this->session->data['econt_delivery']['customer_info']['shipping_price_cod'];
        else $shippingCost = $this->session->data['econt_delivery']['customer_info']['shipping_price'];
        $shippingCost = floatval($shippingCost);

        if (isset($this->session->data['shipping_methods']['econt_delivery'])) $this->session->data['shipping_methods']['econt_delivery']['quote']['econt_delivery']['cost'] = $shippingCost;
        if (isset($this->session->data['shipping_method']) && $this->session->data['shipping_method']['code'] === 'econt_delivery.econt_delivery') $this->session->data['shipping_method']['cost'] = floatval($shippingCost);

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($this->session->data['econt_delivery']['customer_info']));
    }

}