<?php

/** @noinspection PhpUnused */

/**
 * class ModelExtensionPaymentEcontPayment
 *
 * @property DB $db
 * @property Session $session
 * @property Config $config
 * @property \Cart\Cart $cart
 * @property Loader $load
 * @property Language $language
 * @property ControllerCommonLanguage $a;
 */
class ModelExtensionPaymentEcontPayment extends Model {

	public function getMethod($address, $total) {
		$this->load->language('extension/payment/econt_payment');

        $DBName = DB_DATABASE;
        $DBTablePrefix = DB_PREFIX;

		if (
		        is_array($this->session->data)
            &&  array_key_exists('shipping_method', $this->session->data)
            &&  mb_strtolower($this->session->data['shipping_method']['code']) !== 'econt_delivery.econt_delivery'
        ) return array();

		$econtPaymentTotal = round($this->config->get('payment_econt_payment_total'), 2);
        if ($econtPaymentTotal > 0 && $econtPaymentTotal > round($total, 2)) return array();

        if (!$this->cart->hasShipping()) return array();

        $econtGEOZoneID = intval($this->config->get('payment_econt_payment_geo_zone_id'));
        if ($econtGEOZoneID > 0) {
            $countryID = intval($address['country_id']);
            $zoneID = intval($address['zone_id']);

            $queryResult = $this->db->query("
                SELECT
                    z.*
                FROM {$DBName}.{$DBTablePrefix}zone_to_geo_zone AS z
                WHERE TRUE
                    AND z.geo_zone_id = {$econtGEOZoneID}
                    AND z.country_id = {$countryID}
                    AND z.zone_id IN (0, {$zoneID})
            ");
            if (intval($queryResult->num_rows) <= 0) return array();
        }

        $methodDataTitle = $this->language->get('heading_title');
        $terms = $this->language->get('heading_title_2');

        $languagesQueryResult = $this->db->query(sprintf("
            SELECT
                l.language_id AS id
            FROM {$DBName}.{$DBTablePrefix}language AS l
            WHERE TRUE
                AND l.code = '%s'
            LIMIT 1
        ", $this->db->escape($this->session->data['language'])));
        $languageID = @intval($languagesQueryResult->row['id']);
        if ($languageID > 0) {
            $methodDataTitle = trim($this->config->get("payment_econt_payment_title_lang_{$languageID}"));
            ob_start(); ?>
                <?php $paymentLogo = trim($this->config->get("payment_econt_payment_logo_lang_{$languageID}")); ?>
                <?php if (!empty($paymentLogo)): ?>
                    <br>
                    <img src="<?php echo "/catalog/view/theme/default/image/econt_payment_logo_{$paymentLogo}.png"; ?>" alt="<?php echo $methodDataTitle; ?> Logo" style="margin-bottom: 15px;">
                    <br>
                <?php endif; ?>
            <?php echo trim($this->config->get("payment_econt_payment_description_lang_{$languageID}")); ?>
            <?php $terms = ob_get_contents();
            ob_end_clean();
        }

        return array(
            'code' => 'econt_payment',
            'title' => $methodDataTitle,
            'terms' => trim($terms),
            'sort_order' => intval($this->config->get('payment_econt_payment_sort_order'))
        );
	}

}
