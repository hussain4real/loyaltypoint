# Deploying Loyalty Point API to Google Cloud Run

This guide walks you through deploying the Loyalty Point API to Google Cloud Run with Cloud SQL (MySQL).

## Prerequisites

1. **Google Cloud Account** with billing enabled
2. **Google Cloud Project** created
3. **macOS** (these instructions are for macOS)

## Step 1: Install Google Cloud CLI

```bash
# Install via Homebrew
brew install google-cloud-sdk

# Initialize and log in
gcloud init

# Authenticate
gcloud auth login
gcloud auth application-default login
```

## Step 2: Set Your Project

```bash
# Set your project ID
export GCP_PROJECT_ID="wafaapp-ae3c7"
export GCP_REGION="me-central1"

# Configure gcloud
gcloud config set project $GCP_PROJECT_ID
gcloud config set run/region $GCP_REGION
```

## Step 3: Run the Setup Script

The setup script will create all necessary GCP resources:
- Cloud SQL instance (MySQL 8.0)
- Artifact Registry repository
- Secret Manager secrets
- Required IAM permissions

```bash
# Make scripts executable
chmod +x scripts/setup-gcp.sh scripts/deploy.sh

# Run setup (this takes ~10 minutes for Cloud SQL creation)
./scripts/setup-gcp.sh
```

**Important:** Save the output values, especially:
- Database password
- APP_KEY
- Cloud SQL connection name

## Step 4: Configure Gmail App Password

1. Go to your Google Account → Security
2. Enable 2-Step Verification if not already enabled
3. Go to "App passwords" (under 2-Step Verification)
4. Create a new app password for "Mail"
5. Update the secret:

```bash
echo -n 'your-16-char-app-password' | gcloud secrets versions add MAIL_PASSWORD --data-file=-
```

## Step 5: Update Environment Variables

The Cloud Run service needs these environment variables. They're set via `cloudbuild.yaml` and Secret Manager.

Update these in the Cloud Run deploy command if needed:

| Variable | Source | Description |
|----------|--------|-------------|
| `APP_KEY` | Secret Manager | Laravel encryption key |
| `DB_PASSWORD` | Secret Manager | Database password |
| `MAIL_PASSWORD` | Secret Manager | Gmail app password |
| `APP_ENV` | Env var | Set to `production` |
| `APP_DEBUG` | Env var | Set to `false` |
| `LOG_CHANNEL` | Env var | Set to `stderr` |

## Step 6: Deploy

```bash
./scripts/deploy.sh
```

This will:
1. Build the Docker image
2. Push to Artifact Registry
3. Deploy to Cloud Run
4. Run database migrations

## Step 7: Verify Deployment

```bash
# Get the service URL
SERVICE_URL=$(gcloud run services describe loyalty-point-api --region=$GCP_REGION --format="value(status.url)")

# Test the health endpoint
curl $SERVICE_URL/up

# Test the API
curl $SERVICE_URL/api/v1/providers
```

## Manual Deployment (Alternative)

If you prefer manual control:

```bash
# Build locally
docker build -t loyalty-point-api .

# Tag for Artifact Registry
docker tag loyalty-point-api $GCP_REGION-docker.pkg.dev/$GCP_PROJECT_ID/loyalty-point/loyalty-point-api:latest

# Push
docker push $GCP_REGION-docker.pkg.dev/$GCP_PROJECT_ID/loyalty-point/loyalty-point-api:latest

# Deploy
gcloud run deploy loyalty-point-api \
  --image=$GCP_REGION-docker.pkg.dev/$GCP_PROJECT_ID/loyalty-point/loyalty-point-api:latest \
  --region=$GCP_REGION \
  --platform=managed \
  --allow-unauthenticated \
  --add-cloudsql-instances=$GCP_PROJECT_ID:$GCP_REGION:loyalty-point-db \
  --set-env-vars="APP_ENV=production,APP_DEBUG=false,LOG_CHANNEL=stderr,DB_CONNECTION=mysql,DB_SOCKET=/cloudsql/$GCP_PROJECT_ID:$GCP_REGION:loyalty-point-db,DB_DATABASE=loyalty_point,DB_USERNAME=loyalty_user,MAIL_MAILER=smtp,MAIL_HOST=smtp.gmail.com,MAIL_PORT=587,MAIL_USERNAME=your-email@gmail.com,MAIL_ENCRYPTION=tls" \
  --set-secrets="APP_KEY=APP_KEY:latest,DB_PASSWORD=DB_PASSWORD:latest,MAIL_PASSWORD=MAIL_PASSWORD:latest" \
  --memory=512Mi \
  --cpu=1 \
  --min-instances=0 \
  --max-instances=10
```

## Continuous Deployment (Optional)

Set up automatic deployments on push to main:

1. **Connect Repository:**
   ```bash
   gcloud builds triggers create github \
     --repo-name=loyaltypoint \
     --repo-owner=hussain4real \
     --branch-pattern="^main$" \
     --build-config=cloudbuild.yaml
   ```

2. **Or use Cloud Console:**
   - Go to Cloud Build → Triggers
   - Create trigger
   - Connect to GitHub
   - Select repository and branch

## Viewing Logs

```bash
# Stream logs
gcloud run logs tail loyalty-point-api --region=$GCP_REGION

# View in Cloud Console
gcloud run services logs read loyalty-point-api --region=$GCP_REGION --limit=50
```

## Troubleshooting

### Container fails to start
```bash
# Check logs
gcloud run logs read loyalty-point-api --region=$GCP_REGION --limit=100

# Check Cloud Build logs
gcloud builds list --limit=5
gcloud builds log BUILD_ID
```

### Database connection issues
```bash
# Verify Cloud SQL instance is running
gcloud sql instances describe loyalty-point-db

# Check Cloud SQL connection name format
# Should be: PROJECT_ID:REGION:INSTANCE_NAME
```

### Secret access denied
```bash
# Re-grant permissions
PROJECT_NUMBER=$(gcloud projects describe $GCP_PROJECT_ID --format="value(projectNumber)")
SERVICE_ACCOUNT="${PROJECT_NUMBER}-compute@developer.gserviceaccount.com"

gcloud secrets add-iam-policy-binding APP_KEY \
  --member="serviceAccount:${SERVICE_ACCOUNT}" \
  --role="roles/secretmanager.secretAccessor"
```

## Cost Optimization

- **Cloud Run:** Billed per request, scales to zero
- **Cloud SQL:** Consider `db-f1-micro` for development (~$7/month)
- **Artifact Registry:** Minimal cost for Docker images
- **Secret Manager:** Free tier covers typical usage

## Architecture

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   Cloud Run     │────▶│   Cloud SQL     │     │ Secret Manager  │
│  (Laravel API)  │     │    (MySQL)      │     │   (Secrets)     │
└─────────────────┘     └─────────────────┘     └─────────────────┘
        │
        ▼
┌─────────────────┐
│ Artifact Registry│
│ (Docker Images)  │
└─────────────────┘
```
