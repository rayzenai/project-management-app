#!/usr/bin/env bash
# Sets up RustFS for the tracker's documents; run on the VM as root (docs/onlyoffice.md, "Storage").
# Safe to run again. stdin: the tasks-documents user's secret, one line (48 random letters and
# digits); running it with a new secret rotates the key. Reads the root credentials from the
# running rustfs container, so they never leave the VM, and prints no secret.
#
#   (copy it to the VM)  sudo bash setup-documents.sh < documents-secret
#
# Makes the bucket tasks-documents (versioning on, nothing ever expires; unfinished uploads go
# after 2 days), the policy tasks-documents (objects in that bucket: read and write, never delete,
# never old versions, bucket settings or admin calls) and the user tasks-documents with it, then
# proves as that user that it can write and read but not delete.
set -euo pipefail
read -r APP_SECRET
[ ${#APP_SECRET} -ge 40 ] || { echo "secret too short"; exit 1; }
C=$(docker ps -qf name=rustfs-ra9kxy | head -1); [ -n "$C" ] || { echo "rustfs not running"; exit 1; }
AK=$(docker exec "$C" printenv RUSTFS_ACCESS_KEY); SK=$(docker exec "$C" printenv RUSTFS_SECRET_KEY)
T=$(mktemp -d /root/rustfs-admin.XXXXXX); chmod 700 "$T"; trap 'rm -rf "$T"' EXIT
mkdir -p "$T/home/.config/rc"
cat > "$T/home/.config/rc/config.toml" <<TOML
schema_version = 1

[[aliases]]
name = "sdoc"
endpoint = "http://rustfs:9000"
access_key = "$AK"
secret_key = "$SK"
region = "us-east-1"
TOML
printf 'AWS_ACCESS_KEY_ID=%s\nAWS_SECRET_ACCESS_KEY=%s\nAWS_DEFAULT_REGION=us-east-1\n' "$AK" "$SK" > "$T/root.env"
printf 'AWS_ACCESS_KEY_ID=%s\nAWS_SECRET_ACCESS_KEY=%s\nAWS_DEFAULT_REGION=us-east-1\n' "tasks-documents" "$APP_SECRET" > "$T/app.env"
rc()  { docker run --rm --user 0 -e HOME=/cfg -v "$T/home:/cfg" -v "$T:/work:ro" --network storage rustfs/rc:v0.1.36 "$@"; }
aws() { local who=$1; shift; docker run --rm --env-file "$T/$who.env" -v "$T:/work:ro" --network storage amazon/aws-cli:2.38.6 --endpoint-url http://rustfs:9000 "$@"; }

echo "== bucket tasks-documents"
aws root s3api head-bucket --bucket tasks-documents 2>/dev/null && echo "exists" || { aws root s3api create-bucket --bucket tasks-documents >/dev/null && echo "made"; }
aws root s3api put-bucket-versioning --bucket tasks-documents --versioning-configuration Status=Enabled
aws root s3api get-bucket-versioning --bucket tasks-documents --output text
cat > "$T/lifecycle.json" <<'J'
{"Rules":[{"ID":"abort-unfinished-uploads","Status":"Enabled","Filter":{"Prefix":""},
 "AbortIncompleteMultipartUpload":{"DaysAfterInitiation":2}}]}
J
aws root s3api put-bucket-lifecycle-configuration --bucket tasks-documents --lifecycle-configuration file:///work/lifecycle.json
aws root s3api get-bucket-lifecycle-configuration --bucket tasks-documents --output json | tr -d ' \n'; echo

echo "== policy and user tasks-documents"
cat > "$T/tasks-documents.json" <<'J'
{"Version":"2012-10-17","Statement":[
 {"Sid":"OwnBucket","Effect":"Allow","Action":["s3:ListBucket","s3:GetBucketLocation","s3:ListBucketMultipartUploads"],"Resource":["arn:aws:s3:::tasks-documents"]},
 {"Sid":"OwnObjects","Effect":"Allow","Action":["s3:GetObject","s3:PutObject","s3:AbortMultipartUpload","s3:ListMultipartUploadParts"],"Resource":["arn:aws:s3:::tasks-documents/*"]}
]}
J
rc admin policy create sdoc tasks-documents /work/tasks-documents.json 2>&1 | tail -1
if rc admin user info sdoc tasks-documents >/dev/null 2>&1; then
  rc admin user passwd sdoc tasks-documents "$APP_SECRET" 2>&1 | tail -1 | sed -E 's/[A-Za-z0-9]{30,}/<secret>/g'
else
  rc admin user add sdoc tasks-documents "$APP_SECRET" 2>&1 | tail -1 | sed -E 's/[A-Za-z0-9]{30,}/<secret>/g'
fi
rc admin policy attach sdoc tasks-documents --user tasks-documents 2>&1 | tail -1

echo "== as tasks-documents: write, read, and no delete"
echo "setup check $(date -u +%FT%TZ)" > "$T/check.txt"; chmod 644 "$T/check.txt"
aws app s3 cp /work/check.txt s3://tasks-documents/_setup/check.txt >/dev/null && echo "write: ok"
aws app s3 cp s3://tasks-documents/_setup/check.txt - >/dev/null && echo "read: ok"
if aws app s3 rm s3://tasks-documents/_setup/check.txt >/dev/null 2>&1; then echo "delete: ALLOWED (wrong)"; exit 1; else echo "delete: refused (right)"; fi
aws app s3api list-objects-v2 --bucket sdoc-private --max-keys 1 >/dev/null 2>&1 && { echo "sdoc-private: READABLE (wrong)"; exit 1; } || echo "sdoc-private: refused (right)"
