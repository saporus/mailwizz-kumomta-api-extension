"""Freeze the reviewed local source and non-secret target baseline into a private bundle."""
from pathlib import Path
import hashlib,importlib.util,json,shutil,subprocess
REPO=Path(__file__).resolve().parent.parent;BUNDLE=REPO/'.qa/connect-release-20261009-v1'
sha=lambda p:hashlib.sha256(Path(p).read_bytes()).hexdigest()
def main():
    if BUNDLE.exists():raise RuntimeError('Frozen bundle exists; use a new reviewed version instead of overwriting')
    tests=['connect_service_runtime_test.php','connect_http_runtime_test.php','connect_native_runtime_test.php','connect_policy_refresh_test.php','policy_bridge_runtime_test.php','policy_callback_runtime_test.php','policy_correlation_runtime_test.php','policy_disabled_runtime_test.php','short_retry_runtime_test.php','send_runtime_test.php']
    for name in tests:
        result=subprocess.run(['php','-d','extension=pdo_sqlite','-d','extension=openssl','-d','extension=curl',str(REPO/'tests'/name)],capture_output=True,text=True,timeout=60)
        if result.returncode or result.stderr:raise RuntimeError('Local acceptance failed: '+name)
    fixture_parent=Path.home()/'Documents/Codex'
    result=subprocess.run(['python',str(REPO/'tests/connect_deploy_test.py')]+(['--fixture-parent',str(fixture_parent)]if fixture_parent.is_dir()else[]),capture_output=True,text=True,timeout=60)
    if result.returncode:raise RuntimeError('Publication fixtures failed')
    spec=importlib.util.spec_from_file_location('publisher',REPO/'patches/deploy-connect.py');deploy=importlib.util.module_from_spec(spec);spec.loader.exec_module(deploy)
    baseline=json.loads((REPO/'.qa/connect-release-baseline.json').read_text());BUNDLE.mkdir(mode=0o700);(BUNDLE/'files').mkdir();(BUNDLE/'qa').mkdir()
    shutil.copytree(REPO/'magicsmtp',BUNDLE/'qa/magicsmtp');shutil.copytree(REPO/'tests',BUNDLE/'qa/tests',ignore=shutil.ignore_patterns('__pycache__','*.pyc'))
    manifest={'contract':'magic-smtp-connect-release-v1','localAcceptancePassed':True,'localTests':tests+['connect_deploy_test.py'],'files':{},'guards':{}}
    for name,relative in zip(deploy.TARGETS,deploy.NAMES):
        source=REPO/'magicsmtp'/relative;shutil.copy2(source,BUNDLE/'files'/source.name);before=baseline['files'][name]
        manifest['files'][name]={'source':source.name,'before':before,'afterSha256':sha(source),'metadata':before or {'mode':0o644,'uid':1003,'gid':1003}}
    manifest['guards']={name:baseline['guards'][name]['sha256']for name in deploy.GUARDS}
    for name in ['deploy-connect.py','deploy-short-retry.py','test-target-connect.py','setup-connect.py','connect-installation.php']:shutil.copy2(REPO/'patches'/name,BUNDLE/name)
    manifest['deployHelperSha256']=sha(BUNDLE/'deploy-connect.py');manifest['testHelperSha256']=sha(BUNDLE/'test-target-connect.py')
    manifest['artifacts']={str(p.relative_to(BUNDLE)).replace('\\','/'):sha(p)for p in sorted(BUNDLE.rglob('*'))if p.is_file()}
    (BUNDLE/'release-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
    print(json.dumps({'ok':True,'bundle':str(BUNDLE),'manifestSha256':sha(BUNDLE/'release-manifest.json'),'candidateHashes':{k:v['afterSha256']for k,v in manifest['files'].items()},'remoteExecuted':False},indent=2))
if __name__=='__main__':main()
