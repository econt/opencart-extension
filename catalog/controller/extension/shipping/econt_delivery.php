<?php

/** @noinspection PhpUnused */

use Dotenv\Repository\Adapter\WriterInterface;

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
class ControllerExtensionShippingEcontDelivery extends Controller
{

    public function afterModelCheckoutOrderAddHistory($eventRoute, &$data, &$output)
    {
        $this->load->model('extension/shipping/econt_delivery');
        $this->load->model('setting/setting');

        $orderId = 0;
        if ($eventRoute == 'checkout/order/addOrderHistory') {
            $orderId = $data[0];
        }

        $settings = $this->model_setting_setting->getSetting('shipping_econt_delivery');
        $data = $this->model_extension_shipping_econt_delivery->prepareOrder($orderId);
        $order = $data['order'];
        $orderData = $data['orderData'];
        $customerInfo = $data['customerInfo'];

        if ((
                empty($settings['shipping_econt_delivery_system_url'])
                || empty($settings['shipping_econt_delivery_private_key'])
            ) || (
                empty($order)
                || empty($orderData)
                || empty($customerInfo)
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

    public function afterViewCheckoutBilling($route, $templateParams, $html)
    {
        return preg_replace("#<div (class=\"checkbox\">\\s+<label>\\s+<input\\s+type=\"checkbox\"\\s+name=\"shipping_address\")#i", '<div style="display:none !important;" \1', $html);
    }

    public function afterViewCheckoutCheckout($route, &$params, &$html)
    {

        $this->load->model('setting/setting');

        $settings = $this->model_setting_setting->getSetting('shipping_econt_delivery');

        if ($settings['shipping_econt_delivery_checkout_mode'] == 'onestep') {

            $this->ensureAddressFields($this->session->data['shipping_address'], 'shipping');
            $this->ensureAddressFields($this->session->data['payment_address'], 'payment');

            if (!isset($this->session->data['comment'])) {
                $this->session->data['comment'] = '';
            }

            if (!isset($this->session->data['guest'])) {
                $this->session->data['account'] = 'guest';
                $this->session->data['guest'] = [
                    'customer_group_id' => $this->config->get('config_customer_group_id'),
                    'firstname' => $this->session->data['shipping_address']['firstname'] ?? '',
                    'lastname' => $this->session->data['shipping_address']['lastname'] ?? '',
                    'email' => $this->session->data['shipping_address']['email'] ?? '',
                    'telephone' => $this->session->data['shipping_address']['telephone'] ?? '',
                    'city' => $this->session->data['shipping_address']['city'] ?? '',
                    'postcode' => $this->session->data['shipping_address']['postcode'] ?? '',
                    'iso_code_3' => $this->session->data['shipping_address']['iso_code_3'] ?? '',
                    'address_1' => $this->session->data['shipping_address']['address_1'] ?? '',
                    'custom_field' => [],
                    'shipping_address' => 1
                ];
            }

            if (empty($this->session->data['shipping_address'])) {
                $this->session->data['shipping_address'] = [
                    'firstname' => '8a9ggua0sjm$Fn',
                    'lastname' => '',
                    'iso_code_3' => 'BGR',
                    'city' => 'Русе',
                    'postcode' => '7000',
                    'address_1' => 'бул. Славянски 13',
                ];
            }

            $shippingMethodData = [];
            $this->load->model('setting/extension');
            $results = $this->model_setting_extension->getExtensions('shipping');

            foreach ($results as $result) {

                if ($result['code'] != 'econt_delivery') {
                    continue;
                }

                if ($this->config->get('shipping_' . $result['code'] . '_status')) {
                    $this->load->model('extension/shipping/' . $result['code']);
                    ob_start();
                    $quote = $this->{'model_extension_shipping_' . $result['code']}->getQuote($this->session->data['shipping_address']);
                    ob_clean();

                    if ($quote) {
                        $shippingMethodData[$result['code']] = [
                            'title' => $quote['title'],
                            'code' => $quote['quote'][$result['code']]['code'],
                            'quote' => $quote['quote'],
                            'sort_order' => $quote['sort_order'],
                            'error' => $quote['error']
                        ];
                    }
                }
            }


            $this->session->data['shipping_methods'] = $shippingMethodData;
            $this->session->data['shipping_method'] = $shippingMethodData['econt_delivery']['quote']['econt_delivery'];;

            if(!isset($this->session->data['payment_address'])) {
                $this->session->data['payment_address'] = $this->session->data['shipping_address'];
            }

            $this->session->data['payment_method'] = [
                'code' => 'cod',
                'title' => 'Cash on delivery',
            ];

            $ajaxShippingMethod = "
				    $.ajax({
				        url: 'index.php?route=checkout/confirm',
				        dataType: 'html',
				        beforeSend: function() {
				            $('#button-account').button('loading');
				        },
				        complete: function() {
				            $('#button-account').button('reset');
				        },
				        success: function(html) {
				            $('.alert-dismissible, .text-danger').remove();
				            $('.form-group').removeClass('has-error');
				            $('#collapse-checkout-confirm .panel-body').html(html);
				            $('#collapse-checkout-confirm').parent().find('.panel-heading .panel-title').html('<a href=\"#collapse-checkout-confirm\" data-toggle=\"collapse\" data-parent=\"#accordion\" class=\"accordion-toggle\">{{ text_checkout_confirm }} <i class=\"fa fa-caret-down\"></i></a>');
				            $('a[href=\'#collapse-checkout-confirm\']').trigger('click');
				            
				            $('#collapse-checkout-option').parent()[0].style = 'display: none';
				            $('#collapse-payment-address').parent()[0].style = 'display: none';
				            $('#collapse-shipping-address').parent()[0].style = 'display: none';
				            $('#collapse-shipping-method').parent()[0].style = 'display: none';
				            $('#collapse-payment-method').parent()[0].style = 'display: none';
				            $('#collapse-checkout-confirm').parent().find('.panel-heading')[0].style = 'display: none';
				        },
						error: function(xhr, ajaxOptions, thrownError) {
							alert(thrownError + \"\\r\\n\" + xhr.statusText + \"\\r\\n\" + xhr.responseText);
						}
					});
			";

            $html = $this->replaceBetween('$(document).ready(function() {', '// Checkout', $html, "
					$ajaxShippingMethod
				});
			");

            $html = $this->replaceBetween('// Checkout', '// Login', $html, "
				$(document).delegate('#button-@account', 'click', function() {
					$ajaxShippingMethod
				});
			");
        }

        return $html;
    }

    public function afterViewCheckoutLogin($route)
    {

        $this->load->model('setting/setting');
        $settings = $this->model_setting_setting->getSetting('shipping_econt_delivery');

        if ($settings['shipping_econt_delivery_checkout_mode'] == 'onestep') {
            $html = $this->response->getOutput();
            $html = str_replace('<input type="radio" name="account" value="register" checked="checked">', '<input type="radio" name="account" value="register" disabled">', $html);
            $html = str_replace('<input type="radio" name="account" value="guest">', '<input type="radio" name="account" value="guest" checked="checked">', $html);
            $this->response->setOutput($html);
        }
    }

    public function afterViewCheckoutConfirm($route, $params, $html)
    {

        $this->load->model('setting/setting');
        $settings = $this->model_setting_setting->getSetting('shipping_econt_delivery');

        if ($settings['shipping_econt_delivery_checkout_mode'] == 'onestep') {

            $this->load->model("extension/shipping/econt_delivery");

            $econt_delivery = $this->model_extension_shipping_econt_delivery->getQuote([]);

            $this->session->data['shipping_methods']['econt_delivery'] = $econt_delivery;
            $this->session->data['shipping_method'] = $econt_delivery['quote']['econt_delivery'];

            if(!isset($this->session->data['payment_address'])) {
                $this->session->data['payment_address'] = $this->session->data['shipping_address'];
            }

            // Uncomment to load the form from the Econt Delivery system
            $this->loadEcontScripts($route, $params, $html);

            if (!isset($this->session->data['econte_delivery'])) {
                if (isset($this->request->post['customerInfo'])) {
                    $this->session->data['econt_delivery']['customer_info'] = $this->request->post['customerInfo'];
                } else {
                    $this->session->data['econt_delivery']['customer_info'] = null;
                }
            }

            $this->load->controller('checkout/payment_method/index');
            $payment = $this->response->getOutput();
            $payment = str_replace('id="button-payment-method"', 'id="button-payment-method" style="display:none;"', $payment);

            $html = $payment . $html;
            $html = $this->fixCOD($html);
        }

        return $html;
    }

    public function replaceBetween($leftNeedle, $rightNeedle, $haystack, $replacement)
    {
        $pos = strpos($haystack, $leftNeedle);
        $start = $pos === false ? 0 : $pos + strlen($leftNeedle);

        $pos = strpos($haystack, $rightNeedle, $start);
        $end = $pos === false ? strlen($haystack) : $pos;

        return substr_replace($haystack, $replacement, $start, $end - $start);
    }

    public function updateShippingPrice($data)
    {

        if ($this->session->data['shipping_method']['code'] != 'econt_delivery.econt_delivery') {
            return;
        }

        $paymentData = null;

        if (array_key_exists("econt_delivery_temporary_shipping_price", $_COOKIE)) {
            $paymentData = json_decode($_COOKIE["econt_delivery_temporary_shipping_price"]);
        }

        $paymentMethod = $this->session->data['payment_method']['code'];

        if ($paymentData) {
            if ($paymentMethod === 'cod') {
                $this->session->data['shipping_method']['cost'] = $paymentData->shipping_price_cod;
            } elseif ($paymentMethod === 'econt_payment') {
                $this->session->data['shipping_method']['cost'] = $paymentData->shipping_price_cod_e;
            } else {
                $this->session->data['shipping_method']['cost'] = $paymentData->shipping_price;
            }
        }

    }

    public function onChangePaymentMethod()
    {
        $html = $this->load->controller('extension/payment/' . $this->request->request['payment_method'] . '/index');

        // One step for maza theme
        if($this->config->get('maza_status') == 1) {
            $this->load->model('setting/setting');
            $settings = $this->model_setting_setting->getSetting('shipping_econt_delivery');
            if ($settings['shipping_econt_delivery_checkout_mode'] == 'onestep') {
                $this->session->data['payment_method']['code'] = $this->request->request['payment_method'];
                $this->session->data['payment_method']['title'] = $this->session->data['payment_methods'][$this->request->request['payment_method']]['title'];
                $this->load->controller('checkout/confirm');
            }
        }

        $this->response->setOutput($this->fixCOD($html));
    }

    public function onChangeComment()
    {
        $this->session->data['comment'] = strip_tags($this->request->request['comment']);
    }

    public function fixCOD($html)
    {

        if (strpos($html, "url: 'index.php?route=extension/payment/cod/confirm'") !== false) {

            $this->language->load('checkout/checkout');

            if (is_null($this->model_catalog_information)) {
                $this->load->model('catalog/information');
            }

            $information_info = $this->model_catalog_information->getInformation($this->config->get('config_checkout_id'));
            $errorMsg = sprintf($this->language->get('error_agree'), $information_info['title']);

            $this->language->load('extension/shipping/econt_delivery');
            $errorShipping = $this->language->get('err_missing_customer_info');;

            $html = str_replace("$('#button-confirm').on('click', function() {", "
				$('#button-confirm').on('click', function() {
					if(!$('input:checkbox[name=agree]')[0].checked){
						alert('$errorMsg');
						return;
					}
					if($('textarea[name=econt_delivery_shipping_info]').val() == ''){
						alert('$errorShipping');
						return;
					}
			", $html);
        }

        return $html;
    }

    public function beforeCartSaveShipping()
    {


        $this->load->model('setting/setting');
        $settings = $this->model_setting_setting->getSetting('shipping_econt_delivery');
        $this->load->model('extension/shipping/econt_delivery');

        if (@$this->request->request['shipping_method'] == 'econt_delivery.econt_delivery' || $settings['shipping_econt_delivery_checkout_mode'] == 'onestep') {

            $customerInfo = null;
            if (isset($this->request->request['econt_delivery_shipping_info'])) {
                $customerInfo = json_decode(html_entity_decode($this->request->request['econt_delivery_shipping_info']), true);
            }

            if (empty($customerInfo) && isset($this->request->request['customerInfo'])) {
                $customerInfo = $this->request->request['customerInfo'];
            }

            $this->session->data['econt_delivery']['customer_info'] = $customerInfo;

            if (!$this->session->data['econt_delivery']['customer_info']) {
                $this->load->language('extension/shipping/econt_delivery');
                $this->response->addHeader('Content-Type: application/json');
                $this->response->setOutput(json_encode(array('error' => array('warning' => $this->language->get('err_missing_customer_info')))));
                return false;
            }
            $econt_customer_name = $this->session->data['econt_delivery']['customer_info']['name'];
            $econt_customer_name_exploaded = explode(' ', $econt_customer_name, 2);
            $this->session->data['shipping_address']['firstname'] = $econt_customer_name_exploaded[0] ?? $this->session->data['shipping_address']['firstname'];
            $this->session->data['shipping_address']['lastname'] = $econt_customer_name_exploaded[1] ?? $this->session->data['shipping_address']['lastname'];
            $this->session->data['shipping_address']['iso_code_3'] = $this->session->data['econt_delivery']['customer_info']['country_code'];
            $this->session->data['shipping_address']['city'] = $this->session->data['econt_delivery']['customer_info']['city_name'];
            $this->session->data['shipping_address']['postcode'] = $this->session->data['econt_delivery']['customer_info']['post_code'];
            $this->session->data['shipping_address']['email'] = $this->session->data['econt_delivery']['customer_info']['email'];
            $this->session->data['shipping_address']['telephone'] = $this->session->data['econt_delivery']['customer_info']['phone'];

            if ($this->session->data['econt_delivery']['customer_info']['office_code']) {
                $this->session->data['shipping_address']['address_1'] = 'Econt office: ' . $this->session->data['econt_delivery']['customer_info']['office_name'];
                $this->session->data['shipping_address']['address_2'] = $this->session->data['econt_delivery']['customer_info']['address'];
            } else {
                $this->session->data['shipping_address']['address_1'] = $this->session->data['econt_delivery']['customer_info']['address'];
            }

            $this->load->model('setting/setting');
            $settings = $this->model_setting_setting->getSetting('shipping_econt_delivery');

            if ($settings['shipping_econt_delivery_checkout_mode'] == 'onestep') {

                $this->session->data['payment_address'] = $this->session->data['shipping_address'];
                $this->beforeCartSavePayment();
                $this->updateShippingPrice([]);

                $eventsToDisable = [];
                foreach ($this->model_setting_event->getEvents() as $event) {
                    if (strpos($event['trigger'], 'checkout/confirm') !== false) {
                        $eventsToDisable[] = $event['event_id'];
                    }
                }

                $this->load->controller('checkout/confirm');
            }
        }
    }

    public function afterCheckoutConfirm()
    {


        if ($this->session->data['shipping_method']['code'] == 'econt_delivery.econt_delivery') {

            $orderId = @intval($this->session->data['order_id']);

            if (empty($this->session->data['econt_delivery']['customer_info']) && $orderId <= 0) {
                return;
            }

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

                $this->db->query("
		            UPDATE `" . DB_PREFIX . "order`
		            SET firstname = '" . $this->db->escape($this->session->data['econt_delivery']['customer_info']['name'] ?? '') . "',
		            email = '" . $this->db->escape($this->session->data['econt_delivery']['customer_info']['email'] ?? '') . "',
		            telephone = '" . $this->db->escape($this->session->data['econt_delivery']['customer_info']['phone'] ?? '') . "',
		            comment = '" . $this->db->escape($this->session->data['comment'] ?? '') . "'
		            WHERE order_id = $orderId
		        ");
            }
        }
    }

    public function beforeCartSavePayment()
    {


        if (!array_key_exists('econt_delivery', $this->session->data)) {
            return;
        }

        if ($this->session->data['shipping_method']['code'] == 'econt_delivery.econt_delivery') {
            $postfix_map = [
                'cod' => '_cod',
                'econt_payment' => '_cod_e'
            ];

            $cod = @array_key_exists($this->request->request['payment_method'], $postfix_map) ? $postfix_map[$this->request->request['payment_method']] : '';
            @$this->session->data['shipping_method']['cost'] = $this->session->data['econt_delivery']['customer_info']['shipping_price' . $cod];

        }
    }

    public function getCustomerInfoParams()
    {

        $response = array();

        try {

            $this->session->data['order_id'] = $this->request->get['order_id'] ?? 0;

            $this->load->language('extension/shipping/econt_delivery');

            if (!isset($this->session->data['api_id'])) {
                throw new Exception($this->language->get('text_catalog_controller_api_extension_econt_delivery_permission_error'));
            }

            $this->load->model('setting/setting');

            $econtDeliverySettings = $this->model_setting_setting->getSetting('shipping_econt_delivery');

            $separatorPos = strpos($econtDeliverySettings['shipping_econt_delivery_private_key'], '@');

            if ($separatorPos === false) {
                throw new Exception($this->language->get('text_catalog_controller_api_extension_econt_delivery_shop_id_error'));
            }

            $shopId = substr($econtDeliverySettings['shipping_econt_delivery_private_key'], 0, $separatorPos);

            if (intval($shopId) <= 0) {
                throw new Exception($this->language->get('text_catalog_controller_api_extension_econt_delivery_shop_id_error'));
            }

            $this->load->model('extension/shipping/econt_delivery');

            if (($this->session->data['shipping_address']['firstname'] ?? null) == '8a9ggua0sjm$Fn') {
                $this->session->data['shipping_address']['company'] = '';
                $this->session->data['shipping_address']['firstname'] = '';
                $this->session->data['shipping_address']['lastname'] = '';
                $this->session->data['shipping_address']['iso_code_3'] = '';
                $this->session->data['shipping_address']['city'] = '';
                $this->session->data['shipping_address']['postcode'] = '';
                $this->session->data['shipping_address']['address_1'] = '';
                $this->session->data['shipping_address']['address_2'] = '';
            }

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

            $officeCode = @trim(@$this->session->data['econt_delivery']['customer_info']['office_code']);

            if (!empty($officeCode)) {
                $response['customer_info']['customer_office_code'] = $officeCode;
            }
            $zip = @trim(@$this->session->data['econt_delivery']['customer_info']['zip']);

            if (!empty($zip)) {
                $response['customer_info']['customer_zip'] = $zip;
            }

            $response['customer_info_url'] = $econtDeliverySettings['shipping_econt_delivery_system_url'] . '/customer_info.php?' . @http_build_query($response['customer_info'], null, '&');
        } catch (Exception $exception) {
            $response = array('error' => $exception->getMessage());
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($response));

        return false;
    }

    public function beforeApi()
    {
        $this->loadEcontDeliveryData();
        return false;
    }

    public function loadEcontDeliveryData()
    {

        $orderId = @intval($this->request->get['order_id'] ?? $this->session->data['order_id'] ?? 0);

        if (@$this->request->get['action'] === 'updateCustomerInfo') {

            if ($orderId > 0) {

                $this->db->query("
                    INSERT INTO " . DB_PREFIX . "econt_delivery_customer_info
                    SET id_order = {$orderId},
                        customer_info = '" . json_encode($this->request->post) . "'
                    ON DUPLICATE KEY UPDATE
                        customer_info = VALUES(customer_info)
                ");
            }
            $this->session->data['econt_delivery']['customer_info'] = $this->request->post;
        } else {
            if (array_key_exists('econt_delivery', $this->session->data) && empty($this->session->data['econt_delivery']['customer_info']) && $orderId > 0) {
                $customerInfo = $this->db->query("
                    SELECT ci.customer_info AS customerInfo
                    FROM " . DB_PREFIX . "econt_delivery_customer_info AS ci
                    WHERE TRUE
                        AND ci.id_order = {$orderId}
                    LIMIT 1
                ");
                $customerInfo = json_decode($customerInfo->row['customerInfo'], true);
                if ($customerInfo) $this->session->data['econt_delivery']['customer_info'] = $customerInfo;
            }
        }

        if (!@$this->session->data['payment_method'] && is_array(@$this->session->data['payment_methods']) && in_array(@$this->request->post['payment_method'], @$this->session->data['payment_methods'])) {
            $this->session->data['payment_method'] = $this->session->data['payment_methods'][$this->request->post['payment_method']];
        }

        if (!@$this->session->data['shipping_method'] && is_array(@$this->session->data['shipping_methods']) && in_array(@$this->request->post['shipping_method'], @$this->session->data['shipping_methods'])) {
            $this->session->data['shipping_method'] = $this->session->data['shipping_methods'][$this->request->post['shipping_method']];
        }

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

        if ($shippingCost === 0 && isset($this->session->data['econt_delivery']['customer_info']['shipping_price'])) {
            $shippingCost = $this->session->data['econt_delivery']['customer_info']['shipping_price'];
        }

        $shippingCost = floatval($shippingCost);

        if (isset($this->session->data['shipping_methods']['econt_delivery'])) {
            $this->session->data['shipping_methods']['econt_delivery']['quote']['econt_delivery']['cost'] = $shippingCost;
        }

        if (isset($this->session->data['shipping_method']) && $this->session->data['shipping_method']['code'] === 'econt_delivery.econt_delivery') {
            $this->session->data['shipping_method']['cost'] = floatval($shippingCost);
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode(@$this->session->data['econt_delivery']['customer_info']));
    }

    /**
     * Ensures address has all required fields to prevent undefined key errors
     */
    private function ensureAddressFields(&$address, $type)
    {
        $requiredFields = [
            'firstname' => $this->session->data[$type . '_address']['firstname'] ?? '',
            'lastname' => $this->session->data[$type . '_address']['lastname'] ?? '',
            'company' => $this->session->data[$type . '_address']['company'] ?? '',
            'address_1' => $this->session->data[$type . '_address']['address_1'] ?? '',
            'address_2' => $this->session->data[$type . '_address']['address_2'] ?? '',
            'city' => $this->session->data[$type . '_address']['city'] ?? '',
            'postcode' => $this->session->data[$type . '_address']['postcode'] ?? '',
            'zone' => $this->session->data[$type . '_address']['zone'] ?? '',
            'zone_id' =>$this->session->data[$type . '_address']['zone_id'] ?? 0 ,
            'country' => $this->session->data[$type . '_address']['country'] ?? '',
            'country_id' =>$this->session->data[$type . '_address']['country_id'] ?? 0,
            'iso_code_2' => $this->session->data[$type . '_address']['iso_code_2'] ?? '',
            'iso_code_3' => $this->session->data[$type . '_address']['iso_code_3'] ?? '',
            'address_format' => $this->session->data[$type . '_address']['address_format'] ?? '',
        ];

        foreach ($requiredFields as $field => $defaultValue) {
            if (!isset($address[$field])) {
                $address[$field] = $defaultValue;
            }
        }
    }

    /**
     * Modifies the checkout page output by appending Econt delivery-related JavaScript.
     *
     * @param string $route The route being called.
     * @param array $args Additional arguments passed to the method.
     * @param string &$output The HTML output of the checkout page, modified to include Econt delivery script.
     */
    public function loadEcontScripts(&$route, &$args, &$output)
    {
        $this->load->model('extension/shipping/econt_delivery');
        $script = $this->model_extension_shipping_econt_delivery->outputEcontDeliveryCheckoutScript();
        $output = $output . $script;
    }
}
