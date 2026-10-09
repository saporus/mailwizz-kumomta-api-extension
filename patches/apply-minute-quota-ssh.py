"""Execute only the approved pinned two-file apply, with readback/reconciliation."""
from pathlib import Path
import datetime,hashlib,importlib.util,json,re,sys,time
import paramiko

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('quota_upload',HERE/'upload-test-minute-quota-ssh.py');upload=importlib.util.module_from_spec(spec);spec.loader.exec_module(upload)
_,plan=upload.plan()
client=paramiko.SSHClient();client.load_host_keys(str(Path.home()/'.ssh/known_hosts'));client.set_missing_host_key_policy(paramiko.RejectPolicy())
values=dict((key.lower(),value.strip())for key,value in re.findall(r'^\s*(HOST|user|pass)\s*:\s*(.*?)\s*$',(upload.REPO.parents[1]/'Temp-Credentials/Mailwizz.txt').read_text(),re.M|re.I))
if values['host'] not in ['51.77.133.158','servermail2.com']:raise RuntimeError('Exact established MailWizz host required')
report={'ok':False,'at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'manifestSha256':upload.PIN,'phase':'connect','productionApplyAttempted':False}

def command(text,source=None):
    stdin,stdout,stderr=client.exec_command(text,timeout=60)
    if source is not None:stdin.write(source)
    stdin.channel.shutdown_write();raw=stdout.read();errors=stderr.read();status=stdout.channel.recv_exit_status()
    if status or errors:raise RuntimeError('Pinned remote command failed; output withheld, inspect reconciliation')
    result=json.loads(raw)
    if not result.get('ok'):raise RuntimeError('Pinned remote command returned failed result')
    return result

try:
    if len(sys.argv)!=2 or sys.argv[1]!='apply':raise RuntimeError('Explicit apply argument required')
    client.connect(values['host'],username=values['user'],password=values['pass'],timeout=15,auth_timeout=15,banner_timeout=15,look_for_keys=False,allow_agent=False)
    report['phase']='verify_private_stage'
    with client.open_sftp()as sftp:
        manifest=json.loads((upload.BUNDLE/'release-manifest.json').read_text())
        for relative in ['release-manifest.json','deploy-minute-quota.py','deploy-short-retry.py']:
            expected=upload.PIN if relative=='release-manifest.json' else manifest['artifacts'][relative]
            with sftp.open(upload.STAGE+'/'+relative,'rb')as stream:actual=hashlib.sha256(stream.read()).hexdigest()
            if actual!=expected:raise RuntimeError('Private execution pin mismatch')
    report['phase']='natural_worker_window'
    source=(upload.BUNDLE/'read-short-retry-campaign-status.php').read_text()
    # Read-only waits only; do not pause, kill or change cron to create a window.
    deadline=time.monotonic()+100
    while True:
        before=command('php8.1 -d opcache.enable_cli=0',source)
        second=datetime.datetime.fromisoformat(before['at']).second
        if not before['workers'] and 15<=second<=45:break
        if time.monotonic()>=deadline:raise RuntimeError('No natural quiet worker window; no apply attempted')
        time.sleep(5)
    report['before']=before
    report['readiness']=command('php8.1 -d opcache.enable_cli=0',(upload.BUNDLE/'read-minute-quota-readiness.php').read_text())
    report['phase']='apply';report['productionApplyAttempted']=True
    report['apply']=command(plan['deploymentCommands']['apply'])
    report['phase']='verify';report['verify']=command(plan['deploymentCommands']['verify'])
    report['filesAfter']={}
    with client.open_sftp()as sftp:
        for name,item in manifest['files'].items():
            with sftp.open(name,'rb')as stream:actual=hashlib.sha256(stream.read()).hexdigest()
            if actual!=item['afterSha256']:raise RuntimeError('Installed target hash mismatch')
            report['filesAfter'][name]=actual
    report['after']=command('php8.1 -d opcache.enable_cli=0',source)
    report['readinessAfter']=command('php8.1 -d opcache.enable_cli=0',(upload.BUNDLE/'read-minute-quota-readiness.php').read_text())
    if report['readiness']['serverLimits']!=report['readinessAfter']['serverLimits']:raise RuntimeError('Quota settings changed during release; review required')
    report['ok']=True;report['phase']='complete'
except Exception as failure:
    report['error']=str(failure) if isinstance(failure,RuntimeError) else type(failure).__name__
    if report['productionApplyAttempted']:
        try:report['reconcile']=command(plan['deploymentCommands']['reconcile'])
        except Exception as reconciliation:report['reconcileError']=type(reconciliation).__name__
finally:
    client.close();destination=upload.REPO/'.qa/quota-minute-20261009/apply-postflight-receipt.json'
    destination.write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps({'ok':report['ok'],'phase':report['phase'],'receipt':str(destination),'result':report},indent=2))
raise SystemExit(0 if report['ok'] else 1)
