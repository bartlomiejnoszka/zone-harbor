#!/usr/bin/env python3
"""Exercise the real setup wizard in a PTY using isolated settings and synthetic keys."""
import fcntl
import os
from pathlib import Path
import pty
import select
import shutil
import struct
import subprocess
import tempfile
import termios
import time

ROOT = Path(__file__).resolve().parent.parent
PHP = shutil.which('php')

class Session:
    def __init__(self, args, environment):
        self.master, self.slave = pty.openpty()
        self.original = termios.tcgetattr(self.slave)
        fcntl.ioctl(self.slave, termios.TIOCSWINSZ, struct.pack('HHHH', 34, 120, 0, 0))
        self.process = subprocess.Popen([PHP, str(ROOT / 'bin/zone-harbor'), *args], cwd=tempfile.gettempdir(),
                                        stdin=self.slave, stdout=self.slave, stderr=self.slave,
                                        env=environment, start_new_session=True)
        self.output = b''
    def pump(self, seconds=.15):
        end = time.monotonic() + seconds
        while time.monotonic() < end:
            if select.select([self.master], [], [], min(.05, max(0, end-time.monotonic())))[0]:
                self.output += os.read(self.master, 65536)
    def wait(self, marker):
        end = time.monotonic()+15
        while marker.encode() not in self.output:
            if self.process.poll() is not None: raise AssertionError('Wizard exited before expected prompt: '+marker)
            if time.monotonic() > end: raise AssertionError('Wizard prompt timed out: '+marker)
            self.pump(.05)
        self.pump()
    def send(self, value):
        self.output = b''
        os.write(self.master, value.encode())
        self.pump()
    def finish(self):
        end = time.monotonic()+15
        while self.process.poll() is None and time.monotonic()<end: self.pump(.05)
        assert self.process.poll() == 0, 'Wizard did not finish successfully'
        self.pump()
        restored = termios.tcgetattr(self.slave)
        restored[3] &= ~getattr(termios, 'PENDIN', 0)
        self.original[3] &= ~getattr(termios, 'PENDIN', 0)
        assert restored == self.original, 'Wizard did not restore terminal settings'
    def close(self):
        if self.process.poll() is None: self.process.kill(); self.process.wait()
        os.close(self.master); os.close(self.slave)

with tempfile.TemporaryDirectory(prefix='zone-harbor-wizard-') as directory:
    environment = dict(os.environ)
    for key in ['OVH_APPLICATION_KEY','OVH_APPLICATION_SECRET','OVH_CONSUMER_KEY','OVH_ENDPOINT','MCP_HTTP_TOKEN','APP_SECRET','ZONE_HARBOR_CONFIG']:
        environment.pop(key, None); environment.pop(key+'_FILE', None)
    environment['XDG_CONFIG_HOME'] = directory+'/xdg'
    session = Session(['setup','--config',directory+'/public/config.yaml','--skip-checks'], environment)
    try:
        session.wait('What would you like'); session.send('\r')
        session.wait('Default domain'); session.send('example.com\r')
        session.wait('Mail provider expectation'); session.send('\r')
        session.wait('DKIM selectors'); session.send('\r')
        session.wait('MCP client settings'); session.send('generic\r')
        session.wait('Save these settings'); session.send('yes\r'); session.finish()
        assert b'mcpServers' in session.output
        assert Path(directory+'/public/config.yaml').is_file()
    finally: session.close()
    if not (ROOT / '.env.local').exists():
        session = Session([], environment)
        try:
            session.wait('What would you like'); session.send('\r')
            session.wait('Default domain'); session.send('\r')
            session.wait('MCP client settings'); session.send('\r')
            session.wait('Save these settings'); session.send('yes\r'); session.finish()
            assert Path(directory+'/xdg/zone-harbor/config.yaml').is_file()
        finally: session.close()
    session = Session(['setup','--config',directory+'/ovh/config.yaml','--mode=ovh','--skip-checks'], environment)
    secrets = ['test-hidden-key','test-hidden-secret','test-hidden-consumer']
    try:
        session.wait('OVH region'); session.send('\r')
        for prompt, value in zip(['Application key','Application secret','Consumer key'], secrets):
            session.wait(prompt); session.send(value+'\r')
            assert value.encode() not in session.output, 'Hidden credential was echoed'
        session.wait('Default domain'); session.send('\r')
        session.wait('MCP client settings'); session.send('\r')
        session.wait('Save these settings'); session.send('yes\r'); session.finish()
        for secret in secrets: assert secret.encode() not in session.output
        assert len(list(Path(directory+'/ovh').glob('credentials-*.env'))) == 1
    finally: session.close()
print('Onboarding PTY checks passed: setup flow, hidden credentials, generated MCP JSON and terminal restoration.')
