Intelligence Briefing API (Simplified Docker)
This is a standalone PHP application that acts as an asynchronous API for generating detailed company intelligence briefings. It is designed for a simple, single-service deployment on Render using a Docker environment.

Required Files

Make sure your project repository includes:

index.php

worker.php

composer.json

Dockerfile (the one currently in the project)

Deployment Instructions

Please delete any previous applications on Render and start fresh with this guide.

Part 1: Create the Web Service & Disk

From your Render Dashboard, click "New +" and select "Web Service".

Connect your GitHub account and select your student-prep-api repository.

Fill in the settings exactly as follows:

Name: student-prep-api

Environment: Docker

Region: Choose your preferred region.

Scroll down to the "Advanced" section and click "Add Disk".

A new form will appear. Fill it in as follows:

Name: data-disk

Mount Path: /data

Size: 1 GB is sufficient.

Click "Create Web Service".

Part 2: Configure Environment Variables

After the service is created, go to the "Environment" tab.

Add two secret variables:

GEMINI_API_KEY: Paste your actual Gemini API key here.

APP_SECRET_KEY: Paste your own long, random secret key here for your API.

API Endpoints

Once deployed, your API will be available at the URL provided by Render. This architecture does not use clean URLs. You must call the index.php file directly.

1. Create a Briefing Job

Method: POST

URL: https://your-service-name.onrender.com/index.php?action=create-briefing

Headers:

Content-Type: application/json

X-API-KEY: your_app_secret_key

Body (JSON):

{
  "company_name": "NVIDIA",
  "job_title": "Software Engineer",
  "include_podcast": true
}

2. Check Job Status

Method: GET

URL: https://your-service-name.onrender.com/index.php?action=briefing-status&job_id={job_id}

Headers:

X-API-KEY: your_app_secret_key