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
 */
class ControllerExtensionShippingEcontDelivery extends Controller {

    private $error = array();

    private $systemUrls = array(
        'production' => 'https://delivery.econt.com',
        'testing' => 'http://delivery.demo.econt.com'
    );

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
            CREATE TABLE `%s`.`%secont_delivery_customer_info` (
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
        $this->model_setting_event->addEvent('econt_delivery', 'admin/view/sale/order_form/before', 'extension/shipping/econt_delivery/customerInfo');
        $this->model_setting_event->addEvent('econt_delivery', 'admin/view/sale/order_info/before', 'extension/shipping/econt_delivery/trackShipment');
        $this->model_setting_event->addEvent('econt_delivery', 'admin/controller/sale/order/shipping/before', 'extension/shipping/econt_delivery/printShipmentLabel');
        $this->model_setting_event->addEvent('econt_delivery', 'catalog/controller/api/*/before', 'api/extension/econt_delivery/updateSessionCustomerInfo');

    }
    public function uninstall() {
        $this->load->model('setting/event');

        $this->model_setting_event->deleteEventByCode('econt_delivery');
    }


    public function trackShipment(/** @noinspection PhpUnusedParameterInspection */ $eventRoute, &$data) {
        // todo: tova onova ima li pratka nqma li pratka i prosledqvame
        if (true) {
            $data['shipping_method'] = 'Достави с Еконт (<a href="https://www.econt.com/services/track-shipment/1234" target="_blank">проследи пратка</a>)';
        }
    }
    public function customerInfoForm(/** @noinspection PhpUnusedParameterInspection */ $eventRoute, &$data) {
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
                            <iframe src="<?=$econtDeliverySettings['shipping_econt_delivery_system_url']?>"></iframe>
                        </div>
                    </div>
                </div>
            </div>
            <script>
                window.econtDelivery = {
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
                            'route' => 'api/extension/econt_delivery/getCustomerInfoParams',
                            'api_token' => $data['api_token'],
                            'order_id' => $data['order_id']
                        ))?>', {}, function(response) {
                            if (!window.econtDelivery.empty(response['error'])) {
                                alert(response['error']);
                                return;
                            }

                            if (window.econtDelivery.empty(response['customer_info_url'])) {
                                alert('<?=$this->language->get('empty_customer_info_url')?>');
                                return;
                            }

                            $customerInfoWindow.find('iframe').attr('src', response['customer_info_url']);
                            $customerInfoWindow.modal('show');
                        }, 'json').fail(function(xhr, textStatus, errorThrown) {
                            alert('<?=$this->language->get('text_default_error_message')?>');
                            console.error(errorThrown);
                        });
                    });
                    $(window).on('message',function(event){
                        if (event['originalEvent']['origin'] != window.econtDelivery.systemUrl) return;

                        var messageData = event['originalEvent']['data'];
                        if (!messageData) return;

                        var withShippingError = false;
                        if (!window.econtDelivery.empty(messageData['shipment_error'])) {
                            withShippingError = true;
                            alert(messageData['shipment_error']);
                        }

                        $.post('<?=HTTP_CATALOG?>index.php?<?=http_build_query(array(
                            'route' => 'api/extension/econt_delivery/customerInfo',
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
                            'route' => 'api/extension/econt_delivery/customerInfo',
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
    public function printShipmentLabel(/** @noinspection PhpUnusedParameterInspection */ $eventRoute, &$data) {
        echo '<b>PDF</b>';
        die();
    }

}