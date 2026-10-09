"""Read-only exact-ten native proof; no callback replay or production mutation."""
from pathlib import Path
import base64,datetime,hashlib,json,re,paramiko
REPO=Path(__file__).resolve().parent.parent;ROOT=REPO.parents[1]
SCOPE=ROOT/'outputs/campaign-speed-fix-20261009/historical-callback-recovery-scope.json'
assert hashlib.sha256(SCOPE.read_bytes()).hexdigest()=='69e58c5661eb6103a7bee09848c568c576ae2c10666dcf022a2f084690ba9d17'
scope=json.loads(SCOPE.read_text())['samples'];samples=json.loads((ROOT/'outputs/campaign-speed-fix-20261009/callback-handoff-readonly.py.result.txt').read_text())['samples']
assert len(samples)==len(scope)==10
expected={item['eventId']:item for item in scope};assert set(expected)=={item['eventId']for item in samples}
for item in samples:
 pin=expected[item['eventId']];assert item['campaign']=='nj855ymroyc89' and hashlib.sha256(item['messageId'].strip('<>').encode()).hexdigest()==pin['messageSha256'] and item['recipientSha256']==pin['recipientSha256'] and item['bounceType']==pin['bounceType']
source=(REPO/'patches/read-historical-feedback-proof.php').read_text();assert source.count('MAGIC_SMTP_SAMPLE_JSON_BASE64')==1;source=source.replace('MAGIC_SMTP_SAMPLE_JSON_BASE64',base64.b64encode(json.dumps(samples).encode()).decode())
values=dict((key.lower(),value.strip())for key,value in re.findall(r'^\s*(HOST|user|pass)\s*:\s*(.*?)\s*$',(ROOT/'Temp-Credentials/Mailwizz.txt').read_text(),re.M|re.I));assert values['host']=='51.77.133.158'
client=paramiko.SSHClient();client.load_host_keys(str(Path.home()/'.ssh/known_hosts'));client.set_missing_host_key_policy(paramiko.RejectPolicy())
try:
 client.connect(values['host'],username=values['user'],password=values['pass'],timeout=15,auth_timeout=15,banner_timeout=15,look_for_keys=False,allow_agent=False)
 stdin,stdout,stderr=client.exec_command('php8.1 -d opcache.enable_cli=0',timeout=45);stdin.write(source);stdin.channel.shutdown_write();raw=stdout.read();err=stderr.read();status=stdout.channel.recv_exit_status()
 if err or status:raise RuntimeError('Read-only proof failed; details withheld')
 result=json.loads(raw);directory=REPO/'.qa/signed-feedback-20261009';directory.mkdir(exist_ok=True);dest=directory/('historical-native-proof-'+datetime.datetime.now(datetime.timezone.utc).strftime('%H%M%S')+'.json');dest.write_text(json.dumps(result,indent=2)+'\n')
 print(json.dumps({'receipt':str(dest),'receiptSha256':hashlib.sha256(dest.read_bytes()).hexdigest(),'at':result['at'],'ok':result['ok'],'sampleCount':len(result['samples']),'missingExactBounces':sum(row['uniqueExactTuples']==1 and row['missingBounce']for row in result['samples']),'schedulerVerified':result['schedulerVerified'],'serverIds':result['serverIds']},indent=2))
finally:client.close()
