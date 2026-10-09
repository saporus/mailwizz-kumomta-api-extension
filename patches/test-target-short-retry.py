"""Private PHP 8.1 acceptance only. Never deploys, boots MailWizz or sends mail."""
from pathlib import Path
import datetime, hashlib, io, json, os, shutil, subprocess, sys, tarfile, time

STAGE=Path('/var/tmp/magicsmtp-short-retry-20261009-v3')
BACKUP='/root/magicsmtp-short-retry-backup-20261009-v3'
RELEASE_SHA='5bcd3841b47db00e4f81f5795c18bd9b1ea5f7d37aaefb78e8eddef9ef5ae8db'
DEPLOY_SHA='dde853e1fc4a705b81e4f163e5435fb74ce63d78c51e2fa6e9addda6d72c23e9'
sha=lambda p:hashlib.sha256(Path(p).read_bytes()).hexdigest()

def main(upload_sha):
    started=time.monotonic();receipt={'ok':False,'at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'stage':str(STAGE),'productionWrites':False,'applicationBootstrap':False,'networkSends':False,'tests':[]}
    def require(condition,message):
        if not condition:raise RuntimeError(message)
    def run(args,timeout=45):
        left=180-(time.monotonic()-started);require(left>0,'Target acceptance time budget exhausted')
        proc=subprocess.run(args,capture_output=True,text=True,timeout=min(timeout,left))
        if proc.returncode or proc.stderr:raise RuntimeError('Private command failed: '+Path(args[0]).name+'; exit '+str(proc.returncode)+'; output withheld')
        return proc.stdout
    try:
        require(str(STAGE.resolve())==str(STAGE) and STAGE.is_dir(),'Private stage path invalid')
        require((STAGE.stat().st_mode&0o077)==0 and STAGE.stat().st_uid==os.getuid(),'Private stage permissions differ')
        require(sha(STAGE/'upload-manifest.json')==upload_sha,'Upload manifest pin changed')
        upload=json.loads((STAGE/'upload-manifest.json').read_text())
        for relative,digest in upload['files'].items():
            p=STAGE/relative;require(p.resolve().is_relative_to(STAGE) and not p.is_symlink(),'Unsafe uploaded path')
            require(sha(p)==digest,'Upload hash changed: '+relative)
        require(sha(STAGE/'release-manifest.json')==RELEASE_SHA and sha(STAGE/'deploy-short-retry.py')==DEPLOY_SHA,'Reviewed v3 release pins changed')
        php=['php8.1','-d','opcache.enable_cli=0']
        info=json.loads(run(php+['-r',"echo json_encode(['version'=>PHP_VERSION,'drivers'=>PDO::getAvailableDrivers()]);"]))
        require(info['version'].startswith('8.1.'),'Expected PHP 8.1 runtime')
        receipt['php']=info
        if 'sqlite' not in info['drivers']:
            deps=Path('/var/tmp/magicsmtp-policy-candidate-20261002-6c7305d-v2/private-php-deps')
            packages=list(deps.glob('*.deb'));require(len(packages)==1,'Previously verified private SQLite dependency missing')
            package=packages[0];expected='0939a07d1d65bfbd35b056ed2929ab32db1ba470fef03ad78e3ac6a68d9f0c94'
            require(sha(package)==expected and package.stat().st_size<20000000,'Private SQLite package pin mismatch')
            raw=subprocess.run(['dpkg-deb','--fsys-tarfile',str(package)],capture_output=True,check=True,timeout=15).stdout
            with tarfile.open(fileobj=io.BytesIO(raw),mode='r:') as archive:
                member=archive.extractfile('./usr/lib/php/20210902/pdo_sqlite.so');require(member is not None,'SQLite module absent from pinned package');module_sha=hashlib.sha256(member.read()).hexdigest()
            module=deps/'unpacked/usr/lib/php/20210902/pdo_sqlite.so'
            require(not module.is_symlink() and sha(module)==module_sha,'Existing private SQLite module differs from pinned package')
            php+=['-d','extension='+str(module)]
            require('sqlite' in json.loads(run(php+['-r','echo json_encode(PDO::getAvailableDrivers());'])),'Private SQLite module unavailable in PHP 8.1')
            receipt['privateDependency']={'module':str(module),'sha256':module_sha,'packageSha256':expected,'systemInstallationChanged':False}
        receipt['dryRun']=json.loads(run(['python3',str(STAGE/'deploy-short-retry.py'),'dry-run',str(STAGE),BACKUP,RELEASE_SHA]))
        receipt['campaignBefore']=json.loads(run(['php8.1',str(STAGE/'ops/read-short-retry-campaign-status.php')],timeout=15))
        require(receipt['campaignBefore'].get('ok') is True and receipt['campaignBefore']['campaign'][0]['status']=='paused','Expected paused campaign before target acceptance')
        work=STAGE/'test-work';work.mkdir(mode=0o700,exist_ok=False)
        candidate=work/'candidate';shutil.copytree(STAGE/'qa-candidate',candidate)
        tests=sorted((STAGE/'qa-code/tests').glob('*_test.php'))
        require(len(tests)==16,'Unexpected PHP fixture set')
        for test in tests:
            args=php+[str(test)]
            if test.name in ['policy_scheduler_runtime_test.php','short_retry_bridge_runtime_test.php']:args.append(str(candidate))
            output=run(args);(work/(test.stem+'.txt')).write_text(output)
            receipt['tests'].append({'name':test.name,'sha256':sha(test),'passed':True})
        for p in sorted((STAGE/'files').glob('*.php')):run(php+['-l',str(p)])
        receipt['candidateLint']=True
        # Fresh read-only transaction and sanitized process/cron metadata only.
        receipt['campaignReadback']=json.loads(run(['php8.1',str(STAGE/'ops/read-short-retry-campaign-status.php')],timeout=15))
        require(receipt['campaignReadback'].get('ok') is True,'Campaign readback failed')
        require(receipt['campaignReadback']['campaign'][0]['status']=='paused','Campaign pause changed during target acceptance; investigate')
        receipt['dryRunAfter']=json.loads(run(['python3',str(STAGE/'deploy-short-retry.py'),'dry-run',str(STAGE),BACKUP,RELEASE_SHA]))
        receipt['fixturePhpCommand']=php
        receipt['ok']=True
    except Exception as error:
        receipt['error']=str(error) if isinstance(error,RuntimeError) else type(error).__name__
    receipt['elapsedSeconds']=round(time.monotonic()-started,3)
    with (STAGE/'target-runtime-receipt.json').open('x') as out:json.dump(receipt,out,indent=2);out.write('\n')
    print(json.dumps(receipt,indent=2))
    return 0 if receipt['ok'] else 1

if __name__=='__main__':
    if len(sys.argv)!=2:raise SystemExit('Usage: python3 test-target-short-retry.py UPLOAD_MANIFEST_SHA256')
    raise SystemExit(main(sys.argv[1]))
