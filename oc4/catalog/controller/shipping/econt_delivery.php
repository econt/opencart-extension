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

namespace Opencart\Catalog\Controller\Extension\EcontDeliveryOc4\Shipping;
class EcontDelivery extends \Opencart\System\Engine\Controller {

    public function afterModelCheckoutOrderAddHistory($eventRoute, &$data) {
	    //$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
	    //
	    //if ($order_info && !$order_info['order_status_id']) {
		//    $this->model_checkout_order->editOrder($this->session->data['order_id'], $order_data);
	    //}
        $this->load->model('extension/econt_delivery_oc4/shipping/econt_delivery');
        $this->load->model('setting/setting');

        $settings = $this->model_setting_setting->getSetting('shipping_econt_delivery');
        $data = $this->model_extension_econt_delivery_oc4_shipping_econt_delivery->prepareOrder();
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
	        $this->session->data['econt_delivery']['last_order_id'] = $orderData['order_id'];
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

	/** @noinspection PhpUndefinedFieldInspection */
	public function afterViewCheckoutCheckout($route, $params, $html) {
		$this->load->model('setting/setting');
		$settings = $this->model_setting_setting->getSetting('shipping_econt_delivery');
		if($settings["shipping_econt_delivery_status"] == 1){
			$this->load->language('extension/econt_delivery_oc4/shipping/econt_delivery');
			if($this->cart->customer && $this->cart->customer->getEmail()) {
				$email = $this->cart->customer->getEmail();
				$phone = $this->cart->customer->getTelephone();
			} else {
				$email = $this->session->data['shipping_address']['email'] ?? $this->session->data['guest']['email'] ?? '';
				$phone = $this->session->data['shipping_address']['telephone'] ?? $this->session->data['guest']['telephone'] ?? '';
			}
			
			$keys = explode('@', $settings['shipping_econt_delivery_private_key']);
			$frameParams = [
				'id_shop' => intval(reset($keys)),
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
			];
			$officeCode = trim($this->session->data['econt_delivery']['customer_info']['office_code'] ?? '');
			if (!empty($officeCode)) $frameParams['customer_office_code'] = $officeCode;
			$zip = trim($this->session->data['econt_delivery']['customer_info']['zip'] ?? '');
			if (!empty($zip)) $frameParams['customer_zip'] = $zip;
			$frameParams['module'] = 'onecheckout';
			
			$deliveryBaseURL = $settings['shipping_econt_delivery_system_url'];
			$frameURL = $deliveryBaseURL.'/customer_info.php?'. http_build_query($frameParams, '', '&');
			$deliveryMethodTxt = $this->language->get('text_delivery_method_description');
			$deliveryMethodPriceCD = $this->language->get('text_delivery_method_description_cd');
			
			
			
			$response = $this->registry->get('response');
			$output = $response->getOutput();
			/** @noinspection JSUnresolvedReference */
			/** @noinspection HtmlUnknownTarget */
			$output = $this->replaceBetween(
				'<script src="catalog/view/javascript/common.js" type="text/javascript"></script>',
				'</head>',
				$output,
				"\n
				<script type=\"text/javascript\">
					$(document).ready(function() {
						var isLogged = ".($this->cart->customer->getId() ? 1 : 0).";
						if(isLogged){
							$('#input-shipping-firstname').parent().parent()[0].style = 'display: none';
							$('#button-shipping-address')[0].style = 'display: none';
						} else {
							$('#input-firstname').parent().parent()[0].style = 'display: none';
							$('#input-shipping-company').parent().parent()[0].style = 'display: none';
						}
						var toggleButton = $('#toggleEcontDeliveryCustomerInfo');
                        toggleButton.click(function (){
                            $('#econtDeliveryFrame')[0].style.display = 'block';
                            $('#toggleEcontDeliveryCustomerInfo')[0].style.display = 'none';
                        });
					});
					
					$(window).unbind('message.econtShipping');
                    $(window).bind('message.econtShipping',function(e){
                        if(e.originalEvent.origin.indexOf('$deliveryBaseURL') == 0) {
                            if(e.originalEvent.data.shipment_error) alert(e.originalEvent.data.shipment_error)
                            else {
                                var isLogged = ".($this->cart->customer->getId() ? 1 : 0).";
                                $('#econtDeliveryFrame')[0].style.display = 'none';
                            	$('#toggleEcontDeliveryCustomerInfo')[0].style.display = 'block';
                                
                                var shippingInfo = e.originalEvent.data;
                                var labelTxt = '$deliveryMethodTxt' + ' - ' + shippingInfo.shipping_price + ' ' + shippingInfo.shipping_price_currency_sign;
                                if(shippingInfo.shipping_price !== shippingInfo.shipping_price_cod) {
                                    labelTxt += ' (+ ' + (shippingInfo.shipping_price_cod - shippingInfo.shipping_price).toFixed(2) + ' ' + shippingInfo.shipping_price_currency_sign + ' ' + '$deliveryMethodPriceCD' + ')'
                                }
                                var hiddenTextArea = $('[name=econt_delivery_shipping_info]')[0];
                                hiddenTextArea.value = JSON.stringify(e.originalEvent.data);
                        
                                var date = new Date();
                                date.setTime(date.getTime() + (5 * 60 * 1000));
                                document.cookie = \"econt_delivery_temporary_shipping_price=\" + JSON.stringify(e.originalEvent.data) + \"; expires=\" + date;
                                //loadCart();

                                var label = $('label[for=\"econt_delivery.econt_delivery\"]').last();
                                label.empty().append(labelTxt);

                                /**
                                 * Set billing form fields
                                 */
                                var full_name;
                                var company = ''
                                var data = e.originalEvent.data

                                if ( data['face'] != null ) {
                                    full_name = data['face'].split( ' ' );
                                    company = data['name'];
                                } else {
                                    full_name = data['name'].split( ' ' );
                                }
                                
                                if(isLogged){
                                    if(document.getElementById('input-shipping-firstname')) document.getElementById('input-shipping-firstname').value = full_name[0];
                                    if(document.getElementById('input-shipping-lastname')) document.getElementById( 'input-shipping-lastname' ).value = full_name[1] ? full_name[1] : '';
                                } else {
                                    if(document.getElementById('input-firstname')) document.getElementById('input-firstname').value = full_name[0];
	                                if(document.getElementById('input-lastname')) document.getElementById( 'input-lastname' ).value = full_name[1] ? full_name[1] : '';
	                                if(document.getElementById('input-email')) document.getElementById( 'input-email' ).value = data['email'];
                                }
                                if(document.getElementById('input-shipping-company')) document.getElementById('input-shipping-company' ).value = company;
                                if(document.getElementById('input-shipping-address-1')) document.getElementById('input-shipping-address-1' ).value = data['address'] !== '' ? data['address'] : data['office_name'];
                                if(document.getElementById('input-shipping-city')) document.getElementById('input-shipping-city').value = data['city_name'];
                                if(document.getElementById('input-shipping-postcode')) document.getElementById('input-shipping-postcode').value = data['post_code'];
                                
                                var countryId = 0;
                                switch(data['id_country']){
                                	case '1033': countryId = 33; break;
                                	case '1084': countryId = 84; break;
                                	case '1055': countryId = 55; break;
                                	case '1175': countryId = 175; break;
                                }
                                if(document.getElementById('input-shipping-country') && document.getElementById('input-shipping-country').value != countryId){
                                	document.getElementById('input-shipping-country').value = countryId;
                                	document.getElementById('input-shipping-country').dispatchEvent(new Event('change'));
                                }
                               
								window.intervalId = setInterval(function(data){
									var found = false;
									$('#input-shipping-zone>option').each(function(){
										if(this.text == data['region_name'] || this.text == data['region_name_en']) {
											found = true;
											this.setAttribute('selected', 'selected');
											return false;
										}
									}, data)
									
									if(found) clearInterval(window.intervalId);
								
								}, 1000, data);
                                //econtLabelText.text(labelTxt);
                                
                                $.post('".HTTPS_SERVER."index.php?route=extension/econt_delivery_oc4/shipping/econt_delivery|beforeCartSaveShipping',
                                    {customerInfo: shippingInfo, payment_method: $('input:radio[name=payment_method]:checked').val()}, function(response){
                                    	$('#button-shipping-method').click();
                                        //var tmp = $('<div></div>');
                                        //tmp.html(response);
                                        //$('div#collapse-checkout-confirm > div.panel-body > div.table-responsive').empty();
                                        //$('div#collapse-checkout-confirm > div.panel-body > div.table-responsive').html($('div.table-responsive', tmp).html())
                                    }, 'text');
                            }
                        }
                    });
				</script>
				\n"
			);
			
			$output = $this->replaceBetween(
				"<legend>Shipping Address</legend>",
				"<div class=\"row row-cols-1 row-cols-md-2\"",
				$output,
				"<iframe id=\"econtDeliveryFrame\" src=\"$frameURL\" style=\"
				    position: relative;
                    width: 100%;
                    height: 600px;
                    border: none;
                    margin-top: 0;
				\"></iframe>
				<button id=\"toggleEcontDeliveryCustomerInfo\" class=\"btn btn-primary\" style=\"display: none;\">".$this->language->get('text_delivery_method_change_button')."</button>
				<textarea style=\"display:none\" name=\"econt_delivery_shipping_info\"></textarea>
				"
			);
			
			$response->setOutput($output);
			$this->registry->set('response', $response);
		}
		
		return $html;
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

	public function replaceBetween($leftNeedle, $rightNeedle, $haystack, $replacement) {
		$pos = strpos($haystack, $leftNeedle);
		$start = $pos === false ? 0 : $pos + strlen($leftNeedle);
		
		$pos = strpos($haystack, $rightNeedle, $start);
		$end = $pos === false ? strlen($haystack) : $pos;
		
		return substr_replace($haystack, $replacement, $start, $end - $start);
	}

    public function updateShippingPrice($data) {
        if($this->session->data['shipping_method'] != 'econt_delivery.econt_delivery') {
            return;
        }

        $paymentData = null;

        if (array_key_exists("econt_delivery_temporary_shipping_price", $_COOKIE)) {
            $paymentData = json_decode($_COOKIE["econt_delivery_temporary_shipping_price"]);
        }

        //$paymentMethod = $this->session->data['payment_method']['code'];
	    
		foreach($this->session->data['payment_methods'] as $paymentMethod => &$method){
			if($paymentData){
				if($paymentMethod === 'cod') $method['cost'] = $paymentData->shipping_price_cod;
				if($paymentMethod === 'econt_payment') $method['cost'] = $paymentData->shipping_price_cod_e;
				//else $this->session->data['shipping_method']['cost'] = $paymentData->shipping_price;
			}
		}
    
    }
	
	public function onChangePaymentMethod(){
		$html = $this->load->controller('extension/payment/'.$this->request->request['payment_method'].'/index');
		$this->response->setOutput($this->fixCOD($html));
	}
	
	public function onChangeComment(){
		$this->session->data['comment'] = strip_tags($this->request->request['comment']);
	}
	
	public function fixCOD($html){
		if(strpos($html, "url: 'index.php?route=extension/payment/cod/confirm'") !== false){
			$this->language->load('checkout/checkout');
			if(is_null($this->model_catalog_information)) $this->load->model('catalog/information');
			$information_info = $this->model_catalog_information->getInformation($this->config->get('config_checkout_id'));
			$errorMsg = sprintf($this->language->get('error_agree'), $information_info['title']);
			
			$this->language->load('extension/econt_delivery_oc4/shipping/econt_delivery');
			$errorShipping = $this->language->get('err_missing_customer_info');;
			
			$html = str_replace("$('#button-confirm').on('click', function() {", "
				$('#button-confirm').on('click', function() {
					if(!$('input:checkbox[name=agree]')[0].checked){
						alert('$errorMsg');
						return;
					}
					if($('textarea[name=econt_delivery_shipping_info]')[0].value == ''){
						alert('$errorShipping');
						return;
					}
			", $html);
		}
		
		return $html;
	}

    public function beforeCartSaveShipping() {
	    $this->load->model('setting/setting');
		$customerInfo = json_decode(html_entity_decode($this->request->request['econt_delivery_shipping_info']),true);
		if(empty($customerInfo)) $customerInfo = $this->request->request['customerInfo'];
        $this->session->data['econt_delivery']['customer_info'] = $customerInfo;
        if(!$this->session->data['econt_delivery']['customer_info']) {
            $this->load->language('extension/econt_delivery_oc4/shipping/econt_delivery');
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode(array('error' => array('warning' => $this->language->get('err_missing_customer_info')))));
            return false;
        }
        $this->session->data['shipping_address']['firstname'] = $this->session->data['econt_delivery']['customer_info']['name'];
        $this->session->data['shipping_address']['lastname'] = '';
        $this->session->data['shipping_address']['iso_code_3'] = $this->session->data['econt_delivery']['customer_info']['country_code'];
        $this->session->data['shipping_address']['city'] = $this->session->data['econt_delivery']['customer_info']['city_name'];
        $this->session->data['shipping_address']['postcode'] = $this->session->data['econt_delivery']['customer_info']['post_code'];
        $this->session->data['shipping_address']['email'] = $this->session->data['econt_delivery']['customer_info']['email'];
        $this->session->data['shipping_address']['telephone'] = $this->session->data['econt_delivery']['customer_info']['phone'];
        if($this->session->data['econt_delivery']['customer_info']['office_code']) {
            $this->session->data['shipping_address']['address_1'] = 'Econt office: ' . $this->session->data['econt_delivery']['customer_info']['office_name'];
            $this->session->data['shipping_address']['address_2'] = $this->session->data['econt_delivery']['customer_info']['address'];
        } else {
            $this->session->data['shipping_address']['address_1'] = $this->session->data['econt_delivery']['customer_info']['address'];
        }

        //$this->load->model('setting/setting');
        $this->session->data['payment_address'] = $this->session->data['shipping_address'];
        $this->beforeCartSavePayment();
        $this->updateShippingPrice([]);
    }

    public function afterCheckoutConfirm() {
        if ($this->session->data['shipping_method']['code'] == 'econt_delivery.econt_delivery') {
	        $orderId = @intval($this->session->data['order_id']);
            if (empty($this->session->data['econt_delivery']['customer_info']) && $orderId <= 0) return;

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
		            UPDATE `".DB_PREFIX."order`
		            SET firstname = '".$this->db->escape($this->session->data['econt_delivery']['customer_info']['name'])."',
		            email = '".$this->db->escape($this->session->data['econt_delivery']['customer_info']['email'])."',
		            telephone = '".$this->db->escape($this->session->data['econt_delivery']['customer_info']['phone'])."',
		            comment = '".$this->db->escape($this->session->data['comment'])."'
		            WHERE order_id = $orderId
		        ");
            }
        }
    }

    public function beforeCartSavePayment() {
        if (!array_key_exists('econt_delivery', $this->session->data)) {
            return;
        }

        if($this->session->data['shipping_method'] == 'econt_delivery.econt_delivery') {
            $postfix_map = [
                'cod' => '_cod',
                'econt_payment' => '_cod_e'
            ];
            $cod = @array_key_exists($this->request->request['payment_method'], $postfix_map) ? $postfix_map[$this->request->request['payment_method']] : '';
            $this->session->data['shipping_methods']['econt_delivery']['quote']['econt_delivery']['cost'] = $this->session->data['econt_delivery']['customer_info']['shipping_price'.$cod];

        }
    }

    public function getCustomerInfoParams() {
        $response = array();
        try {
            $this->load->language('extension/econt_delivery_oc4/shipping/econt_delivery');

            if (!isset($this->session->data['api_id'])) throw new Exception($this->language->get('text_catalog_controller_api_extension_econt_delivery_permission_error'));

            $this->load->model('setting/setting');
            $econtDeliverySettings = $this->model_setting_setting->getSetting('shipping_econt_delivery');

            $separatorPos = strpos($econtDeliverySettings['shipping_econt_delivery_private_key'], '@');
            if ($separatorPos === false) throw new Exception($this->language->get('text_catalog_controller_api_extension_econt_delivery_shop_id_error'));
            $shopId = substr($econtDeliverySettings['shipping_econt_delivery_private_key'], 0, $separatorPos);
            if (intval($shopId) <= 0) throw new Exception($this->language->get('text_catalog_controller_api_extension_econt_delivery_shop_id_error'));

            $this->load->model('extension/econt_delivery_oc4/shipping/econt_delivery');
			if($this->session->data['shipping_address']['firstname'] == '8a9ggua0sjm$Fn'){
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
        if (isset($this->session->data['shipping_method']['code']) && $this->session->data['shipping_method']['code'] === 'econt_delivery.econt_delivery') $this->session->data['shipping_method']['cost'] = floatval($shippingCost);

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode(@$this->session->data['econt_delivery']['customer_info']));
    }
}