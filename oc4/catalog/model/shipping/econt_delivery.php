<?php

namespace Opencart\Catalog\Model\Extension\EcontDeliveryOc4\Shipping;

/**
 * Econt Delivery Model - OpenCart 4
 * 
 * Handles shipping quotes and order preparation for Econt delivery
 */
class EcontDelivery extends \Opencart\System\Engine\Model {

    /**
     * Get shipping quote for Econt delivery
     * 
     * @param array $address Shipping address
     * @return array Shipping quote data
     */
    public function getQuote(array $address): array {
        $this->load->language('extension/econt_delivery_oc4/shipping/econt_delivery');

        // Check geo zone restriction
        $geoZoneId = (int)$this->config->get('shipping_econt_delivery_geo_zone_id');

        if ($geoZoneId !== 0 && !empty($address['country_id']) && !empty($address['zone_id'])) {
            $result = $this->db->query("
                SELECT COUNT(z.zone_to_geo_zone_id) AS zoneIdsCount
                FROM " . DB_PREFIX . "zone_to_geo_zone AS z
                WHERE TRUE
                    AND z.geo_zone_id = " . (int)$geoZoneId . "
                    AND z.country_id = " . (int)$address['country_id'] . "
                    AND z.zone_id IN (0, " . (int)$address['zone_id'] . ")
                LIMIT 1
            ");

            if ((int)$result->row['zoneIdsCount'] <= 0) {
                return [];
            }
        }

        // Calculate shipping price (will be 0 initially, updated after address selection)
        $shippingPrice = $this->calculateShippingPrice();

        return [
            'code' => 'econt_delivery',
            'name' => $this->language->get('text_delivery_method_title'),
            'quote' => [
                'econt_delivery' => [
                    'code' => 'econt_delivery.econt_delivery',
                    'name' => $this->language->get('text_delivery_method_description'),
                    'cost' => $shippingPrice,
                    'tax_class_id' => 0,
                    'text' => $this->currency->format($shippingPrice, $this->session->data['currency'])
                ]
            ],
            'sort_order' => (int)$this->config->get('shipping_econt_delivery_sort_order'),
            'error' => false
        ];
    }

    /**
     * Calculate shipping price based on selected address and payment method
     * 
     * @return float Shipping price
     */
    public function calculateShippingPrice(): float {
        if (!isset($this->session->data['econt_delivery']['customer_info'])) {
            return 0.00;
        }

        $customerInfo = $this->session->data['econt_delivery']['customer_info'];

        if (empty($customerInfo)) {
            return 0.00;
        }

        // Default shipping price
        $shippingPrice = (float)($customerInfo['shipping_price'] ?? 0);

        // Adjust price based on payment method
        if (isset($this->session->data['payment_method']['code'])) {
            $paymentCode = $this->session->data['payment_method']['code'];

            switch ($paymentCode) {
                case 'cod':
                    $shippingPrice = (float)($customerInfo['shipping_price_cod'] ?? $shippingPrice);
                    break;
                case 'econt_payment':
                    $shippingPrice = (float)($customerInfo['shipping_price_cod_e'] ?? $shippingPrice);
                    break;
            }
        }

        return round($shippingPrice, 2);
    }

    /**
     * Get order total for Econt API (excluding shipping)
     * 
     * @return float Order total
     */
    public function getOrderTotal(): float {
        $this->load->model('setting/extension');

        $totals = [];
        $taxes = $this->cart->getTaxes();
        $total = 0;

        $totalData = [
            'totals' => &$totals,
            'taxes' => &$taxes,
            'total' => &$total
        ];

        $sortOrder = [];
        $results = $this->model_setting_extension->getExtensions('total');

        foreach ($results as $key => $value) {
            $sortOrder[$key] = $this->config->get('total_' . $value['extension'] . '_sort_order');
        }

        array_multisort($sortOrder, SORT_ASC, $results);

        foreach ($results as $result) {
            if ($this->config->get('total_' . $result['extension'] . '_status')) {
                $this->load->model('extension/total/' . $result['extension']);
                $this->{'model_extension_total_' . $result['extension']}->getTotal($totalData);
            }
        }

        $sortOrder = [];
        foreach ($totals as $key => $value) {
            $sortOrder[$key] = $value['sort_order'];
        }

        array_multisort($sortOrder, SORT_ASC, $totals);

        // Sum all totals except shipping and final total
        return array_reduce($totals, function($sum, $currentRow) {
            
            if (!in_array($currentRow['code'], ['shipping', 'total'])) {
                $sum += $currentRow['value'];
            }
            return $sum;
        }, 0);
    }

    /**
     * Prepare order data for Econt API
     * 
     * @param int $orderId Order ID
     * @return array|null Prepared order data or null on failure
     */
    public function prepareOrder(int $orderId = 0): ?array {
        if ($orderId <= 0 && isset($this->session->data['order_id'])) {
            $orderId = (int)$this->session->data['order_id'];
        }

        if ($orderId <= 0) {
            return null;
        }

        $this->load->model('checkout/order');
        $this->load->language('extension/econt_delivery_oc4/shipping/econt_delivery');

        $orderData = $this->model_checkout_order->getOrder($orderId);

        if (empty($orderData) || $orderData['shipping_method']['code'] !== 'econt_delivery.econt_delivery') {
            return null;
        }

        if($orderData['order_status_id'] == 0) {
            return null;
        }

        $this->load->model('localisation/order_status');
        $orderStatus = $this->model_localisation_order_status->getOrderStatus($orderData['order_status_id']);

        // Get customer info from database
        $customerInfoResult = $this->db->query("
            SELECT ci.customer_info, ci.payment_token
            FROM " . DB_PREFIX . "econt_delivery_customer_info AS ci
            WHERE ci.id_order = " . (int)$orderId . "
            LIMIT 1
        ");

        if (!$customerInfoResult->num_rows) {
            return null;
        }

        $customerInfo = json_decode($customerInfoResult->row['customer_info'] ?? '{}', true);
        $paymentToken = trim($customerInfoResult->row['payment_token'] ?? '');

        if (empty($customerInfo['id'])) {
            return null;
        }

        // Prepare order for Econt API
        $order = [
            'customerInfo' => [
                'id' => $customerInfo['id']
            ],
            'orderNumber' => $orderId,
            'shipmentDescription' => sprintf("%s #%d", $this->language->get('text_econt_delivery_order'), $orderId),
            'status' => $orderStatus['name'] ?? 'Processing status',
            'orderTime' => $orderData['date_added'],
            'currency' => $orderData['currency_code'],
            'cod' => in_array($orderData['payment_method']['code'], ['cod.cod', 'econt_payment.econt_payment']) ? 1 : 0,
            'partialDelivery' => 1,
            'paymentToken' => $paymentToken,
            'items' => []
        ];

        // Get order products
        $productTotal = 0;
        $dbp = DB_PREFIX;
        
        $orderProducts = $this->db->query("
            SELECT
                op.*,
                p.weight + COALESCE(SUM(IF(ov.weight_prefix = '-', -ov.weight, ov.weight)), 0) as weight
            FROM {$dbp}order_product op
            JOIN {$dbp}product p ON p.product_id = op.product_id
            LEFT JOIN {$dbp}order_option oo ON oo.order_id = op.order_id AND oo.order_product_id = op.order_product_id
            LEFT JOIN {$dbp}product_option_value ov ON ov.product_option_value_id = oo.product_option_value_id
            WHERE op.order_id = " . (int)$orderId . "
            GROUP BY op.order_product_id
        ");

        if ($orderProducts->num_rows) {
            // If single product, use its name as shipment description
            if ($orderProducts->num_rows === 1) {
                $order['shipmentDescription'] = $orderProducts->row['name'];
            }

            $skus = $this->getProductSkus(array_column($orderProducts->rows, 'product_id'));

            foreach ($orderProducts->rows as $product) {
                $itemPrice = (float)$product['total'] + ((float)$product['tax'] * (int)$product['quantity']);

                $order['items'][] = [
                    'name' => $product['name'],
                    'SKU' => $skus[$product['product_id']] ?? '',
                    'URL' => $this->url->link('product/product', 'product_id=' . $product['product_id'], true),
                    'count' => (int)$product['quantity'],
                    'totalPrice' => $itemPrice,
                    'totalWeight' => (float)$product['weight'] * (int)$product['quantity']
                ];
                
                $productTotal += $itemPrice;
            }
        }

        // Handle discounts
        $orderTotals = $this->model_checkout_order->getTotals($orderId);
        
        if (!empty($orderTotals)) {
            $orderTotal = array_reduce($orderTotals, function($sum, $currentRow) {
                if (!in_array($currentRow['code'], ['shipping', 'total'])) {
                    $sum += $currentRow['value'];
                }
                return $sum;
            }, 0);

            $discount = round($orderTotal - $productTotal, 2);
            
            if ($discount != 0) {
                $order['partialDelivery'] = 0;
                $order['items'][] = [
                    'name' => $this->language->get('text_econt_delivery_order_discount'),
                    'count' => 1,
                    'totalPrice' => $discount
                ];
            }
        }

        return [
            'order' => $order,
            'orderData' => $orderData,
            'customerInfo' => $customerInfo
        ];
    }

    /**
     * SKU на продуктите: product_id => SKU.
     * До OpenCart 4.1 е в колоната product.sku, от 4.1 е в product_code с code = 'SKU'.
     *
     * @param array $productIds
     * @return array
     */
    private function getProductSkus(array $productIds): array {
        $productIds = array_unique(array_map('intval', $productIds));

        if (!$productIds) {
            return [];
        }

        $dbp = DB_PREFIX;
        $ids = implode(',', $productIds);

        if ($this->db->query("SHOW COLUMNS FROM `{$dbp}product` LIKE 'sku'")->num_rows) {
            $query = $this->db->query("SELECT product_id, sku FROM `{$dbp}product` WHERE product_id IN ({$ids})");
        } else {
            $query = $this->db->query("SELECT product_id, `value` AS sku FROM `{$dbp}product_code` WHERE `code` = 'SKU' AND product_id IN ({$ids})");
        }

        return array_column($query->rows, 'sku', 'product_id');
    }
}
