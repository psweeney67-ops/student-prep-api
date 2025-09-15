<?php
// A simple, single-file API for the Student Career Prep Assistant.
// Deployed as a Docker container on Render.

// Set the content type for all responses to JSON
header('Content-Type: application/json');

// --- Security and Configuration ---

// Load environment variables for security
$geminiApiKey = getenv('GEMINI_API_KEY');
$appSecretKey = getenv('APP_SECRET_KEY');

// Get the API key from the request header
$clientApiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

// Security Check: Ensure the request is authorized
if (!$appSecretKey || $clientApiKey !== $appSecretKey) {
    http_response_code(401); // Unauthorized
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Security Check: Ensure this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

// Security Check: Ensure the Gemini API key is configured on the server
if (!$geminiApiKey) {
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => 'Server configuration error: Gemini API key is not set.']);
    exit;
}


// --- Request Handling ---

// Get the raw POST data
$json_input = file_get_contents('php://input');
$data = json_decode($json_input, true);

// Get the company name from the request
$company_name = $data['company_name'] ?? null;

// Validation: Ensure company name is provided
if (empty($company_name)) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Missing required parameter: company_name']);
    exit;
}


// --- Gemini API Interaction ---

require 'vendor/autoload.php';
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

$client = new Client();
$geminiApiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-05-20:generateContent?key=' . $geminiApiKey;

// Define the prompts for the AI
// The system prompt defines the persona and the required JSON structure.
$system_prompt = "You are a helpful career assistant. Your goal is to provide structured company research to help a student prepare for a job application and interview. You MUST use the search tool to find current, relevant information. Your output MUST be a valid JSON object and nothing else. Do not wrap it in markdown backticks or any other text. The JSON object must conform to the following structure:
{
  \"company_name\": \"The official name of the company\",
  \"recent_strategy\": \"A summary of the company's recent strategic initiatives (e.g., new products, market expansion, acquisitions). Cite sources if possible.\",
  \"key_people\": [
    {
      \"name\": \"Full Name\",
      \"title\": \"Official Title (Search for CEO, CFO, COO, CTO, CIO, CPO, CHRO/HRD, CSO - Chief Strategy Officer, and other common C-suite executive roles)\",
      \"intelligence_brief\": \"A brief summary of their background, known leadership style, and recent strategic priorities or statements.\"
    }
  ],
  \"sector_trends\": \"An analysis of key trends affecting the company's industry sector.\",
  \"main_competitors\": [
    \"The name of a primary competitor.\",
    \"The name of another primary competitor.\"
  ],
  \"industry_disruption\": [
    \"A potential disruptive force (e.g., AI, a new business model, sustainability regulations) that could significantly impact the industry.\",
    \"Another potential disruptive force and its possible consequences for the company.\"
  ],
  \"cv_points\": [
    \"A specific, actionable point a student could add to their CV, linking their technical or soft skills to the company's strategy.\",
    \"Another specific, actionable point, focusing on how their experience aligns with a recent company project or product launch.\",
    \"A third actionable point, potentially focused on a different skill or company goal.\",
    \"(Optional) A fourth point, perhaps highlighting adaptability to identified sector trends.\",
    \"(Optional) A fifth point, connecting personal projects or studies to a competitor's weakness or a market opportunity.\"
  ],
  \"interview_questions\": [
    \"An insightful, open-ended question a student could ask about the company's strategy in response to a specific competitor or market trend.\",
    \"A question about how the team measures success for this specific role and how that aligns with broader company goals.\",
    \"A question about the biggest challenge or opportunity the team will face in the next year and how this role contributes to tackling it.\",
    \"A question that shows knowledge of a specific company value and asks for an example of how it manifests in the team's daily work.\",
    \"A forward-looking question about learning and development opportunities in the context of the company's future needs.\",
    \"(Optional) A question about a recent piece of news (e.g., an acquisition, a new product launch) and its impact on the team.\",
    \"(Optional) A question about the collaborative process between this team and other departments.\"
  ]
}";

// The user prompt provides the specific company to research.
$user_prompt = "Provide a detailed research report for the company: '$company_name'. Use your search tool to find the most recent and relevant information.";


// Prepare the payload for the Gemini API
$payload = [
    'contents' => [
        [
            'parts' => [
                ['text' => $user_prompt]
            ]
        ]
    ],
    'tools' => [
        [
            'google_search' => new stdClass() // Use the search tool
        ]
    ],
    'systemInstruction' => [
        'parts' => [
            ['text' => $system_prompt]
        ]
    ]
    // The 'generationConfig' with responseMimeType has been REMOVED to fix the error.
];


try {
    // Make the API call to Gemini
    $response = $client->post($geminiApiUrl, [
        'json' => $payload,
        'headers' => [
            'Content-Type' => 'application/json',
        ],
    ]);

    $gemini_response_body = $response->getBody()->getContents();
    $gemini_data = json_decode($gemini_response_body, true);
    
    // Check for errors in the Gemini response itself
    if (isset($gemini_data['error'])) {
        http_response_code(500);
        echo json_encode(['error' => 'Gemini API Error: ' . $gemini_data['error']['message']]);
        exit;
    }

    // Extract the raw text content from the first candidate
    $raw_text = $gemini_data['candidates'][0]['content']['parts'][0]['text'] ?? '';

    // The AI response is now plain text that should contain a JSON object.
    // We will parse this text to find and decode the JSON.
    $json_output = json_decode($raw_text, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        // If direct decoding fails, try to extract from markdown backticks
        if (preg_match('/```json\s*([\s\S]*?)\s*```/', $raw_text, $matches)) {
            $json_output = json_decode($matches[1], true);
        }
    }
    
    // Final check to ensure we have valid JSON to return
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($json_output)) {
         http_response_code(500);
         echo json_encode(['error' => 'Failed to parse valid JSON from AI response.', 'raw_response' => $raw_text]);
         exit;
    }
    
    // --- Success ---
    // Send the structured JSON data back to the client
    http_response_code(200);
    echo json_encode($json_output);

} catch (RequestException $e) {
    // Handle network or other Guzzle-related errors
    http_response_code(502); // Bad Gateway
    $error_message = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
    echo json_encode(['error' => 'API request to Gemini failed.', 'details' => $error_message]);
}

