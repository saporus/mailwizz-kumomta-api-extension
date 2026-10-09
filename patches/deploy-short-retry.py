"""Explicit five-file release. No application bootstrap, network, cron or database writes."""
from pathlib import Path
import argparse, datetime, hashlib, json, os, shutil, stat, tempfile

APP='/home/admin/web/servermail2.com/public_html'
PRIVATE='/home/admin/web/servermail2.com/private/magicsmtp-policy'
TARGETS=[APP+'/apps/extensions/magicsmtp/models/'+f for f in ['MagicSmtpShortRetry.php','MagicSmtpCooldown.php','DeliveryServerMagicSmtpWebApi.php']]
TARGETS += [APP+'/apps/console/commands/SendCampaignsCommand.php',PRIVATE+'/scheduler-2.7.3-20261002-tenant45-v3.json']
GUARDS=[APP+'/apps/init.php',APP+'/apps/common/components/db/behaviors/CampaignQueueTableBehavior.php',APP+'/apps/common/config/main-custom.php',PRIVATE+'/bridge-tenant-4e049403a560073e.php']
sha=lambda p:hashlib.sha256(Path(p).read_bytes()).hexdigest()

def require(value,message):
    if not value:raise RuntimeError(message)

def safe(path):
    require(not any(p.is_symlink() for p in [path,*path.parents]),'Symlink path rejected')
    return path

def sync_dir(path):
    if os.name!='nt':
        fd=os.open(path,os.O_RDONLY)
        try:os.fsync(fd)
        finally:os.close(fd)

def atomic(source,target,meta):
    safe(target);tmp=target.with_name(target.name+'.short-retry-'+os.urandom(8).hex())
    try:
        with open(tmp,'xb') as out:
            out.write(source.read_bytes());out.flush()
            if os.name!='nt':
                os.fchmod(out.fileno(),meta['mode']);os.fchown(out.fileno(),meta['uid'],meta['gid'])
            os.fsync(out.fileno())
        os.replace(tmp,target);sync_dir(target.parent)
    finally:
        if tmp.exists():tmp.unlink()

def quiet_workers():
    for process in Path('/proc').iterdir():
        if not process.name.isdigit():continue
        try:cmd=(process/'cmdline').read_bytes()
        except (FileNotFoundError,PermissionError,ProcessLookupError):continue
        require(not (b'php' in cmd and b'console.php' in cmd and b'send-campaigns' in cmd),'Sending workers active; use reviewed quiet deployment window')

def run(mode,stage,backup,manifest_sha,fixture=None,fail_after=0):
    stage=Path(stage).resolve();backup=Path(backup).resolve()
    require(mode in ['dry-run','apply','rollback'],'Explicit mode required')
    require(not fail_after or fixture is not None,'Fault injection is fixture-only')
    if fixture is not None:
        fixture=Path(fixture).resolve()
        require(fixture.is_relative_to(Path(tempfile.gettempdir()).resolve()),'Fixture root must be temporary')
    def target(name):return safe((fixture/name.lstrip('/')) if fixture is not None else Path(name))
    require(not stage.is_relative_to(Path(APP)) and not backup.is_relative_to(Path(APP)),'Stage/backup must be private')
    require(sha(stage/'release-manifest.json')==manifest_sha,'Release manifest pin mismatch')
    manifest=json.loads((stage/'release-manifest.json').read_text())
    require(manifest['contract']=='magic-smtp-short-retry-release-v1' and manifest['acceptance']=='passed','Acceptance required')
    require(list(manifest['files'])==TARGETS and list(manifest['guards'])==GUARDS,'Unexpected file scope')
    for name,meta in manifest['guards'].items():require(sha(target(name))==meta['sha256'],'Untouched guard changed: '+name)
    for name,item in manifest['files'].items():
        require(Path(item['source']).name==item['source'],'Invalid stage source')
        require(sha(stage/'files'/item['source'])==item['afterSha256'],'Candidate hash changed')
        expected=item['before']['sha256'] if item['before'] else None
        if mode=='rollback':expected=item['afterSha256']
        actual=sha(target(name)) if target(name).exists() else None
        require(actual==expected,'Target drift: '+name)
        if mode=='rollback' and item['before']:require(sha(backup/'originals'/item['source'])==item['before']['sha256'],'Rollback original changed')
    scheduler=json.loads((stage/'files'/'scheduler.json').read_text())
    require(scheduler['acceptance']=='passed' and scheduler['sha256']['apps/console/commands/SendCampaignsCommand.php']==manifest['files'][TARGETS[3]]['afterSha256'],'Scheduler command acceptance mismatch')
    require(scheduler['sha256']['apps/common/components/db/behaviors/CampaignQueueTableBehavior.php']==manifest['guards'][GUARDS[1]]['sha256'],'Scheduler queue hash mismatch')
    if mode=='dry-run':return {'ok':True,'mode':mode,'checkedFiles':5,'productionWrites':False}
    if fixture is None:quiet_workers()
    if mode=='apply':
        backup.mkdir(mode=0o777 if os.name=='nt' and fixture is not None else 0o700,exist_ok=False)
        (backup/'originals').mkdir(mode=0o777 if os.name=='nt' and fixture is not None else 0o700)
        for name,item in manifest['files'].items():
            if item['before']:
                dest=backup/'originals'/item['source'];shutil.copy2(target(name),dest)
                require(sha(dest)==item['before']['sha256'],'Backup verification failed')
                with open(dest,'rb+') as f:os.fsync(f.fileno())
        shutil.copy2(stage/'release-manifest.json',backup/'release-manifest.json');sync_dir(backup/'originals');sync_dir(backup)
    else:require(sha(backup/'release-manifest.json')==manifest_sha,'Backup manifest pin mismatch')
    written=[]
    def install(name,original):
        item=manifest['files'][name];dest=target(name)
        if original and item['before'] is None:
            if dest.exists():
                require(sha(dest)==item['afterSha256'],'New helper drift before removal');dest.unlink();sync_dir(dest.parent)
        else:atomic((backup/'originals' if original else stage/'files')/item['source'],dest,item['before'] or item['metadata'])
    try:
        order=TARGETS if mode=='apply' else list(reversed(TARGETS))
        for name in order:
            written.append(name);install(name,mode=='rollback')
            if len(written)==fail_after:raise RuntimeError('Injected replacement failure')
        for name,item in manifest['files'].items():
            expected=item['afterSha256'] if mode=='apply' else (item['before']['sha256'] if item['before'] else None)
            require((sha(target(name)) if target(name).exists() else None)==expected,'Post-write hash mismatch')
    except BaseException:
        for name in reversed(written):install(name,mode!='rollback')
        raise
    receipt={'ok':True,'mode':mode,'at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'manifestSha256':manifest_sha,'changedFiles':5,'fixture':fixture is not None,'campaignsChanged':False,'databasesChanged':False,'bindingsChanged':False,'cronChanged':False}
    (backup/(mode+'-receipt.json')).write_text(json.dumps(receipt,indent=2)+'\n');sync_dir(backup)
    return receipt

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('mode',choices=['dry-run','apply','rollback']);parser.add_argument('stage');parser.add_argument('backup');parser.add_argument('manifest_sha256')
    args=parser.parse_args();print(json.dumps(run(args.mode,args.stage,args.backup,args.manifest_sha256),indent=2))
