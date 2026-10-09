"""Explicit initial schema preparation and final protected-settings activation."""
from pathlib import Path
import argparse,datetime,importlib.util,json
HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('connect_publisher',HERE/'deploy-connect.py');deploy=importlib.util.module_from_spec(spec);spec.loader.exec_module(deploy)

def run(mode,pin,settings_sha=None):
    stage=Path(deploy.STAGE);backup=Path(deploy.BACKUP)
    deploy.require(HERE==stage,'Fixed private stage required')
    state=deploy.run('verify' if mode=='activate' else ('dry-run' if mode=='prepare' else 'reconcile'),stage,backup,pin)
    deploy.require(state['ok'],'Known source state required')
    if mode=='prepare':
        deploy.require(not deploy.SETTINGS.exists(),'Protected settings already exist; inspect instead')
        result=deploy.installation(stage,'prepare')
        result.update(manifestSha256=pin,at=datetime.datetime.now(datetime.timezone.utc).isoformat())
        with (stage/'installation-prepared.json').open('x')as f:json.dump(result,f,indent=2);f.flush();deploy.os.fsync(f.fileno())
        deploy.common.sync_dir(stage);return result
    prepared=json.loads((stage/'installation-prepared.json').read_text())
    candidate=stage/'connect-settings.candidate.json';candidate_hash=deploy.sha(candidate)
    deploy.require(prepared['manifestSha256']==pin and candidate_hash==prepared['candidateSha256'],'Prepared key/profile changed')
    deploy.common.safe(candidate);deploy.require(candidate.stat().st_mode&0o077==0,'Private key candidate permissions required')
    current=deploy.current(deploy.SETTINGS)
    deploy.require(current in [None,candidate_hash],'Protected settings drift; do not replace')
    if mode=='activate':
        deploy.require(settings_sha==candidate_hash,'Explicit protected settings SHA required')
        initial=deploy.installation(stage,'inspect');deploy.require(not initial['enrollmentEnabled'] and initial['connections']==0 and not initial['settingsOverridePresent'],'Fresh disabled installation without a settings override required')
        deploy.require(current is None,'Settings already present; inspect instead of repeating activation')
        intent={'manifestSha256':pin,'settingsSha256':candidate_hash,'target':str(deploy.SETTINGS),'at':datetime.datetime.now(datetime.timezone.utc).isoformat()}
        with (stage/'activation-intent.json').open('x')as f:json.dump(intent,f,indent=2);f.flush();deploy.os.fsync(f.fileno())
        deploy.common.sync_dir(stage)
        deploy.atomic_checked(candidate,deploy.SETTINGS,{'mode':0o600,'uid':1003,'gid':1003},None,candidate_hash)
        current=deploy.current(deploy.SETTINGS)
    result=deploy.installation(stage,'disable-enrollment' if mode=='disable-enrollment' else 'inspect')
    result.update(mode=mode,manifestSha256=pin,settingsSha256=current,settingsActive=current==candidate_hash,secretsPrinted=False,servicesChanged=False)
    return result

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('mode',choices=['prepare','inspect','activate','disable-enrollment']);parser.add_argument('manifest_sha256');parser.add_argument('--settings-sha256');args=parser.parse_args()
    with deploy.release_lock():result=run(args.mode,args.manifest_sha256,args.settings_sha256)
    print(json.dumps(result,indent=2))
