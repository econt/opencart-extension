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

        if ($this->session->data['shipping_method']['code'] != 'econt_delivery.econt_delivery') {
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
		?>
<!--        @todo fix this to submit the ajax if recei ve success from econt payment service-->
        <script>
            (function ($) {
                window.econtPaymentSuccess = false;
                var $continue_button = $('#button-payment-method');
                $continue_button.click(function (e) {
                    if (!window.econtPaymentSuccess) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                    var win = window.open('https://abv.bg/', '_blank');
                    // win.focus();

                    return
                    $.ajax({
                        url: 'index.php?route=checkout/payment_method/save',
                        type: 'post',
                        data: $('#collapse-payment-method input[type=\'radio\']:checked, #collapse-payment-method input[type=\'checkbox\']:checked, #collapse-payment-method textarea'),
                        dataType: 'json',
                        beforeSend: function() {
                            $('#button-payment-method').button('loading');
                        },
                        success: function(json) {
                            $('.alert-dismissible, .text-danger').remove();

                            if (json['redirect']) {
                                location = json['redirect'];
                            } else if (json['error']) {
                                $('#button-payment-method').button('reset');

                                if (json['error']['warning']) {
                                    $('#collapse-payment-method .panel-body').prepend('<div class="alert alert-danger alert-dismissible">' + json['error']['warning'] + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
                                }
                            } else {
                                $.ajax({
                                    url: 'index.php?route=checkout/confirm',
                                    dataType: 'html',
                                    complete: function() {
                                        $('#button-payment-method').button('reset');
                                    },
                                    success: function(html) {
                                        $('#collapse-checkout-confirm .panel-body').html(html);

                                        $('#collapse-checkout-confirm').parent().find('.panel-heading .panel-title').html('<a href="#collapse-checkout-confirm" data-toggle="collapse" data-parent="#accordion" class="accordion-toggle">Step 6: Confirm Order <i class="fa fa-caret-down"></i></a>');

                                        $('a[href=\'#collapse-checkout-confirm\']').trigger('click');
                                    },
                                    error: function(xhr, ajaxOptions, thrownError) {
                                        alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
                                    }
                                });
                            }
                        },
                        error: function(xhr, ajaxOptions, thrownError) {
                            alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
                        }
                    });
                })
                setTimeout(function () {
                    window.econtPaymentSuccess = true;
                    $('#button-payment-method').click()
                }, 1500)
            }) (jQuery);
        </script>
        <?php

		return $method_data;
	}
}
