<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\AuthController;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user()->load(['role', 'hotline']);
});

Route::post('/synchro_mails', [EmailController::class, 'synchro_mails'])
    ->middleware(['auth:sanctum', 'throttle:6,1']);


Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsureAdmin::class])->group(function () {
    Route::apiResource('hotlines', \App\Http\Controllers\HotlineController::class);
    Route::apiResource('users', \App\Http\Controllers\UserController::class);
    Route::get('/roles', fn () => response()->json(['data' => \App\Models\Role::orderBy('nom')->get()]));
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/emails/send', [\App\Http\Controllers\MailDeliveryController::class, 'send'])->middleware('throttle:6,1');
    Route::get('/emails/sent', [\App\Http\Controllers\MailDeliveryController::class, 'sent']);
    Route::get('/emails/{email}/attachments', [\App\Http\Controllers\MailDeliveryController::class, 'attachments']);
    Route::get('/attachments/{attachment}/download', [\App\Http\Controllers\MailDeliveryController::class, 'download']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/emails/{email}/reply-context', [\App\Http\Controllers\MailDeliveryController::class, 'replyContext']);
    Route::get('/emails/{email}/conversation', [\App\Http\Controllers\MailDeliveryController::class, 'conversation']);
});
