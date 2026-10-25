#!/usr/bin/env bash
set -euo pipefail
fixture_dir=${1:?Supply an isolated output directory}
mkdir -p "$fixture_dir"
chmod 700 "$fixture_dir"
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$fixture_dir/ca.key" -out "$fixture_dir/ca.crt" -days 2 -subj /CN=MrCache-Test-CA > "$fixture_dir/openssl.log" 2>&1
openssl req -newkey rsa:2048 -nodes -keyout "$fixture_dir/server.key" -out "$fixture_dir/server.csr" -subj /CN=localhost >> "$fixture_dir/openssl.log" 2>&1
printf '%s\n' 'subjectAltName=DNS:localhost,IP:127.0.0.1' > "$fixture_dir/server.ext"
openssl x509 -req -in "$fixture_dir/server.csr" -CA "$fixture_dir/ca.crt" -CAkey "$fixture_dir/ca.key" -CAcreateserial -out "$fixture_dir/server.crt" -days 2 -extfile "$fixture_dir/server.ext" >> "$fixture_dir/openssl.log" 2>&1
python3 - "$fixture_dir" <<'PY'
from pathlib import Path
import secrets,sys
base=Path(sys.argv[1]);password=secrets.token_hex(24)
for name,text in [('password',password),('acl.conf','user default off\nuser mrcache on >'+password+' ~mrcache_test_*:* +@all\n')]:
    path=base/name;path.write_text(text);path.chmod(0o600)
for name in ['ca.key','server.key']: (base/name).chmod(0o600)
PY
