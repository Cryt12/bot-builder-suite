<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\ChatbotController;
use App\Http\Controllers\Api\PublicChatController;
use App\Http\Middleware\BearerTokenAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::options('/{any}', function (Request $request) {
    return response('', 204)->withHeaders([
        'Access-Control-Allow-Origin' => $request->headers->get('Origin') ?: '*',
        'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, Accept, Origin',
        'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
        'Vary' => 'Origin',
    ]);
})->where('any', '.*');

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/google', [AuthController::class, 'google']);
Route::get('/public/widget.js', [PublicChatController::class, 'widget']);
Route::get('/public/bot/{apiKey}', [PublicChatController::class, 'bot']);
Route::get('/public/logo/{apiKey}', [PublicChatController::class, 'logo']);
Route::post('/public/chat', [PublicChatController::class, 'chat']);

Route::middleware(BearerTokenAuth::class)->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/admin/users', [AdminController::class, 'users']);
    Route::get('/analytics', [ChatbotController::class, 'dashboardAnalytics']);
    Route::get('/chatbots', [ChatbotController::class, 'index']);
    Route::post('/chatbots', [ChatbotController::class, 'store']);
    Route::get('/chatbots/{chatbot}', [ChatbotController::class, 'show']);
    Route::patch('/chatbots/{chatbot}', [ChatbotController::class, 'update']);
    Route::delete('/chatbots/{chatbot}', [ChatbotController::class, 'destroy']);
    Route::post('/chatbots/{chatbot}/playground-chat', [ChatbotController::class, 'playgroundChat']);
    Route::post('/chatbots/{chatbot}/logo', [ChatbotController::class, 'uploadLogo']);
    Route::delete('/chatbots/{chatbot}/logo', [ChatbotController::class, 'deleteLogo']);
    Route::get('/chatbots/{chatbot}/sources', [ChatbotController::class, 'sources']);
    Route::post('/chatbots/{chatbot}/sources/text', [ChatbotController::class, 'ingestText']);
    Route::post('/chatbots/{chatbot}/sources/url', [ChatbotController::class, 'ingestUrl']);
    Route::post('/chatbots/{chatbot}/sources/file', [ChatbotController::class, 'ingestFile']);
    Route::get('/sources/{source}/chunks/download', [ChatbotController::class, 'downloadSourceChunks']);
    Route::get('/chatbots/{chatbot}/conversations', [ChatbotController::class, 'conversations']);
    Route::get('/chatbots/{chatbot}/analytics', [ChatbotController::class, 'analytics']);
    Route::delete('/sources/{source}', [ChatbotController::class, 'destroySource']);
    Route::get('/conversations/{conversation}/messages', [ChatbotController::class, 'messages']);
});
