<?php

/** @noinspection PhpUnused */
/** @noinspection PhpUnusedParameterInspection */

/**
 * class ControllerExtensionPaymentEcontPayment
 *
 * @property Loader $load
 * @property Language $language
 * @property Response $response
 * @property Request $request
 * @property Config $config
 * @property Document $document
 * @property Session $session
 * @property Url $url
 *
 * @property ModelSettingEvent $model_setting_event
 * @property ModelSettingSetting $model_setting_setting
 * @property ModelLocalisationLanguage $model_localisation_language
 * @property ModelLocalisationOrderStatus $model_localisation_order_status
 * @property ModelLocalisationGeoZone $model_localisation_geo_zone
 */
class ControllerExtensionPaymentEcontPayment extends Controller {

    public $error = array();

    public function install() {
        $this->load->model('setting/event');
        $this->load->model('setting/setting');
        $this->load->model('localisation/order_status');
        $this->load->model('localisation/language');

        $this->model_setting_event->addEvent('econt_payment', 'catalog/model/extension/shipping/econt_payment/updateOrder/before', 'extension/shipping/econt_delivery/afterModelCheckoutOrderAddHistory', 1, 1);
        $this->model_setting_event->addEvent('econt_payment', 'admin/view/sale/order_list/before', 'extension/payment/econt_payment/beforeAdminViewSaleOrderListShowToken');

        $settings = array(
            'payment_econt_payment_total' => 0.01,
            'payment_econt_payment_geo_zone_id' => 0,
            'payment_econt_payment_order_status_payment_completed_id' => $this->config->get('config_order_status_id'),
            'payment_econt_payment_order_status_payment_processing_id' => $this->config->get('config_order_status_id'),
            'payment_econt_payment_order_status_payment_failed_id' => $this->config->get('config_order_status_id'),
            'payment_econt_payment_title_lang_default' => 'Гарантирано от Еконт',
            'payment_econt_payment_logo_lang_default' => 'dark',
            'payment_econt_payment_description_lang_default' => 'Плащане с карта, при което сумата се резервира по сметката ви. Ще бъде изтеглена едва когато приемете пратката си. Ако я откажете или върнете веднага, сумата от наложения платеж се освобождава и отново е на ваше разположение.'
        );
        foreach ($this->model_localisation_language->getLanguages() as $systemLanguage) {
            $settings["payment_econt_payment_title_lang_{$systemLanguage['language_id']}"] = $settings['payment_econt_payment_title_lang_default'];
            $settings["payment_econt_payment_logo_lang_{$systemLanguage['language_id']}"] = $settings['payment_econt_payment_logo_lang_default'];
            $settings["payment_econt_payment_description_lang_{$systemLanguage['language_id']}"] = $settings['payment_econt_payment_description_lang_default'];
        }
        $this->model_setting_setting->editSetting('payment_econt_payment', $settings);

        return $this->checkIfDeliveryIsInstalled();
    }
    public function uninstall() {
        $this->load->model('setting/event');

        $this->model_setting_event->deleteEventByCode('econt_payment');
    }

    public function index() {
        $this->load->language('extension/payment/econt_payment');

        $this->load->model('setting/setting');
        $this->load->model('localisation/order_status');
        $this->load->model('localisation/geo_zone');
        $this->load->model('localisation/language');

        // save
        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('payment_econt_payment', $this->request->post);

            $this->session->data['success'] = $this->language->get('text_success');

            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        $this->document->setTitle($this->language->get('heading_title'));

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');

        $data['breadcrumbs'] = array(array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        ), array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
        ), array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/payment/econt_payment', 'user_token=' . $this->session->data['user_token'], true)
        ));

        $data['error_warning'] = (isset($this->error['warning']) ? $this->error['warning'] : '');

        $data['cancel_url'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);
        $data['action_url'] = $this->url->link('extension/payment/econt_payment', 'user_token=' . $this->session->data['user_token'], true);
        $data['edit_order_statuses_url'] = $this->url->link('localisation/order_status', 'user_token=' . $this->session->data['user_token'], true);

        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        $settingFields = array(
            'payment_econt_payment_total',
            'payment_econt_payment_order_status_payment_completed_id',
            'payment_econt_payment_order_status_payment_processing_id',
            'payment_econt_payment_order_status_payment_failed_id',
            'payment_econt_payment_geo_zone_id',
            'payment_econt_payment_status',
            'payment_econt_payment_sort_order'
        );
        foreach ($settingFields as $settingFieldName) {
            $data[$settingFieldName] = (isset($this->request->post[$settingFieldName]) ? $this->request->post[$settingFieldName] : $this->config->get($settingFieldName));
        }

        $data['systemLanguages'] = $this->model_localisation_language->getLanguages();
        foreach ($data['systemLanguages'] as $systemLanguage) {
            foreach (array('title', 'logo', 'description') as $fieldNameKey) {
                $fieldName = "payment_econt_payment_{$fieldNameKey}_lang_{$systemLanguage['language_id']}";
                $data[$fieldName] = (isset($this->request->post[$fieldName]) ? $this->request->post[$fieldName] : $this->config->get($fieldName));
            }
        }

        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/econt_payment', $data));
    }
    protected function validate() {
        if (!$this->user->hasPermission('modify', 'extension/payment/econt_payment') && !$this->checkIfDeliveryIsInstalled()) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        return !$this->error;
    }

    public function checkIfDeliveryIsInstalled() {
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

    public function beforeAdminViewSaleOrderListShowToken(&$eventRoute, &$data) {
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
                let orderListTable = $('#form-order table');
                orderListTable.find('thead tr td:last-child').before(($('<td></td>').text('<?=$this->language->get('text_grid_gecd_column_header');?>')));
                orderListTable.find('tbody tr').each(function(rowIndex, row) {
                    let $row = $(row)
                    let orderId = $row.find('[name^="selected"]').val();

                    let $wayBillContent = null;
                    if (
                        window.econtPayment['orderData'] &&
                        window.econtPayment['orderData'][orderId] &&
                        window.econtPayment['orderData'][orderId]['shippingCode'] === 'econt_delivery.econt_delivery'
                    ) {
                        $wayBillContent = $('<p></p>');
                        if (window.econtPayment['orderData'][orderId]['paymentToken']) {
                            $wayBillContent.text('<?=$this->language->get('text_grid_gecd_column_content_yes')?>');
                        } else {
                            $wayBillContent.text('<?=$this->language->get('text_grid_gecd_column_content_no')?>');
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

}