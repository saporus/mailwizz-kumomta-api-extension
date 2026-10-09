"""One-file demo state fix. Retains the deployed managed binding implementation."""
from pathlib import Path
import argparse,hashlib,importlib.util,json,os,shutil,subprocess,datetime
STAGE=Path('/var/tmp/magicsmtp-connect-demo-state-20261009-v1');BACKUP=Path('/root/magicsmtp-connect-demo-state-backup-20261009-v1')
TARGET=Path('/home/admin/web/servermail2.com/public_html/apps/extensions/magicsmtp/MagicSmtpConnectRuntime.php')
BEFORE='8ef78b9f5df9b6fdae3ba82ae41448dc6ef1a99c0689aded6b6d44bfbff86b53';AFTER='087e58a692c5c6d2e45957f1e11afc5116b342349ab3b51109303e9f0fd30e88'
INITIAL=Path('/var/tmp/magicsmtp-connect-20261009-v1');INITIAL_PIN='92d7ab8e9f38bd8b68d6f381cafb86f5bd9cb483b30769923f37e0e92e72558b'
SETTINGS=Path('/home/admin/web/servermail2.com/private/magicsmtp-policy/connect-settings.json');SETTINGS_SHA='c4c65414361dc88cdbd304606fe891adc0ee63b582a5da15c703e9a7716b13c0'
SQLITE=Path('/var/tmp/magicsmtp-policy-candidate-20261002-6c7305d-v2/private-php-deps/unpacked/usr/lib/php/20210902/pdo_sqlite.so');SQLITE_SHA='53a0fc6b05702930c2fc29a12f87713ae66349a4e53bc81f8c343ef2e0333d9b'
sha=lambda path:hashlib.sha256(Path(path).read_bytes()).hexdigest()
def require(value,message):
    if not value:raise RuntimeError(message)
def run(mode,pin):
    require(Path(__file__).resolve().parent==STAGE,'Fixed private stage required')
    require(sha(STAGE/'manifest.json')==pin,'Hotfix manifest changed');manifest=json.loads((STAGE/'manifest.json').read_text())
    for relative,digest in manifest['artifacts'].items():
        path=STAGE/relative;require(path.resolve().is_relative_to(STAGE) and not path.is_symlink() and sha(path)==digest,'Private artifact changed')
    require(sha(INITIAL/'release-manifest.json')==INITIAL_PIN,'Original release manifest changed');original=json.loads((INITIAL/'release-manifest.json').read_text())
    require(sha(INITIAL/'deploy-connect.py')==original['deployHelperSha256'],'Reviewed atomic publisher changed')
    spec=importlib.util.spec_from_file_location('atomic',INITIAL/'deploy-connect.py');atomic=importlib.util.module_from_spec(spec);spec.loader.exec_module(atomic)
    require(sha(SETTINGS)==SETTINGS_SHA,'Protected settings changed')
    for name,item in original['files'].items():
        if Path(name)!=TARGET:require(sha(name)==item['afterSha256'],'Untouched runtime guard changed')
    for name,digest in original['guards'].items():require(sha(name)==digest,'Static/native guard changed')
    atomic.common.safe(TARGET);state=sha(TARGET);require(state in [BEFORE,AFTER],'Unknown runtime state; reconcile before recovery')
    require(sha(STAGE/'MagicSmtpConnectRuntime.php')==AFTER,'Reviewed demo candidate changed')
    if mode=='test':
        require(state==BEFORE,'Initial test requires original managed runtime');require(sha(SQLITE)==SQLITE_SHA,'Private SQLite dependency changed')
        checks=[]
        for name in ['connect_native_runtime_test.php','connect_service_runtime_test.php']:
            result=subprocess.run(['php8.1','-d','opcache.enable_cli=0','-d','extension='+str(SQLITE),str(STAGE/'qa/tests'/name)],capture_output=True,text=True,timeout=45)
            require(result.returncode==0 and not result.stderr,'Isolated PHP check failed');checks.append(name)
        result={'ok':True,'manifestSha256':pin,'candidateSha256':AFTER,'tests':checks,'productionWrites':False}
        with (STAGE/'target-receipt.json').open('x')as f:json.dump(result,f,indent=2)
        return result
    if mode in ['verify','reconcile']:
        if mode=='verify':require(state==AFTER,'Candidate not installed')
        return {'ok':True,'mode':mode,'runtimeSha256':state,'settingsPreserved':True,'productionWrites':False}
    expected=BEFORE if mode=='apply' else AFTER;after=AFTER if mode=='apply' else BEFORE
    require(state==expected,'Explicit publication state differs; reconcile instead of repeat')
    with atomic.release_lock():
        if mode=='apply':
            receipt=json.loads((STAGE/'target-receipt.json').read_text());require(receipt['ok'] and receipt['manifestSha256']==pin and receipt['candidateSha256']==AFTER,'Pinned target acceptance required')
            BACKUP.mkdir(mode=0o700,exist_ok=False);shutil.copy2(TARGET,BACKUP/'MagicSmtpConnectRuntime.php');require(sha(BACKUP/'MagicSmtpConnectRuntime.php')==BEFORE,'Backup failed');shutil.copy2(STAGE/'manifest.json',BACKUP/'manifest.json')
            for name in ['MagicSmtpConnectRuntime.php','manifest.json']:
                with (BACKUP/name).open('rb+')as f:os.fsync(f.fileno())
            atomic.common.sync_dir(BACKUP)
        else:require(sha(BACKUP/'manifest.json')==pin and sha(BACKUP/'MagicSmtpConnectRuntime.php')==BEFORE,'Backup pin changed')
        source=(STAGE if mode=='apply' else BACKUP)/'MagicSmtpConnectRuntime.php'
        atomic.atomic_checked(source,TARGET,{'mode':0o644,'uid':1003,'gid':1003},expected,after)
        require(sha(TARGET)==after,'Readback hash mismatch')
    result={'ok':True,'mode':mode,'at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'runtimeSha256':after,'manifestSha256':pin,'backup':str(BACKUP),'filesChanged':1,'settingsPreserved':True,'databasesChanged':False,'servicesChanged':False}
    with (BACKUP/(mode+'-receipt.json')).open('x')as f:json.dump(result,f,indent=2)
    return result
if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('mode',choices=['test','apply','verify','reconcile','rollback']);parser.add_argument('manifest_sha256');args=parser.parse_args();print(json.dumps(run(args.mode,args.manifest_sha256),indent=2))
