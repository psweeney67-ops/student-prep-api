Student Career Prep Assistant API
This is a standalone PHP application that acts as an API backend. It receives a company name, uses the Gemini API with Google Search to research it, and returns a structured JSON object with key information to help a student prepare for job applications.

How to Deploy on Render
Render is an ideal platform for hosting this service.

Push to GitHub: Create a new repository on GitHub and push the index.php and composer.json files to it.

Create a New Web Service on Render:

In your Render Dashboard, click "New +" and select "Web Service".

Connect your GitHub account and select the repository you just created.

Give your service a unique name (e.g., student-prep-api).

Configure the Service:

Environment: Select PHP.

Build Command: composer install

Start Command: vendor/bin/heroku-php-apache2 . (This tells Render to run a standard Apache web server).

Add Environment Variables (Crucial!):

Go to the "Environment" tab for your new service.

Click "Add Environment Variable" twice to add the following secrets:

Key: GEMINI_API_KEY

Value: paste_your_gemini_api_key_here

Key: APP_SECRET_KEY

Value: generate_a_new_strong_secret_key_here (Use a password generator for this).

Deploy:

Click "Create Web Service". Render will pull your code, run the build command, and deploy your API.

Your API will be live at the URL Render provides, like https://student-prep-api.onrender.com.

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
