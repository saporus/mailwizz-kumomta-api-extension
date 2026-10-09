"""Run the fixed read-only baseline over the already trusted MailWizz SSH host."""
from pathlib import Path
import json, paramiko, re, sys
base=Path(__file__).resolve().parent
root=base.parents[2]
values=dict((k.lower(),v.strip()) for k,v in re.findall(r'^\s*(HOST|user|pass)\s*:\s*(.*?)\s*$',(root/'Temp-Credentials/Mailwizz.txt').read_text(),re.M|re.I))
client=paramiko.SSHClient();client.load_host_keys(str(Path.home()/'.ssh/known_hosts'));client.set_missing_host_key_policy(paramiko.RejectPolicy())
try:
    client.connect(values['host'],username=values['user'],password=values['pass'],timeout=15,auth_timeout=15,banner_timeout=15,look_for_keys=False,allow_agent=False)
    campaign=sys.argv[1:]==['campaign-status'];assert not sys.argv[1:] or campaign
    stdin,stdout,stderr=client.exec_command('php8.1' if campaign else 'python3 -',timeout=30)
    stdin.write((base/('read-short-retry-campaign-status.php' if campaign else 'read-short-retry-baseline.py')).read_text());stdin.channel.shutdown_write()
    raw=stdout.read();error=stderr.read();status=stdout.channel.recv_exit_status()
    if status or error:
        safe=re.search(r'(?:AssertionError|FileNotFoundError):[^\r\n]{0,250}',error.decode(errors='replace'))
        raise RuntimeError('Read-only baseline failed: '+(safe.group(0) if safe else 'output withheld'))
    result=json.loads(raw);assert result['ok'] and result['readOnly']
    output=base.parent/'.qa/short-retry-20261009'/('campaign-status.json' if campaign else 'live-baseline.json')
    output.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result,indent=2))
finally:client.close()
