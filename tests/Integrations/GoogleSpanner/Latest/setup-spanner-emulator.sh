#!/usr/bin/env bash

set -xe
PROJECT_ID="emulator-project"
SPANNER_EMULATOR_URL="http://spanner.local:9020/"
INSTANCE_NAME="test-instance"
DATABASE_NAME="test-database"

gcloud config configurations create emulator
gcloud config set auth/disable_credentials true
gcloud config set project $PROJECT_ID
gcloud config set api_endpoint_overrides/spanner $SPANNER_EMULATOR_URL
gcloud config set auth/disable_credentials true
gcloud spanner instances create $INSTANCE_NAME --config=emulator-config --description=Emulator --nodes=1
gcloud spanner databases create $DATABASE_NAME --instance=$INSTANCE_NAME
gcloud spanner databases ddl update $DATABASE_NAME --instance=$INSTANCE_NAME --ddl='CREATE TABLE users ( UserId INT64 NOT NULL, FirstName STRING(1024), LastName STRING(1024), UsedrInfo BYTES(MAX) ) PRIMARY KEY (UserId)'

#gcloud spanner databases delete $DATABASE_NAME --instance=$INSTANCE_NAME
