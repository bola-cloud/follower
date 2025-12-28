<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);


// Create a dummy article
$slug = 'test-verification-article-' . time();
$article = new \App\Models\Article();
$article->title = 'Test Verification Article';
$article->slug = $slug;
$article->content = 'This is a test article content.';
$article->is_published = true;
$article->save();

// Create a request
$request = Illuminate\Http\Request::create('/blog/' . $slug, 'GET');

// Handle the request
try {
    $response = $kernel->handle($request);
    echo "Status Code: " . $response->getStatusCode() . "\n";
    if ($response->getStatusCode() === 200) {
        $content = $response->getContent();
        if (strpos($content, 'Test Verification Article') !== false) {
            echo "SUCCESS: Blog page loaded with correct title.\n";
        } else {
            echo "FAILURE: Blog page loaded but title not found.\n";
        }
    } else {
        echo "FAILURE: Request failed with status " . $response->getStatusCode() . "\n";
        echo "Content: " . substr($response->getContent(), 0, 500) . "...\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
} finally {
    // Cleanup
    $article->delete();
}
