Intelligence Briefing API (Simplified)
This is a standalone PHP application that acts as an asynchronous API for generating intelligence briefings. It is designed to be deployed on Render as a single, simple Web Service.

Deployment Instructions

Delete your old services/blueprints on Render and start fresh.

From your Render Dashboard, click "New +" and select "Web Service".

Connect your GitHub account and select your student-prep-api repository.

Fill in the settings exactly as follows:

Name: student-prep-api

Region: Choose your preferred region.

Environment: Docker

Scroll down to the "Advanced" section and click "Add Disk".

Fill in the form as follows:

Name: data-disk

Mount Path: /data

Size: 1 GB

Click "Create Web Service".

Once created, go to the "Environment" tab for your new service and add your two secret keys:

GEMINI_API_KEY: Your key from Google AI Studio.

APP_SECRET_KEY: Your own long, random secret key.

That's it. There is only one service to manage. This setup is robust and much easier to debug.