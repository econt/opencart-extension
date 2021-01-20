<?php
class ControllerExtensionPaymentEcontPayment extends Controller {
    private $error = array();

    public function install() {
        $this->load->model('setting/event');
        $this->model_setting_event->addEvent('econt_payment', 'catalog/model/extension/shipping/econt_payment/updateOrder/before', 'extension/shipping/econt_delivery/afterModelCheckoutOrderAddHistory', 1, 1);
//        $this->model_setting_event->addEvent('econt_payment', 'catalog/model/extension/shipping/econt_payment/updateOrder/before', 'extension/shipping/econt_delivery/afterCheckoutConfirm', 1, 10);
        $this->model_setting_event->addEvent('econt_payment', 'admin/view/sale/order_list/before', 'extension/payment/econt_payment/beforeAdminViewSaleOrderListShowToken');

        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting('payment_econt_payment', array(
            'payment_econt_payment_title' => 'Гарантирано от Еконт',
            'payment_econt_payment_logo' => 'dark',
            'payment_econt_payment_description' => 'Плащане с карта, при което се резервира сумата за поръчката и доставката. Плащате, когато приемете пратката. При отказ, стойността на стоката автоматично се освобождава от картата. Приспада се само доставката. Пазарувате с карта без притеснения дали ще получите средствата си обратно при връщане.'
        ));

        return $this->checkIfDeliveryIsInstalled();
    }

    public function uninstall() {
        $this->load->model('setting/event');

        $this->model_setting_event->deleteEventByCode('econt_payment');
    }

    public function index() {
        $this->load->language('extension/payment/econt_payment');

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('payment_econt_payment', $this->request->post);

            $this->session->data['success'] = $this->language->get('text_success');

            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        if (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }

        $data['breadcrumbs'] = array();

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/payment/econt_payment', 'user_token=' . $this->session->data['user_token'], true)
        );

        $data['action'] = $this->url->link('extension/payment/econt_payment', 'user_token=' . $this->session->data['user_token'], true);

        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);

        if (isset($this->request->post['payment_econt_payment_total'])) {
            $data['payment_econt_payment_total'] = $this->request->post['payment_econt_payment_total'];
        } else {
            $data['payment_econt_payment_total'] = $this->config->get('payment_econt_payment_total');
        }

        if (isset($this->request->post['payment_econt_payment_order_status_id'])) {
            $data['payment_ecpnt_payment_order_status_id'] = $this->request->post['payment_econt_payment_order_status_id'];
        } else {
            $data['payment_econt_payment_order_status_id'] = $this->config->get('payment_econt_payment_order_status_id');
        }

        $this->load->model('localisation/order_status');

        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        if (isset($this->request->post['payment_econt_payment_geo_zone_id'])) {
            $data['payment_econt_payment_geo_zone_id'] = $this->request->post['payment_econt_payment_geo_zone_id'];
        } else {
            $data['payment_econt_payment_geo_zone_id'] = $this->config->get('payment_econt_payment_geo_zone_id');
        }

        $this->load->model('localisation/geo_zone');

        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        if (isset($this->request->post['payment_econt_payment_status'])) {
            $data['payment_econt_payment_status'] = $this->request->post['payment_econt_payment_status'];
        } else {
            $data['payment_econt_payment_status'] = $this->config->get('payment_econt_payment_status');
        }

        // payment title
        if (isset($this->request->post['payment_econt_payment_title'])) $data['payment_econt_payment_title'] = $this->request->post['payment_econt_payment_title'];
        else $data['payment_econt_payment_title'] = $this->config->get('payment_econt_payment_title');
        // payment logo
        if (isset($this->request->post['payment_econt_payment_logo'])) $data['payment_econt_payment_logo'] = $this->request->post['payment_econt_payment_logo'];
        else $data['payment_econt_payment_logo'] = $this->config->get('payment_econt_payment_logo');
        // payment description
        if (isset($this->request->post['payment_econt_payment_description'])) $data['payment_econt_payment_description'] = $this->request->post['payment_econt_payment_description'];
        else $data['payment_econt_payment_description'] = $this->config->get('payment_econt_payment_description');


        if (isset($this->request->post['payment_econt_payment_sort_order'])) {
            $data['payment_econt_payment_sort_order'] = $this->request->post['payment_econt_payment_sort_order'];
        } else {
            $data['payment_econt_payment_sort_order'] = $this->config->get('payment_econt_payment_sort_order');
        }

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/econt_payment', $data));
    }

    protected function validate() {
        if (!$this->user->hasPermission('modify', 'extension/payment/econt_payment') && !$this->checkIfDeliveryIsInstalled()) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        return !$this->error;
    }

    /**
     * @return mixed
     */
    public function checkIfDeliveryIsInstalled()
    {
        $result = $this->db->query(sprintf("
            SELECT * FROM `%s`.`%sextension` WHERE `type` = 'shipping' AND `code` = 'econt_delivery'
        ",
            DB_DATABASE,
            DB_PREFIX
        ));

        if (!$result->num_rows) {
            $this->error['warning'] = 'Модула "Достави с Еконт" трябва да бъде инсталиран преди това!';
            return false;
        }

        $econtTableColumns = $this->db->query(sprintf("
            DESCRIBE `%s`.`%secont_delivery_customer_info`;",
            DB_DATABASE,
            DB_PREFIX
        ));

        $install = true;

        foreach ($econtTableColumns->rows as $column) {
            if ($column['Field'] == 'payment_token') {
                $install = false;
            }
        }

        if (!$install) {
            return true;
        }

        $this->db->query(sprintf("       
            ALTER TABLE `%s`.`%secont_delivery_customer_info` ADD `payment_token` VARCHAR(50) DEFAULT NULL;         
        ",
            DB_DATABASE,
            DB_PREFIX
        ));

        return true;
    }

    public function beforeAdminViewSaleOrderListShowToken(/** @noinspection PhpUnusedParameterInspection */ &$eventRoute, &$data) {
        if (empty($data['orders'])) return;

        $this->language->load('extension/payment/econt_payment');

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
                    ci.payment_token AS paymentToken
                FROM `%s`.`%secont_delivery_customer_info` AS ci
                WHERE TRUE
                    AND ci.id_order IN (%s)
                    AND (
                            ci.payment_token != ''
                        AND ci.payment_token IS NOT NULL
                    )
                GROUP BY ci.id_order
            ",
                DB_DATABASE,
                DB_PREFIX,
                implode(', ', $orderIds)
            ));
            foreach ($queryResult->rows as $row) $orderData[$row['orderId']]['paymentToken'] = $row['paymentToken'];
        }

        ob_start(); ?>
        <script>
            window.econtPayment = {
                'orderData': <?=json_encode($orderData)?>
            };
            $(function($) {
                var orderListTable = $('#form-order table');
                orderListTable.find('thead tr td:last-child').before(($('<td></td>').text('<?=$this->language->get('text_order_list_econt_payment_column_label');?>')));
                orderListTable.find('tbody tr').each(function(rowIndex, row) {
                    var $row = $(row)
                    var orderId = $row.find('[name^="selected"]').val();

                    var $wayBillContent = null;
                    if (
                        window.econtPayment['orderData'] &&
                        window.econtPayment['orderData'][orderId] &&
                        window.econtPayment['orderData'][orderId]['shippingCode'] === 'econt_delivery.econt_delivery'
                    ) {
                        $wayBillContent = $('<p></p>');
                        if (window.econtPayment['orderData'][orderId]['paymentToken']) {
                            $wayBillContent.text('<?=$this->language->get('text_token_yes')?>');
                        } else {
                            $wayBillContent.text('<?=$this->language->get('text_token_no')?>');
                        }
                    }
                    $row.find('td:last-child').before(($('<td></td>').css({'text-align': 'center'}).append($wayBillContent)));
                });
            });
        </script>
        <?php //$this->printEcontDeliveryCreateLabelWindow($eventRoute, $data) ?>
        <?php $data['footer'] = str_replace('</body>', sprintf('%s</body>', ob_get_contents()), $data['footer']);
        ob_end_clean();
    }

    private function printEcontDeliveryCreateLabelWindow(/** @noinspection PhpUnusedParameterInspection */ $eventRoute, $data) { ?>
        <?php
        $this->load->model('setting/setting');
        $econtDeliverySettings = $this->model_setting_setting->getSetting('shipping_econt_delivery');

        $this->language->load('extension/payment/econt_payment');

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
}