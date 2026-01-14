#!/bin/bash
# =============================================================================
# Deploy script for Loyalty Point API to Cloud Run
# =============================================================================

set -e

# Configuration - UPDATE THESE VALUES or set as environment variables
PROJECT_ID="${GCP_PROJECT_ID:-wafaapp-ae3c7}"
REGION="${GCP_REGION:-me-central1}"
SERVICE_NAME="loyalty-point-api"
REPOSITORY_NAME="loyalty-point"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${GREEN}=== Deploying Loyalty Point API to Cloud Run ===${NC}"
echo ""

# Check if gcloud is installed
if ! command -v gcloud &> /dev/null; then
    echo -e "${RED}Error: gcloud CLI is not installed.${NC}"
    exit 1
fi

# Set project
gcloud config set project ${PROJECT_ID}

# Generate a build tag (use git short SHA if available, otherwise timestamp)
if git rev-parse --short HEAD > /dev/null 2>&1; then
    BUILD_TAG=$(git rev-parse --short HEAD)
else
    BUILD_TAG=$(date +%Y%m%d%H%M%S)
fi
echo -e "${YELLOW}Build tag: ${BUILD_TAG}${NC}"

# Get Cloud SQL connection name
SQL_CONNECTION=$(gcloud sql instances describe loyalty-point-db --format="value(connectionName)" 2>/dev/null || echo "")

if [ -z "$SQL_CONNECTION" ]; then
    echo -e "${RED}Error: Cloud SQL instance not found. Run setup-gcp.sh first.${NC}"
    exit 1
fi

# Build and deploy using Cloud Build
echo -e "${YELLOW}Starting Cloud Build...${NC}"
gcloud builds submit \
    --config=cloudbuild.yaml \
    --substitutions="_REGION=${REGION},_REPOSITORY=${REPOSITORY_NAME},_SERVICE_NAME=${SERVICE_NAME},_CLOUD_SQL_CONNECTION=${SQL_CONNECTION},SHORT_SHA=${BUILD_TAG}"

# Get the service URL
SERVICE_URL=$(gcloud run services describe ${SERVICE_NAME} --region=${REGION} --format="value(status.url)")

echo ""
echo -e "${GREEN}=== Deployment Complete! ===${NC}"
echo ""
echo -e "Service URL: ${GREEN}${SERVICE_URL}${NC}"
echo ""
echo -e "${YELLOW}Test the API:${NC}"
echo "curl ${SERVICE_URL}/api/v1/providers"
echo ""
