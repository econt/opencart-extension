<?php

/** @noinspection PhpUnused */
/** @noinspection PhpUnusedParameterInspection */

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
        'testing' => 'https://delivery-demo.econt.com'
    );
    private $trackShipmentUrl = 'https://www.econt.com/services/track-shipment';

    public function install() {
        $this->load->model('setting/event');

        $this->db->query(sprintf("
            CREATE TABLE IF NOT EXISTS `%s`.`%secont_delivery_customer_info` (
                `id_order` INT(11) NOT NULL DEFAULT '0',
                `customer_info` MEDIUMTEXT NULL,
                `shipment_number` BIGINT(20) UNSIGNED NULL DEFAULT NULL,
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
        /*OneStepCheckout*/
        $this->model_setting_event->addEvent('econt_delivery', 'catalog/controller/extension/quickcheckout/payment_method/validate/before', 'extension/shipping/econt_delivery/beforeCartSavePayment');
        $this->model_setting_event->addEvent('econt_delivery', 'catalog/controller/extension/quickcheckout/shipping_method/validate/before', 'extension/shipping/econt_delivery/beforeCartSaveShipping');
        $this->model_setting_event->addEvent('econt_delivery', 'catalog/controller/extension/quickcheckout/cart/before', 'extension/shipping/econt_delivery/updateShippingPrice');
        $this->model_setting_event->addEvent('econt_delivery', 'catalog/controller/extension/payment/cod/confirm/before', 'extension/shipping/econt_delivery/afterCheckoutConfirm');
//        end
        $this->model_setting_event->addEvent('econt_delivery', 'catalog/view/checkout/guest/after', 'extension/shipping/econt_delivery/afterViewCheckoutBilling');
        $this->model_setting_event->addEvent('econt_delivery', 'catalog/view/checkout/register/after', 'extension/shipping/econt_delivery/afterViewCheckoutBilling');
        $this->model_setting_event->addEvent('econt_delivery', 'catalog/controller/checkout/confirm/after', 'extension/shipping/econt_delivery/afterCheckoutConfirm');

        $this->model_setting_event->addEvent('econt_delivery', 'catalog/controller/api/*/before', 'extension/shipping/econt_delivery/loadEcontDeliveryData');
        $this->model_setting_event->addEvent('econt_delivery', 'catalog/controller/api/shipping/econt_delivery_beforeApi/before', 'extension/shipping/econt_delivery/beforeApi');
        $this->model_setting_event->addEvent('econt_delivery', 'catalog/controller/api/shipping/econt_delivery_getCustomerInfoParams/before', 'extension/shipping/econt_delivery/getCustomerInfoParams');

        $this->model_setting_event->addEvent('econt_delivery', 'admin/view/sale/order_list/before', 'extension/shipping/econt_delivery/beforeAdminViewSaleOrderList');
        $this->model_setting_event->addEvent('econt_delivery', 'admin/view/sale/order_info/before', 'extension/shipping/econt_delivery/beforeAdminViewSaleOrderInfo');
        $this->model_setting_event->addEvent('econt_delivery', 'admin/view/sale/order_form/before', 'extension/shipping/econt_delivery/beforeAdminViewSaleOrderFrom');
        $this->model_setting_event->addEvent('econt_delivery', 'catalog/model/checkout/order/addOrderHistory/after', 'extension/shipping/econt_delivery/afterModelCheckoutOrderAddHistory');
        $this->model_setting_event->addEvent('econt_delivery', 'admin/model/sale/order/getOrder/after', 'extension/shipping/econt_delivery/afterAdminModelSaleOrderGetOrder');
    }
    public function uninstall() {
        $this->load->model('setting/event');

        $this->model_setting_event->deleteEventByCode('econt_delivery');
    }

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

    private function printEcontDeliveryCreateLabelWindow($eventRoute, $data) { ?>
        <?php
            $this->load->model('setting/setting');
            $econtDeliverySettings = $this->model_setting_setting->getSetting('shipping_econt_delivery');

            $this->language->load('extension/shipping/econt_delivery');

            $orderId = intval(@$_GET['order_id']);
            if ($orderId <= 0) $orderId = null;
        ?>
        <style>
            #econt-delivery-create-label-modal .modal-dialog {
                width: 96%;
            }
            #econt-delivery-create-label-modal .modal-body {
                padding: 0;
            }
            #econt-delivery-create-label-modal iframe {
                border: 0;
                width: 100%;
                height: 87vh;
            }

            @media screen and (min-width: 800px) {
                #econt-delivery-create-label-modal .modal-dialog {
                    width: 700px;
                }
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
            $(function($) {
                var empty__ = function(thingy) {
                    return thingy == 0 || !thingy || (typeof(thingy) === 'object' && $.isEmptyObject(thingy));
                }
                var $createLabelWindow = $('#econt-delivery-create-label-modal').modal({
                    'show': false,
                    'backdrop': 'static'
                });
                $('[href="#open_econt_delivery_create_label_window"]').click(function(event) {
                    event.preventDefault();
                    event.stopPropagation();

                    $createLabelWindow.find('iframe').attr('src', '<?=$econtDeliverySettings['shipping_econt_delivery_system_url'] . '/create_label.php?'?>' + $.param({
                        'order_number': (<?=json_encode($orderId)?> || $(this).attr('data-order-id')),
                        'token': '<?=$econtDeliverySettings['shipping_econt_delivery_private_key']?>'
                    }));
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
                            if (messageData['printPdf'] === true && !empty__(messageData['shipmentStatus']['pdfURL'])) window.open(messageData['shipmentStatus']['pdfURL'], '_blank');
                            setTimeout(function() {
                                window.location.href = 'index.php?' + $.param({
                                    'route': 'sale/order/info',
                                    'user_token': '<?=$data['user_token']?>',
                                    'order_id': messageData['orderData']['num']
                                });
                            }, 300);
                            break;
                    }
                });
            });
        </script>
    <?php }

    public function beforeAdminViewSaleOrderList(&$eventRoute, &$data) {
        if (empty($data['orders'])) return;

        $this->language->load('extension/shipping/econt_delivery');

        $orderIds = array();
        $orderData = array();
        foreach ($data['orders'] as $order) {
            $orderId = intval($order['order_id']);
            $orderData[$orderId] = array(
                'id' => $orderId,
                'shippingCode' => $order['shipping_code']
            );
            if ($orderId > 0 || $order['shipping_code'] === 'econt_delivery.econt_delivery') $orderIds[$orderId] = $orderId;
        }
        if (!empty($orderIds)) {
            $queryResult = $this->db->query(sprintf("
                SELECT
                    ci.id_order AS orderId,
                    ci.shipment_number AS shipmentNum
                FROM `%s`.`%secont_delivery_customer_info` AS ci
                WHERE TRUE
                    AND ci.id_order IN (%s)
                    AND (
                            ci.shipment_number != 0
                        AND ci.shipment_number IS NOT NULL
                    )
                GROUP BY ci.id_order
            ",
                DB_DATABASE,
                DB_PREFIX,
                implode(', ', $orderIds)
            ));
            foreach ($queryResult->rows as $row) $orderData[$row['orderId']]['shipmentNum'] = $row['shipmentNum'];
        }

        ob_start(); ?>
            <script>
                window.econtDelivery = {
                    'orderData': <?=json_encode($orderData)?>
                };
                $(function($) {
                    var orderListTable = $('#form-order table');
                    orderListTable.find('thead tr td:last-child').before(($('<td></td>').text('<?=$this->language->get('text_order_list_econt_shipping_column_label');?>')));
                    orderListTable.find('tbody tr').each(function(rowIndex, row) {
                        var $row = $(row)
                        var orderId = $row.find('[name^="selected"]').val();

                        var $wayBillContent = null;
                        if (window.econtDelivery['orderData'] && window.econtDelivery['orderData'][orderId] && window.econtDelivery['orderData'][orderId]['shippingCode'] === 'econt_delivery.econt_delivery') {
                            $wayBillContent = $('<a></a>');
                            if (window.econtDelivery['orderData'][orderId]['shipmentNum']) {
                                $wayBillContent.attr({
                                    'href': '<?=$this->trackShipmentUrl?>/' + window.econtDelivery['orderData'][orderId]['shipmentNum'],
                                    'target': '_blank'
                                }).text(window.econtDelivery['orderData'][orderId]['shipmentNum']);
                            } else {
                                $wayBillContent.attr({
                                    'href': '#open_econt_delivery_create_label_window',
                                    'target': '_self',
                                    'data-order-id': orderId
                                }).text('<?=$this->language->get('text_order_list_econt_shipping_column_prepare_loading')?>');
                            }
                        }
                        $row.find('td:last-child').before(($('<td></td>').css({'text-align': 'center'}).append($wayBillContent)));
                    });
                });
            </script>
            <?php $this->printEcontDeliveryCreateLabelWindow($eventRoute, $data) ?>
        <?php $data['footer'] = str_replace('</body>', sprintf('%s</body>', ob_get_contents()), $data['footer']);
        ob_end_clean();
    }
    public function beforeAdminViewSaleOrderFrom($eventRoute, &$data) {
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
    public function beforeAdminViewSaleOrderInfo(&$eventRoute, &$data) {
        $orderId = intval($this->request->get['order_id']);
        if ($orderId <= 0) return;

        $this->load->model('sale/order');
        $orderData = $this->model_sale_order->getOrder($orderId);

        if ($orderData['shipping_code'] !== 'econt_delivery.econt_delivery') return;

        $this->language->load('extension/shipping/econt_delivery');

        $data['shipping_method'] = $this->language->get('text_shipping_via_econt');
        $shipment = $this->traceShipment($orderId);
        $this->db->query(sprintf("
            UPDATE `%s`.`%secont_delivery_customer_info` AS ci
            SET ci.shipment_number = '%s'
            WHERE TRUE
                AND ci.id_order = %d
        ",
            DB_DATABASE,
            DB_PREFIX,
            $this->db->escape(@$shipment['shipmentNumber']),
            $orderId
        ));
        if (!empty($shipment['shipmentNumber'])) {
            $data['shipping_method'] .= sprintf(' - №<a href="%s" target="_blank" data-toggle="tooltip" data-original-title="%s">%s</a>',
                "{$this->trackShipmentUrl}/{$shipment['shipmentNumber']}",
                $this->language->get('text_trace_shipping'),
                $shipment['shipmentNumber']
            );
            if (!empty($shipment['pdfURL'])) $data['shipping'] = $shipment['pdfURL'];
        } else {
            $data['shipping'] = '#open_econt_delivery_create_label_window';
            ob_start(); ?>
                <?php $this->printEcontDeliveryCreateLabelWindow($eventRoute, $data); ?>
            <?php $data['footer'] = str_replace('</body>', sprintf('%s</body>', ob_get_contents()), $data['footer']);
            ob_end_clean();
        }
    }
    public function afterAdminModelSaleOrderGetOrder(&$eventRoute, &$data, &$returnData) {
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