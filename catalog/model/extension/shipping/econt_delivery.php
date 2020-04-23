<?php

/** @noinspection PhpUndefinedClassInspection */

/**
 * @property Loader $load
 * @property DB $db
 * @property Config $config
 * @property Language $language
 * @property Session $session
 * @property ModelSettingSetting $model_setting_setting
 * @property \Cart\Cart $cart
 * @property ModelAccountOrder model_account_order
 */
class ModelExtensionShippingEcontDelivery extends Model {

    public function getQuote($address) {
        $geoZoneId = intval($this->config->get('shipping_econt_delivery_geo_zone_id'));
        if ($geoZoneId !== 0) {
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
                $address['country_id'],
                $address['zone_id']
            ));
            if (intval($result->row['zoneIdsCount']) <= 0) return array();
        }
        $this->load->language('extension/shipping/econt_delivery');
        if($this->request->request['route'] == 'checkout/shipping_method') {
            if($this->cart->customer && $this->cart->customer->getEmail()) {
                $email = $this->cart->customer->getEmail();
                $phone = $this->cart->customer->getTelephone();
            } else {
                $email = $this->session->data['guest']['email'];
                $phone = $this->session->data['guest']['telephone'];
            }

            @$frameParams = array(
                'id_shop' => intval(@reset(explode('@',$this->config->get('shipping_econt_delivery_private_key')))),
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
                'confirm_txt' => 'Смятай'
            );
            $officeCode = trim(@$this->session->data['econt_delivery']['customer_info']['office_code']);
            if (!empty($officeCode)) $frameParams['customer_office_code'] = $officeCode;
            $zip = trim(@$this->session->data['econt_delivery']['customer_info']['zip']);
            if (!empty($zip)) $frameParams['customer_zip'] = $zip;

            $this->load->model('setting/setting');
            $settings = $this->model_setting_setting->getSetting('shipping_econt_delivery');
            $deliveryBaseURL = $settings['shipping_econt_delivery_system_url'];
            $frameURL = $deliveryBaseURL.'/customer_info.php?'.http_build_query($frameParams,null,'&');
            $deliveryMethodTxt = $this->language->get('text_delivery_method_description');
            $deliveryMethodPriceCD = $this->language->get('text_delivery_method_description_cd');

            ?>
            <script>
                (function($){
                    var $econtRadio = $('input:radio[value=\'econt_delivery.econt_delivery\']');
                    var $hiddenTextArea = $('<textarea style="display:none" name="econt_delivery_shipping_info"></textarea>').appendTo($econtRadio.parent().parent());
                    $econtRadio.parent().contents().each(function(i,el){if(el.nodeType == 3) el.nodeValue = '';});//zabursvane na originalniq text
                    var $econtLabelText = $('<span></span>').text(<?php echo json_encode($deliveryMethodTxt)?>);
                    $econtRadio.after($econtLabelText);
                    var shippingInfo = null;
                    var $frame = null;
                    $econtRadio.click(function(){
                        if(!$frame) {
                            $frame = $('<iframe style="width:100%;height:612px;border:none;margin-top: 15px;" src="<?php echo $frameURL?>"></iframe>');
                            $econtRadio.parent().parent().append($frame);
                        }
                    });
                    $('input:radio[name=shipping_method]').change(function(){
                        if(!$econtRadio.is(':checked')) {
                            $frame.remove();
                            $frame = null;
                        }
                    });
                    if($econtRadio.is(':checked')) $econtRadio.trigger('click');
                    $(window).unbind('message.econtShipping');
                    $(window).bind('message.econtShipping',function(e){
                        if(e.originalEvent.origin.indexOf('<?php echo $deliveryBaseURL?>') == 0) {
                            if(e.originalEvent.data.shipment_error) {
                                alert(e.originalEvent.data.shipment_error)
                            } else {
                                shippingInfo = e.originalEvent.data;
                                $frame.remove();
                                $frame = null;
                                console.log(shippingInfo);
                                var labelTxt = <?php echo json_encode($deliveryMethodTxt)?> + ' - ' + shippingInfo.shipping_price + shippingInfo.shipping_price_currency_sign;
                                if(shippingInfo.shipping_price != shippingInfo.shipping_price_cod) {
                                    labelTxt += ' (+ ' + (shippingInfo.shipping_price_cod - shippingInfo.shipping_price).toFixed(2) + shippingInfo.shipping_price_currency_sign + ' ' + <?php echo json_encode($deliveryMethodPriceCD)?> + ')'
                                }
                                $econtLabelText.text(labelTxt);
                                $hiddenTextArea.val(JSON.stringify(e.originalEvent.data));
                            }
                        }
                    });
                })(jQuery);
            </script>
            <?php
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

    public function prepareOrder($paymentToken = '')
    {
        $orderId = @intval($this->request->get['order_id']);
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
            } else {
                if (!($orderId = intval($this->session->data['order_id']))) return;
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
            $discount = $orderTotal - $productTotal;
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

    public function calculateShippingPrice()
    {
        if (!array_key_exists('econt_delivery', $this->session->data)) {
            return;
        }

        if (!array_key_exists('payment_method', $this->session->data)) {
            return;
        }

        $payment_method_price_map = [
            'cod' => $this->session->data['econt_delivery']['customer_info']['shipping_price_cod'],
            'econt_payment' => $this->session->data['econt_delivery']['customer_info']['shipping_price_cod_e'],
        ];

        $payment_method = $this->session->data['payment_method']['code'];

        if (in_array($payment_method, $payment_method_price_map)) {
            return @floatval($payment_method_price_map[$payment_method]);
        } else {
            return @floatval($this->session->data['econt_delivery']['customer_info']['shipping_price']);
        }
    }

}