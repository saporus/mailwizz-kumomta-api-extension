"""Private isolated PHP8.1 tests only. No application bootstrap or production writes."""
from pathlib import Path
import datetime,hashlib,json,subprocess,sys,time
STAGE=Path('/var/tmp/magicsmtp-minute-quota-20261009-v1')
BACKUP='/root/magicsmtp-minute-quota-backup-20261009-v1'
SQLITE=Path('/var/tmp/magicsmtp-policy-candidate-20261002-6c7305d-v2/private-php-deps/unpacked/usr/lib/php/20210902/pdo_sqlite.so')
SQLITE_SHA='53a0fc6b05702930c2fc29a12f87713ae66349a4e53bc81f8c343ef2e0333d9b'
sha=lambda p:hashlib.sha256(Path(p).read_bytes()).hexdigest()
def main(pin):
    started=time.monotonic();receipt={'ok':False,'manifestSha256':pin,'at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'applicationBootstrap':False,'productionWrites':False,'networkSends':False,'tests':[]}
    def require(v,m):
        if not v:raise RuntimeError(m)
    def run(args):
        left=180-(time.monotonic()-started);require(left>0,'Isolated acceptance budget exhausted')
        p=subprocess.run(args,capture_output=True,text=True,timeout=min(left,60))
        require(p.returncode==0 and not p.stderr,'Isolated command failed; output withheld');return p.stdout
    try:
        require(STAGE.is_dir() and not STAGE.is_symlink() and (STAGE.stat().st_mode&0o077)==0,'Private stage required')
        require(sha(STAGE/'release-manifest.json')==pin,'Manifest pin changed');manifest=json.loads((STAGE/'release-manifest.json').read_text())
        for relative,digest in manifest['artifacts'].items():
            p=STAGE/relative;require(p.resolve().is_relative_to(STAGE) and not p.is_symlink() and sha(p)==digest,'Private artifact changed')
        require(sha(SQLITE)==SQLITE_SHA and not SQLITE.is_symlink(),'Existing private PDO SQLite pin changed')
        php=['php8.1','-d','opcache.enable_cli=0','-d','extension='+str(SQLITE)]
        version=run(php+['-r','echo PHP_VERSION;']).strip();require(version.startswith('8.1.'),'PHP8.1 required');receipt['phpVersion']=version
        receipt['dryRunBefore']=json.loads(run(['python3',str(STAGE/'deploy-minute-quota.py'),'dry-run',str(STAGE),BACKUP,pin]))
        tests=STAGE/'qa-code/tests';skip={'policy_scheduler_runtime_test.php','short_retry_bridge_runtime_test.php','minute_quota_native_runtime_test.php'}
        for test in sorted(tests.glob('*_test.php')):
            if test.name in skip:continue
            run(php+[str(test)]);receipt['tests'].append({'name':test.name,'passed':True})
        native=STAGE/'private-native/DeliveryServer.php';worker=STAGE/'private-native/SendCampaignsCommand.php'
        run(php+[str(tests/'minute_quota_native_runtime_test.php'),str(native)]);receipt['tests'].append({'name':'minute_quota_native_runtime_test.php','passed':True})
        concurrency=json.loads(run(['python3',str(tests/'minute_quota_concurrency_test.py'),str(native),str(worker),'--php','php8.1','--pdo-sqlite',str(SQLITE)]));require(concurrency['ok'],'Concurrency fixture failed');receipt['concurrency']=concurrency
        recovery=json.loads(run(['python3',str(tests/'minute_quota_deploy_test.py'),'--fixture-parent',str(STAGE)]));require(recovery['ok'],'Isolated deployment recovery fixture failed');receipt['deploymentRecovery']=recovery
        for item in manifest['files'].values():run(php+['-l',str(STAGE/'files'/item['source'])])
        receipt['dryRunAfter']=json.loads(run(['python3',str(STAGE/'deploy-minute-quota.py'),'dry-run',str(STAGE),BACKUP,pin]))
        receipt['candidateHashes']={name:item['afterSha256'] for name,item in manifest['files'].items()};receipt['ok']=True
    except Exception as error:receipt['error']=str(error) if isinstance(error,RuntimeError) else type(error).__name__
    receipt['seconds']=round(time.monotonic()-started,3)
    with (STAGE/'target-runtime-receipt.json').open('x')as output:json.dump(receipt,output,indent=2);output.write('\n')
    print(json.dumps(receipt,indent=2));return 0 if receipt['ok'] else 1
if __name__=='__main__':
    if len(sys.argv)!=2:raise SystemExit('Usage: test-target-minute-quota.py RELEASE_MANIFEST_SHA256')
    raise SystemExit(main(sys.argv[1]))
