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

		if ($status) {
			$method_data = array(
				'code'       => 'econt_payment',
				'title'      => $this->language->get('text_title'),
				'terms'      => '',
				'sort_order' => $this->config->get('payment_econt_payment_sort_order')
			);
		}

//

		return $method_data;
	}
}
