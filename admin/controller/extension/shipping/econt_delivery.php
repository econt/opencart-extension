<?php

/** @noinspection PhpUndefinedClassInspection */

/**
 * @property Language $language
 * @property DB $db
 * @property Loader $load
 * @property ModelExtensionShippingEcontDelivery $model_extension_shipping_econt_delivery
 * @property Document $document
 * @property Response $response
 * @property Request $request
 * @property Url $url
 * @property Session $session
 * @property ModelLocalisationGeoZone $model_localisation_geo_zone
 * @property ModelSettingSetting $model_setting_setting
 * @property ModelSettingEvent $model_setting_event
 * @property \Cart\User $user
 * @property ModelAccountOrder $model_account_order
 * @property Config $config
 * @property ModelSaleOrder $model_sale_order
 * @property ControllerSaleOrder $controller_sale_order
 */
class ControllerExtensionShippingEcontDelivery extends Controller {

    private $error = array();

    private $systemUrls = array(
        'production' => 'https://delivery.econt.com',
        'testing' => 'http://delivery.demo.econt.com'
    );
    private $trackShipmentUrl = 'https://www.econt.com/services/track-shipment';

    public function index() {
        $this->language->load('extension/shipping/econt_delivery');

        $this->load->model('setting/setting');
        $this->load->model('localisation/geo_zone');

        if (isset($this->request->post['action']) && $this->request->post['action'] === 'save_settings' && $this->validate()) {
            $this->model_setting_setting->editSetting('shipping_econt_delivery', $this->request->post);

            $this->session->data['success'] = $this->language->get('text_success_setting_update');
            $this->response->redirect($this->url->link('marketplace/extension', http_build_query(array(
                'user_token' => $this->session->data['user_token'],
                'type' => 'shipping'
            )), true));
        }

        $this->document->setTitle($this->language->get('heading_title'));
        $this->response->setOutput($this->load->view('extension/shipping/econt_delivery', array(
            'header' => $this->load->controller('common/header'),
            'left_menu' => $this->load->controller('common/column_left'),
            'breadcrumbs' => array(array(
                'text' => $this->language->get('text_home'),
                'href' => $this->url->link('common/dashboard', http_build_query(array(
                    'user_token' => $this->session->data['user_token']
                )), true)
            ), array(
                'text' => $this->language->get('text_extensions'),
                'href' => $this->url->link('marketplace/extension', http_build_query(array(
                    'user_token' => $this->session->data['user_token'],
                    'type' => 'shipping'
                )), true)
            ), array(
                'text' => $this->language->get('heading_title'),
                'href' => $this->url->link('extension/shipping/econt_delivery', http_build_query(array(
                    'user_token' => $this->session->data['user_token']
                )), true)
            )),
            'geo_zones' => $this->model_localisation_geo_zone->getGeoZones(),
            'settings' => $this->model_setting_setting->getSetting('shipping_econt_delivery'),
            'system_urls' => $this->systemUrls,
            'actions' => array(
                'submit_url' => $this->url->link('extension/shipping/econt_delivery', http_build_query(array(
                    'user_token' => $this->session->data['user_token']
                )), true),
                'cancel_url' => $this->url->link('marketplace/extension', http_build_query(array(
                    'user_token' => $this->session->data['user_token'],
                    'type' => 'shipping'
                )), true)
            ),
            'footer' => $this->load->controller('common/footer')
        )));
    }

    public function validate() {
        if (!$this->user->hasPermission('modify', 'extension/shipping/econt_delivery')) $this->error['warning'] = $this->language->get('error_permission');

        return empty($this->error);
    }

    public function install() {
        $this->load->model('setting/event');

        $this->db->query(sprintf("
            CREATE TABLE IF NOT EXISTS `%s`.`%secont_delivery_customer_info` (
                `id_order` INT(11) NOT NULL DEFAULT '0',
                `customer_info` MEDIUMTEXT NULL,
                PRIMARY KEY (`id_order`)
            )
            COLLATE = 'utf8_general_ci'
            ENGINE = InnoDB
        ",
            DB_DATABASE,
            DB_PREFIX
        ));

        $this->model_setting_event->addEvent('econt_delivery', 'catalog/controller/checkout/payment_method/save/before', 'extension/shipping/econt_delivery/beforeCartSavePayment');
        $this->model_setting_event->addEvent('econt_delivery', 'catalog/controller/checkout/shipping_method/save/before', 'extension/shipping/econt_delivery/beforeCartSaveShipping');
        $this->model_setting_event->addEvent('econt_delivery', 'catalog/view/checkout/guest/after', 'extension/shipping/econt_delivery/afterViewCheckoutBilling');
        $this->model_setting_event->addEvent('econt_delivery', 'catalog/view/checkout/register/after', 'extension/shipping/econt_delivery/afterViewCheckoutBilling');
        $this->model_setting_event->addEvent('econt_delivery', 'catalog/controller/checkout/confirm/after', 'extension/shipping/econt_delivery/afterCheckoutConfirm');

        $this->model_setting_event->addEvent('econt_delivery', 'catalog/controller/api/*/before', 'extension/shipping/econt_delivery/loadEcontDeliveryData');
        $this->model_setting_event->addEvent('econt_delivery', 'catalog/controller/api/shipping/econt_delivery_beforeApi/before', 'extension/shipping/econt_delivery/beforeApi');
        $this->model_setting_event->addEvent('econt_delivery', 'catalog/controller/api/shipping/econt_delivery_getCustomerInfoParams/before', 'extension/shipping/econt_delivery/getCustomerInfoParams');

        $this->model_setting_event->addEvent('econt_delivery', 'admin/view/sale/order_info/before', 'extension/shipping/econt_delivery/beforeAdminViewSaleOrderInfo');
        $this->model_setting_event->addEvent('econt_delivery', 'admin/view/sale/order_form/before', 'extension/shipping/econt_delivery/beforeAdminViewSaleOrderFrom');
        $this->model_setting_event->addEvent('econt_delivery', 'catalog/model/checkout/order/addOrderHistory/after', 'extension/shipping/econt_delivery/afterModelCheckoutOrderAddHistory');
        $this->model_setting_event->addEvent('econt_delivery', 'admin/model/sale/order/getOrder/after', 'extension/shipping/econt_delivery/afterAdminModelSaleOrderGetOrder');
    }
    public function uninstall() {
        $this->load->model('setting/event');

        $this->model_setting_event->deleteEventByCode('econt_delivery');
    }

    private function traceShipment($orderId) {
        $orderId = intval($orderId);
        if ($orderId <= 0) return array();

        $this->load->model('setting/setting');
        $settings = $this->model_setting_setting->getSetting('shipping_econt_delivery');

        try {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, "{$settings['shipping_econt_delivery_system_url']}/services/OrdersService.getTrace.json");
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                "Authorization: {$settings['shipping_econt_delivery_private_key']}"
            ]);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(array(
                'orderNumber' => $orderId
            )));
            curl_setopt($curl, CURLOPT_TIMEOUT, 6);
            $response = json_decode(curl_exec($curl), true);
            $responseInfo = curl_getinfo($curl);
            if ($responseInfo['http_code'] !== 200) $response['error'] = $response;
            curl_close($curl);
        } catch (Exception $exception) {
            $response['error'] = $exception;
        }

        return $response;
    }

    public function beforeAdminViewSaleOrderFrom(/** @noinspection PhpUnusedParameterInspection */ $eventRoute, &$data) {
        $this->language->load('extension/shipping/econt_delivery');

        $this->load->model('setting/setting');

        $econtDeliverySettings = $this->model_setting_setting->getSetting('shipping_econt_delivery');

        ob_start(); ?>
            <style>
                #econt-delivery-customer-info-modal iframe {
                    border: 0;
                    width: 100%;
                    height: 608px;
                }
            </style>
            <div id="econt-delivery-customer-info-modal" class="modal fade" role="dialog">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                            <h4 class="modal-title"><?=$this->language->get('heading_title')?></h4>
                        </div>
                        <div class="modal-body">
                            <iframe src="about:blank"></iframe>
                        </div>
                    </div>
                </div>
            </div>
            <script>
                window.econtDelivery = window.econtDelivery || {
                    empty: function(thingy) {
                        return thingy == 0 || !thingy || (typeof(thingy) === 'object' && $.isEmptyObject(thingy));
                    },

                    systemUrl: '<?=$econtDeliverySettings['shipping_econt_delivery_system_url']?>',
                    orderId: <?=json_encode($data['order_id'])?>,
                    customerInfo: {}
                };
                $(function($) {
                    var $shippingMethod = $('#input-shipping-method');
                    var $customerInfoWindow = $('#econt-delivery-customer-info-modal').modal({
                        'show': false,
                        'backdrop': 'static'
                    });

                    var $customerInfoLink = $('<a></a>').attr({'href': '#', 'target': '_self'}).text('<?=$this->language->get('text_edit_customer_info')?>');
                    $customerInfoLink.show = function() { $customerInfoLink.css({'display': 'inline-block'}); }
                    $customerInfoLink.hide = function() { $customerInfoLink.css({'display': 'none'}); }
                    $customerInfoLink.hide();
                    $((((function($parent) { return ((parseInt($parent.length, 10) || 0) <= 0 ? false : $parent); })($shippingMethod.parents('.input-group')) || $shippingMethod).parent())).append($customerInfoLink);
                    $customerInfoLink.click(function(event) {
                        event.preventDefault();

                        $.post('<?=HTTP_CATALOG?>index.php?<?=http_build_query(array(
                            'route' => 'api/shipping/econt_delivery_getCustomerInfoParams',
                            'api_token' => $data['api_token'],
                            'order_id' => $data['order_id']
                        ))?>', {}, function(response) {
                            if (!window.econtDelivery.empty(response['error'])) {
                                alert(response['error']);
                                return;
                            }

                            if (window.econtDelivery.empty(response['customer_info_url'])) {
                                alert('<?=$this->language->get('text_empty_customer_info_url')?>');
                                return;
                            }

                            $customerInfoWindow.find('iframe').attr('src', response['customer_info_url']);
                            $customerInfoWindow.modal('show');
                        }, 'json').fail(function(xhr, textStatus, errorThrown) {
                            alert('<?=$this->language->get('text_default_error_message')?>');
                            console.error(errorThrown);
                        });
                    });
                    $(window).on('message', function(event){
                        if (event['originalEvent']['origin'] != window.econtDelivery.systemUrl) return;

                        var messageData = event['originalEvent']['data'];
                        if (!messageData) return;

                        var withShippingError = false;
                        if (!window.econtDelivery.empty(messageData['shipment_error'])) {
                            withShippingError = true;
                            alert(messageData['shipment_error']);
                        }

                        $.post('<?=HTTP_CATALOG?>index.php?<?=http_build_query(array(
                            'route' => 'api/shipping/econt_delivery_beforeApi',
                            'api_token' => $data['api_token'],
                            'order_id' => $data['order_id'],
                            'action' => 'updateCustomerInfo'
                        ))?>', messageData, function(response) {
                            if (!withShippingError) $customerInfoWindow.modal('hide');
                            window.econtDelivery.customerInfo = (response['customer_info']);
                        }, 'json');
                    });

                    if ($shippingMethod.val() === 'econt_delivery.econt_delivery') $customerInfoLink.show();
                    else $customerInfoLink.hide();

                    $shippingMethod.change(function() {
                        if ($(this).val() !== 'econt_delivery.econt_delivery') $customerInfoLink.hide();
                        else {
                            $customerInfoLink.show();
                            if (window.econtDelivery.empty(window.econtDelivery.customerInfo)) $customerInfoLink.click();
                        }
                    });

                    var loadCustomerInfo = function(showWindow) {
                        $.post('<?=HTTP_CATALOG?>index.php?<?=http_build_query(array(
                            'route' => 'api/shipping/econt_delivery_beforeApi',
                            'api_token' => $data['api_token'],
                            'order_id' => $data['order_id']
                        ))?>', {}, function(response) {
                            window.econtDelivery.customerInfo = response;
                            if (showWindow && showWindow === true && window.econtDelivery.empty(window.econtDelivery.customerInfo)) {
                                $shippingMethod.change();
                            }
                        }, 'json');
                    }
                    $('#button-shipping-address').click(function() {
                        loadCustomerInfo(true);
                    });
                    if (window.econtDelivery.empty(window.econtDelivery.customerInfo)) loadCustomerInfo(false)
                });
            </script>
        <?php $data['footer'] = str_replace('</body>', sprintf('%s</body>', ob_get_contents()), $data['footer']);
        ob_end_clean();
    }
    public function beforeAdminViewSaleOrderInfo(/** @noinspection PhpUnusedParameterInspection */ $eventRoute, &$data) {
        $orderId = intval($this->request->get['order_id']);
        if ($orderId <= 0) return;

        $this->load->model('sale/order');
        $orderData = $this->model_sale_order->getOrder($orderId);

        if ($orderData['shipping_code'] !== 'econt_delivery.econt_delivery') return;

        $this->language->load('extension/shipping/econt_delivery');

        $data['shipping_method'] = $this->language->get('text_shipping_via_econt');
        $shipment = $this->traceShipment($orderId);
        if (!empty($shipment['shipmentNumber'])) {
            $data['shipping_method'] .= sprintf(' - №<a href="%s" target="_blank" data-toggle="tooltip" data-original-title="%s">%s</a>',
                "{$this->trackShipmentUrl}/{$shipment['shipmentNumber']}",
                $this->language->get('text_trace_shipping'),
                $shipment['shipmentNumber']
            );
            if (!empty($shipment['pdfURL'])) $data['shipping'] = $shipment['pdfURL'];
        } else {
            $this->load->model('setting/setting');
            $econtDeliverySettings = $this->model_setting_setting->getSetting('shipping_econt_delivery');

            $data['shipping'] = '#open_econt_delivery_create_label_window';
            ob_start(); ?>
                <style>
                    #econt-delivery-create-label-modal .modal-dialog {
                        width: 520px;
                    }
                    #econt-delivery-create-label-modal .modal-body {
                        padding: 0;
                    }
                    #econt-delivery-create-label-modal iframe {
                        border: 0;
                        width: 100%;
                        height: 280px;
                    }
                </style>
                <div id="econt-delivery-create-label-modal" class="modal fade" role="dialog">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                                <h4 class="modal-title"><?=$this->language->get('heading_title')?></h4>
                            </div>
                            <div class="modal-body">
                                <iframe src="about:blank"></iframe>
                            </div>
                        </div>
                    </div>
                </div>
                <script>
                    window.econtDelivery = window.econtDelivery || {
                        empty: function(thingy) {
                            return thingy == 0 || !thingy || (typeof(thingy) === 'object' && $.isEmptyObject(thingy));
                        },

                        orderId: <?=json_encode($orderId)?>
                    };
                    $(function($) {
                        var $createLabelWindow = $('#econt-delivery-create-label-modal').modal({
                            'show': false,
                            'backdrop': 'static'
                        });
                        $('a[href="#open_econt_delivery_create_label_window"]').click(function(event) {
                            event.preventDefault();
                            event.stopPropagation();

                            $createLabelWindow.find('iframe').attr('src', '<?=$econtDeliverySettings['shipping_econt_delivery_system_url'] . '/create_label.php?'. http_build_query(array(
                                'order_number' => $orderId,
                                'token' => $econtDeliverySettings['shipping_econt_delivery_private_key']
                            ))?>');
                            $createLabelWindow.modal('show');
                        });
                        $(window).on('message', function(event){
                            if (event['originalEvent']['origin'] != '<?=$econtDeliverySettings['shipping_econt_delivery_system_url']?>') return;

                            var messageData = event['originalEvent']['data'];
                            if (!messageData) return;

                            switch (messageData['event']) {
                                case 'cancel':
                                    $createLabelWindow.modal('hide');
                                    break;
                                case 'confirm':
                                    if (messageData['printPdf'] === true && !window.econtDelivery.empty(messageData['shipmentStatus']['pdfURL'])) window.open(messageData['shipmentStatus']['pdfURL'], '_blank');
                                    window.location.href = window.location.href;
                                    break;
                            }
                        });
                    });
                </script>
            <?php $data['footer'] = str_replace('</body>', sprintf('%s</body>', ob_get_contents()), $data['footer']);
            ob_end_clean();
        }
    }
    public function afterAdminModelSaleOrderGetOrder(/** @noinspection PhpUnusedParameterInspection */ &$eventRoute, &$data, &$returnData) {
        $orderId = $returnData['order_id'];
        if ($orderId <= 0) return;

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
        $customerInfo = @json_decode($customerInfo->row['customerInfo'], true);
        if (!$customerInfo) return;

        if (
                $returnData['shipping_code'] === 'econt_delivery.econt_delivery'
            &&  (
                    @empty($returnData['shipping_firstname'])
                ||  @empty($returnData['shipping_lastname'])
            )
            &&  @!empty($customerInfo)
        ) {
            $shippingName = '';
            if (@!empty($customerInfo['name']) && @!empty($customerInfo['face'])) {
                $shippingName = $customerInfo['face'];
            } elseif (@!empty($customerInfo['name']) && @empty($customerInfo['face'])) {
                $shippingName = $customerInfo['name'];
            } elseif (@empty($customerInfo['name']) && @!empty($customerInfo['face'])) {
                $shippingName = $customerInfo['face'];
            }
            if (!empty($shippingName)) {
                $shippingNameParts = explode(' ', trim($shippingName));
                if (!empty($shippingNameParts)) {
                    $returnData['shipping_firstname'] = reset($shippingNameParts);
                    $returnData['shipping_lastname'] = end($shippingNameParts);
                }
            }
        }
    }

}