<?php declare(strict_types=1);
defined('MW_PATH') or exit('No direct script access allowed');
require_once dirname(__DIR__).'/MagicSmtpConnectRuntime.php';

class Magic_smtp_connectController extends ExtensionController
{
    private function identity():array
    {
        if(apps()->isAppName('backend')){ $actor=MagicSmtpConnectRuntime::administrator();$id=request()->getIsPostRequest()?request()->getPost('customer_id',0):request()->getQuery('customer_id',0);if(!is_scalar($id)||!ctype_digit((string)$id))throw new CHttpException(400,'Choose a customer.');return [(int)$id,$actor,true];}
        if(!apps()->isAppName('customer'))throw new CHttpException(403,'Customer access required.');return array_merge(MagicSmtpConnectRuntime::customerIdentity(),[false]);
    }
    private function post():void
    {
        if(!request()->getIsPostRequest())throw new CHttpException(405,'POST required.');$token=request()->getPost(request()->csrfTokenName,'');if(!is_string($token)||!hash_equals(request()->csrfToken,$token))throw new CHttpException(400,'Refresh the page and retry.');
    }
    public function actionIndex()
    {
        if(!headers_sent())header('Cache-Control: no-store, private');
        [$customerId,$actor,$backend]=$this->identity();$pairing=null;$error=null;$page=null;$demoState=null;
        if(request()->getIsPostRequest()){
            $this->post();$operation=request()->getPost('operation','');
            try{
                if($operation==='enable'){
                    if(!$backend)throw new CHttpException(403,'Administrator access required.');MagicSmtpConnectRuntime::administrator();
                    $settings=MagicSmtpConnectRuntime::settings();if(!$settings)throw new RuntimeException('The installation administrator must install the protected connection settings first.');
                    new MagicSmtpConnectCrypto($settings['encryptionKey']);require_once dirname(__DIR__).'/MagicSmtpPolicyRuntime.php';
                    if(!MagicSmtpPolicyRuntime::schedulerVerified(['scheduler_manifest'=>$settings['schedulerProfile']]))throw new RuntimeException('The installed scheduler has not passed compatibility verification.');
                    MagicSmtpConnectRuntime::store()->install();MagicSmtpConnectRuntime::store()->setEnabled(true,$actor,time());notify()->addSuccess('Customer connections are enabled. Existing bindings were preserved.');
                }elseif($customerId<1)throw new InvalidArgumentException('Choose a customer first.');
                elseif(MagicSmtpConnectRuntime::demo($customerId)){
                    $demoState=MagicSmtpConnectRuntime::demoOperation($customerId,$actor,$operation==='generate'?'code':'save',['label'=>request()->getPost('label','')]);
                    if($operation==='generate')$pairing=['pairingCode'=>'DEMO-MAILWIZZ-PAIR','expiresAt'=>time()+600,'tenantName'=>'Demonstration workspace'];
                    else notify()->addSuccess('The sample connection was saved in your demonstration workspace.');
                }elseif($operation==='generate'){
                    $endpoint=request()->getPost('endpoint_id','');if(!is_scalar($endpoint)||!ctype_digit((string)$endpoint))throw new InvalidArgumentException('Choose a delivery server.');
                    $url=container()->get(OptionUrl::class)->getFrontendUrl('dswh/'.(int)$endpoint);
                    if(request()->getIsSecureConnection()&&strpos($url,'http://')===0)$url='https://'.substr($url,7);
                    $pairing=MagicSmtpConnectRuntime::service()->issueGrant($customerId,$actor,(int)$endpoint,$url);
                }else throw new InvalidArgumentException('Unknown connection action.');
            }catch(CHttpException $e){throw $e;}catch(InvalidArgumentException $e){$error=$e->getMessage();}catch(Throwable $e){$error='The connection could not be updated. Check account access, server eligibility and installation readiness, then retry.';}
        }
        if($customerId>0)$page=MagicSmtpConnectRuntime::page($customerId);
        $this->setData(['pageMetaTitle'=>'Connect MailWizz','pageHeading'=>'Connect MailWizz','pageBreadcrumbs'=>['Connect MailWizz']]);
        $this->render('ext-magicsmtp.views.connect',compact('customerId','backend','pairing','error','page'));
    }
}
