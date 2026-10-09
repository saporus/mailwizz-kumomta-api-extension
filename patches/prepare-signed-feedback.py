"""Freeze the reviewed two-file feedback candidate. Local output only."""
from pathlib import Path
import hashlib,json,os,shutil,sys
REPO=Path(__file__).resolve().parent.parent;APP='/home/admin/web/servermail2.com/public_html'
stage=Path(sys.argv[1]).resolve()
if stage.exists():raise RuntimeError('New private output required')
sha=lambda path:hashlib.sha256(Path(path).read_bytes()).hexdigest()
stage.mkdir(parents=True,mode=0o777 if os.name=='nt' else 0o700);(stage/'files').mkdir()
metadata={'mode':420,'uid':1003,'gid':1003}
manifest={'contract':'magic-smtp-signed-feedback-release-v1','localAcceptancePassed':True,'normalDemoAcceptance':'not-verified','files':{},'guards':{},'artifacts':{}}
for name,before in [('MagicSmtpBounceIngress.php',None),('DeliveryServerMagicSmtp.php','286b0b6eda61e880afbf53df998d255a76a50853a655287000b8486075caea2c')]:
 shutil.copy2(REPO/'magicsmtp/models'/name,stage/'files'/name)
 manifest['files'][APP+'/apps/extensions/magicsmtp/models/'+name]={'source':name,'before':dict(metadata,sha256=before)if before else None,'metadata':metadata,'afterSha256':sha(stage/'files'/name)}
for relative,digest in [('models/ListSubscriber.php','5e82c3009f06d204d62148ac3eb59f912ad3a7711b1d53132278daad0386daad'),('models/EmailBlacklist.php','7b1c64f0699b300d8309b08fdb62feaec4dacb2abc40b86cd9697cdfecbae35c'),('models/CustomerEmailBlacklist.php','ad449b5a276525d9db1bc402e7c41ae96694b809e49a828eb0982708e567a70c'),('models/CampaignComplainLog.php','b409f9fa2bedbdf6243d62d625edf1b55094794f36cc396b9df9fbd6c5db5572'),('models/CampaignTrackUnsubscribe.php','2703c70ebe6ae7febcc0bca66599e64c8faf2e130eef60296352ccb681e68d57'),('models/option/OptionCronProcessFeedbackLoopServers.php','305bdf8c422c1bcac02d05df7c633f1a36dbbd0a53e6a99796e729db2d0414cc')]:manifest['guards'][APP+'/apps/common/'+relative]=digest
manifest['guards'][APP+'/apps/common/models/Lists.php']='bf0c67da80294c1c00498396261218e03e67d7550ec7ee05cf2c9a89cec22896'
for name in ['deploy-signed-feedback.py','deploy-short-retry.py','test-target-signed-feedback.py']:shutil.copy2(REPO/'patches'/name,stage/name)
manifest['deployHelperSha256']=sha(stage/'deploy-signed-feedback.py');manifest['testHelperSha256']=sha(stage/'test-target-signed-feedback.py')
for relative in ['tests/signed_bounce_runtime_test.php','tests/bounce_callback_runtime_test.php','tests/policy_callback_runtime_test.php','tests/hook_runtime_test.php','magicsmtp/models/DeliveryServerMagicSmtp.php','magicsmtp/models/MagicSmtpBounceIngress.php','magicsmtp/models/MagicSmtpPolicyBridge.php','magicsmtp/models/MagicSmtpPolicyStore.php','magicsmtp/MagicSmtpPolicyRuntime.php','magicsmtp/MagicsmtpExt.php']:
 dest=stage/'qa'/relative;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(REPO/relative,dest)
for path in sorted(stage.rglob('*')):
 if path.is_file():manifest['artifacts'][path.relative_to(stage).as_posix()]=sha(path)
(stage/'release-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
print(json.dumps({'ok':True,'stage':str(stage),'manifestSha256':sha(stage/'release-manifest.json'),'candidateHashes':{name:item['afterSha256']for name,item in manifest['files'].items()}},indent=2))
