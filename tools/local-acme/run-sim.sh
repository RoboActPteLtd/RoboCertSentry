#!/usr/bin/env bash
#
# Reproduces the local ACME simulation end to end. See README.md for what it
# verifies and the known Pebble limitation. Designed to be run from this directory.
#
#   ./run-sim.sh            # bring up + verify
#   docker compose down -v && docker rm -f rcs-acme-sim-nginx   # teardown
#
# Windows/Git-Bash note: run with MSYS_NO_PATHCONV=1 so container paths are not
# mangled, e.g.  MSYS_NO_PATHCONV=1 ./run-sim.sh

set -euo pipefail
export MSYS_NO_PATHCONV=1

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="$(cd "$here/../.." && pwd)"
plesk=rcs-acme-sim-plesk-1
pebble=rcs-acme-sim-pebble-1

echo "==> Bringing up Plesk + Pebble + challtestsrv"
( cd "$here" && docker compose up -d )

echo "==> Waiting for Plesk (up to ~5 min)"
for i in $(seq 1 30); do
  if docker exec "$plesk" plesk version >/dev/null 2>&1; then echo "    ready"; break; fi
  sleep 10
done

echo "==> Generating spoof CA + acme-v02 cert, extracting Pebble CA"
mkdir -p "$here/certs"
docker exec "$plesk" bash -c '
  cd /tmp
  openssl genrsa -out ca.key 2048 2>/dev/null
  openssl req -x509 -new -nodes -key ca.key -sha256 -days 30 -subj "/CN=RCS Sim Spoof CA" -out ca.crt 2>/dev/null
  openssl genrsa -out acme.key 2048 2>/dev/null
  openssl req -new -key acme.key -subj "/CN=acme-v02.api.letsencrypt.org" -out acme.csr 2>/dev/null
  printf "subjectAltName=DNS:acme-v02.api.letsencrypt.org\nbasicConstraints=CA:FALSE\n" > ext.cnf
  openssl x509 -req -in acme.csr -CA ca.crt -CAkey ca.key -CAcreateserial -days 30 -extfile ext.cnf -out acme.crt 2>/dev/null'
docker cp "$plesk:/tmp/ca.crt" "$here/certs/ca.crt"
docker cp "$plesk:/tmp/acme.crt" "$here/certs/acme.crt"
docker cp "$plesk:/tmp/acme.key" "$here/certs/acme.key"
docker cp "$pebble:/test/certs/pebble.minica.pem" "$here/pebble.minica.pem"

echo "==> Building and installing the RoboCertSentry extension"
docker exec "$plesk" rm -rf /tmp/rcs /tmp/rcs.zip
docker exec "$plesk" mkdir -p /tmp/rcs/plib
docker cp "$repo/meta.xml" "$plesk:/tmp/rcs/meta.xml"
docker cp "$repo/plib/." "$plesk:/tmp/rcs/plib/"
docker cp "$repo/src" "$plesk:/tmp/rcs/plib/src"
docker cp "$repo/data" "$plesk:/tmp/rcs/plib/data"
docker exec "$plesk" bash -c '
  command -v zip >/dev/null || (apt-get update -qq && apt-get install -y -qq zip) >/dev/null 2>&1
  cd /tmp/rcs && zip -rq /tmp/rcs.zip .
  plesk bin extension --uninstall robocertsentry >/dev/null 2>&1 || true
  rm -f /opt/psa/var/modules/robocertsentry/issuance.sqlite
  plesk bin extension --install /tmp/rcs.zip'

echo "==> Trusting both CAs in Plesk, pointing acme-v02 at the spoof, starting nginx"
docker cp "$here/certs/ca.crt" "$plesk:/usr/local/share/ca-certificates/rcs-spoof-ca.crt"
docker cp "$here/pebble.minica.pem" "$plesk:/usr/local/share/ca-certificates/pebble-minica.crt"
docker exec "$plesk" bash -c '
  update-ca-certificates >/dev/null 2>&1
  grep -q acme-v02 /etc/hosts || echo "172.28.0.40 acme-v02.api.letsencrypt.org" >> /etc/hosts'
net="$(docker network ls --format '{{.Name}}' | grep acme | head -1)"
docker rm -f rcs-acme-sim-nginx >/dev/null 2>&1 || true
docker run -d --name rcs-acme-sim-nginx --network "$net" --ip 172.28.0.40 \
  -v "$here":/conf:ro nginx:alpine nginx -c /conf/acme-spoof.nginx.conf -g 'daemon off;' >/dev/null

echo "==> Creating hosted test domain + a Let's-Encrypt-issuer cert in its repository"
docker cp "$here/fake-le-openssl.cnf" "$plesk:/tmp/le.cnf"
docker exec "$plesk" bash -c '
  plesk bin subscription --remove example.test >/dev/null 2>&1 || true
  plesk bin subscription --create example.test -owner admin -ip 172.28.0.10 -login exuser -passwd "Xx!23456ab9" -hosting true >/dev/null 2>&1
  cd /tmp
  openssl req -x509 -newkey rsa:2048 -nodes -keyout le.key -out le.crt -days 90 -config /tmp/le.cnf 2>/dev/null
  plesk bin certificate -c le-sim -domain example.test -key-file /tmp/le.key -cert-file /tmp/le.crt >/dev/null 2>&1'

echo ""
echo "==> ITEM 1: does an SSL binding event drive the reconcile and record the issuance?"
docker exec "$plesk" bash -c '
  rm -f /opt/psa/var/modules/robocertsentry/issuance.sqlite
  plesk bin site --update example.test -ssl true -certificate-name "" >/dev/null 2>&1; sleep 1
  plesk bin site --update example.test -ssl true -certificate-name le-sim >/dev/null 2>&1; sleep 4
  /opt/psa/admin/bin/php -r "\$f=\"/opt/psa/var/modules/robocertsentry/issuance.sqlite\"; if(!file_exists(\$f)){echo \"  RESULT: no ledger (handler did not run)\n\";exit(1);} \$p=new PDO(\"sqlite:\$f\"); \$n=\$p->query(\"SELECT COUNT(*) FROM issuances\")->fetchColumn(); echo \"  RESULT: \$n issuance(s) recorded via the binding event\n\"; foreach(\$p->query(\"SELECT canonical_key FROM issuances\") as \$r){echo \"    \".\$r[0].\"\n\";}"'

echo ""
echo "==> ITEM 2: attempt a real issuance via the local CA and capture the log format"
docker exec "$plesk" bash -c '
  rm -f /usr/local/psa/var/modules/letsencrypt/registrations/*.json 2>/dev/null || true
  plesk bin extension --exec letsencrypt cli.php -d example.test -m admin@example.test >/dev/null 2>&1 || true
  echo "  panel.log [extension/letsencrypt] lines:"
  grep "extension/letsencrypt" /var/log/plesk/panel.log | tail -4 | sed "s/^/    /"'

echo ""
echo "==> Done. Teardown: docker compose down -v && docker rm -f rcs-acme-sim-nginx"
