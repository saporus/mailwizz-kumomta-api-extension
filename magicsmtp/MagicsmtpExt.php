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
    public $version = '1.1.1';

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

        // Add the webhook action to MailWizz's frontend controller when it is missing.
        // The repository intentionally does not redistribute MailWizz's proprietary
        // controller source; only this small extension-owned method is inserted.
        $destController = Yii::getPathOfAlias('common') . '/../frontend/controllers/DswhController.php';
        if (file_exists($destController)) {
            $destContent = file_get_contents($destController);
            if (strpos($destContent, 'actionMagicsmtp') === false) {
                $target = 'public function actionNewsman()';
                if (strpos($destContent, $target) !== false) {
                    $replacement = "    /**\n     * Process Magic SMTP (KumoMTA) Webhooks\n     */\n    public function actionMagicsmtp()\n    {\n        \$server = new DeliveryServerMagicSmtp();\n        \$server->handleCallback(request());\n    }\n\n    public function actionNewsman()";
                    $newContent = str_replace($target, $replacement, $destContent);
                    @file_put_contents($destController, $newContent);
                }
            }
        }
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
