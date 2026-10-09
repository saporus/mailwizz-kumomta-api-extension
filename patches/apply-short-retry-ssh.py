"""Explicit reviewed v3 apply, or read-only reconciliation. Strict known SSH host."""
from pathlib import Path
import argparse, importlib.util, json, re, sys

BASE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('short_retry_upload',BASE/'upload-test-short-retry-ssh.py')
upload=importlib.util.module_from_spec(spec);spec.loader.exec_module(upload)

def execute(mode):
    _,_,plan=upload.plan()
    data={'mode':mode,'stage':upload.STAGE,'backup':upload.BACKUP,'releaseSha':upload.RELEASE_SHA,'deploySha':upload.DEPLOY_SHA,
          'applyCommand':plan['deploymentCommands']['apply'],
          'campaignPhp':(BASE/'read-short-retry-campaign-status.php').read_text(),
          'baselinePy':(BASE/'read-short-retry-baseline.py').read_text(),
          'runtimePhp':(BASE/'read-short-retry-runtime.php').read_text()}
    remote='REQUEST='+repr(data)+'\n'+r'''
from pathlib import Path
import datetime,hashlib,json,os,re,subprocess
R=REQUEST;S=Path(R['stage']);B=Path(R['backup']);report={'ok':False,'mode':R['mode'],'at':datetime.datetime.now(datetime.timezone.utc).isoformat()}
sha=lambda p:hashlib.sha256(Path(p).read_bytes()).hexdigest()
def require(v,m):
    if not v:raise RuntimeError(m)
def run(args,source=None,env=None):
    p=subprocess.run(args,input=source.encode() if source is not None else None,capture_output=True,timeout=45,env=env)
    require(p.returncode==0 and not p.stderr,'Bounded command failed; stderr withheld')
    out=json.loads(p.stdout);require(out.get('ok'),'Remote check returned failure');return out
def campaign():return run(['php8.1'],R['campaignPhp'])
def baseline():return run(['python3','-'],R['baselinePy'])
def runtime():
    pool=Path('/etc/php/8.1/fpm/pool.d/servermail2.com.conf').read_text()
    user=re.search(r'^\s*user\s*=\s*(\S+)',pool,re.M);group=re.search(r'^\s*group\s*=\s*(\S+)',pool,re.M)
    directory=re.search(r'^\s*php_admin_value\[open_basedir\]\s*=\s*(.+)',pool,re.M)
    require(user and group and user[1]=='admin' and group[1]=='admin' and directory,'Worker settings drift')
    require('/home/admin/web/servermail2.com/private' in directory[1].strip().split(':'),'Private path outside worker open_basedir')
    result=run(['runuser','-u','admin','--','php8.1','-c','/etc/php/8.1/fpm/php.ini','-d','opcache.enable_cli=0','-d','open_basedir='+directory[1].strip()],R['runtimePhp'],{**os.environ,'PHP_INI_SCAN_DIR':'/etc/php/8.1/fpm/conf.d'})
    require(result['mailwizzVersion']=='2.7.3' and result['sourceVersion']=='1.2.0' and result['registryVersion']=='1.2.0' and result['mustUpdate'] is False,'Runtime version drift')
    require(result['bindingCount']==1 and all(x['schedulerVerified'] for x in result['checks']),'Scheduler readiness failure')
    result['workerUser']='admin';return result
try:
    require(sha(S/'release-manifest.json')==R['releaseSha'] and sha(S/'deploy-short-retry.py')==R['deploySha'],'Staged helper/manifest drift')
    manifest=json.loads((S/'release-manifest.json').read_text())
    target=json.loads((S/'target-runtime-receipt.json').read_text());require(target['ok'],'Target acceptance missing')
    report['campaignBefore']=campaign();report['filesBefore']=baseline()
    if R['mode']=='apply':
        require(report['campaignBefore']['campaign'][0]['status']=='paused','Campaign pause changed; stop')
        require(not report['campaignBefore']['workers'],'Workers active; stop')
        report['apply']=run(['python3',str(S/'deploy-short-retry.py'),'apply',str(S),str(B),R['releaseSha']])
    report['filesAfter']=baseline();report['campaignAfter']=campaign()
    for name,item in manifest['files'].items():require(report['filesAfter']['files'][name]['sha256']==item['afterSha256'],'Installed hash mismatch')
    for name,item in manifest['guards'].items():require(report['filesAfter']['guards'][name]['sha256']==item['sha256'],'Untouched guard mismatch')
    report['runtime']=runtime()
    require(report['campaignAfter']['campaign'][0]['status']=='paused','Postflight campaign status changed')
    report['backupReceipt']=json.loads((B/'apply-receipt.json').read_text())
    report['ok']=True
except Exception as error:
    report['failure']={'type':type(error).__name__,'message':str(error) if isinstance(error,RuntimeError) else 'Unexpected failure; reconcile before retry'}
if R['mode']=='apply':
    receipt=S/'apply-postflight-receipt.json'
    with open(receipt,'x') as output:json.dump(report,output,indent=2);output.write('\n')
    receipt.chmod(0o600)
print(json.dumps(report,indent=2))
'''
    import paramiko
    values=dict((k.lower(),v.strip()) for k,v in re.findall(r'^\s*(HOST|user|pass)\s*:\s*(.*?)\s*$',(BASE.parents[2]/'Temp-Credentials/Mailwizz.txt').read_text(),re.M|re.I))
    if values['host'] not in ['51.77.133.158','servermail2.com']:raise RuntimeError('Unexpected host')
    client=paramiko.SSHClient();client.load_host_keys(str(Path.home()/'.ssh/known_hosts'));client.set_missing_host_key_policy(paramiko.RejectPolicy())
    try:
        client.connect(values['host'],username=values['user'],password=values['pass'],timeout=15,auth_timeout=15,banner_timeout=15,look_for_keys=False,allow_agent=False)
        stdin,stdout,stderr=client.exec_command('python3 -',timeout=180);stdin.write(remote);stdin.channel.shutdown_write()
        result=stdout.read();errors=stderr.read();status=stdout.channel.recv_exit_status()
        if status or errors:raise RuntimeError('SSH operation interrupted or failed; do not retry apply; reconcile remote receipts and hashes')
        report=json.loads(result);destination=upload.REPO/'.qa/short-retry-20261009'/('apply-postflight-receipt.json' if mode=='apply' else 'reconcile-postflight-receipt.json')
        destination.write_text(json.dumps(report,indent=2)+'\n')
        return {'receipt':str(destination),'result':report}
    finally:client.close()

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('mode',choices=['apply','reconcile']);args=parser.parse_args()
    report=execute(args.mode);print(json.dumps(report,indent=2));sys.exit(0 if report['result']['ok'] else 1)
