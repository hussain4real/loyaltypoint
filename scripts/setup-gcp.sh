#!/bin/bash
# =============================================================================
# GCP Initial Setup Script for Loyalty Point API
# Run this once to set up all GCP resources
# =============================================================================

set -e

# Configuration - UPDATE THESE VALUES
PROJECT_ID="${GCP_PROJECT_ID:-wafaapp-ae3c7}"
REGION="${GCP_REGION:-me-central1}"
SERVICE_NAME="loyalty-point-api"
REPOSITORY_NAME="loyalty-point"
DB_INSTANCE_NAME="loyalty-point-db"
DB_NAME="loyalty_point"
DB_USER="loyalty_user"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}=== Loyalty Point API - GCP Setup ===${NC}"
echo ""

# Check if gcloud is installed
if ! command -v gcloud &> /dev/null; then
    echo -e "${RED}Error: gcloud CLI is not installed.${NC}"
    echo "Install it from: https://cloud.google.com/sdk/docs/install"
    exit 1
fi

# Check if logged in
if ! gcloud auth list --filter=status:ACTIVE --format="value(account)" | head -1 > /dev/null 2>&1; then
    echo -e "${YELLOW}Please log in to Google Cloud...${NC}"
    gcloud auth login
fi

# Set project
echo -e "${YELLOW}Setting project to: ${PROJECT_ID}${NC}"
gcloud config set project ${PROJECT_ID}

# Enable required APIs
echo -e "${YELLOW}Enabling required APIs...${NC}"
gcloud services enable \
    cloudbuild.googleapis.com \
    run.googleapis.com \
    sqladmin.googleapis.com \
    secretmanager.googleapis.com \
    artifactregistry.googleapis.com

# Create Artifact Registry repository
echo -e "${YELLOW}Creating Artifact Registry repository...${NC}"
gcloud artifacts repositories create ${REPOSITORY_NAME} \
    --repository-format=docker \
    --location=${REGION} \
    --description="Docker images for Loyalty Point API" \
    2>/dev/null || echo "Repository already exists"

# Create Cloud SQL instance (MySQL 8.0)
echo -e "${YELLOW}Creating Cloud SQL instance (this may take a few minutes)...${NC}"
gcloud sql instances create ${DB_INSTANCE_NAME} \
    --database-version=MYSQL_8_0 \
    --tier=db-f1-micro \
    --region=${REGION} \
    --storage-type=SSD \
    --storage-size=10GB \
    --storage-auto-increase \
    --backup-start-time=03:00 \
    --maintenance-window-day=SUN \
    --maintenance-window-hour=04 \
    2>/dev/null || echo "SQL instance already exists"

# Generate a random password for the database
DB_PASSWORD=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 24)

# Create database user
echo -e "${YELLOW}Creating database user...${NC}"
gcloud sql users create ${DB_USER} \
    --instance=${DB_INSTANCE_NAME} \
    --password=${DB_PASSWORD} \
    2>/dev/null || echo "User already exists, updating password..."

gcloud sql users set-password ${DB_USER} \
    --instance=${DB_INSTANCE_NAME} \
    --password=${DB_PASSWORD}

# Create database
echo -e "${YELLOW}Creating database...${NC}"
gcloud sql databases create ${DB_NAME} \
    --instance=${DB_INSTANCE_NAME} \
    2>/dev/null || echo "Database already exists"

# Generate Laravel APP_KEY
echo -e "${YELLOW}Generating Laravel APP_KEY...${NC}"
APP_KEY="base64:$(openssl rand -base64 32)"

# Create secrets in Secret Manager
echo -e "${YELLOW}Creating secrets in Secret Manager...${NC}"

# APP_KEY secret
echo -n "${APP_KEY}" | gcloud secrets create APP_KEY --data-file=- 2>/dev/null || \
echo -n "${APP_KEY}" | gcloud secrets versions add APP_KEY --data-file=-

# DB_PASSWORD secret
echo -n "${DB_PASSWORD}" | gcloud secrets create DB_PASSWORD --data-file=- 2>/dev/null || \
echo -n "${DB_PASSWORD}" | gcloud secrets versions add DB_PASSWORD --data-file=-

# MAIL_PASSWORD secret (placeholder - update later)
echo -n "your-gmail-app-password" | gcloud secrets create MAIL_PASSWORD --data-file=- 2>/dev/null || \
echo "MAIL_PASSWORD secret already exists"

# Get Cloud SQL connection name
SQL_CONNECTION=$(gcloud sql instances describe ${DB_INSTANCE_NAME} --format="value(connectionName)")

# Grant Cloud Run service account access to secrets
PROJECT_NUMBER=$(gcloud projects describe ${PROJECT_ID} --format="value(projectNumber)")
SERVICE_ACCOUNT="${PROJECT_NUMBER}-compute@developer.gserviceaccount.com"

echo -e "${YELLOW}Granting secret access to Cloud Run service account...${NC}"
for SECRET in APP_KEY DB_PASSWORD MAIL_PASSWORD; do
    gcloud secrets add-iam-policy-binding ${SECRET} \
        --member="serviceAccount:${SERVICE_ACCOUNT}" \
        --role="roles/secretmanager.secretAccessor" \
        2>/dev/null || true
done

# Grant Cloud SQL Client role
gcloud projects add-iam-policy-binding ${PROJECT_ID} \
    --member="serviceAccount:${SERVICE_ACCOUNT}" \
    --role="roles/cloudsql.client" \
    2>/dev/null || true

echo ""
echo -e "${GREEN}=== Setup Complete! ===${NC}"
echo ""
echo -e "${YELLOW}Important values (save these):${NC}"
echo "----------------------------------------"
echo "Project ID:           ${PROJECT_ID}"
echo "Region:               ${REGION}"
echo "Cloud SQL Connection: ${SQL_CONNECTION}"
echo "Database Name:        ${DB_NAME}"
echo "Database User:        ${DB_USER}"
echo "Database Password:    ${DB_PASSWORD}"
echo "APP_KEY:              ${APP_KEY}"
echo "----------------------------------------"
echo ""
echo -e "${YELLOW}Next steps:${NC}"
echo "1. Update MAIL_PASSWORD secret with your Gmail App Password:"
echo "   echo -n 'your-app-password' | gcloud secrets versions add MAIL_PASSWORD --data-file=-"
echo ""
echo "2. Create .env.production with these values (see .env.production.example)"
echo ""
echo "3. Deploy the application:"
echo "   ./scripts/deploy.sh"
echo ""
