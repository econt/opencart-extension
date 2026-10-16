<?php

namespace Opencart\Catalog\Controller\Extension\EcontDeliveryOc4\Shipping;

class EcontDelivery extends \Opencart\System\Engine\Controller {

    /**
     * Get customer info parameters for iframe
     * 
     * @return void
     */
    public function getCustomerInfoParams(): void {
        $this->load->language('extension/econt_delivery_oc4/shipping/econt_delivery');
        $this->load->model('setting/setting');

        $json = [];

        try {
            $settings = $this->model_setting_setting->getSetting('shipping_econt_delivery');

            if (empty($settings['shipping_econt_delivery_system_url']) || empty($settings['shipping_econt_delivery_private_key'])) {
                throw new \Exception($this->language->get('error_configuration'));
            }

            $separatorPos = strpos($settings['shipping_econt_delivery_private_key'], '@');
            if ($separatorPos === false) {
                throw new \Exception($this->language->get('text_catalog_controller_api_extension_econt_delivery_shop_id_error'));
            }

            $shopId = substr($settings['shipping_econt_delivery_private_key'], 0, $separatorPos);
            if (intval($shopId) <= 0) {
                throw new \Exception($this->language->get('text_catalog_controller_api_extension_econt_delivery_shop_id_error'));
            }

            $this->load->model('extension/econt_delivery_oc4/shipping/econt_delivery');

            $customer = $this->customer;
            $email = $customer->isLogged() ? $customer->getEmail() : ($this->session->data['guest']['email'] ?? '');
            $phone = $customer->isLogged() ? $customer->getTelephone() : ($this->session->data['guest']['telephone'] ?? '');
            
            $shippingAddress = $this->session->data['shipping_address'] ?? [];
            $firstname = $shippingAddress['firstname'] ?? '';
            $lastname = $shippingAddress['lastname'] ?? '';

            $frameParams = [
                'id_shop' => intval($shopId),
                'order_weight' => $this->cart->getWeight(),
                'order_total' => $this->model_extension_econt_delivery_oc4_shipping_econt_delivery->getOrderTotal(),
                'order_currency' => $this->session->data['currency'] ?? $this->config->get('config_currency'),
                'customer_company' => $shippingAddress['company'] ?? '',
                'customer_name' => trim($firstname . ' ' . $lastname),
                'customer_phone' => $phone,
                'customer_email' => $email,
                'customer_country' => $shippingAddress['iso_code_3'] ?? '',
                'customer_city_name' => $shippingAddress['city'] ?? '',
                'customer_post_code' => $shippingAddress['postcode'] ?? '',
                'customer_address' => trim(($shippingAddress['address_1'] ?? '') . ' ' . ($shippingAddress['address_2'] ?? '')),
                'ignore_history' => true,
                'default_css' => true
            ];

            // Include existing office code if available
            $officeCode = trim($this->session->data['econt_delivery']['customer_info']['office_code'] ?? '');
            if (!empty($officeCode)) {
                $frameParams['customer_office_code'] = $officeCode;
            }

            $zip = trim($this->session->data['econt_delivery']['customer_info']['zip'] ?? '');
            if (!empty($zip)) {
                $frameParams['customer_zip'] = $zip;
            }

            $json['success'] = true;
            $json['iframe_url'] = $settings['shipping_econt_delivery_system_url'] . '/customer_info.php?' . http_build_query($frameParams, '', '&');
            $json['delivery_base_url'] = $settings['shipping_econt_delivery_system_url'];

        } catch (\Exception $e) {
            $json['error'] = $e->getMessage();
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * Save customer info from Econt iframe
     * 
     * @return void
     */
    public function saveCustomerInfo(): void {
        $this->load->language('extension/econt_delivery_oc4/shipping/econt_delivery');

        $json = [];

        try {
            
            if (empty($this->request->post)) {
                throw new \Exception($this->language->get('err_missing_customer_info'));
            }

            $customerInfo = $this->request->post;

            // Only a result from the Econt form
            if (!$this->isValidCustomerInfo($customerInfo)) {
                throw new \Exception($this->language->get('err_missing_customer_info'));
            }

            // Save to session
            $this->session->data['econt_delivery']['customer_info'] = $customerInfo;

            // Update shipping address in session
            $this->updateSessionAddress($customerInfo);

            $shippingPrice = $this->refreshShippingCost();

            $json['success'] = true;
            $json['customer_info'] = $customerInfo;
            $json['shipping_price'] = $shippingPrice;

        } catch (\Exception $e) {
            $json['error'] = $e->getMessage();
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * Checks that the data is a result from the Econt customer info form (id, name and phone are always there)
     *
     * @param mixed $customerInfo
     * @return bool
     */
    private function isValidCustomerInfo($customerInfo): bool {
        return is_array($customerInfo)
            && is_scalar($customerInfo['id'] ?? null) && trim((string)$customerInfo['id']) !== ''
            && is_string($customerInfo['name'] ?? null) && trim($customerInfo['name']) !== ''
            && is_scalar($customerInfo['phone'] ?? null) && trim((string)$customerInfo['phone']) !== '';
    }

    /**
     * Update session address with Econt customer info
     * 
     * @param array $customerInfo
     * @return void
     */
    private function updateSessionAddress(array $customerInfo): void {
        $nameParts = explode(' ', trim($customerInfo['name']), 2);
        $firstname = $nameParts[0] ?? '';
        $lastname = $nameParts[1] ?? '';

        $this->session->data['shipping_address']['firstname'] = $firstname;
        $this->session->data['shipping_address']['lastname'] = $lastname;
        $this->session->data['shipping_address']['iso_code_3'] = $customerInfo['country_code'] ?? 'BGR';
        $this->session->data['shipping_address']['city'] = $customerInfo['city_name'] ?? '';
        $this->session->data['shipping_address']['postcode'] = $customerInfo['post_code'] ?? '';
        $this->session->data['shipping_address']['email'] = $customerInfo['email'] ?? '';
        $this->session->data['shipping_address']['telephone'] = $customerInfo['phone'] ?? '';

        if (!empty($customerInfo['office_code'])) {
            $this->session->data['shipping_address']['address_1'] = 'Econt office: ' . ($customerInfo['office_name'] ?? '');
            $this->session->data['shipping_address']['address_2'] = $customerInfo['address'] ?? '';
        } else {
            $this->session->data['shipping_address']['address_1'] = $customerInfo['address'] ?? '';
            $this->session->data['shipping_address']['address_2'] = '';
        }
    }

    /**
     * Validate shipping method has customer info before saving
     * Event: catalog/controller/checkout/shipping_method.save/before
     * 
     * @param string $route
     * @param array $args
     * @return void
     */
    public function validateCustomerInfo(string $route, array &$args): void {
        if (isset($this->request->post['shipping_method']) && $this->request->post['shipping_method'] === 'econt_delivery.econt_delivery') {
            if (empty($this->session->data['econt_delivery']['customer_info'])) {
                $this->load->language('extension/econt_delivery_oc4/shipping/econt_delivery');
                
                $json = ['error' => $this->language->get('err_missing_customer_info')];
                
                $this->response->addHeader('Content-Type: application/json');
                $this->response->setOutput(json_encode($json));
                $this->response->output();
                exit;
            }

            // OpenCart copies the cached quote into shipping_method, so it must have the price of the selected address
            $this->refreshShippingCost();
        }
    }

    /**
     * Put the current Econt price in the order totals before the order is created
     * Events: catalog/controller/checkout/confirm/before, catalog/controller/checkout/confirm.confirm/before
     *
     * The payment method is known only here (shipping_method.save unsets it), so the cash on delivery price applies.
     *
     * @param string $route
     * @param array $args
     * @return void
     */
    public function beforeCheckoutConfirm(string $route, array &$args): void {
        $this->refreshShippingCost();
    }

    /**
     * Write the Econt price (from the selected address and payment method) to the cached quote and to the selected
     * shipping method. OpenCart takes the shipping cost from them and does not call getQuote() again.
     *
     * @return float
     */
    private function refreshShippingCost(): float {
        $this->load->model('extension/econt_delivery_oc4/shipping/econt_delivery');
        $shippingPrice = $this->model_extension_econt_delivery_oc4_shipping_econt_delivery->calculateShippingPrice();
        $text = $this->currency->format($shippingPrice, $this->session->data['currency']);

        if (isset($this->session->data['shipping_methods']['econt_delivery']['quote']['econt_delivery'])) {
            $this->session->data['shipping_methods']['econt_delivery']['quote']['econt_delivery']['cost'] = $shippingPrice;
            $this->session->data['shipping_methods']['econt_delivery']['quote']['econt_delivery']['text'] = $text;
        }

        if (($this->session->data['shipping_method']['code'] ?? '') === 'econt_delivery.econt_delivery') {
            $this->session->data['shipping_method']['cost'] = $shippingPrice;
            $this->session->data['shipping_method']['text'] = $text;
        }

        return $shippingPrice;
    }

    /**
     * Update order data before creation with Econt address
     * Event: catalog/model/checkout/order/addOrder/before
     * 
     * @param string $route
     * @param array $args
     * @return void
     */
    public function beforeOrderCreate(string $route, array &$args): void {
        $orderData = &$args[0];

        if (isset($orderData['shipping_method']) && $orderData['shipping_method']['code'] === 'econt_delivery.econt_delivery') {
            $customerInfo = $this->session->data['econt_delivery']['customer_info'] ?? null;

            if ($customerInfo) {
                // Parse customer name
                $nameParts = explode(' ', trim($customerInfo['name']), 2);
                $firstname = $nameParts[0] ?? '';
                $lastname = $nameParts[1] ?? '';

                // Update order shipping address
                $orderData['shipping_firstname'] = $firstname;
                $orderData['shipping_lastname'] = $lastname;
                $orderData['shipping_company'] = $customerInfo['face'] ?? '';
                $orderData['shipping_city'] = $customerInfo['city_name'] ?? '';
                $orderData['shipping_postcode'] = $customerInfo['post_code'] ?? '';
                $orderData['shipping_country'] = $customerInfo['country_name'] ?? '';
                $orderData['shipping_zone'] = $customerInfo['region_name'] ?? '';

                if (!empty($customerInfo['office_code'])) {
                    $orderData['shipping_address_1'] = 'Econt office: ' . ($customerInfo['office_name'] ?? '');
                    $orderData['shipping_address_2'] = $customerInfo['address'] ?? '';
                } else {
                    $orderData['shipping_address_1'] = $customerInfo['address'] ?? '';
                    $orderData['shipping_address_2'] = '';
                }

                // Update contact info if available
                if (!empty($customerInfo['email'])) {
                    $orderData['email'] = $customerInfo['email'];
                }
                if (!empty($customerInfo['phone'])) {
                    $orderData['telephone'] = $customerInfo['phone'];
                }
            }
        }
    }

    /**
     * Save customer info to database after order is created
     * Event: catalog/model/checkout/order/addOrder/after
     * 
     * @param string $route
     * @param array $args
     * @param mixed $output
     * @return void
     */
    public function afterCheckoutConfirmConfirm(string $route, array &$args, &$output): void {

        $orderId = $this->session->data['order_id'] ?? null;

        if ($orderId && isset($this->session->data['shipping_method']['code']) && $this->session->data['shipping_method']['code'] === 'econt_delivery.econt_delivery') {
            
            $customerInfo = $this->session->data['econt_delivery']['customer_info'] ?? null;

            if ($customerInfo && $orderId > 0) {
                $this->db->query("
                    INSERT INTO `" . DB_PREFIX . "econt_delivery_customer_info`
                    SET id_order = " . (int)$orderId . ",
                        customer_info = '" . $this->db->escape(json_encode($customerInfo)) . "'
                    ON DUPLICATE KEY UPDATE
                        customer_info = VALUES(customer_info)
                ");
            }
        }
    }

    /**
     * Send order to Econt API after order confirmation
     * Event: catalog/model/checkout/addHistory/after
     * 
     * @param string $route
     * @param array $args
     * @return void
     */
    public function syncOrderWithEcont(string $route, array &$args): void {
        $orderId = $args[0];
        $order_status_id = $args[1];

        $this->load->model('checkout/order');
        $this->load->model('setting/setting');
        $this->load->model('extension/econt_delivery_oc4/shipping/econt_delivery');

        $orderData = $this->model_checkout_order->getOrder($orderId);

        if (empty($orderData) || $orderData['shipping_method']['code'] !== 'econt_delivery.econt_delivery') {
            return;
        }

        $settings = $this->model_setting_setting->getSetting('shipping_econt_delivery');

        if (empty($settings['shipping_econt_delivery_system_url']) || empty($settings['shipping_econt_delivery_private_key'])) {
            return;
        }

        $preparedOrderData = $this->model_extension_econt_delivery_oc4_shipping_econt_delivery->prepareOrder($orderId);

        if (!$preparedOrderData || empty($preparedOrderData['order'])) {
            return;
        }

        try {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $settings['shipping_econt_delivery_system_url'] . '/services/OrdersService.updateOrder.json');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: ' . $settings['shipping_econt_delivery_private_key']
            ]);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($preparedOrderData['order']));
            curl_setopt($curl, CURLOPT_TIMEOUT, 6);
            $response = curl_exec($curl);
            curl_close($curl);
        } catch (\Exception $e) {
            $this->log->write('Econt Delivery: Failed to sync order ' . $orderId . ' - ' . $e->getMessage());
            error_log('Econt Delivery: Failed to sync order ' . $orderId . ' - ' . $e->getMessage());
        }
    }

    /**
     * Load Econt checkout script template into checkout
     * Event: catalog/view/checkout/shipping_method/after
     * 
     * @param string $route
     * @param array $args
     * @param string $output
     * @return string
     */
    public function loadModal(string $route, array &$args, string &$output): void {
        $this->load->language('extension/econt_delivery_oc4/shipping/econt_delivery');

        $data = [
            'text_title' => $this->language->get('text_delivery_method_title'),
            'text_choose_address' => $this->language->get('text_choose_address'),
            'text_select_address_below' => $this->language->get('text_select_address_below'),
            'language' => $this->config->get('config_language')
        ];

        // Only load checkout script for inline iframe implementation
        // The separate modal template is no longer needed
        $checkoutScript = $this->load->view('extension/econt_delivery_oc4/shipping/econt_delivery_checkout_script', $data);
        
        $output .= $checkoutScript;
    }


    // ADMIN

    /**
     * Validate and prepare Econt data before order save
     * Events: catalog/model/checkout/order/addOrder/before, catalog/model/checkout/order/editOrder/before
     * 
     * @param string $route
     * @param array $args
     * @return void
     */
    public function beforeAdminOrderSave(string $route, array $args): void {

        if(isset($args[1])) {
            $orderData = &$args[1];
        } else {
            return;
        }

        // Check if Econt delivery is selected
        if (!isset($orderData['shipping_method']) || 
            (is_array($orderData['shipping_method']) && ($orderData['shipping_method']['code'] ?? '') !== 'econt_delivery.econt_delivery') ||
            (is_string($orderData['shipping_method']) && $orderData['shipping_method'] !== 'econt_delivery.econt_delivery')) {
            return;
        }
        
        // Get customer info from database instead of session
        $orderId = (int)($orderData['order_id'] ?? 0);
        $customerInfo = null;
        
        if ($orderId > 0) {
            // Existing order - query by id_order
            $result = $this->db->query("
                SELECT customer_info 
                FROM `" . DB_PREFIX . "econt_delivery_customer_info`
                WHERE id_order = " . (int)$orderId . "
                LIMIT 1
            ");
            
            if ($result->num_rows > 0) {
                $customerInfo = json_decode($result->row['customer_info'], true);
                $this->log->write('Econt Delivery: Retrieved customer info from DB for order #' . $orderId);
            }
        } else {
            // New order - get the most recent temp record (id_order = 0)
            $result = $this->db->query("
                SELECT customer_info 
                FROM `" . DB_PREFIX . "econt_delivery_customer_info`
                WHERE id_order = 0
                ORDER BY CAST(JSON_EXTRACT(customer_info, '$.temp_key') AS CHAR) DESC
                LIMIT 1
            ");
            
            if ($result->num_rows > 0) {
                $customerInfo = json_decode($result->row['customer_info'], true);
                $this->log->write('Econt Delivery: Retrieved temp customer info from DB');
            }
        }
        
        if ($customerInfo) {
            // Parse customer name
            $nameParts = explode(' ', trim($customerInfo['name']), 2);
            $firstname = $nameParts[0] ?? '';
            $lastname = $nameParts[1] ?? '';

            // Update order shipping address
            $orderData['shipping_firstname'] = $firstname;
            $orderData['shipping_lastname'] = $lastname;
            $orderData['shipping_company'] = $customerInfo['face'] ?? '';
            $orderData['shipping_city'] = $customerInfo['city_name'] ?? '';
            $orderData['shipping_postcode'] = $customerInfo['post_code'] ?? '';
            $orderData['shipping_country'] = $customerInfo['country_name'] ?? '';
            $orderData['shipping_zone'] = $customerInfo['region_name'] ?? '';

            if (!empty($customerInfo['office_code'])) {
                $orderData['shipping_address_1'] = 'Econt office: ' . ($customerInfo['office_name'] ?? '');
                $orderData['shipping_address_2'] = $customerInfo['address'] ?? '';
            } else {
                $orderData['shipping_address_1'] = $customerInfo['address'] ?? '';
                $orderData['shipping_address_2'] = '';
            }

            // Update contact info if available
            if (!empty($customerInfo['email'])) {
                $orderData['email'] = $customerInfo['email'];
            }
            if (!empty($customerInfo['phone'])) {
                $orderData['telephone'] = $customerInfo['phone'];
            }
            
            // Store customer info in order data for afterAdminOrderSave to use
            $orderData['_econt_customer_info'] = $customerInfo;
        } else {
            $this->log->write('Econt Delivery: No customer info found in DB for order #' . $orderId);
        }
    }

    /**
     * Save customer info to database after order save
     * Events: catalog/model/checkout/order/addOrder/after, catalog/model/checkout/order/editOrder/after
     * 
     * @param string $route
     * @param array $args
     * @param mixed $output
     * @return void
     */
    public function afterAdminOrderSave(string $route, array &$args, &$output): void {
        // Get order ID from output (for addOrder) or from args (for editOrder)
        $orderId = is_numeric($output) ? (int)$output : (int)($args[0] ?? 0);

        if ($orderId <= 0) {
            return;
        }

        // Check if this order uses Econt delivery
        $this->load->model('checkout/order');
        $orderData = $this->model_checkout_order->getOrder($orderId);

        if (empty($orderData) || 
            !isset($orderData['shipping_method']['code']) || 
            $orderData['shipping_method']['code'] !== 'econt_delivery.econt_delivery') {
            return;
        }

        // Get customer info from database
        // First check if temp record exists (for new orders)
        $result = $this->db->query("
            SELECT customer_info 
            FROM `" . DB_PREFIX . "econt_delivery_customer_info`
            WHERE id_order = 0
            ORDER BY CAST(JSON_EXTRACT(customer_info, '$.temp_key') AS CHAR) DESC
            LIMIT 1
        ");
        
        $customerInfo = null;
        $isTempRecord = false;
        
        if ($result->num_rows > 0) {
            $customerInfo = json_decode($result->row['customer_info'], true);
            $isTempRecord = true;
        } else {
            // No temp record, check if record already exists for this order
            $result = $this->db->query("
                SELECT customer_info 
                FROM `" . DB_PREFIX . "econt_delivery_customer_info`
                WHERE id_order = " . (int)$orderId . "
                LIMIT 1
            ");
            
            if ($result->num_rows > 0) {
                $customerInfo = json_decode($result->row['customer_info'], true);
            }
        }

        if ($customerInfo && $orderId > 0) {
            // Remove temp_key from customer info if present
            unset($customerInfo['temp_key']);
            
            // Save/update customer info with real order ID
            $this->db->query("
                INSERT INTO `" . DB_PREFIX . "econt_delivery_customer_info`
                SET id_order = " . (int)$orderId . ",
                    customer_info = '" . $this->db->escape(json_encode($customerInfo)) . "'
                ON DUPLICATE KEY UPDATE
                    customer_info = VALUES(customer_info)
            ");

            $this->log->write('Econt Delivery: Saved customer info to DB for order #' . $orderId);
            
            // Clean up temp record if this was a new order
            if ($isTempRecord) {
                $this->db->query("
                    DELETE FROM `" . DB_PREFIX . "econt_delivery_customer_info`
                    WHERE id_order = 0
                ");
                
                $this->log->write('Econt Delivery: Cleaned up temp customer info records');
            }
        } else {
            $this->log->write('Econt Delivery: No customer info found in DB for order #' . $orderId);
        }
    }
}
