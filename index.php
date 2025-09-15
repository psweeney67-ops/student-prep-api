<?php

// Load Composer's autoloader for Guzzle
require 'vendor/autoload.php';

// Set the content type for all responses to JSON
header('Content-Type: application/json');

// --- Environment & Security Configuration ---

// Get API keys from environment variables (this is how Render and other hosts handle secrets)
$geminiApiKey = getenv('GEMINI_API_KEY');
$appSecretKey = getenv('APP_SECRET_KEY');

// Die immediately if keys are not configured on the server
if (empty($geminiApiKey) || empty($appSecretKey)) {
    http_response_code(500);
    echo json_encode(['error' => 'Server is not configured correctly. API keys are missing.']);
    exit;
}

// --- Request Validation ---

// 1. Check for POST request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed. Only POST requests are accepted.']);
    exit;
}

// 2. Check for the secret API key in the header for authentication
$providedApiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!hash_equals($appSecretKey, $providedApiKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Invalid API Key.']);
    exit;
}

// 3. Get and validate the incoming JSON body
$json_input = file_get_contents('php://input');
$input_data = json_decode($json_input, true);
$companyName = htmlspecialchars(strip_tags($input_data['company_name'] ?? ''));

if (empty($companyName)) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad Request. "company_name" is a required parameter.']);
    exit;
}

// --- AI Prompt Engineering ---

/**
 * Gets the system prompt that defines the AI's persona and the required JSON output structure.
 */
function get_system_prompt() {
    return <<<PROMPT
You are a helpful and insightful career coach and business analyst. Your goal is to help a graduating student research a company for a job application. You must provide concise, actionable, and well-structured information. Your entire output MUST be in a valid JSON format according to the following schema:
{
  "type": "object",
  "properties": {
    "company_overview": { "type": "string", "description": "A brief, 2-3 sentence summary of what the company does and its mission." },
    "strategic_initiatives": {
      "type": "array",
      "description": "2-3 of the company's most important current strategic goals (e.g., AI expansion, sustainability, new market entry).",
      "items": { "type": "string" }
    },
    "key_personnel": {
      "type": "array",
      "description": "A list of 2-3 key executives (like CEO, CTO, or relevant VPs) and their roles.",
      "items": {
        "type": "object",
        "properties": {
          "name": { "type": "string" },
          "role": { "type": "string" }
        },
        "required": ["name", "role"]
      }
    },
    "sector_trends": {
      "type": "array",
      "description": "2-3 key trends currently impacting the company's industry.",
      "items": { "type": "string" }
    },
    "cv_suggestions": {
      "type": "array",
      "description": "2-3 tailored suggestions for a student's CV, linking their skills to the company's initiatives.",
      "items": { "type": "string" }
    },
    "interview_questions": {
      "type": "array",
      "description": "3 insightful, open-ended questions the student could ask the interviewer, based on the research.",
      "items": { "type": "string" }
    }
  },
  "required": ["company_overview", "strategic_initiatives", "key_personnel", "sector_trends", "cv_suggestions", "interview_questions"]
}
PROMPT;
}

/**
 * Gets the user prompt that contains the specific task for the AI.
 */
function get_user_prompt(string $companyName): string {
    return "Please conduct research on the company: '{$companyName}'. Use your search tool to find the most up-to-date information available to populate all the fields in the required JSON structure. Focus on data from the last 12-18 months.";
}


// --- Gemini API Call ---
try {
    $client = new GuzzleHttp\Client();
    $geminiApiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-05-20:generateContent?key=' . $geminiApiKey;

    $payload = [
        'contents' => [['parts' => [['text' => get_user_prompt($companyName)]]]],
        'systemInstruction' => ['parts' => [['text' => get_system_prompt()]]],
        'tools' => [['google_search' => new stdClass()]], // Enable Google Search grounding
        'generationConfig' => ['responseMimeType' => 'application/json'],
    ];

    $response = $client->post($geminiApiUrl, [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode($payload),
        'timeout' => 120,
    ]);

    $responseBody = json_decode($response->getBody()->getContents(), true);
    $aiContentText = $responseBody['candidates'][0]['content']['parts'][0]['text'] ?? null;

    if (empty($aiContentText)) {
        throw new Exception('AI returned an empty response.');
    }
    
    // The AI's response is a JSON *string*, so we need to decode it to get the actual object
    $structuredData = json_decode($aiContentText, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('AI returned malformed JSON. Error: ' . json_last_error_msg());
    }

    http_response_code(200);
    echo json_encode($structuredData);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'An internal error occurred while contacting the AI service.', 'details' => $e->getMessage()]);
}
