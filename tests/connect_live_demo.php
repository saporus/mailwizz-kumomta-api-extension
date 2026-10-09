<?php declare(strict_types=1);
/**
 * Explicit operator-only acceptance fixture; not an application route.
 * Creates only new, uniquely named QA customers/groups and synthetic demo state.
 * No list, campaign, subscriber, delivery server, API key, real grant or webhook.
 * Never resets or extends any account. Credentials go only to a private receipt.
 *
 * php connect_live_demo.php plan|create|verify|expire APP_ROOT existing|new STAMP RECEIPT [STAGED_STORE_FILE]
 * STAMP: 20261009-xxxxxxxx (random hex). RECEIPT must be the exact root-private path printed by plan.
 */
if (PHP_SAPI !== 'cli' || count($argv) < 6 || count($argv) > 7) exit(2);
[$script, $mode, $appRoot, $kind, $stamp, $receiptPath] = array_slice($argv, 0, 6);
// Six arguments including script are sufficient; the seventh is optional store.

class MagicConnectQaException extends RuntimeException {}
function connectQaFail(string $reason): void { throw new MagicConnectQaException($reason); }
function connectQaJson(array $data): string { return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"; }
function connectQaEmit(array $data): void { echo connectQaJson($data); }
function connectQaPrivateWrite($handle, array $data): void
{
    $raw = connectQaJson($data);
    if (!rewind($handle) || !ftruncate($handle, 0)) connectQaFail('private_receipt_write_failed');
    $offset = 0;
    while ($offset < strlen($raw)) { $written = fwrite($handle, substr($raw, $offset)); if ($written === false || $written === 0) connectQaFail('private_receipt_write_failed'); $offset += $written; }
    if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) connectQaFail('private_receipt_sync_failed');
}

try {
    if (!in_array($mode, ['plan', 'create', 'verify', 'expire'], true) || !in_array($kind, ['existing', 'new'], true)) connectQaFail('invalid_mode');
    if (!preg_match('/^20261009-[a-f0-9]{8}$/D', $stamp)) connectQaFail('invalid_acceptance_stamp');
    $expectedReceipt = '/root/magic-mailwizz-selfservice-demo-'.$stamp.'-'.$kind.'.json';
    if ($receiptPath !== $expectedReceipt) connectQaFail('private_receipt_path_mismatch');
    $email = 'magic-connect-qa-'.$kind.'-'.$stamp.'@example.invalid';
    $groupName = 'Magic Connect QA '.$kind.' '.$stamp;
    if ($mode === 'plan') {
        connectQaEmit(['ok'=>true, 'mode'=>'plan', 'databaseWrites'=>0, 'externalRequests'=>0,
            'customerEmail'=>$email, 'groupName'=>$groupName, 'receipt'=>$receiptPath,
            'expirySeconds'=>7200, 'scope'=>['one_new_customer','one_new_group','zero_execution_group_options','synthetic_connection_demo_state'],
            'existingAccountChanges'=>false, 'realSending'=>false, 'creationRequiresExplicitOperatorExecution'=>true]);
        exit(0);
    }
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) connectQaFail('root_required');
    if (realpath(dirname($receiptPath)) !== '/root' || is_link($receiptPath)) connectQaFail('unsafe_private_receipt_path');
    $root = realpath($appRoot);
    if (!$root || !is_file($root.'/apps/init.php')) connectQaFail('native_application_missing');
    define('MW_APP_NAME', 'console'); define('MW_RETURN_APP_INSTANCE', true);
    require $root.'/apps/init.php';
    $storeFile = $argv[6] ?? $root.'/apps/extensions/magicsmtp/models/MagicSmtpConnectStore.php';
    $storeReal = realpath($storeFile);
    if (!$storeReal || !is_file($storeReal) || is_link($storeFile)) connectQaFail('reviewed_store_file_missing');
    require_once $storeReal;
    $db = Yii::app()->getDb(); $db->setActive(true);
    $pdo = $db->getPdoInstance();
    $store = new MagicSmtpConnectStore($pdo, (string)$db->tablePrefix);
    if (!$store->installed()) connectQaFail('schema_must_be_prepared_separately');
    $prefix = (string)$db->tablePrefix;
    if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) connectQaFail('unsafe_database_prefix');
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') connectQaFail('native_mysql_expected');
    foreach (['customer','customer_group','customer_group_option','magic_smtp_connect_demo'] as $name) {
        $q=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'); $q->execute([$prefix.$name]);
        if (strtoupper((string)$q->fetchColumn()) !== 'INNODB') connectQaFail('transactional_tables_required');
    }
    $options = ['system.customer_sending.quota'=>'0','system.customer_sending.quota_time_value'=>'0',
        'system.customer_sending.action_quota_reached'=>'','system.customer_servers.can_send_from_system_servers'=>'no',
        'system.customer_servers.max_delivery_servers'=>'0','system.customer_servers.max_bounce_servers'=>'0',
        'system.customer_servers.max_fbl_servers'=>'0','system.customer_servers.max_email_box_monitors'=>'0'];
    $seed = static function(string $table, array $data) use ($db): int {
        if (!in_array($table,['customer','customer_group','customer_group_option'],true)) connectQaFail('fixture_insert_scope');
        $schema=$db->schema->getTable('{{'.$table.'}}'); if(!$schema)connectQaFail('fixture_table_missing'); $values=[];
        foreach($schema->columns as$name=>$column) {
            if($column->autoIncrement||$column->allowNull||$column->defaultValue!==null||array_key_exists($name,$data))continue;
            if(in_array($name,['date_added','last_updated'],true))$values[$name]=gmdate('Y-m-d H:i:s');
            elseif(strpos($column->dbType,'enum(')===0){preg_match("/'([^']+)'/",$column->dbType,$m);$values[$name]=$m[1];}
            elseif(in_array($column->type,['integer','double','boolean'],true))$values[$name]=0;
            else $values[$name]='';
        }
        $db->createCommand()->insert('{{'.$table.'}}',array_merge($values,$data));return(int)$db->lastInsertID;
    };
    $inspect = static function(array $receipt, bool $allowExpired=false) use ($db, $store, $email, $groupName, $options): array {
        $customer=Customer::model()->findByPk((int)$receipt['customerId']);
        if(!$customer || !hash_equals((string)$receipt['customerUid'],(string)$customer->customer_uid)
            || (string)$customer->email!==$email || (int)$customer->group_id!==(int)$receipt['groupId'])connectQaFail('fixture_identity_mismatch');
        $group=$db->createCommand('SELECT name FROM {{customer_group}} WHERE group_id=:g')->queryScalar([':g'=>$receipt['groupId']]);
        if($group!==$groupName)connectQaFail('fixture_group_mismatch');
        foreach($options as$key=>$value){$stored=$db->createCommand('SELECT value FROM {{customer_group_option}} WHERE group_id=:g AND code=:k')->queryScalar([':g'=>$receipt['groupId'],':k'=>$key]);if((string)$stored!==$value)connectQaFail('fixture_execution_options_drift');}
        if((string)$customer->getGroupOption('sending.quota','missing')!=='0'
            ||(string)$customer->getGroupOption('servers.can_send_from_system_servers','missing')!=='no'
            ||(string)$customer->getGroupOption('servers.max_delivery_servers','missing')!=='0'
            ||!$customer->getIsOverQuota())connectQaFail('native_zero_sending_quota_not_enforced');
        foreach(['delivery_server','list','campaign']as$table){$count=(int)$db->createCommand('SELECT COUNT(*) FROM {{'.$table.'}} WHERE customer_id=:c')->queryScalar([':c'=>$receipt['customerId']]);if($count!==0)connectQaFail('fixture_real_resource_drift');}
        foreach(['grant','connection']as$name){if((int)$store->query('SELECT COUNT(*) FROM '.$store->table($name).' WHERE customer_id=?',[$receipt['customerId']])->fetchColumn()!==0)connectQaFail('fixture_real_connection_drift');}
        $demo=$store->query('SELECT * FROM '.$store->table('demo').' WHERE customer_id=?',[$receipt['customerId']])->fetch(PDO::FETCH_ASSOC);
        if(!$demo||!hash_equals($receipt['customerUid'],(string)$demo['customer_uid'])||empty($customer->inactive_at)
            ||strtotime((string)$customer->inactive_at)>(int)$demo['expires_at'])connectQaFail('fixture_demo_boundary_drift');
        if(!$allowExpired&&(!$customer->getIsActive()||(int)$demo['expires_at']<=time()||strtotime((string)$customer->inactive_at)<=time()))connectQaFail('fixture_expired');
        return ['customerId'=>(int)$customer->customer_id,'customerUid'=>(string)$customer->customer_uid,
            'groupId'=>(int)$customer->group_id,'sendingDisabled'=>true,'realServers'=>0,'realGrants'=>0,'realConnections'=>0,
            'expiresAt'=>(int)$demo['expires_at'],'state'=>json_decode($demo['state_json'],true,16,JSON_THROW_ON_ERROR)];
    };
    if($mode==='create') {
        $settingsPath=dirname($root).'/private/magicsmtp-policy/connect-settings.json';
        if($kind==='existing'&&(is_file($settingsPath)||$store->enabled()))connectQaFail('existing_fixture_requires_pre_activation');
        if($kind==='new'&&(!is_file($settingsPath)||!is_file($root.'/apps/extensions/magicsmtp/MagicSmtpConnectRuntime.php')))connectQaFail('new_fixture_requires_deployed_runtime');
        if(file_exists($receiptPath)||Customer::model()->findByAttributes(['email'=>$email])
            ||$db->createCommand('SELECT COUNT(*) FROM {{customer_group}} WHERE name=:n')->queryScalar([':n'=>$groupName]))connectQaFail('already_exists_no_reset_or_retry');
        $oldMask=umask(0077);$handle=fopen($receiptPath,'x+b');umask($oldMask);if(!$handle)connectQaFail('exclusive_receipt_failed');
        chmod($receiptPath,0600);
        $password=bin2hex(random_bytes(24));$uid=StringHelper::random(13);$expires=time()+7200;
        $record=['kind'=>'magic-mailwizz-selfservice-demo-v1','fixture'=>$kind,'stamp'=>$stamp,'email'=>$email,'password'=>$password,
            'customerUid'=>$uid,'expiresAt'=>$expires,'status'=>'prepared','createdAt'=>time()];
        connectQaPrivateWrite($handle,$record);
        $tx=$db->beginTransaction();
        try{
            $group=$seed('customer_group',['name'=>$groupName]);
            foreach($options as$key=>$value)$seed('customer_group_option',['group_id'=>$group,'code'=>$key,'value'=>$value]);
            $customerData=['customer_uid'=>$uid,'group_id'=>$group,'first_name'=>'Magic Connect QA','last_name'=>ucfirst($kind),
                'email'=>$email,'password'=>passwordHasher()->hash($password),'status'=>'active','timezone'=>'UTC',
                'inactive_at'=>gmdate('Y-m-d H:i:s',$expires)];
            if(isset($db->schema->getTable('{{customer}}')->columns['email_details']))$customerData['email_details']='no';
            $cid=$seed('customer',$customerData);
            $state=$kind==='existing'?['label'=>'Existing edited sample before activation','stage'=>'connected','revision'=>3]:['label'=>'New sample connection','stage'=>'new','revision'=>0];
            $store->query('INSERT INTO '.$store->table('demo').' (customer_id,customer_uid,expires_at,state_json,updated_at) VALUES(?,?,?,?,?)',[$cid,$uid,$expires,json_encode($state,JSON_THROW_ON_ERROR),time()]);
            $record['customerId']=$cid;$record['groupId']=$group;$record['initialState']=$state;
            $check=$inspect($record);connectQaPrivateWrite($handle,$record);$tx->commit();
            $record['status']='created';connectQaPrivateWrite($handle,$record);fclose($handle);
            connectQaEmit(['ok'=>true,'mode'=>'create','fixture'=>$kind,'receipt'=>$receiptPath,'customerId'=>$cid,'groupId'=>$group,
                'expiresAt'=>$expires,'sendingDisabled'=>$check['sendingDisabled'],'externalRequests'=>0,'existingAccountsChanged'=>0,
                'credentialOutput'=>'private receipt only']);
        }catch(Throwable $e){if($tx->active)$tx->rollback();$record['status']='inspect_before_retry';connectQaPrivateWrite($handle,$record);fclose($handle);throw $e;}
    }else{
        $info=stat($receiptPath);if(!$info||$info['uid']!==0||($info['mode']&0077)!==0)connectQaFail('private_receipt_permissions');
        $record=json_decode((string)file_get_contents($receiptPath),true,32,JSON_THROW_ON_ERROR);
        if(($record['kind']??'')!=='magic-mailwizz-selfservice-demo-v1'||($record['fixture']??'')!==$kind
            ||($record['stamp']??'')!==$stamp||($record['email']??'')!==$email||!isset($record['customerId'],$record['groupId'],$record['customerUid']))connectQaFail('private_fixture_receipt_mismatch');
        $check=$inspect($record,true);
        if($mode==='expire'){
            $tx=$db->beginTransaction();try{
                $inspect($record,true);$expires=min(time()-1,$check['expiresAt']);
                $store->query('UPDATE '.$store->table('demo').' SET expires_at=? WHERE customer_id=? AND customer_uid=?',[$expires,$record['customerId'],$record['customerUid']]);
                $db->createCommand()->update('{{customer}}',['inactive_at'=>gmdate('Y-m-d H:i:s',$expires)],'customer_id=:c AND customer_uid=:u',[':c'=>$record['customerId'],':u'=>$record['customerUid']]);
                $tx->commit();$check=$inspect($record,true);
            }catch(Throwable $e){if($tx->active)$tx->rollback();throw $e;}
        }
        connectQaEmit(['ok'=>true,'mode'=>$mode,'fixture'=>$kind,'state'=>$check,'externalRequests'=>0,
            'existingAccountsChanged'=>$mode==='expire'?1:0,'scope'=>'only the exact operator-owned QA fixture from the private receipt']);
    }
}catch(Throwable $e){connectQaEmit(['ok'=>false,'errorType'=>get_class($e),'error'=>$e instanceof MagicConnectQaException?$e->getMessage():'inspect_operator_fixture_receipt','retryAutomatically'=>false]);exit(1);}
