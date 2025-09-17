Intelligence Briefing API
This is a standalone PHP application that acts as an asynchronous API for generating detailed company intelligence briefings and podcasts. It is designed to be deployed on Render as a multi-service application.

IMPORTANT: Do not use a render.yaml file. Please follow these manual setup instructions carefully.

Part 1: Create the Web Service (API) & Disk

This service handles incoming requests and will also create the shared disk.

From your Render Dashboard, click "New +" and select "Web Service".

Connect your GitHub account and select your student-prep-api repository.

Fill in the settings exactly as follows:

Name: student-prep-api

Region: Choose your preferred region. (e.g., Frankfurt)

Environment: Docker (The Dockerfile Path can be left blank as it will default to the root Dockerfile)

Scroll down to the "Advanced" section and click "Add Disk".

A new form will appear. Fill it in as follows:

Name: data-disk

Mount Path: /data

Size: 1 GB is sufficient.

Click "Create Web Service".

Part 2: Create the Background Worker

This service does the heavy lifting and will connect to the disk you just created.

From your Render Dashboard, click "New +" and select "Background Worker".

Connect your GitHub account and select your student-prep-api repository.

Fill in the settings exactly as follows:

Name: intelligence-worker

Region: (Must be the same region as your Web Service)

Environment: Docker

Scroll down to the "Advanced" section.

--- CRITICAL STEP ---
Find the "Dockerfile Path" field and enter the path to the new worker Dockerfile: Dockerfile.worker

Click "Add Disk" and fill out the form using the exact same details as before to link them:

Name: data-disk

Mount Path: /data

Size: 1 GB

Click "Create Background Worker".

Part 3: Configure Environment Variables

This step remains the same. You need to create an Environment Group and link it to both services.

Go to the "Environment" tab for your student-prep-api (Web Service).

Click "Add Environment Group".

Group Name: API Keys

Add two secret variables:

GEMINI_API_KEY: Paste your actual Gemini API key here.

APP_SECRET_KEY: Paste your own long, random secret key here.

Click "Save Changes".

Go to the "Environment" tab for your intelligence-worker (Background Worker).

Click "Link Environment Group" and select the API Keys group.

API Usage

Your API is now live. Here’s how to use it:

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

URL: https://your-service-name.onrender.com/index.php?action=briefing-status&job_id=the_job_id_from_step_1

Headers:

X-API-KEY: your_app_secret_key