<?php

declare(strict_types=1);

use App\Http\Controllers\Internal\ImplementationEventController;
use App\Http\Controllers\Internal\ImplementationRequestController;
use App\Http\Controllers\Internal\IntegrationController;
use App\Http\Controllers\Internal\RepositoryController;
use Illuminate\Support\Facades\Route;

// Authentication is enforced by Cloudflare Access, not Laravel.
Route::get('repositories', [RepositoryController::class, 'index']);
Route::get('repositories/availability', [RepositoryController::class, 'availability']);
Route::get('repositories/by-name/{name}', [RepositoryController::class, 'byName']);
Route::get('repositories/{repository}', [RepositoryController::class, 'show']);
Route::post('repositories', [RepositoryController::class, 'store']);
Route::get('implementation-requests', [ImplementationRequestController::class, 'index']);
Route::post('implementation-requests', ImplementationRequestController::class);
Route::get('implementation-requests/{implementation}', [ImplementationRequestController::class, 'show']);
Route::post('implementation-requests/{implementation}/events', ImplementationEventController::class);
Route::get('integration-requests/{integration}', [IntegrationController::class, 'show']);
Route::post('integration-requests/{integration}/events', [IntegrationController::class, 'event']);
