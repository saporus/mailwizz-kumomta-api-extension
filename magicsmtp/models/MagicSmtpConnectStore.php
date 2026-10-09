<?php declare(strict_types=1);
defined('MW_PATH') or exit('No direct script access allowed');

/** Additive connection records. Installation is always an explicit administrator action. */
final class MagicSmtpConnectStore
{
    private $pdo;
    private $prefix;
    public function __construct(PDO $pdo, string $prefix='')
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/D',$prefix)) throw new InvalidArgumentException('Invalid database prefix');
        $this->pdo=$pdo;$this->prefix=$prefix;$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    }
    public function table(string $name): string
    {
        if (!in_array($name,['config','grant','connection','operation','audit','demo'],true)) throw new InvalidArgumentException('Invalid connection table');
        return $this->prefix.'magic_smtp_connect_'.$name;
    }
    public function query(string $sql,array $args=[]): PDOStatement
    {
        $q=$this->pdo->prepare($sql);if (!$q||!$q->execute($args)) throw new RuntimeException('Connection storage unavailable');return $q;
    }
    public function install(): void
    {
        $suffix=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin':'';
        $tables=[
            'config'=>'id INTEGER PRIMARY KEY, enabled INTEGER NOT NULL, updated_at BIGINT NOT NULL',
            'grant'=>'token_hash VARCHAR(64) PRIMARY KEY, customer_id INTEGER NOT NULL, endpoint_id INTEGER NOT NULL, api_base VARCHAR(255) NOT NULL, tenant_id VARCHAR(128) NOT NULL, static_bridge_id VARCHAR(128) NOT NULL, server_fingerprint VARCHAR(64) NOT NULL, created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL, claimed_operation VARCHAR(128) NULL',
            'connection'=>'connection_id VARCHAR(64) PRIMARY KEY, customer_id INTEGER NOT NULL, endpoint_id INTEGER NOT NULL, api_base VARCHAR(255) NOT NULL, tenant_id VARCHAR(128) NOT NULL, tenant_name VARCHAR(255) NOT NULL, bridge_id VARCHAR(128) NOT NULL UNIQUE, webhook_id VARCHAR(128) NOT NULL, secret_box TEXT NOT NULL, static_hash VARCHAR(64) NOT NULL, server_ids TEXT NOT NULL, revision INTEGER NOT NULL, state VARCHAR(24) NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL',
            'operation'=>'operation_id VARCHAR(128) NOT NULL, action VARCHAR(16) NOT NULL, connection_id VARCHAR(64) NOT NULL, request_hash VARCHAR(64) NOT NULL, state VARCHAR(24) NOT NULL, response_body TEXT NULL, response_status INTEGER NULL, candidate TEXT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, PRIMARY KEY(operation_id,action)',
            'audit'=>'audit_id VARCHAR(64) PRIMARY KEY, customer_id INTEGER NOT NULL, actor_id INTEGER NOT NULL, action VARCHAR(32) NOT NULL, connection_id VARCHAR(64) NOT NULL, details TEXT NOT NULL, created_at BIGINT NOT NULL',
            'demo'=>'customer_id INTEGER PRIMARY KEY, customer_uid VARCHAR(32) NOT NULL, expires_at BIGINT NOT NULL, state_json TEXT NOT NULL, updated_at BIGINT NOT NULL',
        ];
        foreach($tables as$name=>$columns)$this->query('CREATE TABLE IF NOT EXISTS '.$this->table($name).' ('.$columns.')'.$suffix);
        if(!$this->query('SELECT id FROM '.$this->table('config').' WHERE id=1')->fetchColumn())$this->query('INSERT INTO '.$this->table('config').' (id,enabled,updated_at) VALUES(1,0,?)',[time()]);
    }
    public function installed(): bool
    {
        try{return (bool)$this->query('SELECT id FROM '.$this->table('config').' WHERE id=1')->fetchColumn();}catch(PDOException $e){return false;}
    }
    public function enabled(): bool{return (int)$this->query('SELECT enabled FROM '.$this->table('config').' WHERE id=1')->fetchColumn()===1;}
    public function setEnabled(bool $enabled,int $actor,int $now): void
    {
        $this->atomic(function()use($enabled,$actor,$now):void{$this->query('UPDATE '.$this->table('config').' SET enabled=?,updated_at=? WHERE id=1',[$enabled?1:0,$now]);$this->audit(0,$actor,$enabled?'enable_enrollment':'disable_enrollment','',[],$now);});
    }
    /** Serialize short local mutations only. No HTTP or DNS runs while this lock is held. */
    public function atomic(callable $operation)
    {
        if($this->pdo->inTransaction())throw new RuntimeException('Nested connection transaction rejected');
        $sqlite=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
        if($sqlite)$this->pdo->exec('BEGIN IMMEDIATE');else{$this->pdo->beginTransaction();$this->query('SELECT id FROM '.$this->table('config').' WHERE id=1 FOR UPDATE')->fetchColumn();}
        try{$value=$operation();if($sqlite)$this->pdo->exec('COMMIT');else $this->pdo->commit();return $value;}
        catch(Throwable $e){try{if($sqlite)$this->pdo->exec('ROLLBACK');elseif($this->pdo->inTransaction())$this->pdo->rollBack();}catch(Throwable $ignored){}throw $e;}
    }
    public function grant(string $hash): ?array{$r=$this->query('SELECT * FROM '.$this->table('grant').' WHERE token_hash=?',[$hash])->fetch(PDO::FETCH_ASSOC);return $r?:null;}
    public function connection(string $id): ?array{$r=$this->query('SELECT * FROM '.$this->table('connection').' WHERE connection_id=?',[$id])->fetch(PDO::FETCH_ASSOC);return $r?:null;}
    public function operation(string $id,string $action): ?array{$r=$this->query('SELECT * FROM '.$this->table('operation').' WHERE operation_id=? AND action=?',[$id,$action])->fetch(PDO::FETCH_ASSOC);return $r?:null;}
    public function connections(?int $customer=null): array
    {
        return $this->query('SELECT * FROM '.$this->table('connection').($customer===null?'':' WHERE customer_id=?').' ORDER BY created_at,connection_id LIMIT 101',$customer===null?[]:[$customer])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function audit(int $customer,int $actor,string $action,string $connection,array $details,int $now): void
    {
        // Callers provide identifiers/counts only, never tokens, API keys or raw envelopes.
        $this->query('INSERT INTO '.$this->table('audit').' (audit_id,customer_id,actor_id,action,connection_id,details,created_at) VALUES(?,?,?,?,?,?,?)',[bin2hex(random_bytes(16)),$customer,$actor,$action,$connection,json_encode($details,JSON_THROW_ON_ERROR),$now]);
    }
}

/** Secrets remain encrypted in the database; the installation key is outside the webroot. */
final class MagicSmtpConnectCrypto
{
    private $key;
    public function __construct(string $encodedKey)
    {
        $key=base64_decode($encodedKey,true);if($key===false||strlen($key)!==32)throw new RuntimeException('Connection encryption is not configured');$this->key=$key;
    }
    public function seal(string $plain,string $context): string
    {
        $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$iv,$tag,$context,16);
        if($cipher===false)throw new RuntimeException('Connection encryption failed');return 'v1.'.base64_encode($iv.$tag.$cipher);
    }
    public function open(string $box,string $context): string
    {
        $raw=strncmp($box,'v1.',3)===0?base64_decode(substr($box,3),true):false;
        if($raw===false||strlen($raw)<29)throw new RuntimeException('Connection secret unavailable');
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$context);
        if($plain===false)throw new RuntimeException('Connection secret unavailable');return $plain;
    }
}
