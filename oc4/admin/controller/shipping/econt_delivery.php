<?php

namespace Opencart\Admin\Controller\Extension\EcontDeliveryOc4\Shipping;

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

class EcontDelivery extends \Opencart\System\Engine\Controller {

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
                `payment_token` VARCHAR(255) NULL DEFAULT NULL,
                PRIMARY KEY (`id_order`)
            )
            COLLATE = 'utf8_general_ci'
            ENGINE = InnoDB
        ",
            DB_DATABASE,
            DB_PREFIX
        ));

         /**
         * If the extension is reinstalled, then we need to add the payment token because the payment token is
         * in econt_delivery queries, but previously was created only when econt_payment in installed.
         */
        // Check if column exists
        $column_exists = $this->db->query("
                            SELECT COUNT(*) as total 
                            FROM INFORMATION_SCHEMA.COLUMNS 
                            WHERE TABLE_SCHEMA = '" . DB_DATABASE . "' 
                            AND TABLE_NAME = '" . DB_PREFIX . "econt_delivery_customer_info' 
                            AND COLUMN_NAME = 'payment_token'
                        ");

        // Add column if it doesn't exist
        if ($column_exists->row['total'] == 0) {
            $this->db->query("ALTER TABLE `" . DB_PREFIX . "econt_delivery_customer_info` ADD COLUMN payment_token VARCHAR(255) DEFAULT NULL");
        }
	    
	    $events = [
            // Catalog events - Checkout integration
		    [
			    'code'        => 'econt_delivery_oc4',
			    'description' => 'Econt Delivery - Load modal on shipping method page',
			    'trigger'     => 'catalog/view/checkout/shipping_method/after',
			    'action'      => 'extension/econt_delivery_oc4/shipping/econt_delivery.loadModal',
			    'status'      => 1,
			    'sort_order'  => 1
		    ],
		    [
			    'code'        => 'econt_delivery_oc4',
			    'description' => 'Econt Delivery - Validate customer info before shipping save',
			    'trigger'     => 'catalog/controller/checkout/shipping_method.save/before',
			    'action'      => 'extension/econt_delivery_oc4/shipping/econt_delivery.validateCustomerInfo',
			    'status'      => 1,
			    'sort_order'  => 1
		    ],
		    [
			    'code'        => 'econt_delivery_oc4',
			    'description' => 'Econt Delivery - Update order data before creation',
			    'trigger'     => 'catalog/model/checkout/order/addOrder/before',
			    'action'      => 'extension/econt_delivery_oc4/shipping/econt_delivery.beforeOrderCreate',
			    'status'      => 1,
			    'sort_order'  => 1
		    ],
		    [
			    'code'        => 'econt_delivery_oc4',
			    'description' => 'Econt Delivery - Save customer info after order confirmation',
			    'trigger'     => 'catalog/controller/checkout/confirm.confirm/after',
			    'action'      => 'extension/econt_delivery_oc4/shipping/econt_delivery.afterCheckoutConfirmConfirm',
			    'status'      => 1,
			    'sort_order'  => 1
		    ],
		    [
			    'code'        => 'econt_delivery_oc4',
			    'description' => 'Econt Delivery - Sync order with Econt API',
			    'trigger'     => 'catalog/model/checkout/order.addHistory/after',
			    'action'      => 'extension/econt_delivery_oc4/shipping/econt_delivery.syncOrderWithEcont',
			    'status'      => 1,
			    'sort_order'  => 1
		    ],
		    // Admin events - Order management
		    [
			    'code'        => 'econt_delivery_oc4',
			    'description' => 'Econt Delivery - Add column to order list',
			    'trigger'     => 'admin/view/sale/order_list/after',
			    'action'      => 'extension/econt_delivery_oc4/shipping/econt_delivery.beforeAdminViewSaleOrderList',
			    'status'      => 1,
			    'sort_order'  => 1
		    ],
		    [
			    'code'        => 'econt_delivery_oc4',
			    'description' => 'Econt Delivery - Add Econt info to order view',
			    'trigger'     => 'admin/view/sale/order_info/before',
			    'action'      => 'extension/econt_delivery_oc4/shipping/econt_delivery.beforeAdminViewSaleOrderInfo',
			    'status'      => 1,
			    'sort_order'  => 1
		    ],
		    [
			    'code'        => 'econt_delivery_oc4',
			    'description' => 'Econt Delivery - Update order status in Econt',
			    'trigger'     => 'admin/model/sale/order/addHistory/after',
			    'action'      => 'extension/econt_delivery_oc4/shipping/econt_delivery.afterAdminSaleOrderAddHistory',
			    'status'      => 1,
			    'sort_order'  => 1
		    ],
		    [
			    'code'        => 'econt_delivery_oc4',
			    'description' => 'Econt Delivery - Populate order data with Econt info',
			    'trigger'     => 'admin/model/sale/order/getOrder/after',
			    'action'      => 'extension/econt_delivery_oc4/shipping/econt_delivery.afterAdminModelSaleOrderGetOrder',
			    'status'      => 1,
			    'sort_order'  => 1
		    ],
		    // Admin Order Form events - Order creation/editing integration
		    [
			    'code'        => 'econt_delivery_oc4',
			    'description' => 'Econt Delivery - Load modal on admin order form',
			    'trigger'     => 'admin/view/sale/order_form/after',
			    'action'      => 'extension/econt_delivery_oc4/shipping/econt_delivery.loadAdminOrderFormModal',
			    'status'      => 1,
			    'sort_order'  => 1
		    ],
		    [
			    'code'        => 'econt_delivery_oc4',
			    'description' => 'Econt Delivery - Load modal on admin order form (alternative route)',
			    'trigger'     => 'admin/view/sale/order_info/after',
			    'action'      => 'extension/econt_delivery_oc4/shipping/econt_delivery.loadAdminOrderFormModal',
			    'status'      => 1,
			    'sort_order'  => 1
		    ],
		    [
			    'code'        => 'econt_delivery_oc4',
			    'description' => 'Econt Delivery - Update order data before creation (admin)',
			    'trigger'     => 'admin/model/sale/order/addOrder/before',
			    'action'      => 'extension/econt_delivery_oc4/shipping/econt_delivery.beforeAdminOrderSave',
			    'status'      => 1,
			    'sort_order'  => 1
		    ],
		    [
			    'code'        => 'econt_delivery_oc4',
			    'description' => 'Econt Delivery - Save customer info after order creation (admin)',
			    'trigger'     => 'admin/model/sale/order/addOrder/after',
			    'action'      => 'extension/econt_delivery_oc4/shipping/econt_delivery.afterAdminOrderSave',
			    'status'      => 1,
			    'sort_order'  => 1
		    ],
		    [
			    'code'        => 'econt_delivery_oc4',
			    'description' => 'Econt Delivery - Update order data before editing (admin)',
			    'trigger'     => 'admin/model/sale/order/editOrder/before',
			    'action'      => 'extension/econt_delivery_oc4/shipping/econt_delivery.beforeAdminOrderSave',
			    'status'      => 1,
			    'sort_order'  => 1
		    ],
		    [
			    'code'        => 'econt_delivery_oc4',
			    'description' => 'Econt Delivery - Save customer info after order editing (admin)',
			    'trigger'     => 'admin/model/sale/order/editOrder/after',
			    'action'      => 'extension/econt_delivery_oc4/shipping/econt_delivery.afterAdminOrderSave',
			    'status'      => 1,
			    'sort_order'  => 1
		    ],
		    [
			    'code'        => 'econt_delivery_oc4',
			    'description' => 'Econt Delivery - Notify about a new module version (admin)',
			    'trigger'     => 'admin/view/common/header/after',
			    'action'      => 'extension/econt_delivery_oc4/shipping/econt_delivery.adminCheckVersion',
			    'status'      => 1,
			    'sort_order'  => 1
		    ],
	    ];

	    foreach($events as $event) {
	        $this->model_setting_event->addEvent($event);
	    }
    }
    public function uninstall() {
        $this->load->model('setting/event');

        $this->model_setting_event->deleteEventByCode('econt_delivery_oc4');
    }

    /**
     * Event admin/view/common/header/after: известие в админа, ако в GitHub има по-нова версия на модула.
     * Инсталираната версия е от oc_extension_install (install.json от zip-а, качен през Extensions → Installer).
     */
    public function adminCheckVersion(string &$route, array &$args, string &$output): void {
        if (!$this->config->get('shipping_econt_delivery_status') || empty($this->session->data['user_token'])) {
            return;
        }

        $this->load->model('setting/extension');
        $install = $this->model_setting_extension->getInstallByCode('econt_delivery_oc4');
        $currentVersion = $install['version'] ?? '';

        // Веднъж на сесия, включително когато GitHub не отговори, за да не се бави всяка страница
        if (!isset($this->session->data['econt_delivery_latest_version'])) {
            $this->session->data['econt_delivery_latest_version'] = $this->getLatestReleaseVersion();
        }
        $latestVersion = $this->session->data['econt_delivery_latest_version'];

        if ($currentVersion === '' || $latestVersion === '' || !version_compare($latestVersion, $currentVersion, '>')) {
            return;
        }

        $this->load->language('extension/econt_delivery_oc4/shipping/econt_delivery', 'econt_delivery');
        $notification = $this->load->view('extension/econt_delivery_oc4/shipping/econt_delivery_version_notification', [
            'text_version_notification' => sprintf($this->language->get('econt_delivery_text_version_notification'), $currentVersion, $latestVersion),
            'button_version_download' => $this->language->get('econt_delivery_button_version_download'),
            'button_version_instructions' => $this->language->get('econt_delivery_button_version_instructions'),
            'download_url' => 'https://github.com/econt/opencart-extension/releases/latest/download/econt_delivery_oc4.ocmod.zip',
            'instructions_url' => 'https://github.com/econt/opencart-extension/wiki/Installation'
        ]);

        $output = str_replace('</header>', '</header>' . $notification, $output);
    }

    /**
     * Версията от tag-а на последния release (vX.Y.Z), общ за OC3 и OC4. '' при грешка.
     */
    private function getLatestReleaseVersion(): string {
        $ch = curl_init('https://api.github.com/repos/econt/opencart-extension/releases/latest');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
            CURLOPT_USERAGENT => 'econt-opencart-extension' // GitHub API изисква User-Agent
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $release = $status == 200 ? json_decode((string)$response, true) : null;
        if (!is_array($release) || !preg_match('/^v?(\d+\.\d+\.\d+)$/', $release['tag_name'] ?? '', $match)) {
            return '';
        }

        return $match[1];
    }

    public function index() {

        $this->language->load('extension/econt_delivery_oc4/shipping/econt_delivery');
        
        $this->load->model('setting/setting');
        $this->load->model('localisation/geo_zone');


        $this->document->setTitle($this->language->get('heading_title'));
        $this->response->setOutput($this->load->view('extension/econt_delivery_oc4/shipping/econt_delivery', array(
            'header' => $this->load->controller('common/header'),
            'column_left' => $this->load->controller('common/column_left'),
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
                'href' => $this->url->link('extension/econt_delivery_oc4/shipping/econt_delivery', http_build_query(array(
                    'user_token' => $this->session->data['user_token']
                )), true)
            )),
            'geo_zones' => $this->model_localisation_geo_zone->getGeoZones(),
            'settings' => $this->model_setting_setting->getSetting('shipping_econt_delivery'),
            'system_urls' => $this->systemUrls,
            'actions' => array(
                'submit_url' => $this->url->link('extension/econt_delivery_oc4/shipping/econt_delivery.save', http_build_query(array(
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

    public function save(): void
    {
        $this->language->load('extension/econt_delivery_oc4/shipping/econt_delivery');
        
        $this->load->model('setting/setting');
        $json = $this->validate();
        
        if ( empty($json) ) {

            $oldSettings = $this->model_setting_setting->getSetting('shipping_econt_delivery');
            $this->model_setting_setting->editSetting('shipping_econt_delivery', $this->request->post);

            if(
                $this->request->post['shipping_econt_delivery_private_key'] != $oldSettings['shipping_econt_delivery_private_key'] ||
                $this->request->post['shipping_econt_delivery_system_url'] != $oldSettings['shipping_econt_delivery_system_url']
            )
            {
	            try {
                    $curl = curl_init();
                    curl_setopt($curl, CURLOPT_URL, "{$this->request->post['shipping_econt_delivery_system_url']}/services/PluginsService.logEvent.json");
                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($curl, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        "Authorization: {$this->request->post['shipping_econt_delivery_private_key']}"
                    ]);
                    curl_setopt($curl, CURLOPT_POST, true);
                    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([[
                        'plugin_type' => 'opencart4',
                        'action' => 'activate'
                    ]]));
                    curl_setopt($curl, CURLOPT_TIMEOUT, 6);
                    curl_exec($curl);
                    curl_close($curl);
                } catch (Exception $exception) {
                    $logger = new Log('econt_delivery.log');
                    $logger->write(sprintf('Curl failed with error [%d] %s', $exception->getCode(), $exception->getMessage()));
                }
            }

            $this->response->addHeader('Content-Type: application/json');
            $json['success'] = $this->language->get('text_success_setting_update');
            $this->response->setOutput(json_encode($json));
        } else {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($json));
        }
    }
    public function validate() {
        $json = [];
        if (!$this->user->hasPermission('modify', 'extension/econt_delivery_oc4/shipping/econt_delivery')) {
            $json['error'] = $this->language->get('error_permission');
        }

        if(empty($this->request->post['shipping_econt_delivery_private_key'])) {
            $json['error'] = $this->language->get('error_private_key');
        }

        return $json;
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

    private function printEcontDeliveryCreateLabelWindow($eventRoute, $data) {

        $this->load->model('setting/setting');
        $econtDeliverySettings = $this->model_setting_setting->getSetting('shipping_econt_delivery');

        $this->language->load('extension/econt_delivery_oc4/shipping/econt_delivery');

        $orderId = isset($data['order_id']) ? intval($data['order_id']) : 0;
        if ($orderId <= 0) {
            $orderId = null;
        }

    
        $html = '';

        $html .= '<style>';
        $html .= '    #econt-delivery-create-label-modal .modal-dialog {';
        $html .= '        width: 96%;';
        $html .= '    }';
        $html .= '    #econt-delivery-create-label-modal .modal-body {';
        $html .= '        padding: 0;';
        $html .= '    }';
        $html .= '    #econt-delivery-create-label-modal .modal-title {';
        $html .= '        padding: 0;';
        $html .= '    }';
        $html .= '    #econt-delivery-create-label-modal iframe {';
        $html .= '        border: 0;';
        $html .= '        width: 100%;';
        $html .= '        height: 87vh;';
        $html .= '    }';
        $html .= '    div.modal-header>button.close {';
        $html .= '        float: right;';
        $html .= '        font-size: 19.5px;';
        $html .= '        font-weight: bold;';
        $html .= '        line-height: 1;';
        $html .= '        color: #000;';
        $html .= '        text-shadow: 0 1px 0 #fff;';
        $html .= '        opacity: 0.2;';
        $html .= '        padding: 0;';
        $html .= '        cursor: pointer;';
        $html .= '        background: transparent;';
        $html .= '        border: 0;';
        $html .= '        -webkit-appearance: none;';
        $html .= '    }';
        $html .= '';
        $html .= '    @media screen and (min-width: 800px) {';
        $html .= '        #econt-delivery-create-label-modal .modal-dialog {';
        $html .= '            width: 700px;';
        $html .= '            max-width: 700px;';
        $html .= '        }';
        $html .= '    }';
        $html .= '</style>';
        $html .= '<div id="econt-delivery-create-label-modal" class="modal fade" role="dialog">';
        $html .= '    <div class="modal-dialog">';
        $html .= '        <div class="modal-content">';
        $html .= '            <div class="modal-header">';
        $html .= '                <button type="button" class="close" data-dismiss="modal">&times;</button>';
        $html .= '                <h4 class="modal-title">' . $this->language->get('heading_title') . '</h4>';
        $html .= '            </div>';
        $html .= '            <div class="modal-body">';
        $html .= '                <iframe src="about:blank"></iframe>';
        $html .= '            </div>';
        $html .= '        </div>';
        $html .= '    </div>';
        $html .= '</div>';
        $html .= '<script>';
        $html .= '    $(function($) {';
        $html .= '        var empty__ = function(thingy) {';
        $html .= '            return thingy == 0 || !thingy || (typeof(thingy) === \'object\' && $.isEmptyObject(thingy));';
        $html .= '        };';
        $html .= '        var $createLabelWindow = $(\'#econt-delivery-create-label-modal\').modal({';
        $html .= '            \'show\': false,';
        $html .= '            \'backdrop\': \'static\'';
        $html .= '        });';
        $html .= '        $(\'[href="#open_econt_delivery_create_label_window"]\').click(function(event) {';
        $html .= '            event.preventDefault();';
        $html .= '            event.stopPropagation();';
        $html .= '';
        $html .= '            $createLabelWindow.find(\'iframe\').attr(\'src\', \'' . $econtDeliverySettings['shipping_econt_delivery_system_url'] . '/create_label.php?\' + $.param({';
        $html .= '                \'order_number\': (' . json_encode($orderId) . ' || $(this).attr(\'data-order-id\')),';
        $html .= '                \'token\': \'' . $econtDeliverySettings['shipping_econt_delivery_private_key'] . '\'';
        $html .= '            }));';
        $html .= '            $createLabelWindow.modal(\'show\');';
        $html .= '        });';
        $html .= '        $(\'div.modal-header>button.close\').click(function(){';
        $html .= '            $createLabelWindow.modal(\'hide\');';
        $html .= '        });';
        $html .= '        $(window).on(\'message\', function(event){';
        $html .= '            if (event[\'originalEvent\'][\'origin\'] != \'' . $econtDeliverySettings['shipping_econt_delivery_system_url'] . '\') return;';
        $html .= '';
        $html .= '            var messageData = event[\'originalEvent\'][\'data\'];';
        $html .= '            if (!messageData) return;';
        $html .= '';
        $html .= '            switch (messageData[\'event\']) {';
        $html .= '                case \'cancel\':';
        $html .= '                    $createLabelWindow.modal(\'hide\');';
        $html .= '                    break;';
        $html .= '                case \'confirm\':';
        $html .= '                    if (messageData[\'printPdf\'] === true && !empty__(messageData[\'shipmentStatus\'][\'pdfURL\'])) window.open(messageData[\'shipmentStatus\'][\'pdfURL\'], \'_blank\');';
        $html .= '                    setTimeout(function() {';
        $html .= '                        window.location.href = \'index.php?\' + $.param({';
        $html .= '                            \'route\': \'sale/order|info\',';
        $html .= '                            \'user_token\': \'' . $this->session->data['user_token'] . '\',';
        $html .= '                            \'order_id\': messageData[\'orderData\'][\'num\']';
        $html .= '                        });';
        $html .= '                    }, 300);';
        $html .= '                    break;';
        $html .= '            }';
        $html .= '        });';
        $html .= '    });';
        $html .= '</script>';

        return $html;
    }

    private function printEcontAddressSelectionModal() {
        $this->load->model('setting/setting');
        $econtDeliverySettings = $this->model_setting_setting->getSetting('shipping_econt_delivery');
        $this->language->load('extension/econt_delivery_oc4/shipping/econt_delivery');

        $html = '';

        $html .= '<style>';
        $html .= '    #econt-address-selection-modal .modal-dialog {';
        $html .= '        width: 96%;';
        $html .= '    }';
        $html .= '    #econt-address-selection-modal .modal-body {';
        $html .= '        padding: 0;';
        $html .= '    }';
        $html .= '    #econt-address-selection-modal iframe {';
        $html .= '        border: 0;';
        $html .= '        width: 100%;';
        $html .= '        height: 600px;';
        $html .= '    }';
        $html .= '    @media screen and (min-width: 800px) {';
        $html .= '        #econt-address-selection-modal .modal-dialog {';
        $html .= '            width: 900px;';
        $html .= '            max-width: 900px;';
        $html .= '        }';
        $html .= '    }';
        $html .= '    .econt-select-address-btn {';
        $html .= '        margin-left: 10px;';
        $html .= '        padding: 5px 15px;';
        $html .= '        background: #007bff;';
        $html .= '        color: white;';
        $html .= '        border: none;';
        $html .= '        border-radius: 3px;';
        $html .= '        cursor: pointer;';
        $html .= '        text-decoration: none;';
        $html .= '        display: inline-block;';
        $html .= '    }';
        $html .= '    .econt-select-address-btn:hover {';
        $html .= '        background: #0056b3;';
        $html .= '        color: white;';
        $html .= '        text-decoration: none;';
        $html .= '    }';
        $html .= '</style>';
        
        $html .= '<div id="econt-address-selection-modal" class="modal fade" role="dialog">';
        $html .= '    <div class="modal-dialog">';
        $html .= '        <div class="modal-content">';
        $html .= '            <div class="modal-header">';
        $html .= '                <button type="button" class="close" data-dismiss="modal">&times;</button>';
        $html .= '                <h4 class="modal-title">' . $this->language->get('text_choose_address') . '</h4>';
        $html .= '            </div>';
        $html .= '            <div class="modal-body">';
        $html .= '                <div id="econt-address-loader" style="text-align: center; padding: 40px;">';
        $html .= '                    <i class="fa fa-spinner fa-spin fa-3x"></i>';
        $html .= '                    <p style="margin-top: 15px;">' . $this->language->get('text_choose_address') . '</p>';
        $html .= '                </div>';
        $html .= '                <iframe id="econt-address-iframe" src="about:blank" style="display: none;"></iframe>';
        $html .= '            </div>';
        $html .= '        </div>';
        $html .= '    </div>';
        $html .= '</div>';
        
        $html .= '<script>';
        $html .= '(function() {';
        $html .= '    var userToken = \'' . $this->session->data['user_token'] . '\';';
        $html .= '    var deliveryBaseUrl = \'' . $econtDeliverySettings['shipping_econt_delivery_system_url'] . '\';';
        $html .= '    var $addressModal = $(\'#econt-address-selection-modal\');';
        $html .= '    var $iframe = $(\'#econt-address-iframe\');';
        $html .= '    var $loader = $(\'#econt-address-loader\');';
        $html .= '';
        $html .= '    $addressModal.modal({ show: false, backdrop: \'static\' });';
        $html .= '';
        $html .= '    $(document).on(\'click\', \'[href="#select_econt_address"]\', function(event) {';
        $html .= '        event.preventDefault();';
        $html .= '        event.stopPropagation();';
        $html .= '';
        $html .= '        $loader.show();';
        $html .= '        $iframe.hide();';
        $html .= '        $addressModal.modal(\'show\');';
        $html .= '';
        $html .= '        var orderData = {';
        $html .= '            order_weight: parseFloat($(\'input[name="weight"]\').val()) || 0,';
        $html .= '            order_total: calculateOrderTotal(),';
        $html .= '            order_currency: $(\'select[name="currency"]\').val() || \'BGN\',';
        $html .= '            shipping_firstname: $(\'input[name="shipping_firstname"]\').val() || \'\',';
        $html .= '            shipping_lastname: $(\'input[name="shipping_lastname"]\').val() || \'\',';
        $html .= '            shipping_company: $(\'input[name="shipping_company"]\').val() || \'\',';
        $html .= '            shipping_address_1: $(\'input[name="shipping_address_1"]\').val() || \'\',';
        $html .= '            shipping_address_2: $(\'input[name="shipping_address_2"]\').val() || \'\',';
        $html .= '            shipping_city: $(\'input[name="shipping_city"]\').val() || \'\',';
        $html .= '            shipping_postcode: $(\'input[name="shipping_postcode"]\').val() || \'\',';
        $html .= '            shipping_country_code: $(\'select[name="shipping_country_id"] option:selected\').data(\'iso-code-3\') || \'BGR\',';
        $html .= '            customer_email: $(\'input[name="email"]\').val() || \'\',';
        $html .= '            customer_telephone: $(\'input[name="telephone"]\').val() || \'\',';
        $html .= '            payment_method: $(\'select[name="payment_method"]\').val() || \'\'';
        $html .= '        };';
        $html .= '';
        $html .= '        $.ajax({';
        $html .= '            url: \'index.php?route=extension/econt_delivery_oc4/shipping/econt_delivery.getAdminCustomerInfoParams&user_token=\' + userToken,';
        $html .= '            type: \'POST\',';
        $html .= '            data: orderData,';
        $html .= '            dataType: \'json\',';
        $html .= '            success: function(json) {';
        $html .= '                if (json.error) {';
        $html .= '                    alert(json.error);';
        $html .= '                    $addressModal.modal(\'hide\');';
        $html .= '                    return;';
        $html .= '                }';
        $html .= '                if (json.success && json.iframe_url) {';
        $html .= '                    $iframe.attr(\'src\', json.iframe_url);';
        $html .= '                    $iframe.on(\'load\', function() {';
        $html .= '                        $loader.hide();';
        $html .= '                        $iframe.show();';
        $html .= '                    });';
        $html .= '                }';
        $html .= '            },';
        $html .= '            error: function() {';
        $html .= '                alert(\'Failed to load address selection. Please try again.\');';
        $html .= '                $addressModal.modal(\'hide\');';
        $html .= '            }';
        $html .= '        });';
        $html .= '    });';
        $html .= '';
        $html .= '    $(window).on(\'message\', function(event) {';
        $html .= '        if (event.originalEvent.origin.indexOf(deliveryBaseUrl) !== 0) return;';
        $html .= '';
        $html .= '        var data = event.originalEvent.data;';
        $html .= '        if (!data || data.shipment_error) {';
        $html .= '            if (data && data.shipment_error) alert(data.shipment_error);';
        $html .= '            return;';
        $html .= '        }';
        $html .= '';
        $html .= '        data.payment_method = $(\'select[name="payment_method"]\').val() || \'\';';
        $html .= '';
        $html .= '        $.ajax({';
        $html .= '            url: \'index.php?route=extension/econt_delivery_oc4/shipping/econt_delivery.saveAdminCustomerInfo&user_token=\' + userToken,';
        $html .= '            type: \'POST\',';
        $html .= '            data: data,';
        $html .= '            dataType: \'json\',';
        $html .= '            success: function(json) {';
        $html .= '                if (json.error) {';
        $html .= '                    alert(json.error);';
        $html .= '                    return;';
        $html .= '                }';
        $html .= '                if (json.success) {';
        $html .= '                    var info = json.customer_info;';
        $html .= '                    var nameParts = info.name.split(\' \', 2);';
        $html .= '                    $(\'input[name="shipping_firstname"]\').val(nameParts[0] || \'\');';
        $html .= '                    $(\'input[name="shipping_lastname"]\').val(nameParts[1] || \'\');';
        $html .= '                    $(\'input[name="shipping_company"]\').val(info.face || \'\');';
        $html .= '                    $(\'input[name="shipping_city"]\').val(info.city_name || \'\');';
        $html .= '                    $(\'input[name="shipping_postcode"]\').val(info.post_code || \'\');';
        $html .= '                    if (info.office_code) {';
        $html .= '                        $(\'input[name="shipping_address_1"]\').val(\'Econt office: \' + (info.office_name || \'\'));';
        $html .= '                        $(\'input[name="shipping_address_2"]\').val(info.address || \'\');';
        $html .= '                    } else {';
        $html .= '                        $(\'input[name="shipping_address_1"]\').val(info.address || \'\');';
        $html .= '                        $(\'input[name="shipping_address_2"]\').val(\'\');';
        $html .= '                    }';
        $html .= '                    if (info.email) $(\'input[name="email"]\').val(info.email);';
        $html .= '                    if (info.phone) $(\'input[name="telephone"]\').val(info.phone);';
        $html .= '                    if (json.shipping_price) {';
        $html .= '                        var shippingInput = $(\'input[name="shipping_value"]\');';
        $html .= '                        if (shippingInput.length) shippingInput.val(json.shipping_price.toFixed(2));';
        $html .= '                    }';
        $html .= '                    $addressModal.modal(\'hide\');';
        $html .= '                    alert(\'Address selected successfully!\');';
        $html .= '                }';
        $html .= '            }';
        $html .= '        });';
        $html .= '    });';
        $html .= '';
        $html .= '    function calculateOrderTotal() {';
        $html .= '        var total = 0;';
        $html .= '        $(\'input[name^="product["][name$="][total]"]\').each(function() {';
        $html .= '            total += parseFloat($(this).val()) || 0;';
        $html .= '        });';
        $html .= '        return total;';
        $html .= '    }';
        $html .= '})();';
        $html .= '</script>';

        return $html;
    }

    public function beforeAdminViewSaleOrderList(&$eventRoute, &$data, &$output) {
        
        if (empty($data['orders'])){
            return;
        }

        $orderIds = array();
        $orderData = array();

        foreach ($data['orders'] as $order) {

            $shippingMethodQuery = $this->db->query("SELECT shipping_method FROM " . DB_PREFIX . "order WHERE order_id = " . $order['order_id']);
            $shippingMethod = $shippingMethodQuery->row['shipping_method'];

            if(!empty($shippingMethod)) {
                $shippingMethod = json_decode($shippingMethod, true);
                $order['shipping_method'] = $shippingMethod;
            }

            $orderId = intval($order['order_id']);
            
            $orderData[$orderId] = array(
                'id' => $orderId,
                'shippingCode' => $order['shipping_method']['code'] ?? ''
            );

            if ($orderId > 0 || $order['shipping_method']['code'] === 'econt_delivery.econt_delivery') {
                $orderIds[$orderId] = $orderId;
            }
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

            foreach ($queryResult->rows as $row) {
                $orderData[$row['orderId']]['shipmentNum'] = $row['shipmentNum'];
            }
        }

        $this->load->language('extension/econt_delivery_oc4/shipping/econt_delivery');

        $this->session->data['econt_delivery']['orders'] = $orderData;

        $html = '';
        $html .= '<script>';
        $html .= '    window.econtDelivery = {';
        $html .= '        \'orderData\': ' . json_encode($orderData);
        $html .= '    };';
        $html .= '    $(function($) {';
        $html .= '        var orderListTable = $(\'#form-order table\');';
        $html .= '        orderListTable.find(\'thead tr td:last-child\').before(($(\'<td></td>\').text(\'' . $this->language->get('text_order_list_econt_shipping_column_label') . '\')));';
        $html .= '        orderListTable.find(\'tbody tr\').each(function(rowIndex, row) {';
        $html .= '            var $row = $(row);';
        $html .= '            var orderId = $row.find(\'[name^="selected"]\').val();';
        $html .= '';
        $html .= '            var $wayBillContent = null;';
        $html .= '            if (window.econtDelivery[\'orderData\'] && window.econtDelivery[\'orderData\'][orderId] && window.econtDelivery[\'orderData\'][orderId][\'shippingCode\'] === \'econt_delivery.econt_delivery\') {';
        $html .= '                $wayBillContent = $(\'<a></a>\');';
        $html .= '                if (window.econtDelivery[\'orderData\'][orderId][\'shipmentNum\']) {';
        $html .= '                    $wayBillContent.attr({';
        $html .= '                        \'href\': \'' . $this->trackShipmentUrl . '/\' + window.econtDelivery[\'orderData\'][orderId][\'shipmentNum\'],';
        $html .= '                        \'target\': \'_blank\'';
        $html .= '                    }).text(window.econtDelivery[\'orderData\'][orderId][\'shipmentNum\']);';
        $html .= '                } else {';
        $html .= '                    $wayBillContent.attr({';
        $html .= '                        \'href\': \'#open_econt_delivery_create_label_window\',';
        $html .= '                        \'target\': \'_self\',';
        $html .= '                        \'data-order-id\': orderId';
        $html .= '                    }).text(\'' . $this->language->get('text_order_list_econt_shipping_column_prepare_loading') . '\');';
        $html .= '                }';
        $html .= '            }';
        $html .= '            $row.find(\'td:last-child\').before(($(\'<td></td>\').css({\'text-align\': \'center\'}).append($wayBillContent)));';
        $html .= '        });';
        $html .= '    });';
        $html .= '</script>';
        $html .= $this->printEcontDeliveryCreateLabelWindow($eventRoute, $data);
        $output = $output . $html;
    }
    
    
    public function beforeAdminViewSaleOrderInfo(&$eventRoute, &$data, &$output): void {

        $orderId = isset($this->request->get['order_id']) ? intval($this->request->get['order_id']) : 0;

        if ($orderId <= 0) {
            return;
        }

        $this->load->model('sale/order');
        $orderData = $this->model_sale_order->getOrder($orderId);

        if ($orderData['shipping_method']['code'] !== 'econt_delivery.econt_delivery') {
            return;
        }

        $this->language->load('extension/econt_delivery_oc4/shipping/econt_delivery');

        $data['shipping_method'] = $this->language->get('text_shipping_via_econt');
        $shipment = $this->traceShipment($orderId);
        
        if (!empty($shipment['shipmentNumber'])) {
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
            
            $data['shipping_method'] .= sprintf(' - №<a href="%s" target="_blank" data-toggle="tooltip" data-original-title="%s">%s</a>',
                "{$this->trackShipmentUrl}/{$shipment['shipmentNumber']}",
                $this->language->get('text_trace_shipping'),
                $shipment['shipmentNumber']
            );

            if (!empty($shipment['pdfURL'])) {
                $data['shipping'] = $shipment['pdfURL'];
            }

        } else {
            $data['shipping'] = '#open_econt_delivery_create_label_window';
            ob_start();
            $html = $this->printEcontDeliveryCreateLabelWindow($eventRoute, $data);
            $data['footer'] = str_replace('</body>', sprintf('%s</body>', $html), $data['footer']);
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
                $returnData['shipping_method']['code'] === 'econt_delivery.econt_delivery'
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
    
    /**
     * Update order status in Econt when admin adds order history
     * Event: admin/model/sale/order/addHistory/after
     * 
     * @param string $eventRoute
     * @param array $data
     * @return void
     */
    public function afterAdminSaleOrderAddHistory(string $eventRoute, array &$data): void {
	    if (!isset($data[0])) {
	        return;
	    }
	    
	    $orderId = (int)$data[0];
	    
	    if ($orderId <= 0) {
	        return;
	    }
	    
	    $this->load->model('setting/setting');
	    $this->load->model('sale/order');
	    
	    $settings = $this->model_setting_setting->getSetting('shipping_econt_delivery');
	    $orderData = $this->model_sale_order->getOrder($orderId);
	    
	    if (empty($orderData) || $orderData['shipping_method']['code'] !== 'econt_delivery.econt_delivery') {
	        return;
	    }
	    
	    if (empty($settings['shipping_econt_delivery_system_url']) || empty($settings['shipping_econt_delivery_private_key'])) {
		    return;
	    }
      
	    try {
		    // Get existing order from Econt
		    $curl = curl_init();
		    curl_setopt($curl, CURLOPT_URL, $settings['shipping_econt_delivery_system_url'] . '/services/OrdersService.getOrder.json');
		    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
		    curl_setopt($curl, CURLOPT_HTTPHEADER, [
			    'Content-Type: application/json',
			    'Authorization: ' . $settings['shipping_econt_delivery_private_key']
		    ]);
		    curl_setopt($curl, CURLOPT_POST, true);
		    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(['orderNumber' => $orderId]));
		    curl_setopt($curl, CURLOPT_TIMEOUT, 6);
		    $response = curl_exec($curl);
		    curl_close($curl);
		    
		    $order = json_decode($response, true);
		    
		    if (!empty($order['id'])) {
		        // Update order status
		        $order['status'] = empty($orderData['order_status_id']) || $orderData['order_status_id'] == 0 ? 'oc4_unconfirmed' : $orderData['order_status_id'];
	         
		        $curl = curl_init();
		        curl_setopt($curl, CURLOPT_URL, $settings['shipping_econt_delivery_system_url'] . '/services/OrdersService.updateOrder.json');
		        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
		        curl_setopt($curl, CURLOPT_HTTPHEADER, [
			        'Content-Type: application/json',
			        'Authorization: ' . $settings['shipping_econt_delivery_private_key']
		        ]);
		        curl_setopt($curl, CURLOPT_POST, true);
		        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($order));
		        curl_setopt($curl, CURLOPT_TIMEOUT, 6);
		        curl_exec($curl);
		        curl_close($curl);
	        }
	    } catch (\Exception $e) {
		    error_log('Econt Delivery: Failed to update order status for order ' . $orderId . ' - ' . $e->getMessage());
	    }
    }

    /**
     * Load Econt address selection modal into admin order form
     * Event: admin/view/sale/order_form/after
     * 
     * @param string $route
     * @param array $args
     * @param string $output
     * @return void
     */
    public function loadAdminOrderFormModal(string $route, array &$args, string &$output): void {
        // Load the Econt delivery integration script (which includes modal creation)
        $this->load->language('extension/econt_delivery_oc4/shipping/econt_delivery');
        $data = [
            'text_select_address' => $this->language->get('text_choose_address'),
            'user_token' => $this->session->data['user_token']
        ];
        $script = $this->load->view('extension/econt_delivery_oc4/shipping/econt_delivery_order_form_script', $data);
        
        $output .= $script;
    }

    /**
     * Get customer info parameters for iframe (Admin Order Form)
     * 
     * @return void
     */
    public function getAdminCustomerInfoParams(): void {
        $this->load->language('extension/econt_delivery_oc4/shipping/econt_delivery');
        $this->load->model('setting/setting');

        $json = [];

        try {
            $settings = $this->model_setting_setting->getSetting('shipping_econt_delivery');

            if (empty($settings['shipping_econt_delivery_system_url']) || empty($settings['shipping_econt_delivery_private_key'])) {
                throw new \Exception($this->language->get('error_configuration'));
            }

            $separatorPos = strpos($settings['shipping_econt_delivery_private_key'], '@');
            if ($separatorPos === false) {
                throw new \Exception($this->language->get('text_catalog_controller_api_extension_econt_delivery_shop_id_error'));
            }

            $shopId = substr($settings['shipping_econt_delivery_private_key'], 0, $separatorPos);
            if (intval($shopId) <= 0) {
                throw new \Exception($this->language->get('text_catalog_controller_api_extension_econt_delivery_shop_id_error'));
            }

            // Get order data from POST
            $orderWeight = (float)($this->request->post['order_weight'] ?? 0);
            $orderTotal = (float)($this->request->post['order_total'] ?? 0);
            $orderCurrency = $this->request->post['order_currency'] ?? $this->config->get('config_currency');
            
            // Get shipping address data from POST
            $firstname = $this->request->post['shipping_firstname'] ?? '';
            $lastname = $this->request->post['shipping_lastname'] ?? '';
            $company = $this->request->post['shipping_company'] ?? '';
            $address1 = $this->request->post['shipping_address_1'] ?? '';
            $address2 = $this->request->post['shipping_address_2'] ?? '';
            $city = $this->request->post['shipping_city'] ?? '';
            $postcode = $this->request->post['shipping_postcode'] ?? '';
            $countryCode = $this->request->post['shipping_country_code'] ?? 'BGR';
            $email = $this->request->post['customer_email'] ?? '';
            $phone = $this->request->post['customer_telephone'] ?? '';

            $frameParams = [
                'id_shop' => intval($shopId),
                'order_weight' => $orderWeight,
                'order_total' => $orderTotal,
                'order_currency' => $orderCurrency,
                'customer_company' => $company,
                'customer_name' => trim($firstname . ' ' . $lastname),
                'customer_phone' => $phone,
                'customer_email' => $email,
                'customer_country' => $countryCode,
                'customer_city_name' => $city,
                'customer_post_code' => $postcode,
                'customer_address' => trim($address1 . ' ' . $address2),
                'ignore_history' => true,
                'default_css' => true
            ];

            // Include existing office code if available
            $orderId = (int)($this->request->post['order_id'] ?? 0);
            if ($orderId > 0) {
                $existingInfo = $this->db->query("
                    SELECT customer_info 
                    FROM " . DB_PREFIX . "econt_delivery_customer_info 
                    WHERE id_order = " . (int)$orderId . "
                ");
                
                if ($existingInfo->num_rows) {
                    $customerInfo = json_decode($existingInfo->row['customer_info'], true);
                    if (!empty($customerInfo['office_code'])) {
                        $frameParams['customer_office_code'] = $customerInfo['office_code'];
                    }
                    if (!empty($customerInfo['zip'])) {
                        $frameParams['customer_zip'] = $customerInfo['zip'];
                    }
                }
            }

            $json['success'] = true;
            $json['iframe_url'] = $settings['shipping_econt_delivery_system_url'] . '/customer_info.php?' . http_build_query($frameParams, '', '&');
            $json['delivery_base_url'] = $settings['shipping_econt_delivery_system_url'];

        } catch (\Exception $e) {
            $json['error'] = $e->getMessage();
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * Save customer info from Econt iframe (Admin Order Form)
     * 
     * @return void
     */
    public function saveAdminCustomerInfo(): void {
        $this->load->language('extension/econt_delivery_oc4/shipping/econt_delivery');

        $json = [];

        try {
            if (empty($this->request->post)) {
                throw new \Exception($this->language->get('err_missing_customer_info'));
            }

            $customerInfo = $this->request->post;

            // Validate required fields
            if (empty($customerInfo['id']) || empty($customerInfo['name'])) {
                throw new \Exception($this->language->get('err_missing_customer_info'));
            }

            $orderId = (int)($customerInfo['order_id'] ?? 0);
            
            // Save to database immediately (cross-session accessible)
            // For new orders, use id_order = 0 with a temp key
            // For existing orders, use the actual id_order
            if ($orderId > 0) {
                // Existing order - save/update directly
                $this->db->query("
                    INSERT INTO `" . DB_PREFIX . "econt_delivery_customer_info`
                    SET id_order = " . (int)$orderId . ",
                        customer_info = '" . $this->db->escape(json_encode($customerInfo)) . "'
                    ON DUPLICATE KEY UPDATE
                        customer_info = VALUES(customer_info)
                ");
                
                $this->log->write('Econt Delivery: Saved customer info to DB for existing order #' . $orderId);
            } else {
                // New order - create temporary record with id_order = 0
                // Add unique temp_key to identify this pending order
                $tempKey = 'temp_' . $this->session->getId() . '_' . time();
                $customerInfo['temp_key'] = $tempKey;
                
                // Delete old temp records for this session (cleanup)
                $this->db->query("
                    DELETE FROM `" . DB_PREFIX . "econt_delivery_customer_info`
                    WHERE id_order = 0
                    AND customer_info LIKE '%" . $this->db->escape('temp_' . $this->session->getId()) . "%'
                ");
                
                // Insert new temp record
                $this->db->query("
                    INSERT INTO `" . DB_PREFIX . "econt_delivery_customer_info`
                    SET id_order = 0,
                        customer_info = '" . $this->db->escape(json_encode($customerInfo)) . "'
                ");
                
                // Also keep in session as backup
                $this->session->data['admin_econt_delivery']['pending_customer_info'] = $customerInfo;
                
                $this->log->write('Econt Delivery: Saved customer info to DB with temp key: ' . $tempKey);
            }

            // Calculate shipping price
            $shippingPrice = (float)($customerInfo['shipping_price'] ?? 0);
            
            // Adjust price based on payment method if provided
            if (!empty($customerInfo['payment_method'])) {
                $paymentCode = $customerInfo['payment_method'];
                
                switch ($paymentCode) {
                    case 'cod':
                        $shippingPrice = (float)($customerInfo['shipping_price_cod'] ?? $shippingPrice);
                        break;
                    case 'econt_payment':
                        $shippingPrice = (float)($customerInfo['shipping_price_cod_e'] ?? $shippingPrice);
                        break;
                }
            }

            $json['success'] = true;
            $json['customer_info'] = $customerInfo;
            $json['shipping_price'] = $shippingPrice;

        } catch (\Exception $e) {
            $json['error'] = $e->getMessage();
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

}