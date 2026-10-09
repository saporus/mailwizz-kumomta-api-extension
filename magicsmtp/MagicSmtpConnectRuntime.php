<?php declare(strict_types=1);
defined('MW_PATH') or exit('No direct script access allowed');
require_once __DIR__.'/models/MagicSmtpConnectService.php';
require_once __DIR__.'/models/MagicSmtpConnectHttp.php';

/** Native application adapter. No enrollment/install work happens from a callback or page read. */
final class MagicSmtpConnectRuntime
{
    private static $store;
    public static function settings():?array
    {
        $settings=function_exists('app_param')?app_param('magicsmtp.connect',null):null;
        if($settings===null){$path=dirname(MW_PATH).'/private/magicsmtp-policy/connect-settings.json';if(!is_file($path))return null;$settings=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);}
        if(!is_array($settings)||!is_string($settings['encryptionKey']??null)||!is_array($settings['schedulerProfile']??null)||!is_array($settings['approvedApiBases']??null)||!$settings['approvedApiBases'])throw new RuntimeException('Installation connection settings are incomplete');
        return $settings;
    }
    public static function staticBindings():array
    {$b=function_exists('app_param')?app_param('magicsmtp.policyBridges',[]):[];if(!is_array($b))throw new RuntimeException('Protected binding configuration rejected');return $b;}
    public static function store():MagicSmtpConnectStore
    {if(!self::$store){$db=Yii::app()->getDb();$db->setActive(true);self::$store=new MagicSmtpConnectStore($db->getPdoInstance(),(string)$db->tablePrefix);}return self::$store;}
    public static function service():MagicSmtpConnectService
    {
        $settings=self::settings();if(!$settings)throw new RuntimeException('An installation administrator must configure secure pairing first');
        return new MagicSmtpConnectService(self::store(),new MagicSmtpConnectCrypto($settings['encryptionKey']),self::staticBindings(),$settings['schedulerProfile'],[self::class,'nativeServers'],static function(array $server,string $base,string $nonce,int $timeoutMs=8000)use($settings):array{return MagicSmtpConnectHttp::identity($server,$base,$nonce,$settings['approvedApiBases'],$timeoutMs);},null,static function()use($settings):bool{require_once __DIR__.'/MagicSmtpPolicyRuntime.php';return MagicSmtpPolicyRuntime::schedulerVerified(['scheduler_manifest'=>$settings['schedulerProfile']]);});
    }
    public static function bindings():array
    {
        // Existing installations remain static-only until their one-time protected settings exist.
        if(self::settings()===null)return self::staticBindings();
        return self::service()->effectiveBindings();
    }
    public static function customerModel(int $id)
    {
        $customer=Customer::model()->findByPk($id);
        if(!$customer||!$customer->getIsActive()||(!empty($customer->inactive_at)&&strtotime((string)$customer->inactive_at)<=time()))throw new CHttpException(403,'Customer access is inactive or expired.');return $customer;
    }
    public static function customerIdentity():array
    {
        if(customer()->isGuest)throw new CHttpException(403,'Sign in to manage your connection.');
        $id=(int)customer()->getId();$model=self::customerModel($id);$actor=$id;
        if(is_subaccount()){if(!subaccount()->customer()||!subaccount()->canManageServers())throw new CHttpException(403,'Delivery server management permission is required.');$actor=(int)subaccount()->customer()->customer_id;}
        $demo=self::demo($id);
        if(!$demo&&!(int)$model->getGroupOption('servers.max_delivery_servers',0))throw new CHttpException(403,'Delivery server access is not enabled for this account.');
        return [$id,$actor];
    }
    public static function administrator():int
    {
        if(!apps()->isAppName('backend')||user()->isGuest)throw new CHttpException(403,'Administrator access required.');
        $model=user()->getModel();if(!$model||$model->status!=='active'||!$model->hasRouteAccess('extensions/update')||!$model->hasRouteAccess('delivery_servers/update'))throw new CHttpException(403,'Extension and delivery server administration permissions are required.');return (int)$model->user_id;
    }
    public static function nativeServers(int $customer):array
    {
        self::customerModel($customer);if(self::demo($customer))throw new RuntimeException('Demo workspaces cannot access production credentials',403);
        $ids=[];foreach(self::staticBindings()as$b)if((int)$b['customer_id']===$customer)$ids=array_merge($ids,$b['server_ids']);
        $criteria=new CDbCriteria();$criteria->addCondition('customer_id=:connectCustomer'.($ids?' OR server_id IN ('.implode(',',array_map('intval',$ids)).')':''));$criteria->params=[':connectCustomer'=>$customer];$criteria->limit=101;$criteria->order='server_id ASC';
        $rows=DeliveryServer::model()->findAll($criteria);if(count($rows)>100)throw new RuntimeException('Too many servers to inspect in one connection');$out=[];
        foreach($rows as$s){
            $api=$s->type==='magic-smtp-web-api'?DeliveryServerMagicSmtpWebApi::model()->findByPk($s->server_id):null;
            $base='';if($api){try{$base=MagicSmtpConnectHttp::apiBase((string)$api->hostname);}catch(Throwable $invalidEndpoint){/* An ineligible row must not hide the remaining inventory. */}}
            $out[]=['id'=>(int)$s->server_id,'customerId'=>(int)$s->customer_id,'name'=>(string)$s->name,'type'=>(string)$s->type,'status'=>(string)$s->status,'apiBaseUrl'=>$base,'tlsVerified'=>$base!==''&&$api&&!(int)$api->disable_ssl,'key'=>$api?(string)$api->password:''];
        }
        return $out;
    }
    public static function demo(int $customer):?array
    {
        if(self::settings()===null||!self::store()->installed())return null;
        $r=self::store()->query('SELECT * FROM '.self::store()->table('demo').' WHERE customer_id=?',[$customer])->fetch(PDO::FETCH_ASSOC);if(!$r)return null;
        $owner=self::customerModel($customer);
        if(!hash_equals((string)$r['customer_uid'],(string)$owner->customer_uid)||(int)$r['expires_at']<=time()||empty($owner->inactive_at)||strtotime((string)$owner->inactive_at)>(int)$r['expires_at'])throw new CHttpException(403,'The demonstration workspace is expired or restricted.');
        if((string)$owner->getGroupOption('sending.quota','missing')!=='0'||(string)$owner->getGroupOption('servers.can_send_from_system_servers','missing')!=='no')throw new CHttpException(403,'Demonstration execution restrictions changed.');
        $db=Yii::app()->getDb();if((int)$db->createCommand('SELECT COUNT(*) FROM {{delivery_server}} WHERE customer_id=:c')->queryScalar([':c'=>$customer])>0)throw new CHttpException(403,'Demonstration workspace contains real delivery servers.');
        $r['state']=json_decode($r['state_json'],true,16,JSON_THROW_ON_ERROR);return $r;
    }
    public static function demoOperation(int $customer,int $actor,string $operation,array $input=[]):array
    {
        $demo=self::demo($customer);if(!$demo)throw new CHttpException(403,'Demonstration workspace is not registered.');$state=$demo['state'];
        if($operation==='code'){$state['lastCodeAt']=time();if(($state['stage']??'')!=='connected')$state['stage']='code_ready';}
        elseif($operation==='save'){$label=$input['label']??'';if(!is_string($label)||trim($label)===''||strlen($label)>100)throw new InvalidArgumentException('Enter a connection name of up to 100 characters.');$state['label']=trim($label);$state['stage']='connected';$state['revision']=(int)($state['revision']??0)+1;}
        else throw new InvalidArgumentException('Unknown demonstration action.');
        self::store()->atomic(static function()use($customer,$actor,$state):void{self::demo($customer);self::store()->query('UPDATE '.self::store()->table('demo').' SET state_json=?,updated_at=? WHERE customer_id=?',[json_encode($state,JSON_THROW_ON_ERROR),time(),$customer]);self::store()->audit($customer,$actor,'demo_save','',['stage'=>$state['stage']],time());});
        return $state;
    }
    public static function page(int $customer):array
    {
        self::customerModel($customer);$demo=self::demo($customer);
        if($demo)return ['demo'=>true,'demoState'=>$demo['state'],'enabled'=>true,'servers'=>[['id'=>1,'name'=>'Sample newsletter server','status'=>'active','connected'=>($demo['state']['stage']??'')==='connected','eligible'=>true]],'connections'=>[]];
        $configured=self::settings()!==null;$installed=self::store()->installed();$connections=$installed?self::store()->connections($customer):[];$active=[];
        foreach(self::staticBindings()as$b)if((int)$b['customer_id']===$customer)$active=array_merge($active,$b['server_ids']);
        foreach($connections as$c)$active=array_merge($active,json_decode($c['server_ids'],true,16,JSON_THROW_ON_ERROR));$servers=[];
        foreach(self::nativeServers($customer)as$s){$legacy=in_array($s['id'],$active,true);$eligible=$s['customerId']===$customer&&$s['type']==='magic-smtp-web-api'&&in_array($s['status'],['active','in-use'],true)&&$s['tlsVerified']&&$configured&&in_array($s['apiBaseUrl'],self::settings()['approvedApiBases'],true);$servers[]=['id'=>$s['id'],'name'=>$s['name'],'status'=>$s['status'],'connected'=>$legacy,'eligible'=>$eligible,'codeEndpoint'=>($eligible||$legacy)&&$s['type']==='magic-smtp-web-api'&&in_array($s['status'],['active','in-use'],true)&&$s['tlsVerified']];}
        $safe=[];foreach($connections as$c)$safe[]=['connectionId'=>$c['connection_id'],'tenantName'=>$c['tenant_name'],'state'=>$c['state'],'revision'=>(int)$c['revision'],'serverIds'=>json_decode($c['server_ids'],true,16,JSON_THROW_ON_ERROR)];
        return ['demo'=>false,'enabled'=>$configured&&$installed&&self::store()->enabled(),'servers'=>$servers,'connections'=>$safe,'configured'=>$configured,'installed'=>$installed];
    }
    public static function management(int $endpoint,string $raw,string $signature):array
    {
        // Demo credentials and workspaces never reach this production service.
        return self::service()->handle($endpoint,$raw,$signature);
    }
}
