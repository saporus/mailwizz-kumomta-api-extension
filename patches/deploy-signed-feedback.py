"""Explicit two-file CAS deployment. No campaign, database, cache or service changes."""
from pathlib import Path
import argparse,ctypes,datetime,errno,importlib.util,json,os,shutil

HERE=Path(__file__).resolve().parent
COMMON_SHA='dde853e1fc4a705b81e4f163e5435fb74ce63d78c51e2fa6e9addda6d72c23e9'
import hashlib
if hashlib.sha256((HERE/'deploy-short-retry.py').read_bytes()).hexdigest()!=COMMON_SHA:raise RuntimeError('Reviewed atomic helper changed')
spec=importlib.util.spec_from_file_location('reviewed_atomic',HERE/'deploy-short-retry.py');common=importlib.util.module_from_spec(spec);spec.loader.exec_module(common)
require=common.require;sha=common.sha
APP='/home/admin/web/servermail2.com/public_html'
STAGE='/var/tmp/magicsmtp-signed-feedback-20261009-v2'
BACKUP='/root/magicsmtp-signed-feedback-backup-20261009-v2'
TARGETS=[APP+'/apps/extensions/magicsmtp/models/'+n for n in ['MagicSmtpBounceIngress.php','DeliveryServerMagicSmtp.php']]
GUARDS=['/home/admin/web/servermail2.com/public_html/apps/common/models/ListSubscriber.php', '/home/admin/web/servermail2.com/public_html/apps/common/models/EmailBlacklist.php', '/home/admin/web/servermail2.com/public_html/apps/common/models/CustomerEmailBlacklist.php', '/home/admin/web/servermail2.com/public_html/apps/common/models/CampaignComplainLog.php', '/home/admin/web/servermail2.com/public_html/apps/common/models/CampaignTrackUnsubscribe.php', '/home/admin/web/servermail2.com/public_html/apps/common/models/option/OptionCronProcessFeedbackLoopServers.php', '/home/admin/web/servermail2.com/public_html/apps/common/models/Lists.php']

def current(path):
    common.safe(path)
    return sha(path) if path.exists() else None

def rename_new(source,dest):
    if os.name=='nt':return os.rename(source,dest) # Windows refuses existing destinations.
    library=ctypes.CDLL(None,use_errno=True);function=library.renameat2
    function.argtypes=[ctypes.c_int,ctypes.c_char_p,ctypes.c_int,ctypes.c_char_p,ctypes.c_uint];function.restype=ctypes.c_int
    if function(-100,os.fsencode(source),-100,os.fsencode(dest),1)!=0:
        code=ctypes.get_errno()
        if code==errno.EEXIST:raise FileExistsError(code,'No-overwrite destination exists')
        raise OSError(code,'Atomic no-overwrite rename unavailable')

def atomic_checked(source,dest,metadata,expected,after):
    """Recheck immediately before publication; new files use exclusive rename."""
    require(current(dest)==expected,'Target drift immediately before install: '+str(dest))
    tmp=dest.with_name(dest.name+'.minute-quota-'+os.urandom(8).hex())
    try:
        with open(tmp,'xb')as output:
            output.write(source.read_bytes());output.flush()
            if os.name!='nt':
                os.fchmod(output.fileno(),metadata['mode']);os.fchown(output.fileno(),metadata['uid'],metadata['gid'])
            os.fsync(output.fileno())
        require(sha(tmp)==after,'Publication source changed')
        require(current(dest)==expected,'Target drift at publication: '+str(dest))
        if expected is None:
            # os.replace would overwrite an unexpected file created after CAS.
            rename_new(tmp,dest)
        else:os.replace(tmp,dest)
        common.sync_dir(dest.parent)
    finally:
        if tmp.exists():tmp.unlink()

def run(mode,stage,backup,pin,fixture=None,fail_after=0):
    stage=Path(stage).resolve();backup=Path(backup).resolve()
    require(mode in ['dry-run','apply','rollback','verify','reconcile'],'Explicit mode required')
    if fixture is None:require(str(stage)==STAGE and str(backup)==BACKUP and fail_after==0,'Fixed private production paths required')
    else:fixture=Path(fixture).resolve();require(fixture.name.startswith('signed-feedback-deploy-fixture-'),'Disposable fixture root required')
    def target(name):return common.safe(fixture/name.lstrip('/') if fixture else Path(name))
    common.safe(stage);common.safe(backup)
    require(sha(stage/'release-manifest.json')==pin,'Manifest pin changed');manifest=json.loads((stage/'release-manifest.json').read_text())
    require(manifest['contract']=='magic-smtp-signed-feedback-release-v1' and manifest['localAcceptancePassed'],'Local acceptance absent')
    require(list(manifest['files'])==TARGETS and list(manifest['guards'])==GUARDS,'Unexpected deployment scope')
    require(sha(stage/'deploy-signed-feedback.py')==manifest['deployHelperSha256'] and sha(stage/'test-target-signed-feedback.py')==manifest['testHelperSha256'],'Reviewed helper pin changed')
    for name,digest in manifest['guards'].items():require(sha(target(name))==digest,'Untouched guard changed: '+name)
    states={}
    for name,item in manifest['files'].items():
        require(Path(item['source']).name==item['source'] and sha(stage/'files'/item['source'])==item['afterSha256'],'Candidate source changed')
        before=item['before']['sha256'] if item['before'] else None;actual=current(target(name))
        states[name]='baseline' if actual==before else ('candidate' if actual==item['afterSha256'] else 'unknown')
        if mode!='reconcile':
            expected=item['afterSha256'] if mode=='verify' else before
            require(states[name]!='unknown' if mode=='rollback' else actual==expected,'Target drift: '+name)
        if mode=='rollback' and item['before']:require(sha(backup/'originals'/item['source'])==item['before']['sha256'],'Backup original changed')
    if mode=='reconcile':
        backup_valid=backup.is_dir() and current(backup/'release-manifest.json')==pin and all(not item['before'] or current(backup/'originals'/item['source'])==item['before']['sha256'] for item in manifest['files'].values())
        return {'ok':'unknown' not in states.values(),'mode':mode,'states':states,'backupValid':backup_valid,'productionWrites':False}
    if mode in ['dry-run','verify']:return {'ok':True,'mode':mode,'checkedFiles':2,'productionWrites':False}
    # Helper first; old code ignores it. No worker or campaign state changes.
    if mode=='apply':
        acceptance=json.loads((stage/'target-runtime-receipt.json').read_text())
        require(fixture is not None or not acceptance.get('fixture'),'Fixture receipt cannot authorize production apply')
        require(acceptance['ok'] and acceptance['manifestSha256']==pin and acceptance['phpVersion'].startswith('8.1.'),'Pinned target PHP acceptance required')
        require(acceptance['candidateHashes']=={name:item['afterSha256'] for name,item in manifest['files'].items()},'Target acceptance candidate differs')
        backup.mkdir(mode=0o777 if os.name=='nt' else 0o700,exist_ok=False);(backup/'originals').mkdir(mode=0o777 if os.name=='nt' else 0o700)
        for name,item in manifest['files'].items():
            if item['before']:
                dest=backup/'originals'/item['source'];shutil.copy2(target(name),dest);require(sha(dest)==item['before']['sha256'],'Backup verification failed')
                with open(dest,'rb+')as output:os.fsync(output.fileno())
        shutil.copy2(stage/'release-manifest.json',backup/'release-manifest.json')
        with (backup/'release-manifest.json').open('rb+')as output:os.fsync(output.fileno())
        common.sync_dir(backup/'originals');common.sync_dir(backup)
    else:require(sha(backup/'release-manifest.json')==pin,'Backup manifest pin changed')
    def install(name,original):
        item=manifest['files'][name];dest=target(name)
        before=item['before']['sha256'] if item['before'] else None
        expected=item['afterSha256'] if original else before
        after=before if original else item['afterSha256']
        actual=current(dest)
        if original and actual==after:return
        require(actual==expected,'Target drift immediately before install: '+name)
        if original and item['before'] is None:
            require(current(dest)==expected,'New helper drift before removal');dest.unlink();common.sync_dir(dest.parent)
        else:atomic_checked((backup/'originals' if original else stage/'files')/item['source'],dest,item['before'] or item['metadata'],expected,after)
    written=[]
    try:
        for name in (TARGETS if mode=='apply' else list(reversed(TARGETS))):
            written.append(name);install(name,mode=='rollback')
            if len(written)==fail_after:raise RuntimeError('Injected fixture replacement failure')
        for name,item in manifest['files'].items():
            expected=item['afterSha256'] if mode=='apply' else (item['before']['sha256'] if item['before'] else None)
            require((sha(target(name)) if target(name).exists() else None)==expected,'Post-write hash mismatch')
    except BaseException:
        # Do not roll a partly completed rollback forward. It can be resumed
        # from any verified baseline/candidate mixture, including process loss.
        if mode=='apply':
            for name in TARGETS:
                item=manifest['files'][name];before=item['before']['sha256'] if item['before'] else None
                require(current(target(name)) in [before,item['afterSha256']],'Unknown target drift; reconcile before recovery: '+name)
            for name in reversed(TARGETS):install(name,True)
        raise
    receipt={'ok':True,'mode':mode,'at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'manifestSha256':pin,'changedFiles':2,'campaignsChanged':False,'databasesChanged':False,'cacheCleared':False,'quotasChanged':False,'servicesChanged':False}
    (backup/(mode+'-receipt.json')).write_text(json.dumps(receipt,indent=2)+'\n');common.sync_dir(backup);return receipt

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('mode',choices=['dry-run','apply','rollback','verify','reconcile']);parser.add_argument('stage');parser.add_argument('backup');parser.add_argument('manifest_sha256');args=parser.parse_args()
    result=run(args.mode,args.stage,args.backup,args.manifest_sha256);print(json.dumps(result,indent=2));raise SystemExit(0 if result['ok'] else 1)
