"""Separate prepare/apply wrapper. Private binding bytes stay on MailWizz host."""
from pathlib import Path
import argparse,datetime,errno,hashlib,json,re,shlex
REPO=Path(__file__).resolve().parent.parent
STAGE='/var/tmp/magicsmtp-binding445-20261009-v2';BACKUP='/root/magicsmtp-binding445-backup-20261009-v2'
HELPER=REPO/'patches/binding445-release.py';HELPER_SHA='4cb7197e4bc3bceed05ad863989997319ab215f66f803c4d1e728957e7789cf8'
def execute(mode,pin=None):
 if hashlib.sha256(HELPER.read_bytes()).hexdigest()!=HELPER_SHA:raise RuntimeError('Reviewed binding helper changed')
 if mode not in ['plan','prepare'] and not re.fullmatch('[a-f0-9]{64}',pin or ''):raise RuntimeError('Exact prepared manifest SHA256 required')
 command=shlex.join(['python3',STAGE+'/binding445-release.py',mode]+([pin]if pin else[]))
 if mode=='plan':return {'ok':True,'remoteExecuted':False,'stage':STAGE,'backup':BACKUP,'helperSha256':HELPER_SHA,'next':'prepare uploads helper and creates a private exact one-field candidate; apply requires its manifest pin separately'}
 import paramiko
 values=dict((key.lower(),value.strip())for key,value in re.findall(r'^\s*(HOST|user|pass)\s*:\s*(.*?)\s*$',(REPO.parents[1]/'Temp-Credentials/Mailwizz.txt').read_text(),re.M|re.I))
 if values['host']not in ['51.77.133.158','servermail2.com']:raise RuntimeError('Established MailWizz host required')
 client=paramiko.SSHClient();client.load_host_keys(str(Path.home()/'.ssh/known_hosts'));client.set_missing_host_key_policy(paramiko.RejectPolicy())
 report={'ok':False,'mode':mode,'helperSha256':HELPER_SHA,'manifestSha256':pin,'applyAttempted':False}
 def run(value):
  stdin,stdout,stderr=client.exec_command(value,timeout=45);stdin.channel.shutdown_write();out=stdout.read();err=stderr.read();status=stdout.channel.recv_exit_status()
  if err or status:raise RuntimeError('Scoped binding command failed; details withheld')
  result=json.loads(out)
  if not result.get('ok'):raise RuntimeError('Scoped binding result failed')
  return result
 try:
  client.connect(values['host'],username=values['user'],password=values['pass'],timeout=15,auth_timeout=15,banner_timeout=15,look_for_keys=False,allow_agent=False)
  with client.open_sftp()as sftp:
   if mode=='prepare':
    try:sftp.lstat(STAGE)
    except IOError as failure:
     if failure.errno!=errno.ENOENT:raise
    else:raise RuntimeError('Private stage exists; inspect instead of repeating preparation')
    sftp.mkdir(STAGE,mode=0o700);sftp.chmod(STAGE,0o700)
    with sftp.open(STAGE+'/binding445-release.py','wx')as stream:stream.write(HELPER.read_bytes())
    sftp.chmod(STAGE+'/binding445-release.py',0o600)
   with sftp.open(STAGE+'/binding445-release.py','rb')as stream:
    if hashlib.sha256(stream.read()).hexdigest()!=HELPER_SHA:raise RuntimeError('Remote helper changed')
  report['applyAttempted']=mode=='apply';report['result']=run(command)
  if mode=='apply':report['verify']=run(shlex.join(['python3',STAGE+'/binding445-release.py','verify',pin]))
  report['ok']=True
 except Exception as failure:
  report['error']=str(failure)if isinstance(failure,RuntimeError)else type(failure).__name__
  if report['applyAttempted']:
   try:report['reconcile']=run(shlex.join(['python3',STAGE+'/binding445-release.py','reconcile',pin]))
   except Exception as second:report['reconcileError']=type(second).__name__
 finally:
  client.close();directory=REPO/'.qa/binding445-20261009';directory.mkdir(exist_ok=True);dest=directory/(mode+'-'+datetime.datetime.now(datetime.timezone.utc).strftime('%H%M%S')+'.json');dest.write_text(json.dumps(report,indent=2)+'\n')
 return {'ok':report['ok'],'receipt':str(dest),'result':report}
if __name__=='__main__':
 parser=argparse.ArgumentParser();parser.add_argument('mode',nargs='?',default='plan',choices=['plan','prepare','dry-run','apply','verify','reconcile','rollback']);parser.add_argument('--manifest-sha256');args=parser.parse_args();result=execute(args.mode,args.manifest_sha256);print(json.dumps(result,indent=2));raise SystemExit(0 if result['ok']else 1)
