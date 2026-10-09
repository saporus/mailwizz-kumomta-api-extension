"""Freeze a new private one-file callback candidate. Local only."""
from pathlib import Path
import hashlib,json,os,shutil,sys
REPO=Path(__file__).resolve().parent.parent
stage=Path(sys.argv[1]).resolve()
if stage.exists():raise RuntimeError('New private output directory required')
sha=lambda p:hashlib.sha256(Path(p).read_bytes()).hexdigest()
stage.mkdir(parents=True,mode=0o777 if os.name=='nt' else 0o700)
manifest={'contract':'magic-smtp-bounce-ack-v1','target':'/home/admin/web/servermail2.com/public_html/apps/extensions/magicsmtp/models/DeliveryServerMagicSmtp.php','beforeSha256':'ef989ddae4a2fc78e45105ae0b0b65679c27e794945a6a8048f6617c5d2a6d8d','metadata':{'mode':420,'uid':1003,'gid':1003},'normalDemoAcceptance':'not-verified','artifacts':{}}
shutil.copy2(REPO/'magicsmtp/models/DeliveryServerMagicSmtp.php',stage/'candidate.php');manifest['afterSha256']=sha(stage/'candidate.php')
for name in ['deploy-bounce-ack.py','deploy-minute-quota.py','deploy-short-retry.py','test-target-bounce-ack.py']:shutil.copy2(REPO/'patches'/name,stage/name)
manifest['deployHelperSha256']=sha(stage/'deploy-bounce-ack.py')
for relative in ['tests/bounce_callback_runtime_test.php','tests/policy_callback_runtime_test.php','tests/hook_runtime_test.php','magicsmtp/models/DeliveryServerMagicSmtp.php','magicsmtp/models/MagicSmtpPolicyBridge.php','magicsmtp/models/MagicSmtpPolicyStore.php','magicsmtp/MagicSmtpPolicyRuntime.php','magicsmtp/MagicsmtpExt.php']:
    dest=stage/'qa'/relative;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(REPO/relative,dest)
for path in sorted(stage.rglob('*')):
    if path.is_file():manifest['artifacts'][path.relative_to(stage).as_posix()]=sha(path)
(stage/'release-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
print(json.dumps({'ok':True,'stage':str(stage),'manifestSha256':sha(stage/'release-manifest.json'),'candidateSha256':manifest['afterSha256']},indent=2))
