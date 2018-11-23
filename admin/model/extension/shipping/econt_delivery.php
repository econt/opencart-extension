<?php

/** @noinspection PhpUndefinedClassInspection */

/**
 * @property Session $session
 */
class ModelExtensionShippingEcontDelivery extends Model {

    public function getCustomerInfoParams() {
        return $this->session->data['shipping_address'];
    }

}