"""Read-only source/manifest metadata. Does not bootstrap MailWizz or expose config."""
from pathlib import Path
import datetime, hashlib, json, stat

APP = Path('/home/admin/web/servermail2.com/public_html')
PRIVATE = Path('/home/admin/web/servermail2.com/private/magicsmtp-policy')
BINDING = PRIVATE / 'bridge-tenant-4e049403a560073e.php'
SCHEDULER = PRIVATE / 'scheduler-2.7.3-20261002-tenant45-v3.json'
CONFIG = APP / 'apps/common/config/main-custom.php'
FILES = [APP / ('apps/extensions/magicsmtp/models/' + name) for name in ['MagicSmtpShortRetry.php', 'MagicSmtpCooldown.php', 'DeliveryServerMagicSmtpWebApi.php']]
FILES += [APP / 'apps/console/commands/SendCampaignsCommand.php', SCHEDULER]
GUARDS = [APP / 'apps/init.php', APP / 'apps/common/components/db/behaviors/CampaignQueueTableBehavior.php', CONFIG, BINDING]

def metadata(path):
    assert not any(p.is_symlink() for p in [path, *path.parents]), 'Symlink rejected'
    if not path.exists(): return None
    data = path.read_bytes(); info = path.stat()
    return {'sha256': hashlib.sha256(data).hexdigest(), 'bytes': len(data), 'mode': stat.S_IMODE(info.st_mode), 'uid': info.st_uid, 'gid': info.st_gid}

state = json.loads(SCHEDULER.read_text())
assert state['contract'] == 'magic-smtp-policy-scheduler-v1' and state['acceptance'] == 'passed' and state['mailwizz_version'] == '2.7.3'
assert str(BINDING) in CONFIG.read_text() and ("__DIR__.'/' . '" + SCHEDULER.name + "'") in BINDING.read_text(), 'Binding include paths changed'
print(json.dumps({'ok': True, 'readOnly': True, 'at': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'files': {str(p): metadata(p) for p in FILES}, 'guards': {str(p): metadata(p) for p in GUARDS}, 'scheduler': state, 'configReferenceVerified': True}, indent=2))
