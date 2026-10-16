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
namespace Opencart\Catalog\Model\Extension\EcontDeliveryOc4\Payment;
class EcontPayment extends \Opencart\System\Engine\Model {

	public function getMethod($address) {
		$this->load->language('extension/econt_delivery_oc4/payment/econt_delivery');

        $DBName = DB_DATABASE;
        $DBTablePrefix = DB_PREFIX;

		if (
		        is_array($this->session->data)
            &&  array_key_exists('shipping_method', $this->session->data)
            &&  mb_strtolower($this->session->data['shipping_method']) !== 'econt_delivery.econt_delivery'
        ) return array();

		//$econtPaymentTotal = round($this->config->get('payment_econt_payment_total'), 2);
        //if ($econtPaymentTotal > 0 && $econtPaymentTotal > round($total, 2)) return array();

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

        $methodDataTitle = "EcontPay";
        $terms = $this->language->get('heading_title_2');

        $languagesQueryResult = $this->db->query(sprintf("
            SELECT
                l.language_id AS id
            FROM `{$DBName}`.`{$DBTablePrefix}language` AS l
            WHERE TRUE
                AND l.code = '%s'
            LIMIT 1
        ", $this->db->escape($this->config->get('language_code'))));
        $languageID = @intval($languagesQueryResult->row['id']);
        if ($languageID > 0) {
            ob_start(); ?>
            <br>
            <img src="<?php echo "extension/econt_delivery_oc4/catalog/view/image/payment/econt_pay_logo.svg"; ?>" alt="<?php echo $methodDataTitle; ?> Logo" style="margin-bottom: 15px;">
            <br>
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
