#!/usr/bin/env bash

# Render a header for a section of related functionality within a script.
function section_header() {
	MESSAGE="$1"
	BORDER="${MESSAGE//[^-]/-}"
    printf "\n%s\n%s\n%s\n" "$BORDER" "$MESSAGE" "$BORDER";
}

# Based on https://gist.github.com/dmadisetti/16006751fd6e1526fa9c2f2e1660e8e3

# Generates a wildcard certificate for a given domain name.

# Fail on any error (and yes, I know this not perfect)
set -e

section_header "Creating Certificates..."

# Set domain
DOMAIN=$1
WILDCARD="*.$DOMAIN"

# Set our variables
cat <<EOF > req.cnf
[req]
distinguished_name = req_distinguished_name
x509_extensions = v3_req
prompt = no
[req_distinguished_name]
C = US
ST = NY
O = _Nexstar
localityName = New York
commonName = $WILDCARD
organizationalUnitName = Nexstar
emailAddress = info@$DOMAIN
[v3_req]
keyUsage = critical, nonRepudiation, digitalSignature, keyEncipherment, keyAgreement
extendedKeyUsage = serverAuth
subjectAltName = @alt_names
[alt_names]
DNS.1 = $DOMAIN
DNS.2 = $WILDCARD
EOF

# Generate our Private Key, and Certificate directly
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout "$DOMAIN.key" -config req.cnf \
  -out "$DOMAIN.crt" -sha256
rm req.cnf

# Move certs
rm -f ${PWD}/docker/sni/cert/altis.pem
cp ${DOMAIN}.crt ${PWD}/docker/sni/cert/altis.pem
mv ${DOMAIN}.crt ${PWD}/${DOMAIN}.crt

rm -f ${PWD}/docker/sni/key/altis.pem
mv ${DOMAIN}.key ${PWD}/docker/sni/key/altis.pem

echo "Remember to import ./$DOMAIN.crt into your browser or OS and trust it."