<?php declare(strict_types=1);
defined('MW_PATH') or exit('No direct script access allowed');

/** Only an operator-approved identity endpoint may receive a stored sending credential. */
final class MagicSmtpConnectHttp
{
    public static function apiBase(string $endpoint): string
    {
        if ($endpoint==='' || strlen($endpoint)>2048 || preg_match('/[\s\\\\%\x00-\x1f\x7f]/',$endpoint)) return '';
        $p=parse_url($endpoint);
        if (!$p || ($p['scheme']??'')!=='https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])
            || (isset($p['port']) && (int)$p['port']!==443) || ($p['path']??'')!=='/ui/api/ingest/email') return '';
        $host=strtolower($p['host']);
        if (strlen($host)>253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',$host)
            || filter_var($host,FILTER_VALIDATE_IP)) return '';
        return 'https://'.$host.'/ui/api';
    }

    public static function publicAddress(string $ip): bool
    {
        if (!filter_var($ip,FILTER_VALIDATE_IP) || strpos($ip,'%')!==false) return false;
        $packed=inet_pton($ip); if ($packed===false) return false;
        if (strlen($packed)===4) {
            $bytes=unpack('C4',$packed); $a=$bytes[1];$b=$bytes[2];$c=$bytes[3];
            return !($a===0 || $a===10 || $a===127 || $a>=224 || ($a===100 && $b>=64 && $b<=127)
                || ($a===169 && $b===254) || ($a===172 && $b>=16 && $b<=31)
                || ($a===192 && ($b===168 || ($b===0 && ($c===0 || $c===2)) || ($b===88 && $c===99)))
                || ($a===198 && ($b===18 || $b===19 || ($b===51 && $c===100))) || ($a===203 && $b===0 && $c===113));
        }
        if (strlen($packed)!==16 || strpos($ip,'.')!==false) return false;
        $words=unpack('n8',$packed); $a=$words[1];$b=$words[2];
        // Global unicast only; reject transition mechanisms and special-purpose ranges.
        if (($a&0xe000)!==0x2000) return false;
        return !(($a===0x2001 && ($b<0x0200 || $b===0x0db8)) || $a===0x2002 || $a===0x3ffe || ($a===0x3fff && ($b&0xf000)===0));
    }

    /** Test dependencies are injected only when constructing this client, never via request input. */
    public static function createClient(callable $resolve,callable $transport,?callable $clock=null): callable
    {
        $clock=$clock??static function():float{return microtime(true);};
        return static function(array $server,string $base,string $nonce,array $approvedBases,int $timeoutMs=8000)use($resolve,$transport,$clock):array {
            if ($timeoutMs<1 || $timeoutMs>30000) throw new RuntimeException('Invalid identity request time budget',422);
            $deadline=$clock()+$timeoutMs/1000;
            if ($base!==self::apiBase($base.'/ingest/email') || !in_array($base,$approvedBases,true)
                || $base!==($server['apiBaseUrl']??null) || empty($server['tlsVerified'])
                || !is_string($server['key']??null) || !preg_match('/^(?:magicsmtp|kumo)_tp_[A-Za-z0-9_-]{16,400}$/D',$server['key'])
                || !preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$nonce)) throw new RuntimeException('Identity endpoint is not approved',403);
            $host=parse_url($base,PHP_URL_HOST);
            try { $addresses=$resolve($host); } catch (Throwable $error) { throw new RuntimeException('Identity lookup unavailable',503); }
            $remaining=(int)floor(($deadline-$clock())*1000);
            // Native PHP DNS may not be interruptible; a lookup consuming the budget
            // must never start a later HTTP request or send credentials afterward.
            if ($remaining<1) throw new RuntimeException('Identity verification timed out',409);
            if (!is_array($addresses) || !$addresses || count($addresses)>64) throw new RuntimeException('Identity lookup unavailable',503);
            foreach ($addresses as $ip) if (!is_string($ip) || !self::publicAddress($ip)) throw new RuntimeException('Identity endpoint must have public addresses',403);
            $ip=$addresses[0];
            $resolveEntry=$host.':443:'.(strpos($ip,':')!==false?'['.$ip.']':$ip);
            $options=[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['nonce'=>$nonce],JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','Accept-Encoding: identity','X-Tenant-Api-Key: '.$server['key']],
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_CONNECTTIMEOUT_MS=>min(3000,$remaining),CURLOPT_TIMEOUT_MS=>$remaining,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_SSLVERSION=>CURL_SSLVERSION_TLSv1_2,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_RESOLVE=>[$resolveEntry],CURLOPT_IPRESOLVE=>strpos($ip,':')!==false?CURL_IPRESOLVE_V6:CURL_IPRESOLVE_V4,
                CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*'];
            try { $response=$transport($base.'/mailwizz-integration/identity',$options); }
            catch (Throwable $error) { throw new RuntimeException('Identity verification was not accepted',409); }
            if ($clock()>$deadline || !is_array($response) || ($response['status']??null)!==200 || !is_string($response['body']??null)
                || strlen($response['body'])>8192 || !is_string($response['peerIp']??null)
                || @inet_pton($response['peerIp'])!==inet_pton($ip)) throw new RuntimeException('Identity verification was not accepted',409);
            try { $result=json_decode($response['body'],true,16,JSON_THROW_ON_ERROR); }
            catch (Throwable $error) { throw new RuntimeException('Identity response rejected',409); }
            if (!is_array($result) || ($result['ok']??null)!==true || ($result['nonce']??null)!==$nonce || ($result['apiBaseUrl']??null)!==$base)
                throw new RuntimeException('Identity response rejected',409);
            return $result;
        };
    }

    public static function identity(array $server,string $base,string $nonce,array $approvedBases,int $timeoutMs=8000): array
    {
        if (!extension_loaded('curl') || !function_exists('curl_init')) throw new RuntimeException('Identity client unavailable',503);
        $client=self::createClient(static function(string $host):array {
            $records=@dns_get_record($host,DNS_A|DNS_AAAA);$addresses=[];
            if (!is_array($records)) throw new RuntimeException('Identity lookup unavailable',503);
            foreach ($records as $row) { $ip=$row['ip']??$row['ipv6']??null; if ($ip!==null) $addresses[]=$ip; }
            return array_values(array_unique($addresses));
        },static function(string $url,array $options):array {
            $curl=curl_init($url); if (!$curl) throw new RuntimeException('Identity client unavailable',503);
            $received='';$overflow=false;
            $options[CURLOPT_WRITEFUNCTION]=static function($handle,string $chunk)use(&$received,&$overflow):int {
                if (strlen($received)+strlen($chunk)>8192) {$overflow=true;return 0;}
                $received.=$chunk;return strlen($chunk);
            };
            try {
                if (!curl_setopt_array($curl,$options)) throw new RuntimeException('Identity client unavailable',503);
                $ok=curl_exec($curl);
                if ($ok===false || $overflow) throw new RuntimeException('Identity verification was not accepted',409);
                return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$received,'peerIp'=>(string)curl_getinfo($curl,CURLINFO_PRIMARY_IP)];
            } finally { curl_close($curl); }
        });
        return $client($server,$base,$nonce,$approvedBases,$timeoutMs);
    }
}
