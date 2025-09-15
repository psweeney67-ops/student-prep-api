Intelligence Briefing API
This is a standalone, asynchronous API service designed to generate detailed intelligence briefings and accompanying audio podcasts for a given company, based on specific industry trends.

Architecture Overview
This application uses an asynchronous, job-based architecture to handle long-running tasks. This is essential because generating a full briefing and podcast can take several minutes.

API Service (index.php): A lightweight web service that receives requests. Its only jobs are to create a new job file on a persistent disk and to start the background worker. It immediately returns a job_id.

Background Worker (worker.php): A separate process that is triggered by the API service. It reads the job file, performs the multi-step AI calls (research, synthesis, text-to-speech), and saves the final output files (a Markdown document and an MP3 podcast).

Persistent Disk: A networked storage volume on Render that is shared by both the API service and the worker. This is where job files and final reports/podcasts are stored.

Deployment to Render (Asynchronous Setup)
Deploying this service requires setting up three components on Render: a Persistent Disk, a Web Service for the API, and a Background Worker to process jobs.

Step 1: Create a Persistent Disk

First, create a place to store your job files and results.

From the Render Dashboard, click New+ -> Persistent Disk.

Name: briefing-storage

Size: 1 GB is a good starting point.

Region: Choose your preferred region.

Click Create Persistent Disk.

Step 2: Create the Web Service (API)

This service will handle incoming requests from your "Lovable" app.

From the Render Dashboard, click New+ -> Web Service.

Connect your GitHub repository.

Environment: Set to Docker.

Name: intelligence-api

Region: Must be the same region as your Persistent Disk.

Add Persistent Disk

Under "Advanced Settings," click Add Persistent Disk.

Disk: Select the briefing-storage disk you just created.

Mount Path: /var/data

Add Environment Variables

Go to the Environment tab and add your secrets:

GEMINI_API_KEY: Your key from Google AI Studio.

APP_SECRET_KEY: A new, long, random string you create to secure your API.

Click Create Web Service.

Step 3: Create the Background Worker

This service will run the worker.php script.

From the Render Dashboard, click New+ -> Background Worker.

Connect the same GitHub repository.

Environment: Set to Docker.

Name: intelligence-worker

Region: Must be the same region as your Persistent Disk and Web Service.

Add Persistent Disk & Environment Variables

Follow the same steps as above to Add Persistent Disk (mount path /var/data) and your Environment Variables (GEMINI_API_KEY, APP_SECRET_KEY).

Set the Start Command

The Start Command for the worker is different. You do not need a build command. Set the start command to: php worker.php

Click Create Background Worker.

API Usage
Interaction with the API is a two-step process:

Create a Job: Send a POST request to start the briefing process.

Check Status: Send GET requests using the job_id you received to check if the job is done.

1. Create Briefing Job

Endpoint: POST /create-briefing

Header: X-API-KEY: your-app-secret-key

Body (JSON):

{
  "company_name": "NVIDIA",
  "industry_trends": ["AI Chip Dominance", "Data Center Growth", "Gaming Market Evolution"]
}

Success Response (202 Accepted):

{
  "status": "processing-queued",
  "job_id": "65045eb6d3a0c"
}

2. Check Briefing Status

Poll this endpoint every 15-30 seconds until the status is complete or failed.

Endpoint: GET /briefing-status/{job_id}

Header: X-API-KEY: your-app-secret-key

Intermediate Response (200 OK):

{
  "job_id": "65045eb6d3a0c",
  "status": "processing-research",
  "created_at": "...",
  "updated_at": "..."
}

Final Success Response (200 OK):

{
    "job_id": "65045eb6d3a0c",
    "status": "complete",
    "created_at": "...",
    "updated_at": "...",
    "results": {
        "briefing_document_url": "/briefing/65045eb6d3a0c_briefing.md",
        "sources": [
            { "url": "...", "title": "..." }
        ],
        "podcast_url": "/podcast/65045eb6d3a0c_podcast.mp3"
    }
}

Example JavaScript Client

// This code would run on your "Lovable" front-end application

const API_URL = '[https://intelligence-api.onrender.com](https://intelligence-api.onrender.com)'; // Your Render URL
const SECRET_KEY = 'your-app-secret-key';

async function startBriefing(companyName, trends) {
    const response = await fetch(`${API_URL}/create-briefing`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-API-KEY': SECRET_KEY
        },
        body: JSON.stringify({
            company_name: companyName,
            industry_trends: trends
        })
    });

    if (!response.ok) {
        throw new Error('Failed to start briefing job');
    }

    const data = await response.json();
    return data.job_id;
}

async function checkStatus(jobId) {
    const response = await fetch(`${API_URL}/briefing-status/${jobId}`, {
         headers: { 'X-API-KEY': SECRET_KEY }
    });
    if (!response.ok) {
        throw new Error('Failed to check job status');
    }
    return await response.json();
}

// --- Main Polling Logic ---
async function main() {
    try {
        console.log('Starting briefing job...');
        const jobId = await startBriefing('Salesforce', ['CRM market consolidation', 'AI integration in sales tools']);
        console.log(`Job started with ID: ${jobId}`);

        // Poll for the result
        const poll = setInterval(async () => {
            const statusResult = await checkStatus(jobId);
            console.log(`Current status: ${statusResult.status}`);

            if (statusResult.status === 'complete') {
                clearInterval(poll);
                console.log('Briefing complete!');
                console.log('Document URL:', API_URL + statusResult.results.briefing_document_url);
                console.log('Podcast URL:', API_URL + statusResult.results.podcast_url);
            } else if (statusResult.status === 'failed') {
                clearInterval(poll);
                console.error('Job failed:', statusResult.results.error);
            }
        }, 20000); // Poll every 20 seconds

    } catch (error) {
        console.error(error);
    }
}

main();
