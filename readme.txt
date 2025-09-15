Student Career Prep Assistant API
This is a standalone PHP application that acts as an API backend. It receives a company name, uses the Gemini API with Google Search to research it, and returns a structured JSON object with key information to help a student prepare for job applications.

How to Deploy on Render (Using Docker)
This method is the most reliable way to deploy a PHP application on Render.

Push to GitHub:

Create a new repository on GitHub.

Push index.php, composer.json, and the new Dockerfile to it.

You can delete the old build.sh file, as it is no longer needed.

Create a New Web Service on Render:

In your Render Dashboard, click "New +" and select "Web Service".

Connect your GitHub account and select your repository.

Give your service a unique name (e.g., student-prep-api).

Configure the Service:

Environment: Select Docker. Render will automatically detect your Dockerfile.

Build Command: Leave this blank.

Start Command: Leave this blank.

The Dockerfile handles all the build and start instructions internally.

Add Environment Variables (Crucial!):

Go to the "Environment" tab for your new service.

Add the following secrets:

Key: GEMINI_API_KEY

Value: paste_your_gemini_api_key_here

Key: APP_SECRET_KEY

Value: generate_a_new_strong_secret_key_here

Deploy:

Click "Create Web Service" or manually trigger a new deploy.

Render will now use the Dockerfile to build and deploy your service. This build may take a few minutes.

Your API will be live at the URL Render provides.

How to Call the API from Your "Lovable" App
Use this JavaScript code on your front-end.

// This code goes on your "Lovable" website's front-end.

async function getCompanyResearch(companyName) {
    // IMPORTANT: Use the URL provided by Render
    const apiUrl = '[https://your-service-name.onrender.com](https://your-service-name.onrender.com)';
    
    // IMPORTANT: Use the same secret key you created in the Render environment variables
    const secretApiKey = 'the_strong_secret_key_you_generated';

    try {
        const response = await fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Api-Key': secretApiKey // This is the custom security header
            },
            body: JSON.stringify({
                company_name: companyName
            })
        });

        const researchData = await response.json();

        if (!response.ok) {
            throw new Error(`API Error (${response.status}): ${researchData.error || 'Unknown error'}`);
        }

        // Now you have the structured research data!
        console.log('Research Complete:', researchData);
        // e.g., displayInterviewQuestions(researchData.interview_questions);

        return researchData;

    } catch (error) {
        console.error('Failed to get company research:', error);
    }
}

// --- Example Usage ---
// getCompanyResearch('Salesforce');
