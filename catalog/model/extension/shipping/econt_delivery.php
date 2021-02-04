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

        $this->oneStepCheckoutModuleEnabled = $this->request->request['route'] == 'extension/quickcheckout/shipping_method';
        $this->load->language('extension/shipping/econt_delivery');
        if($this->request->request['route'] == 'checkout/shipping_method' || $this->oneStepCheckoutModuleEnabled) {
            if($this->cart->customer && $this->cart->customer->getEmail()) {
                $email = $this->cart->customer->getEmail();
                $phone = $this->cart->customer->getTelephone();
            } else {
                $email = $this->session->data['guest']['email'];
                $phone = $this->session->data['guest']['telephone'];
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
            $officeCode = trim(@$this->session->data['econt_delivery']['customer_info']['office_code']);
            if (!empty($officeCode)) $frameParams['customer_office_code'] = $officeCode;
            $zip = trim(@$this->session->data['econt_delivery']['customer_info']['zip']);
            if (!empty($zip)) $frameParams['customer_zip'] = $zip;

            $this->load->model('setting/setting');
            $settings = $this->model_setting_setting->getSetting('shipping_econt_delivery');
            $deliveryBaseURL = $settings['shipping_econt_delivery_system_url'];
            $frameURL = $deliveryBaseURL.'/customer_info.php?'. http_build_query($frameParams, null, '&');
            $deliveryMethodTxt = $this->language->get('text_delivery_method_description');
            $deliveryMethodPriceCD = $this->language->get('text_delivery_method_description_cd');

            ?>
            <script>
                (function($){
                    var $econtRadio = $('input:radio[value=\'econt_delivery.econt_delivery\']');

                    <?php if ($this->oneStepCheckoutModuleEnabled) { ?>
                        var useShipping = false;
                        var useShippingCheckBox = $('#shipping')[0];
                        var customerInfoUrl = "<?php echo $frameURL?>";
                        var splited = customerInfoUrl.split('&').map(param => param.split('='));
                        var fieldsData = [];
                        var calculateButtonText = '<?php echo $this->language->get('text_delivery_method_calculate_button');?>';
                        var changeInfoButtonText = '<?php echo $this->language->get('text_delivery_method_change_button');?>';
                        var hasPrice = false;
                        var userIsLogged = false;

                        <?php if($this->customer->isLogged()) echo 'userIsLogged = true;'; ?>

                        if (userIsLogged === false && useShippingCheckBox.checked === false) {
                            useShipping = true;
                        }

                        var fields = [
                            '#input-' + ( useShipping ? 'shipping' : 'payment' ) + '-firstname',
                            '#input-' + ( useShipping ? 'shipping' : 'payment' ) + '-lastname',
                            '#input-' + ( useShipping ? 'shipping' : 'payment' ) + '-country',
                            '#input-' + ( useShipping ? 'shipping' : 'payment' ) + '-address-1',
                            '#input-' + ( useShipping ? 'shipping' : 'payment' ) + '-city',
                            '#input-' + ( useShipping ? 'shipping' : 'payment' ) + '-zone',
                            '#input-' + ( useShipping ? 'shipping' : 'payment' ) + '-postcode',
                            '#input-' + ( useShipping ? 'shipping' : 'payment' ) + '-company',
                            '#input-payment-telephone',
                            '#input-payment-email'
                        ]

                        var getFields = function () {
                            fields.forEach( function( field ) {
                                fieldsData[field] = $( field ).val()
                                if (field.indexOf('-zone') > -1) {
                                    var select = $(field)[0].options;
                                    var selected = $( field ).val();
                                    var selectedText = '';
                                    for (var i = 0; i < select.length; i++) {
                                        if (select[i].value === selected) {
                                            selectedText = select[i].text
                                        }
                                    }

                                    fieldsData[field] = selectedText;
                                }
                            });
                            return fieldsData;
                        }

                        var getUrl = function(data) {
                            var name;
                            var company;
                            var dataArray = [];

                            for (var x = 0; x < 4; x++){
                                dataArray.push(splited[x]);
                            }

                            // if (data['#input-' + ( useShipping ? 'shipping' : 'payment' ) + '-company'].length) {
                                company = data['#input-' + ( useShipping ? 'shipping' : 'payment' ) + '-company'];
                                name = data['#input-' + ( useShipping ? 'shipping' : 'payment' ) + '-firstname'] + ' ' + data['#input-' + ( useShipping ? 'shipping' : 'payment' ) + '-lastname'];
                            // } else {
                            //     name = data['#input-' + ( useShipping ? 'shipping' : 'payment' ) + '-firstname'] + ' ' + data['#input-' + ( useShipping ? 'shipping' : 'payment' ) + '-lastname'];
                            //     company = ''
                            // }

                            var additional = [
                                ["customer_company", company],
                                ["customer_name", name],
                                ["customer_phone", data['#input-payment-telephone']],
                                ["customer_email", data['#input-payment-email']],
                                ["customer_city_name", data['#input-' + ( useShipping ? 'shipping' : 'payment' ) + '-city']],
                                ["customer_post_code", data['#input-' + ( useShipping ? 'shipping' : 'payment' ) + '-postcode']],
                                ["customer_address", data['#input-' + ( useShipping ? 'shipping' : 'payment' ) + '-address-1']],
                                ["customer_address", data['#input-' + ( useShipping ? 'shipping' : 'payment' ) + '-address-1']],
                                ['ignore_history', true]
                            ]

                            additional.forEach( entry => {
                                dataArray.push(entry)
                            });

                            return dataArray.map(param => param.join('=')).join('&');
                        }

                        var setButtonText = function () {
                            hasPrice = true;
                            $('#openEcontModal').text(changeInfoButtonText);
                        }

                        $econtRadio.closest('tr').after('' +
                            '<tr id="econt_delivery_row">' +
                                '<td colspan="3">' +
                                    '<div id="econt_iframe_wrapper"><div id="econt_iframe_inner"><span id="closeEcontModal" class="close-econt-modal">x</span></div></div>' +
                                    '<div>' +
                                        '<button type="button" id="openEcontModal" ' +
                                            'class="btn btn-primary" style="float: right;">' + ( hasPrice ? changeInfoButtonText : calculateButtonText ) + '</button>' +
                                    '</div>' +
                                '</td>' +
                            '</tr>' +
                        '');

                        $('#closeEcontModal').on('click', function () {
                            $("#econt_iframe_wrapper").css('display', 'none')
                            $('html body').removeClass('background-muted')
                            if ($frame) {
                                $frame.remove();
                                $frame = null;
                            }
                        })

                        $('#openEcontModal').on('click', function () {
                            var preparedFields = getFields();
                            var url = getUrl(preparedFields);

                            if(!$frame) {
                                $frame = $('<iframe id="econtDeliverFrame" src="' + url + '"></iframe>');
                                $("#econt_iframe_inner").append($frame);
                            }

                            $("#econt_iframe_wrapper").css('display', 'block');
                            $('html body').addClass('background-muted')
                        })

                    <?php } ?>

                    var $hiddenTextArea = $('<textarea style="display:none" name="econt_delivery_shipping_info"></textarea>')
                        .appendTo(<?php echo $this->oneStepCheckoutModuleEnabled ? '$("#econt_iframe_inner")' : '$econtRadio.parent().parent()'; ?>);
                    $econtRadio.parent().contents().each(function(i,el){if(el.nodeType == 3) el.nodeValue = '';});//zabursvane na originalniq text
                    var $econtLabelText = $('<span></span>').text(<?php echo json_encode($deliveryMethodTxt)?>);
                    var shippingInfo = null;
                    var $frame = null;
                    $econtRadio.after($econtLabelText);
                    $econtRadio.click(function(){
                        if(!$frame) {
                            $frame = $('<iframe id="econtDeliverFrame" src="<?php echo $frameURL?>"></iframe>');
                            <?php echo $this->oneStepCheckoutModuleEnabled ? '$("#econt_iframe_inner").append($frame)' : '$econtRadio.parent().parent().append($frame)'; ?>
                        }
                    });
                    $('input:radio[name=shipping_method]').change(function(){
                        if(!$econtRadio.is(':checked')) {
                        <?php if (!$this->oneStepCheckoutModuleEnabled) { ?>
                            if ($frame) $frame.remove();
                            $frame = null;
                        <?php } else { ?>
                            $('#econt_delivery_row').css('display', 'none')
                        <?php } ?>
                        } else {
                            <?php if ($this->oneStepCheckoutModuleEnabled) { ?>
                                $('#econt_delivery_row').css('display', 'table-row')
                            <?php } ?>
                        }
                    });

                    if($econtRadio.is(':checked')) $econtRadio.trigger('click');
                    $(window).unbind('message.econtShipping');
                    $(window).bind('message.econtShipping',function(e){
                        if(e.originalEvent.origin.indexOf('<?php echo $deliveryBaseURL?>') == 0) {
                            if(e.originalEvent.data.shipment_error) {
                                alert(e.originalEvent.data.shipment_error)
                            } else {
                                <?php if ($this->oneStepCheckoutModuleEnabled) { ?>
                                    setButtonText()
                                <?php } ?>
                                shippingInfo = e.originalEvent.data;
                                <?php echo $this->oneStepCheckoutModuleEnabled ? "$('#econt_iframe_wrapper').css('display', 'none');\n $('html body').removeClass('background-muted');" : ''; ?>
                                $frame.remove();
                                $frame = null;
                                var labelTxt = <?php echo $this->oneStepCheckoutModuleEnabled ? '' : json_encode($deliveryMethodTxt . ' - ') ?> + shippingInfo.shipping_price + ' ' + shippingInfo.shipping_price_currency_sign;
                                if(shippingInfo.shipping_price != shippingInfo.shipping_price_cod) {
                                    labelTxt += ' (+ ' + (shippingInfo.shipping_price_cod - shippingInfo.shipping_price).toFixed(2) + ' ' + shippingInfo.shipping_price_currency_sign + ' ' + <?php echo json_encode($deliveryMethodPriceCD)?> + ')'
                                }
                                $hiddenTextArea.val(JSON.stringify(e.originalEvent.data));
                                <?php
                                    if ($this->oneStepCheckoutModuleEnabled) { ?>
                                        var date = new Date();
                                        date.setTime(date.getTime() + (5 * 60 * 1000));
                                        document.cookie = "econt_delivery_temporary_shipping_price=" + JSON.stringify(e.originalEvent.data) + "; expires=" + date;
                                        loadCart();

                                        var label = $('label[for="econt_delivery.econt_delivery"]').last();
                                        label.empty().append(labelTxt);

                                        /**
                                         * Set billing form fields
                                         */
                                        var full_name = []
                                        var company = ''
                                        var data = e.originalEvent.data

                                        if ( data['face'] != null ) {
                                            full_name = data['face'].split( ' ' );
                                            company = data['name'];
                                        } else {
                                            full_name = data['name'].split( ' ' );
                                        }
                                        if ( document.getElementById( 'input-' + ( useShipping ? 'shipping' : 'payment' ) + '-firstname' ) )
                                            document.getElementById( 'input-' + ( useShipping ? 'shipping' : 'payment' ) + '-firstname' ).value = full_name[0];
                                        if ( document.getElementById( 'input-' + ( useShipping ? 'shipping' : 'payment' ) + '-lastname' ) )
                                            document.getElementById( 'input-' + ( useShipping ? 'shipping' : 'payment' ) + '-lastname' ).value = full_name[1] ? full_name[1] : '';
                                        if ( document.getElementById( 'input-' + ( useShipping ? 'shipping' : 'payment' ) + '-company' ) )
                                            document.getElementById( 'input-' + ( useShipping ? 'shipping' : 'payment' ) + '-company' ).value = company;
                                        if ( document.getElementById( 'input-' + ( useShipping ? 'shipping' : 'payment' ) + '-address-1' ) )
                                            document.getElementById( 'input-' + ( useShipping ? 'shipping' : 'payment' ) + '-address-1' ).value = data['address'] != '' ? data['address'] : data['office_name'];
                                        if ( document.getElementById( 'input-' + ( useShipping ? 'shipping' : 'payment' ) + '-city' ) )
                                            document.getElementById( 'input-' + ( useShipping ? 'shipping' : 'payment' ) + '-city' ).value = data['city_name'];
                                        if ( document.getElementById( 'input-' + ( useShipping ? 'shipping' : 'payment' ) + '-postcode' ) )
                                            document.getElementById( 'input-' + ( useShipping ? 'shipping' : 'payment' ) + '-postcode' ).value = data['post_code'];
                                        if ( document.getElementById( 'input-payment-telephone' ) )
                                            document.getElementById( 'input-payment-telephone' ).value = data['phone'];
                                        if ( document.getElementById( 'input-payment-email' ) )
                                            document.getElementById( 'input-payment-email' ).value = data['email'];
                                <?php } else {
                                    ?>$econtLabelText.text(labelTxt);<?php
                                } ?>
                            }
                        }
                    });
                })(jQuery);
            </script>

            <?php
            if ($this->oneStepCheckoutModuleEnabled) {
                ?>
                <style>
                    #econt_iframe_wrapper {
                        width: 100vw;
                        height: 100vh;
                        background: rgba(0,0,0,.5);
                        display: none;
                        position: fixed;
                        z-index: 3;
                        top: 0;
                        left: 0;
                    }

                    #econt_iframe_inner {
                        position: absolute;
                        background: #ffffff;
                        width: 100vw;
                        height: 100%;
                        padding: 50px 10px;
                    }

                    #econtDeliverFrame {
                        position: relative;
                        width: 100%;
                        height: 100%;
                        border: none;
                        margin-top: 0;
                    }

                    .close-econt-modal {
                        float: right;
                        font-size: 24px;
                        cursor: pointer;
                        position: relative;
                        bottom: 15px;
                    }

                    .background-muted {
                        overflow-y: hidden;
                        z-index: -1;
                    }

                    @media screen and (min-width: 650px) {
                        #econt_iframe_inner {
                            top: 10%;
                            left: 50%;
                            transform: translateX(-50%);
                            width: 80vw;
                            height: 80%;
                            padding: 25px;
                            max-width: 800px;
                        }

                        #econtDeliverFrame {
                            margin-top: -15px;
                            max-height: 85vh;
                        }
                    }
                </style>
                <?php
            } else { ?>
                <style>
                    #econtDeliverFrame {
                        width: 80%;
                        height: 750px;
                        border: 0;
                    }
                </style>
            <?php }
            ?>
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

    public function prepareOrder() {
        $orderId = @intval($this->request->get['order_id']);
        if ($orderId <= 0) {
            if ($this->request->get['route'] === 'api/order/add') {
                $orderId = intval(reset($data));
                if ($orderId <= 0) return null;

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
                if (!($orderId = intval($this->session->data['order_id']))) return null;
            }
        }

        $orderData = $this->model_checkout_order->getOrder($orderId);
        if (empty($orderData) || $orderData['shipping_code'] !== 'econt_delivery.econt_delivery') return null;

        $this->load->model('extension/shipping/econt_delivery');
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

    public function calculateShippingPrice() {
        if (!array_key_exists('econt_delivery', $this->session->data)) {
            return;
        }

        if (!array_key_exists('payment_method', $this->session->data)) {
            return;
        }

        $aData = $this->session->data['econt_delivery']['customer_info'];

        $payment_method_price_map = [
            'cod' => array_key_exists('shipping_price_cod', $aData) ? $aData['shipping_price_cod'] : 0,
            'econt_payment' => array_key_exists('shipping_price_cod_e', $aData) ? $aData['shipping_price_cod_e'] : 0,
        ];

        $payment_method = $this->session->data['payment_method']['code'];

        if (in_array($payment_method, $payment_method_price_map)) {
            return @floatval($payment_method_price_map[$payment_method]);
        } else {
            return @floatval($this->session->data['econt_delivery']['customer_info']['shipping_price']);
        }
    }

}