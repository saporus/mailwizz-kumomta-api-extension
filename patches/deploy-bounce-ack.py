"""Explicit one-file bounce acknowledgement release; no replay/config/DB changes."""
from pathlib import Path
import argparse,datetime,hashlib,importlib.util,json,os,shutil
HERE=Path(__file__).resolve().parent
sha=lambda path:hashlib.sha256(Path(path).read_bytes()).hexdigest()
QUOTA_HELPER_SHA='d0e0b69ce24fcfdf81ca1f67dc96d9291df502df60e5f842b0c57244693833f3'
if sha(HERE/'deploy-minute-quota.py')!=QUOTA_HELPER_SHA:raise RuntimeError('Reviewed atomic helper changed')
spec=importlib.util.spec_from_file_location('quota_atomic',HERE/'deploy-minute-quota.py');atomic=importlib.util.module_from_spec(spec);spec.loader.exec_module(atomic)
require=atomic.require
STAGE='/var/tmp/magicsmtp-bounce-ack-20261009-v2';BACKUP='/root/magicsmtp-bounce-ack-backup-20261009-v2'
APP='/home/admin/web/servermail2.com/public_html'
TARGET=APP+'/apps/extensions/magicsmtp/models/DeliveryServerMagicSmtp.php'
BEFORE='ef989ddae4a2fc78e45105ae0b0b65679c27e794945a6a8048f6617c5d2a6d8d'
GUARDS={APP+'/apps/common/models/ListSubscriber.php':'5e82c3009f06d204d62148ac3eb59f912ad3a7711b1d53132278daad0386daad',APP+'/apps/common/models/EmailBlacklist.php':'7b1c64f0699b300d8309b08fdb62feaec4dacb2abc40b86cd9697cdfecbae35c',APP+'/apps/extensions/magicsmtp/models/DeliveryServerMagicSmtpWebApi.php':'15f6c34edb58ff73a880b250893f5e4b5e9ad57018d24c24f89635e9612b7918'}

def run(mode,stage,backup,pin,fixture=None,fail_after=False):
    stage=atomic.common.safe(Path(stage).absolute());backup=atomic.common.safe(Path(backup).absolute())
    require(mode in ['dry-run','apply','verify','reconcile','rollback'],'Explicit mode required')
    if fixture is None:require(str(stage)==STAGE and str(backup)==BACKUP and not fail_after,'Fixed private production paths required')
    else:fixture=Path(fixture).resolve();require(fixture.name.startswith('bounce-ack-fixture-'),'Disposable fixture required')
    def local(name):return atomic.common.safe(fixture/name.lstrip('/') if fixture else Path(name))
    require(sha(stage/'release-manifest.json')==pin,'Manifest changed');manifest=json.loads((stage/'release-manifest.json').read_text())
    require(manifest['contract']=='magic-smtp-bounce-ack-v1' and manifest['target']==TARGET and manifest['beforeSha256']==BEFORE,'Unexpected scope')
    require(sha(stage/'deploy-bounce-ack.py')==manifest['deployHelperSha256'],'Deployment helper changed')
    require(sha(stage/'candidate.php')==manifest['afterSha256'],'Candidate changed')
    for name,digest in GUARDS.items():require(sha(local(name))==digest,'Untouched guard changed')
    dest=local(TARGET);actual=atomic.current(dest);known=actual in [BEFORE,manifest['afterSha256']]
    if mode=='reconcile':return {'ok':known,'mode':mode,'targetState':'baseline' if actual==BEFORE else ('candidate' if known else 'unknown'),'backupValid':atomic.current(backup/'release-manifest.json')==pin and atomic.current(backup/'original.php')==BEFORE,'productionWrites':False}
    expected=manifest['afterSha256'] if mode=='verify' else BEFORE
    require(known if mode=='rollback' else actual==expected,'Target drift')
    if mode in ['dry-run','verify']:return {'ok':True,'mode':mode,'checkedFiles':1,'productionWrites':False,'targetSha256':actual}
    if mode=='apply':
        receipt=json.loads((stage/'target-runtime-receipt.json').read_text())
        require(receipt['ok'] and receipt['manifestSha256']==pin and receipt['phpVersion'].startswith('8.1.') and receipt['candidateSha256']==manifest['afterSha256'],'Target PHP acceptance required')
        require(fixture is not None or not receipt.get('fixture'),'Synthetic receipt cannot authorize production apply')
        backup.mkdir(mode=0o777 if os.name=='nt' else 0o700,exist_ok=False)
        shutil.copy2(dest,backup/'original.php');require(sha(backup/'original.php')==BEFORE,'Backup verification failed')
        shutil.copy2(stage/'release-manifest.json',backup/'release-manifest.json')
        for name in ['original.php','release-manifest.json']:
            with (backup/name).open('rb+')as output:os.fsync(output.fileno())
        atomic.common.sync_dir(backup)
    else:require(sha(backup/'release-manifest.json')==pin and sha(backup/'original.php')==BEFORE,'Backup changed')
    try:
        if mode=='apply':atomic.atomic_checked(stage/'candidate.php',dest,manifest['metadata'],BEFORE,manifest['afterSha256'])
        elif actual!=BEFORE:atomic.atomic_checked(backup/'original.php',dest,manifest['metadata'],manifest['afterSha256'],BEFORE)
        if fail_after:raise RuntimeError('Injected fixture publication fault')
        require(sha(dest)==(manifest['afterSha256'] if mode=='apply' else BEFORE),'Post-write hash changed')
    except BaseException:
        if mode=='apply' and atomic.current(dest)==manifest['afterSha256']:atomic.atomic_checked(backup/'original.php',dest,manifest['metadata'],manifest['afterSha256'],BEFORE)
        raise
    result={'ok':True,'mode':mode,'at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'manifestSha256':pin,'targetSha256':sha(dest),'changedFiles':1,'databasesChanged':False,'configChanged':False,'replayed':False,'servicesChanged':False}
    (backup/(mode+'-receipt.json')).write_text(json.dumps(result,indent=2)+'\n');atomic.common.sync_dir(backup);return result

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('mode',choices=['dry-run','apply','verify','reconcile','rollback']);parser.add_argument('stage');parser.add_argument('backup');parser.add_argument('pin');args=parser.parse_args()
    result=run(args.mode,args.stage,args.backup,args.pin);print(json.dumps(result,indent=2));raise SystemExit(0 if result['ok'] else 1)
