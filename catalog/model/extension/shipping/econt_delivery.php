<?php

/** @noinspection PhpUndefinedClassInspection */

/**
 * @property Loader $load
 * @property DB $db
 * @property Config $config
 * @property Language $language
 * @property Session $session
 * @property \Cart\Cart $cart
 * @property Request $request
 * @property Url $url
 *
 * @property ModelSettingSetting $model_setting_setting
 * @property ModelAccountOrder $model_account_order
 * @property ModelCheckoutOrder $model_checkout_order
 */
class ModelExtensionShippingEcontDelivery extends Model {

    private $oneStepCheckoutModuleEnabled = false;
    private $econtDeliveryOneStepCheckoutEnabled = false;

    public function getQuote($address) {

        $geoZoneId = intval($this->config->get('shipping_econt_delivery_geo_zone_id'));

        if ($geoZoneId !== 0 && !empty($address['country_id']) && !empty($address['zone_id'])) {

            $result = $this->db->query("
                SELECT
                    COUNT(z.zone_to_geo_zone_id) AS zoneIdsCount
                FROM ".DB_PREFIX."zone_to_geo_zone AS z
                WHERE TRUE
                    AND z.geo_zone_id = {$geoZoneId}
                    AND z.country_id = ".(int)$address['country_id']."
                    AND z.zone_id IN (0, ".(int)$address['zone_id'].")
                LIMIT 1
            ");

            if (intval($result->row['zoneIdsCount']) <= 0) {
                return array();
            }
        }
	
	    $this->load->model('setting/setting');
	    $settings = $this->model_setting_setting->getSetting('shipping_econt_delivery');
        $this->econtDeliveryOneStepCheckoutEnabled = $settings['shipping_econt_delivery_checkout_mode'] == 'onestep';

	    if($this->econtDeliveryOneStepCheckoutEnabled && $this->session->data['shipping_address']['firstname'] == '8a9ggua0sjm$Fn'){
		    $this->session->data['shipping_address']['company']     = '';
		    $this->session->data['shipping_address']['firstname']   = '';
		    $this->session->data['shipping_address']['lastname']    = '';
		    $this->session->data['shipping_address']['iso_code_3']  = '';
		    $this->session->data['shipping_address']['city']        = '';
		    $this->session->data['shipping_address']['postcode']    = '';
		    $this->session->data['shipping_address']['address_1']   = '';
		    $this->session->data['shipping_address']['address_2']   = '';
	    }
        
        $this->oneStepCheckoutModuleEnabled = $this->request->request['route'] == 'extension/quickcheckout/shipping_method';
        $this->load->language('extension/shipping/econt_delivery');

        if(in_array($this->request->request['route'], ['journal3/checkout/save', 'checkout/shipping_method', 'checkout/checkout']) || $this->oneStepCheckoutModuleEnabled || $this->econtDeliveryOneStepCheckoutEnabled) {

	        $customer = $this->registry->get('customer');
	        
            if($customer && $customer->getEmail()) {
		        $email = $customer->getEmail();
		        $phone = $customer->getTelephone();
            } else {
	            $email = $this->session->data['shipping_address']['email'] ?? $this->session->data['guest']['email'] ?? '';
	            $phone = $this->session->data['shipping_address']['telephone'] ?? $this->session->data['guest']['telephone'] ?? '';
            }

            $keys = explode('@',$this->config->get('shipping_econt_delivery_private_key'));

            @$frameParams = array(
                'id_shop' => intval(@reset($keys)),
                'order_weight' => $this->cart->getWeight(),
                'order_total' => $this->getOrderTotal(),
                'order_currency' => $this->session->data['currency'],
                'customer_company' => $this->session->data['shipping_address']['company'],
                'customer_name' => "{$this->session->data['shipping_address']['firstname']} {$this->session->data['shipping_address']['lastname']}",
                'customer_phone' =>  $phone,
                'customer_email' => $email,
                'customer_country' => $this->session->data['shipping_address']['iso_code_3'],
                'customer_city_name' => $this->session->data['shipping_address']['city'],
                'customer_post_code' => $this->session->data['shipping_address']['postcode'],
                'customer_address' => $this->session->data['shipping_address']['address_1'].' '.$this->session->data['shipping_address']['address_2'],
            );
            $officeCode = trim($this->session->data['econt_delivery']['customer_info']['office_code'] ?? '');
            if (!empty($officeCode)) {
                $frameParams['customer_office_code'] = $officeCode;
            }
            $zip = trim($this->session->data['econt_delivery']['customer_info']['zip'] ?? '');
            if (!empty($zip)) {
                $frameParams['customer_zip'] = $zip;
            }
            if($this->econtDeliveryOneStepCheckoutEnabled) {
                $frameParams['module'] = 'onecheckout';
            }

            $deliveryBaseURL = $settings['shipping_econt_delivery_system_url'];
            $frameURL = $deliveryBaseURL.'/customer_info.php?'. http_build_query($frameParams, '', '&');
            $deliveryMethodTxt = $this->language->get('text_delivery_method_description');
            $deliveryMethodPriceCD = $this->language->get('text_delivery_method_description_cd');

            /**
             * Journal3 specific change. If no JOURNAL3_VERSION is defined, we assume that the theme is not installed.
             * If the theme is installed and the opencart default checkout is active, we output the JS/CSS for Econt Delivery.
             * For Journal3 one page checkout we use event
             */
            // if(!$this->isJournalOnePageCheckout()){
            //     $this->outputEcontDeliveryCheckoutScript();
            // }
        }

        return array(
            'code' => 'econt_delivery',
            'title' => $this->language->get('text_delivery_method_title'),
            'quote' => array(
                'econt_delivery' => array(
                    'code' => 'econt_delivery.econt_delivery',
                    'title' => $this->language->get('text_delivery_method_description'),
                    'cost' => $this->calculateShippingPrice(),
                    'tax_class_id' => 0,
                    'text' => ''
                )
            ),
            'sort_order' => intval($this->config->get('shipping_econt_delivery_sort_order')),
            'error' => false
        );
    }

    /**
     * Outputs the Econt Delivery checkout JavaScript and CSS required for the shipping method.
     * The method dynamically modifies and includes necessary client-side scripts for processing
     * Econt Delivery within the checkout, including handling various configurations and user interactions.
     *
     * @param bool $returnHtml Optional. If true, the method will return the generated HTML script content as a string,
     *                         rather than directly outputting it. Default is false.
     * @return void|string Returns void when $returnHtml is false (standard behavior).
     *                     Returns a string containing the generated HTML script content if $returnHtml is true.
     */
    public function outputEcontDeliveryCheckoutScript() {

        // This method outputs the checkout JS/CSS for Econt Delivery without requiring parameters
        $this->load->model('setting/setting');
        $settings = $this->model_setting_setting->getSetting('shipping_econt_delivery');

        // Setup modes based on settings and request, same as in getQuote
        $this->econtDeliveryOneStepCheckoutEnabled = isset($settings['shipping_econt_delivery_checkout_mode']) && $settings['shipping_econt_delivery_checkout_mode'] == 'onestep';

        if ($this->econtDeliveryOneStepCheckoutEnabled && isset($this->session->data['shipping_address']['firstname']) && $this->session->data['shipping_address']['firstname'] == '8a9ggua0sjm$Fn') {
            $this->session->data['shipping_address']['company']     = '';
            $this->session->data['shipping_address']['firstname']   = '';
            $this->session->data['shipping_address']['lastname']    = '';
            $this->session->data['shipping_address']['iso_code_3']  = '';
            $this->session->data['shipping_address']['city']        = '';
            $this->session->data['shipping_address']['postcode']    = '';
            $this->session->data['shipping_address']['address_1']   = '';
            $this->session->data['shipping_address']['address_2']   = '';
        }
        $this->oneStepCheckoutModuleEnabled = isset($this->request->request['route']) && $this->request->request['route'] == 'extension/quickcheckout/shipping_method';

        $this->load->language('extension/shipping/econt_delivery');

        // if ( isset($this->request->request['route']) 
        //     && ($this->request->request['route'] == 'checkout/checkout' || $this->request->request['route'] == 'checkout/shipping_method' || $this->oneStepCheckoutModuleEnabled || $this->econtDeliveryOneStepCheckoutEnabled)
        // ) {
            
            $customer = $this->registry->get('customer');
            if ($customer && $customer->getEmail()) {
                $email = $customer->getEmail();
                $phone = $customer->getTelephone();
            } else {
                $email = $this->session->data['shipping_address']['email'] ?? ($this->session->data['guest']['email'] ?? '');
                $phone = $this->session->data['shipping_address']['telephone'] ?? ($this->session->data['guest']['telephone'] ?? '');
            }

            $keys = explode('@', $this->config->get('shipping_econt_delivery_private_key'));

            @$frameParams = array(
                'id_shop' => intval(@reset($keys)),
                'order_weight' => $this->cart->getWeight(),
                'order_total' => $this->getOrderTotal(),
                'order_currency' => $this->session->data['currency'],
                'customer_company' => $this->session->data['shipping_address']['company'],
                'customer_name' => "{$this->session->data['shipping_address']['firstname']} {$this->session->data['shipping_address']['lastname']}",
                'customer_phone' => $phone,
                'customer_email' => $email,
                'customer_country' => $this->session->data['shipping_address']['iso_code_3'],
                'customer_city_name' => $this->session->data['shipping_address']['city'],
                'customer_post_code' => $this->session->data['shipping_address']['postcode'],
                'customer_address' => $this->session->data['shipping_address']['address_1'] . ' ' . $this->session->data['shipping_address']['address_2'],
            );

            $officeCode = trim($this->session->data['econt_delivery']['customer_info']['office_code'] ?? '');

            if (!empty($officeCode)) {
                $frameParams['customer_office_code'] = $officeCode;
            }

            $zip = trim($this->session->data['econt_delivery']['customer_info']['zip'] ?? '');

            if (!empty($zip)) {
                $frameParams['customer_zip'] = $zip;
            }

            if ($this->econtDeliveryOneStepCheckoutEnabled) {
                $frameParams['module'] = 'onecheckout';
            }

            $deliveryBaseURL = $settings['shipping_econt_delivery_system_url'];
            $frameURL = $deliveryBaseURL . '/customer_info.php?' . http_build_query($frameParams, '', '&');
            $deliveryMethodTxt = $this->language->get('text_delivery_method_description');
            $deliveryMethodPriceCD = $this->language->get('text_delivery_method_description_cd');

            $data['is_logged'] = $this->customer->isLogged();
            $data['is_journal_theme'] = $this->config->get('config_theme') === 'journal3';
            $data['isJournalOnePageCheckout'] = $this->isJournalOnePageCheckout();
            $data['deliveryBaseURL'] = $deliveryBaseURL;
            $data['frameURL'] = $frameURL;
            $data['deliveryMethodTxt'] = $deliveryMethodTxt;
            $data['deliveryMethodPriceCD'] = $deliveryMethodPriceCD;
            $data['econtDeliveryOneStepCheckoutEnabled'] = $this->econtDeliveryOneStepCheckoutEnabled;
            $data['oneStepCheckoutModuleEnabled'] = $this->oneStepCheckoutModuleEnabled;

            $html = $this->load->view('extension/shipping/econt_delivery_checkout_script', $data);

            return $html;
        // }
    }

    public function getOrderTotal() {
        $products = $this->cart->getProducts();
        foreach ($products as $product) {
            $product_total = 0;
            foreach ($products as $product_2) if ($product_2['product_id'] == $product['product_id']) $product_total += $product_2['quantity'];
            $option_data = array();
            foreach ($product['option'] as $option) {
                $option_data[] = array(
                    'product_option_id'       => $option['product_option_id'],
                    'product_option_value_id' => $option['product_option_value_id'],
                    'name'                    => $option['name'],
                    'value'                   => $option['value'],
                    'type'                    => $option['type']
                );
            }
        }

        $this->load->model('setting/extension');
        $totals = array();
        $taxes = $this->cart->getTaxes();

        $total = 0;

        $total_data = array(
            'totals' => &$totals,
            'taxes'  => &$taxes,
            'total'  => &$total
        );

        $sort_order = array();
        $results = $this->model_setting_extension->getExtensions('total');

        foreach ($results as $key => $value) {
            $sort_order[$key] = $this->config->get('total_' . $value['code'] . '_sort_order');
        }

        array_multisort($sort_order, SORT_ASC, $results);

        foreach ($results as $result) {
            if ($this->config->get('total_' . $result['code'] . '_status')) {
                $this->load->model('extension/total/' . $result['code']);
                $this->{'model_extension_total_' . $result['code']}->getTotal($total_data);
            }
        }

        $sort_order = array();

        foreach ($totals as $key => $value) {
            $sort_order[$key] = $value['sort_order'];
        }

        array_multisort($sort_order, SORT_ASC, $totals);

        return array_reduce($totals, function($total, $currentRow) {

            if (!in_array($currentRow['code'], array('shipping', 'total'))) {
                $total += $currentRow['value'];
            }
            return $total;
        }, 0);
    }

    public function prepareOrder($orderId = 0) {

        $orderId = @intval($this->request->get['order_id'] ?? $orderId);

        if ($orderId <= 0) {
        
            if ($this->request->get['route'] === 'api/order/add') {
        
                //$orderId = intval(reset($data));

        
                if ($orderId <= 0) {
                    return null;
                }

                if (!empty($this->session->data['econt_delivery']['customer_info'])) {
                    $this->db->query("
                            INSERT INTO ".DB_PREFIX."econt_delivery_customer_info
                            SET id_order = {$orderId},
                                customer_info = '".json_encode($this->session->data['econt_delivery']['customer_info'])."'
                            ON DUPLICATE KEY UPDATE customer_info = VALUES(customer_info)
                        ");
                }
            } else {
                if (!($orderId = intval($this->session->data['order_id']))) {
                    return null;
                }
            }
        }

        if(is_null($this->model_checkout_order)) {
            $this->load->model('checkout/order');
        }
        
        $orderData = $this->model_checkout_order->getOrder($orderId);
        
        if (empty($orderData) || $orderData['shipping_code'] !== 'econt_delivery.econt_delivery'){
             return null;
        }

        $this->load->model('extension/shipping/econt_delivery');

        $customerInfo = isset($this->session->data['econt_delivery']) 
            ? $this->session->data['econt_delivery']['customer_info'] 
            : [];
        $paymentToken = '';
        
        if (empty($customerInfo)) {
            $customerInfo = $this->db->query("
                SELECT
                    ci.customer_info AS customerInfo,
                    ci.payment_token AS paymentToken
                FROM ".DB_PREFIX."econt_delivery_customer_info AS ci
                WHERE ci.id_order = {$orderId}
                LIMIT 1
            ");

            $paymentToken = @trim($customerInfo->row['paymentToken']);
            $customerInfo = json_decode($customerInfo->row['customerInfo'] ?? '{}', true);
        }
        if (!$customerInfo || empty($customerInfo['id'])) return null;

        $this->load->language('extension/shipping/econt_delivery');
        $order = array(
            'customerInfo' => array(
                'id' => $customerInfo['id']
            ),
            'orderNumber' => $orderData['order_id'],
            'shipmentDescription' => sprintf("%s #{$orderData['order_id']}", $this->language->get('text_econt_delivery_order')),
            'status' => $orderData['order_status'],
            'orderTime' => $orderData['date_added'],
            'currency' => $orderData['currency_code'],
            'cod' => ($orderData['payment_code'] === 'cod' || $orderData['payment_code'] === 'econt_payment'),
            'partialDelivery' => 1,
            'paymentToken' => $paymentToken,
            'items' => array()
        );

        $productTotal = 0;

        $dbp = DB_PREFIX;
        $orderProducts = $this->db->query("
            SELECT
                op.*,
                p.sku as sku,
                p.weight + SUM(COALESCE(IF(ov.weight_prefix = '-',-ov.weight,ov.weight),0)) as weight
            FROM {$dbp}order_product op
            JOIN {$dbp}product p ON p.product_id = op.product_id
            LEFT JOIN {$dbp}order_option oo ON oo.order_id = op.order_id AND oo.order_product_id = op.order_product_id
            LEFT JOIN {$dbp}product_option_value ov ON ov.product_option_value_id = oo.product_option_value_id
            WHERE op.order_id = ".intval($orderId)."
            GROUP BY p.product_id
        ");
        $orderProducts = $orderProducts->rows;
        if (!empty($orderProducts)) {
            if (count($orderProducts) <= 1) {
                $orderProduct = reset($orderProducts);
                $order['shipmentDescription'] = $orderProduct['name'];
            }
            $this->load->model('catalog/product');
            foreach ($orderProducts as $orderProduct) {
                $orderItemPrice = floatval($orderProduct['total']) + (floatval($orderProduct['tax']) * intval($orderProduct['quantity']));
                $order['items'][] = array(
                    'name' => $orderProduct['name'],
                    'SKU' => $orderProduct['sku'],
                    'URL' => $this->url->link('product/product', http_build_query(array(
                        'product_id' => $orderProduct['product_id']
                    )), true),
                    'count' => $orderProduct['quantity'],
                    'totalPrice' => $orderItemPrice,
                    'totalWeight' => floatval($orderProduct['weight'] * $orderProduct['quantity'])
                );
                $productTotal += $orderItemPrice;
            }
        }

        $orderTotals = $this->model_checkout_order->getOrderTotals($orderData['order_id']);
        if (!empty($orderTotals)) {
            $orderTotal = array_reduce($orderTotals, function($total, $currentRow) {
                if (!in_array($currentRow['code'], array('shipping', 'total'))) $total += $currentRow['value'];
                return $total;
            }, 0);
            $discount = round($orderTotal - $productTotal,2);
            if ($discount != 0) {
                $order['partialDelivery'] = 0;
                $order['items'][] = array(
                    'name' => $this->language->get('text_econt_delivery_order_discount'),
                    'count' => 1,
                    'totalPrice' => $discount
                );
            }
        }

        $aOrderObject = [];
        $aOrderObject['order'] = $order;
        $aOrderObject['orderData'] = $orderData;
        $aOrderObject['customerInfo'] = $customerInfo;

        return $aOrderObject;
    }
    public function calculateShippingPrice() {
        if (!array_key_exists('econt_delivery', $this->session->data)) {
            return;
        }

        if (!array_key_exists('payment_method', $this->session->data)) {
            return;
        }

        $aData = $this->session->data['econt_delivery']['customer_info'];

        if(is_null($aData)) {
            return 0;
        }
        
        $payment_method_price_map = [
            'cod' => array_key_exists('shipping_price_cod', $aData) ? $aData['shipping_price_cod'] : 0,
            'econt_payment' => array_key_exists('shipping_price_cod_e', $aData) ? $aData['shipping_price_cod_e'] : 0,
        ];

        $payment_method = $this->session->data['payment_method']['code'];

        if (in_array($payment_method, $payment_method_price_map)) {
	        return round(@floatval($payment_method_price_map[$payment_method]), 2);
        } else {
	        return round(@floatval($this->session->data['econt_delivery']['customer_info']['shipping_price']), 2);
        }
    }

    public function isJournalOnePageCheckout()
    {
        return defined('JOURNAL3_VERSION')
            || (
                (class_exists(Journal3::class) && Journal3::getInstance() && Journal3::getInstance()->settings->get('activeCheckout') === 'journal')
                ||
                ( !is_null($this->journal3 ?? null) && $this->journal3->get('activeCheckout') === 'journal')
            );
    }
}
