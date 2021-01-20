<?php
class ModelExtensionPaymentEcontPayment extends Model {

	public function getMethod($address, $total) {
		$this->load->language('extension/payment/econt_payment');

		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('payment_econt_payment_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");

		if ($this->config->get('payment_econt_payment_total') > 0 && $this->config->get('payment_econt_payment_total') > $total) {
			$status = false;
		} elseif (!$this->cart->hasShipping()) {
			$status = false;
		} elseif (!$this->config->get('payment_econt_payment_geo_zone_id')) {
			$status = true;
		} elseif ($query->num_rows) {
			$status = true;
		} else {
			$status = false;
		}

		parse_str(explode('?', $_SERVER["HTTP_REFERER"])[1], $route);

        if (array_key_exists('shipping_method', $this->session->data) && $this->session->data['shipping_method']['code'] != 'econt_delivery.econt_delivery') {
            $status = false;
        } elseif (count($route) && $route['route'] === 'sale/order/edit') {
            $status = false;
        }

		$method_data = array();

        ob_start() ;?>
            <?php $paymentLogo = trim($this->config->get('payment_econt_payment_logo')); ?>
            <?php if (!empty($paymentLogo)): ?>
                <br>
                <img src="<?php echo "/catalog/view/theme/default/image/econt_payment_logo_{$paymentLogo}.png"; ?>" alt="Econt Delivery Payment Logo" style="margin-bottom: 15px;">
                <br>
            <?php endif; ?>
            <?php echo trim($this->config->get('payment_econt_payment_description')); ?>
            <?php $terms = ob_get_contents();
        ob_end_clean();

		if ($status) {
			$method_data = array(
				'code'       => 'econt_payment',
				'title'      => trim($this->config->get('payment_econt_payment_title')),
				'terms'      => trim($terms),
				'sort_order' => $this->config->get('payment_econt_payment_sort_order')
			);
		}

		return $method_data;
	}

    public function updateOrder($orderId, $token = '')
    {
        if (isset($this->session->data['econt_payment_paymentToken'])) {
            unset($this->session->data['econt_payment_paymentToken']);
        }

        return $this->db->query(sprintf("
                    INSERT INTO `%s`.`%secont_delivery_customer_info`
                    SET id_order = {$orderId},
                        customer_info = '%s',
                        payment_token = '{$token}'
                    ON DUPLICATE KEY UPDATE
                        customer_info = VALUES(customer_info)
                ",
            DB_DATABASE,
            DB_PREFIX,
            $this->db->escape(json_encode($this->session->data['econt_delivery']['customer_info']))
        ));

//        return $this->db->query(sprintf("
//                    UPDATE `%s`.`%secont_delivery_customer_info`
//                    SET payment_token = '{$token}'
//                    WHERE id_order = {$orderId}
//                ",
//            DB_DATABASE,
//            DB_PREFIX
//        ));
	}
}
