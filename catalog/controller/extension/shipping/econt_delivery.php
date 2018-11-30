<?php

/** @noinspection PhpUndefinedClassInspection */

/**
 * @property Request $request
 * @property Response $response
 * @property Session $session
 * @property \Cart\Cart $cart
 * @property ModelCheckoutOrder $model_checkout_order
 * @property ControllerApiExtensionEcontDelivery
 * @property Loader $load
 * @property Language $language
 * @property ModelCatalogProduct $model_catalog_product
 * @property Url $url
 * @property ModelSettingSetting $model_setting_setting
 * @property ModelExtensionShippingEcontDelivery $model_extension_shipping_econt_delivery
 * @property DB $db
 */
class ControllerExtensionShippingEcontDelivery extends Controller {

    public function updateOrder(/** @noinspection PhpUnusedParameterInspection */ $eventRoute, &$data) {
        $orderId = intval($this->request->get['order_id']);
        if ($orderId <= 0) {
            if ($this->request->get['route'] === 'api/order/add') {
                $orderId = intval(reset($data));
                if ($orderId <= 0) return;

                if (!empty($this->session->data['econt_delivery']['customer_info'])) $this->db->query(sprintf("
                    INSERT INTO `%s`.`%secont_delivery_customer_info`
                    SET id_order = {$orderId},
                        customer_info = '%s'
                    ON DUPLICATE KEY UPDATE
                        customer_info = VALUES(customer_info)
                ",
                    DB_DATABASE,
                    DB_PREFIX,
                    json_encode($this->session->data['econt_delivery']['customer_info'])
                ));
            }
        }

        $orderData = $this->model_checkout_order->getOrder($orderId);
        if (empty($orderData) || $orderData['shipping_code'] !== 'econt_delivery.econt_delivery') return;

        $this->load->model('extension/shipping/econt_delivery');
        $customerInfo = $this->session->data['econt_delivery']['customer_info'];
        if (empty($customerInfo)) {
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
        }
        if (!$customerInfo || empty($customerInfo['id'])) return;

        $this->load->language('extension/shipping/econt_delivery');
        $order = array(
            'customerInfo' => array(
                'id' => $customerInfo['id']
            ),
            'orderNumber' => $orderData['order_id'],
            'shipmentDescription' => sprintf("{$orderData['store_name']} - %s #{$orderData['order_id']}", $this->language->get('econt_delivery_order')),
            'status' => $orderData['order_status'],
            'orderTime' => $orderData['date_added'],
            'currency' => $orderData['currency_code'],
            'cod' => ($orderData['payment_code'] === 'cod'),
            'partialDelivery' => 1,
            'items' => array()
        );
        $orderProducts = $this->model_checkout_order->getOrderProducts($orderId);
        if (!empty($orderProducts)) {
            $this->load->model('catalog/product');
            foreach ($orderProducts as $orderProduct) {
                $productData = $this->model_catalog_product->getProduct($orderProduct['product_id']);
                if (empty($productData)) continue;

                $order['items'][] = array(
                    'name' => $productData['name'],
                    'SKU' => $productData['sku'],
                    'URL' => $this->url->link('product/product', http_build_query(array(
                        'product_id' => $productData['product_id']
                    )), true),
                    'count' => $orderProduct['quantity'],
                    'totalPrice' => $orderProduct['total'],
                    'totalWeight' => floatval($productData['weight'] * $orderProduct['quantity'])
                );
            }
        }

        $this->load->model('setting/setting');
        $settings = $this->model_setting_setting->getSetting('shipping_econt_delivery');
        if (empty($settings['shipping_econt_delivery_system_url']) || empty($settings['shipping_econt_delivery_private_key'])) return;

        $response = [];
        try {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, "{$settings['shipping_econt_delivery_system_url']}/services/OrdersService.updateOrder.json");
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                "Authorization: {$settings['shipping_econt_delivery_private_key']}"
            ]);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($order));
            curl_setopt($curl, CURLOPT_TIMEOUT, 6);
            $response = curl_exec($curl);
            curl_close($curl);
        } catch (Exception $exception) {
            $logger = new Log('econt_delivery.log');
            $logger->write(sprintf('Curl failed with error [%d] %s', $exception->getCode(), $exception->getMessage()));
        }

        return json_decode($response, true);
    }

    public function afterViewCheckoutBilling($route,$templateParams,$html) {
        return preg_replace("#<div (class=\"checkbox\">\\s+<label>\\s+<input\\s+type=\"checkbox\"\\s+name=\"shipping_address\")#i",'<div style="display:none !important;" \1',$html);
    }
    public function beforeCartSaveShipping() {
        if($this->request->request['shipping_method'] == 'econt_delivery.econt_delivery') {
            $this->session->data['econt_delivery']['customer_info'] = json_decode(html_entity_decode($this->request->request['econt_delivery_shipping_info']),true);
            if(!$this->session->data['econt_delivery']['customer_info']) {
                $this->load->language('extension/shipping/econt_delivery');
                $this->response->addHeader('Content-Type: application/json');
                $this->response->setOutput(json_encode(array('error' => array('warning' => $this->language->get('err_missing_customer_info')))));
                return false;
            }
            $this->session->data['shipping_address']['firstname'] = $this->session->data['econt_delivery']['customer_info']['name'];
            $this->session->data['shipping_address']['lastname'] = '';
            $this->session->data['shipping_address']['iso_code_3'] = $this->session->data['econt_delivery']['customer_info']['country_code'];
            $this->session->data['shipping_address']['city'] = $this->session->data['econt_delivery']['customer_info']['city_name'];
            $this->session->data['shipping_address']['postcode'] = $this->session->data['econt_delivery']['customer_info']['post_code'];
            if($this->session->data['econt_delivery']['customer_info']['office_code']) {
                $this->session->data['shipping_address']['address_1'] = 'Econt office: '.$this->session->data['econt_delivery']['customer_info']['office_code'];
                $this->session->data['shipping_address']['address_2'] = $this->session->data['econt_delivery']['customer_info']['address'];
            } else {
                $this->session->data['shipping_address']['address_1'] = $this->session->data['econt_delivery']['customer_info']['address'];
            }
        }
    }

    public function afterCheckoutConfirm() {
        if($this->session->data['shipping_method']['code'] == 'econt_delivery.econt_delivery') {
            if(empty($this->session->data['econt_delivery']['customer_info'])) throw new Exception;
            if (($orderId = @intval($this->session->data['order_id'])) > 0) {
                $this->db->query(sprintf("
                    INSERT INTO `%s`.`%secont_delivery_customer_info`
                    SET id_order = {$orderId},
                        customer_info = '%s'
                    ON DUPLICATE KEY UPDATE
                        customer_info = VALUES(customer_info)
                ",
                    DB_DATABASE,
                    DB_PREFIX,
                    $this->db->escape(json_encode($this->session->data['econt_delivery']['customer_info']))
                ));
            }
        }
    }

    public function beforeCartSavePayment() {
        if($this->session->data['shipping_method']['code'] == 'econt_delivery.econt_delivery') {
            $cod = $this->request->request['payment_method'] == 'cod' ? '_cod' : '';
            $this->session->data['shipping_method']['cost'] = $this->session->data['econt_delivery']['customer_info']['shipping_price'.$cod];
        }
    }
}