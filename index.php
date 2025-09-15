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
$system_prompt = "You are a helpful career assistant. Your goal is to provide structured company research to help a student prepare for a job application and interview. You MUST use the search tool to find current, relevant information. Your output MUST be a valid JSON object and nothing else. Do not wrap it in markdown backticks or any other text.

IMPORTANT: If the company is private, public information will be limited. Acknowledge this where appropriate (e.g., 'As a private company, detailed strategic plans are not public...'). Search for information in news articles, press releases, and funding announcements. Do not invent information if it is not publicly available.

The JSON object must conform to the following structure:
{
  \"company_name\": \"The official name of the company\",
  \"recent_strategy\": \"A summary of the company's recent strategic initiatives (e.g., new products, market expansion, acquisitions). If the company is private, synthesize this from news and press releases. Cite sources if possible.\",
  \"key_people\": [
    {
      \"name\": \"Full Name\",
      \"title\": \"Official Title (Search for CEO, CFO, COO, CTO, CIO, CPO, CHRO/HRD, CSO - Chief Strategy Officer, and other common C-suite executive roles)\",
      \"intelligence_brief\": \"A brief summary of their background, known leadership style, and recent strategic priorities or statements. For private companies, state if this information is not publicly available.\"
    }
  ],
  \"sector_trends\": \"An analysis of key trends affecting the company's industry sector.\",
  \"main_competitors\": [
    \"A list of the company's main competitors (provide several if possible).\"
  ],
  \"industry_disruption\": [
    \"A list of potential disruptive forces (e.g., AI, new business models, sustainability) that could impact the industry.\"
  ],
  \"cv_points\": [
    \"Provide 3-5 distinct, actionable CV points. Each point should connect a student's skills (e.g., technical, analytical, soft skills) directly to the company's specific strategy, products, recent news, or identified sector trends.\"
  ],
  \"interview_questions\": [
    \"Provide 5-10 insightful, open-ended interview questions that demonstrate genuine research. The questions should cover a range of topics including: the company's strategy and competitive landscape, team-specific challenges and success metrics, company culture in practice, and opportunities for personal growth within the role.\"
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

