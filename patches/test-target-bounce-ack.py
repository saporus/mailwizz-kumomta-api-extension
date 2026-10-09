"""Private PHP8.1 callback acceptance only; no live callback or app bootstrap."""
from pathlib import Path
import hashlib,json,subprocess,sys,time
STAGE=Path('/var/tmp/magicsmtp-bounce-ack-20261009-v2');BACKUP='/root/magicsmtp-bounce-ack-backup-20261009-v2'
SQLITE=Path('/var/tmp/magicsmtp-policy-candidate-20261002-6c7305d-v2/private-php-deps/unpacked/usr/lib/php/20210902/pdo_sqlite.so')
SQLITE_SHA='53a0fc6b05702930c2fc29a12f87713ae66349a4e53bc81f8c343ef2e0333d9b'
sha=lambda p:hashlib.sha256(Path(p).read_bytes()).hexdigest()
def main(pin):
    started=time.monotonic();receipt={'ok':False,'manifestSha256':pin,'productionWrites':False,'networkSends':False,'normalDemoAcceptance':'not-verified','tests':[]}
    def require(value,message):
        if not value:raise RuntimeError(message)
    def run(args):
        output=subprocess.run(args,capture_output=True,text=True,timeout=45)
        require(output.returncode==0 and not output.stderr,'Isolated check failed; output withheld');return output.stdout
    try:
        require(STAGE.is_dir() and not STAGE.is_symlink() and STAGE.stat().st_mode&0o077==0,'Private stage required')
        require(sha(STAGE/'release-manifest.json')==pin,'Manifest changed');manifest=json.loads((STAGE/'release-manifest.json').read_text())
        for name,digest in manifest['artifacts'].items():
            path=STAGE/name;require(path.resolve().is_relative_to(STAGE) and not path.is_symlink() and sha(path)==digest,'Artifact changed')
        require(sha(SQLITE)==SQLITE_SHA,'Private SQLite module changed')
        php=['php8.1','-d','opcache.enable_cli=0','-d','extension='+str(SQLITE)]
        receipt['phpVersion']=run(php+['-r','echo PHP_VERSION;']).strip();require(receipt['phpVersion'].startswith('8.1.'),'PHP8.1 required')
        deploy=['python3',str(STAGE/'deploy-bounce-ack.py'),'dry-run',str(STAGE),BACKUP,pin]
        receipt['dryRunBefore']=json.loads(run(deploy))
        for name in ['bounce_callback_runtime_test.php','policy_callback_runtime_test.php','hook_runtime_test.php']:
            run(php+[str(STAGE/'qa/tests'/name)]);receipt['tests'].append({'name':name,'passed':True})
        run(php+['-l',str(STAGE/'candidate.php')]);receipt['dryRunAfter']=json.loads(run(deploy))
        receipt['candidateSha256']=manifest['afterSha256'];receipt['ok']=True
    except Exception as failure:receipt['error']=str(failure) if isinstance(failure,RuntimeError) else type(failure).__name__
    receipt['seconds']=round(time.monotonic()-started,3)
    with (STAGE/'target-runtime-receipt.json').open('x')as output:json.dump(receipt,output,indent=2);output.write('\n')
    print(json.dumps(receipt,indent=2));return 0 if receipt['ok'] else 1
if __name__=='__main__':raise SystemExit(main(sys.argv[1]))
