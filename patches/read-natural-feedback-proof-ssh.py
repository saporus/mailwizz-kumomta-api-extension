"""Read native saved feedback after the fixed binding cutoff, without application bootstrap."""
from pathlib import Path
import datetime,hashlib,json,re,paramiko
REPO=Path(__file__).resolve().parent.parent;ROOT=REPO.parents[1]
source=(REPO/'patches/read-natural-feedback-proof.php').read_text()
values=dict((key.lower(),value.strip())for key,value in re.findall(r'^\s*(HOST|user|pass)\s*:\s*(.*?)\s*$',(ROOT/'Temp-Credentials/Mailwizz.txt').read_text(),re.M|re.I));assert values['host']=='51.77.133.158'
client=paramiko.SSHClient();client.load_host_keys(str(Path.home()/'.ssh/known_hosts'));client.set_missing_host_key_policy(paramiko.RejectPolicy())
try:
 client.connect(values['host'],username=values['user'],password=values['pass'],timeout=15,auth_timeout=15,banner_timeout=15,look_for_keys=False,allow_agent=False)
 stdin,stdout,stderr=client.exec_command('php8.1 -d opcache.enable_cli=0',timeout=45);stdin.write(source);stdin.channel.shutdown_write();raw=stdout.read();err=stderr.read();status=stdout.channel.recv_exit_status()
 if err or status:raise RuntimeError('Read-only native feedback proof failed; details withheld')
 result=json.loads(raw);directory=REPO/'.qa/signed-feedback-20261009';directory.mkdir(exist_ok=True);dest=directory/('natural-native-proof-'+datetime.datetime.now(datetime.timezone.utc).strftime('%H%M%S')+'.json');dest.write_text(json.dumps(result,indent=2)+'\n')
 print(json.dumps({'receipt':str(dest),'receiptSha256':hashlib.sha256(dest.read_bytes()).hexdigest(),'at':result['at'],'ok':result['ok'],'sampleCount':len(result['samples']),'uniqueServer445Samples':sum(row['uniqueServer445Tuple']for row in result['samples']),'postBindingBounces':result['postBindingBounces'],'allCampaignBounces':result['allCampaignBounces'],'campaign':result['campaign'],'applicationBootstrapped':False},indent=2))
finally:client.close()
