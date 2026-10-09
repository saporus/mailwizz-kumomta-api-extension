<?php
defined('MW_PATH') or exit('No direct script access allowed');

/**
 * MagicsmtpExt bootstrap class for MailWizz.
 * Registers the Magic SMTP Delivery Server types.
 *
 * Author: Omni Knoweth
 */
class MagicsmtpExt extends ExtensionInit
{
    // Unique extension identifier
    public $name = 'Magic SMTP Web API Delivery Server';

    // Brief description
    public $description = 'Connects MailWizz to the Omni Knoweth Enterprise KumoMTA API, including webhook processing.';

    // Extension version
    public $version = '1.3.0';

    // Minimum MailWizz version required
    public $minAppVersion = '2.7.3';

    // Author name
    public $author = 'Omni Knoweth';

    // Author website
    public $website = 'https://www.omniknoweth.com/';

    // Enable extension in CLI/cron context
    public $cliEnabled = true;

    // Applications where this extension is allowed to run
    public $allowedApps = array('*');

    /** Additive schema installation uses the normal administrator enable/update workflow. */
    public function beforeEnable()
    {
        $this->installPolicySchema();
        return true;
    }

    public function update()
    {
        $this->installPolicySchema();
        return true;
    }

    private function installPolicySchema()
    {
        require_once dirname(__FILE__) . '/models/MagicSmtpPolicyStore.php';
        $db = Yii::app()->db;
        $db->setActive(true);
        (new MagicSmtpPolicyStore($db->getPdoInstance(), (string)$db->tablePrefix))->install();
        require_once dirname(__FILE__) . '/models/MagicSmtpConnectStore.php';
        (new MagicSmtpConnectStore($db->getPdoInstance(), (string)$db->tablePrefix))->install();
    }

    /**
     * Bootstrap the extension
     */
    public function run()
    {
        // Explicitly register the path alias to guarantee class loading works on all platforms
        Yii::setPathOfAlias('ext-magicsmtp', dirname(__FILE__));

        // Import models directory and explicitly require the DeliveryServer classes
        Yii::import('ext-magicsmtp.models.*');
        require_once dirname(__FILE__) . '/models/DeliveryServerMagicSmtp.php';
        require_once dirname(__FILE__) . '/models/DeliveryServerMagicSmtpWebApi.php';

        // Add filters to register the custom delivery server types
        Yii::app()->hooks->addFilter('delivery_servers_get_types_mapping', array($this, '_registerDeliveryServerType'));
        Yii::app()->hooks->addFilter('delivery_servers_get_types_map', array($this, '_registerDeliveryServerType'));

        // Map the configuration form view
        Yii::app()->hooks->addFilter('delivery_servers_form_view_file', array($this, '_registerDeliveryServerFormView'));

        // Explain MTA acceptance in delivery reports without changing send outcomes.
        require_once dirname(__FILE__) . '/MagicSmtpDeliveryReportClarity.php';
        Yii::app()->hooks->addFilter('grid_view_properties', array('MagicSmtpDeliveryReportClarity', 'gridProperties'));
        require_once dirname(__FILE__) . '/MagicSmtpPolicyRuntime.php';
        Yii::app()->hooks->addFilter('grid_view_properties', array('MagicSmtpPolicyRuntime', 'gridProperties'));

        // Register webhook processing through MailWizz's supported DSWH hook.
        Yii::app()->hooks->addFilter('dswh_process_map', array($this, '_registerDswhProcessor'));

        if ($this->isAppName('customer') || $this->isAppName('backend')) {
            require_once dirname(__FILE__) . '/MagicSmtpConnectRuntime.php';
            Yii::app()->controllerMap['magic_smtp_connect'] = array('class' => 'ext-magicsmtp.controllers.Magic_smtp_connectController');
            hooks()->addFilter($this->isAppName('backend') ? 'backend_left_navigation_menu_items' : 'customer_left_navigation_menu_items', array($this, '_connectMenu'));
        }
    }

    public function _connectMenu(array $items): array
    {
        try {
            if ($this->isAppName('backend')) MagicSmtpConnectRuntime::administrator();
            else MagicSmtpConnectRuntime::customerIdentity();
        } catch (Throwable $failure) { return $items; }
        $items['magic-smtp-connect'] = array('name' => 'Connect MailWizz', 'icon' => 'glyphicon-link', 'active' => 'magic_smtp_connect', 'route' => array('magic_smtp_connect/index'));
        return $items;
    }

    /**
     * Add the magic-smtp and magic-smtp-web-api types to the delivery server types map
     */
    public function _registerDeliveryServerType(array $types = array())
    {
        $types['magic-smtp'] = 'DeliveryServerMagicSmtp';
        $types['magic-smtp-web-api'] = 'DeliveryServerMagicSmtpWebApi';
        return $types;
    }

    /**
     * Register the Magic SMTP webhook processor in MailWizz's DSWH map.
     *
     * @param array          $map
     * @param DeliveryServer $server
     * @param Controller     $controller
     * @return array
     */
    public function _registerDswhProcessor(array $map, $server, $controller)
    {
        if (in_array($server->type, array('magic-smtp', 'magic-smtp-web-api'), true)) {
            $map[$server->type] = array($this, '_processDswhWebhook');
        }

        return $map;
    }

    /**
     * Process a Magic SMTP webhook selected by MailWizz's DSWH controller.
     *
     * @param DeliveryServer $server
     * @param Controller     $controller
     * @return void
     */
    public function _processDswhWebhook($server, $controller)
    {
        $handler = new DeliveryServerMagicSmtp();
        $handler->server_id = (int)$server->server_id;
        $handler->handleCallback(request());
    }

    /**
     * Map the form view to standard or custom views
     */
    public function _registerDeliveryServerFormView($viewFile, $server, $controller)
    {
        if ($server->type === 'magic-smtp') {
            return 'ext-magicsmtp.views.form-magic-smtp';
        }
        if ($server->type === 'magic-smtp-web-api') {
            return 'ext-magicsmtp.views.form-magic-smtp';
        }
        return $viewFile;
    }
}
