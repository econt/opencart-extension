<?php

/** @noinspection PhpUnused */
/** @noinspection PhpUnusedParameterInspection */

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

    public function afterModelCheckoutOrderAddHistory($eventRoute, &$data) {
        $this->load->model('extension/shipping/econt_delivery');
        $this->load->model('setting/setting');

        $settings = $this->model_setting_setting->getSetting('shipping_econt_delivery');
        $data = $this->model_extension_shipping_econt_delivery->prepareOrder();
        $order = $data['order'];
        $orderData = $data['orderData'];
        $customerInfo = $data['customerInfo'];

        if ((
                empty($settings['shipping_econt_delivery_system_url'])
            ||  empty($settings['shipping_econt_delivery_private_key'])
        ) || (
                empty($order)
            ||  empty($orderData)
            ||  empty($customerInfo)
        )) return json_decode(null, true);

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

        if ($orderData['shipping_code'] === 'econt_delivery.econt_delivery' && $orderData['order_id']) {
            $this->session->data['shipping_address']['address_1'] = ($customerInfo['office_code'] ? $customerInfo['office_name'] : $customerInfo['address']);
            $this->session->data['shipping_address']['address_2'] = ($customerInfo['office_code'] ? $customerInfo['address'] : '');
            $this->session->data['shipping_address']['city'] = $customerInfo['city_name'];
            $this->session->data['shipping_address']['postcode'] = $customerInfo['post_code'];
            $this->db->query(sprintf("
                UPDATE `%s`.`%sorder` AS o
                SET o.shipping_address_1 = '%s',
                    o.shipping_address_2 = '%s',
                    o.shipping_city = '%s',
                    o.shipping_postcode = '%s'
                WHERE TRUE
                    AND o.order_id = %d
            ",
                DB_DATABASE,
                DB_PREFIX,
                $this->db->escape($this->session->data['shipping_address']['address_1']),
                $this->db->escape($this->session->data['shipping_address']['address_2']),
                $this->db->escape($this->session->data['shipping_address']['city']),
                $this->db->escape($this->session->data['shipping_address']['postcode']),
                $orderData['order_id']
            ));
        }

        return json_decode($response, true);
    }

    public function afterViewCheckoutBilling($route, $templateParams, $html) {
        return preg_replace("#<div (class=\"checkbox\">\\s+<label>\\s+<input\\s+type=\"checkbox\"\\s+name=\"shipping_address\")#i",'<div style="display:none !important;" \1',$html);
    }

    public function updateShippingPrice($data) {
        if($this->session->data['shipping_method']['code'] != 'econt_delivery.econt_delivery') {
            return;
        }

        $paymentData = null;

        if (array_key_exists("econt_delivery_temporary_shipping_price", $_COOKIE)) {
            $paymentData = json_decode($_COOKIE["econt_delivery_temporary_shipping_price"]);
        }

        $paymentMethod = $this->session->data['payment_method']['code'];

        if ($paymentData && $paymentMethod === 'cod') {
            $this->session->data['shipping_method']['cost'] = $paymentData->shipping_price_cod;
        } elseif ($paymentData && $paymentMethod === 'econt_payment') {
            $this->session->data['shipping_method']['cost'] = $paymentData->shipping_price_cod_e;
        } else {
            $this->session->data['shipping_method']['cost'] = $paymentData ? $paymentData->shipping_price : 0;
        }
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
                $this->session->data['shipping_address']['address_1'] = 'Econt office: ' . $this->session->data['econt_delivery']['customer_info']['office_name'];
                $this->session->data['shipping_address']['address_2'] = $this->session->data['econt_delivery']['customer_info']['address'];
            } else {
                $this->session->data['shipping_address']['address_1'] = $this->session->data['econt_delivery']['customer_info']['address'];
            }
        }
    }

    public function afterCheckoutConfirm() {
        if ($this->session->data['shipping_method']['code'] == 'econt_delivery.econt_delivery') {
            if (empty($this->session->data['econt_delivery']['customer_info'])) throw new Exception;

            $orderId = @intval($this->session->data['order_id']);
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
                    $this->db->escape(json_encode($this->session->data['econt_delivery']['customer_info']))
                ));
            }
        }
    }

    public function beforeCartSavePayment() {
        if (!array_key_exists('econt_delivery', $this->session->data)) {
            return;
        }

        if($this->session->data['shipping_method']['code'] == 'econt_delivery.econt_delivery') {
            $postfix_map = [
                'cod' => '_cod',
                'econt_payment' => '_cod_e'
            ];
            $cod = @array_key_exists($this->request->request['payment_method'], $postfix_map) ? $postfix_map[$this->request->request['payment_method']] : '';
            $this->session->data['shipping_method']['cost'] = $this->session->data['econt_delivery']['customer_info']['shipping_price'.$cod];

        }
    }

    public function getCustomerInfoParams() {
        $response = array();
        try {
            $this->load->language('extension/shipping/econt_delivery');

            if (!isset($this->session->data['api_id'])) throw new Exception($this->language->get('text_catalog_controller_api_extension_econt_delivery_permission_error'));

            $this->load->model('setting/setting');
            $econtDeliverySettings = $this->model_setting_setting->getSetting('shipping_econt_delivery');

            $separatorPos = strpos($econtDeliverySettings['shipping_econt_delivery_private_key'], '@');
            if ($separatorPos === false) throw new Exception($this->language->get('text_catalog_controller_api_extension_econt_delivery_shop_id_error'));
            $shopId = substr($econtDeliverySettings['shipping_econt_delivery_private_key'], 0, $separatorPos);
            if (intval($shopId) <= 0) throw new Exception($this->language->get('text_catalog_controller_api_extension_econt_delivery_shop_id_error'));

            $this->load->model('extension/shipping/econt_delivery');
            $response['customer_info'] = array(
                'id_shop' => $shopId,
                'order_total' => $this->model_extension_shipping_econt_delivery->getOrderTotal(),
                'order_weight' => $this->cart->getWeight(),
                'order_currency' => @$this->session->data['currency'],
                'customer_company' => @$this->session->data['shipping_address']['company'],
                'customer_name' => @$this->session->data['shipping_address']['firstname'] . ' ' . @$this->session->data['shipping_address']['lastname'],
                'customer_phone' => @$this->session->data['customer']['telephone'],
                'customer_email' => @$this->session->data['customer']['email'],
                'customer_country' => @$this->session->data['shipping_address']['iso_code_3'],
                'customer_city_name' => @$this->session->data['shipping_address']['city'],
                'customer_post_code' => @$this->session->data['shipping_address']['postcode'],
                'customer_address' => @$this->session->data['shipping_address']['address_1'] . ' ' . @$this->session->data['shipping_address']['address_2'],
                'ignore_history' => true,
                'default_css' => true
            );
            $officeCode = trim(@$this->session->data['econt_delivery']['customer_info']['office_code']);
            if (!empty($officeCode)) $response['customer_info']['customer_office_code'] = $officeCode;
            $zip = trim(@$this->session->data['econt_delivery']['customer_info']['zip']);
            if (!empty($zip)) $response['customer_info']['customer_zip'] = $zip;
            $response['customer_info_url'] = $econtDeliverySettings['shipping_econt_delivery_system_url'] . '/customer_info.php?' . http_build_query($response['customer_info'], null, '&');
        } catch (Exception $exception) {
            $response = array('error' => $exception->getMessage());
        }
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($response));

        return false;
    }

    public function beforeApi() {
        $this->loadEcontDeliveryData();
        return false;
    }
    public function loadEcontDeliveryData() {
        $orderId = @intval($this->request->get['order_id']);
        if (@$this->request->get['action'] === 'updateCustomerInfo') {
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
            if (array_key_exists('econt_delivery', $this->session->data) && empty($this->session->data['econt_delivery']['customer_info']) && $orderId > 0) {
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

        if (!@$this->session->data['payment_method'] && is_array(@$this->session->data['payment_methods']) && in_array(@$this->request->post['payment_method'], @$this->session->data['payment_methods'])) $this->session->data['payment_method'] = $this->session->data['payment_methods'][$this->request->post['payment_method']];
        if (!@$this->session->data['shipping_method'] && is_array(@$this->session->data['shipping_methods']) && in_array(@$this->request->post['shipping_method'], @$this->session->data['shipping_methods'])) $this->session->data['shipping_method'] = $this->session->data['shipping_methods'][$this->request->post['shipping_method']];
        $shippingCost = 0;
        if (isset($this->session->data['payment_method']['code'])) {
            if ($this->session->data['payment_method']['code'] === 'cod') {
                $shippingCost = @$this->session->data['econt_delivery']['customer_info']['shipping_price_cod'];
            } elseif ($this->session->data['payment_method']['code'] === 'econt_payment') {
                $shippingCost = @$this->session->data['econt_delivery']['customer_info']['shipping_price_cod_e'];
            } else {
                $shippingCost = @$this->session->data['econt_delivery']['customer_info']['shipping_price'];
            }
        }

        $shippingCost = floatval($shippingCost);

        if (isset($this->session->data['shipping_methods']['econt_delivery'])) $this->session->data['shipping_methods']['econt_delivery']['quote']['econt_delivery']['cost'] = $shippingCost;
        if (isset($this->session->data['shipping_method']) && $this->session->data['shipping_method']['code'] === 'econt_delivery.econt_delivery') $this->session->data['shipping_method']['cost'] = floatval($shippingCost);

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode(@$this->session->data['econt_delivery']['customer_info']));
    }

}