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

namespace Opencart\Catalog\Model\Extension\EcontDeliveryOc4\Shipping;
class EcontDelivery extends \Opencart\System\Engine\Model {

    private $oneStepCheckoutModuleEnabled = false;
    private $econtDeliveryOneStepCheckoutEnabled = false;

    public function getQuote($address) {
        $geoZoneId = intval($this->config->get('shipping_econt_delivery_geo_zone_id'));
        if ($geoZoneId !== 0 && !empty($address['country_id']) && !empty($address['zone_id'])) {
            $result = $this->db->query(sprintf("
                SELECT
                    COUNT(z.zone_to_geo_zone_id) AS zoneIdsCount
                FROM `%s`.`%szone_to_geo_zone` AS z
                WHERE TRUE
                    AND z.geo_zone_id = {$geoZoneId}
                    AND z.country_id = %d
                    AND z.zone_id IN (0, %d)
                LIMIT 1
            ",
                DB_DATABASE,
                DB_PREFIX,
                (int)$address['country_id'],
                (int)$address['zone_id']
            ));
            if (intval($result->row['zoneIdsCount']) <= 0) return array();
        }
	
        $this->load->language('extension/econt_delivery_oc4/shipping/econt_delivery');
	    $shippingPrice = $this->calculateShippingPrice();
	    return array(
            'code' => 'econt_delivery',
            'title' => $this->language->get('text_delivery_method_title'),
            'quote' => array(
                'econt_delivery' => array(
                    'code' => 'econt_delivery.econt_delivery',
                    'title' => $this->language->get('text_delivery_method_description'),
                    'cost' => $shippingPrice,
                    'tax_class_id' => 0,
                    'text' => $this->currency->format($shippingPrice, $this->session->data['currency'])
                )
            ),
            'sort_order' => intval($this->config->get('shipping_econt_delivery_sort_order')),
            'error' => false
        );
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
        foreach ($results as $key => $value) $sort_order[$key] = $this->config->get('total_' . $value['code'] . '_sort_order');
        array_multisort($sort_order, SORT_ASC, $results);
        foreach ($results as $result) {
            if ($this->config->get('total_' . $result['code'] . '_status')) {
                $this->load->model('extension/total/' . $result['code']);
                $this->{'model_extension_total_' . $result['code']}->getTotal($total_data);
            }
        }
        $sort_order = array();
        foreach ($totals as $key => $value) $sort_order[$key] = $value['sort_order'];
        array_multisort($sort_order, SORT_ASC, $totals);

        return array_reduce($totals, function($total, $currentRow) {
            if (!in_array($currentRow['code'], array('shipping', 'total'))) $total += $currentRow['value'];
            return $total;
        }, 0);
    }

    public function prepareOrder() {
	    $orderId = intval($this->session->data['order_id']);
	    if(empty($orderId)) $orderId = $this->session->data['econt_delivery']['last_order_id'];
        if ($orderId <= 0) return null;

        if(!empty($this->session->data['econt_delivery']['customer_info'])) $this->db->query(sprintf("
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

        $this->load->model('checkout/order');
        $orderData = $this->model_checkout_order->getOrder($orderId);
        if (empty($orderData) || $orderData['shipping_code'] !== 'econt_delivery.econt_delivery') return null;

        $this->load->model('extension/econt_delivery_oc4/shipping/econt_delivery');
        $customerInfo = isset($this->session->data['econt_delivery']) ? $this->session->data['econt_delivery']['customer_info'] : [];
        $paymentToken = '';
        if (empty($customerInfo)) {
            $customerInfo = $this->db->query(sprintf("
                SELECT
                    ci.customer_info AS customerInfo,
                    ci.payment_token AS paymentToken
                FROM `%s`.`%secont_delivery_customer_info` AS ci
                WHERE TRUE
                    AND ci.id_order = {$orderId}
                LIMIT 1
            ",
                DB_DATABASE,
                DB_PREFIX
            ));
            $paymentToken = trim($customerInfo->row['paymentToken']);
            $customerInfo = json_decode($customerInfo->row['customerInfo'], true);
        }
        if (!$customerInfo || empty($customerInfo['id'])) return null;

        $this->load->language('extension/econt_delivery_oc4/shipping/econt_delivery');
        $order = array(
            'customerInfo' => array(
                'id' => $customerInfo['id']
            ),
            'orderNumber' => $orderData['order_id'],
            'shipmentDescription' => sprintf("%s #{$orderData['order_id']}", $this->language->get('text_econt_delivery_order')),
            'status' => empty($orderData['order_status']) || $orderData['order_status'] == 0 ? 'oc4_unconfirmed' : $orderData['order_status'],
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

        $orderTotals = $this->model_checkout_order->getTotals($orderData['order_id']);
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
		if(!empty($this->session->data['econt_delivery']['customer_info'])){
			$data = $this->session->data['econt_delivery']['customer_info'];
			$defaultPrice = round(@floatval($this->session->data['econt_delivery']['customer_info']['shipping_price']), 2);
			
			if(!empty($this->session->data['payment_method'])){
				$paymentMethodPriceMap = [
					'cod' => array_key_exists('shipping_price_cod', $data) ? $data['shipping_price_cod'] : 0.00,
					'econt_payment' => array_key_exists('shipping_price_cod_e', $data) ? $data['shipping_price_cod_e'] : 0.00,
				];
				
				if(in_array($this->session->data['payment_method'], $paymentMethodPriceMap))
					return round(@floatval($paymentMethodPriceMap[$this->session->data['payment_method']]), 2);
				else return $defaultPrice;
			} else return $defaultPrice;
			
		} else return 0.00;
    }

}