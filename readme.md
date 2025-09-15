Intelligence Briefing API
This is a standalone, asynchronous API for generating detailed company intelligence briefings and podcasts.

Architecture Overview
The API uses an asynchronous, job-based architecture to handle long-running tasks.

Start Job (POST): The client sends a request with a company name. The API immediately creates a job, saves it to a file on a persistent disk, and returns a unique job_id.

Background Worker: An independent PHP process (worker.php) is started in the background to perform the multi-step AI analysis and podcast generation.

Check Status (GET): The client periodically polls a status endpoint using the job_id to see if the job is queued, processing, or complete.

Download Results: Once complete, the status response will contain URLs to download the final briefing document and podcast MP3.

Deployment to Render
This service is designed to be deployed on Render using Docker.

1. Project Setup on GitHub

Push the following files to your GitHub repository:

index.php (the main API file)

worker.php (the background worker)

composer.json (PHP dependencies)

Dockerfile (Render build instructions)

You no longer need .htaccess or 000-default.conf.

2. Service Setup on Render

Create a new Web Service on Render and connect it to your GitHub repository.

During setup, use the following settings:

Environment: Docker

Start Command: (Leave this blank, the Dockerfile handles it)

Add a Persistent Disk:

Mount Path: /data

Size: 1 GB is sufficient to start.

Add your Environment Variables:

GEMINI_API_KEY: Your secret key from Google AI Studio.

APP_SECRET_KEY: A long, random string you create to secure your API.

API Usage
Endpoint URL Structure

The API no longer uses pretty URLs. All actions are routed through index.php using a query parameter.

Base URL: https://your-service-name.onrender.com

1. Create a New Briefing

Method: POST

Endpoint: [Base URL]/index.php?action=create-briefing

Headers:

Content-Type: application/json

X-API-KEY: [Your APP_SECRET_KEY]

Body (JSON):

{
  "company_name": "NVIDIA",
  "job_title": "Software Engineer"
}

Note: job_title is optional.

Success Response (202 Accepted):

{
  "job_id": "briefing_65a5a1b9c3b2a1.23456789",
  "status": "queued"
}

2. Check Job Status

Method: GET

Endpoint: [Base URL]/index.php?action=briefing-status&job_id=[Your Job ID]

Headers:

X-API-KEY: [Your APP_SECRET_KEY]

Response (when complete):

{
  "job_id": "briefing_65a5a1b9c3b2a1.23456789",
  "status": "complete",
  // ... other job data ...
  "result": {
    "briefing_url": "https://.../index.php?action=get-file&job_id=...&type=briefing",
    "podcast_url": "https://.../index.php?action=get-file&job_id=...&type=podcast"
  }
}
