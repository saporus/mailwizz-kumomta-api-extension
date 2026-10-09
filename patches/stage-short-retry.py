"""Build a local review bundle from current tested files and read-only target metadata."""
from pathlib import Path
import hashlib, json, shutil, sys
repo=Path(__file__).resolve().parents[1]
candidate=repo/'.qa/short-retry-20261009'
stage=Path(sys.argv[1]);assert not stage.exists()
baseline=json.loads((candidate/'live-baseline.json').read_text())
policy=json.loads((candidate/'policy-scheduler-manifest.json').read_text());assert policy['acceptance']=='passed'
stage.mkdir(parents=True);(stage/'files').mkdir()
sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
names=['MagicSmtpShortRetry.php','MagicSmtpCooldown.php','DeliveryServerMagicSmtpWebApi.php','SendCampaignsCommand.php','scheduler.json']
sources=[repo/'magicsmtp/models'/n for n in names[:3]]+[candidate/'apps/console/commands/SendCampaignsCommand.php',candidate/'policy-scheduler-manifest.json']
manifest={'contract':'magic-smtp-short-retry-release-v1','acceptance':'passed','baselineAt':baseline['at'],'files':{},'guards':baseline['guards']}
for (target,before),name,source in zip(baseline['files'].items(),names,sources):
    dest=stage/'files'/name;shutil.copy2(source,dest)
    manifest['files'][target]={'source':name,'before':before,'afterSha256':sha(dest),'metadata':before or {'mode':420,'uid':1003,'gid':1003}}
(stage/'release-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
shutil.copy2(repo/'patches/deploy-short-retry.py',stage/'deploy-short-retry.py')
shutil.copytree(repo/'magicsmtp',stage/'qa-code/magicsmtp')
shutil.copytree(repo/'tests',stage/'qa-code/tests',ignore=shutil.ignore_patterns('__pycache__'))
for relative in ['apps/console/commands/SendCampaignsCommand.php','apps/common/components/db/behaviors/CampaignQueueTableBehavior.php','policy-scheduler-manifest.json','short-retry-manifest.json']:
    dest=stage/'qa-candidate'/relative;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(candidate/relative,dest)
print(json.dumps({'stage':str(stage.resolve()),'manifestSha256':sha(stage/'release-manifest.json'),'helperSha256':sha(stage/'deploy-short-retry.py'),'files':{k:v['afterSha256'] for k,v in manifest['files'].items()}},indent=2))
