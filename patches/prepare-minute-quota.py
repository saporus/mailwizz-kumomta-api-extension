"""Freeze a LOCAL two-file candidate and isolated tests. Never connects or deploys."""
from pathlib import Path
import argparse,datetime,hashlib,json,os,shutil
REPO=Path(__file__).resolve().parent.parent
sha=lambda p:hashlib.sha256(Path(p).read_bytes()).hexdigest()
parser=argparse.ArgumentParser();parser.add_argument('new_output_directory');args=parser.parse_args();stage=Path(args.new_output_directory).resolve()
if stage.exists():raise RuntimeError('New private output directory required')
receipt=json.loads((REPO/'.qa/quota-minute-20261009/standalone-tests.json').read_text())
if not receipt['ok']:raise RuntimeError('Local synthetic acceptance missing')
if len(receipt['tests'])!=16 or not all(item['ok'] for item in receipt['tests']):raise RuntimeError('Expected sixteen standalone fixtures')
evidence={}
for name in ['standalone-tests.json','concurrency-test.json','deploy-test.json','native-test.txt']:
    path=REPO/'.qa/quota-minute-20261009'/name
    if not path.is_file():raise RuntimeError('Missing local evidence')
    if name.endswith('.json') and not json.loads(path.read_text()).get('ok'):raise RuntimeError('Local fixture acceptance failed')
    evidence[name]=sha(path)
baseline=json.loads((REPO/'.qa/short-retry-20261009/apply-postflight-receipt.json').read_text())['filesAfter']
APP='/home/admin/web/servermail2.com/public_html';PRIVATE='/home/admin/web/servermail2.com/private/magicsmtp-policy'
targets=[APP+'/apps/extensions/magicsmtp/models/'+n for n in ['MagicSmtpMinuteQuota.php','DeliveryServerMagicSmtpWebApi.php']]
guards=[APP+'/apps/common/models/DeliveryServer.php',APP+'/apps/console/commands/SendCampaignsCommand.php',APP+'/apps/extensions/magicsmtp/models/MagicSmtpShortRetry.php',APP+'/apps/extensions/magicsmtp/models/MagicSmtpCooldown.php',APP+'/apps/common/config/main-custom.php',PRIVATE+'/bridge-tenant-4e049403a560073e.php',PRIVATE+'/scheduler-2.7.3-20261002-tenant45-v3.json']
stage.mkdir(mode=0o777 if os.name=='nt' else 0o700,parents=True);(stage/'files').mkdir()
manifest={'contract':'magic-smtp-minute-quota-release-v1','at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'localAcceptancePassed':True,'localEvidenceSha256':evidence,'targetAcceptance':'required-before-apply','normalDemoAcceptance':'not-verified','baselineRequiresFreshRemoteRecheck':True,'files':{},'guards':{},'artifacts':{}}
for target in targets:
    name=Path(target).name;source=REPO/'magicsmtp/models'/name;dest=stage/'files'/name;shutil.copy2(source,dest)
    before=baseline['files'].get(target);manifest['files'][target]={'source':name,'before':before,'metadata':before or {'mode':420,'uid':1003,'gid':1003},'afterSha256':sha(dest)}
for guard in guards:
    manifest['guards'][guard]='a173922750c477558ac5302d5355ce68a5993f5ab2f8e639b944a41cf019bfef' if guard.endswith('/DeliveryServer.php') else (baseline['files'].get(guard) or baseline['guards'][guard])['sha256']
for name in ['deploy-minute-quota.py','deploy-short-retry.py','test-target-minute-quota.py','read-minute-quota-readiness.php','read-short-retry-campaign-status.php']:shutil.copy2(REPO/'patches'/name,stage/name)
manifest['deployHelperSha256']=sha(stage/'deploy-minute-quota.py');manifest['testHelperSha256']=sha(stage/'test-target-minute-quota.py')
shutil.copytree(REPO/'magicsmtp',stage/'qa-code/magicsmtp');shutil.copytree(REPO/'tests',stage/'qa-code/tests',ignore=shutil.ignore_patterns('__pycache__'))
for name in ['mailwizz-2.7.3-transient-retry.patch','mailwizz-2.8.1-transient-retry.patch','deploy-minute-quota.py','deploy-short-retry.py','test-target-minute-quota.py']:
    target=stage/'qa-code/patches'/name;target.parent.mkdir(exist_ok=True);shutil.copy2(REPO/'patches'/name,target)
(stage/'private-native').mkdir();shutil.copy2(REPO/'.qa/source/DeliveryServer.php',stage/'private-native/DeliveryServer.php')
shutil.copy2(REPO/'.qa/short-retry-release-20261009-v3/qa-candidate/apps/console/commands/SendCampaignsCommand.php',stage/'private-native/SendCampaignsCommand.php')
for name,relative in [('DeliveryServer.php','apps/common/models/DeliveryServer.php'),('SendCampaignsCommand.php','apps/console/commands/SendCampaignsCommand.php')]:
    if sha(stage/'private-native'/name)!=manifest['guards'][APP+'/'+relative]:raise RuntimeError('Private native fixture source drift')
for item in manifest['files'].values():
    if sha(stage/'qa-code/magicsmtp/models'/item['source'])!=item['afterSha256']:raise RuntimeError('QA runtime and candidate differ')
for path in sorted(stage.rglob('*')):
    if path.is_file():manifest['artifacts'][path.relative_to(stage).as_posix()]=sha(path)
(stage/'release-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
print(json.dumps({'ok':True,'stage':str(stage),'manifestSha256':sha(stage/'release-manifest.json'),'candidateHashes':{name:item['afterSha256']for name,item in manifest['files'].items()}},indent=2))
