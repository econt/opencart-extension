<?php

/** @noinspection PhpUndefinedClassInspection */

/**
 * @property Loader $load
 * @property DB $db
 * @property Config $config
 * @property Language $language
 */
class ModelExtensionShippingEcontDelivery extends Model {

    public function getQuote($address) {
        $geoZoneId = intval($this->config->get('shipping_econt_delivery_geo_zone_id'));
        if ($geoZoneId !== 0) {
            $result = $this->db->query(sprintf("
                SELECT
                    COUNT(z.zone_to_geo_zone_id) AS zoneIdsCount
                FROM %s.%szone_to_geo_zone AS z
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
        return array(
            'code' => 'econt_delivery',
            'title' => $this->language->get('delivery_method_title'),
            'quote' => array(
                'econt_delivery' => array(
                    'code' => 'econt_delivery.econt_delivery',
                    'title' => $this->language->get('delivery_method_description'),
                    'cost' => 0,
                    'tax_class_id' => 0,
                    'text' => $this->language->get('delivery_method_description_services')
                )
            ),
            'sort_order' => intval($this->config->get('shipping_econt_delivery_sort_order')),
            'error' => false
        );
    }

}